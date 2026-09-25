<?php

namespace OGAS\Models;

use OGAS\Database;
use PDO;

/**
 * Модель для работы с обращениями в поддержку
 */
class Ticket
{
    /**
     * Создать новое обращение
     */
    public function create(int $userId, string $subject, string $priority = 'medium', ?int $relatedTransactionId = null, ?int $relatedBillId = null, string $ticketType = 'general'): int
    {
        $db = Database::getConnection();
        $stmt = $db->prepare("
            INSERT INTO support_tickets (user_id, subject, priority, status, related_transaction_id, related_bill_id, ticket_type)
            VALUES (?, ?, ?, 'open', ?, ?, ?)
        ");
        
        $stmt->execute([$userId, $subject, $priority, $relatedTransactionId, $relatedBillId, $ticketType]);
        
        return $db->lastInsertId();
    }
    
    /**
     * Получить обращение по ID
     */
    public function findById(int $ticketId): ?array
    {
        $db = Database::getConnection();
        $stmt = $db->prepare("
            SELECT t.*, 
                   u.full_name as user_name,
                   u.email as user_email,
                   a.full_name as assigned_name,
                   tr.id as transaction_id,
                   tr.status as transaction_status,
                   b.id as bill_id,
                   b.status as bill_status
            FROM support_tickets t
            LEFT JOIN users u ON t.user_id = u.id
            LEFT JOIN users a ON t.assigned_to = a.id
            LEFT JOIN transactions tr ON t.related_transaction_id = tr.id
            LEFT JOIN bills b ON t.related_bill_id = b.id
            WHERE t.id = ?
        ");
        
        $stmt->execute([$ticketId]);
        $ticket = $stmt->fetch(PDO::FETCH_ASSOC);
        
        return $ticket ?: null;
    }
    
    /**
     * Получить все обращения пользователя
     */
    public function findByUserId(int $userId, ?string $status = null): array
    {
        $db = Database::getConnection();
        $sql = "
            SELECT t.*, 
                   u.full_name as user_name,
                   u.email as user_email,
                   (SELECT COUNT(*) FROM support_messages WHERE ticket_id = t.id) as message_count,
                   (SELECT MAX(created_at) FROM support_messages WHERE ticket_id = t.id) as last_message_at
            FROM support_tickets t
            LEFT JOIN users u ON t.user_id = u.id
            WHERE t.user_id = ?
        ";
        
        $params = [$userId];
        
        if ($status) {
            $sql .= " AND t.status = ?";
            $params[] = $status;
        }
        
        $sql .= " ORDER BY t.created_at DESC";
        
        $stmt = $db->prepare($sql);
        $stmt->execute($params);
        
        return $stmt->fetchAll(PDO::FETCH_ASSOC);
    }
    
    /**
     * Получить все обращения (для администраторов)
     */
    public function findAll(?string $status = null, ?string $priority = null, ?int $assignedTo = null): array
    {
        $db = Database::getConnection();
        $sql = "
            SELECT t.*, 
                   u.full_name as user_name,
                   u.email as user_email,
                   a.full_name as assigned_name,
                   (SELECT COUNT(*) FROM support_messages WHERE ticket_id = t.id) as message_count,
                   (SELECT MAX(created_at) FROM support_messages WHERE ticket_id = t.id) as last_message_at
            FROM support_tickets t
            LEFT JOIN users u ON t.user_id = u.id
            LEFT JOIN users a ON t.assigned_to = a.id
            WHERE 1=1
        ";
        
        $params = [];
        
        if ($status) {
            $sql .= " AND t.status = ?";
            $params[] = $status;
        }
        
        if ($priority) {
            $sql .= " AND t.priority = ?";
            $params[] = $priority;
        }
        
        if ($assignedTo !== null) {
            $sql .= " AND t.assigned_to = ?";
            $params[] = $assignedTo;
        }
        
        $sql .= " ORDER BY 
            CASE t.priority
                WHEN 'urgent' THEN 1
                WHEN 'high' THEN 2
                WHEN 'medium' THEN 3
                WHEN 'low' THEN 4
            END,
            t.created_at DESC";
        
        $stmt = $db->prepare($sql);
        $stmt->execute($params);
        
        return $stmt->fetchAll(PDO::FETCH_ASSOC);
    }
    
    /**
     * Обновить статус обращения
     */
    public function updateStatus(int $ticketId, string $status, ?int $assignedTo = null): bool
    {
        $db = Database::getConnection();
        $sql = "UPDATE support_tickets SET status = ?, updated_at = NOW()";
        $params = [$status];
        
        if ($status === 'resolved' || $status === 'closed') {
            $sql .= ", resolved_at = NOW()";
        } else {
            $sql .= ", resolved_at = NULL";
        }
        
        if ($assignedTo !== null) {
            $sql .= ", assigned_to = ?";
            $params[] = $assignedTo;
        }
        
        $sql .= " WHERE id = ?";
        $params[] = $ticketId;
        
        $stmt = $db->prepare($sql);
        return $stmt->execute($params);
    }
    
    /**
     * Назначить обращение администратору (или снять назначение, если $adminId = null)
     */
    public function assignTo(int $ticketId, ?int $adminId): bool
    {
        $db = Database::getConnection();
        
        // Если назначаем администратора и статус "open", меняем на "in_progress"
        // Если снимаем назначение, статус не меняем
        if ($adminId !== null) {
            $stmt = $db->prepare("
                UPDATE support_tickets 
                SET assigned_to = ?, 
                    status = CASE WHEN status = 'open' THEN 'in_progress' ELSE status END,
                    updated_at = NOW()
                WHERE id = ?
            ");
            return $stmt->execute([$adminId, $ticketId]);
        } else {
            // Снимаем назначение
            $stmt = $db->prepare("
                UPDATE support_tickets 
                SET assigned_to = NULL, updated_at = NOW()
                WHERE id = ?
            ");
            return $stmt->execute([$ticketId]);
        }
    }
    
    /**
     * Получить статистику обращений
     */
    public function getStats(?int $userId = null): array
    {
        $db = Database::getConnection();
        $sql = "
            SELECT 
                COUNT(*) as total,
                SUM(CASE WHEN status = 'open' THEN 1 ELSE 0 END) as open,
                SUM(CASE WHEN status = 'in_progress' THEN 1 ELSE 0 END) as in_progress,
                SUM(CASE WHEN status = 'resolved' THEN 1 ELSE 0 END) as resolved,
                SUM(CASE WHEN status = 'closed' THEN 1 ELSE 0 END) as closed
            FROM support_tickets
        ";
        
        $params = [];
        if ($userId) {
            $sql .= " WHERE user_id = ?";
            $params[] = $userId;
        }
        
        $stmt = $db->prepare($sql);
        $stmt->execute($params);
        
        $result = $stmt->fetch(PDO::FETCH_ASSOC);
        return $result ?: [
            'total' => 0,
            'open' => 0,
            'in_progress' => 0,
            'resolved' => 0,
            'closed' => 0
        ];
    }
}

