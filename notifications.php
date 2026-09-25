<?php
/**
 * Страница уведомлений
 */

require_once __DIR__ . '/../src/bootstrap.php';

use OGAS\Services\Auth;
use OGAS\Models\Notification;
use OGAS\Core\Session;
use OGAS\Core\Security;

Auth::requireAuth();
$user = Auth::user();

if (!$user) {
    Auth::logout();
    header('Location: /login.php');
    exit;
}

$error = Session::getFlash('error');
$success = Session::getFlash('success');

// Обработка действий
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    // CSRF защита
    if (!Security::checkCsrfToken()) {
        Session::flash('error', 'Ошибка безопасности. Обновите страницу и попробуйте снова.');
        header('Location: /notifications.php');
        exit;
    }
    
    $action = $_POST['action'] ?? '';
    
    try {
        switch ($action) {
            case 'mark_read':
                $notificationId = (int)($_POST['id'] ?? 0);
                $notification = Notification::findById($notificationId);
                
                if ($notification && $notification->getUserId() === $user->getId()) {
                    $notification->markAsRead();
                    $success = 'Уведомление отмечено как прочитанное';
                } else {
                    $error = 'Уведомление не найдено';
                }
                break;
                
            case 'mark_all_read':
                Notification::markAllAsRead($user->getId());
                $success = 'Все уведомления отмечены как прочитанные';
                header('Location: /notifications.php');
                exit;
                
            case 'delete':
                $notificationId = (int)($_POST['id'] ?? 0);
                $notification = Notification::findById($notificationId);
                
                if ($notification && $notification->getUserId() === $user->getId()) {
                    $notification->delete();
                    $success = 'Уведомление удалено';
                } else {
                    $error = 'Уведомление не найдено';
                }
                break;
                
            case 'delete_read':
                Notification::deleteRead($user->getId());
                $success = 'Все прочитанные уведомления удалены';
                header('Location: /notifications.php');
                exit;
        }
    } catch (\Exception $e) {
        $error = 'Ошибка: ' . $e->getMessage();
    }
}

// Фильтры
$filter = $_GET['filter'] ?? 'all'; // all, unread, read
$unreadOnly = $filter === 'unread';

// Получаем уведомления
$notifications = Notification::findByUserId($user->getId(), $unreadOnly, 100);
$unreadCount = Notification::getUnreadCount($user->getId());

// Получаем напоминания о погашении векселей
use OGAS\Services\NotificationService;
NotificationService::checkMaturityReminders($user->getId());
NotificationService::checkOverdueBills($user->getId());

// Обновляем список уведомлений после проверки
$notifications = Notification::findByUserId($user->getId(), $unreadOnly, 100);
$unreadCount = Notification::getUnreadCount($user->getId());

// Группируем уведомления по типам
$notificationsByType = [
    'bill' => [],
    'transaction' => [],
    'community' => [],
    'other' => []
];

foreach ($notifications as $notification) {
    if (strpos($notification->getType(), 'bill') !== false) {
        $notificationsByType['bill'][] = $notification;
    } elseif (strpos($notification->getType(), 'transaction') !== false) {
        $notificationsByType['transaction'][] = $notification;
    } elseif (strpos($notification->getType(), 'community') !== false) {
        $notificationsByType['community'][] = $notification;
    } else {
        $notificationsByType['other'][] = $notification;
    }
}

// Иконки для типов уведомлений
$typeIcons = [
    Notification::TYPE_BILL_RECEIVED => '💵',
    Notification::TYPE_BILL_MATURITY_REMINDER => '⏰',
    Notification::TYPE_BILL_OVERDUE => '⚠️',
    Notification::TYPE_BILL_PAID => '✅',
    Notification::TYPE_COMMUNITY_REQUEST => '👥',
    Notification::TYPE_COMMUNITY_GUARANTOR => '🤝',
    Notification::TYPE_TRANSACTION_CREATED => '🔄',
    Notification::TYPE_TRANSACTION_COMPLETED => '✅',
    Notification::TYPE_TRANSACTION_CANCELLED => '❌'
];

$title = 'Уведомления';
ob_start();
?>
<div class="dashboard">
    <div class="dashboard-header">
        <h2>Уведомления</h2>
        <div class="header-actions">
            <?php if ($unreadCount > 0): ?>
                <form method="POST" style="display:inline;">
                    <?= csrf_field() ?>
                    <input type="hidden" name="action" value="mark_all_read">
                    <button type="submit" class="btn btn-secondary">Отметить все как прочитанные</button>
                </form>
            <?php endif; ?>
            <form method="POST" style="display:inline;">
                <?= csrf_field() ?>
                <input type="hidden" name="action" value="delete_read">
                <button type="submit" class="btn btn-secondary" onclick="return confirm('Удалить все прочитанные уведомления?')">Удалить прочитанные</button>
            </form>
            <a href="/dashboard.php" class="btn btn-secondary">← Назад</a>
        </div>
    </div>
    
    <?php if ($error): ?>
        <div class="alert alert-error"><?= htmlspecialchars($error) ?></div>
    <?php endif; ?>
    
    <?php if ($success): ?>
        <div class="alert alert-success"><?= htmlspecialchars($success) ?></div>
    <?php endif; ?>
    
    <!-- Фильтры -->
    <div class="filters">
        <form method="GET" action="" style="display: flex; gap: 10px; align-items: center;">
            <label>
                Показать:
                <select name="filter" onchange="this.form.submit()">
                    <option value="all" <?= $filter === 'all' ? 'selected' : '' ?>>Все</option>
                    <option value="unread" <?= $filter === 'unread' ? 'selected' : '' ?>>Непрочитанные (<?= $unreadCount ?>)</option>
                    <option value="read" <?= $filter === 'read' ? 'selected' : '' ?>>Прочитанные</option>
                </select>
            </label>
        </form>
    </div>
    
    <!-- Статистика -->
    <div class="catalog-info" style="margin-bottom: 20px;">
        <div class="catalog-stats">
            <div class="stat-item">
                <i class="fas fa-bell"></i>
                <span>Всего уведомлений: <strong><?= count($notifications) ?></strong></span>
            </div>
            <div class="stat-item">
                <i class="fas fa-bell-slash"></i>
                <span>Непрочитанных: <strong style="color: #dc3545;"><?= $unreadCount ?></strong></span>
            </div>
            <div class="stat-item">
                <i class="fas fa-check"></i>
                <span>Прочитанных: <strong style="color: #28a745;"><?= count($notifications) - $unreadCount ?></strong></span>
            </div>
        </div>
    </div>
    
    <!-- Список уведомлений -->
    <div class="notifications-list">
        <?php if (empty($notifications)): ?>
            <div class="info-card">
                <p class="text-muted">Уведомлений нет</p>
            </div>
        <?php else: ?>
            <?php foreach ($notifications as $notification): ?>
                <div class="notification-item <?= !$notification->isRead() ? 'unread' : '' ?>">
                    <div class="notification-icon">
                        <?= $typeIcons[$notification->getType()] ?? '🔔' ?>
                    </div>
                    <div class="notification-content">
                        <div class="notification-header">
                            <h4><?= htmlspecialchars($notification->getTitle()) ?></h4>
                            <span class="notification-time">
                                <?= date('d.m.Y H:i', strtotime($notification->getCreatedAt())) ?>
                            </span>
                        </div>
                        <p class="notification-message"><?= htmlspecialchars($notification->getMessage()) ?></p>
                        
                        <?php if ($notification->getRelatedType() && $notification->getRelatedId()): ?>
                            <?php
                            $link = '';
                            if ($notification->getRelatedType() === 'bill') {
                                $link = '/bills.php';
                            } elseif ($notification->getRelatedType() === 'transaction') {
                                $link = '/transactions.php?id=' . $notification->getRelatedId();
                            } elseif ($notification->getRelatedType() === 'community_request') {
                                $link = '/community.php';
                            }
                            ?>
                            <?php if ($link): ?>
                                <a href="<?= $link ?>" class="btn btn-small" style="margin-top: 5px;">Перейти</a>
                            <?php endif; ?>
                        <?php endif; ?>
                    </div>
                    <div class="notification-actions">
                        <?php if (!$notification->isRead()): ?>
                            <form method="POST" style="display:inline;">
                                <?= csrf_field() ?>
                                <input type="hidden" name="action" value="mark_read">
                                <input type="hidden" name="id" value="<?= $notification->getId() ?>">
                                <button type="submit" class="btn btn-small" title="Отметить как прочитанное">✓</button>
                            </form>
                        <?php endif; ?>
                        <form method="POST" style="display:inline;" onsubmit="return confirm('Удалить уведомление?');">
                            <?= csrf_field() ?>
                            <input type="hidden" name="action" value="delete">
                            <input type="hidden" name="id" value="<?= $notification->getId() ?>">
                            <button type="submit" class="btn btn-small" style="background:#dc3545;color:white;" title="Удалить">×</button>
                        </form>
                    </div>
                </div>
            <?php endforeach; ?>
        <?php endif; ?>
    </div>
</div>

<style>
.notifications-list {
    display: flex;
    flex-direction: column;
    gap: 10px;
}

.notification-item {
    display: flex;
    gap: 15px;
    padding: 15px;
    background: #f9f9f9;
    border-radius: 8px;
    border-left: 4px solid #ddd;
    transition: all 0.2s;
}

.notification-item:hover {
    background: #f0f0f0;
    box-shadow: 0 2px 8px rgba(0,0,0,0.1);
}

.notification-item.unread {
    background: #e8f4f8;
    border-left-color: #667eea;
    font-weight: 500;
}

.notification-icon {
    font-size: 2em;
    flex-shrink: 0;
}

.notification-content {
    flex: 1;
}

.notification-header {
    display: flex;
    justify-content: space-between;
    align-items: start;
    margin-bottom: 8px;
}

.notification-header h4 {
    margin: 0;
    color: #333;
    font-size: 1.1em;
}

.notification-time {
    color: #666;
    font-size: 0.9em;
    white-space: nowrap;
}

.notification-message {
    margin: 0;
    color: #555;
    line-height: 1.5;
}

.notification-actions {
    display: flex;
    gap: 5px;
    flex-shrink: 0;
    align-items: flex-start;
}

.notification-actions .btn {
    min-width: 30px;
    padding: 5px 10px;
}

@media (max-width: 768px) {
    .notification-item {
        flex-direction: column;
    }
    
    .notification-actions {
        justify-content: flex-end;
        width: 100%;
    }
    
    .notification-header {
        flex-direction: column;
        gap: 5px;
    }
}
</style>
<?php
$content = ob_get_clean();
include __DIR__ . '/../templates/base.php';








