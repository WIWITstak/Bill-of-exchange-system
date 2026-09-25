<?php

namespace OGAS\Services;

use OGAS\Models\Notification;
use OGAS\Models\Bill;
use OGAS\Models\Transaction;
use OGAS\Models\CommunityRequest;
use OGAS\Models\User;

/**
 * Сервис для работы с уведомлениями
 */
class NotificationService
{
    /**
     * Создать уведомление о получении нового векселя
     */
    public static function notifyBillReceived(int $userId, int $billId): void
    {
        $bill = Bill::findById($billId);
        if (!$bill) {
            return;
        }
        
        $issuer = User::findById($bill->getIssuerId());
        
        Notification::create([
            'user_id' => $userId,
            'type' => Notification::TYPE_BILL_RECEIVED,
            'title' => 'Получен новый вексель',
            'message' => sprintf(
                'Вы получили вексель на сумму %s ₽ от %s. Срок погашения: %s',
                number_format($bill->getNominal(), 2, '.', ' '),
                $issuer->getFullName(),
                date('d.m.Y', strtotime($bill->getMaturityDate()))
            ),
            'related_id' => $billId,
            'related_type' => 'bill'
        ]);
    }
    
    /**
     * Создать уведомление о напоминании о погашении векселя
     */
    public static function notifyBillMaturityReminder(int $userId, int $billId, int $daysLeft): void
    {
        $bill = Bill::findById($billId);
        if (!$bill) {
            return;
        }
        
        Notification::create([
            'user_id' => $userId,
            'type' => Notification::TYPE_BILL_MATURITY_REMINDER,
            'title' => 'Напоминание о погашении векселя',
            'message' => sprintf(
                'Вексель #%d на сумму %s ₽ необходимо погасить через %d %s. Срок погашения: %s',
                $bill->getId(),
                number_format($bill->getNominal(), 2, '.', ' '),
                $daysLeft,
                self::getDaysWord($daysLeft),
                date('d.m.Y', strtotime($bill->getMaturityDate()))
            ),
            'related_id' => $billId,
            'related_type' => 'bill'
        ]);
    }
    
    /**
     * Создать уведомление о просроченном векселе
     */
    public static function notifyBillOverdue(int $userId, int $billId): void
    {
        $bill = Bill::findById($billId);
        if (!$bill) {
            return;
        }
        
        $holder = User::findById($bill->getHolderId());
        
        Notification::create([
            'user_id' => $userId,
            'type' => Notification::TYPE_BILL_OVERDUE,
            'title' => 'Вексель просрочен',
            'message' => sprintf(
                'Вексель #%d на сумму %s ₽, выданный для %s, просрочен. Срок погашения был: %s',
                $bill->getId(),
                number_format($bill->getNominal(), 2, '.', ' '),
                $holder->getFullName(),
                date('d.m.Y', strtotime($bill->getMaturityDate()))
            ),
            'related_id' => $billId,
            'related_type' => 'bill'
        ]);
    }
    
    /**
     * Создать уведомление о погашении векселя
     */
    public static function notifyBillPaid(int $userId, int $billId): void
    {
        $bill = Bill::findById($billId);
        if (!$bill) {
            return;
        }
        
        Notification::create([
            'user_id' => $userId,
            'type' => Notification::TYPE_BILL_PAID,
            'title' => 'Вексель погашен',
            'message' => sprintf(
                'Вексель #%d на сумму %s ₽ был успешно погашен.',
                $bill->getId(),
                number_format($bill->getNominal(), 2, '.', ' ')
            ),
            'related_id' => $billId,
            'related_type' => 'bill'
        ]);
    }
    
    /**
     * Создать уведомление о новой заявке в "Общине"
     */
    public static function notifyCommunityRequest(int $userId, int $requestId): void
    {
        $request = CommunityRequest::findById($requestId);
        if (!$request) {
            return;
        }
        
        $requester = User::findById($request->getUserId());
        
        Notification::create([
            'user_id' => $userId,
            'type' => Notification::TYPE_COMMUNITY_REQUEST,
            'title' => 'Новая заявка в "Общине"',
            'message' => sprintf(
                '%s создал(а) новую заявку на %d векселей по %s ₽ каждый. Срок погашения: %d дней.',
                $requester->getFullName(),
                $request->getAmount(),
                number_format($request->getNominal(), 2, '.', ' '),
                $request->getMaturityDays()
            ),
            'related_id' => $requestId,
            'related_type' => 'community_request'
        ]);
    }
    
    /**
     * Создать уведомление о новом поручителе
     */
    public static function notifyCommunityGuarantor(int $userId, int $requestId, int $guarantorId): void
    {
        $request = CommunityRequest::findById($requestId);
        $guarantor = User::findById($guarantorId);
        
        if (!$request || !$guarantor) {
            return;
        }
        
        Notification::create([
            'user_id' => $userId,
            'type' => Notification::TYPE_COMMUNITY_GUARANTOR,
            'title' => 'Новый поручитель',
            'message' => sprintf(
                '%s стал поручителем по вашей заявке #%d',
                $guarantor->getFullName(),
                $requestId
            ),
            'related_id' => $requestId,
            'related_type' => 'community_request'
        ]);
    }
    
    /**
     * Создать уведомление о создании транзакции
     */
    public static function notifyTransactionCreated(int $userId, int $transactionId): void
    {
        $transaction = Transaction::findById($transactionId);
        if (!$transaction) {
            return;
        }
        
        $seller = User::findById($transaction->getSellerId());
        $buyer = User::findById($transaction->getBuyerId());
        
        $role = '';
        $otherUser = null;
        if ($transaction->getSellerId() === $userId) {
            $role = 'продавцом';
            $otherUser = $buyer;
        } else {
            $role = 'покупателем';
            $otherUser = $seller;
        }
        
        Notification::create([
            'user_id' => $userId,
            'type' => Notification::TYPE_TRANSACTION_CREATED,
            'title' => 'Новая транзакция',
            'message' => sprintf(
                'Создана новая транзакция #%d, где вы являетесь %s с %s',
                $transactionId,
                $role,
                $otherUser->getFullName()
            ),
            'related_id' => $transactionId,
            'related_type' => 'transaction'
        ]);
    }
    
    /**
     * Создать уведомление о завершении транзакции
     */
    public static function notifyTransactionCompleted(int $userId, int $transactionId): void
    {
        $transaction = Transaction::findById($transactionId);
        if (!$transaction) {
            return;
        }
        
        Notification::create([
            'user_id' => $userId,
            'type' => Notification::TYPE_TRANSACTION_COMPLETED,
            'title' => 'Транзакция завершена',
            'message' => sprintf(
                'Транзакция #%d была успешно завершена.',
                $transactionId
            ),
            'related_id' => $transactionId,
            'related_type' => 'transaction'
        ]);
    }
    
    /**
     * Создать уведомление об отмене транзакции
     */
    public static function notifyTransactionCancelled(int $userId, int $transactionId): void
    {
        $transaction = Transaction::findById($transactionId);
        if (!$transaction) {
            return;
        }
        
        Notification::create([
            'user_id' => $userId,
            'type' => Notification::TYPE_TRANSACTION_CANCELLED,
            'title' => 'Транзакция отменена',
            'message' => sprintf(
                'Транзакция #%d была отменена.',
                $transactionId
            ),
            'related_id' => $transactionId,
            'related_type' => 'transaction'
        ]);
    }
    
    /**
     * Проверить и создать напоминания о погашении векселей
     */
    public static function checkMaturityReminders(int $userId): void
    {
        $bills = Bill::findByIssuer($userId, 'active');
        
        foreach ($bills as $bill) {
            $maturityDate = strtotime($bill->getMaturityDate());
            $today = strtotime('today');
            $daysLeft = floor(($maturityDate - $today) / 86400);
            
            // Напоминаем за 7, 3 и 1 день до погашения
            if (in_array($daysLeft, [7, 3, 1]) && $daysLeft > 0) {
                // Проверяем, нет ли уже такого уведомления за сегодня
                $existing = self::hasRecentNotification($userId, Notification::TYPE_BILL_MATURITY_REMINDER, $bill->getId(), 1);
                if (!$existing) {
                    self::notifyBillMaturityReminder($userId, $bill->getId(), $daysLeft);
                }
            }
        }
    }
    
    /**
     * Проверить просроченные вексели и создать уведомления
     */
    public static function checkOverdueBills(int $userId): void
    {
        $bills = Bill::findByIssuer($userId, 'active');
        
        foreach ($bills as $bill) {
            if ($bill->getStatus() === 'active' && strtotime($bill->getMaturityDate()) < strtotime('today')) {
                // Проверяем, нет ли уже уведомления о просрочке за сегодня
                $existing = self::hasRecentNotification($userId, Notification::TYPE_BILL_OVERDUE, $bill->getId(), 1);
                if (!$existing) {
                    self::notifyBillOverdue($userId, $bill->getId());
                }
            }
        }
    }
    
    /**
     * Создать уведомление (универсальный метод)
     */
    public static function createNotification(
        int $userId, 
        string $type, 
        string $title, 
        string $message, 
        ?int $relatedId = null, 
        ?string $relatedType = null
    ): \OGAS\Models\Notification {
        return Notification::create([
            'user_id' => $userId,
            'type' => $type,
            'title' => $title,
            'message' => $message,
            'related_id' => $relatedId,
            'related_type' => $relatedType
        ]);
    }
    
    /**
     * Проверить, есть ли недавнее уведомление
     */
    private static function hasRecentNotification(int $userId, string $type, ?int $relatedId, int $days): bool
    {
        $db = \OGAS\Database::getConnection();
        $stmt = $db->prepare("
            SELECT COUNT(*) as count 
            FROM notifications 
            WHERE user_id = ? 
            AND type = ? 
            AND related_id = ?
            AND created_at >= DATE_SUB(NOW(), INTERVAL ? DAY)
        ");
        $stmt->execute([$userId, $type, $relatedId, $days]);
        $result = $stmt->fetch(\PDO::FETCH_ASSOC);
        
        return (int)($result['count'] ?? 0) > 0;
    }
    
    /**
     * Получить правильное склонение для дней
     */
    private static function getDaysWord(int $days): string
    {
        $lastDigit = $days % 10;
        $lastTwoDigits = $days % 100;
        
        if ($lastTwoDigits >= 11 && $lastTwoDigits <= 14) {
            return 'дней';
        }
        
        if ($lastDigit == 1) {
            return 'день';
        } elseif ($lastDigit >= 2 && $lastDigit <= 4) {
            return 'дня';
        } else {
            return 'дней';
        }
    }
    
    /**
     * Получить URL для уведомления
     */
    public static function getNotificationUrl(\OGAS\Models\Notification $notification): string
    {
        $relatedType = $notification->getRelatedType();
        $relatedId = $notification->getRelatedId();
        
        if (!$relatedType || !$relatedId) {
            return '/notifications.php';
        }
        
        switch ($relatedType) {
            case 'bill':
                return '/bills.php?id=' . $relatedId;
            case 'transaction':
                // Если это уведомление о сообщении в чате, открываем чат
                if ($notification->getType() === 'chat_message') {
                    return '/transactions/chat.php?id=' . $relatedId;
                }
                return '/transactions.php?id=' . $relatedId;
            case 'community_request':
                return '/community.php#request-' . $relatedId;
            case 'subscription':
                return '/subscriptions.php';
            default:
                return '/notifications.php';
        }
    }
    
    /**
     * Получить время назад (например, "5 минут назад")
     */
    public static function getTimeAgo(?string $datetime): string
    {
        if (!$datetime) {
            return 'недавно';
        }
        
        $time = strtotime($datetime);
        $diff = time() - $time;
        
        if ($diff < 60) {
            return 'только что';
        } elseif ($diff < 3600) {
            $minutes = floor($diff / 60);
            $word = self::getMinutesWord($minutes);
            return $minutes . ' ' . $word . ' назад';
        } elseif ($diff < 86400) {
            $hours = floor($diff / 3600);
            $word = self::getHoursWord($hours);
            return $hours . ' ' . $word . ' назад';
        } elseif ($diff < 604800) {
            $days = floor($diff / 86400);
            $word = self::getDaysWord($days);
            return $days . ' ' . $word . ' назад';
        } else {
            return date('d.m.Y H:i', $time);
        }
    }
    
    /**
     * Получить правильное склонение для минут
     */
    private static function getMinutesWord(int $minutes): string
    {
        $lastDigit = $minutes % 10;
        $lastTwoDigits = $minutes % 100;
        
        if ($lastTwoDigits >= 11 && $lastTwoDigits <= 14) {
            return 'минут';
        }
        
        if ($lastDigit == 1) {
            return 'минуту';
        } elseif ($lastDigit >= 2 && $lastDigit <= 4) {
            return 'минуты';
        } else {
            return 'минут';
        }
    }
    
    /**
     * Получить правильное склонение для часов
     */
    private static function getHoursWord(int $hours): string
    {
        $lastDigit = $hours % 10;
        $lastTwoDigits = $hours % 100;
        
        if ($lastTwoDigits >= 11 && $lastTwoDigits <= 14) {
            return 'часов';
        }
        
        if ($lastDigit == 1) {
            return 'час';
        } elseif ($lastDigit >= 2 && $lastDigit <= 4) {
            return 'часа';
        } else {
            return 'часов';
        }
    }
}

