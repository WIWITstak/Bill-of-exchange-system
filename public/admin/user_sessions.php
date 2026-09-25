<?php
/**
 * Страница управления активными сессиями пользователей (админ-панель)
 */

require_once __DIR__ . '/../../src/bootstrap.php';

use OGAS\Services\Auth;
use OGAS\Services\AdminService;
use OGAS\Models\User;
use OGAS\Models\UserSession;
use OGAS\Core\Security;
use OGAS\Core\Session;

Auth::requireAuth();
AdminService::requireAdmin();

$user = Auth::user();

// Параметры
$userId = (int)($_GET['user_id'] ?? 0);
$action = $_GET['action'] ?? '';
$sessionId = $_GET['session_id'] ?? '';

// Обработка действий
if ($action === 'delete' && !empty($sessionId) && $userId > 0) {
    Security::requireCsrfToken();
    
    $session = UserSession::findBySessionId($sessionId);
    if ($session && $session->getUserId() === $userId) {
        $session->delete();
        header('Location: /admin/user_sessions.php?user_id=' . $userId . '&success=1');
        exit;
    }
}

// Получаем пользователя
$targetUser = null;
if ($userId > 0) {
    $targetUser = User::findById($userId);
}

// Получаем активные сессии
$sessions = [];
if ($targetUser) {
    $sessions = UserSession::findByUserId($targetUser->getId(), false);
}

$title = 'Управление сессиями пользователей';
ob_start();
?>
<div class="dashboard">
    <div class="dashboard-header">
        <h2>Управление активными сессиями</h2>
        <div class="header-actions">
            <a href="/admin/index.php" class="btn btn-secondary">← Назад к админ-панели</a>
        </div>
    </div>

    <!-- Поиск пользователя -->
    <div class="session-search">
        <form method="GET" action="" class="search-form">
            <div class="form-group">
                <label for="user_id">ID пользователя:</label>
                <input type="number" id="user_id" name="user_id" value="<?= $userId ?>" min="1" required>
                <button type="submit" class="btn btn-primary">Найти</button>
            </div>
        </form>
    </div>

    <?php if ($targetUser): ?>
        <div class="user-info">
            <h3>Пользователь: <?= htmlspecialchars($targetUser->getFullName()) ?></h3>
            <p>Email: <?= htmlspecialchars($targetUser->getEmail()) ?></p>
            <p>Активных сессий: <?= count(array_filter($sessions, fn($s) => $s->isCurrent())) ?></p>
            <p>Максимум сессий: <?= Session::getMaxSessionsPerUser() ?></p>
            <p>Таймаут неактивности: <?= Session::getInactivityTimeout() / 60 ?> минут</p>
        </div>

        <?php if (empty($sessions)): ?>
            <div class="no-sessions">
                <p>Активных сессий не найдено</p>
            </div>
        <?php else: ?>
            <div class="sessions-table-container">
                <table class="sessions-table">
                    <thead>
                        <tr>
                            <th>ID</th>
                            <th>Session ID</th>
                            <th>IP адрес</th>
                            <th>Устройство</th>
                            <th>User Agent</th>
                            <th>Создана</th>
                            <th>Последняя активность</th>
                            <th>Текущая</th>
                            <th>Действия</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php foreach ($sessions as $session): ?>
                            <tr class="<?= $session->isCurrent() ? 'current-session' : '' ?>">
                                <td><?= $session->getId() ?></td>
                                <td class="session-id"><?= htmlspecialchars(substr($session->getSessionId(), 0, 20)) ?>...</td>
                                <td class="ip-address"><?= htmlspecialchars($session->getIpAddress()) ?></td>
                                <td><?= htmlspecialchars($session->getDeviceInfo() ?? 'Unknown') ?></td>
                                <td class="user-agent"><?= htmlspecialchars(substr($session->getUserAgent() ?? 'Unknown', 0, 50)) ?>...</td>
                                <td><?= htmlspecialchars($session->getCreatedAt()) ?></td>
                                <td><?= htmlspecialchars($session->getLastActivity()) ?></td>
                                <td>
                                    <?php if ($session->isCurrent()): ?>
                                        <span class="badge badge-success">Да</span>
                                    <?php else: ?>
                                        <span class="badge badge-secondary">Нет</span>
                                    <?php endif; ?>
                                </td>
                                <td>
                                    <?php if (!$session->isCurrent()): ?>
                                        <a href="?user_id=<?= $userId ?>&action=delete&session_id=<?= urlencode($session->getSessionId()) ?>&_csrf_token=<?= urlencode(csrf_token()) ?>" 
                                           class="btn btn-danger btn-small"
                                           onclick="return confirm('Удалить эту сессию?')">Удалить</a>
                                    <?php else: ?>
                                        <span class="text-muted">Текущая</span>
                                    <?php endif; ?>
                                </td>
                            </tr>
                        <?php endforeach; ?>
                    </tbody>
                </table>
            </div>
        <?php endif; ?>
    <?php elseif ($userId > 0): ?>
        <div class="error-message">
            <p>Пользователь с ID <?= $userId ?> не найден</p>
        </div>
    <?php else: ?>
        <div class="info-message">
            <p>Введите ID пользователя для просмотра его активных сессий</p>
        </div>
    <?php endif; ?>
</div>

<style>
.session-search {
    background: white;
    border: 1px solid #ddd;
    border-radius: 8px;
    padding: 20px;
    margin-bottom: 20px;
}

.search-form {
    display: flex;
    gap: 15px;
    align-items: flex-end;
}

.search-form .form-group {
    display: flex;
    gap: 10px;
    align-items: center;
}

.search-form label {
    font-weight: 500;
}

.search-form input[type="number"] {
    padding: 8px 12px;
    border: 1px solid #ddd;
    border-radius: 4px;
    width: 150px;
}

.user-info {
    background: white;
    border: 1px solid #ddd;
    border-radius: 8px;
    padding: 20px;
    margin-bottom: 20px;
}

.user-info h3 {
    margin-top: 0;
}

.user-info p {
    margin: 5px 0;
    color: #666;
}

.sessions-table-container {
    background: white;
    border: 1px solid #ddd;
    border-radius: 8px;
    padding: 20px;
    overflow-x: auto;
}

.sessions-table {
    width: 100%;
    border-collapse: collapse;
}

.sessions-table thead {
    background: #f9f9f9;
}

.sessions-table th {
    padding: 12px;
    text-align: left;
    font-weight: 600;
    color: #333;
    border-bottom: 2px solid #ddd;
}

.sessions-table td {
    padding: 12px;
    border-bottom: 1px solid #eee;
}

.current-session {
    background: #f0f9ff;
}

.session-id, .ip-address {
    font-family: monospace;
    font-size: 0.9em;
}

.user-agent {
    font-size: 0.85em;
    color: #666;
}

.badge {
    display: inline-block;
    padding: 4px 8px;
    border-radius: 4px;
    font-size: 0.8em;
    font-weight: 600;
}

.badge-success {
    background: #d1fae5;
    color: #065f46;
}

.badge-secondary {
    background: #e5e7eb;
    color: #374151;
}

.btn-small {
    padding: 4px 8px;
    font-size: 0.85em;
}

.no-sessions, .error-message, .info-message {
    text-align: center;
    padding: 40px;
    background: white;
    border: 1px solid #ddd;
    border-radius: 8px;
    color: #666;
}

.error-message {
    background: #fee2e2;
    color: #991b1b;
}

@media (max-width: 768px) {
    .sessions-table {
        font-size: 0.9em;
    }
    
    .sessions-table th,
    .sessions-table td {
        padding: 8px;
    }
}
</style>

<?php
$content = ob_get_clean();
include __DIR__ . '/../../templates/base.php';
?>








