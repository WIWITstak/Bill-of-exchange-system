<?php
/**
 * Страница просмотра логов кэша (админ-панель)
 */

require_once __DIR__ . '/../../src/bootstrap.php';

use OGAS\Services\Auth;
use OGAS\Services\AdminService;
use OGAS\Core\SecurityLogger;
use OGAS\Core\CacheLogger;

Auth::requireAuth();
AdminService::requireAdmin();

$user = Auth::user();

// Логируем доступ к логам кэша
SecurityLogger::logAdminAccess('view_cache_logs', true);

// Путь к файлу логов
$logFile = __DIR__ . '/../../storage/logs/cache.log';

// Параметры фильтрации и пагинации
$filter = $_GET['filter'] ?? '';
$level = $_GET['level'] ?? '';
$limit = max(1, min(1000, (int)($_GET['limit'] ?? 100)));
$page = max(1, (int)($_GET['page'] ?? 1));
$offset = ($page - 1) * $limit;

// Читаем логи
$logs = [];
$totalLines = 0;

if (file_exists($logFile) && is_readable($logFile)) {
    $allLogs = CacheLogger::getRecentLogs(10000); // Получаем много логов для фильтрации
    $totalLines = count($allLogs);
    
    // Фильтрация
    if (!empty($filter)) {
        $allLogs = array_filter($allLogs, function($log) use ($filter) {
            $searchText = json_encode($log);
            return stripos($searchText, $filter) !== false;
        });
    }
    
    if (!empty($level)) {
        $allLogs = array_filter($allLogs, function($log) use ($level) {
            return ($log['level'] ?? 'info') === $level;
        });
    }
    
    // Пагинация
    $totalFiltered = count($allLogs);
    $totalPages = ceil($totalFiltered / $limit);
    $allLogs = array_values($allLogs);
    $logs = array_slice($allLogs, $offset, $limit);
} else {
    $error = 'Файл логов не найден или недоступен для чтения: ' . htmlspecialchars($logFile);
}

// Статистика
$stats = [
    'total_lines' => $totalLines,
    'file_size' => file_exists($logFile) ? filesize($logFile) : 0,
    'file_modified' => file_exists($logFile) ? filemtime($logFile) : 0,
    'error_count' => 0,
    'warning_count' => 0,
    'info_count' => 0
];

foreach ($logs as $log) {
    $logLevel = $log['level'] ?? 'info';
    switch ($logLevel) {
        case 'error':
            $stats['error_count']++;
            break;
        case 'warning':
            $stats['warning_count']++;
            break;
        case 'info':
        default:
            $stats['info_count']++;
            break;
    }
}

$title = 'Логи кэша';
ob_start();
?>

<div class="dashboard">
    <div class="dashboard-header">
        <h2>Логи кэша</h2>
        <div class="header-actions">
            <a href="/admin/cache.php" class="btn btn-secondary">← Управление кэшем</a>
            <a href="/admin/index.php" class="btn btn-secondary">← Админ-панель</a>
        </div>
    </div>

    <!-- Статистика логов -->
    <div class="admin-section">
        <h3>Статистика</h3>
        <div class="stats-grid">
            <div class="stat-card">
                <div class="stat-label">Всего записей</div>
                <div class="stat-value"><?= number_format($stats['total_lines']) ?></div>
            </div>
            <div class="stat-card">
                <div class="stat-label">Размер файла</div>
                <div class="stat-value">
                    <?php
                    $size = $stats['file_size'];
                    if ($size < 1024) {
                        echo $size . ' Б';
                    } elseif ($size < 1024 * 1024) {
                        echo number_format($size / 1024, 2) . ' КБ';
                    } else {
                        echo number_format($size / (1024 * 1024), 2) . ' МБ';
                    }
                    ?>
                </div>
            </div>
            <div class="stat-card">
                <div class="stat-label">Последнее обновление</div>
                <div class="stat-value" style="font-size: 14px;">
                    <?php
                    if ($stats['file_modified']) {
                        echo date('d.m.Y H:i:s', $stats['file_modified']);
                    } else {
                        echo 'Нет данных';
                    }
                    ?>
                </div>
            </div>
            <div class="stat-card">
                <div class="stat-label">Ошибки</div>
                <div class="stat-value text-danger"><?= number_format($stats['error_count']) ?></div>
            </div>
            <div class="stat-card">
                <div class="stat-label">Предупреждения</div>
                <div class="stat-value text-warning"><?= number_format($stats['warning_count']) ?></div>
            </div>
            <div class="stat-card">
                <div class="stat-label">Информация</div>
                <div class="stat-value text-success"><?= number_format($stats['info_count']) ?></div>
            </div>
        </div>
    </div>

    <!-- Фильтры -->
    <div class="admin-section">
        <h3>Фильтры</h3>
        <form method="GET" class="filter-form">
            <div class="filter-row">
                <div class="filter-group">
                    <label>Поиск:</label>
                    <input type="text" name="filter" value="<?= htmlspecialchars($filter) ?>" 
                           class="form-control" placeholder="Поиск по логам...">
                </div>
                <div class="filter-group">
                    <label>Уровень:</label>
                    <select name="level" class="form-control">
                        <option value="">Все</option>
                        <option value="error" <?= $level === 'error' ? 'selected' : '' ?>>Ошибки</option>
                        <option value="warning" <?= $level === 'warning' ? 'selected' : '' ?>>Предупреждения</option>
                        <option value="info" <?= $level === 'info' ? 'selected' : '' ?>>Информация</option>
                        <option value="debug" <?= $level === 'debug' ? 'selected' : '' ?>>Отладка</option>
                    </select>
                </div>
                <div class="filter-group">
                    <label>На странице:</label>
                    <select name="limit" class="form-control">
                        <option value="50" <?= $limit === 50 ? 'selected' : '' ?>>50</option>
                        <option value="100" <?= $limit === 100 ? 'selected' : '' ?>>100</option>
                        <option value="200" <?= $limit === 200 ? 'selected' : '' ?>>200</option>
                        <option value="500" <?= $limit === 500 ? 'selected' : '' ?>>500</option>
                    </select>
                </div>
                <div class="filter-group">
                    <button type="submit" class="btn btn-primary">Применить</button>
                    <a href="/admin/cache-logs.php" class="btn btn-secondary">Сбросить</a>
                </div>
            </div>
        </form>
    </div>

    <!-- Логи -->
    <div class="admin-section">
        <h3>Логи кэша</h3>
        <?php if (isset($error)): ?>
            <div class="alert alert-error"><?= $error ?></div>
        <?php elseif (empty($logs)): ?>
            <div class="alert alert-info">Логов не найдено</div>
        <?php else: ?>
            <div class="logs-container">
                <?php foreach ($logs as $log): ?>
                    <?php
                    $logLevel = $log['level'] ?? 'info';
                    $operation = $log['operation'] ?? 'unknown';
                    $data = $log['data'] ?? [];
                    ?>
                    <div class="log-entry log-<?= $logLevel ?>">
                        <div class="log-header">
                            <span class="log-timestamp"><?= htmlspecialchars($log['timestamp'] ?? '') ?></span>
                            <span class="log-level log-level-<?= $logLevel ?>"><?= strtoupper($logLevel) ?></span>
                            <span class="log-operation"><?= htmlspecialchars($operation) ?></span>
                            <?php if (isset($log['user_id'])): ?>
                                <span class="log-user">User ID: <?= $log['user_id'] ?></span>
                            <?php endif; ?>
                        </div>
                        <div class="log-data">
                            <?php if (!empty($data)): ?>
                                <pre><?= htmlspecialchars(json_encode($data, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE)) ?></pre>
                            <?php endif; ?>
                        </div>
                    </div>
                <?php endforeach; ?>
            </div>

            <!-- Пагинация -->
            <?php if (isset($totalPages) && $totalPages > 1): ?>
                <div class="pagination">
                    <?php if ($page > 1): ?>
                        <a href="?page=<?= $page - 1 ?>&limit=<?= $limit ?>&filter=<?= urlencode($filter) ?>&level=<?= urlencode($level) ?>" 
                           class="btn btn-secondary">← Назад</a>
                    <?php endif; ?>
                    
                    <span class="pagination-info">
                        Страница <?= $page ?> из <?= $totalPages ?>
                    </span>
                    
                    <?php if ($page < $totalPages): ?>
                        <a href="?page=<?= $page + 1 ?>&limit=<?= $limit ?>&filter=<?= urlencode($filter) ?>&level=<?= urlencode($level) ?>" 
                           class="btn btn-secondary">Вперед →</a>
                    <?php endif; ?>
                </div>
            <?php endif; ?>
        <?php endif; ?>
    </div>
</div>

<style>
.stats-grid {
    display: grid;
    grid-template-columns: repeat(auto-fit, minmax(150px, 1fr));
    gap: 15px;
    margin-top: 15px;
}

.stat-card {
    background: white;
    padding: 20px;
    border-radius: 8px;
    border: 1px solid #e0e0e0;
    text-align: center;
}

.stat-label {
    font-size: 12px;
    color: #666;
    margin-bottom: 10px;
}

.stat-value {
    font-size: 24px;
    font-weight: bold;
    color: #333;
}

.filter-form {
    background: white;
    padding: 20px;
    border-radius: 8px;
    border: 1px solid #e0e0e0;
}

.filter-row {
    display: flex;
    flex-wrap: wrap;
    gap: 15px;
    align-items: flex-end;
}

.filter-group {
    flex: 1;
    min-width: 150px;
}

.filter-group label {
    display: block;
    margin-bottom: 5px;
    font-size: 12px;
    color: #666;
}

.form-control {
    width: 100%;
    padding: 8px 12px;
    border: 1px solid #ddd;
    border-radius: 4px;
}

.logs-container {
    background: white;
    border: 1px solid #e0e0e0;
    border-radius: 8px;
    max-height: 800px;
    overflow-y: auto;
}

.log-entry {
    padding: 15px;
    border-bottom: 1px solid #e0e0e0;
}

.log-entry:last-child {
    border-bottom: none;
}

.log-entry.log-error {
    background: #fee2e2;
    border-left: 4px solid #ef4444;
}

.log-entry.log-warning {
    background: #fef3c7;
    border-left: 4px solid #f59e0b;
}

.log-entry.log-info {
    background: #dbeafe;
    border-left: 4px solid #3b82f6;
}

.log-header {
    display: flex;
    gap: 15px;
    align-items: center;
    margin-bottom: 10px;
    flex-wrap: wrap;
}

.log-timestamp {
    font-weight: 500;
    color: #333;
}

.log-level {
    padding: 2px 8px;
    border-radius: 4px;
    font-size: 11px;
    font-weight: bold;
}

.log-level-error { background: #ef4444; color: white; }
.log-level-warning { background: #f59e0b; color: white; }
.log-level-info { background: #3b82f6; color: white; }
.log-level-debug { background: #6b7280; color: white; }

.log-operation {
    font-weight: 500;
    color: #666;
}

.log-user {
    font-size: 12px;
    color: #999;
}

.log-data {
    margin-top: 10px;
}

.log-data pre {
    margin: 0;
    padding: 10px;
    background: rgba(0,0,0,0.05);
    border-radius: 4px;
    font-size: 12px;
    overflow-x: auto;
}

.pagination {
    display: flex;
    justify-content: center;
    align-items: center;
    gap: 15px;
    margin-top: 20px;
}

.pagination-info {
    color: #666;
}

.alert {
    padding: 15px;
    border-radius: 4px;
    margin-bottom: 20px;
}

.alert-error {
    background: #fee2e2;
    border: 1px solid #ef4444;
    color: #991b1b;
}

.alert-info {
    background: #dbeafe;
    border: 1px solid #3b82f6;
    color: #1e40af;
}

.text-success { color: #10b981; }
.text-warning { color: #f59e0b; }
.text-danger { color: #ef4444; }
</style>

<?php
$content = ob_get_clean();
require_once __DIR__ . '/../../templates/base.php';
?>



