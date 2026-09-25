<?php

namespace OGAS\Services;

use OGAS\Models\User;
use OGAS\Services\Auth;

/**
 * Сервис для работы с администраторскими функциями
 */
class AdminService
{
    /**
     * Проверить, является ли текущий пользователь администратором
     */
    public static function check(): bool
    {
        $user = Auth::user();
        return $user && $user->isAdmin();
    }
    
    /**
     * Требовать права администратора (редирект, если не админ)
     */
    public static function requireAdmin(): void
    {
        Auth::requireAuth();
        
        if (!self::check()) {
            header('Location: /dashboard.php?error=access_denied');
            exit;
        }
    }
    
    /**
     * Получить всех пользователей (для админ-панели)
     */
    public static function getAllUsers(int $limit = 100, int $offset = 0, ?string $search = null): array
    {
        $db = \OGAS\Database::getConnection();
        
        $sql = "SELECT * FROM users WHERE is_system = 0";
        $params = [];
        
        if ($search) {
            $sql .= " AND (full_name LIKE ? OR email LIKE ?)";
            $searchTerm = '%' . $search . '%';
            $params[] = $searchTerm;
            $params[] = $searchTerm;
        }
        
        $sql .= " ORDER BY created_at DESC LIMIT ? OFFSET ?";
        $params[] = $limit;
        $params[] = $offset;
        
        $stmt = $db->prepare($sql);
        $stmt->execute($params);
        
        $users = [];
        while ($data = $stmt->fetch(\PDO::FETCH_ASSOC)) {
            $users[] = User::fromArray($data);
        }
        
        return $users;
    }
    
    /**
     * Получить количество пользователей
     */
    public static function getUsersCount(?string $search = null): int
    {
        $db = \OGAS\Database::getConnection();
        
        $sql = "SELECT COUNT(*) FROM users WHERE is_system = 0";
        $params = [];
        
        if ($search) {
            $sql .= " AND (full_name LIKE ? OR email LIKE ?)";
            $searchTerm = '%' . $search . '%';
            $params[] = $searchTerm;
            $params[] = $searchTerm;
        }
        
        $stmt = $db->prepare($sql);
        $stmt->execute($params);
        
        return (int)$stmt->fetchColumn();
    }
    
    /**
     * Получить статистику системы
     */
    public static function getSystemStatistics(): array
    {
        $db = \OGAS\Database::getConnection();
        
        // Пользователи
        $stmt = $db->query("SELECT COUNT(*) FROM users WHERE is_system = 0");
        $totalUsers = (int)$stmt->fetchColumn();
        
        $stmt = $db->query("SELECT COUNT(*) FROM users WHERE is_active = 1 AND is_system = 0");
        $activeUsers = (int)$stmt->fetchColumn();
        
        $stmt = $db->query("SELECT COUNT(*) FROM users WHERE is_admin = 1");
        $adminUsers = (int)$stmt->fetchColumn();
        
        // Вексели
        $stmt = $db->query("SELECT COUNT(*) FROM bills");
        $totalBills = (int)$stmt->fetchColumn();
        
        $stmt = $db->query("SELECT COUNT(*) FROM bills WHERE status = 'active'");
        $activeBills = (int)$stmt->fetchColumn();
        
        $stmt = $db->query("SELECT COALESCE(SUM(nominal), 0) FROM bills WHERE status = 'active'");
        $activeBillsTotal = (float)$stmt->fetchColumn();
        
        // Транзакции
        $stmt = $db->query("SELECT COUNT(*) FROM transactions");
        $totalTransactions = (int)$stmt->fetchColumn();
        
        $stmt = $db->query("SELECT COUNT(*) FROM transactions WHERE status = 'active'");
        $activeTransactions = (int)$stmt->fetchColumn();
        
        $stmt = $db->query("SELECT COUNT(*) FROM transactions WHERE status = 'completed'");
        $completedTransactions = (int)$stmt->fetchColumn();
        
        // Подписки
        $stmt = $db->query("SELECT COUNT(*) FROM subscriptions");
        $totalSubscriptions = (int)$stmt->fetchColumn();
        
        $stmt = $db->query("SELECT COUNT(*) FROM subscriptions WHERE status = 'active'");
        $activeSubscriptions = (int)$stmt->fetchColumn();
        
        // Категории
        $stmt = $db->query("SELECT COUNT(*) FROM categories WHERE is_active = 1");
        $activeCategories = (int)$stmt->fetchColumn();
        
        // Уведомления
        $stmt = $db->query("SELECT COUNT(*) FROM notifications");
        $totalNotifications = (int)$stmt->fetchColumn();
        
        $stmt = $db->query("SELECT COUNT(*) FROM notifications WHERE is_read = 0");
        $unreadNotifications = (int)$stmt->fetchColumn();
        
        return [
            'users' => [
                'total' => $totalUsers,
                'active' => $activeUsers,
                'admins' => $adminUsers
            ],
            'bills' => [
                'total' => $totalBills,
                'active' => $activeBills,
                'active_total' => $activeBillsTotal
            ],
            'transactions' => [
                'total' => $totalTransactions,
                'active' => $activeTransactions,
                'completed' => $completedTransactions
            ],
            'subscriptions' => [
                'total' => $totalSubscriptions,
                'active' => $activeSubscriptions
            ],
            'categories' => [
                'active' => $activeCategories
            ],
            'notifications' => [
                'total' => $totalNotifications,
                'unread' => $unreadNotifications
            ]
        ];
    }
}








