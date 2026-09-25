<?php
/**
 * Скрипт для проверки подключения к базе данных
 * Откройте в браузере: http://ogas/test-db-connection.php
 */

require_once __DIR__ . '/../src/bootstrap.php';

use OGAS\Database;

header('Content-Type: text/html; charset=UTF-8');
?>
<!DOCTYPE html>
<html lang="ru">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Проверка подключения к БД</title>
    <style>
        body {
            font-family: Arial, sans-serif;
            max-width: 800px;
            margin: 50px auto;
            padding: 20px;
            background: #f5f5f5;
        }
        .container {
            background: white;
            padding: 30px;
            border-radius: 8px;
            box-shadow: 0 2px 10px rgba(0,0,0,0.1);
        }
        h1 {
            color: #333;
            border-bottom: 2px solid #4CAF50;
            padding-bottom: 10px;
        }
        .success {
            background: #d4edda;
            color: #155724;
            padding: 15px;
            border-radius: 5px;
            margin: 15px 0;
            border-left: 4px solid #28a745;
        }
        .error {
            background: #f8d7da;
            color: #721c24;
            padding: 15px;
            border-radius: 5px;
            margin: 15px 0;
            border-left: 4px solid #dc3545;
        }
        .info {
            background: #d1ecf1;
            color: #0c5460;
            padding: 15px;
            border-radius: 5px;
            margin: 15px 0;
            border-left: 4px solid #17a2b8;
        }
        pre {
            background: #f8f9fa;
            padding: 10px;
            border-radius: 5px;
            overflow-x: auto;
        }
        .config-table {
            width: 100%;
            border-collapse: collapse;
            margin: 15px 0;
        }
        .config-table th,
        .config-table td {
            padding: 10px;
            text-align: left;
            border-bottom: 1px solid #ddd;
        }
        .config-table th {
            background: #f8f9fa;
            font-weight: bold;
        }
    </style>
</head>
<body>
    <div class="container">
        <h1>🔍 Проверка подключения к базе данных</h1>
        
        <?php
        $config = require __DIR__ . '/../config/app.php';
        $dbConfig = $config['database'];
        
        echo '<div class="info">';
        echo '<h3>Текущие настройки подключения:</h3>';
        echo '<table class="config-table">';
        echo '<tr><th>Параметр</th><th>Значение</th></tr>';
        echo '<tr><td>Хост</td><td>' . htmlspecialchars($dbConfig['host']) . '</td></tr>';
        echo '<tr><td>Порт</td><td>' . htmlspecialchars($dbConfig['port']) . '</td></tr>';
        echo '<tr><td>База данных</td><td>' . htmlspecialchars($dbConfig['database']) . '</td></tr>';
        echo '<tr><td>Пользователь</td><td>' . htmlspecialchars($dbConfig['username']) . '</td></tr>';
        echo '<tr><td>Пароль</td><td>' . (empty($dbConfig['password']) ? '<em>(пусто)</em>' : '***') . '</td></tr>';
        echo '</table>';
        echo '</div>';
        
        try {
            // Пытаемся подключиться
            Database::init($dbConfig);
            $db = Database::getConnection();
            
            echo '<div class="success">';
            echo '<h3>✅ Подключение успешно!</h3>';
            echo '<p>Соединение с базой данных установлено.</p>';
            
            // Проверяем версию MySQL
            $stmt = $db->query('SELECT VERSION() as version');
            $version = $stmt->fetch();
            echo '<p><strong>Версия MySQL:</strong> ' . htmlspecialchars($version['version']) . '</p>';
            
            // Проверяем существование базы данных
            $stmt = $db->query('SELECT DATABASE() as db');
            $currentDb = $stmt->fetch();
            echo '<p><strong>Текущая база данных:</strong> ' . htmlspecialchars($currentDb['db']) . '</p>';
            
            // Проверяем наличие таблиц
            $stmt = $db->query('SHOW TABLES');
            $tables = $stmt->fetchAll(PDO::FETCH_COLUMN);
            echo '<p><strong>Количество таблиц:</strong> ' . count($tables) . '</p>';
            
            if (count($tables) > 0) {
                echo '<p><strong>Таблицы:</strong> ' . implode(', ', array_slice($tables, 0, 10));
                if (count($tables) > 10) {
                    echo ' и еще ' . (count($tables) - 10) . '...';
                }
                echo '</p>';
            } else {
                echo '<div class="info">';
                echo '<p><strong>⚠️ База данных пуста.</strong> Необходимо импортировать структуру из <code>database/schema.sql</code></p>';
                echo '</div>';
            }
            
            echo '</div>';
            
        } catch (\Exception $e) {
            echo '<div class="error">';
            echo '<h3>❌ Ошибка подключения</h3>';
            echo '<pre>' . htmlspecialchars($e->getMessage()) . '</pre>';
            echo '</div>';
            
            echo '<div class="info">';
            echo '<h3>💡 Рекомендации по устранению проблемы:</h3>';
            echo '<ol>';
            echo '<li><strong>Проверьте, запущен ли MySQL в Open Server:</strong><br>';
            echo '   - Откройте панель Open Server<br>';
            echo '   - Убедитесь, что MySQL запущен (зеленый индикатор)</li>';
            echo '<li><strong>Проверьте настройки в файле .env:</strong><br>';
            echo '   - Убедитесь, что файл .env существует в корне проекта<br>';
            echo '   - Проверьте значения DB_HOST, DB_PORT, DB_DATABASE, DB_USERNAME, DB_PASSWORD</li>';
            echo '<li><strong>Попробуйте изменить DB_HOST:</strong><br>';
            echo '   - Если указан localhost, попробуйте 127.0.0.1<br>';
            echo '   - Если указан 127.0.0.1, попробуйте localhost</li>';
            echo '<li><strong>Проверьте пароль MySQL:</strong><br>';
            echo '   - В Open Server пароль обычно пустой или "root"<br>';
            echo '   - Попробуйте оба варианта в файле .env</li>';
            echo '<li><strong>Создайте базу данных:</strong><br>';
            echo '   - Откройте phpMyAdmin: <a href="http://localhost/openserver/phpmyadmin/" target="_blank">http://localhost/openserver/phpmyadmin/</a><br>';
            echo '   - Создайте базу данных с именем: <code>' . htmlspecialchars($dbConfig['database']) . '</code><br>';
            echo '   - Или импортируйте <code>database/schema.sql</code></li>';
            echo '</ol>';
            echo '</div>';
        }
        ?>
        
        <div class="info">
            <h3>📝 Следующие шаги:</h3>
            <ul>
                <li>Если подключение успешно, но база пуста - импортируйте <code>database/schema.sql</code></li>
                <li>Если есть ошибки - проверьте настройки в файле <code>.env</code></li>
                <li>Убедитесь, что MySQL запущен в Open Server</li>
            </ul>
        </div>
    </div>
</body>
</html>



