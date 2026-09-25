<?php
/**
 * Страница просмотра логов безопасности (админ-панель)
 */

require_once __DIR__ . '/../../src/bootstrap.php';

use OGAS\Services\Auth;
use OGAS\Services\AdminService;
use OGAS\Core\SecurityLogger;
use OGAS\Core\Security;

Auth::requireAuth();
AdminService::requireAdmin();

$user = Auth::user();

// Логируем доступ к логам
SecurityLogger::logAdminAccess('view_security_logs', true);

// Параметры фильтрации
$level = $_GET['level'] ?? 'all';
$event = $_GET['event'] ?? 'all';
$limit = (int)($_GET['limit'] ?? 100);
$page = max(1, (int)($_GET['page'] ?? 1));
$offset = ($page - 1) * $limit;

// Получаем логи
$allLogs = SecurityLogger::getRecentLogs(10000); // Получаем больше для фильтрации

// Фильтрация
$filteredLogs = $allLogs;
if ($level !== 'all') {
    $filteredLogs = array_filter($filteredLogs, function($log) use ($level) {
        return ($log['level'] ?? 'info') === $level;
    });
}
if ($event !== 'all') {
    $filteredLogs = array_filter($filteredLogs, function($log) use ($event) {
        return ($log['event'] ?? '') === $event;
    });
}

// Пагинация
$totalLogs = count($filteredLogs);
$totalPages = ceil($totalLogs / $limit);
$filteredLogs = array_slice($filteredLogs, $offset, $limit);

// Статистика по уровням
$levelStats = [
    'info' => 0,
    'warning' => 0,
    'critical' => 0
];
foreach ($allLogs as $log) {
    $logLevel = $log['level'] ?? 'info';
    if (isset($levelStats[$logLevel])) {
        $levelStats[$logLevel]++;
    }
}

// Уникальные события
$uniqueEvents = array_unique(array_column($allLogs, 'event'));

$title = 'Просмотр логов безопасности';
ob_start();
?>
<div class="dashboard">
    <div class="dashboard-header">
        <h2>Просмотр логов безопасности</h2>
        <div class="header-actions">
            <a href="/admin/index.php" class="btn btn-secondary">← Назад к админ-панели</a>
        </div>
    </div>

    <!-- Статистика -->
    <div class="logs-stats">
        <div class="stat-item">
            <span class="stat-label">Всего записей:</span>
            <span class="stat-value"><?= count($allLogs) ?></span>
        </div>
        <div class="stat-item">
            <span class="stat-label">Info:</span>
            <span class="stat-value stat-info"><?= $levelStats['info'] ?></span>
        </div>
        <div class="stat-item">
            <span class="stat-label">Warning:</span>
            <span class="stat-value stat-warning"><?= $levelStats['warning'] ?></span>
        </div>
        <div class="stat-item">
            <span class="stat-label">Critical:</span>
            <span class="stat-value stat-critical"><?= $levelStats['critical'] ?></span>
        </div>
    </div>

    <!-- Фильтры -->
    <div class="logs-filters">
        <form method="GET" action="" class="filter-form">
            <div class="filter-group">
                <label for="level">Уровень:</label>
                <select name="level" id="level">
                    <option value="all" <?= $level === 'all' ? 'selected' : '' ?>>Все</option>
                    <option value="info" <?= $level === 'info' ? 'selected' : '' ?>>Info</option>
                    <option value="warning" <?= $level === 'warning' ? 'selected' : '' ?>>Warning</option>
                    <option value="critical" <?= $level === 'critical' ? 'selected' : '' ?>>Critical</option>
                </select>
            </div>
            
            <div class="filter-group">
                <label for="event">Событие:</label>
                <select name="event" id="event">
                    <option value="all" <?= $event === 'all' ? 'selected' : '' ?>>Все события</option>
                    <?php foreach ($uniqueEvents as $evt): ?>
                        <option value="<?= htmlspecialchars($evt) ?>" <?= $event === $evt ? 'selected' : '' ?>>
                            <?= htmlspecialchars($evt) ?>
                        </option>
                    <?php endforeach; ?>
                </select>
            </div>
            
            <div class="filter-group">
                <label for="limit">Записей на странице:</label>
                <select name="limit" id="limit">
                    <option value="50" <?= $limit === 50 ? 'selected' : '' ?>>50</option>
                    <option value="100" <?= $limit === 100 ? 'selected' : '' ?>>100</option>
                    <option value="200" <?= $limit === 200 ? 'selected' : '' ?>>200</option>
                    <option value="500" <?= $limit === 500 ? 'selected' : '' ?>>500</option>
                </select>
            </div>
            
            <button type="submit" class="btn btn-primary">Применить фильтры</button>
            <a href="/admin/logs.php" class="btn btn-secondary">Сбросить</a>
        </form>
    </div>

    <!-- Таблица логов -->
    <div class="logs-table-container">
        <?php if (empty($filteredLogs)): ?>
            <div class="no-logs">
                <p>Логи не найдены</p>
            </div>
        <?php else: ?>
            <table class="logs-table">
                <thead>
                    <tr>
                        <th>Время</th>
                        <th>Уровень</th>
                        <th>Событие</th>
                        <th>IP</th>
                        <th>Пользователь</th>
                        <th>Детали</th>
                    </tr>
                </thead>
                <tbody>
                    <?php foreach ($filteredLogs as $log): ?>
                        <tr class="log-row log-level-<?= htmlspecialchars($log['level'] ?? 'info') ?>">
                            <td class="log-time"><?= htmlspecialchars($log['timestamp'] ?? '') ?></td>
                            <td class="log-level">
                                <span class="level-badge level-<?= htmlspecialchars($log['level'] ?? 'info') ?>">
                                    <?= strtoupper(htmlspecialchars($log['level'] ?? 'info')) ?>
                                </span>
                            </td>
                            <td class="log-event"><?= htmlspecialchars($log['event'] ?? '') ?></td>
                            <td class="log-ip"><?= htmlspecialchars($log['ip'] ?? '') ?></td>
                            <td class="log-user">
                                <?php if (isset($log['user_id'])): ?>
                                    <a href="/admin/users.php?id=<?= $log['user_id'] ?>">
                                        ID: <?= $log['user_id'] ?>
                                    </a>
                                <?php else: ?>
                                    <span class="text-muted">—</span>
                                <?php endif; ?>
                            </td>
                            <td class="log-details">
                                <button class="btn-details" onclick="toggleLogDetails(this)">
                                    Показать детали
                                </button>
                                <div class="log-details-content" style="display: none;">
                                    <pre><?= htmlspecialchars(json_encode($log['data'] ?? [], JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE)) ?></pre>
                                    <div class="log-meta">
                                        <p><strong>User Agent:</strong> <?= htmlspecialchars($log['user_agent'] ?? 'Unknown') ?></p>
                                        <p><strong>Request URI:</strong> <?= htmlspecialchars($log['request_uri'] ?? 'Unknown') ?></p>
                                        <p><strong>Method:</strong> <?= htmlspecialchars($log['request_method'] ?? 'Unknown') ?></p>
                                    </div>
                                </div>
                            </td>
                        </tr>
                    <?php endforeach; ?>
                </tbody>
            </table>

            <!-- Пагинация -->
            <?php if ($totalPages > 1): ?>
                <div class="pagination">
                    <?php if ($page > 1): ?>
                        <a href="?level=<?= htmlspecialchars($level) ?>&event=<?= htmlspecialchars($event) ?>&limit=<?= $limit ?>&page=<?= $page - 1 ?>" class="btn btn-secondary">← Назад</a>
                    <?php endif; ?>
                    
                    <span class="page-info">
                        Страница <?= $page ?> из <?= $totalPages ?> (всего записей: <?= $totalLogs ?>)
                    </span>
                    
                    <?php if ($page < $totalPages): ?>
                        <a href="?level=<?= htmlspecialchars($level) ?>&event=<?= htmlspecialchars($event) ?>&limit=<?= $limit ?>&page=<?= $page + 1 ?>" class="btn btn-secondary">Вперёд →</a>
                    <?php endif; ?>
                </div>
            <?php endif; ?>
        <?php endif; ?>
    </div>
</div>

<style>
.logs-stats {
    background: white;
    border: 1px solid #ddd;
    border-radius: 8px;
    padding: 20px;
    margin-bottom: 20px;
    display: flex;
    gap: 30px;
    flex-wrap: wrap;
}

.stat-item {
    display: flex;
    flex-direction: column;
    gap: 5px;
}

.stat-label {
    font-size: 0.9em;
    color: #666;
}

.stat-value {
    font-size: 1.5em;
    font-weight: bold;
    color: #667eea;
}

.stat-value.stat-info {
    color: #3b82f6;
}

.stat-value.stat-warning {
    color: #f59e0b;
}

.stat-value.stat-critical {
    color: #ef4444;
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
    gap: 15px;
    flex-wrap: wrap;
    align-items: flex-end;
}

.filter-group {
    display: flex;
    flex-direction: column;
    gap: 5px;
}

.filter-group label {
    font-size: 0.9em;
    color: #666;
    font-weight: 500;
}

.filter-group select {
    padding: 8px 12px;
    border: 1px solid #ddd;
    border-radius: 4px;
    font-size: 1em;
}

.logs-table-container {
    background: white;
    border: 1px solid #ddd;
    border-radius: 8px;
    padding: 20px;
    overflow-x: auto;
}

.logs-table {
    width: 100%;
    border-collapse: collapse;
}

.logs-table thead {
    background: #f9f9f9;
}

.logs-table th {
    padding: 12px;
    text-align: left;
    font-weight: 600;
    color: #333;
    border-bottom: 2px solid #ddd;
}

.logs-table td {
    padding: 12px;
    border-bottom: 1px solid #eee;
}

.log-row:hover {
    background: #f9f9f9;
}

.log-level-critical {
    background: #fee2e2;
}

.log-level-warning {
    background: #fef3c7;
}

.log-time {
    font-family: monospace;
    font-size: 0.9em;
    color: #666;
}

.level-badge {
    display: inline-block;
    padding: 4px 8px;
    border-radius: 4px;
    font-size: 0.8em;
    font-weight: 600;
}

.level-badge.level-info {
    background: #dbeafe;
    color: #1e40af;
}

.level-badge.level-warning {
    background: #fef3c7;
    color: #92400e;
}

.level-badge.level-critical {
    background: #fee2e2;
    color: #991b1b;
}

.log-event {
    font-weight: 500;
    color: #333;
}

.log-ip {
    font-family: monospace;
    font-size: 0.9em;
}

.log-details-content {
    margin-top: 10px;
    padding: 15px;
    background: #f9f9f9;
    border-radius: 4px;
    border: 1px solid #ddd;
}

.log-details-content pre {
    margin: 0 0 15px 0;
    padding: 10px;
    background: white;
    border: 1px solid #ddd;
    border-radius: 4px;
    overflow-x: auto;
    font-size: 0.85em;
}

.log-meta {
    font-size: 0.9em;
    color: #666;
}

.log-meta p {
    margin: 5px 0;
}

.btn-details {
    padding: 4px 8px;
    font-size: 0.85em;
    background: #667eea;
    color: white;
    border: none;
    border-radius: 4px;
    cursor: pointer;
}

.btn-details:hover {
    background: #5568d3;
}

.pagination {
    display: flex;
    justify-content: center;
    align-items: center;
    gap: 15px;
    margin-top: 20px;
    padding-top: 20px;
    border-top: 1px solid #ddd;
}

.page-info {
    color: #666;
}

.no-logs {
    text-align: center;
    padding: 40px;
    color: #666;
}

@media (max-width: 768px) {
    .logs-stats {
        flex-direction: column;
        gap: 15px;
    }
    
    .filter-form {
        flex-direction: column;
    }
    
    .filter-group {
        width: 100%;
    }
    
    .logs-table {
        font-size: 0.9em;
    }
    
    .logs-table th,
    .logs-table td {
        padding: 8px;
    }
}
</style>

<script>
function toggleLogDetails(button) {
    const details = button.nextElementSibling;
    if (details.style.display === 'none') {
        details.style.display = 'block';
        button.textContent = 'Скрыть детали';
    } else {
        details.style.display = 'none';
        button.textContent = 'Показать детали';
    }
}
</script>

<?php
$content = ob_get_clean();
include __DIR__ . '/../../templates/base.php';
?>

