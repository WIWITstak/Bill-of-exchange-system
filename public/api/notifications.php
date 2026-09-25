<?php
/**
 * API endpoint для работы с уведомлениями
 */

require_once __DIR__ . '/../../src/bootstrap.php';

use OGAS\Services\Auth;
use OGAS\Models\Notification;
use OGAS\Services\NotificationService;
use OGAS\Core\Security;
use OGAS\Core\RateLimiter;

header('Content-Type: application/json; charset=UTF-8');

// Rate limiting (100 запросов в минуту с одного IP)
$clientIp = Security::getClientIp();
RateLimiter::requireLimit($clientIp, 100, 60);

Auth::requireAuth();
$user = Auth::user();

if (!$user) {
    http_response_code(401);
    echo json_encode(['error' => 'Unauthorized']);
    exit;
}

$action = $_GET['action'] ?? $_POST['action'] ?? '';

try {
    switch ($action) {
        case 'get_unread_count':
            // Получить количество непрочитанных уведомлений
            $count = Notification::getUnreadCount($user->getId());
            
            echo json_encode([
                'success' => true,
                'count' => $count
            ]);
            break;
            
        case 'mark_read':
            // Отметить уведомление как прочитанное
            $id = (int)($_POST['id'] ?? $_GET['id'] ?? 0);
            
            if ($id <= 0) {
                throw new \Exception('Notification ID required');
            }
            
            $notification = Notification::findById($id);
            if (!$notification || $notification->getUserId() !== $user->getId()) {
                throw new \Exception('Notification not found or access denied');
            }
            
            $notification->markAsRead();
            
            echo json_encode([
                'success' => true
            ]);
            break;
            
        case 'get_recent':
            // Получить последние уведомления
            $limit = (int)($_GET['limit'] ?? 10);
            $limit = min(max($limit, 1), 50); // От 1 до 50
            
            $notifications = Notification::findByUserId($user->getId(), false, $limit);
            
            $formattedNotifications = [];
            foreach ($notifications as $notif) {
                $formattedNotifications[] = [
                    'id' => $notif->getId(),
                    'type' => $notif->getType(),
                    'title' => $notif->getTitle(),
                    'message' => $notif->getMessage(),
                    'is_read' => $notif->isRead(),
                    'created_at' => $notif->getCreatedAt(),
                    'url' => NotificationService::getNotificationUrl($notif),
                    'time_ago' => NotificationService::getTimeAgo($notif->getCreatedAt())
                ];
            }
            
            echo json_encode([
                'success' => true,
                'notifications' => $formattedNotifications
            ], JSON_UNESCAPED_UNICODE);
            break;
            
        case 'mark_all_read':
            // Отметить все уведомления как прочитанные
            Notification::markAllAsRead($user->getId());
            
            echo json_encode([
                'success' => true
            ]);
            break;
            
        default:
            http_response_code(400);
            echo json_encode(['error' => 'Invalid action']);
            break;
    }
} catch (\Exception $e) {
    http_response_code(500);
    echo json_encode(['error' => $e->getMessage()]);
}


