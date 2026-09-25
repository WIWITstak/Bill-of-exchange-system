<?php

namespace OGAS\Models;

use OGAS\Database;
use PDO;

/**
 * Модель истории рейтинга
 */
class RatingHistory
{
    private ?int $id = null;
    private int $userId;
    private float $totalRating;
    private float $balanceScore;
    private float $paymentDiscipline;
    private float $earlyPaymentAvg;
    private float $doubles;
    private ?string $createdAt = null;
    
    /**
     * Создать из массива
     */
    public static function fromArray(array $data): self
    {
        $history = new self();
        $history->id = (int)$data['id'];
        $history->userId = (int)$data['user_id'];
        $history->totalRating = (float)$data['total_rating'];
        $history->balanceScore = (float)$data['balance_score'];
        $history->paymentDiscipline = (float)$data['payment_discipline'];
        $history->earlyPaymentAvg = (float)$data['early_payment_avg'];
        $history->doubles = (float)$data['doubles'];
        $history->createdAt = $data['created_at'];
        return $history;
    }
    
    /**
     * Сохранить запись истории рейтинга
     */
    public static function create(array $data): self
    {
        $db = Database::getConnection();
        
        $stmt = $db->prepare("
            INSERT INTO rating_history (user_id, total_rating, balance_score, payment_discipline, early_payment_avg, doubles)
            VALUES (?, ?, ?, ?, ?, ?)
        ");
        
        $stmt->execute([
            $data['user_id'],
            $data['total_rating'],
            $data['balance_score'],
            $data['payment_discipline'],
            $data['early_payment_avg'],
            $data['doubles']
        ]);
        
        $id = (int)$db->lastInsertId();
        
        return self::findById($id);
    }
    
    /**
     * Найти запись по ID
     */
    public static function findById(int $id): ?self
    {
        $db = Database::getConnection();
        $stmt = $db->prepare("SELECT * FROM rating_history WHERE id = ?");
        $stmt->execute([$id]);
        $data = $stmt->fetch(PDO::FETCH_ASSOC);
        
        if (!$data) {
            return null;
        }
        
        return self::fromArray($data);
    }
    
    /**
     * Получить историю рейтинга пользователя
     */
    public static function findByUserId(int $userId, int $limit = 100, int $days = 30): array
    {
        $db = Database::getConnection();
        $stmt = $db->prepare("
            SELECT * FROM rating_history 
            WHERE user_id = ? 
            AND created_at >= DATE_SUB(NOW(), INTERVAL ? DAY)
            ORDER BY created_at DESC
            LIMIT ?
        ");
        $stmt->execute([$userId, $days, $limit]);
        
        $history = [];
        while ($data = $stmt->fetch(PDO::FETCH_ASSOC)) {
            $history[] = self::fromArray($data);
        }
        
        return array_reverse($history); // Возвращаем в хронологическом порядке
    }
    
    /**
     * Получить последнюю запись рейтинга пользователя
     */
    public static function getLatest(int $userId): ?self
    {
        $db = Database::getConnection();
        $stmt = $db->prepare("
            SELECT * FROM rating_history 
            WHERE user_id = ? 
            ORDER BY created_at DESC
            LIMIT 1
        ");
        $stmt->execute([$userId]);
        $data = $stmt->fetch(PDO::FETCH_ASSOC);
        
        if (!$data) {
            return null;
        }
        
        return self::fromArray($data);
    }
    
    // Getters
    public function getId(): ?int { return $this->id; }
    public function getUserId(): int { return $this->userId; }
    public function getTotalRating(): float { return $this->totalRating; }
    public function getBalanceScore(): float { return $this->balanceScore; }
    public function getPaymentDiscipline(): float { return $this->paymentDiscipline; }
    public function getEarlyPaymentAvg(): float { return $this->earlyPaymentAvg; }
    public function getDoubles(): float { return $this->doubles; }
    public function getCreatedAt(): ?string { return $this->createdAt; }
}








