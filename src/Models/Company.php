<?php

namespace OGAS\Models;

use OGAS\Database;
use PDO;

/**
 * Модель предприятия (для юридических лиц)
 */
class Company
{
    private ?int $id = null;
    private int $userId;
    private string $name;
    private ?string $address = null;
    private ?string $okvedCode = null;
    private int $employeeCount = 0;
    private ?string $createdAt = null;
    private ?string $updatedAt = null;
    
    /**
     * Найти предприятие по ID
     */
    public static function findById(int $id): ?self
    {
        $db = Database::getConnection();
        $stmt = $db->prepare("SELECT * FROM companies WHERE id = ?");
        $stmt->execute([$id]);
        $data = $stmt->fetch(PDO::FETCH_ASSOC);
        
        if (!$data) {
            return null;
        }
        
        return self::fromArray($data);
    }
    
    /**
     * Найти предприятие по user_id
     */
    public static function findByUserId(int $userId): ?self
    {
        $db = Database::getConnection();
        $stmt = $db->prepare("SELECT * FROM companies WHERE user_id = ?");
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
        $company = new self();
        $company->id = (int)$data['id'];
        $company->userId = (int)$data['user_id'];
        $company->name = $data['name'];
        $company->address = $data['address'];
        $company->okvedCode = $data['okved_code'];
        $company->employeeCount = (int)$data['employee_count'];
        $company->createdAt = $data['created_at'];
        $company->updatedAt = $data['updated_at'];
        return $company;
    }
    
    /**
     * Создать новое предприятие
     */
    public static function create(array $data): self
    {
        $db = Database::getConnection();
        
        $stmt = $db->prepare("
            INSERT INTO companies (user_id, name, address, okved_code, employee_count)
            VALUES (?, ?, ?, ?, ?)
        ");
        
        $stmt->execute([
            $data['user_id'],
            $data['name'],
            $data['address'] ?? null,
            $data['okved_code'] ?? null,
            $data['employee_count'] ?? 0
        ]);
        
        $companyId = (int)$db->lastInsertId();
        return self::findById($companyId);
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
            UPDATE companies 
            SET name = ?, address = ?, okved_code = ?, employee_count = ?
            WHERE id = ?
        ");
        
        return $stmt->execute([
            $this->name,
            $this->address,
            $this->okvedCode,
            $this->employeeCount,
            $this->id
        ]);
    }
    
    // Getters
    public function getId(): ?int { return $this->id; }
    public function getUserId(): int { return $this->userId; }
    public function getName(): string { return $this->name; }
    public function getAddress(): ?string { return $this->address; }
    public function getOkvedCode(): ?string { return $this->okvedCode; }
    public function getEmployeeCount(): int { return $this->employeeCount; }
    public function getCreatedAt(): ?string { return $this->createdAt; }
    public function getUpdatedAt(): ?string { return $this->updatedAt; }
    
    // Setters
    public function setName(string $name): void { $this->name = $name; }
    public function setAddress(?string $address): void { $this->address = $address; }
    public function setOkvedCode(?string $okvedCode): void { $this->okvedCode = $okvedCode; }
    public function setEmployeeCount(int $count): void { $this->employeeCount = $count; }
}

