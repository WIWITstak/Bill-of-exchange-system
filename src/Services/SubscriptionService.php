<?php

namespace OGAS\Services;

use OGAS\Models\Subscription;
use OGAS\Models\User;
use OGAS\Models\Bill;
use OGAS\Services\BillService;
use OGAS\Services\NotificationService;
use OGAS\Models\Notification;
use PDO;

/**
 * Сервис для работы с подписками
 */
class SubscriptionService
{
    /**
     * Рассчитать сумму подписки для пользователя
     */
    public static function calculateAmount(User $user): float
    {
        return $user->getUserType() === 'legal' ? 10000.0 : 1000.0;
    }
    
    /**
     * Создать подписку для пользователя
     */
    public static function createSubscription(User $user, ?int $billId = null): Subscription
    {
        $amount = self::calculateAmount($user);
        $periodDays = 30; // Месячная подписка
        
        $subscription = Subscription::create([
            'user_id' => $user->getId(),
            'amount' => $amount,
            'period_days' => $periodDays,
            'status' => 'active',
            'bill_id' => $billId
        ]);
        
        if ($billId) {
            $subscription->markAsPaid($billId);
        }
        
        return $subscription;
    }
    
    /**
     * Продлить подписку (создать новый период)
     */
    public static function renewSubscription(User $user, ?int $billId = null): Subscription
    {
        // Отмечаем старую подписку как истекшую
        $oldSubscription = Subscription::findActiveByUserId($user->getId());
        if ($oldSubscription && $oldSubscription->isActive()) {
            $oldSubscription->markAsExpired();
        }
        
        // Создаём новую подписку
        return self::createSubscription($user, $billId);
    }
    
    /**
     * Проверить и обновить статусы подписок
     * При истечении подписки: деактивирует аккаунт и создаёт новый вексель для продления
     */
    public static function checkExpiredSubscriptions(): int
    {
        $expiredSubscriptions = Subscription::findExpired();
        $count = 0;
        
        foreach ($expiredSubscriptions as $subscription) {
            $subscription->markAsExpired();
            
            $user = User::findById($subscription->getUserId());
            if (!$user || $user->isSystem()) {
                continue;
            }
            
            // Деактивируем аккаунт пользователя, если подписка истекла
            if ($user->isActive()) {
                $user->setActive(false);
                $user->save();
            }
            
            // Проверяем, нет ли уже активного векселя подписки для этого пользователя
            $hasActiveSubscriptionBill = false;
            $activeSubscription = Subscription::findActiveByUserId($user->getId());
            if ($activeSubscription && $activeSubscription->getBillId()) {
                $existingBill = Bill::findById($activeSubscription->getBillId());
                if ($existingBill && $existingBill->getStatus() === 'active') {
                    $hasActiveSubscriptionBill = true;
                }
            }
            
            // Создаём новый вексель для продления подписки (если ещё не создан)
            if (!$hasActiveSubscriptionBill) {
                try {
                    $systemUser = User::getOrCreateSystemUser();
                    $amount = self::calculateAmount($user);
                    
                    // ОГАС собирает подписку в виде векселей: пользователь выписывает вексель в пользу ОГАС
                    $bill = BillService::issue(
                        $user->getId(),      // issuer - пользователь выписывает вексель
                        $systemUser->getId(), // holder - ОГАС получает вексель
                        $amount,             // сумма подписки (1 000 или 10 000 дублей)
                        30                   // срок погашения - 30 дней
                    );
                    
                    // Создаём новую подписку, связанную с векселем
                    // Статус новой подписки будет 'active', но аккаунт останется неактивным до оплаты векселя
                    self::createSubscription($user, $bill->getId());
                    
                    // Создаём уведомление о создании нового векселя для продления
                    NotificationService::createNotification(
                        $user->getId(),
                        'subscription_expired',
                        'Подписка истекла - создан новый вексель',
                        sprintf(
                            'Ваша подписка на систему ОГАС истекла. Аккаунт деактивирован. Для активации необходимо погасить вексель на сумму %s дублей (срок погашения: 30 дней). Номер векселя: #%d.',
                            number_format($amount, 2, '.', ' '),
                            $bill->getId()
                        ),
                        $bill->getId(),
                        'bill'
                    );
                } catch (\Exception $e) {
                    // Если не удалось создать вексель, отправляем простое уведомление об истечении
                    NotificationService::createNotification(
                        $user->getId(),
                        'subscription_expired',
                        'Подписка истекла',
                        'Ваша подписка на систему ОГАС истекла. Аккаунт деактивирован. Для продления подписки обратитесь к администратору.',
                        $subscription->getId(),
                        'subscription'
                    );
                    error_log('Ошибка при создании векселя при истечении подписки: ' . $e->getMessage());
                }
            } else {
                // Если вексель уже существует, отправляем простое уведомление об истечении
                NotificationService::createNotification(
                    $user->getId(),
                    'subscription_expired',
                    'Подписка истекла',
                    'Ваша подписка на систему ОГАС истекла. Аккаунт деактивирован. Погасите вексель подписки для продолжения работы.',
                    $subscription->getId(),
                    'subscription'
                );
            }
            
            $count++;
        }
        
        return $count;
    }
    
    /**
     * Отправить напоминания о подписке
     */
    public static function sendRenewalReminders(int $daysBeforeExpiry = 7): int
    {
        $expiringSoon = Subscription::findExpiringSoon($daysBeforeExpiry);
        $count = 0;
        
        foreach ($expiringSoon as $subscription) {
            // Отправляем напоминание только один раз
            if ($subscription->isReminderSent()) {
                continue;
            }
            
            $user = User::findById($subscription->getUserId());
            if (!$user) {
                continue;
            }
            
            $daysLeft = $subscription->getDaysLeft();
            $amount = self::calculateAmount($user);
            
            // Создаём уведомление о необходимости продления
            NotificationService::createNotification(
                $user->getId(),
                'subscription_renewal_reminder',
                'Напоминание о подписке',
                "Ваша подписка на систему ОГАС истекает через {$daysLeft} " . self::getDaysWord($daysLeft) . ". Для продления необходимо оплатить {$amount} дублей.",
                $subscription->getId(),
                'subscription'
            );
            
            // Отмечаем, что напоминание отправлено
            $subscription->setReminderSent(true);
            $subscription->save();
            
            $count++;
        }
        
        return $count;
    }
    
    /**
     * Обработать оплату подписки (когда вексель погашен)
     */
    public static function processPayment(int $billId): bool
    {
        $bill = Bill::findById($billId);
        if (!$bill || $bill->getStatus() !== 'paid') {
            return false;
        }
        
        // Ищем подписку по векселю
        $subscription = Subscription::findByUserId($bill->getHolderId());
        $targetSubscription = null;
        
        foreach ($subscription as $sub) {
            if ($sub->getBillId() === $billId) {
                $targetSubscription = $sub;
                break;
            }
        }
        
        if (!$targetSubscription) {
            return false;
        }
        
        // Отмечаем подписку как оплаченную
        $targetSubscription->markAsPaid($billId);
        
        // Активируем или продлеваем подписку
        $user = User::findById($targetSubscription->getUserId());
        if ($user && !$user->isActive()) {
            $user->setActive(true);
            $user->save();
        }
        
        // Создаём уведомление об оплате
        NotificationService::createNotification(
            $user->getId(),
            'subscription_paid',
            'Подписка оплачена',
            'Ваша подписка на систему ОГАС успешно оплачена. Аккаунт активирован.',
            $targetSubscription->getId(),
            'subscription'
        );
        
        return true;
    }
    
    /**
     * Ежемесячное списание подписки (создание векселей для всех активных пользователей)
     */
    public static function monthlyBilling(): array
    {
        $results = [
            'success' => 0,
            'failed' => 0,
            'errors' => []
        ];
        
        // Получаем все активные подписки, которые истекают в течение дня
        $db = \OGAS\Database::getConnection();
        $stmt = $db->prepare("
            SELECT * FROM subscriptions 
            WHERE status = 'active' 
            AND end_date BETWEEN NOW() AND DATE_ADD(NOW(), INTERVAL 1 DAY)
            ORDER BY end_date ASC
        ");
        $stmt->execute();
        
        $activeSubscriptions = [];
        while ($data = $stmt->fetch(\PDO::FETCH_ASSOC)) {
            $activeSubscriptions[] = Subscription::fromArray($data);
        }
        
        foreach ($activeSubscriptions as $subscription) {
            if (!$subscription->isActive() || $subscription->isExpired()) {
                continue;
            }
            
            $user = User::findById($subscription->getUserId());
            if (!$user || $user->isSystem()) {
                continue;
            }
            
            try {
                // Создаём вексель на подписку
                $systemUser = User::getOrCreateSystemUser();
                $amount = self::calculateAmount($user);
                
                // ОГАС собирает подписку в виде векселей: пользователь выписывает вексель в пользу ОГАС
                // Пользователь - issuer (эмитент векселя), ОГАС - holder (держатель векселя)
                $bill = BillService::issue(
                    $user->getId(),      // issuer - пользователь выписывает вексель
                    $systemUser->getId(), // holder - ОГАС получает вексель
                    $amount,             // сумма подписки (1 000 или 10 000 дублей)
                    30                   // срок погашения - 30 дней
                );
                
                // Продлеваем подписку
                self::renewSubscription($user, $bill->getId());
                
                // Создаём уведомление о необходимости оплаты векселя подписки
                NotificationService::createNotification(
                    $user->getId(),
                    'subscription_bill_created',
                    'Новый вексель на подписку',
                    sprintf(
                        "Выпущен вексель на подписку на систему ОГАС на сумму %s дублей (срок погашения: 30 дней). Номер векселя: #%d.",
                        number_format($amount, 2, '.', ' '),
                        $bill->getId()
                    ),
                    $bill->getId(),
                    'bill'
                );
                
                $results['success']++;
            } catch (\Exception $e) {
                $results['failed']++;
                $results['errors'][] = [
                    'user_id' => $user->getId(),
                    'error' => $e->getMessage()
                ];
            }
        }
        
        return $results;
    }
    
    /**
     * Вспомогательная функция для склонения слова "день"
     */
    private static function getDaysWord(int $days): string
    {
        if ($days % 10 === 1 && $days % 100 !== 11) {
            return 'день';
        } elseif (in_array($days % 10, [2, 3, 4]) && !in_array($days % 100, [12, 13, 14])) {
            return 'дня';
        } else {
            return 'дней';
        }
    }
    
    /**
     * Получить статистику по подпискам
     */
    public static function getStatistics(): array
    {
        $db = \OGAS\Database::getConnection();
        
        // Всего подписок
        $stmt = $db->query("SELECT COUNT(*) FROM subscriptions");
        $total = (int)$stmt->fetchColumn();
        
        // Активных
        $stmt = $db->query("SELECT COUNT(*) FROM subscriptions WHERE status = 'active'");
        $active = (int)$stmt->fetchColumn();
        
        // Истекших
        $stmt = $db->query("SELECT COUNT(*) FROM subscriptions WHERE status = 'expired'");
        $expired = (int)$stmt->fetchColumn();
        
        // Истекающих в ближайшие 7 дней
        $stmt = $db->query("
            SELECT COUNT(*) FROM subscriptions 
            WHERE status = 'active' 
            AND end_date BETWEEN NOW() AND DATE_ADD(NOW(), INTERVAL 7 DAY)
        ");
        $expiringSoon = (int)$stmt->fetchColumn();
        
        return [
            'total' => $total,
            'active' => $active,
            'expired' => $expired,
            'expiring_soon' => $expiringSoon
        ];
    }
}

