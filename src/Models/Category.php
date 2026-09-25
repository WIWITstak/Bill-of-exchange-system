<?php

namespace OGAS\Models;

use OGAS\Database;
use OGAS\Core\Cache;
use PDO;

/**
 * Модель категории товаров/услуг
 */
class Category
{
    private ?int $id = null;
    private string $name;
    private ?string $description = null;
    private ?string $icon = null;
    private ?int $parentId = null;
    private int $sortOrder = 0;
    private bool $isActive = true;
    private ?string $createdAt = null;
    private ?string $updatedAt = null;
    
    /**
     * Создать из массива
     */
    public static function fromArray(array $data): self
    {
        $category = new self();
        $category->id = (int)$data['id'];
        $category->name = $data['name'];
        $category->description = $data['description'] ?? null;
        $category->icon = $data['icon'] ?? null;
        $category->parentId = isset($data['parent_id']) ? (int)$data['parent_id'] : null;
        $category->sortOrder = (int)($data['sort_order'] ?? 0);
        $category->isActive = (bool)($data['is_active'] ?? true);
        $category->createdAt = $data['created_at'] ?? null;
        $category->updatedAt = $data['updated_at'] ?? null;
        return $category;
    }
    
    /**
     * Найти категорию по ID
     */
    public static function findById(int $id): ?self
    {
        $cacheKey = "category:id:{$id}";
        
        return Cache::remember($cacheKey, function() use ($id) {
            $db = Database::getConnection();
            $stmt = $db->prepare("SELECT * FROM categories WHERE id = ?");
            $stmt->execute([$id]);
            $data = $stmt->fetch(PDO::FETCH_ASSOC);
            
            if (!$data) {
                return null;
            }
            
            return self::fromArray($data);
        }, Cache::DEFAULT_TTL);
    }
    
    /**
     * Найти категорию по имени
     */
    public static function findByName(string $name): ?self
    {
        $db = Database::getConnection();
        $stmt = $db->prepare("SELECT * FROM categories WHERE name = ? AND is_active = 1");
        $stmt->execute([$name]);
        $data = $stmt->fetch(PDO::FETCH_ASSOC);
        
        if (!$data) {
            return null;
        }
        
        return self::fromArray($data);
    }
    
    /**
     * Получить все активные категории
     */
    public static function getAllActive(?int $parentId = null): array
    {
        $parentKey = $parentId === null ? 'null' : ($parentId === 0 ? 'all' : (string)$parentId);
        $cacheKey = "categories:active:parent:{$parentKey}";
        
        return Cache::remember($cacheKey, function() use ($parentId) {
            $db = Database::getConnection();
            $sql = "SELECT * FROM categories WHERE is_active = 1";
            $params = [];
            
            if ($parentId === null) {
                $sql .= " AND parent_id IS NULL";
            } elseif ($parentId === 0) {
                // Все категории (включая вложенные)
            } else {
                $sql .= " AND parent_id = ?";
                $params[] = $parentId;
            }
            
            $sql .= " ORDER BY sort_order ASC, name ASC";
            
            $stmt = $db->prepare($sql);
            $stmt->execute($params);
            
            $categories = [];
            while ($data = $stmt->fetch(PDO::FETCH_ASSOC)) {
                $categories[] = self::fromArray($data);
            }
            
            return $categories;
        }, Cache::DEFAULT_TTL);
    }
    
    /**
     * Поиск категорий по названию (для автокомплита)
     */
    public static function search(string $query, int $limit = 10): array
    {
        $db = Database::getConnection();
        $stmt = $db->prepare("
            SELECT * FROM categories 
            WHERE is_active = 1 
            AND name LIKE ?
            ORDER BY sort_order ASC, name ASC
            LIMIT ?
        ");
        $stmt->execute(["%{$query}%", $limit]);
        
        $categories = [];
        while ($data = $stmt->fetch(PDO::FETCH_ASSOC)) {
            $categories[] = self::fromArray($data);
        }
        
        return $categories;
    }
    
    /**
     * Получить статистику использования категории
     */
    public function getUsageCount(): int
    {
        $db = Database::getConnection();
        $stmt = $db->prepare("SELECT COUNT(*) as count FROM transactions WHERE category = ?");
        $stmt->execute([$this->name]);
        $result = $stmt->fetch(PDO::FETCH_ASSOC);
        
        return (int)($result['count'] ?? 0);
    }
    
    /**
     * Создать новую категорию
     */
    public static function create(array $data): self
    {
        $db = Database::getConnection();
        
        $stmt = $db->prepare("
            INSERT INTO categories (name, description, icon, parent_id, sort_order, is_active)
            VALUES (?, ?, ?, ?, ?, ?)
        ");
        
        $stmt->execute([
            $data['name'],
            $data['description'] ?? null,
            $data['icon'] ?? null,
            $data['parent_id'] ?? null,
            $data['sort_order'] ?? 0,
            $data['is_active'] ?? true
        ]);
        
        $id = (int)$db->lastInsertId();
        
        // Инвалидируем кэш категорий
        self::clearCache();
        
        return self::findById($id);
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
            UPDATE categories 
            SET name = ?, description = ?, icon = ?, parent_id = ?, sort_order = ?, is_active = ?
            WHERE id = ?
        ");
        
        $result = $stmt->execute([
            $this->name,
            $this->description,
            $this->icon,
            $this->parentId,
            $this->sortOrder,
            $this->isActive ? 1 : 0,
            $this->id
        ]);
        
        if ($result) {
            // Инвалидируем кэш категорий
            self::clearCache();
        }
        
        return $result;
    }
    
    /**
     * Удалить категорию
     */
    public function delete(): bool
    {
        if (!$this->id) {
            return false;
        }
        
        // Проверяем, используется ли категория
        if ($this->getUsageCount() > 0) {
            // Не удаляем, а деактивируем
            $this->isActive = false;
            return $this->save();
        }
        
        $db = Database::getConnection();
        $stmt = $db->prepare("DELETE FROM categories WHERE id = ?");
        $result = $stmt->execute([$this->id]);
        
        if ($result) {
            // Инвалидируем кэш категорий
            self::clearCache();
        }
        
        return $result;
    }
    
    /**
     * Очистить кэш категорий
     */
    private static function clearCache(): void
    {
        if (class_exists('OGAS\Core\Cache')) {
            Cache::deleteByPattern('categories:*');
            Cache::deleteByPattern('category:*');
        }
    }
    
    // Getters
    public function getId(): ?int { return $this->id; }
    public function getName(): string { return $this->name; }
    public function getDescription(): ?string { return $this->description; }
    public function getIcon(): ?string { return $this->icon; }
    public function getParentId(): ?int { return $this->parentId; }
    public function getSortOrder(): int { return $this->sortOrder; }
    public function isActive(): bool { return $this->isActive; }
    public function getCreatedAt(): ?string { return $this->createdAt; }
    public function getUpdatedAt(): ?string { return $this->updatedAt; }
    
    // Setters
    public function setName(string $name): void { $this->name = $name; }
    public function setDescription(?string $description): void { $this->description = $description; }
    public function setIcon(?string $icon): void { $this->icon = $icon; }
    public function setParentId(?int $parentId): void { $this->parentId = $parentId; }
    public function setSortOrder(int $sortOrder): void { $this->sortOrder = $sortOrder; }
    public function setIsActive(bool $isActive): void { $this->isActive = $isActive; }
}








