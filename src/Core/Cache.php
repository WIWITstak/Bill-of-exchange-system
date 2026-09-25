<?php

namespace OGAS\Core;

/**
 * Система кэширования с поддержкой Redis и fallback на файловое хранилище
 */
class Cache
{
    private static ?\Redis $redis = null;
    private static bool $redisAvailable = false;
    private static string $cacheDir;
    private static bool $loggingEnabled = false;
    private static bool $enabled = true; // Включен ли кэш
    
    // Стандартное время жизни кэша (в секундах)
    public const DEFAULT_TTL = 3600; // 1 час
    public const SHORT_TTL = 300;    // 5 минут
    public const LONG_TTL = 86400;   // 24 часа
    
    /**
     * Инициализация кэша
     */
    public static function init(array $config = []): void
    {
        // Директория для файлового кэша
        self::$cacheDir = $config['cache_dir'] ?? __DIR__ . '/../../storage/cache';
        
        // Создаем директорию, если не существует
        if (!is_dir(self::$cacheDir)) {
            @mkdir(self::$cacheDir, 0755, true);
        }
        
        // Включаем логирование, если указано в конфиге
        self::$loggingEnabled = $config['logging'] ?? false;
        
        // Включаем/отключаем кэш
        self::$enabled = $config['enabled'] ?? true;
        
        // Пытаемся подключиться к Redis только если кэш включен
        if (self::$enabled) {
            self::initRedis($config['redis'] ?? []);
        }
    }
    
    /**
     * Включить/отключить кэш (для динамического управления)
     */
    public static function setEnabled(bool $enabled): void
    {
        self::$enabled = $enabled;
        
        // Если отключаем кэш, закрываем соединение с Redis
        if (!$enabled && self::$redis !== null) {
            try {
                @self::$redis->close();
            } catch (\Exception $e) {
                // Игнорируем ошибки
            }
            self::$redis = null;
            self::$redisAvailable = false;
        }
    }
    
    /**
     * Проверить, включен ли кэш
     */
    public static function isEnabled(): bool
    {
        return self::$enabled;
    }
    
    /**
     * Принудительно переподключиться к Redis (если кэш включен)
     */
    public static function reconnect(): bool
    {
        // Закрываем существующее подключение, если есть
        if (self::$redis !== null) {
            try {
                @self::$redis->close();
            } catch (\Exception $e) {
                // Игнорируем ошибки
            }
            self::$redis = null;
        }
        
        self::$redisAvailable = false;
        
        // Загружаем конфигурацию заново
        $config = require __DIR__ . '/../../config/app.php';
        $redisConfig = $config['cache']['redis'] ?? [];
        
        // Если кэш отключен, не подключаемся
        if (!self::$enabled) {
            return false;
        }
        
        // Пытаемся подключиться
        self::initRedis($redisConfig);
        
        return self::$redisAvailable;
    }
    
    /**
     * Инициализация подключения к Redis
     */
    private static function initRedis(array $config): void
    {
        // Проверяем, есть ли расширение Redis
        if (!extension_loaded('redis')) {
            self::$redisAvailable = false;
            return;
        }
        
        $host = $config['host'] ?? '127.0.0.1';
        $port = (int)($config['port'] ?? 6379);
        $timeout = (float)($config['timeout'] ?? 2.0);
        $password = $config['password'] ?? null;
        $database = (int)($config['database'] ?? 0);
        
        try {
            self::$redis = new \Redis();
            
            // Устанавливаем таймаут подключения
            $connected = @self::$redis->connect($host, $port, $timeout);
            
            if (!$connected) {
                $error = error_get_last();
                $errorMsg = $error ? $error['message'] : 'Unknown connection error';
                error_log("Redis connection failed to {$host}:{$port} - {$errorMsg}");
                self::$redisAvailable = false;
                self::$redis = null;
                return;
            }
            
            // Аутентификация, если указан пароль
            if ($password !== null && $password !== '') {
                $authResult = @self::$redis->auth($password);
                if (!$authResult) {
                    error_log("Redis authentication failed for {$host}:{$port}");
                    self::$redis->close();
                    self::$redisAvailable = false;
                    self::$redis = null;
                    return;
                }
            }
            
            // Выбираем базу данных
            if ($database > 0) {
                $selectResult = @self::$redis->select($database);
                if (!$selectResult) {
                    error_log("Redis failed to select database {$database} on {$host}:{$port}");
                    self::$redis->close();
                    self::$redisAvailable = false;
                    self::$redis = null;
                    return;
                }
            }
            
            // Проверяем, что Redis работает
            $pingResult = @self::$redis->ping();
            if ($pingResult === false && $pingResult !== '+PONG' && $pingResult !== 'PONG' && $pingResult !== true) {
                error_log("Redis ping failed on {$host}:{$port}");
                self::$redis->close();
                self::$redisAvailable = false;
                self::$redis = null;
                return;
            }
            
            self::$redisAvailable = true;
            
            // Логируем успешное подключение, если включено логирование
            if (self::$loggingEnabled && class_exists('OGAS\Core\CacheLogger')) {
                CacheLogger::log('redis_connected', [
                    'host' => $host,
                    'port' => $port,
                    'database' => $database
                ], 'info');
            }
        } catch (\Exception $e) {
            // Redis недоступен, используем файловый кэш
            error_log('Redis connection exception: ' . $e->getMessage() . ' on ' . $host . ':' . $port);
            if (self::$loggingEnabled && class_exists('OGAS\Core\CacheLogger')) {
                CacheLogger::logError('redis_connection', $e->getMessage(), [
                    'host' => $host,
                    'port' => $port
                ]);
            }
            self::$redisAvailable = false;
            self::$redis = null;
        } catch (\Throwable $e) {
            error_log('Redis connection throwable: ' . $e->getMessage() . ' on ' . $host . ':' . $port);
            self::$redisAvailable = false;
            self::$redis = null;
        }
    }
    
    /**
     * Получить значение из кэша
     * 
     * @param string $key Ключ кэша
     * @param mixed $default Значение по умолчанию, если ключ не найден
     * @return mixed
     */
    public static function get(string $key, $default = null)
    {
        // Преобразуем ключ в безопасный формат
        $safeKey = self::sanitizeKey($key);
        $hit = false;
        $value = $default;
        
        if (self::$redisAvailable && self::$redis !== null) {
            try {
                $cached = @self::$redis->get($safeKey);
                if ($cached !== false) {
                    $value = unserialize($cached);
                    $hit = true;
                }
            } catch (\Exception $e) {
                error_log('Redis get error: ' . $e->getMessage());
                self::logError('get', $e->getMessage(), ['key' => $key]);
            }
        }
        
        if (!$hit) {
            // Fallback на файловый кэш
            $value = self::getFromFile($safeKey, $default);
            $hit = ($value !== $default);
        }
        
        // Логируем операцию
        if (self::$loggingEnabled && class_exists('OGAS\Core\CacheLogger')) {
            CacheLogger::logGet($key, $hit);
        }
        
        return $value;
    }
    
    /**
     * Сохранить значение в кэш
     * 
     * @param string $key Ключ кэша
     * @param mixed $value Значение для кэширования
     * @param int $ttl Время жизни в секундах (0 = вечно)
     * @return bool
     */
    public static function set(string $key, $value, int $ttl = self::DEFAULT_TTL): bool
    {
        // Если кэш отключен, просто возвращаем true (имитация успешной записи)
        if (!self::$enabled) {
            if (self::$loggingEnabled && class_exists('OGAS\Core\CacheLogger')) {
                CacheLogger::logSet($key, $ttl);
            }
            return true;
        }
        
        $safeKey = self::sanitizeKey($key);
        $serialized = serialize($value);
        $result = false;
        
        if (self::$redisAvailable && self::$redis !== null) {
            try {
                if ($ttl > 0) {
                    $result = @self::$redis->setex($safeKey, $ttl, $serialized);
                } else {
                    $result = @self::$redis->set($safeKey, $serialized);
                }
            } catch (\Exception $e) {
                error_log('Redis set error: ' . $e->getMessage());
                self::logError('set', $e->getMessage(), ['key' => $key]);
            }
        }
        
        if (!$result) {
            // Fallback на файловый кэш
            $result = self::setToFile($safeKey, $value, $ttl);
        }
        
        // Логируем операцию
        if (self::$loggingEnabled && $result && class_exists('OGAS\Core\CacheLogger')) {
            CacheLogger::logSet($key, $ttl);
        }
        
        return $result;
    }
    
    /**
     * Удалить значение из кэша
     * 
     * @param string $key Ключ кэша
     * @return bool
     */
    public static function delete(string $key): bool
    {
        // Если кэш отключен, возвращаем true (имитация успешного удаления)
        if (!self::$enabled) {
            if (self::$loggingEnabled && class_exists('OGAS\Core\CacheLogger')) {
                CacheLogger::logDelete($key);
            }
            return true;
        }
        
        $safeKey = self::sanitizeKey($key);
        $deleted = false;
        
        if (self::$redisAvailable && self::$redis !== null) {
            try {
                $deleted = @self::$redis->del($safeKey) > 0;
            } catch (\Exception $e) {
                error_log('Redis delete error: ' . $e->getMessage());
                self::logError('delete', $e->getMessage(), ['key' => $key]);
            }
        }
        
        // Удаляем из файлового кэша
        $fileDeleted = self::deleteFromFile($safeKey);
        $result = $deleted || $fileDeleted;
        
        // Логируем операцию
        if (self::$loggingEnabled && $result && class_exists('OGAS\Core\CacheLogger')) {
            CacheLogger::logDelete($key);
        }
        
        return $result;
    }
    
    /**
     * Удалить все ключи по паттерну
     * 
     * @param string $pattern Паттерн (например, "categories:*")
     * @return int Количество удаленных ключей
     */
    public static function deleteByPattern(string $pattern): int
    {
        // Если кэш отключен, возвращаем 0
        if (!self::$enabled) {
            if (self::$loggingEnabled && class_exists('OGAS\Core\CacheLogger')) {
                CacheLogger::logDeleteByPattern($pattern, 0);
            }
            return 0;
        }
        
        $count = 0;
        $safePattern = self::sanitizeKey($pattern);
        
        if (self::$redisAvailable && self::$redis !== null) {
            try {
                $keys = @self::$redis->keys($safePattern);
                if (!empty($keys)) {
                    $count = @self::$redis->del($keys);
                }
            } catch (\Exception $e) {
                error_log('Redis deleteByPattern error: ' . $e->getMessage());
                self::logError('deleteByPattern', $e->getMessage(), ['pattern' => $pattern]);
            }
        }
        
        // Удаляем из файлового кэша
        $count += self::deleteByPatternFromFile($safePattern);
        
        // Логируем операцию
        if (self::$loggingEnabled && $count > 0 && class_exists('OGAS\Core\CacheLogger')) {
            CacheLogger::logDeleteByPattern($pattern, $count);
        }
        
        return $count;
    }
    
    /**
     * Проверить, существует ли ключ в кэше
     * 
     * @param string $key Ключ кэша
     * @return bool
     */
    public static function has(string $key): bool
    {
        // Если кэш отключен, всегда возвращаем false
        if (!self::$enabled) {
            return false;
        }
        
        $safeKey = self::sanitizeKey($key);
        
        if (self::$redisAvailable && self::$redis !== null) {
            try {
                return @self::$redis->exists($safeKey) > 0;
            } catch (\Exception $e) {
                error_log('Redis exists error: ' . $e->getMessage());
            }
        }
        
        // Проверяем файловый кэш
        return self::hasInFile($safeKey);
    }
    
    /**
     * Очистить весь кэш
     * 
     * @return bool
     */
    public static function flush(): bool
    {
        // Если кэш отключен, возвращаем true
        if (!self::$enabled) {
            if (self::$loggingEnabled && class_exists('OGAS\Core\CacheLogger')) {
                CacheLogger::logFlush();
            }
            return true;
        }
        
        $success = true;
        
        if (self::$redisAvailable && self::$redis !== null) {
            try {
                @self::$redis->flushDB();
            } catch (\Exception $e) {
                error_log('Redis flush error: ' . $e->getMessage());
                self::logError('flush', $e->getMessage());
                $success = false;
            }
        }
        
        // Очищаем файловый кэш
        $fileSuccess = self::flushFiles();
        $result = $fileSuccess && $success;
        
        // Логируем операцию
        if (self::$loggingEnabled && class_exists('OGAS\Core\CacheLogger')) {
            CacheLogger::logFlush();
        }
        
        return $result;
    }
    
    /**
     * Логировать ошибку (внутренний метод)
     */
    private static function logError(string $operation, string $message, array $context = []): void
    {
        if (self::$loggingEnabled && class_exists('OGAS\Core\CacheLogger')) {
            CacheLogger::logError($operation, $message, $context);
        }
    }
    
    /**
     * Получить значение или вызвать callback и закэшировать результат
     * 
     * @param string $key Ключ кэша
     * @param callable $callback Функция, которая вернет значение, если кэш пуст
     * @param int $ttl Время жизни в секундах
     * @return mixed
     */
    public static function remember(string $key, callable $callback, int $ttl = self::DEFAULT_TTL)
    {
        $value = self::get($key);
        
        if ($value !== null) {
            return $value;
        }
        
        // Вызываем callback и кэшируем результат
        $value = $callback();
        self::set($key, $value, $ttl);
        
        return $value;
    }
    
    /**
     * Преобразовать ключ в безопасный формат
     */
    private static function sanitizeKey(string $key): string
    {
        // Убираем опасные символы
        $key = preg_replace('/[^a-zA-Z0-9_\-:]/', '_', $key);
        // Добавляем префикс
        return 'ogas:' . $key;
    }
    
    /**
     * Получить значение из файлового кэша
     */
    private static function getFromFile(string $key, $default = null)
    {
        $filePath = self::getFilePath($key);
        
        if (!file_exists($filePath)) {
            return $default;
        }
        
        $data = @file_get_contents($filePath);
        if ($data === false) {
            return $default;
        }
        
        $cacheData = @unserialize($data);
        if (!is_array($cacheData) || !isset($cacheData['value'], $cacheData['expires'])) {
            return $default;
        }
        
        // Проверяем срок действия
        if ($cacheData['expires'] > 0 && time() > $cacheData['expires']) {
            @unlink($filePath);
            return $default;
        }
        
        return $cacheData['value'];
    }
    
    /**
     * Сохранить значение в файловый кэш
     */
    private static function setToFile(string $key, $value, int $ttl): bool
    {
        $filePath = self::getFilePath($key);
        $dir = dirname($filePath);
        
        if (!is_dir($dir)) {
            @mkdir($dir, 0755, true);
        }
        
        $expires = $ttl > 0 ? time() + $ttl : 0;
        
        $cacheData = [
            'value' => $value,
            'expires' => $expires,
            'created' => time()
        ];
        
        return @file_put_contents($filePath, serialize($cacheData), LOCK_EX) !== false;
    }
    
    /**
     * Удалить значение из файлового кэша
     */
    private static function deleteFromFile(string $key): bool
    {
        $filePath = self::getFilePath($key);
        
        if (file_exists($filePath)) {
            return @unlink($filePath);
        }
        
        return false;
    }
    
    /**
     * Удалить по паттерну из файлового кэша
     */
    private static function deleteByPatternFromFile(string $pattern): int
    {
        $count = 0;
        $pattern = str_replace('*', '.*', $pattern);
        
        if (!is_dir(self::$cacheDir)) {
            return 0;
        }
        
        $iterator = new \RecursiveIteratorIterator(
            new \RecursiveDirectoryIterator(self::$cacheDir, \RecursiveDirectoryIterator::SKIP_DOTS)
        );
        
        foreach ($iterator as $file) {
            if ($file->isFile()) {
                $relativePath = str_replace(self::$cacheDir . DIRECTORY_SEPARATOR, '', $file->getPathname());
                if (preg_match('/^' . $pattern . '$/', $relativePath)) {
                    if (@unlink($file->getPathname())) {
                        $count++;
                    }
                }
            }
        }
        
        return $count;
    }
    
    /**
     * Проверить существование в файловом кэше
     */
    private static function hasInFile(string $key): bool
    {
        $filePath = self::getFilePath($key);
        
        if (!file_exists($filePath)) {
            return false;
        }
        
        $data = @file_get_contents($filePath);
        if ($data === false) {
            return false;
        }
        
        $cacheData = @unserialize($data);
        if (!is_array($cacheData) || !isset($cacheData['expires'])) {
            return false;
        }
        
        // Проверяем срок действия
        if ($cacheData['expires'] > 0 && time() > $cacheData['expires']) {
            @unlink($filePath);
            return false;
        }
        
        return true;
    }
    
    /**
     * Очистить файловый кэш
     */
    private static function flushFiles(): bool
    {
        if (!is_dir(self::$cacheDir)) {
            return true;
        }
        
        $iterator = new \RecursiveIteratorIterator(
            new \RecursiveDirectoryIterator(self::$cacheDir, \RecursiveDirectoryIterator::SKIP_DOTS),
            \RecursiveIteratorIterator::CHILD_FIRST
        );
        
        foreach ($iterator as $file) {
            if ($file->isDir()) {
                @rmdir($file->getPathname());
            } else {
                @unlink($file->getPathname());
            }
        }
        
        return true;
    }
    
    /**
     * Получить путь к файлу кэша
     */
    private static function getFilePath(string $key): string
    {
        // Создаем вложенные директории на основе хэша ключа
        $hash = md5($key);
        $subDir = substr($hash, 0, 2) . '/' . substr($hash, 2, 2);
        
        return self::$cacheDir . '/' . $subDir . '/' . $hash . '.cache';
    }
    
    /**
     * Получить информацию о кэше
     */
    public static function getInfo(): array
    {
        $info = [
            'enabled' => self::$enabled,
            'redis_available' => self::$redisAvailable,
            'redis_connected' => self::$redisAvailable && self::$redis !== null,
            'cache_dir' => self::$cacheDir,
            'driver' => self::$redisAvailable ? 'redis' : 'file',
            'logging_enabled' => self::$loggingEnabled,
        ];
        
        // Добавляем информацию о Redis, если подключен
        if (self::$redisAvailable && self::$redis !== null) {
            try {
                $info['redis_info'] = @self::$redis->info();
                $info['redis_db_size'] = @self::$redis->dbSize();
            } catch (\Exception $e) {
                // Игнорируем ошибки
            }
        }
        
        return $info;
    }
    
    /**
     * Получить статистику размера файлового кэша
     */
    public static function getFileCacheStats(): array
    {
        $size = 0;
        $fileCount = 0;
        $dirCount = 0;
        
        if (is_dir(self::$cacheDir)) {
            $iterator = new \RecursiveIteratorIterator(
                new \RecursiveDirectoryIterator(self::$cacheDir, \RecursiveDirectoryIterator::SKIP_DOTS)
            );
            
            foreach ($iterator as $file) {
                if ($file->isFile()) {
                    $size += $file->getSize();
                    $fileCount++;
                } elseif ($file->isDir()) {
                    $dirCount++;
                }
            }
        }
        
        return [
            'size_bytes' => $size,
            'size_mb' => round($size / (1024 * 1024), 2),
            'size_kb' => round($size / 1024, 2),
            'file_count' => $fileCount,
            'dir_count' => $dirCount,
        ];
    }
    
    /**
     * Закрыть соединение с Redis
     */
    public static function close(): void
    {
        if (self::$redis !== null) {
            try {
                @self::$redis->close();
            } catch (\Exception $e) {
                // Игнорируем ошибки при закрытии
            }
            self::$redis = null;
        }
        self::$redisAvailable = false;
    }
}

