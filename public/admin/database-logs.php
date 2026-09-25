<?php
/**
 * Страница просмотра логов базы данных (админ-панель)
 */

require_once __DIR__ . '/../../src/bootstrap.php';

use OGAS\Services\Auth;
use OGAS\Services\AdminService;
use OGAS\Services\DatabaseLogService;
use OGAS\Core\SecurityLogger;

Auth::requireAuth();
AdminService::requireAdmin();

$user = Auth::user();

// Логируем доступ к логам БД
SecurityLogger::logAdminAccess('view_database_logs', true);

// Параметры
$logType = $_GET['type'] ?? 'queries'; // queries или errors
$limit = min(1000, max(10, (int)($_GET['limit'] ?? 500)));
$page = max(1, (int)($_GET['page'] ?? 1));
$search = trim($_GET['search'] ?? '');
$filter = $_GET['filter'] ?? null; // type для queries, level для errors

// Получаем логи
if ($logType === 'errors') {
    $result = DatabaseLogService::getErrorLogs($limit, $search ?: null, $filter);
} else {
    $result = DatabaseLogService::getQueryLogs($limit, $search ?: null, $filter);
}

$logs = $result['logs'] ?? [];
$stats = DatabaseLogService::getLogStatistics();

$title = 'Логи базы данных';
ob_start();
?>
<div class="dashboard">
    <div class="dashboard-header">
        <h2>Логи базы данных</h2>
        <div class="header-actions">
            <a href="/admin/index.php" class="btn btn-secondary">← Назад к админ-панели</a>
        </div>
    </div>

    <!-- Статистика -->
    <div class="db-logs-stats">
        <div class="stat-card">
            <h4>Лог запросов</h4>
            <?php if ($stats['queries_log']['exists']): ?>
                <p><strong>Размер:</strong> <?= DatabaseLogService::formatFileSize($stats['queries_log']['size']) ?></p>
                <p><strong>Последнее изменение:</strong> <?= $stats['queries_log']['last_modified'] ? date('Y-m-d H:i:s', $stats['queries_log']['last_modified']) : 'N/A' ?></p>
                <?php if (isset($stats['queries_log']['type_stats'])): ?>
                    <div class="type-stats">
                        <?php foreach ($stats['queries_log']['type_stats'] as $type => $count): ?>
                            <span class="type-badge"><?= htmlspecialchars($type) ?>: <?= $count ?></span>
                        <?php endforeach; ?>
                    </div>
                <?php endif; ?>
            <?php else: ?>
                <p class="text-muted">Файл не найден</p>
            <?php endif; ?>
        </div>
        
        <div class="stat-card">
            <h4>Лог ошибок</h4>
            <?php if ($stats['error_log']['exists']): ?>
                <p><strong>Размер:</strong> <?= DatabaseLogService::formatFileSize($stats['error_log']['size']) ?></p>
                <p><strong>Последнее изменение:</strong> <?= $stats['error_log']['last_modified'] ? date('Y-m-d H:i:s', $stats['error_log']['last_modified']) : 'N/A' ?></p>
                <?php if (isset($stats['error_log']['level_stats'])): ?>
                    <div class="level-stats">
                        <?php foreach ($stats['error_log']['level_stats'] as $level => $count): ?>
                            <span class="level-badge level-<?= strtolower($level) ?>"><?= htmlspecialchars($level) ?>: <?= $count ?></span>
                        <?php endforeach; ?>
                    </div>
                <?php endif; ?>
            <?php else: ?>
                <p class="text-muted">Файл не найден</p>
            <?php endif; ?>
        </div>
    </div>

    <!-- Переключатель типа логов -->
    <div class="log-type-switcher">
        <a href="?type=queries<?= $search ? '&search=' . urlencode($search) : '' ?><?= $filter ? '&filter=' . urlencode($filter) : '' ?>" 
           class="btn <?= $logType === 'queries' ? 'btn-primary' : 'btn-secondary' ?>">
            <i class="fas fa-database"></i> Запросы
        </a>
        <a href="?type=errors<?= $search ? '&search=' . urlencode($search) : '' ?><?= $filter ? '&filter=' . urlencode($filter) : '' ?>" 
           class="btn <?= $logType === 'errors' ? 'btn-primary' : 'btn-secondary' ?>">
            <i class="fas fa-exclamation-triangle"></i> Ошибки
        </a>
    </div>

    <!-- Фильтры -->
    <div class="db-logs-filters">
        <form method="GET" action="" class="filter-form">
            <input type="hidden" name="type" value="<?= htmlspecialchars($logType) ?>">
            
            <div class="filter-group">
                <label for="search">Поиск:</label>
                <input type="text" 
                       name="search" 
                       id="search" 
                       class="form-input" 
                       placeholder="Поиск в логах..."
                       value="<?= htmlspecialchars($search) ?>">
            </div>
            
            <?php if ($logType === 'queries'): ?>
                <div class="filter-group">
                    <label for="filter">Тип запроса:</label>
                    <select name="filter" id="filter" class="form-input">
                        <option value="">Все типы</option>
                        <option value="Query" <?= $filter === 'Query' ? 'selected' : '' ?>>Query</option>
                        <option value="Init DB" <?= $filter === 'Init DB' ? 'selected' : '' ?>>Init DB</option>
                        <option value="Connect" <?= $filter === 'Connect' ? 'selected' : '' ?>>Connect</option>
                        <option value="Quit" <?= $filter === 'Quit' ? 'selected' : '' ?>>Quit</option>
                    </select>
                </div>
            <?php else: ?>
                <div class="filter-group">
                    <label for="filter">Уровень:</label>
                    <select name="filter" id="filter" class="form-input">
                        <option value="">Все уровни</option>
                        <option value="ERROR" <?= $filter === 'ERROR' ? 'selected' : '' ?>>ERROR</option>
                        <option value="WARNING" <?= $filter === 'WARNING' ? 'selected' : '' ?>>WARNING</option>
                        <option value="NOTE" <?= $filter === 'NOTE' ? 'selected' : '' ?>>NOTE</option>
                        <option value="INFO" <?= $filter === 'INFO' ? 'selected' : '' ?>>INFO</option>
                    </select>
                </div>
            <?php endif; ?>
            
            <div class="filter-group">
                <label for="limit">Количество записей:</label>
                <select name="limit" id="limit" class="form-input">
                    <option value="100" <?= $limit === 100 ? 'selected' : '' ?>>100</option>
                    <option value="500" <?= $limit === 500 ? 'selected' : '' ?>>500</option>
                    <option value="1000" <?= $limit === 1000 ? 'selected' : '' ?>>1000</option>
                </select>
            </div>
            
            <div class="filter-actions">
                <button type="submit" class="btn btn-primary">
                    <i class="fas fa-search"></i> Применить
                </button>
                <a href="?type=<?= htmlspecialchars($logType) ?>" class="btn btn-secondary">
                    <i class="fas fa-redo"></i> Сбросить
                </a>
            </div>
        </form>
    </div>

    <!-- Логи -->
    <div class="db-logs-container">
        <?php if (!$result['success']): ?>
            <div class="alert alert-error">
                <strong>Ошибка:</strong> <?= htmlspecialchars($result['message'] ?? 'Не удалось загрузить логи') ?>
            </div>
        <?php elseif (empty($logs)): ?>
            <div class="alert alert-info">
                Логи не найдены. Попробуйте изменить фильтры.
            </div>
        <?php else: ?>
            <div class="logs-table-wrapper">
                <table class="logs-table">
                    <thead>
                        <tr>
                            <?php if ($logType === 'queries'): ?>
                                <th>Время</th>
                                <th>Thread ID</th>
                                <th>Тип</th>
                                <th>Запрос</th>
                            <?php else: ?>
                                <th>Время</th>
                                <th>Уровень</th>
                                <th>Сообщение</th>
                            <?php endif; ?>
                        </tr>
                    </thead>
                    <tbody>
                        <?php foreach ($logs as $log): ?>
                            <tr class="log-row">
                                <?php if ($logType === 'queries'): ?>
                                    <td class="log-timestamp"><?= htmlspecialchars($log['timestamp'] ?? 'N/A') ?></td>
                                    <td class="log-thread-id"><?= htmlspecialchars($log['thread_id'] ?? 'N/A') ?></td>
                                    <td class="log-type">
                                        <span class="type-badge type-<?= strtolower(str_replace(' ', '-', $log['type'] ?? 'unknown')) ?>">
                                            <?= htmlspecialchars($log['type'] ?? 'Unknown') ?>
                                        </span>
                                    </td>
                                    <td class="log-query">
                                        <code><?= htmlspecialchars($log['query'] ?? '') ?></code>
                                    </td>
                                <?php else: ?>
                                    <td class="log-timestamp"><?= htmlspecialchars($log['timestamp'] ?? 'N/A') ?></td>
                                    <td class="log-level">
                                        <span class="level-badge level-<?= strtolower($log['level'] ?? 'info') ?>">
                                            <?= htmlspecialchars($log['level'] ?? 'INFO') ?>
                                        </span>
                                    </td>
                                    <td class="log-message">
                                        <?= htmlspecialchars($log['message'] ?? '') ?>
                                    </td>
                                <?php endif; ?>
                            </tr>
                        <?php endforeach; ?>
                    </tbody>
                </table>
            </div>
            
            <div class="logs-info">
                <p>Показано записей: <strong><?= count($logs) ?></strong> из <?= $result['total'] ?? 0 ?></p>
                <?php if ($result['file_path']): ?>
                    <p class="text-muted">Файл: <code><?= htmlspecialchars($result['file_path']) ?></code></p>
                <?php endif; ?>
            </div>
        <?php endif; ?>
    </div>
</div>

<style>
.db-logs-stats {
    display: grid;
    grid-template-columns: repeat(auto-fit, minmax(300px, 1fr));
    gap: 20px;
    margin-bottom: 30px;
}

.db-logs-stats .stat-card {
    background: white;
    border: 1px solid #ddd;
    border-radius: 8px;
    padding: 20px;
    box-shadow: 0 2px 4px rgba(0,0,0,0.1);
}

.db-logs-stats .stat-card h4 {
    margin: 0 0 15px 0;
    color: #333;
    font-size: 1.1em;
}

.db-logs-stats .stat-card p {
    margin: 8px 0;
    color: #666;
    font-size: 0.9em;
}

.type-stats, .level-stats {
    margin-top: 10px;
    display: flex;
    flex-wrap: wrap;
    gap: 8px;
}

.type-badge, .level-badge {
    display: inline-block;
    padding: 4px 8px;
    border-radius: 4px;
    font-size: 0.85em;
    font-weight: 500;
}

.type-badge {
    background: #e3f2fd;
    color: #1976d2;
}

.level-badge.level-error {
    background: #ffebee;
    color: #c62828;
}

.level-badge.level-warning {
    background: #fff3e0;
    color: #e65100;
}

.level-badge.level-note {
    background: #f3e5f5;
    color: #7b1fa2;
}

.level-badge.level-info {
    background: #e8f5e9;
    color: #2e7d32;
}

.log-type-switcher {
    display: flex;
    gap: 10px;
    margin-bottom: 20px;
}

.db-logs-filters {
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
    flex: 1;
    min-width: 200px;
}

.filter-group label {
    display: block;
    margin-bottom: 5px;
    font-weight: 500;
    color: #333;
}

.filter-actions {
    display: flex;
    gap: 10px;
}

.db-logs-container {
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
}

.logs-table thead {
    position: sticky;
    top: 0;
    background: #f5f5f5;
    z-index: 10;
}

.logs-table th {
    padding: 12px;
    text-align: left;
    border-bottom: 2px solid #ddd;
    font-weight: 600;
    color: #333;
}

.logs-table td {
    padding: 10px 12px;
    border-bottom: 1px solid #eee;
}

.logs-table tbody tr:hover {
    background: #f9f9f9;
}

.log-timestamp {
    white-space: nowrap;
    color: #666;
    font-size: 0.85em;
}

.log-query code {
    background: #f5f5f5;
    padding: 2px 6px;
    border-radius: 3px;
    font-family: 'Courier New', monospace;
    font-size: 0.9em;
    word-break: break-all;
}

.log-message {
    word-break: break-word;
}

.logs-info {
    margin-top: 20px;
    padding-top: 15px;
    border-top: 1px solid #eee;
    color: #666;
    font-size: 0.9em;
}

.text-muted {
    color: #999;
}

@media (max-width: 768px) {
    .filter-form {
        flex-direction: column;
    }
    
    .filter-group {
        width: 100%;
    }
    
    .logs-table {
        font-size: 0.8em;
    }
    
    .logs-table th,
    .logs-table td {
        padding: 8px;
    }
}
</style>

<?php
$content = ob_get_clean();
include __DIR__ . '/../../templates/base.php';
?>



