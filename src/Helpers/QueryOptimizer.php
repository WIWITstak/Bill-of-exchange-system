<?php

namespace OGAS\Helpers;

use OGAS\Models\User;
use OGAS\Models\Transaction;
use OGAS\Models\Bill;

/**
 * Класс для оптимизации запросов и решения проблемы N+1
 */
class QueryOptimizer
{
    /**
     * Загрузить пользователей для списка транзакций (решение N+1)
     * 
     * @param array $transactions Массив объектов Transaction
     * @return array Ассоциативный массив [transaction_id => ['seller' => User, 'buyer' => User]]
     */
    public static function loadUsersForTransactions(array $transactions): array
    {
        if (empty($transactions)) {
            return [];
        }
        
        // Собираем все уникальные ID пользователей
        $userIds = [];
        foreach ($transactions as $transaction) {
            $userIds[] = $transaction->getSellerId();
            $userIds[] = $transaction->getBuyerId();
        }
        
        $userIds = array_unique($userIds);
        
        // Загружаем всех пользователей одним запросом
        $users = User::findByIds($userIds);
        
        // Формируем результат
        $result = [];
        foreach ($transactions as $transaction) {
            $transactionId = $transaction->getId();
            $result[$transactionId] = [
                'seller' => $users[$transaction->getSellerId()] ?? null,
                'buyer' => $users[$transaction->getBuyerId()] ?? null
            ];
        }
        
        return $result;
    }
    
    /**
     * Загрузить пользователей для списка векселей (решение N+1)
     * 
     * @param array $bills Массив объектов Bill
     * @return array Ассоциативный массив [bill_id => ['issuer' => User, 'holder' => User]]
     */
    public static function loadUsersForBills(array $bills): array
    {
        if (empty($bills)) {
            return [];
        }
        
        // Собираем все уникальные ID пользователей
        $userIds = [];
        foreach ($bills as $bill) {
            $userIds[] = $bill->getIssuerId();
            $userIds[] = $bill->getHolderId();
        }
        
        $userIds = array_unique($userIds);
        
        // Загружаем всех пользователей одним запросом
        $users = User::findByIds($userIds);
        
        // Формируем результат
        $result = [];
        foreach ($bills as $bill) {
            $billId = $bill->getId();
            $result[$billId] = [
                'issuer' => $users[$bill->getIssuerId()] ?? null,
                'holder' => $users[$bill->getHolderId()] ?? null
            ];
        }
        
        return $result;
    }
    
    /**
     * Загрузить транзакции с пользователями через JOIN (альтернативный метод)
     * 
     * @param int $userId ID пользователя
     * @param string|null $status Фильтр по статусу
     * @return array Массив с транзакциями и пользователями
     */
    public static function getTransactionsWithUsers(int $userId, ?string $status = null): array
    {
        $db = \OGAS\Database::getConnection();
        
        $sql = "
            SELECT 
                t.*,
                seller.id as seller_id_data,
                seller.email as seller_email,
                seller.full_name as seller_name,
                seller.user_type as seller_type,
                seller.is_active as seller_active,
                seller.avatar_path as seller_avatar,
                buyer.id as buyer_id_data,
                buyer.email as buyer_email,
                buyer.full_name as buyer_name,
                buyer.user_type as buyer_type,
                buyer.is_active as buyer_active,
                buyer.avatar_path as buyer_avatar
            FROM transactions t
            INNER JOIN users seller ON t.seller_id = seller.id
            INNER JOIN users buyer ON t.buyer_id = buyer.id
            WHERE (t.seller_id = ? OR t.buyer_id = ?)
        ";
        
        $params = [$userId, $userId];
        
        if ($status) {
            $sql .= " AND t.status = ?";
            $params[] = $status;
        }
        
        $sql .= " ORDER BY t.created_at DESC";
        
        $stmt = $db->prepare($sql);
        $stmt->execute($params);
        
        $result = [];
        while ($row = $stmt->fetch(\PDO::FETCH_ASSOC)) {
            // Создаем транзакцию
            $transactionData = array_filter($row, function($key) {
                return !in_array($key, [
                    'seller_id_data', 'seller_email', 'seller_name', 'seller_type', 'seller_active', 'seller_avatar',
                    'buyer_id_data', 'buyer_email', 'buyer_name', 'buyer_type', 'buyer_active', 'buyer_avatar'
                ]);
            }, ARRAY_FILTER_USE_KEY);
            
            $transaction = Transaction::fromArray($transactionData);
            
            // Создаем пользователей
            $seller = User::fromArray([
                'id' => $row['seller_id_data'],
                'email' => $row['seller_email'],
                'full_name' => $row['seller_name'],
                'user_type' => $row['seller_type'],
                'is_active' => $row['seller_active'],
                'avatar_path' => $row['seller_avatar'],
                'password_hash' => '', // Не нужен для отображения
                'created_at' => null,
                'updated_at' => null
            ]);
            
            $buyer = User::fromArray([
                'id' => $row['buyer_id_data'],
                'email' => $row['buyer_email'],
                'full_name' => $row['buyer_name'],
                'user_type' => $row['buyer_type'],
                'is_active' => $row['buyer_active'],
                'avatar_path' => $row['buyer_avatar'],
                'password_hash' => '', // Не нужен для отображения
                'created_at' => null,
                'updated_at' => null
            ]);
            
            $result[] = [
                'transaction' => $transaction,
                'seller' => $seller,
                'buyer' => $buyer
            ];
        }
        
        return $result;
    }
    
    /**
     * Загрузить вексели с пользователями через JOIN
     * 
     * @param int $userId ID пользователя
     * @param string|null $status Фильтр по статусу
     * @param string $role 'issuer', 'holder' или 'both'
     * @return array Массив с векселями и пользователями
     */
    public static function getBillsWithUsers(int $userId, ?string $status = null, string $role = 'both'): array
    {
        $db = \OGAS\Database::getConnection();
        
        $sql = "
            SELECT 
                b.*,
                issuer.id as issuer_id_data,
                issuer.email as issuer_email,
                issuer.full_name as issuer_name,
                issuer.user_type as issuer_type,
                issuer.is_active as issuer_active,
                issuer.avatar_path as issuer_avatar,
                holder.id as holder_id_data,
                holder.email as holder_email,
                holder.full_name as holder_name,
                holder.user_type as holder_type,
                holder.is_active as holder_active,
                holder.avatar_path as holder_avatar
            FROM bills b
            INNER JOIN users issuer ON b.issuer_id = issuer.id
            INNER JOIN users holder ON b.holder_id = holder.id
            WHERE 
        ";
        
        $params = [];
        
        if ($role === 'issuer') {
            $sql .= " b.issuer_id = ?";
            $params[] = $userId;
        } elseif ($role === 'holder') {
            $sql .= " b.holder_id = ?";
            $params[] = $userId;
        } else {
            $sql .= " (b.issuer_id = ? OR b.holder_id = ?)";
            $params[] = $userId;
            $params[] = $userId;
        }
        
        if ($status) {
            $sql .= " AND b.status = ?";
            $params[] = $status;
        }
        
        $sql .= " ORDER BY b.issue_date DESC";
        
        $stmt = $db->prepare($sql);
        $stmt->execute($params);
        
        $result = [];
        while ($row = $stmt->fetch(\PDO::FETCH_ASSOC)) {
            // Создаем вексель
            $billData = array_filter($row, function($key) {
                return !in_array($key, [
                    'issuer_id_data', 'issuer_email', 'issuer_name', 'issuer_type', 'issuer_active', 'issuer_avatar',
                    'holder_id_data', 'holder_email', 'holder_name', 'holder_type', 'holder_active', 'holder_avatar'
                ]);
            }, ARRAY_FILTER_USE_KEY);
            
            $bill = Bill::fromArray($billData);
            
            // Создаем пользователей
            $issuer = User::fromArray([
                'id' => $row['issuer_id_data'],
                'email' => $row['issuer_email'],
                'full_name' => $row['issuer_name'],
                'user_type' => $row['issuer_type'],
                'is_active' => $row['issuer_active'],
                'avatar_path' => $row['issuer_avatar'],
                'password_hash' => '',
                'created_at' => null,
                'updated_at' => null
            ]);
            
            $holder = User::fromArray([
                'id' => $row['holder_id_data'],
                'email' => $row['holder_email'],
                'full_name' => $row['holder_name'],
                'user_type' => $row['holder_type'],
                'is_active' => $row['holder_active'],
                'avatar_path' => $row['holder_avatar'],
                'password_hash' => '',
                'created_at' => null,
                'updated_at' => null
            ]);
            
            $result[] = [
                'bill' => $bill,
                'issuer' => $issuer,
                'holder' => $holder
            ];
        }
        
        return $result;
    }
    
    /**
     * Подсчитать количество непрочитанных сообщений для нескольких транзакций
     * 
     * @param array $transactionIds Массив ID транзакций
     * @param int $userId ID пользователя
     * @return array Ассоциативный массив [transaction_id => count]
     */
    public static function getUnreadCountsForTransactions(array $transactionIds, int $userId): array
    {
        if (empty($transactionIds)) {
            return [];
        }
        
        $db = \OGAS\Database::getConnection();
        
        $placeholders = implode(',', array_fill(0, count($transactionIds), '?'));
        $params = $transactionIds;
        $params[] = $userId;
        
        $sql = "
            SELECT transaction_id, COUNT(*) as unread_count
            FROM messages
            WHERE transaction_id IN ({$placeholders})
            AND user_id != ?
            AND is_read = 0
            GROUP BY transaction_id
        ";
        
        $stmt = $db->prepare($sql);
        $stmt->execute($params);
        
        $result = [];
        while ($row = $stmt->fetch(\PDO::FETCH_ASSOC)) {
            $result[(int)$row['transaction_id']] = (int)$row['unread_count'];
        }
        
        return $result;
    }
}





