<?php

namespace OGAS\Models;

use OGAS\Database;
use PDO;

/**
 * Модель активной сессии пользователя
 */
class UserSession
{
    private ?int $id = null;
    private int $userId;
    private string $sessionId;
    private string $ipAddress;
    private ?string $userAgent;
    private ?string $deviceInfo;
    private string $lastActivity;
    private string $createdAt;
    private bool $isCurrent;
    
    /**
     * Создать из массива
     */
    public static function fromArray(array $data): self
    {
        $session = new self();
        $session->id = (int)$data['id'];
        $session->userId = (int)$data['user_id'];
        $session->sessionId = $data['session_id'];
        $session->ipAddress = $data['ip_address'];
        $session->userAgent = $data['user_agent'];
        $session->deviceInfo = $data['device_info'];
        $session->lastActivity = $data['last_activity'];
        $session->createdAt = $data['created_at'];
        $session->isCurrent = (bool)$data['is_current'];
        return $session;
    }
    
    /**
     * Создать новую сессию
     */
    public static function create(int $userId, string $sessionId, string $ipAddress, ?string $userAgent = null, ?string $deviceInfo = null): self
    {
        $db = Database::getConnection();
        
        // Помечаем все предыдущие сессии как не текущие
        $stmt = $db->prepare("UPDATE user_sessions SET is_current = 0 WHERE user_id = ?");
        $stmt->execute([$userId]);
        
        // Создаем новую сессию
        $stmt = $db->prepare("
            INSERT INTO user_sessions (user_id, session_id, ip_address, user_agent, device_info, last_activity, is_current)
            VALUES (?, ?, ?, ?, ?, NOW(), 1)
        ");
        
        $stmt->execute([
            $userId,
            $sessionId,
            $ipAddress,
            $userAgent,
            $deviceInfo
        ]);
        
        $sessionId_db = (int)$db->lastInsertId();
        
        // Получаем созданную сессию
        $stmt = $db->prepare("SELECT * FROM user_sessions WHERE id = ?");
        $stmt->execute([$sessionId_db]);
        $data = $stmt->fetch(PDO::FETCH_ASSOC);
        
        return self::fromArray($data);
    }
    
    /**
     * Найти сессию по session_id
     */
    public static function findBySessionId(string $sessionId): ?self
    {
        $db = Database::getConnection();
        $stmt = $db->prepare("SELECT * FROM user_sessions WHERE session_id = ? AND is_current = 1");
        $stmt->execute([$sessionId]);
        $data = $stmt->fetch(PDO::FETCH_ASSOC);
        
        if (!$data) {
            return null;
        }
        
        return self::fromArray($data);
    }
    
    /**
     * Найти все активные сессии пользователя
     */
    public static function findByUserId(int $userId, bool $onlyCurrent = true): array
    {
        $db = Database::getConnection();
        
        if ($onlyCurrent) {
            $stmt = $db->prepare("SELECT * FROM user_sessions WHERE user_id = ? AND is_current = 1 ORDER BY last_activity DESC");
        } else {
            $stmt = $db->prepare("SELECT * FROM user_sessions WHERE user_id = ? ORDER BY last_activity DESC");
        }
        
        $stmt->execute([$userId]);
        $data = $stmt->fetchAll(PDO::FETCH_ASSOC);
        
        return array_map([self::class, 'fromArray'], $data);
    }
    
    /**
     * Обновить время последней активности
     */
    public function updateActivity(): bool
    {
        $db = Database::getConnection();
        $stmt = $db->prepare("UPDATE user_sessions SET last_activity = NOW() WHERE id = ?");
        return $stmt->execute([$this->id]);
    }
    
    /**
     * Удалить сессию
     */
    public function delete(): bool
    {
        $db = Database::getConnection();
        $stmt = $db->prepare("DELETE FROM user_sessions WHERE id = ?");
        return $stmt->execute([$this->id]);
    }
    
    /**
     * Пометить как не текущую
     */
    public function markAsInactive(): bool
    {
        $db = Database::getConnection();
        $stmt = $db->prepare("UPDATE user_sessions SET is_current = 0 WHERE id = ?");
        if ($stmt->execute([$this->id])) {
            $this->isCurrent = false;
            return true;
        }
        return false;
    }
    
    /**
     * Удалить устаревшие сессии (старше указанного времени неактивности)
     */
    public static function cleanupExpired(int $inactivityTimeout = 3600): int
    {
        $db = Database::getConnection();
        $stmt = $db->prepare("
            DELETE FROM user_sessions 
            WHERE last_activity < DATE_SUB(NOW(), INTERVAL ? SECOND)
        ");
        $stmt->execute([$inactivityTimeout]);
        return $stmt->rowCount();
    }
    
    /**
     * Получить количество активных сессий пользователя
     */
    public static function countActiveByUserId(int $userId): int
    {
        $db = Database::getConnection();
        $stmt = $db->prepare("SELECT COUNT(*) FROM user_sessions WHERE user_id = ? AND is_current = 1");
        $stmt->execute([$userId]);
        return (int)$stmt->fetchColumn();
    }
    
    /**
     * Удалить все сессии пользователя кроме текущей
     */
    public static function removeAllExceptCurrent(int $userId, string $currentSessionId): int
    {
        $db = Database::getConnection();
        $stmt = $db->prepare("DELETE FROM user_sessions WHERE user_id = ? AND session_id != ?");
        $stmt->execute([$userId, $currentSessionId]);
        return $stmt->rowCount();
    }
    
    // Getters
    public function getId(): ?int { return $this->id; }
    public function getUserId(): int { return $this->userId; }
    public function getSessionId(): string { return $this->sessionId; }
    public function getIpAddress(): string { return $this->ipAddress; }
    public function getUserAgent(): ?string { return $this->userAgent; }
    public function getDeviceInfo(): ?string { return $this->deviceInfo; }
    public function getLastActivity(): string { return $this->lastActivity; }
    public function getCreatedAt(): string { return $this->createdAt; }
    public function isCurrent(): bool { return $this->isCurrent; }
}








