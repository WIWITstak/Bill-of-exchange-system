<?php

namespace OGAS\Models;

use OGAS\Database;
use PDO;

/**
 * Модель заявки на круговую поруку "Община"
 */
class CommunityRequest
{
    private ?int $id = null;
    private int $userId;
    private int $amount; // Количество векселей
    private float $nominal;
    private ?string $description = null;
    private int $maturityDays;
    private string $status; // open, fulfilled, closed, cancelled
    private ?string $createdAt = null;
    private ?string $updatedAt = null;
    private ?int $targetCompanyUserId = null; // ID пользователя компании-продавца
    private ?string $productDescription = null; // Описание целевого товара/услуги
    private ?float $productPrice = null; // Стоимость товара/услуги
    
    /**
     * Найти заявку по ID
     */
    public static function findById(int $id): ?self
    {
        $db = Database::getConnection();
        $stmt = $db->prepare("SELECT * FROM community_requests WHERE id = ?");
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
        $request = new self();
        $request->id = (int)$data['id'];
        $request->userId = (int)$data['user_id'];
        $request->amount = (int)$data['amount'];
        $request->nominal = (float)$data['nominal'];
        $request->description = $data['description'];
        $request->maturityDays = (int)$data['maturity_days'];
        $request->status = $data['status'];
        $request->createdAt = $data['created_at'] ?? null;
        $request->updatedAt = $data['updated_at'] ?? null;
        $request->targetCompanyUserId = isset($data['target_company_user_id']) && $data['target_company_user_id'] ? (int)$data['target_company_user_id'] : null;
        $request->productDescription = $data['product_description'] ?? null;
        $request->productPrice = isset($data['product_price']) && $data['product_price'] ? (float)$data['product_price'] : null;
        return $request;
    }
    
    /**
     * Создать новую заявку
     */
    public static function create(array $data): self
    {
        $db = Database::getConnection();
        
        $stmt = $db->prepare("
            INSERT INTO community_requests (user_id, amount, nominal, description, maturity_days, status, target_company_user_id, product_description, product_price)
            VALUES (?, ?, ?, ?, ?, 'open', ?, ?, ?)
        ");
        
        $stmt->execute([
            $data['user_id'],
            $data['amount'],
            $data['nominal'],
            $data['description'] ?? null,
            $data['maturity_days'],
            $data['target_company_user_id'] ?? null,
            $data['product_description'] ?? null,
            $data['product_price'] ?? null
        ]);
        
        $requestId = (int)$db->lastInsertId();
        return self::findById($requestId);
    }
    
    /**
     * Получить все открытые заявки
     */
    public static function findOpen(): array
    {
        $db = Database::getConnection();
        $stmt = $db->prepare("
            SELECT * FROM community_requests 
            WHERE status = 'open'
            ORDER BY created_at DESC
        ");
        $stmt->execute();
        
        $requests = [];
        while ($data = $stmt->fetch(PDO::FETCH_ASSOC)) {
            $requests[] = self::fromArray($data);
        }
        
        return $requests;
    }
    
    /**
     * Получить все архивные заявки (fulfilled, closed, cancelled)
     */
    public static function findArchived(): array
    {
        $db = Database::getConnection();
        $stmt = $db->prepare("
            SELECT * FROM community_requests 
            WHERE status IN ('fulfilled', 'closed', 'cancelled')
            ORDER BY updated_at DESC, created_at DESC
        ");
        $stmt->execute();
        
        $requests = [];
        while ($data = $stmt->fetch(PDO::FETCH_ASSOC)) {
            $requests[] = self::fromArray($data);
        }
        
        return $requests;
    }
    
    /**
     * Получить архивные заявки пользователя (где пользователь является заявителем или поручителем)
     */
    public static function findUserArchived(int $userId): array
    {
        $db = Database::getConnection();
        $stmt = $db->prepare("
            SELECT DISTINCT cr.* 
            FROM community_requests cr
            LEFT JOIN community_guarantors cg ON cr.id = cg.request_id
            WHERE (cr.user_id = ? OR cg.guarantor_id = ?)
            AND cr.status IN ('fulfilled', 'closed', 'cancelled')
            ORDER BY cr.updated_at DESC, cr.created_at DESC
        ");
        $stmt->execute([$userId, $userId]);
        
        $requests = [];
        while ($data = $stmt->fetch(PDO::FETCH_ASSOC)) {
            $requests[] = self::fromArray($data);
        }
        
        return $requests;
    }
    
    /**
     * Получить заявки общины, в которых пользователь является участником группового чата
     * (заявитель или поручитель) и заявка выполнена (есть групповой чат)
     */
    public static function findUserCommunityChats(int $userId): array
    {
        $db = Database::getConnection();
        // Получаем заявки, где пользователь является заявителем или поручителем
        // и заявка выполнена (собрано нужное количество векселей)
        $stmt = $db->prepare("
            SELECT DISTINCT cr.* 
            FROM community_requests cr
            LEFT JOIN community_guarantors cg ON cr.id = cg.request_id AND cg.status = 'active'
            WHERE (cr.user_id = ? OR cg.guarantor_id = ?)
            AND (
                (SELECT SUM(bills_count) FROM community_guarantors WHERE request_id = cr.id AND status = 'active') >= cr.amount
            )
            AND EXISTS (
                SELECT 1 FROM messages 
                WHERE community_request_id = cr.id AND transaction_id IS NULL
            )
            ORDER BY cr.updated_at DESC, cr.created_at DESC
        ");
        $stmt->execute([$userId, $userId]);
        
        $requests = [];
        while ($data = $stmt->fetch(PDO::FETCH_ASSOC)) {
            $requests[] = self::fromArray($data);
        }
        
        return $requests;
    }
    
    /**
     * Получить количество поручителей
     */
    public function getGuarantorsCount(): int
    {
        $db = Database::getConnection();
        $stmt = $db->prepare("
            SELECT COUNT(*) as count 
            FROM community_guarantors 
            WHERE request_id = ? AND status = 'active'
        ");
        $stmt->execute([$this->id]);
        return (int)$stmt->fetch(PDO::FETCH_ASSOC)['count'];
    }
    
    /**
     * Получить количество собранных векселей
     */
    public function getCollectedAmount(): int
    {
        $db = Database::getConnection();
        $stmt = $db->prepare("
            SELECT COALESCE(SUM(bills_count), 0) as total 
            FROM community_guarantors 
            WHERE request_id = ? AND status = 'active'
        ");
        $stmt->execute([$this->id]);
        return (int)$stmt->fetch(PDO::FETCH_ASSOC)['total'];
    }
    
    /**
     * Проверить, выполнена ли заявка (собрано нужное количество векселей)
     */
    public function isFulfilled(): bool
    {
        return $this->getCollectedAmount() >= $this->amount;
    }
    
    /**
     * Получить всех поручителей
     */
    public function getGuarantors(): array
    {
        $db = Database::getConnection();
        $stmt = $db->prepare("
            SELECT * FROM community_guarantors 
            WHERE request_id = ? AND status = 'active'
            ORDER BY created_at ASC
        ");
        $stmt->execute([$this->id]);
        
        $guarantors = [];
        while ($data = $stmt->fetch(PDO::FETCH_ASSOC)) {
            $guarantors[] = $data;
        }
        
        return $guarantors;
    }
    
    /**
     * Получить количество подтвердивших поручителей
     */
    public function getConfirmedGuarantorsCount(): int
    {
        $db = Database::getConnection();
        $stmt = $db->prepare("
            SELECT COUNT(*) as count 
            FROM community_guarantors 
            WHERE request_id = ? AND status = 'active' AND confirmed = 1
        ");
        $stmt->execute([$this->id]);
        return (int)$stmt->fetch(PDO::FETCH_ASSOC)['count'];
    }
    
    /**
     * Проверить, все ли поручители подтвердили согласие
     */
    public function allGuarantorsConfirmed(): bool
    {
        $guarantorsCount = $this->getGuarantorsCount();
        if ($guarantorsCount == 0) {
            return false;
        }
        return $this->getConfirmedGuarantorsCount() == $guarantorsCount && $this->isFulfilled();
    }
    
    /**
     * Получить участников группового чата (заявитель + поручители)
     */
    public function getChatParticipants(): array
    {
        $participants = [$this->userId]; // Заявитель
        
        $guarantors = $this->getGuarantors();
        foreach ($guarantors as $guarantor) {
            $participants[] = (int)$guarantor['guarantor_id'];
        }
        
        return array_unique($participants);
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
            UPDATE community_requests 
            SET status = ?
            WHERE id = ?
        ");
        
        return $stmt->execute([$this->status, $this->id]);
    }
    
    // Getters
    public function getId(): ?int { return $this->id; }
    public function getUserId(): int { return $this->userId; }
    public function getAmount(): int { return $this->amount; }
    public function getNominal(): float { return $this->nominal; }
    public function getDescription(): ?string { return $this->description; }
    public function getMaturityDays(): int { return $this->maturityDays; }
    public function getStatus(): string { return $this->status; }
    public function getCreatedAt(): ?string { return $this->createdAt; }
    public function getUpdatedAt(): ?string { return $this->updatedAt; }
    public function getTargetCompanyUserId(): ?int { return $this->targetCompanyUserId; }
    public function getProductDescription(): ?string { return $this->productDescription; }
    public function getProductPrice(): ?float { return $this->productPrice; }
    
    /**
     * Получить общую стоимость заявки
     */
    public function getTotalAmount(): float
    {
        return $this->amount * $this->nominal;
    }
    
    /**
     * Получить прогресс сбора (в процентах)
     */
    public function getProgressPercent(): float
    {
        if ($this->amount == 0) {
            return 0;
        }
        return min(100, ($this->getCollectedAmount() / $this->amount) * 100);
    }
    
    // Setters
    public function setStatus(string $status): void { $this->status = $status; }
}

