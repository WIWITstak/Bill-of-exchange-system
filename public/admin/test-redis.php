<?php
/**
 * Диагностический скрипт для проверки подключения к Redis
 */

require_once __DIR__ . '/../../src/bootstrap.php';

use OGAS\Services\Auth;
use OGAS\Services\AdminService;

// Проверка прав администратора
Auth::requireAuth();
AdminService::requireAdmin();

header('Content-Type: text/html; charset=UTF-8');
?>
<!DOCTYPE html>
<html>
<head>
    <title>Диагностика Redis</title>
    <style>
        body {
            font-family: Arial, sans-serif;
            max-width: 800px;
            margin: 50px auto;
            padding: 20px;
            background: #f5f5f5;
        }
        .card {
            background: white;
            padding: 20px;
            margin: 20px 0;
            border-radius: 8px;
            box-shadow: 0 2px 4px rgba(0,0,0,0.1);
        }
        .success { color: #10b981; }
        .error { color: #ef4444; }
        .warning { color: #f59e0b; }
        .info { color: #3b82f6; }
        pre {
            background: #f3f4f6;
            padding: 15px;
            border-radius: 4px;
            overflow-x: auto;
        }
        h2 {
            margin-top: 0;
        }
    </style>
</head>
<body>
    <h1>Диагностика подключения к Redis</h1>
    
    <?php
    $config = require __DIR__ . '/../../config/app.php';
    $redisConfig = $config['cache']['redis'] ?? [];
    
    echo '<div class="card">';
    echo '<h2>Конфигурация Redis</h2>';
    echo '<pre>';
    echo "Host: " . htmlspecialchars($redisConfig['host'] ?? 'не указан') . "\n";
    echo "Port: " . htmlspecialchars($redisConfig['port'] ?? 'не указан') . "\n";
    echo "Database: " . htmlspecialchars($redisConfig['database'] ?? 'не указан') . "\n";
    echo "Password: " . (isset($redisConfig['password']) && !empty($redisConfig['password']) ? 'установлен' : 'не установлен') . "\n";
    echo "Timeout: " . htmlspecialchars($redisConfig['timeout'] ?? 'не указан') . "\n";
    
    // Показываем источник настроек
    echo "\n--- Источник настроек ---\n";
    if (isset($_ENV['REDIS_HOST']) || getenv('REDIS_HOST')) {
        echo "Источник: переменные окружения (.env или системные)\n";
    } else {
        echo "Источник: значения по умолчанию из config/app.php\n";
    }
    echo '</pre>';
    echo '</div>';
    
    // Проверка расширения Redis
    echo '<div class="card">';
    echo '<h2>Проверка расширения PHP Redis</h2>';
    if (extension_loaded('redis')) {
        echo '<p class="success">✓ Расширение Redis установлено</p>';
        $redisVersion = phpversion('redis');
        if ($redisVersion) {
            echo '<p class="info">Версия расширения: ' . htmlspecialchars($redisVersion) . '</p>';
        }
    } else {
        echo '<p class="error">✗ Расширение Redis НЕ установлено</p>';
        echo '<p class="warning">Для работы с Redis необходимо установить расширение php-redis</p>';
    }
    echo '</div>';
    
    if (extension_loaded('redis')) {
        // Попытка подключения
        echo '<div class="card">';
        echo '<h2>Попытка подключения к Redis</h2>';
        
        $host = $redisConfig['host'] ?? '127.0.0.1';
        $port = (int)($redisConfig['port'] ?? 6379);
        $timeout = (float)($redisConfig['timeout'] ?? 2.0);
        $password = $redisConfig['password'] ?? null;
        $database = (int)($redisConfig['database'] ?? 0);
        
        try {
            $redis = new \Redis();
            
            echo '<p class="info">Попытка подключения к ' . htmlspecialchars($host) . ':' . $port . '...</p>';
            
            $connected = @$redis->connect($host, $port, $timeout);
            
            if ($connected) {
                echo '<p class="success">✓ Успешное подключение к Redis серверу</p>';
                
                // Аутентификация, если нужна
                if ($password !== null && $password !== '') {
                    try {
                        $authResult = @$redis->auth($password);
                        if ($authResult) {
                            echo '<p class="success">✓ Аутентификация прошла успешно</p>';
                        } else {
                            echo '<p class="error">✗ Ошибка аутентификации (неверный пароль?)</p>';
                            $redis->close();
                            $connected = false;
                        }
                    } catch (\Exception $e) {
                        echo '<p class="error">✗ Ошибка аутентификации: ' . htmlspecialchars($e->getMessage()) . '</p>';
                        $connected = false;
                    }
                }
                
                if ($connected) {
                    // Выбор базы данных
                    if ($database > 0) {
                        try {
                            @$redis->select($database);
                            echo '<p class="success">✓ Выбрана база данных ' . $database . '</p>';
                        } catch (\Exception $e) {
                            echo '<p class="warning">⚠ Ошибка выбора базы данных: ' . htmlspecialchars($e->getMessage()) . '</p>';
                        }
                    }
                    
                    // Проверка ping
                    try {
                        $pingResult = @$redis->ping();
                        if ($pingResult === '+PONG' || $pingResult === true || $pingResult === 'PONG') {
                            echo '<p class="success">✓ PING успешен - Redis работает</p>';
                        } else {
                            echo '<p class="warning">⚠ PING вернул неожиданный результат: ' . htmlspecialchars(var_export($pingResult, true)) . '</p>';
                        }
                    } catch (\Exception $e) {
                        echo '<p class="error">✗ Ошибка PING: ' . htmlspecialchars($e->getMessage()) . '</p>';
                    }
                    
                    // Получение информации о сервере
                    try {
                        $info = @$redis->info();
                        if ($info) {
                            echo '<h3>Информация о Redis сервере:</h3>';
                            echo '<pre>';
                            $infoArray = [];
                            $lines = explode("\n", $info);
                            foreach ($lines as $line) {
                                if (!empty(trim($line)) && strpos($line, '#') !== 0) {
                                    echo htmlspecialchars($line) . "\n";
                                    // Парсим ключ-значение
                                    if (strpos($line, ':') !== false) {
                                        list($key, $value) = explode(':', $line, 2);
                                        $infoArray[trim($key)] = trim($value);
                                    }
                                }
                            }
                            echo '</pre>';
                            
                            // Проверка настроек безопасности
                            echo '<h3>Проверка настроек безопасности:</h3>';
                            echo '<ul>';
                            
                            // Проверка protected-mode (только для Linux, на Windows обычно отключен)
                            if (isset($infoArray['redis_mode']) && $infoArray['redis_mode'] !== 'standalone') {
                                echo '<li class="warning">⚠ Режим: ' . htmlspecialchars($infoArray['redis_mode']) . '</li>';
                            }
                            
                            // Проверка порта
                            if (isset($infoArray['tcp_port'])) {
                                $redisPort = (int)$infoArray['tcp_port'];
                                if ($redisPort !== $port) {
                                    echo '<li class="warning">⚠ Redis слушает на порту ' . $redisPort . ', а подключение идет на ' . $port . '</li>';
                                } else {
                                    echo '<li class="success">✓ Порт совпадает: ' . $redisPort . '</li>';
                                }
                            }
                            
                            // Проверка requirepass (пароль)
                            try {
                                $configGet = @$redis->config('GET', 'requirepass');
                                if ($configGet && !empty($configGet[1])) {
                                    echo '<li class="info">ℹ Установлен пароль (requirepass)</li>';
                                    if (empty($password)) {
                                        echo '<li class="warning">⚠ В конфигурации приложения пароль не указан, но Redis требует пароль!</li>';
                                    }
                                } else {
                                    echo '<li class="success">✓ Пароль не требуется (requirepass не установлен)</li>';
                                }
                            } catch (\Exception $e) {
                                // Команда config может быть недоступна, игнорируем
                            }
                            
                            echo '</ul>';
                        }
                    } catch (\Exception $e) {
                        echo '<p class="warning">⚠ Не удалось получить информацию о сервере: ' . htmlspecialchars($e->getMessage()) . '</p>';
                    }
                    
                    // Проверка настроек безопасности через CONFIG GET
                    try {
                        echo '<h3>Детальные настройки безопасности Redis:</h3>';
                        echo '<pre>';
                        
                        // Проверка bind
                        try {
                            $bindConfig = @$redis->config('GET', 'bind');
                            if ($bindConfig) {
                                $bindValue = $bindConfig[1] ?? '';
                                if (empty($bindValue) || $bindValue === '*') {
                                    echo "bind: * (слушает все интерфейсы) ✓\n";
                                } else {
                                    echo "bind: " . htmlspecialchars($bindValue) . "\n";
                                    if (strpos($bindValue, '127.0.0.1') === false && strpos($bindValue, 'localhost') === false && $host === '127.0.0.1') {
                                        echo "⚠ ВНИМАНИЕ: Redis привязан к другому интерфейсу!\n";
                                    }
                                }
                            }
                        } catch (\Exception $e) {
                            echo "bind: не удалось получить (возможно, команда недоступна)\n";
                        }
                        
                        // Проверка protected-mode (актуально для Redis 3.2+ на Linux)
                        try {
                            $protectedMode = @$redis->config('GET', 'protected-mode');
                            if ($protectedMode) {
                                $protectedModeValue = $protectedMode[1] ?? '';
                                echo "protected-mode: " . htmlspecialchars($protectedModeValue) . "\n";
                                if ($protectedModeValue === 'yes' && empty($password)) {
                                    echo "⚠ ВНИМАНИЕ: Protected mode включен, но пароль не установлен!\n";
                                }
                            }
                        } catch (\Exception $e) {
                            // На Windows protected-mode обычно не используется
                            echo "protected-mode: не применяется (Windows)\n";
                        }
                        
                        // Проверка requirepass
                        try {
                            $requirepass = @$redis->config('GET', 'requirepass');
                            if ($requirepass) {
                                $requirepassValue = $requirepass[1] ?? '';
                                if (!empty($requirepassValue)) {
                                    echo "requirepass: установлен ✓\n";
                                } else {
                                    echo "requirepass: не установлен\n";
                                }
                            }
                        } catch (\Exception $e) {
                            echo "requirepass: не удалось получить\n";
                        }
                        
                        // Проверка maxclients
                        try {
                            $maxclients = @$redis->config('GET', 'maxclients');
                            if ($maxclients) {
                                echo "maxclients: " . htmlspecialchars($maxclients[1] ?? 'не установлен') . "\n";
                            }
                        } catch (\Exception $e) {
                            // Игнорируем
                        }
                        
                        echo '</pre>';
                    } catch (\Exception $e) {
                        echo '<p class="info">ℹ Команда CONFIG недоступна (возможно, ограничены права доступа)</p>';
                    }
                    
                    // Тест записи/чтения
                    try {
                        $testKey = 'ogas_test_' . time();
                        $testValue = 'test_value_' . rand(1000, 9999);
                        
                        $setResult = @$redis->set($testKey, $testValue, 10); // TTL 10 секунд
                        if ($setResult) {
                            echo '<p class="success">✓ Тест записи успешен</p>';
                            
                            $getValue = @$redis->get($testKey);
                            if ($getValue === $testValue) {
                                echo '<p class="success">✓ Тест чтения успешен</p>';
                                
                                // Удаляем тестовый ключ
                                @$redis->del($testKey);
                            } else {
                                echo '<p class="error">✗ Тест чтения не удался. Ожидалось: ' . htmlspecialchars($testValue) . ', получено: ' . htmlspecialchars(var_export($getValue, true)) . '</p>';
                            }
                        } else {
                            echo '<p class="error">✗ Тест записи не удался</p>';
                        }
                    } catch (\Exception $e) {
                        echo '<p class="error">✗ Ошибка при тесте записи/чтения: ' . htmlspecialchars($e->getMessage()) . '</p>';
                    }
                    
                    $redis->close();
                }
            } else {
                echo '<p class="error">✗ Не удалось подключиться к Redis серверу</p>';
                echo '<p class="warning">Возможные причины:</p>';
                echo '<ul>';
                echo '<li>Redis сервер не запущен на ' . htmlspecialchars($host) . ':' . $port . '</li>';
                echo '<li>Неправильные настройки host и port</li>';
                echo '<li><strong>Файрвол Windows блокирует подключение</strong> (проверьте исключения для порта ' . $port . ')</li>';
                echo '<li><strong>Redis привязан к другому интерфейсу</strong> (проверьте настройку bind в redis.conf)</li>';
                echo '<li><strong>Redis требует пароль</strong> (проверьте настройку requirepass в redis.conf)</li>';
                echo '<li><strong>Protected mode включен</strong> (актуально для Redis 3.2+ на Linux)</li>';
                echo '</ul>';
                
                // Дополнительная диагностика
                echo '<h3>Дополнительная диагностика:</h3>';
                echo '<ul>';
                
                // Проверка доступности порта
                echo '<li>Проверка доступности порта: ';
                $connection = @fsockopen($host, $port, $errno, $errstr, 2);
                if ($connection) {
                    echo '<span class="success">✓ Порт ' . $port . ' доступен</span></li>';
                    fclose($connection);
                } else {
                    echo '<span class="error">✗ Порт ' . $port . ' недоступен (ошибка: ' . htmlspecialchars($errstr) . ')</span></li>';
                }
                echo '</ul>';
                
                // Информация о конфигурационном файле
                try {
                    if (extension_loaded('redis')) {
                        // Пытаемся получить путь к конфигу из переменных окружения или стандартных мест
                        $possibleConfigPaths = [
                            'F:\\OpenServer\\userdata\\config\\Redis-5.0.conf',
                            'F:\\OpenServer\\modules\\redis\\Redis-5.0\\redis.conf',
                            'C:\\OpenServer\\userdata\\config\\Redis-5.0.conf',
                            'C:\\OpenServer\\modules\\redis\\Redis-5.0\\redis.conf',
                            'D:\\OpenServer\\userdata\\config\\Redis-5.0.conf',
                            'D:\\OpenServer\\modules\\redis\\Redis-5.0\\redis.conf',
                            getenv('REDIS_CONF'),
                        ];
                        
                        echo '<h3>Конфигурационный файл Redis:</h3>';
                        $configFound = false;
                        foreach ($possibleConfigPaths as $configPath) {
                            if ($configPath && file_exists($configPath)) {
                                echo '<p class="success">✓ Найден: ' . htmlspecialchars($configPath) . '</p>';
                                $configContent = file_get_contents($configPath);
                                
                                // Проверяем важные настройки
                                echo '<pre>';
                                $issuesFound = [];
                                
                                // Проверка bind
                                if (preg_match('/^bind\s+(.+)$/mi', $configContent, $matches)) {
                                    $bindValue = trim($matches[1]);
                                    echo "bind: " . htmlspecialchars($bindValue) . "\n";
                                    
                                    // Проверка на шаблон Open Server
                                    if ($bindValue === '%ip%') {
                                        echo "⚠ ВНИМАНИЕ: bind содержит шаблон %ip%, который должен быть заменен Open Server!\n";
                                        $issuesFound[] = 'bind_placeholder';
                                    } elseif ($bindValue === '0.0.0.0' || $bindValue === '*') {
                                        echo "⚠ ВНИМАНИЕ: Redis слушает на всех интерфейсах! Для безопасности лучше использовать 127.0.0.1\n";
                                        $issuesFound[] = 'bind_all';
                                    } elseif (strpos($bindValue, '127.0.0.1') === false && strpos($bindValue, 'localhost') === false && $host === '127.0.0.1') {
                                        echo "⚠ ВНИМАНИЕ: bind настроен на другой интерфейс, чем указано в приложении!\n";
                                        $issuesFound[] = 'bind_mismatch';
                                    }
                                } else {
                                    echo "bind: не найден (Redis будет слушать на всех интерфейсах)\n";
                                    $issuesFound[] = 'bind_missing';
                                }
                                
                                // Проверка protected-mode
                                if (preg_match('/^protected-mode\s+(.+)$/mi', $configContent, $matches)) {
                                    $protectedModeValue = strtolower(trim($matches[1]));
                                    echo "protected-mode: " . htmlspecialchars($protectedModeValue) . "\n";
                                    
                                    if ($protectedModeValue === 'yes') {
                                        // Проверяем, есть ли пароль или bind к localhost
                                        $hasPassword = preg_match('/^requirepass\s+\S+$/mi', $configContent, $passwordMatch);
                                        if (!$hasPassword) {
                                            echo "⚠ ВНИМАНИЕ: protected-mode включен, но пароль не установлен!\n";
                                            echo "   Redis будет принимать подключения только с 127.0.0.1\n";
                                            $issuesFound[] = 'protected_mode_no_password';
                                        }
                                    }
                                }
                                
                                // Проверка requirepass
                                if (preg_match('/^requirepass\s+(.+)$/mi', $configContent, $matches)) {
                                    $passwordValue = trim($matches[1]);
                                    if (strpos($passwordValue, '#') === 0 || empty($passwordValue)) {
                                        echo "requirepass: закомментирован или пуст (пароль не установлен)\n";
                                        if (preg_match('/^protected-mode\s+yes/mi', $configContent)) {
                                            echo "⚠ ВНИМАНИЕ: protected-mode включен, но пароль не установлен!\n";
                                        }
                                    } else {
                                        echo "requirepass: установлен (пароль скрыт) ✓\n";
                                        if (empty($password)) {
                                            echo "⚠ ВНИМАНИЕ: В конфигурации приложения пароль не указан, но Redis требует пароль!\n";
                                            $issuesFound[] = 'password_required';
                                        }
                                    }
                                } else {
                                    echo "requirepass: не установлен\n";
                                }
                                
                                // Проверка port
                                if (preg_match('/^port\s+(.+)$/mi', $configContent, $matches)) {
                                    $portValue = trim($matches[1]);
                                    if ($portValue === '%redisport%') {
                                        echo "port: %redisport% (шаблон Open Server, будет заменен)\n";
                                    } elseif (is_numeric($portValue)) {
                                        $configPort = (int)$portValue;
                                        echo "port: " . $configPort . "\n";
                                        if ($configPort !== $port) {
                                            echo "⚠ ВНИМАНИЕ: Порт в конфиге (" . $configPort . ") отличается от настройки приложения (" . $port . ")!\n";
                                            $issuesFound[] = 'port_mismatch';
                                        }
                                    } else {
                                        echo "port: " . htmlspecialchars($portValue) . "\n";
                                    }
                                }
                                
                                echo '</pre>';
                                
                                // Рекомендации по исправлению
                                if (!empty($issuesFound)) {
                                    echo '<div style="background: #fef3c7; padding: 15px; border-radius: 8px; margin-top: 15px;">';
                                    echo '<h4 style="margin-top: 0; color: #92400e;">⚠ Рекомендации по исправлению:</h4>';
                                    echo '<ol>';
                                    
                                    if (in_array('bind_placeholder', $issuesFound)) {
                                        echo '<li><strong>bind %ip%:</strong> Проверьте, что Open Server правильно заменяет этот шаблон. Обычно должно быть <code>bind 127.0.0.1</code> для локальной разработки.</li>';
                                    }
                                    if (in_array('bind_all', $issuesFound)) {
                                        echo '<li><strong>bind на всех интерфейсах:</strong> Измените в конфиге: <code>bind 127.0.0.1</code> для безопасности.</li>';
                                    }
                                    if (in_array('password_required', $issuesFound)) {
                                        echo '<li><strong>Пароль требуется:</strong> Добавьте пароль в <code>config/app.php</code>: <code>REDIS_PASSWORD=ваш_пароль</code> или в .env файл.</li>';
                                    }
                                    if (in_array('protected_mode_no_password', $issuesFound)) {
                                        echo '<li><strong>Protected mode включен без пароля:</strong><br>';
                                        echo '   <strong>Вариант 1 (рекомендуется для локальной разработки):</strong> Отключите protected-mode:<br>';
                                        echo '   В файле <code>' . htmlspecialchars($configPath) . '</code> измените:<br>';
                                        echo '   <code>protected-mode yes</code> → <code>protected-mode no</code><br><br>';
                                        echo '   <strong>Вариант 2:</strong> Убедитесь, что bind заменяется на 127.0.0.1<br>';
                                        echo '   Open Server должен заменить <code>bind %ip%</code> на <code>bind 127.0.0.1</code><br>';
                                        echo '   Если это не происходит, вручную измените в конфиге: <code>bind 127.0.0.1</code></li>';
                                    }
                                    if (in_array('port_mismatch', $issuesFound)) {
                                        echo '<li><strong>Порт не совпадает:</strong> Обновите порт в <code>config/app.php</code> или в конфиге Redis.</li>';
                                    }
                                    
                                    echo '</ol>';
                                    echo '</div>';
                                } else {
                                    echo '<p class="success">✓ Настройки безопасности выглядят правильно!</p>';
                                }
                                
                                $configFound = true;
                                break;
                            }
                        }
                        
                        if (!$configFound) {
                            echo '<p class="warning">⚠ Конфигурационный файл не найден в стандартных местах.</p>';
                            echo '<p>Проверьте файл redis.conf вручную. Обычно он находится в:</p>';
                            echo '<ul>';
                            echo '<li>F:\\OpenServer\\userdata\\config\\Redis-5.0.conf (Open Server конфиг)</li>';
                            echo '<li>F:\\OpenServer\\modules\\redis\\Redis-5.0\\redis.conf</li>';
                            echo '<li>Или в директории установки Redis</li>';
                            echo '</ul>';
                        }
                    }
                } catch (\Exception $e) {
                    echo '<p class="warning">⚠ Не удалось прочитать конфигурацию: ' . htmlspecialchars($e->getMessage()) . '</p>';
                }
            }
        } catch (\Exception $e) {
            echo '<p class="error">✗ Ошибка при подключении: ' . htmlspecialchars($e->getMessage()) . '</p>';
            echo '<pre>' . htmlspecialchars($e->getTraceAsString()) . '</pre>';
        } catch (\Throwable $e) {
            echo '<p class="error">✗ Критическая ошибка: ' . htmlspecialchars($e->getMessage()) . '</p>';
            echo '<pre>' . htmlspecialchars($e->getTraceAsString()) . '</pre>';
        }
        
        echo '</div>';
        
        // Проверка через класс Cache
        echo '<div class="card">';
        echo '<h2>Проверка через класс Cache</h2>';
        
        try {
            if (class_exists('OGAS\Core\Cache')) {
                $cacheInfo = \OGAS\Core\Cache::getInfo();
                echo '<pre>';
                echo "Enabled: " . ($cacheInfo['enabled'] ?? 'не указано') . "\n";
                echo "Redis Available: " . ($cacheInfo['redis_available'] ? 'да' : 'нет') . "\n";
                echo "Redis Connected: " . ($cacheInfo['redis_connected'] ? 'да' : 'нет') . "\n";
                echo "Driver: " . htmlspecialchars($cacheInfo['driver'] ?? 'не указано') . "\n";
                echo "Logging Enabled: " . ($cacheInfo['logging_enabled'] ? 'да' : 'нет') . "\n";
                echo '</pre>';
                
                // Диагностика проблем
                if (!($cacheInfo['enabled'] ?? true)) {
                    echo '<p class="warning">⚠ Кэширование ОТКЛЮЧЕНО в настройках. Включите его в админ-панели.</p>';
                }
                
                if (!($cacheInfo['redis_available'] ?? false)) {
                    echo '<p class="warning">⚠ Redis недоступен для класса Cache.</p>';
                    echo '<p>Возможные причины:</p>';
                    echo '<ul>';
                    echo '<li>Кэширование отключено</li>';
                    echo '<li>Расширение Redis не установлено</li>';
                    echo '<li>Ошибка подключения при инициализации (проверьте логи PHP)</li>';
                    echo '<li>Неправильные настройки подключения</li>';
                    echo '</ul>';
                    
                    echo '<p><button onclick="if(confirm(\'Выполнить переподключение к Redis?\')) { window.location.href = window.location.pathname + \'?reconnect=1\'; }" class="btn" style="padding: 10px 20px; background: #3b82f6; color: white; border: none; border-radius: 4px; cursor: pointer;">Переподключиться к Redis</button></p>';
                }
                
                if (!empty($cacheInfo['redis_info'])) {
                    echo '<h3>Информация о Redis из Cache:</h3>';
                    echo '<pre>';
                    if (is_array($cacheInfo['redis_info'])) {
                        foreach ($cacheInfo['redis_info'] as $key => $value) {
                            if (is_scalar($value)) {
                                echo htmlspecialchars($key) . ': ' . htmlspecialchars($value) . "\n";
                            }
                        }
                    } else {
                        echo htmlspecialchars($cacheInfo['redis_info']);
                    }
                    echo '</pre>';
                    
                    if (isset($cacheInfo['redis_db_size'])) {
                        echo '<p class="info">Количество ключей в Redis: ' . htmlspecialchars($cacheInfo['redis_db_size']) . '</p>';
                    }
                }
            } else {
                echo '<p class="error">Класс Cache не найден</p>';
            }
        } catch (\Exception $e) {
            echo '<p class="error">Ошибка: ' . htmlspecialchars($e->getMessage()) . '</p>';
            echo '<pre>' . htmlspecialchars($e->getTraceAsString()) . '</pre>';
        }
        
        echo '</div>';
        
        // Обработка переподключения
        if (isset($_GET['reconnect']) && $_GET['reconnect'] == '1') {
            echo '<div class="card">';
            echo '<h2>Переподключение к Redis</h2>';
            try {
                if (class_exists('OGAS\Core\Cache')) {
                    $reconnected = \OGAS\Core\Cache::reconnect();
                    if ($reconnected) {
                        echo '<p class="success">✓ Переподключение успешно!</p>';
                        echo '<p><a href="?">Обновить страницу</a></p>';
                    } else {
                        echo '<p class="error">✗ Не удалось переподключиться. Проверьте настройки и логи.</p>';
                    }
                }
            } catch (\Exception $e) {
                echo '<p class="error">Ошибка при переподключении: ' . htmlspecialchars($e->getMessage()) . '</p>';
            }
            echo '</div>';
        }
    }
    ?>
    
    <div class="card">
        <h2>Рекомендации по безопасности Redis</h2>
        
        <h3>Для локальной разработки (Open Server на Windows):</h3>
        <ul>
            <li><strong>Порт:</strong> Убедитесь, что Redis слушает на 127.0.0.1:6379</li>
            <li><strong>Пароль:</strong> Для локальной разработки пароль не обязателен, но рекомендуется</li>
            <li><strong>Файрвол:</strong> Проверьте, что Windows Firewall не блокирует порт 6379 для PHP</li>
            <li><strong>Bind:</strong> Redis должен быть привязан к 127.0.0.1 (не к 0.0.0.0 или внешнему IP)</li>
        </ul>
        
        <h3>Если Redis не подключается:</h3>
        <ol>
            <li><strong>Проверьте файрвол Windows:</strong>
                <ul>
                    <li>Откройте "Брандмауэр Защитника Windows"</li>
                    <li>Проверьте правила для порта 6379</li>
                    <li>Разрешите подключения для PHP (php.exe) к порту 6379</li>
                </ul>
            </li>
            <li><strong>Проверьте конфигурационный файл Redis:</strong>
                <ul>
                    <li>Обычно: <code>F:\OpenServer\modules\redis\Redis-5.0\redis.conf</code></li>
                    <li>Убедитесь, что <code>bind 127.0.0.1</code> (или <code>bind *</code> для всех интерфейсов)</li>
                    <li>Если установлен <code>requirepass</code>, добавьте пароль в config/app.php</li>
                </ul>
            </li>
            <li><strong>Проверьте настройки в config/app.php:</strong>
                <ul>
                    <li><code>REDIS_HOST</code> должен быть <code>127.0.0.1</code> или <code>localhost</code></li>
                    <li><code>REDIS_PORT</code> должен быть <code>6379</code></li>
                    <li>Если Redis требует пароль, установите <code>REDIS_PASSWORD</code></li>
                </ul>
            </li>
        </ol>
        
        <h3>Общие рекомендации:</h3>
        <ul>
            <li>Если расширение Redis не установлено, установите его через Open Server или вручную</li>
            <li>Убедитесь, что Redis сервер запущен (проверьте в диспетчере задач)</li>
            <li>Для продакшена рекомендуется установить пароль и использовать защищенное подключение</li>
        </ul>
    </div>
    
    <div class="card">
        <a href="/admin/cache.php">← Вернуться к управлению кэшем</a>
    </div>
</body>
</html>
