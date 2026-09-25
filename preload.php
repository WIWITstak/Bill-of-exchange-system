<?php
/**
 * OPcache Preload для ОГАС
 * 
 * Этот файл предзагружает критичные классы в OPcache при старте PHP-FPM/Apache
 * Требует PHP 7.4+ и включенный OPcache
 * 
 * УСТАНОВКА:
 * Добавьте в php.ini:
 * opcache.preload=/path/to/your/project/preload.php
 * 
 * Например:
 * opcache.preload=F:\OpenServer\domains\xn--80af6ao.xn--p1ai\preload.php
 * 
 * ВНИМАНИЕ:
 * - После изменения этого файла нужно перезапустить веб-сервер
 * - Используйте АБСОЛЮТНЫЕ пути
 * - Для Open Server путь должен быть полным (F:\OpenServer\...)
 */

// Получаем корневую директорию проекта
$projectRoot = __DIR__;

echo "🚀 OPcache Preload: начинаем предзагрузку классов ОГАС...\n";
$startTime = microtime(true);
$loadedCount = 0;
$errorCount = 0;

/**
 * Функция безопасной предзагрузки файла
 */
function preloadFile(string $file): bool
{
    global $loadedCount, $errorCount;
    
    if (!file_exists($file)) {
        echo "⚠️  Пропущен (не найден): {$file}\n";
        $errorCount++;
        return false;
    }
    
    try {
        opcache_compile_file($file);
        echo "✅ Загружен: " . basename($file) . "\n";
        $loadedCount++;
        return true;
    } catch (\Throwable $e) {
        echo "❌ Ошибка при загрузке {$file}: {$e->getMessage()}\n";
        $errorCount++;
        return false;
    }
}

// ============================================================
// 1. BOOTSTRAP И ОСНОВНЫЕ ФАЙЛЫ
// ============================================================

echo "\n📦 1. Bootstrap и базовые файлы...\n";

preloadFile($projectRoot . '/src/bootstrap.php');
preloadFile($projectRoot . '/src/Database.php');
preloadFile($projectRoot . '/src/System.php');

// ============================================================
// 2. CORE КЛАССЫ (критично важные)
// ============================================================

echo "\n🔧 2. Core классы...\n";

$coreClasses = [
    '/src/Core/Security.php',
    '/src/Core/Session.php',
    '/src/Core/Cache.php',
    '/src/Core/CacheLogger.php',
    '/src/Core/RateLimiter.php',
    '/src/Core/SecurityLogger.php',
    '/src/Core/BruteForceProtection.php',
    '/src/Core/BotProtection.php',
    '/src/Core/Router.php',
];

foreach ($coreClasses as $file) {
    preloadFile($projectRoot . $file);
}

// ============================================================
// 3. МОДЕЛИ (самые часто используемые)
// ============================================================

echo "\n📊 3. Модели данных...\n";

$models = [
    '/src/Models/User.php',              // Самая частая
    '/src/Models/Transaction.php',       // Часто
    '/src/Models/Bill.php',              // Часто
    '/src/Models/Message.php',           // Часто (чаты)
    '/src/Models/Category.php',          // Средне
    '/src/Models/Product.php',           // Средне
    '/src/Models/Rating.php',            // Средне
    '/src/Models/RatingHistory.php',     // Редко
    '/src/Models/Company.php',           // Средне
    '/src/Models/Notification.php',      // Часто
    '/src/Models/CommunityRequest.php',  // Средне
    '/src/Models/UserSession.php',       // Часто
    '/src/Models/Subscription.php',      // Редко
    '/src/Models/PasswordReset.php',     // Редко
    '/src/Models/Ticket.php',            // Редко
    '/src/Models/SupportMessage.php',    // Редко
];

foreach ($models as $file) {
    preloadFile($projectRoot . $file);
}

// ============================================================
// 4. СЕРВИСЫ (бизнес-логика)
// ============================================================

echo "\n⚙️  4. Сервисы...\n";

$services = [
    '/src/Services/Auth.php',                      // Критично
    '/src/Services/TransactionService.php',        // Часто
    '/src/Services/BillService.php',               // Часто
    '/src/Services/ChatService.php',               // Часто
    '/src/Services/NotificationService.php',       // Часто
    '/src/Services/RegistrationService.php',       // Средне
    '/src/Services/PasswordResetService.php',      // Редко
    '/src/Services/CommunityService.php',          // Средне
    '/src/Services/AdminService.php',              // Средне
    '/src/Services/WebSocketService.php',          // Средне
    '/src/Services/BillPdfService.php',            // Средне
    '/src/Services/SubscriptionService.php',       // Редко
    '/src/Services/SupportService.php',            // Редко
    '/src/Services/CacheSettingsService.php',      // Редко
    '/src/Services/DatabaseLogService.php',        // Редко
    '/src/Services/WebSocketLoggingService.php',   // Редко
    '/src/Services/ServerMonitoringService.php',   // Редко
];

foreach ($services as $file) {
    preloadFile($projectRoot . $file);
}

// ============================================================
// 5. HELPERS
// ============================================================

echo "\n🔨 5. Вспомогательные классы...\n";

if (file_exists($projectRoot . '/src/Helpers/helpers.php')) {
    preloadFile($projectRoot . '/src/Helpers/helpers.php');
}

if (file_exists($projectRoot . '/src/Helpers/QueryOptimizer.php')) {
    preloadFile($projectRoot . '/src/Helpers/QueryOptimizer.php');
}

// ============================================================
// 6. COMPOSER AUTOLOAD (опционально)
// ============================================================

echo "\n📚 6. Composer autoloader...\n";

if (file_exists($projectRoot . '/vendor/autoload.php')) {
    // Не загружаем напрямую, только регистрируем
    echo "ℹ️  Composer autoload зарегистрирован\n";
    
    // Можно предзагрузить критичные библиотеки:
    // preloadFile($projectRoot . '/vendor/tecnickcom/tcpdf/tcpdf.php');
}

// ============================================================
// СТАТИСТИКА
// ============================================================

$endTime = microtime(true);
$duration = round(($endTime - $startTime) * 1000, 2);

echo "\n" . str_repeat("=", 70) . "\n";
echo "📊 Статистика предзагрузки:\n";
echo "   ✅ Загружено файлов: {$loadedCount}\n";
echo "   ❌ Ошибок: {$errorCount}\n";
echo "   ⏱️  Время: {$duration} мс\n";
echo str_repeat("=", 70) . "\n";

if ($loadedCount > 0) {
    echo "✅ OPcache Preload завершен успешно!\n";
} else {
    echo "⚠️  Предупреждение: не загружено ни одного файла!\n";
}

echo "\n💡 Информация об OPcache:\n";
if (function_exists('opcache_get_status')) {
    $status = opcache_get_status();
    if ($status) {
        echo "   Память: " . round($status['memory_usage']['used_memory'] / 1024 / 1024, 2) . " MB\n";
        echo "   Скриптов в кэше: " . ($status['opcache_statistics']['num_cached_scripts'] ?? 0) . "\n";
    }
}

echo "\n🎉 Preload готов! Теперь эти классы загружены в память.\n\n";





