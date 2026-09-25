<?php

namespace OGAS\Models;

use OGAS\Database;
use OGAS\Core\Cache;
use OGAS\Models\Bill;
use OGAS\Models\RatingHistory;
use PDO;

/**
 * Модель рейтинговой системы "Око"
 * 
 * Формула расчета рейтинга:
 * Рейтинг = Базовый рейтинг (1.0) + ((Балансовый расчёт / 100) × (Дисциплина погашаемости / 100) × Усреднённое досрочное погашение)
 * 
 * Где:
 * - Балансовый расчёт: баланс (сумма векселей на счету) / обязательства (сумма выпущенных векселей)
 *   Пример: 1 млрд ₽ баланс / 100 млн ₽ обязательств = 10 баллов
 * - Процентная дисциплина погашаемости: процент своевременно погашенных векселей (0-100%)
 *   Пример: 10 млрд ₽ погашено / (10 млрд + 1 млрд) = 90%
 * - Усреднённое досрочное погашение: коэффициент скорости погашения
 *   Пример: 9 векселей погашены в 3 раза быстрее (коэф. 3), 1 просрочен (коэф. -2) → усреднённое = 2
 * 
 * Пример расчета:
 * - Балансовый расчёт = 10 баллов
 * - Дисциплина = 90%
 * - Усреднённое досрочное погашение = 2
 * - Рейтинг = 1.0 + ((10/100) × (90/100) × 2) = 1.0 + (0.1 × 0.9 × 2) = 1.0 + 0.18 = 1.18
 * 
 * Базовый рейтинг = 1.0 для всех пользователей всегда
 * 
 * Дубли (репутационный капитал):
 * Дубли = Сумма всех векселей на счету пользователя × Рейтинг "Око"
 * 
 * ВАЖНО: Номинал векселя может быть любым
 * Дубли рассчитываются как сумма всех векселей на счету пользователя (полученных векселей),
 * умноженная на рейтинг пользователя в системе "Око"
 * 
 * Базовый рейтинг = 1.0 для всех пользователей всегда
 * При базовом рейтинге 1.0: 1 рубль = 1 дубль
 * Дубль динамически меняет свою ценность в результате сделок в зависимости от рейтинга пользователя
 * 
 * Примеры расчета дублей:
 * - Сумма векселей 1000 ₽ при рейтинге 1.0 = 1000 дублей (1000 × 1.0)
 * - Сумма векселей 1000 ₽ при рейтинге 1.5 = 1500 дублей (1000 × 1.5)
 * - Сумма векселей 2500 ₽ (1000 + 1500) при рейтинге 1.0 = 2500 дублей (2500 × 1.0)
 * - Сумма векселей 5000 ₽ при рейтинге 2.0 = 10000 дублей (5000 × 2.0)
 * 
 * Дубли отражают реальную покупательную способность векселей пользователя.
 * Векселеполучатель будет требовать оплаты товаров или услуг не просто в векселях с номиналом в рублях,
 * но с номиналом в рублях, жестко привязанных к желаемому количеству дублей.
 */
class Rating
{
    private ?int $id = null;
    private int $userId;
    private float $balanceScore = 0;
    private float $paymentDiscipline = 0;
    private float $earlyPaymentAvg = 1.0;
    private float $totalRating = 0;
    private float $doubles = 0;
    
    /**
     * Найти рейтинг пользователя
     */
    public static function findByUserId(int $userId): ?self
    {
        $cacheKey = "rating:user:{$userId}";
        
        return Cache::remember($cacheKey, function() use ($userId) {
            $db = Database::getConnection();
            $stmt = $db->prepare("SELECT * FROM ratings WHERE user_id = ?");
            $stmt->execute([$userId]);
            $data = $stmt->fetch(PDO::FETCH_ASSOC);
            
            if (!$data) {
                // Создаём новый рейтинг, если не существует
                return self::createForUser($userId);
            }
            
            $rating = self::fromArray($data);
            
            // Если рейтинг = 0 (старые записи), устанавливаем базовый рейтинг 1.0
            if ($rating->totalRating == 0) {
                $rating->totalRating = 1.0;
                $rating->doubles = 1000 * 1.0; // Базовые дубли
                $rating->paymentDiscipline = 100; // Устанавливаем 100% для новых пользователей
                $rating->save();
            }
            
            return $rating;
        }, Cache::SHORT_TTL); // Короткое время кэширования, т.к. рейтинг может часто меняться
    }
    
    /**
     * Создать рейтинг для пользователя
     * Базовый рейтинг всегда = 1.0 для всех пользователей
     */
    public static function createForUser(int $userId): self
    {
        $db = Database::getConnection();
        
        // Базовый рейтинг = 1.0 для всех новых пользователей
        $baseRating = 1.0;
        $baseDoubles = 1000 * $baseRating; // Базовые дубли для векселя номиналом 1000 рублей
        
        $stmt = $db->prepare("
            INSERT INTO ratings (user_id, balance_score, payment_discipline, early_payment_avg, total_rating, doubles)
            VALUES (?, 0, 100, 0, ?, ?)
        ");
        
        $stmt->execute([$userId, $baseRating, $baseDoubles]);
        
        return self::findByUserId($userId);
    }
    
    /**
     * Создать из массива
     */
    public static function fromArray(array $data): self
    {
        $rating = new self();
        $rating->id = (int)$data['id'];
        $rating->userId = (int)$data['user_id'];
        $rating->balanceScore = (float)$data['balance_score'];
        $rating->paymentDiscipline = (float)$data['payment_discipline'];
        $rating->earlyPaymentAvg = (float)$data['early_payment_avg'];
        $rating->totalRating = (float)$data['total_rating'];
        $rating->doubles = (float)$data['doubles'];
        return $rating;
    }
    
    /**
     * Пересчитать рейтинг пользователя
     */
    public function recalculate(): void
    {
        // 1. Балансовый расчёт
        $this->calculateBalanceScore();
        
        // 2. Дисциплина погашаемости
        $this->calculatePaymentDiscipline();
        
        // 3. Усреднённое досрочное погашение
        $this->calculateEarlyPaymentAvg();
        
        // 4. Общий рейтинг
        // Формула согласно примеру:
        // (Балансовый расчёт / 100) × Дисциплина погашаемости × Усреднённое досрочное погашение
        // 
        // Пример из документации:
        // - Балансовый расчёт = 10 баллов
        // - Дисциплина погашаемости = 90 (в процентах, но используется как число)
        // - Усреднённое досрочное погашение = 2
        // - Рейтинг = (10 / 100) × 90 × 2 = 0.1 × 90 × 2 = 18 баллов
        // 
        // Базовый рейтинг = 1.0 для всех пользователей всегда (минимальный рейтинг)
        // Если рассчитанное значение < 1.0, используем базовый 1.0
        // 
        // Где:
        // - balanceScore: баланс / обязательства (в баллах)
        // - paymentDiscipline: процентная дисциплина погашаемости (0-100, используется как число)
        // - earlyPaymentAvg: усреднённое значение досрочного погашения (коэффициент, может быть > 1 или < 0)
        
        // Усреднённое досрочное погашение уже является коэффициентом
        // Если коэффициент < 0 (просрочки), ограничиваем минимальным значением
        $earlyPaymentCoefficient = $this->earlyPaymentAvg;
        if ($earlyPaymentCoefficient < 0.01) {
            $earlyPaymentCoefficient = 0.01; // Минимальный коэффициент
        }
        
        // Итоговая формула рейтинга
        // Согласно примеру: (балансовый расчёт / 100) × дисциплина × усреднённое досрочное погашение
        // Пример: (10/100) × 90 × 2 = 0.1 × 90 × 2 = 18 баллов
        // 
        // Базовый рейтинг = 1.0 для всех пользователей всегда (минимальный рейтинг)
        if ($this->balanceScore == 0) {
            // Нет векселей - используем базовый рейтинг 1.0
            $this->totalRating = 1.0;
        } else {
            // Рассчитываем рейтинг по формуле из примера
            // Дисциплина используется как число (90), а не как доля (0.9)
            $calculatedRating = ($this->balanceScore / 100) * $this->paymentDiscipline * $earlyPaymentCoefficient;
            
            // Базовый рейтинг = 1.0 (минимальный), рассчитанное значение может быть любым
            // Если рассчитанное значение < 1.0, используем базовый 1.0
            // Если рассчитанное значение >= 1.0, используем его
            $this->totalRating = max(1.0, $calculatedRating);
        }
        
        // Гарантируем, что рейтинг не меньше базового 1.0 для новых пользователей без активности
        if ($this->balanceScore == 0 && $this->paymentDiscipline == 0) {
            $this->totalRating = 1.0; // Базовый рейтинг для новых пользователей
        }
        
        // 5. Дубли (репутационный капитал)
        // Дубли = Сумма всех векселей на счету пользователя × Рейтинг "Око"
        // 
        // ВАЖНО: Номинал векселя может быть любым
        // Дубли рассчитываются как сумма всех векселей на счету пользователя, умноженная на рейтинг
        // 
        // При базовом рейтинге 1.0: 1 рубль = 1 дубль
        // Дубль динамически меняет свою ценность в зависимости от рейтинга
        // 
        // Получаем сумму всех векселей на счету пользователя (полученных векселей)
        $db = Database::getConnection();
        $stmt = $db->prepare("
            SELECT COALESCE(SUM(nominal), 0) as total 
            FROM bills 
            WHERE holder_id = ? 
            AND status IN ('active', 'paid')
        ");
        $stmt->execute([$this->userId]);
        $totalBillsNominal = (float)$stmt->fetch(PDO::FETCH_ASSOC)['total'];
        
        // Дубли = Сумма векселей на счету × Рейтинг "Око"
        $this->doubles = $totalBillsNominal * $this->totalRating;
        
        $this->save();
        
        // Сохраняем в историю рейтинга (только если изменился)
        $latestHistory = RatingHistory::getLatest($this->userId);
        if (!$latestHistory || 
            abs($latestHistory->getTotalRating() - $this->totalRating) > 0.01) {
            // Сохраняем только если рейтинг изменился более чем на 0.01
            RatingHistory::create([
                'user_id' => $this->userId,
                'total_rating' => $this->totalRating,
                'balance_score' => $this->balanceScore,
                'payment_discipline' => $this->paymentDiscipline,
                'early_payment_avg' => $this->earlyPaymentAvg,
                'doubles' => $this->doubles
            ]);
        }
    }
    
    /**
     * Получить историю рейтинга
     */
    public function getHistory(int $days = 30): array
    {
        return RatingHistory::findByUserId($this->userId, 100, $days);
    }
    
    /**
     * Рассчитать балансовый показатель
     * Балансовый расчёт = Баланс (сумма векселей на счету) / Обязательства (сумма выпущенных векселей)
     * 
     * Пример:
     * - Баланс: 1 млрд рублей (вексели на счету)
     * - Обязательства: 100 млн рублей (выпущенные вексели)
     * - Балансовый расчёт = 1 млрд / 100 млн = 10 баллов
     */
    private function calculateBalanceScore(): void
    {
        $db = Database::getConnection();
        
        // Сумма векселей на счету (полученных векселей) - баланс
        $stmt = $db->prepare("
            SELECT COALESCE(SUM(nominal), 0) as total 
            FROM bills 
            WHERE holder_id = ? 
            AND status IN ('active', 'paid')
        ");
        $stmt->execute([$this->userId]);
        $balance = (float)$stmt->fetch(PDO::FETCH_ASSOC)['total'];
        
        // Сумма обязательств (выпущенных активных векселей)
        $stmt = $db->prepare("
            SELECT COALESCE(SUM(nominal), 0) as total 
            FROM bills 
            WHERE issuer_id = ? 
            AND status = 'active'
        ");
        $stmt->execute([$this->userId]);
        $obligations = (float)$stmt->fetch(PDO::FETCH_ASSOC)['total'];
        
        // Балансовый расчёт = баланс / обязательства
        if ($obligations > 0) {
            $this->balanceScore = $balance / $obligations;
        } else {
            // Если нет обязательств, но есть баланс - считаем максимальным
            // Если нет ни баланса, ни обязательств - 0
            $this->balanceScore = $balance > 0 ? 1000 : 0; // Большое значение, если есть баланс без обязательств
        }
    }
    
    /**
     * Рассчитать дисциплину погашаемости
     */
    private function calculatePaymentDiscipline(): void
    {
        $db = Database::getConnection();
        
        // Погашенные вексели
        $stmt = $db->prepare("
            SELECT COUNT(*) as count, COALESCE(SUM(nominal), 0) as total 
            FROM bills 
            WHERE issuer_id = ? AND status = 'paid'
        ");
        $stmt->execute([$this->userId]);
        $paid = $stmt->fetch(PDO::FETCH_ASSOC);
        $paidCount = (int)$paid['count'];
        $paidTotal = (float)$paid['total'];
        
        // Просроченные вексели
        $stmt = $db->prepare("
            SELECT COUNT(*) as count, COALESCE(SUM(nominal), 0) as total 
            FROM bills 
            WHERE issuer_id = ? AND status = 'overdue'
        ");
        $stmt->execute([$this->userId]);
        $overdue = $stmt->fetch(PDO::FETCH_ASSOC);
        $overdueCount = (int)$overdue['count'];
        $overdueTotal = (float)$overdue['total'];
        
        // Дисциплина = (погашенные / (погашенные + просроченные)) * 100
        $total = $paidTotal + $overdueTotal;
        if ($total > 0) {
            $this->paymentDiscipline = ($paidTotal / $total) * 100;
        } else {
            $this->paymentDiscipline = 100; // Если нет обязательств, считаем идеальной
        }
    }
    
    /**
     * Рассчитать усреднённое значение досрочного погашения обязательств
     * 
     * Формула согласно примеру:
     * - Для досрочного погашения: коэффициент = запланированные дни / фактические дни (будет > 1)
     * - Для просрочки: коэффициент = - (фактические дни / запланированные дни) (отрицательный)
     * - Усреднённое значение = среднее всех коэффициентов
     * 
     * Пример:
     * - 9 векселей: запланировано 300 дней, погашено за 100 дней → коэффициент = 300/100 = 3
     * - 1 вексель: запланировано 300 дней, погашено за 600 дней → коэффициент = -600/300 = -2
     * - Усреднённое = (9×3 + 1×(-2)) / 10 = 25/10 = 2.5 ≈ 2
     */
    private function calculateEarlyPaymentAvg(): void
    {
        $db = Database::getConnection();
        
        // Получаем все погашенные вексели
        $stmt = $db->prepare("
            SELECT issue_date, maturity_date, payment_date, 
                   DATEDIFF(maturity_date, issue_date) as planned_days,
                   DATEDIFF(payment_date, issue_date) as actual_days
            FROM bills 
            WHERE issuer_id = ? AND status = 'paid' AND payment_date IS NOT NULL
        ");
        $stmt->execute([$this->userId]);
        
        $coefficients = [];
        while ($row = $stmt->fetch(PDO::FETCH_ASSOC)) {
            $plannedDays = (int)$row['planned_days'];
            $actualDays = (int)$row['actual_days'];
            
            if ($plannedDays > 0) {
                if ($actualDays <= $plannedDays) {
                    // Досрочное погашение: коэффициент = запланированные / фактические (будет >= 1)
                    $coefficient = $plannedDays / max($actualDays, 1);
                } else {
                    // Просрочка: коэффициент = - (фактические / запланированные) (отрицательный)
                    $coefficient = -($actualDays / $plannedDays);
                }
                $coefficients[] = $coefficient;
            }
        }
        
        if (empty($coefficients)) {
            // Если нет погашенных векселей, используем значение по умолчанию 1.0 (без изменений)
            $this->earlyPaymentAvg = 1.0;
        } else {
            // Усреднённое значение коэффициентов
            $this->earlyPaymentAvg = array_sum($coefficients) / count($coefficients);
            // Ограничиваем минимальное значение (не менее 0.01)
            if ($this->earlyPaymentAvg < 0.01) {
                $this->earlyPaymentAvg = 0.01;
            }
        }
    }
    
    /**
     * Сохранить рейтинг
     */
    public function save(): bool
    {
        $db = Database::getConnection();
        $stmt = $db->prepare("
            UPDATE ratings 
            SET balance_score = ?, payment_discipline = ?, early_payment_avg = ?, 
                total_rating = ?, doubles = ?
            WHERE user_id = ?
        ");
        
        $result = $stmt->execute([
            $this->balanceScore,
            $this->paymentDiscipline,
            $this->earlyPaymentAvg,
            $this->totalRating,
            $this->doubles,
            $this->userId
        ]);
        
        if ($result) {
            // Инвалидируем кэш рейтинга
            self::clearCache($this->userId);
        }
        
        return $result;
    }
    
    /**
     * Очистить кэш рейтинга
     */
    private static function clearCache(int $userId): void
    {
        if (class_exists('OGAS\Core\Cache')) {
            Cache::delete("rating:user:{$userId}");
        }
    }
    
    /**
     * Рассчитать дубли для конкретного векселя
     * 
     * ВАЖНО: Номинал векселя может быть любым
     * Дубли для векселя = Номинал векселя × Рейтинг "Око"
     * 
     * Базовый рейтинг = 1.0 для всех пользователей всегда
     * При базовом рейтинге 1.0: 1 рубль = 1 дубль
     * Дубль динамически меняет свою ценность в зависимости от рейтинга пользователя
     * 
     * Примеры:
     * - Вексель 1000 ₽ при рейтинге 1.0 = 1000 дублей (1000 × 1.0)
     * - Вексель 1000 ₽ при рейтинге 1.5 = 1500 дублей (1000 × 1.5)
     * - Вексель 500 ₽ при рейтинге 0.8 = 400 дублей (500 × 0.8)
     * - Вексель 2000 ₽ при рейтинге 2.0 = 4000 дублей (2000 × 2.0)
     * 
     * @param float $billNominal Номинал векселя в рублях (может быть любым)
     * @return float Количество дублей для данного векселя (с учетом рейтинга)
     */
    public function calculateDoublesForBill(float $billNominal): float
    {
        // Используем рейтинг пользователя (минимум 1.0 - базовый рейтинг)
        $rating = max($this->totalRating, 1.0);
        // Дубли = Номинал векселя × Рейтинг "Око"
        return $billNominal * $rating;
    }
    
    /**
     * Получить сумму всех векселей на счету пользователя
     * 
     * @return float Сумма номиналов всех векселей на счету пользователя
     */
    public function getTotalBillsNominal(): float
    {
        $db = Database::getConnection();
        $stmt = $db->prepare("
            SELECT COALESCE(SUM(nominal), 0) as total 
            FROM bills 
            WHERE holder_id = ? 
            AND status IN ('active', 'paid')
        ");
        $stmt->execute([$this->userId]);
        return (float)$stmt->fetch(PDO::FETCH_ASSOC)['total'];
    }
    
    /**
     * Получить базовый рейтинг (всегда 1.0)
     * 
     * @return float Базовый рейтинг
     */
    public static function getBaseRating(): float
    {
        return 1.0;
    }
    
    /**
     * Получить текущий рейтинг пользователя (минимум базовый рейтинг 1.0)
     * 
     * @return float Текущий рейтинг (не менее 1.0)
     */
    public function getEffectiveRating(): float
    {
        return max($this->totalRating, 1.0);
    }
    
    // Getters
    public function getBalanceScore(): float { return $this->balanceScore; }
    public function getPaymentDiscipline(): float { return $this->paymentDiscipline; }
    public function getEarlyPaymentAvg(): float { return $this->earlyPaymentAvg; }
    public function getTotalRating(): float { return $this->totalRating; }
    public function getDoubles(): float { return $this->doubles; }
}

