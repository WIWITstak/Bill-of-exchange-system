<?php
/**
 * Скрипт применения индексов для оптимизации производительности
 * 
 * Использование:
 * 1. Через браузер: http://ogas/database/apply_performance_indexes.php
 * 2. Через CLI: php database/apply_performance_indexes.php
 */

// Подключаем bootstrap
require_once __DIR__ . '/../src/bootstrap.php';

// Только для администраторов (если запуск через браузер)
if (php_sapi_name() !== 'cli') {
    use OGAS\Services\Auth;
    
    // Проверяем авторизацию
    if (!Auth::check()) {
        die('Доступ запрещен. Войдите как администратор.');
    }
    
    if (!Auth::user()->isAdmin()) {
        die('Доступ запрещен. Требуются права администратора.');
    }
}

set_time_limit(300); // 5 минут на выполнение

echo "🚀 Применение индексов для оптимизации производительности\n";
echo str_repeat("=", 70) . "\n\n";

try {
    $db = \OGAS\Database::getConnection();
    
    // Читаем SQL файл
    $sqlFile = __DIR__ . '/migrations/performance_indexes.sql';
    
    if (!file_exists($sqlFile)) {
        die("❌ Файл не найден: {$sqlFile}\n");
    }
    
    $sql = file_get_contents($sqlFile);
    
    // Убираем комментарии и разбиваем на отдельные команды
    $sql = preg_replace('/--.*$/m', '', $sql); // Удаляем однострочные комментарии
    $sql = preg_replace('/\/\*.*?\*\//s', '', $sql); // Удаляем многострочные комментарии
    
    // Разбиваем по точке с запятой
    $statements = array_filter(
        array_map('trim', explode(';', $sql)),
        function($stmt) { return !empty($stmt); }
    );
    
    echo "📋 Найдено команд SQL: " . count($statements) . "\n\n";
    
    $executed = 0;
    $errors = 0;
    $skipped = 0;
    
    foreach ($statements as $i => $statement) {
        // Пропускаем USE и SHOW команды
        if (stripos($statement, 'USE ') === 0 || 
            stripos($statement, 'SHOW ') === 0 ||
            stripos($statement, 'SELECT ') === 0 ||
            stripos($statement, 'EXPLAIN ') === 0) {
            continue;
        }
        
        // Извлекаем имя индекса для отображения
        $indexName = 'unknown';
        if (preg_match('/INDEX\s+(?:IF NOT EXISTS\s+)?`?(\w+)`?/i', $statement, $matches)) {
            $indexName = $matches[1];
        }
        
        echo sprintf("[%d/%d] Создание индекса: %s... ", $i + 1, count($statements), $indexName);
        
        try {
            $db->exec($statement);
            echo "✅\n";
            $executed++;
        } catch (\PDOException $e) {
            // Проверяем, не существует ли индекс уже
            if (strpos($e->getMessage(), 'Duplicate key name') !== false ||
                strpos($e->getMessage(), 'already exists') !== false) {
                echo "⚠️  (уже существует)\n";
                $skipped++;
            } else {
                echo "❌ Ошибка: " . $e->getMessage() . "\n";
                $errors++;
            }
        }
    }
    
    echo "\n" . str_repeat("=", 70) . "\n";
    echo "📊 Статистика:\n";
    echo "   ✅ Создано: {$executed}\n";
    echo "   ⚠️  Пропущено (уже существуют): {$skipped}\n";
    echo "   ❌ Ошибок: {$errors}\n";
    echo "\n";
    
    // Оптимизация таблиц
    echo "🔧 Оптимизация таблиц...\n\n";
    
    $tables = [
        'transactions', 'bills', 'messages', 'products', 
        'ratings', 'categories', 'community_requests', 
        'notifications', 'user_sessions', 'users'
    ];
    
    foreach ($tables as $table) {
        echo "   Оптимизация {$table}... ";
        try {
            $db->exec("OPTIMIZE TABLE {$table}");
            echo "✅\n";
        } catch (\PDOException $e) {
            echo "❌ {$e->getMessage()}\n";
        }
    }
    
    echo "\n" . str_repeat("=", 70) . "\n";
    echo "✅ Применение индексов завершено!\n\n";
    
    // Показываем созданные индексы для основных таблиц
    echo "📋 Проверка индексов:\n\n";
    
    $checkTables = ['transactions', 'bills', 'messages'];
    foreach ($checkTables as $table) {
        $stmt = $db->query("SHOW INDEXES FROM {$table}");
        $indexes = $stmt->fetchAll(\PDO::FETCH_ASSOC);
        
        echo "   {$table}: " . count($indexes) . " индексов\n";
    }
    
    echo "\n💡 Рекомендации:\n";
    echo "   1. Проверьте производительность через /admin/monitoring.php\n";
    echo "   2. Мониторьте запросы через /admin/database-logs.php\n";
    echo "   3. Очистите кэш: /admin/cache.php\n";
    echo "\n";
    
    if (php_sapi_name() === 'cli') {
        echo "Готово! Индексы применены.\n";
    } else {
        echo "<br><br><a href='/admin/monitoring.php' class='btn btn-primary'>Перейти к мониторингу</a>";
        echo " <a href='/dashboard.php' class='btn btn-secondary'>На главную</a>";
    }
    
} catch (\Exception $e) {
    echo "\n❌ КРИТИЧЕСКАЯ ОШИБКА:\n";
    echo $e->getMessage() . "\n";
    echo "\nStack trace:\n";
    echo $e->getTraceAsString() . "\n";
    exit(1);
}





