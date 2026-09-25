<?php
/**
 * Страница просмотра логов WebSocket сервера (админ-панель)
 */

require_once __DIR__ . '/../../src/bootstrap.php';

use OGAS\Services\Auth;
use OGAS\Services\AdminService;
use OGAS\Core\SecurityLogger;
use OGAS\Core\Security;

Auth::requireAuth();
AdminService::requireAdmin();

$user = Auth::user();

// Логируем доступ к логам WebSocket
SecurityLogger::logAdminAccess('view_websocket_logs', true);

// Путь к файлу логов
$logFile = __DIR__ . '/../../websocket/server.log';

// Параметры фильтрации и пагинации
$filter = $_GET['filter'] ?? '';
$limit = max(1, min(1000, (int)($_GET['limit'] ?? 100)));
$page = max(1, (int)($_GET['page'] ?? 1));
$offset = ($page - 1) * $limit;

// Читаем логи
$logs = [];
$totalLines = 0;

if (file_exists($logFile) && is_readable($logFile)) {
    // Читаем файл построчно (с конца для последних записей)
    $lines = file($logFile, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES);
    $totalLines = count($lines);
    
    // Переворачиваем массив, чтобы последние записи были первыми
    $lines = array_reverse($lines);
    
    // Фильтрация
    if (!empty($filter)) {
        $lines = array_filter($lines, function($line) use ($filter) {
            return stripos($line, $filter) !== false;
        });
    }
    
    // Пагинация
    $totalFiltered = count($lines);
    $totalPages = ceil($totalFiltered / $limit);
    $lines = array_slice($lines, $offset, $limit);
    
    // Форматируем логи
    foreach ($lines as $line) {
        $logEntry = [
            'raw' => $line,
            'timestamp' => '',
            'level' => 'info',
            'message' => $line
        ];
        
        // Пытаемся извлечь timestamp и уровень из строки
        // Формат: [2025-01-15 12:00:00] ERROR: сообщение
        if (preg_match('/\[([^\]]+)\]\s*(ERROR|WARNING|INFO|DEBUG)?:?\s*(.*)/i', $line, $matches)) {
            $logEntry['timestamp'] = $matches[1] ?? '';
            $logEntry['level'] = strtolower($matches[2] ?? 'info');
            $logEntry['message'] = $matches[3] ?? $line;
        } elseif (preg_match('/^(\d{4}-\d{2}-\d{2}\s+\d{2}:\d{2}:\d{2})\s+(.*)/', $line, $matches)) {
            $logEntry['timestamp'] = $matches[1] ?? '';
            $logEntry['message'] = $matches[2] ?? $line;
            
            // Определяем уровень по содержимому
            $message = strtolower($logEntry['message']);
            if (strpos($message, 'error') !== false || strpos($message, 'ошибка') !== false) {
                $logEntry['level'] = 'error';
            } elseif (strpos($message, 'warning') !== false || strpos($message, 'предупреждение') !== false) {
                $logEntry['level'] = 'warning';
            }
        }
        
        $logs[] = $logEntry;
    }
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
    $level = $log['level'] ?? 'info';
    if ($level === 'error') {
        $stats['error_count']++;
    } elseif ($level === 'warning') {
        $stats['warning_count']++;
    } else {
        $stats['info_count']++;
    }
}

$title = 'Логи WebSocket сервера';
ob_start();
?>
<div class="dashboard">
    <div class="dashboard-header">
        <h2>Логи WebSocket сервера</h2>
        <div class="header-actions">
            <a href="/admin/index.php" class="btn btn-secondary">← Назад к админ-панели</a>
            <?php if (file_exists($logFile)): ?>
                <a href="/admin/websocket-logs.php?action=clear" class="btn btn-danger" 
                   onclick="return confirm('Очистить файл логов? Это действие нельзя отменить.');">
                    Очистить логи
                </a>
            <?php endif; ?>
        </div>
    </div>

    <?php
    // Обработка очистки логов
    if (isset($_GET['action']) && $_GET['action'] === 'clear' && file_exists($logFile)) {
        if (is_writable($logFile)) {
            file_put_contents($logFile, '');
            SecurityLogger::logAdminAccess('clear_websocket_logs', true);
            header('Location: /admin/websocket-logs.php?cleared=1');
            exit;
        } else {
            $error = 'Нет прав на запись в файл логов';
        }
    }
    
    if (isset($_GET['cleared'])) {
        echo '<div class="alert alert-success">Логи успешно очищены</div>';
    }
    
    if (isset($error)) {
        echo '<div class="alert alert-error">' . htmlspecialchars($error) . '</div>';
    }
    ?>

    <!-- Статистика -->
    <div class="logs-stats">
        <div class="stat-item">
            <span class="stat-label">Всего строк:</span>
            <span class="stat-value"><?= number_format($stats['total_lines']) ?></span>
        </div>
        <div class="stat-item">
            <span class="stat-label">Размер файла:</span>
            <span class="stat-value"><?= number_format($stats['file_size'] / 1024, 2) ?> KB</span>
        </div>
        <div class="stat-item">
            <span class="stat-label">Последнее обновление:</span>
            <span class="stat-value"><?= $stats['file_modified'] ? date('Y-m-d H:i:s', $stats['file_modified']) : 'N/A' ?></span>
        </div>
        <div class="stat-item">
            <span class="stat-label">Ошибки:</span>
            <span class="stat-value stat-error"><?= $stats['error_count'] ?></span>
        </div>
        <div class="stat-item">
            <span class="stat-label">Предупреждения:</span>
            <span class="stat-value stat-warning"><?= $stats['warning_count'] ?></span>
        </div>
        <div class="stat-item">
            <span class="stat-label">Информация:</span>
            <span class="stat-value stat-info"><?= $stats['info_count'] ?></span>
        </div>
    </div>

    <!-- Фильтры -->
    <div class="logs-filters">
        <form method="GET" action="" class="filter-form">
            <div class="filter-group">
                <label for="filter">Поиск:</label>
                <input type="text" id="filter" name="filter" value="<?= htmlspecialchars($filter) ?>" 
                       placeholder="Поиск по содержимому...">
            </div>
            <div class="filter-group">
                <label for="limit">Записей на странице:</label>
                <select id="limit" name="limit">
                    <option value="50" <?= $limit === 50 ? 'selected' : '' ?>>50</option>
                    <option value="100" <?= $limit === 100 ? 'selected' : '' ?>>100</option>
                    <option value="200" <?= $limit === 200 ? 'selected' : '' ?>>200</option>
                    <option value="500" <?= $limit === 500 ? 'selected' : '' ?>>500</option>
                </select>
            </div>
            <div class="filter-group">
                <button type="submit" class="btn btn-primary">Применить</button>
                <a href="/admin/websocket-logs.php" class="btn btn-secondary">Сбросить</a>
            </div>
        </form>
    </div>

    <!-- Логи -->
    <div class="logs-container">
        <?php if (empty($logs)): ?>
            <div class="no-logs">
                <p>Логи не найдены</p>
                <?php if (!file_exists($logFile)): ?>
                    <p class="text-muted">Файл логов не существует. WebSocket сервер может быть не запущен или логи не создаются.</p>
                <?php endif; ?>
            </div>
        <?php else: ?>
            <div class="logs-table-wrapper">
                <table class="logs-table">
                    <thead>
                        <tr>
                            <th style="width: 150px;">Время</th>
                            <th style="width: 100px;">Уровень</th>
                            <th>Сообщение</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php foreach ($logs as $log): ?>
                            <tr class="log-row log-level-<?= htmlspecialchars($log['level']) ?>">
                                <td class="log-timestamp">
                                    <?= htmlspecialchars($log['timestamp'] ?: 'N/A') ?>
                                </td>
                                <td class="log-level">
                                    <span class="badge badge-<?= htmlspecialchars($log['level']) ?>">
                                        <?= strtoupper(htmlspecialchars($log['level'])) ?>
                                    </span>
                                </td>
                                <td class="log-message">
                                    <code><?= htmlspecialchars($log['message']) ?></code>
                                </td>
                            </tr>
                        <?php endforeach; ?>
                    </tbody>
                </table>
            </div>

            <!-- Пагинация -->
            <?php if (isset($totalPages) && $totalPages > 1): ?>
                <div class="pagination">
                    <?php if ($page > 1): ?>
                        <a href="?page=<?= $page - 1 ?>&limit=<?= $limit ?>&filter=<?= urlencode($filter) ?>" class="btn btn-secondary">← Назад</a>
                    <?php endif; ?>
                    
                    <span class="page-info">
                        Страница <?= $page ?> из <?= $totalPages ?> 
                        (<?= number_format($totalFiltered ?? $totalLines) ?> записей)
                    </span>
                    
                    <?php if ($page < $totalPages): ?>
                        <a href="?page=<?= $page + 1 ?>&limit=<?= $limit ?>&filter=<?= urlencode($filter) ?>" class="btn btn-secondary">Вперёд →</a>
                    <?php endif; ?>
                </div>
            <?php endif; ?>
        <?php endif; ?>
    </div>
</div>

<style>
.logs-stats {
    display: flex;
    flex-wrap: wrap;
    gap: 15px;
    margin-bottom: 20px;
    padding: 15px;
    background: white;
    border: 1px solid #ddd;
    border-radius: 8px;
}

.stat-item {
    display: flex;
    flex-direction: column;
    gap: 5px;
}

.stat-label {
    font-size: 0.85em;
    color: #666;
}

.stat-value {
    font-size: 1.2em;
    font-weight: 600;
    color: #333;
}

.stat-error {
    color: #dc2626;
}

.stat-warning {
    color: #f59e0b;
}

.stat-info {
    color: #3b82f6;
}

.logs-filters {
    background: white;
    border: 1px solid #ddd;
    border-radius: 8px;
    padding: 20px;
    margin-bottom: 20px;
}

.filter-form {
    display: flex;
    flex-wrap: wrap;
    gap: 15px;
    align-items: flex-end;
}

.filter-group {
    display: flex;
    flex-direction: column;
    gap: 5px;
}

.filter-group label {
    font-size: 0.9em;
    font-weight: 500;
    color: #666;
}

.filter-group input,
.filter-group select {
    padding: 8px 12px;
    border: 1px solid #ddd;
    border-radius: 4px;
    font-size: 0.9em;
}

.logs-container {
    background: white;
    border: 1px solid #ddd;
    border-radius: 8px;
    padding: 20px;
}

.logs-table-wrapper {
    overflow-x: auto;
    max-height: 600px;
    overflow-y: auto;
}

.logs-table {
    width: 100%;
    border-collapse: collapse;
    font-size: 0.9em;
    font-family: 'Courier New', monospace;
}

.logs-table thead {
    position: sticky;
    top: 0;
    background: #f9f9f9;
    z-index: 10;
}

.logs-table th {
    padding: 12px;
    text-align: left;
    font-weight: 600;
    border-bottom: 2px solid #ddd;
    background: #f9f9f9;
}

.logs-table td {
    padding: 10px 12px;
    border-bottom: 1px solid #eee;
    vertical-align: top;
}

.log-row:hover {
    background: #f9f9f9;
}

.log-level-error {
    background: #fee2e2;
}

.log-level-warning {
    background: #fef3c7;
}

.log-timestamp {
    color: #666;
    font-size: 0.85em;
}

.log-level {
    text-align: center;
}

.badge {
    display: inline-block;
    padding: 4px 8px;
    border-radius: 4px;
    font-size: 0.75em;
    font-weight: 600;
    text-transform: uppercase;
}

.badge-error {
    background: #fee2e2;
    color: #991b1b;
}

.badge-warning {
    background: #fef3c7;
    color: #92400e;
}

.badge-info {
    background: #dbeafe;
    color: #1e40af;
}

.log-message code {
    background: transparent;
    padding: 0;
    font-size: 0.9em;
    color: #333;
    word-break: break-all;
}

.no-logs {
    text-align: center;
    padding: 40px;
    color: #666;
}

.pagination {
    display: flex;
    justify-content: center;
    align-items: center;
    gap: 15px;
    margin-top: 20px;
    padding-top: 20px;
    border-top: 1px solid #eee;
}

.page-info {
    color: #666;
    font-size: 0.9em;
}

@media (max-width: 768px) {
    .logs-stats {
        flex-direction: column;
    }
    
    .filter-form {
        flex-direction: column;
    }
    
    .logs-table {
        font-size: 0.8em;
    }
}
</style>

<?php
$content = ob_get_clean();
include __DIR__ . '/../../templates/base.php';
?>








