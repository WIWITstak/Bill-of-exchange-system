<?php

namespace OGAS\Models;

use OGAS\Database;
use PDO;

/**
 * Модель сообщения в чате транзакции
 */
class Message
{
    private ?int $id = null;
    private ?int $transactionId = null; // NULL для групповых чатов общины
    private ?int $communityRequestId = null; // ID заявки общины для группового чата
    private int $userId;
    private string $message;
    private ?string $imagePath = null; // Путь к изображению в сообщении
    private bool $isRead = false;
    private ?string $createdAt = null;
    
    /**
     * Найти сообщение по ID
     */
    public static function findById(int $id): ?self
    {
        $db = Database::getConnection();
        $stmt = $db->prepare("SELECT * FROM messages WHERE id = ?");
        $stmt->execute([$id]);
        $data = $stmt->fetch(PDO::FETCH_ASSOC);
        
        if (!$data) {
            return null;
        }
        
        return self::fromArray($data);
    }
    
    /**
     * Получить все сообщения транзакции
     */
    public static function findByTransaction(int $transactionId, ?int $limit = null, ?int $offset = null): array
    {
        $db = Database::getConnection();
        $sql = "SELECT * FROM messages WHERE transaction_id = ? ORDER BY created_at ASC";
        
        if ($limit !== null) {
            $sql .= " LIMIT ?";
            if ($offset !== null) {
                $sql .= " OFFSET ?";
            }
        }
        
        $stmt = $db->prepare($sql);
        
        if ($limit !== null && $offset !== null) {
            $stmt->execute([$transactionId, $limit, $offset]);
        } elseif ($limit !== null) {
            $stmt->execute([$transactionId, $limit]);
        } else {
            $stmt->execute([$transactionId]);
        }
        
        $messages = [];
        while ($data = $stmt->fetch(PDO::FETCH_ASSOC)) {
            $messages[] = self::fromArray($data);
        }
        
        return $messages;
    }
    
    /**
     * Получить новые сообщения транзакции (после указанной даты)
     */
    public static function findNewByTransaction(int $transactionId, string $afterDate): array
    {
        $db = Database::getConnection();
        $stmt = $db->prepare("
            SELECT * FROM messages 
            WHERE transaction_id = ? AND created_at > ? 
            ORDER BY created_at ASC
        ");
        $stmt->execute([$transactionId, $afterDate]);
        
        $messages = [];
        while ($data = $stmt->fetch(PDO::FETCH_ASSOC)) {
            $messages[] = self::fromArray($data);
        }
        
        return $messages;
    }
    
    /**
     * Получить количество непрочитанных сообщений для пользователя в транзакции
     */
    public static function getUnreadCount(int $transactionId, int $userId): int
    {
        $db = Database::getConnection();
        $stmt = $db->prepare("
            SELECT COUNT(*) FROM messages 
            WHERE transaction_id = ? AND user_id != ? AND is_read = 0
        ");
        $stmt->execute([$transactionId, $userId]);
        return (int)$stmt->fetchColumn();
    }
    
    /**
     * Получить количество непрочитанных сообщений в групповом чате общины
     */
    public static function getUnreadCountByCommunityRequest(int $communityRequestId, int $userId): int
    {
        $db = Database::getConnection();
        $stmt = $db->prepare("
            SELECT COUNT(*) FROM messages 
            WHERE community_request_id = ? AND transaction_id IS NULL AND user_id != ? AND is_read = 0
        ");
        $stmt->execute([$communityRequestId, $userId]);
        return (int)$stmt->fetchColumn();
    }
    
    /**
     * Отметить сообщения транзакции как прочитанные (для указанного пользователя)
     */
    public static function markAsReadByTransaction(int $transactionId, int $userId): bool
    {
        $db = Database::getConnection();
        $stmt = $db->prepare("
            UPDATE messages 
            SET is_read = 1 
            WHERE transaction_id = ? AND user_id != ?
        ");
        return $stmt->execute([$transactionId, $userId]);
    }
    
    /**
     * Отметить сообщения группового чата общины как прочитанные (для указанного пользователя)
     */
    public static function markAsReadByCommunityRequest(int $communityRequestId, int $userId): bool
    {
        $db = Database::getConnection();
        $stmt = $db->prepare("
            UPDATE messages 
            SET is_read = 1 
            WHERE community_request_id = ? AND transaction_id IS NULL AND user_id != ?
        ");
        return $stmt->execute([$communityRequestId, $userId]);
    }
    
    /**
     * Создать сообщение из массива данных
     */
    public static function fromArray(array $data): self
    {
        $message = new self();
        $message->id = (int)$data['id'];
        $message->transactionId = isset($data['transaction_id']) && $data['transaction_id'] ? (int)$data['transaction_id'] : null;
        $message->communityRequestId = isset($data['community_request_id']) && $data['community_request_id'] ? (int)$data['community_request_id'] : null;
        $message->userId = (int)$data['user_id'];
        $message->message = $data['message'];
        $message->imagePath = isset($data['image_path']) && $data['image_path'] ? $data['image_path'] : null;
        $message->isRead = (bool)($data['is_read'] ?? false);
        $message->createdAt = $data['created_at'] ?? null;
        return $message;
    }
    
    /**
     * Создать новое сообщение
     */
    public static function create(array $data): self
    {
        $db = Database::getConnection();
        
        // Проверяем, существует ли поле image_path в таблице
        $hasImagePath = false;
        try {
            $checkStmt = $db->query("SHOW COLUMNS FROM messages LIKE 'image_path'");
            $hasImagePath = $checkStmt->rowCount() > 0;
        } catch (\Exception $e) {
            // Если ошибка, значит поля нет
            $hasImagePath = false;
        }
        
        if ($hasImagePath) {
            $stmt = $db->prepare("
                INSERT INTO messages (transaction_id, community_request_id, user_id, message, image_path)
                VALUES (?, ?, ?, ?, ?)
            ");
            
            $stmt->execute([
                $data['transaction_id'] ?? null,
                $data['community_request_id'] ?? null,
                $data['user_id'],
                $data['message'] ?? '',
                $data['image_path'] ?? null
            ]);
        } else {
            // Если поля нет, вставляем без image_path
            $stmt = $db->prepare("
                INSERT INTO messages (transaction_id, community_request_id, user_id, message)
                VALUES (?, ?, ?, ?)
            ");
            
            $stmt->execute([
                $data['transaction_id'] ?? null,
                $data['community_request_id'] ?? null,
                $data['user_id'],
                $data['message'] ?? ''
            ]);
        }
        
        $messageId = (int)$db->lastInsertId();
        return self::findById($messageId);
    }
    
    /**
     * Отметить сообщение как прочитанное
     */
    public function markAsRead(): bool
    {
        if (!$this->id) {
            return false;
        }
        
        $db = Database::getConnection();
        $stmt = $db->prepare("UPDATE messages SET is_read = 1 WHERE id = ?");
        
        if ($stmt->execute([$this->id])) {
            $this->isRead = true;
            return true;
        }
        
        return false;
    }
    
    /**
     * Удалить сообщение
     */
    public function delete(): bool
    {
        if (!$this->id) {
            return false;
        }
        
        $db = Database::getConnection();
        $stmt = $db->prepare("DELETE FROM messages WHERE id = ?");
        return $stmt->execute([$this->id]);
    }
    
    /**
     * Получить все сообщения группового чата заявки общины
     */
    public static function findByCommunityRequest(int $communityRequestId): array
    {
        $db = Database::getConnection();
        $stmt = $db->prepare("
            SELECT * FROM messages 
            WHERE community_request_id = ? AND transaction_id IS NULL
            ORDER BY created_at ASC
        ");
        $stmt->execute([$communityRequestId]);
        
        $messages = [];
        while ($data = $stmt->fetch(PDO::FETCH_ASSOC)) {
            $messages[] = self::fromArray($data);
        }
        
        return $messages;
    }
    
    /**
     * Получить новые сообщения группового чата общины (после указанной даты)
     */
    public static function findNewByCommunityRequest(int $communityRequestId, string $afterDate): array
    {
        $db = Database::getConnection();
        $stmt = $db->prepare("
            SELECT * FROM messages 
            WHERE community_request_id = ? AND transaction_id IS NULL AND created_at > ? 
            ORDER BY created_at ASC
        ");
        $stmt->execute([$communityRequestId, $afterDate]);
        
        $messages = [];
        while ($data = $stmt->fetch(PDO::FETCH_ASSOC)) {
            $messages[] = self::fromArray($data);
        }
        
        return $messages;
    }
    
    // Getters
    public function getId(): ?int { return $this->id; }
    public function getTransactionId(): ?int { return $this->transactionId; }
    public function getCommunityRequestId(): ?int { return $this->communityRequestId; }
    public function getUserId(): int { return $this->userId; }
    public function getMessage(): string { return $this->message; }
    public function getImagePath(): ?string { return $this->imagePath; }
    public function isRead(): bool { return $this->isRead; }
    public function getCreatedAt(): ?string { return $this->createdAt; }
    
    // Setters
    public function setMessage(string $message): void { $this->message = $message; }
    public function setImagePath(?string $imagePath): void { $this->imagePath = $imagePath; }
}


