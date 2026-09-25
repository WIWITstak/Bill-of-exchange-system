<?php

namespace OGAS\Models;

use OGAS\Database;
use PDO;

class Product
{
    private $id;
    private $userId;
    private $name;
    private $description;
    private $type; // 'product' или 'service'
    private $category;
    private $price; // Для обратной совместимости
    private $basePrice; // Начальная цена (цена продавца) в рублях
    private $doublesPrice; // Цена товара в дублях (новая система)
    private $unit;
    private $quantity;
    private $isAvailable;
    private $images;
    private $createdAt;
    private $updatedAt;

    public function __construct(array $data = [])
    {
        if (!empty($data)) {
            $this->id = $data['id'] ?? null;
            $this->userId = $data['user_id'] ?? null;
            $this->name = $data['name'] ?? '';
            $this->description = $data['description'] ?? '';
            $this->type = $data['type'] ?? 'product';
            $this->category = $data['category'] ?? '';
            $this->price = $data['price'] ?? 0.00;
            // base_price - начальная цена (цена продавца) в рублях
            // Если base_price не указан, используем price для обратной совместимости
            $this->basePrice = $data['base_price'] ?? ($data['price'] ?? 0.00);
            // doubles_price - цена товара в дублях (новая система)
            $this->doublesPrice = $data['doubles_price'] ?? 0.00;
            $this->unit = $data['unit'] ?? 'шт';
            $this->quantity = $data['quantity'] ?? null;
            $this->isAvailable = isset($data['is_available']) ? (bool)$data['is_available'] : true;
            $this->images = $data['images'] ?? null;
            $this->createdAt = $data['created_at'] ?? null;
            $this->updatedAt = $data['updated_at'] ?? null;
        }
    }

    // Getters
    public function getId() { return $this->id; }
    public function getUserId() { return $this->userId; }
    public function getName() { return $this->name; }
    public function getDescription() { return $this->description; }
    public function getType() { return $this->type; }
    public function getCategory() { return $this->category; }
    public function getPrice() { return $this->price; } // Для обратной совместимости
    public function getBasePrice() { return $this->basePrice ?? $this->price; } // Начальная цена в рублях
    public function getDoublesPrice() { return $this->doublesPrice; } // Цена в дублях
    public function getUnit() { return $this->unit; }
    public function getQuantity() { return $this->quantity; }
    public function isAvailable() { return $this->isAvailable; }
    public function getImages() {
        if ($this->images) {
            return json_decode($this->images, true);
        }
        return [];
    }
    public function getCreatedAt() { return $this->createdAt; }
    public function getUpdatedAt() { return $this->updatedAt; }

    /**
     * Получить фактическую цену товара/услуги в рублях на основе рейтинга покупателя
     *
     * Новая формула: цена в рублях = цена в дублях / рейтинг_покупателя
     * P = C/R, где:
     * P — цена в рублях, которую платит пользователь
     * C — стоимость товара в дублях (фиксированная для всех)
     * R — рейтинг пользователя
     *
     * Примеры:
     * - Цена в дублях: 5000, рейтинг покупателя: 1.0 → цена в рублях: 5000 ₽
     * - Цена в дублях: 5000, рейтинг покупателя: 10 → цена в рублях: 500 ₽
     * - Цена в дублях: 5000, рейтинг покупателя: 0.5 → цена в рублях: 10000 ₽
     *
     * @param float $buyerRating Рейтинг покупателя в системе "Око" (минимум 1.0)
     * @return float Фактическая цена в рублях для данного покупателя
     */
    public function getActualPrice(float $buyerRating = 1.0): float
    {
        // Рейтинг не может быть меньше базового 1.0
        $rating = max($buyerRating, 1.0);

        // Фактическая цена в рублях = цена в дублях / рейтинг покупателя
        $doublesPrice = $this->getDoublesPrice();
        $actualPrice = $doublesPrice / $rating;

        // Округляем до 2 знаков после запятой
        return round($actualPrice, 2);
    }

    // Setters
    public function setUserId($userId) { $this->userId = $userId; }
    public function setName($name) { $this->name = $name; }
    public function setDescription($description) { $this->description = $description; }
    public function setType($type) { $this->type = $type; }
    public function setCategory($category) { $this->category = $category; }
    public function setPrice($price) {
        $this->price = $price;
        // Если base_price не установлен, устанавливаем его равным price
        if (!isset($this->basePrice) || $this->basePrice == 0) {
            $this->basePrice = $price;
        }
    }
    public function setBasePrice($basePrice) {
        $this->basePrice = $basePrice;
        // Обновляем price для обратной совместимости
        $this->price = $basePrice;
    }
    public function setDoublesPrice(float $doublesPrice) {
        $this->doublesPrice = $doublesPrice;
    }
    public function setUnit($unit) { $this->unit = $unit; }
    public function setQuantity($quantity) { $this->quantity = $quantity; }
    public function setIsAvailable($isAvailable) { $this->isAvailable = $isAvailable; }
    public function setImages($images) {
        if (is_array($images)) {
            $this->images = json_encode($images);
        } else {
            $this->images = $images;
        }
    }

    /**
     * Сохранить товар/услугу
     */
    public function save()
    {
        $db = Database::getConnection();

        if ($this->id) {
            // Получаем старую цену для сравнения
            $oldProduct = self::findById($this->id);
            $oldPrice = $oldProduct ? $oldProduct->getPrice() : null;

            // Обновление
            $stmt = $db->prepare("
                UPDATE products
                SET user_id = ?, name = ?, description = ?, type = ?, category = ?,
                    price = ?, base_price = ?, doubles_price = ?, unit = ?, quantity = ?, is_available = ?, images = ?
                WHERE id = ?
            ");
            $basePrice = $this->getBasePrice();
            $doublesPrice = $this->getDoublesPrice();
            $result = $stmt->execute([
                $this->userId,
                $this->name,
                $this->description,
                $this->type,
                $this->category,
                $this->price,
                $basePrice,
                $doublesPrice,
                $this->unit,
                $this->quantity,
                $this->isAvailable ? 1 : 0,
                $this->images,
                $this->id
            ]);

            // Сохраняем историю цены, если начальная цена изменилась
            if ($result && $oldPrice !== null && $oldPrice != $basePrice) {
                $this->savePriceHistory($basePrice);
            }

            return $result;
        } else {
            // Создание
            $basePrice = $this->getBasePrice();
            $doublesPrice = $this->getDoublesPrice();
            $stmt = $db->prepare("
                INSERT INTO products (user_id, name, description, type, category, price, base_price, doubles_price, unit, quantity, is_available, images)
                VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)
            ");
            $result = $stmt->execute([
                $this->userId,
                $this->name,
                $this->description,
                $this->type,
                $this->category,
                $this->price,
                $basePrice,
                $doublesPrice,
                $this->unit,
                $this->quantity,
                $this->isAvailable ? 1 : 0,
                $this->images
            ]);

            if ($result) {
                $this->id = $db->lastInsertId();
                // Сохраняем начальную цену в историю
                $this->savePriceHistory($basePrice);
            }

            return $result;
        }
    }

    /**
     * Удалить товар/услугу
     */
    public function delete()
    {
        if (!$this->id) {
            return false;
        }

        $db = Database::getConnection();
        $stmt = $db->prepare("DELETE FROM products WHERE id = ?");
        return $stmt->execute([$this->id]);
    }

    /**
     * Найти товар/услугу по ID
     */
    public static function findById($id)
    {
        $db = Database::getConnection();
        $stmt = $db->prepare("SELECT * FROM products WHERE id = ?");
        $stmt->execute([$id]);
        $data = $stmt->fetch(PDO::FETCH_ASSOC);

        return $data ? new self($data) : null;
    }

    /**
     * Найти все товары/услуги пользователя
     */
    public static function findByUserId($userId, $type = null, $isAvailable = null)
    {
        $db = Database::getConnection();

        $sql = "SELECT * FROM products WHERE user_id = ?";
        $params = [$userId];

        if ($type !== null) {
            $sql .= " AND type = ?";
            $params[] = $type;
        }

        if ($isAvailable !== null) {
            $sql .= " AND is_available = ?";
            $params[] = $isAvailable ? 1 : 0;
        }

        $sql .= " ORDER BY created_at DESC";

        $stmt = $db->prepare($sql);
        $stmt->execute($params);

        $products = [];
        while ($row = $stmt->fetch(PDO::FETCH_ASSOC)) {
            $products[] = new self($row);
        }

        return $products;
    }

    /**
     * Найти все товары/услуги с фильтрами
     */
    public static function findAll($filters = [])
    {
        $db = Database::getConnection();

        $sql = "SELECT p.*, u.full_name as user_name
                FROM products p
                LEFT JOIN users u ON p.user_id = u.id
                WHERE 1=1";
        $params = [];

        if (isset($filters['type'])) {
            $sql .= " AND p.type = ?";
            $params[] = $filters['type'];
        }

        if (isset($filters['category'])) {
            $sql .= " AND p.category = ?";
            $params[] = $filters['category'];
        }

        if (isset($filters['is_available'])) {
            $sql .= " AND p.is_available = ?";
            $params[] = $filters['is_available'] ? 1 : 0;
        }

        if (isset($filters['search']) && !empty($filters['search'])) {
            $sql .= " AND (p.name LIKE ? OR p.description LIKE ? OR u.full_name LIKE ?)";
            $searchTerm = '%' . $filters['search'] . '%';
            $params[] = $searchTerm;
            $params[] = $searchTerm;
            $params[] = $searchTerm;
        }

        if (isset($filters['user_id'])) {
            $sql .= " AND p.user_id = ?";
            $params[] = $filters['user_id'];
        }

        if (isset($filters['exclude_user_id'])) {
            $sql .= " AND p.user_id != ?";
            $params[] = $filters['exclude_user_id'];
        }

        $sql .= " ORDER BY p.created_at DESC";

        if (isset($filters['limit'])) {
            $sql .= " LIMIT ?";
            $params[] = (int)$filters['limit'];
        }

        $stmt = $db->prepare($sql);
        $stmt->execute($params);

        $products = [];
        while ($row = $stmt->fetch(PDO::FETCH_ASSOC)) {
            $products[] = new self($row);
        }

        return $products;
    }

    /**
     * Получить количество товаров/услуг пользователя
     */
    public static function countByUserId($userId, $type = null)
    {
        $db = Database::getConnection();

        $sql = "SELECT COUNT(*) FROM products WHERE user_id = ?";
        $params = [$userId];

        if ($type !== null) {
            $sql .= " AND type = ?";
            $params[] = $type;
        }

        $stmt = $db->prepare($sql);
        $stmt->execute($params);

        return (int)$stmt->fetchColumn();
    }

    /**
     * Сохранить историю цены
     */
    private function savePriceHistory($price)
    {
        if (!$this->id) {
            return false;
        }

        $db = Database::getConnection();
        $stmt = $db->prepare("
            INSERT INTO product_prices_history (product_id, price)
            VALUES (?, ?)
        ");
        return $stmt->execute([$this->id, $price]);
    }

    /**
     * Получить категории товаров/услуг
     */
    public static function getCategories()
    {
        $db = Database::getConnection();
        $stmt = $db->query("
            SELECT DISTINCT category
            FROM products
            WHERE category IS NOT NULL AND category != ''
            ORDER BY category
        ");

        return $stmt->fetchAll(PDO::FETCH_COLUMN);
    }
}