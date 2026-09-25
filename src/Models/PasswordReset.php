<?php

namespace OGAS\Models;

use OGAS\Database;
use PDO;

/**
 * Модель токена сброса пароля
 */
class PasswordReset
{
    private ?int $id = null;
    private int $userId;
    private string $token;
    private string $expiresAt;
    private ?string $usedAt = null;
    private ?string $createdAt = null;
    
    /**
     * Найти токен по значению
     */
    public static function findByToken(string $token): ?self
    {
        $db = Database::getConnection();
        $stmt = $db->prepare("
            SELECT * FROM password_resets 
            WHERE token = ? AND expires_at > NOW() AND used_at IS NULL
        ");
        $stmt->execute([$token]);
        $data = $stmt->fetch(PDO::FETCH_ASSOC);
        
        if (!$data) {
            return null;
        }
        
        return self::fromArray($data);
    }
    
    /**
     * Найти активный токен пользователя
     */
    public static function findActiveByUserId(int $userId): ?self
    {
        $db = Database::getConnection();
        $stmt = $db->prepare("
            SELECT * FROM password_resets 
            WHERE user_id = ? AND expires_at > NOW() AND used_at IS NULL
            ORDER BY created_at DESC
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
     * Создать из массива
     */
    public static function fromArray(array $data): self
    {
        $reset = new self();
        $reset->id = (int)$data['id'];
        $reset->userId = (int)$data['user_id'];
        $reset->token = $data['token'];
        $reset->expiresAt = $data['expires_at'];
        $reset->usedAt = $data['used_at'];
        $reset->createdAt = $data['created_at'];
        return $reset;
    }
    
    /**
     * Создать новый токен сброса пароля
     */
    public static function create(int $userId, string $token, int $hoursValid = 24): self
    {
        $db = Database::getConnection();
        
        // Инвалидируем все предыдущие токены пользователя
        $stmt = $db->prepare("
            UPDATE password_resets 
            SET used_at = NOW() 
            WHERE user_id = ? AND used_at IS NULL
        ");
        $stmt->execute([$userId]);
        
        // Создаём новый токен
        $expiresAt = date('Y-m-d H:i:s', strtotime("+{$hoursValid} hours"));
        
        $stmt = $db->prepare("
            INSERT INTO password_resets (user_id, token, expires_at)
            VALUES (?, ?, ?)
        ");
        
        $stmt->execute([$userId, $token, $expiresAt]);
        
        $resetId = (int)$db->lastInsertId();
        
        // Получаем созданный токен
        $stmt = $db->prepare("SELECT * FROM password_resets WHERE id = ?");
        $stmt->execute([$resetId]);
        $data = $stmt->fetch(PDO::FETCH_ASSOC);
        
        return self::fromArray($data);
    }
    
    /**
     * Пометить токен как использованный
     */
    public function markAsUsed(): bool
    {
        if ($this->usedAt !== null) {
            return false; // Уже использован
        }
        
        $db = Database::getConnection();
        $stmt = $db->prepare("
            UPDATE password_resets 
            SET used_at = NOW() 
            WHERE id = ?
        ");
        
        if ($stmt->execute([$this->id])) {
            $this->usedAt = date('Y-m-d H:i:s');
            return true;
        }
        
        return false;
    }
    
    /**
     * Проверить, действителен ли токен
     */
    public function isValid(): bool
    {
        if ($this->usedAt !== null) {
            return false; // Уже использован
        }
        
        return strtotime($this->expiresAt) > time();
    }
    
    /**
     * Очистить устаревшие токены
     */
    public static function cleanupExpired(): int
    {
        $db = Database::getConnection();
        $stmt = $db->prepare("
            DELETE FROM password_resets 
            WHERE expires_at < NOW() OR used_at IS NOT NULL
        ");
        $stmt->execute();
        
        return $stmt->rowCount();
    }
    
    // Getters
    public function getId(): ?int { return $this->id; }
    public function getUserId(): int { return $this->userId; }
    public function getToken(): string { return $this->token; }
    public function getExpiresAt(): string { return $this->expiresAt; }
    public function getUsedAt(): ?string { return $this->usedAt; }
    public function getCreatedAt(): ?string { return $this->createdAt; }
}








