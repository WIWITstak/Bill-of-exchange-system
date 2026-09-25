<?php

namespace OGAS\Models;

use OGAS\Database;
use PDO;

/**
 * Модель векселя
 */
class Bill
{
    private ?int $id = null;
    private int $issuerId; // Выпустивший вексель
    private int $holderId; // Держатель векселя
    private float $nominal;
    private string $issueDate;
    private string $maturityDate;
    private ?string $paymentDate = null;
    private string $status; // active, paid, overdue, cancelled
    private ?int $communityRequestId = null; // ID заявки общины
    private ?int $communityGuarantorId = null; // ID поручителя в общине
    private ?string $filePath = null; // Путь к PDF файлу векселя
    
    /**
     * Найти вексель по ID
     */
    public static function findById(int $id): ?self
    {
        $db = Database::getConnection();
        $stmt = $db->prepare("SELECT * FROM bills WHERE id = ?");
        $stmt->execute([$id]);
        $data = $stmt->fetch(PDO::FETCH_ASSOC);
        
        if (!$data) {
            return null;
        }
        
        return self::fromArray($data);
    }
    
    /**
     * Найти все вексели пользователя (выпущенные)
     */
    public static function findByIssuer(int $userId, ?string $status = null): array
    {
        $db = Database::getConnection();
        $sql = "SELECT * FROM bills WHERE issuer_id = ?";
        $params = [$userId];
        
        if ($status) {
            $sql .= " AND status = ?";
            $params[] = $status;
        }
        
        $sql .= " ORDER BY issue_date DESC";
        
        $stmt = $db->prepare($sql);
        $stmt->execute($params);
        
        $bills = [];
        while ($data = $stmt->fetch(PDO::FETCH_ASSOC)) {
            $bills[] = self::fromArray($data);
        }
        
        return $bills;
    }
    
    /**
     * Найти все вексели на счету пользователя (полученные)
     */
    public static function findByHolder(int $userId, ?string $status = null): array
    {
        $db = Database::getConnection();
        $sql = "SELECT * FROM bills WHERE holder_id = ?";
        $params = [$userId];
        
        if ($status) {
            $sql .= " AND status = ?";
            $params[] = $status;
        }
        
        $sql .= " ORDER BY issue_date DESC";
        
        $stmt = $db->prepare($sql);
        $stmt->execute($params);
        
        $bills = [];
        while ($data = $stmt->fetch(PDO::FETCH_ASSOC)) {
            $bills[] = self::fromArray($data);
        }
        
        return $bills;
    }
    
    /**
     * Получить все вексели (для админ-панели)
     */
    public static function findAll(?string $status = null, int $limit = 100, int $offset = 0): array
    {
        $db = Database::getConnection();
        $sql = "SELECT * FROM bills";
        $params = [];
        
        if ($status) {
            $sql .= " WHERE status = ?";
            $params[] = $status;
        }
        
        $sql .= " ORDER BY issue_date DESC LIMIT ? OFFSET ?";
        $params[] = $limit;
        $params[] = $offset;
        
        $stmt = $db->prepare($sql);
        $stmt->execute($params);
        
        $bills = [];
        while ($data = $stmt->fetch(PDO::FETCH_ASSOC)) {
            $bills[] = self::fromArray($data);
        }
        
        return $bills;
    }
    
    /**
     * Создать из массива
     */
    public static function fromArray(array $data): self
    {
        $bill = new self();
        $bill->id = (int)$data['id'];
        $bill->issuerId = (int)$data['issuer_id'];
        $bill->holderId = (int)$data['holder_id'];
        $bill->nominal = (float)$data['nominal'];
        $bill->issueDate = $data['issue_date'];
        $bill->maturityDate = $data['maturity_date'];
        $bill->paymentDate = $data['payment_date'];
        $bill->status = $data['status'];
        $bill->communityRequestId = isset($data['community_request_id']) && $data['community_request_id'] ? (int)$data['community_request_id'] : null;
        $bill->communityGuarantorId = isset($data['community_guarantor_id']) && $data['community_guarantor_id'] ? (int)$data['community_guarantor_id'] : null;
        $bill->filePath = isset($data['file_path']) && $data['file_path'] ? $data['file_path'] : null;
        return $bill;
    }
    
    /**
     * Создать новый вексель
     */
    public static function create(array $data): self
    {
        $db = Database::getConnection();
        
        $stmt = $db->prepare("
            INSERT INTO bills (issuer_id, holder_id, nominal, issue_date, maturity_date, status, community_request_id, community_guarantor_id, file_path)
            VALUES (?, ?, ?, NOW(), ?, 'active', ?, ?, ?)
        ");
        
        $stmt->execute([
            $data['issuer_id'],
            $data['holder_id'],
            $data['nominal'],
            $data['maturity_date'],
            $data['community_request_id'] ?? null,
            $data['community_guarantor_id'] ?? null,
            $data['file_path'] ?? null
        ]);
        
        $billId = (int)$db->lastInsertId();
        return self::findById($billId);
    }
    
    /**
     * Погасить вексель
     */
    public function pay(): bool
    {
        if ($this->status !== 'active') {
            return false;
        }
        
        $db = Database::getConnection();
        $stmt = $db->prepare("
            UPDATE bills 
            SET status = 'paid', payment_date = NOW()
            WHERE id = ?
        ");
        
        if ($stmt->execute([$this->id])) {
            $this->status = 'paid';
            $this->paymentDate = date('Y-m-d H:i:s');
            return true;
        }
        
        return false;
    }
    
    /**
     * Проверить, просрочен ли вексель
     */
    public function isOverdue(): bool
    {
        if ($this->status !== 'active') {
            return false;
        }
        
        return strtotime($this->maturityDate) < time();
    }
    
    /**
     * Обновить статус просроченных векселей
     */
    public function updateOverdueStatus(): void
    {
        if ($this->isOverdue() && $this->status === 'active') {
            $db = Database::getConnection();
            $stmt = $db->prepare("UPDATE bills SET status = 'overdue' WHERE id = ?");
            $stmt->execute([$this->id]);
            $this->status = 'overdue';
        }
    }
    
    /**
     * Найти вексели связанные с общиной
     */
    public static function findByCommunityRequest(int $requestId): array
    {
        $db = Database::getConnection();
        $stmt = $db->prepare("SELECT * FROM bills WHERE community_request_id = ? ORDER BY issue_date DESC");
        $stmt->execute([$requestId]);
        
        $bills = [];
        while ($data = $stmt->fetch(PDO::FETCH_ASSOC)) {
            $bills[] = self::fromArray($data);
        }
        
        return $bills;
    }
    
    /**
     * Найти вексели пользователя связанные с общиной
     */
    public static function findByUserAndCommunity(int $userId, bool $communityOnly = false): array
    {
        $db = Database::getConnection();
        $sql = "SELECT * FROM bills WHERE (issuer_id = ? OR holder_id = ?)";
        $params = [$userId, $userId];
        
        if ($communityOnly) {
            $sql .= " AND community_request_id IS NOT NULL";
        }
        
        $sql .= " ORDER BY issue_date DESC";
        
        $stmt = $db->prepare($sql);
        $stmt->execute($params);
        
        $bills = [];
        while ($data = $stmt->fetch(PDO::FETCH_ASSOC)) {
            $bills[] = self::fromArray($data);
        }
        
        return $bills;
    }
    
    // Getters
    public function getId(): ?int { return $this->id; }
    public function getIssuerId(): int { return $this->issuerId; }
    public function getHolderId(): int { return $this->holderId; }
    public function getNominal(): float { return $this->nominal; }
    public function getIssueDate(): string { return $this->issueDate; }
    public function getMaturityDate(): string { return $this->maturityDate; }
    public function getPaymentDate(): ?string { return $this->paymentDate; }
    public function getStatus(): string { return $this->status; }
    public function getCommunityRequestId(): ?int { return $this->communityRequestId; }
    public function getCommunityGuarantorId(): ?int { return $this->communityGuarantorId; }
    public function getFilePath(): ?string { return $this->filePath; }
    
    /**
     * Установить путь к PDF файлу
     */
    public function setFilePath(?string $filePath): void
    {
        $this->filePath = $filePath;
    }
    
    /**
     * Сохранить путь к файлу в базе данных
     */
    public function saveFilePath(): bool
    {
        if (!$this->id) {
            return false;
        }
        
        $db = Database::getConnection();
        $stmt = $db->prepare("UPDATE bills SET file_path = ? WHERE id = ?");
        return $stmt->execute([$this->filePath, $this->id]);
    }
    
    /**
     * Проверить, связан ли вексель с общиной
     */
    public function isFromCommunity(): bool
    {
        return $this->communityRequestId !== null;
    }
}

