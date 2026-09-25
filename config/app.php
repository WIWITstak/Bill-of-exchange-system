<?php
/**
 * Конфигурация приложения ОГАС
 */

// Получаем параметры из переменных окружения или используем значения по умолчанию
$dbHost = $_ENV['DB_HOST'] ?? getenv('DB_HOST') ?: 'localhost';
$dbPort = (int)($_ENV['DB_PORT'] ?? getenv('DB_PORT') ?: 3306);
$dbDatabase = $_ENV['DB_DATABASE'] ?? getenv('DB_DATABASE') ?: 'ogas';
$dbUsername = $_ENV['DB_USERNAME'] ?? getenv('DB_USERNAME') ?: 'root';
$dbPassword = $_ENV['DB_PASSWORD'] ?? getenv('DB_PASSWORD') ?: '(S-qACm7dAq!1J4o';

return [
    // Настройки окружения
    'env' => $_ENV['APP_ENV'] ?? getenv('APP_ENV') ?: 'development',
    'debug' => filter_var($_ENV['APP_DEBUG'] ?? getenv('APP_DEBUG') ?: true, FILTER_VALIDATE_BOOLEAN),
    
    // Часовой пояс
    'timezone' => $_ENV['APP_TIMEZONE'] ?? getenv('APP_TIMEZONE') ?: 'Europe/Moscow',
    
    // Настройки базы данных
    'database' => [
        'host' => $dbHost,
        'port' => $dbPort,
        'database' => $dbDatabase,
        'username' => $dbUsername,
        'password' => $dbPassword,
        'charset' => 'utf8mb4',
    ],
    
    // Настройки приложения
    'app' => [
        'name' => 'ОГАС',
        'url' => $_ENV['APP_URL'] ?? getenv('APP_URL') ?: 'https://огас.рф',
    ],
    
    // Пути
    'paths' => [
        'storage' => __DIR__ . '/../storage',
        'bills' => __DIR__ . '/../storage/bills',
        'cache' => __DIR__ . '/../storage/cache',
    ],
    
    // Настройки кэширования
    'cache' => [
        'cache_dir' => __DIR__ . '/../storage/cache',
        'default_ttl' => (int)($_ENV['CACHE_DEFAULT_TTL'] ?? getenv('CACHE_DEFAULT_TTL') ?: 3600), // 1 час
        'enabled' => filter_var($_ENV['CACHE_ENABLED'] ?? getenv('CACHE_ENABLED') ?: true, FILTER_VALIDATE_BOOLEAN),
        'logging' => filter_var($_ENV['CACHE_LOGGING'] ?? getenv('CACHE_LOGGING') ?: true, FILTER_VALIDATE_BOOLEAN), // Включить логирование операций с кэшем
        'redis' => [
            'host' => $_ENV['REDIS_HOST'] ?? getenv('REDIS_HOST') ?: '127.0.0.1',
            'port' => (int)($_ENV['REDIS_PORT'] ?? getenv('REDIS_PORT') ?: 6379),
            // Пароль из .env (пустая строка считается как null)
            'password' => !empty($_ENV['REDIS_PASSWORD'] ?? getenv('REDIS_PASSWORD')) 
                ? ($_ENV['REDIS_PASSWORD'] ?? getenv('REDIS_PASSWORD')) 
                : null,
            'database' => (int)($_ENV['REDIS_DATABASE'] ?? getenv('REDIS_DATABASE') ?: 0),
            'timeout' => (float)($_ENV['REDIS_TIMEOUT'] ?? getenv('REDIS_TIMEOUT') ?: 2.0),
        ],
        // Время жизни кэша для разных типов данных (в секундах)
        'ttl' => [
            'categories' => 3600,        // 1 час (категории редко меняются)
            'ratings' => 300,            // 5 минут (рейтинги могут часто обновляться)
            'users' => 1800,             // 30 минут
            'statistics' => 600,         // 10 минут
            'settings' => 86400,         // 24 часа
        ],
    ],
];




