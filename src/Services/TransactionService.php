<?php

namespace OGAS\Services;

use OGAS\Models\Transaction;
use OGAS\Models\Bill;
use OGAS\Models\Rating;
use OGAS\Models\User;
use OGAS\Services\NotificationService;

/**
 * Сервис для работы с транзакциями
 */
class TransactionService
{
    /**
     * Создать транзакцию (статус всегда pending, вексель создаётся после подтверждения обоими участниками)
     */
    public static function create(array $data): Transaction
    {
        $seller = User::findById($data['seller_id']);
        $buyer = User::findById($data['buyer_id']);
        
        // Получаем системного пользователя для проверки транзакций активации
        $systemUser = User::getOrCreateSystemUser();
        $isActivationTransaction = ($seller->getId() === $systemUser->getId() || 
                                    $buyer->getId() === $systemUser->getId());
        
        // Проверяем, что продавец активен (если это не транзакция активации и не системный пользователь)
        if (!$isActivationTransaction && !$seller->isSystem() && !$seller->isActive()) {
            throw new \Exception('Продавец должен иметь активированный аккаунт для создания транзакций');
        }
        
        // Для транзакций активации разрешаем создание даже если пользователь неактивен
        // Для обычных транзакций проверяем, что оба участника активны (кроме системного пользователя)
        if (!$isActivationTransaction && !$buyer->isSystem() && !$buyer->isActive()) {
            throw new \Exception('Покупатель должен иметь активированный аккаунт для создания транзакций');
        }
        
        // Создаём транзакцию (всегда в статусе pending)
        $transaction = Transaction::create([
            'seller_id' => $data['seller_id'],
            'buyer_id' => $data['buyer_id'],
            'description' => $data['description'] ?? null,
            'category' => $data['category'] ?? null,
            'transaction_type' => $data['transaction_type'] ?? 'barter'
        ]);
        
        // Транзакция создаётся всегда в статусе 'pending' (на рассмотрении)
        // Вексель создаётся только после подтверждения обоими участниками через чат
        
        // Создаем уведомления о новой транзакции (но не для системного пользователя)
        if (!$seller->isSystem()) {
            NotificationService::notifyTransactionCreated($data['seller_id'], $transaction->getId());
        }
        if ($data['seller_id'] !== $data['buyer_id'] && !$buyer->isSystem()) {
            NotificationService::notifyTransactionCreated($data['buyer_id'], $transaction->getId());
        }
        
        return $transaction;
    }
    
    /**
     * Завершить транзакцию
     * @param int $transactionId ID транзакции
     * @param int $userId ID пользователя, который пытается завершить (должен быть продавцом)
     * @return bool
     */
    public static function complete(int $transactionId, int $userId): bool
    {
        $transaction = Transaction::findById($transactionId);
        
        if (!$transaction || $transaction->getSellerId() !== $userId || $transaction->getStatus() !== 'pending') {
            return false; // Только продавец может завершить pending транзакцию
        }
        
        if ($transaction->setStatus('completed')) {
            // Создаем уведомления о завершении транзакции
            NotificationService::notifyTransactionCompleted($transaction->getSellerId(), $transactionId);
            NotificationService::notifyTransactionCompleted($transaction->getBuyerId(), $transactionId);
            return true;
        }
        
        return false;
    }
    
    /**
     * Отменить транзакцию
     * @param int $transactionId ID транзакции
     * @param int $userId ID пользователя, который пытается отменить (может быть продавцом или покупателем)
     * @return bool
     */
    public static function cancel(int $transactionId, int $userId): bool
    {
        $transaction = Transaction::findById($transactionId);
        
        if (!$transaction || ($transaction->getSellerId() !== $userId && $transaction->getBuyerId() !== $userId)) {
            return false; // Только участники транзакции могут отменить
        }
        
        // Если транзакция уже завершена, отменить нельзя
        if ($transaction->getStatus() === 'completed') {
            return false;
        }
        
        if ($transaction->setStatus('cancelled')) {
            // Создаем уведомления об отмене транзакции
            $sellerId = $transaction->getSellerId();
            $buyerId = $transaction->getBuyerId();
            
            NotificationService::notifyTransactionCancelled($sellerId, $transactionId);
            if ($sellerId !== $buyerId) {
                NotificationService::notifyTransactionCancelled($buyerId, $transactionId);
            }
            
            return true;
        }
        
        return false;
    }
    
    /**
     * Получить статистику по транзакциям пользователя
     */
    public static function getStatistics(int $userId): array
    {
        $allTransactions = Transaction::findByUser($userId);
        $asSeller = Transaction::findBySeller($userId);
        $asBuyer = Transaction::findByBuyer($userId);
        
        $activeTransactions = array_filter($allTransactions, fn($t) => $t->getStatus() === 'active');
        $completedTransactions = array_filter($allTransactions, fn($t) => $t->getStatus() === 'completed');
        $pendingTransactions = array_filter($allTransactions, fn($t) => $t->getStatus() === 'pending');
        
        return [
            'total' => count($allTransactions),
            'as_seller' => count($asSeller),
            'as_buyer' => count($asBuyer),
            'active' => count($activeTransactions),
            'completed' => count($completedTransactions),
            'pending' => count($pendingTransactions)
        ];
    }
    
    /**
     * Получить вексели, связанные с транзакцией (по продавцу и покупателю)
     */
    public static function getBillsForTransaction(int $transactionId): array
    {
        $transaction = Transaction::findById($transactionId);
        
        if (!$transaction) {
            return [];
        }
        
        // Ищем вексели между продавцом и покупателем в период транзакции
        $db = \OGAS\Database::getConnection();
        $stmt = $db->prepare("
            SELECT * FROM bills 
            WHERE issuer_id = ? AND holder_id = ?
            AND issue_date >= ?
            ORDER BY issue_date DESC
        ");
        
        $transactionDate = $transaction->getCreatedAt() ?? date('Y-m-d H:i:s');
        $stmt->execute([
            $transaction->getSellerId(),
            $transaction->getBuyerId(),
            $transactionDate
        ]);
        
        $bills = [];
        while ($data = $stmt->fetch(\PDO::FETCH_ASSOC)) {
            $bills[] = Bill::fromArray($data);
        }
        
        return $bills;
    }
}

