<?php

namespace OGAS\Models;

use OGAS\Database;
use PDO;

/**
 * Модель транзакции/сделки
 */
class Transaction
{
    private ?int $id = null;
    private int $sellerId;
    private int $buyerId;
    private ?string $description = null;
    private ?string $category = null;
    private string $transactionType; // barter, guarantee, community, mixed
    private string $status; // pending, active, completed, cancelled
    private bool $sellerConfirmed = false;
    private bool $buyerConfirmed = false;
    private ?string $createdAt = null;
    private ?string $updatedAt = null;
    private ?int $communityRequestId = null; // ID заявки общины
    
    /**
     * Найти транзакцию по ID
     */
    public static function findById(int $id): ?self
    {
        $db = Database::getConnection();
        $stmt = $db->prepare("SELECT * FROM transactions WHERE id = ?");
        $stmt->execute([$id]);
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
        $transaction = new self();
        $transaction->id = (int)$data['id'];
        $transaction->sellerId = (int)$data['seller_id'];
        $transaction->buyerId = (int)$data['buyer_id'];
        $transaction->description = $data['description'];
        $transaction->category = $data['category'];
        $transaction->transactionType = $data['transaction_type'];
        $transaction->status = $data['status'];
        $transaction->sellerConfirmed = (bool)($data['seller_confirmed'] ?? false);
        $transaction->buyerConfirmed = (bool)($data['buyer_confirmed'] ?? false);
        $transaction->createdAt = $data['created_at'] ?? null;
        $transaction->updatedAt = $data['updated_at'] ?? null;
        $transaction->communityRequestId = isset($data['community_request_id']) && $data['community_request_id'] ? (int)$data['community_request_id'] : null;
        return $transaction;
    }
    
    /**
     * Создать новую транзакцию
     */
    public static function create(array $data): self
    {
        $db = Database::getConnection();
        
        $stmt = $db->prepare("
            INSERT INTO transactions (seller_id, buyer_id, description, category, transaction_type, status, seller_confirmed, buyer_confirmed, community_request_id)
            VALUES (?, ?, ?, ?, ?, 'pending', 0, 0, ?)
        ");
        
        $stmt->execute([
            $data['seller_id'],
            $data['buyer_id'],
            $data['description'] ?? null,
            $data['category'] ?? null,
            $data['transaction_type'] ?? 'barter',
            $data['community_request_id'] ?? null
        ]);
        
        $transactionId = (int)$db->lastInsertId();
        return self::findById($transactionId);
    }
    
    /**
     * Получить все транзакции пользователя
     */
    public static function findByUser(int $userId, ?string $status = null): array
    {
        $db = Database::getConnection();
        $sql = "
            SELECT * FROM transactions 
            WHERE seller_id = ? OR buyer_id = ?
        ";
        $params = [$userId, $userId];
        
        if ($status) {
            $sql .= " AND status = ?";
            $params[] = $status;
        }
        
        $sql .= " ORDER BY created_at DESC";
        
        $stmt = $db->prepare($sql);
        $stmt->execute($params);
        
        $transactions = [];
        while ($data = $stmt->fetch(PDO::FETCH_ASSOC)) {
            $transactions[] = self::fromArray($data);
        }
        
        return $transactions;
    }
    
    /**
     * Получить транзакции, где пользователь продавец
     */
    public static function findBySeller(int $sellerId, ?string $status = null): array
    {
        $db = Database::getConnection();
        $sql = "SELECT * FROM transactions WHERE seller_id = ?";
        $params = [$sellerId];
        
        if ($status) {
            $sql .= " AND status = ?";
            $params[] = $status;
        }
        
        $sql .= " ORDER BY created_at DESC";
        
        $stmt = $db->prepare($sql);
        $stmt->execute($params);
        
        $transactions = [];
        while ($data = $stmt->fetch(PDO::FETCH_ASSOC)) {
            $transactions[] = self::fromArray($data);
        }
        
        return $transactions;
    }
    
    /**
     * Получить транзакции, где пользователь покупатель
     */
    public static function findByBuyer(int $buyerId, ?string $status = null): array
    {
        $db = Database::getConnection();
        $sql = "SELECT * FROM transactions WHERE buyer_id = ?";
        $params = [$buyerId];
        
        if ($status) {
            $sql .= " AND status = ?";
            $params[] = $status;
        }
        
        $sql .= " ORDER BY created_at DESC";
        
        $stmt = $db->prepare($sql);
        $stmt->execute($params);
        
        $transactions = [];
        while ($data = $stmt->fetch(PDO::FETCH_ASSOC)) {
            $transactions[] = self::fromArray($data);
        }
        
        return $transactions;
    }
    
    /**
     * Получить все транзакции (для админ-панели)
     */
    public static function findAll(?string $status = null, int $limit = 100, int $offset = 0): array
    {
        $db = Database::getConnection();
        $sql = "SELECT * FROM transactions";
        $params = [];
        
        if ($status) {
            $sql .= " WHERE status = ?";
            $params[] = $status;
        }
        
        $sql .= " ORDER BY created_at DESC LIMIT ? OFFSET ?";
        $params[] = $limit;
        $params[] = $offset;
        
        $stmt = $db->prepare($sql);
        $stmt->execute($params);
        
        $transactions = [];
        while ($data = $stmt->fetch(PDO::FETCH_ASSOC)) {
            $transactions[] = self::fromArray($data);
        }
        
        return $transactions;
    }
    
    /**
     * Обновить статус транзакции
     */
    public function setStatus(string $status): bool
    {
        if (!in_array($status, ['pending', 'active', 'completed', 'cancelled'])) {
            return false;
        }
        
        $this->status = $status;
        $db = Database::getConnection();
        $stmt = $db->prepare("UPDATE transactions SET status = ? WHERE id = ?");
        return $stmt->execute([$status, $this->id]);
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
            UPDATE transactions 
            SET description = ?, category = ?, status = ?, seller_confirmed = ?, buyer_confirmed = ?
            WHERE id = ?
        ");
        
        return $stmt->execute([
            $this->description,
            $this->category,
            $this->status,
            $this->sellerConfirmed ? 1 : 0,
            $this->buyerConfirmed ? 1 : 0,
            $this->id
        ]);
    }
    
    // Getters
    public function getId(): ?int { return $this->id; }
    public function getSellerId(): int { return $this->sellerId; }
    public function getBuyerId(): int { return $this->buyerId; }
    public function getDescription(): ?string { return $this->description; }
    public function getCategory(): ?string { return $this->category; }
    public function getTransactionType(): string { return $this->transactionType; }
    public function getStatus(): string { return $this->status; }
    public function isSellerConfirmed(): bool { return $this->sellerConfirmed; }
    public function isBuyerConfirmed(): bool { return $this->buyerConfirmed; }
    public function isBothConfirmed(): bool { return $this->sellerConfirmed && $this->buyerConfirmed; }
    public function getCreatedAt(): ?string { return $this->createdAt; }
    public function getUpdatedAt(): ?string { return $this->updatedAt; }
    public function getCommunityRequestId(): ?int { return $this->communityRequestId; }
    
    /**
     * Найти транзакции связанные с общиной
     */
    public static function findByCommunityRequest(int $requestId): array
    {
        $db = Database::getConnection();
        $stmt = $db->prepare("SELECT * FROM transactions WHERE community_request_id = ? ORDER BY created_at DESC");
        $stmt->execute([$requestId]);
        
        $transactions = [];
        while ($data = $stmt->fetch(PDO::FETCH_ASSOC)) {
            $transactions[] = self::fromArray($data);
        }
        
        return $transactions;
    }
    
    /**
     * Найти транзакции пользователя связанные с общиной
     */
    public static function findByUserAndCommunity(int $userId, bool $communityOnly = false): array
    {
        $db = Database::getConnection();
        $sql = "SELECT * FROM transactions WHERE (seller_id = ? OR buyer_id = ?)";
        $params = [$userId, $userId];
        
        if ($communityOnly) {
            $sql .= " AND community_request_id IS NOT NULL";
        }
        
        $sql .= " ORDER BY created_at DESC";
        
        $stmt = $db->prepare($sql);
        $stmt->execute($params);
        
        $transactions = [];
        while ($data = $stmt->fetch(PDO::FETCH_ASSOC)) {
            $transactions[] = self::fromArray($data);
        }
        
        return $transactions;
    }
    
    /**
     * Проверить, связана ли транзакция с общиной
     */
    public function isFromCommunity(): bool
    {
        return $this->communityRequestId !== null || $this->transactionType === 'community';
    }
    
    // Setters
    public function setDescription(?string $description): void { $this->description = $description; }
    public function setCategory(?string $category): void { $this->category = $category; }
    public function setTransactionType(string $type): void { $this->transactionType = $type; }
    public function setSellerConfirmed(bool $confirmed): void { $this->sellerConfirmed = $confirmed; }
    public function setBuyerConfirmed(bool $confirmed): void { $this->buyerConfirmed = $confirmed; }
}

