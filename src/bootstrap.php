<?php
/**
 * Файл инициализации приложения
 */

// Загрузка автозагрузчика Composer
if (file_exists(__DIR__ . '/../vendor/autoload.php')) {
    require_once __DIR__ . '/../vendor/autoload.php';
}

// Автозагрузчик для классов OGAS (всегда регистрируем, даже если Composer загружен)
spl_autoload_register(function ($class) {
    // Преобразуем пространство имен в путь к файлу
    $prefix = 'OGAS\\';
    $base_dir = __DIR__ . '/';
    
    // Проверяем, начинается ли класс с префикса
    $len = strlen($prefix);
    if (strncmp($prefix, $class, $len) !== 0) {
        return;
    }
    
    // Получаем относительное имя класса
    $relative_class = substr($class, $len);
    
    // Преобразуем пространство имен в путь к файлу
    $file = $base_dir . str_replace('\\', '/', $relative_class) . '.php';
    
    // Если файл существует, подключаем его
    if (file_exists($file)) {
        require $file;
        return true;
    }
    
    return false;
});

// Загрузка helper функций
require_once __DIR__ . '/Helpers/helpers.php';

// Загрузка переменных окружения из .env
$envFile = __DIR__ . '/../.env';
if (file_exists($envFile)) {
    $lines = file($envFile, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES);
    foreach ($lines as $line) {
        // Пропускаем комментарии
        if (strpos(trim($line), '#') === 0) {
            continue;
        }
        
        // Парсим KEY=VALUE
        if (strpos($line, '=') !== false) {
            list($key, $value) = explode('=', $line, 2);
            $key = trim($key);
            $value = trim($value);
            
            // Убираем кавычки, если есть
            if ((substr($value, 0, 1) === '"' && substr($value, -1) === '"') ||
                (substr($value, 0, 1) === "'" && substr($value, -1) === "'")) {
                $value = substr($value, 1, -1);
            }
            
            // Обработка пустых значений
            if ($value === '' || $value === 'null' || $value === 'NULL') {
                $value = null;
            }
            
            // Устанавливаем переменные окружения
            $_ENV[$key] = $value;
            if ($value !== null) {
                putenv("$key=$value");
            } else {
                putenv("$key");
            }
        }
    }
}

// Загрузка конфигурации
$config = require_once __DIR__ . '/../config/app.php';

// Инициализация базы данных (не подключаем сразу, подключение будет при первом использовании)
// Используем полное имя класса, чтобы избежать проблем с автозагрузкой
\OGAS\Database::init($config['database']);

// Инициализация сессии с безопасными параметрами (для WebSocket сервера сессия не нужна, но проверим наличие класса)
if (class_exists('OGAS\Core\Session')) {
    \OGAS\Core\Session::start();
    
    // Очистка устаревших сессий (раз в 100 запросов для производительности)
    if (php_sapi_name() !== 'cli' && mt_rand(1, 100) === 1) {
        if (class_exists('OGAS\Models\UserSession')) {
            \OGAS\Models\UserSession::cleanupExpired(\OGAS\Core\Session::getInactivityTimeout());
        }
    }
}

// Инициализация безопасности (только для веб-запросов, не для CLI)
if (php_sapi_name() !== 'cli' && class_exists('OGAS\Core\Security')) {
    \OGAS\Core\Security::setSecureSessionParams();
    // Проверка на ботов (только для критичных страниц, не для API и статики)
    // ВРЕМЕННО ОТКЛЮЧЕНО для отладки - можно включить позже
    /*
    $requestUri = $_SERVER['REQUEST_URI'] ?? '';
    $isApi = strpos($requestUri, '/api/') === 0;
    $isStatic = preg_match('/\.(css|js|jpg|jpeg|png|gif|webp|svg|ico|woff|woff2|ttf|eot)$/i', $requestUri);
    
    if (!$isApi && !$isStatic && class_exists('OGAS\Core\BotProtection')) {
        // Проверяем на ботов (не строгий режим для обычных страниц)
        // Только логируем подозрительную активность, не блокируем
        $detection = \OGAS\Core\BotProtection::detectBot();
        if ($detection['score'] >= 70) {
            // Только логируем, не блокируем
            if (class_exists('OGAS\Core\SecurityLogger')) {
                \OGAS\Core\SecurityLogger::log('bot_suspicious', $detection, 'warning');
            }
        }
    }
    */
    
    // Устанавливаем HTTP Security Headers (только если заголовки еще не отправлены)
    if (!headers_sent()) {
        \OGAS\Core\Security::setSecurityHeaders();
    }
}

// Включение отображения ошибок в режиме разработки
if (($config['debug'] ?? true) && ($config['env'] ?? 'development') === 'development') {
    error_reporting(E_ALL);
    ini_set('display_errors', '1');
}

// Установка кодировки (только если заголовки еще не были отправлены)
// Для API файлов этот заголовок будет переопределен
if (!headers_sent()) {
    header('Content-Type: text/html; charset=UTF-8');
}

// Установка часового пояса
date_default_timezone_set($config['timezone']);

// Инициализация кэша
if (class_exists('OGAS\Core\Cache') && isset($config['cache'])) {
    // Загружаем настройки кэша из файла, если есть
    $cacheSettings = [];
    if (class_exists('OGAS\Services\CacheSettingsService')) {
        try {
            $cacheSettings = \OGAS\Services\CacheSettingsService::getSettings();
            // Добавляем настройку enabled в конфиг кэша
            if (isset($cacheSettings['enabled'])) {
                $config['cache']['enabled'] = $cacheSettings['enabled'];
            }
        } catch (\Exception $e) {
            // Игнорируем ошибки загрузки настроек
        }
    }
    
    // Объединяем настройки из конфига и из файла
    $cacheConfig = array_merge($config['cache'], [
        'cache_dir' => $config['cache']['cache_dir'] ?? $config['paths']['cache'] ?? __DIR__ . '/../storage/cache',
        'redis' => $config['cache']['redis'] ?? [],
        'logging' => $cacheSettings['logging_enabled'] ?? $config['cache']['logging'] ?? false,
        'enabled' => $cacheSettings['enabled'] ?? true,
    ]);
    
    \OGAS\Core\Cache::init($cacheConfig);
    \OGAS\Core\Cache::init($config['cache']);
}
