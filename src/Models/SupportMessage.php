<?php

namespace OGAS\Models;

use OGAS\Database;
use PDO;

/**
 * Модель для работы с сообщениями в обращениях поддержки
 */
class SupportMessage
{
    /**
     * Создать новое сообщение
     */
    public function create(int $ticketId, int $userId, string $message, bool $isAdmin = false): int
    {
        $db = Database::getConnection();
        $stmt = $db->prepare("
            INSERT INTO support_messages (ticket_id, user_id, message, is_admin)
            VALUES (?, ?, ?, ?)
        ");
        
        $stmt->execute([$ticketId, $userId, $message, $isAdmin ? 1 : 0]);
        
        // Обновляем время обновления обращения
        $updateStmt = $db->prepare("
            UPDATE support_tickets 
            SET updated_at = NOW() 
            WHERE id = ?
        ");
        $updateStmt->execute([$ticketId]);
        
        return $db->lastInsertId();
    }
    
    /**
     * Получить все сообщения обращения
     */
    public function findByTicketId(int $ticketId): array
    {
        $db = Database::getConnection();
        $stmt = $db->prepare("
            SELECT m.*, 
                   u.full_name as user_name,
                   u.email as user_email
            FROM support_messages m
            LEFT JOIN users u ON m.user_id = u.id
            WHERE m.ticket_id = ?
            ORDER BY m.created_at ASC
        ");
        
        $stmt->execute([$ticketId]);
        
        return $stmt->fetchAll(PDO::FETCH_ASSOC);
    }
    
    /**
     * Отметить сообщения как прочитанные
     */
    public function markAsRead(int $ticketId, int $userId): bool
    {
        $db = Database::getConnection();
        $stmt = $db->prepare("
            UPDATE support_messages 
            SET read_at = NOW() 
            WHERE ticket_id = ? 
            AND user_id != ? 
            AND read_at IS NULL
        ");
        
        return $stmt->execute([$ticketId, $userId]);
    }
    
    /**
     * Получить количество непрочитанных сообщений для пользователя
     */
    public function getUnreadCount(int $ticketId, int $userId): int
    {
        $db = Database::getConnection();
        $stmt = $db->prepare("
            SELECT COUNT(*) 
            FROM support_messages 
            WHERE ticket_id = ? 
            AND user_id != ? 
            AND read_at IS NULL
        ");
        
        $stmt->execute([$ticketId, $userId]);
        
        return (int)$stmt->fetchColumn();
    }
}

