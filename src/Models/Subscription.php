<?php

namespace OGAS\Models;

use OGAS\Database;
use PDO;

/**
 * Модель подписки пользователя
 */
class Subscription
{
    private ?int $id = null;
    private int $userId;
    private float $amount;
    private int $periodDays = 30;
    private string $status = 'active'; // active, expired, cancelled
    private string $startDate;
    private string $endDate;
    private ?int $billId = null;
    private ?string $paidAt = null;
    private bool $reminderSent = false;
    private ?string $createdAt = null;
    private ?string $updatedAt = null;
    
    /**
     * Найти подписку по ID
     */
    public static function findById(int $id): ?self
    {
        $db = Database::getConnection();
        $stmt = $db->prepare("SELECT * FROM subscriptions WHERE id = ?");
        $stmt->execute([$id]);
        $data = $stmt->fetch(PDO::FETCH_ASSOC);
        
        if (!$data) {
            return null;
        }
        
        return self::fromArray($data);
    }
    
    /**
     * Найти активную подписку пользователя
     */
    public static function findActiveByUserId(int $userId): ?self
    {
        $db = Database::getConnection();
        $stmt = $db->prepare("
            SELECT * FROM subscriptions 
            WHERE user_id = ? AND status = 'active' 
            ORDER BY end_date DESC 
            LIMIT 1
        ");
        $stmt->execute([$userId]);
        $data = $stmt->fetch(PDO::FETCH_ASSOC);
        
        if (!$data) {
            return null;
        }
        
        return self::fromArray($data);
    }
    
    /**
     * Найти все подписки пользователя
     */
    public static function findByUserId(int $userId, ?string $status = null): array
    {
        $db = Database::getConnection();
        $sql = "SELECT * FROM subscriptions WHERE user_id = ?";
        $params = [$userId];
        
        if ($status) {
            $sql .= " AND status = ?";
            $params[] = $status;
        }
        
        $sql .= " ORDER BY end_date DESC";
        
        $stmt = $db->prepare($sql);
        $stmt->execute($params);
        
        $subscriptions = [];
        while ($data = $stmt->fetch(PDO::FETCH_ASSOC)) {
            $subscriptions[] = self::fromArray($data);
        }
        
        return $subscriptions;
    }
    
    /**
     * Найти подписки, которые истекают в ближайшие дни
     */
    public static function findExpiringSoon(int $days = 7): array
    {
        $db = Database::getConnection();
        $stmt = $db->prepare("
            SELECT * FROM subscriptions 
            WHERE status = 'active' 
            AND end_date BETWEEN NOW() AND DATE_ADD(NOW(), INTERVAL ? DAY)
            ORDER BY end_date ASC
        ");
        $stmt->execute([$days]);
        
        $subscriptions = [];
        while ($data = $stmt->fetch(PDO::FETCH_ASSOC)) {
            $subscriptions[] = self::fromArray($data);
        }
        
        return $subscriptions;
    }
    
    /**
     * Найти истекшие подписки
     */
    public static function findExpired(): array
    {
        $db = Database::getConnection();
        $stmt = $db->prepare("
            SELECT * FROM subscriptions 
            WHERE status = 'active' 
            AND end_date < NOW()
            ORDER BY end_date ASC
        ");
        $stmt->execute();
        
        $subscriptions = [];
        while ($data = $stmt->fetch(PDO::FETCH_ASSOC)) {
            $subscriptions[] = self::fromArray($data);
        }
        
        return $subscriptions;
    }
    
    /**
     * Создать подписку из массива данных
     */
    public static function fromArray(array $data): self
    {
        $subscription = new self();
        $subscription->id = (int)$data['id'];
        $subscription->userId = (int)$data['user_id'];
        $subscription->amount = (float)$data['amount'];
        $subscription->periodDays = (int)$data['period_days'];
        $subscription->status = $data['status'];
        $subscription->startDate = $data['start_date'];
        $subscription->endDate = $data['end_date'];
        $subscription->billId = $data['bill_id'] ? (int)$data['bill_id'] : null;
        $subscription->paidAt = $data['paid_at'] ?? null;
        $subscription->reminderSent = (bool)($data['reminder_sent'] ?? false);
        $subscription->createdAt = $data['created_at'] ?? null;
        $subscription->updatedAt = $data['updated_at'] ?? null;
        return $subscription;
    }
    
    /**
     * Создать новую подписку
     */
    public static function create(array $data): self
    {
        $db = Database::getConnection();
        
        $startDate = $data['start_date'] ?? date('Y-m-d H:i:s');
        $periodDays = $data['period_days'] ?? 30;
        $endDate = date('Y-m-d H:i:s', strtotime("{$startDate} +{$periodDays} days"));
        
        $stmt = $db->prepare("
            INSERT INTO subscriptions (
                user_id, amount, period_days, status, 
                start_date, end_date, bill_id, paid_at
            ) VALUES (?, ?, ?, ?, ?, ?, ?, ?)
        ");
        
        $stmt->execute([
            $data['user_id'],
            $data['amount'],
            $periodDays,
            $data['status'] ?? 'active',
            $startDate,
            $endDate,
            $data['bill_id'] ?? null,
            $data['paid_at'] ?? null
        ]);
        
        $subscriptionId = (int)$db->lastInsertId();
        return self::findById($subscriptionId);
    }
    
    /**
     * Сохранить изменения
     */
    public function save(): bool
    {
        if (!$this->id) {
            return false;
        }
        
        $db = Database::getConnection();
        $stmt = $db->prepare("
            UPDATE subscriptions 
            SET amount = ?, period_days = ?, status = ?, 
                start_date = ?, end_date = ?, bill_id = ?, 
                paid_at = ?, reminder_sent = ?
            WHERE id = ?
        ");
        
        return $stmt->execute([
            $this->amount,
            $this->periodDays,
            $this->status,
            $this->startDate,
            $this->endDate,
            $this->billId,
            $this->paidAt,
            $this->reminderSent ? 1 : 0,
            $this->id
        ]);
    }
    
    /**
     * Отметить подписку как оплаченную
     */
    public function markAsPaid(?int $billId = null): bool
    {
        $this->status = 'active';
        $this->paidAt = date('Y-m-d H:i:s');
        if ($billId !== null) {
            $this->billId = $billId;
        }
        return $this->save();
    }
    
    /**
     * Отметить подписку как истекшую
     */
    public function markAsExpired(): bool
    {
        $this->status = 'expired';
        return $this->save();
    }
    
    /**
     * Отменить подписку
     */
    public function cancel(): bool
    {
        $this->status = 'cancelled';
        return $this->save();
    }
    
    /**
     * Продлить подписку
     */
    public function extend(int $days): bool
    {
        $newEndDate = date('Y-m-d H:i:s', strtotime("{$this->endDate} +{$days} days"));
        $this->endDate = $newEndDate;
        $this->periodDays += $days;
        return $this->save();
    }
    
    /**
     * Проверить, истекла ли подписка
     */
    public function isExpired(): bool
    {
        return strtotime($this->endDate) < time();
    }
    
    /**
     * Проверить, активна ли подписка
     */
    public function isActive(): bool
    {
        return $this->status === 'active' && !$this->isExpired();
    }
    
    /**
     * Получить количество оставшихся дней
     */
    public function getDaysLeft(): int
    {
        $endTimestamp = strtotime($this->endDate);
        $now = time();
        $diff = $endTimestamp - $now;
        return max(0, (int)ceil($diff / 86400));
    }
    
    // Getters
    public function getId(): ?int { return $this->id; }
    public function getUserId(): int { return $this->userId; }
    public function getAmount(): float { return $this->amount; }
    public function getPeriodDays(): int { return $this->periodDays; }
    public function getStatus(): string { return $this->status; }
    public function getStartDate(): string { return $this->startDate; }
    public function getEndDate(): string { return $this->endDate; }
    public function getBillId(): ?int { return $this->billId; }
    public function getPaidAt(): ?string { return $this->paidAt; }
    public function isReminderSent(): bool { return $this->reminderSent; }
    
    // Setters
    public function setAmount(float $amount): void { $this->amount = $amount; }
    public function setPeriodDays(int $periodDays): void { $this->periodDays = $periodDays; }
    public function setStatus(string $status): void { $this->status = $status; }
    public function setBillId(?int $billId): void { $this->billId = $billId; }
    public function setReminderSent(bool $reminderSent): void { $this->reminderSent = $reminderSent; }
}








