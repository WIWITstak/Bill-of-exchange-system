<?php

namespace OGAS\Models;

use OGAS\Database;
use PDO;

/**
 * Модель уведомления
 */
class Notification
{
    private ?int $id = null;
    private int $userId;
    private string $type;
    private string $title;
    private string $message;
    private ?int $relatedId = null;
    private ?string $relatedType = null;
    private bool $isRead = false;
    private ?string $readAt = null;
    private ?string $createdAt = null;
    
    /**
     * Типы уведомлений
     */
    const TYPE_BILL_RECEIVED = 'bill_received';
    const TYPE_BILL_MATURITY_REMINDER = 'bill_maturity_reminder';
    const TYPE_BILL_OVERDUE = 'bill_overdue';
    const TYPE_BILL_PAID = 'bill_paid';
    const TYPE_COMMUNITY_REQUEST = 'community_request';
    const TYPE_COMMUNITY_GUARANTOR = 'community_guarantor';
    const TYPE_TRANSACTION_CREATED = 'transaction_created';
    const TYPE_TRANSACTION_COMPLETED = 'transaction_completed';
    const TYPE_TRANSACTION_CANCELLED = 'transaction_cancelled';
    
    /**
     * Создать из массива
     */
    public static function fromArray(array $data): self
    {
        $notification = new self();
        $notification->id = (int)$data['id'];
        $notification->userId = (int)$data['user_id'];
        $notification->type = $data['type'];
        $notification->title = $data['title'];
        $notification->message = $data['message'];
        $notification->relatedId = isset($data['related_id']) ? (int)$data['related_id'] : null;
        $notification->relatedType = $data['related_type'] ?? null;
        $notification->isRead = (bool)($data['is_read'] ?? false);
        $notification->readAt = $data['read_at'] ?? null;
        $notification->createdAt = $data['created_at'] ?? null;
        return $notification;
    }
    
    /**
     * Найти уведомление по ID
     */
    public static function findById(int $id): ?self
    {
        $db = Database::getConnection();
        $stmt = $db->prepare("SELECT * FROM notifications WHERE id = ?");
        $stmt->execute([$id]);
        $data = $stmt->fetch(PDO::FETCH_ASSOC);
        
        if (!$data) {
            return null;
        }
        
        return self::fromArray($data);
    }
    
    /**
     * Получить все уведомления пользователя
     */
    public static function findByUserId(int $userId, bool $unreadOnly = false, int $limit = 50): array
    {
        $db = Database::getConnection();
        $sql = "SELECT * FROM notifications WHERE user_id = ?";
        $params = [$userId];
        
        if ($unreadOnly) {
            $sql .= " AND is_read = 0";
        }
        
        $sql .= " ORDER BY created_at DESC LIMIT ?";
        $params[] = $limit;
        
        $stmt = $db->prepare($sql);
        $stmt->execute($params);
        
        $notifications = [];
        while ($data = $stmt->fetch(PDO::FETCH_ASSOC)) {
            $notifications[] = self::fromArray($data);
        }
        
        return $notifications;
    }
    
    /**
     * Получить количество непрочитанных уведомлений
     */
    public static function getUnreadCount(int $userId): int
    {
        $db = Database::getConnection();
        $stmt = $db->prepare("SELECT COUNT(*) as count FROM notifications WHERE user_id = ? AND is_read = 0");
        $stmt->execute([$userId]);
        $result = $stmt->fetch(PDO::FETCH_ASSOC);
        
        return (int)($result['count'] ?? 0);
    }
    
    /**
     * Создать новое уведомление
     */
    public static function create(array $data): self
    {
        $db = Database::getConnection();
        
        $stmt = $db->prepare("
            INSERT INTO notifications (user_id, type, title, message, related_id, related_type, is_read)
            VALUES (?, ?, ?, ?, ?, ?, 0)
        ");
        
        $stmt->execute([
            $data['user_id'],
            $data['type'],
            $data['title'],
            $data['message'],
            $data['related_id'] ?? null,
            $data['related_type'] ?? null
        ]);
        
        $id = (int)$db->lastInsertId();
        return self::findById($id);
    }
    
    /**
     * Отметить как прочитанное
     */
    public function markAsRead(): bool
    {
        if (!$this->id || $this->isRead) {
            return false;
        }
        
        $db = Database::getConnection();
        $stmt = $db->prepare("
            UPDATE notifications 
            SET is_read = 1, read_at = NOW()
            WHERE id = ?
        ");
        
        if ($stmt->execute([$this->id])) {
            $this->isRead = true;
            $this->readAt = date('Y-m-d H:i:s');
            return true;
        }
        
        return false;
    }
    
    /**
     * Отметить все уведомления пользователя как прочитанные
     */
    public static function markAllAsRead(int $userId): bool
    {
        $db = Database::getConnection();
        $stmt = $db->prepare("
            UPDATE notifications 
            SET is_read = 1, read_at = NOW()
            WHERE user_id = ? AND is_read = 0
        ");
        
        return $stmt->execute([$userId]);
    }
    
    /**
     * Удалить уведомление
     */
    public function delete(): bool
    {
        if (!$this->id) {
            return false;
        }
        
        $db = Database::getConnection();
        $stmt = $db->prepare("DELETE FROM notifications WHERE id = ?");
        return $stmt->execute([$this->id]);
    }
    
    /**
     * Удалить все прочитанные уведомления пользователя
     */
    public static function deleteRead(int $userId): bool
    {
        $db = Database::getConnection();
        $stmt = $db->prepare("DELETE FROM notifications WHERE user_id = ? AND is_read = 1");
        return $stmt->execute([$userId]);
    }
    
    // Getters
    public function getId(): ?int { return $this->id; }
    public function getUserId(): int { return $this->userId; }
    public function getType(): string { return $this->type; }
    public function getTitle(): string { return $this->title; }
    public function getMessage(): string { return $this->message; }
    public function getRelatedId(): ?int { return $this->relatedId; }
    public function getRelatedType(): ?string { return $this->relatedType; }
    public function isRead(): bool { return $this->isRead; }
    public function getReadAt(): ?string { return $this->readAt; }
    public function getCreatedAt(): ?string { return $this->createdAt; }
    
    // Setters
    public function setUserId(int $userId): void { $this->userId = $userId; }
    public function setType(string $type): void { $this->type = $type; }
    public function setTitle(string $title): void { $this->title = $title; }
    public function setMessage(string $message): void { $this->message = $message; }
    public function setRelatedId(?int $relatedId): void { $this->relatedId = $relatedId; }
    public function setRelatedType(?string $relatedType): void { $this->relatedType = $relatedType; }
}








