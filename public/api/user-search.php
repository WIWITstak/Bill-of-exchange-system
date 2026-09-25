<?php
/**
 * API endpoint для поиска пользователей
 */

require_once __DIR__ . '/../../src/bootstrap.php';

use OGAS\Models\User;
use OGAS\Models\Rating;
use OGAS\Models\Company;
use OGAS\Services\Auth;
use OGAS\Database;
use OGAS\Core\Security;
use OGAS\Core\RateLimiter;
use PDO;

header('Content-Type: application/json; charset=utf-8');

// Rate limiting (100 запросов в минуту с одного IP)
$clientIp = Security::getClientIp();
RateLimiter::requireLimit($clientIp, 100, 60);

$query = trim($_GET['q'] ?? '');
$currentUserId = Auth::check() ? Auth::user()->getId() : 0;

// Фильтры
$userType = $_GET['user_type'] ?? ''; // 'individual', 'legal' или пусто
$minRating = isset($_GET['min_rating']) ? (float)$_GET['min_rating'] : 0;
$onlyActive = isset($_GET['only_active']) ? (bool)$_GET['only_active'] : true;
$sortBy = $_GET['sort_by'] ?? 'name'; // 'name', 'rating', 'transactions'
$limit = isset($_GET['limit']) ? (int)$_GET['limit'] : 20;

// Если запрос - это числовой ID, ищем конкретного пользователя
if (is_numeric($query) && (int)$query > 0 && strlen($query) < 10) {
    $userId = (int)$query;
    $user = User::findById($userId);
    
    if ($user && $user->getId() !== $currentUserId && !$user->isSystem()) {
        $rating = Rating::findByUserId($user->getId());
        $ratingValue = $rating ? $rating->getTotalRating() : 0;
        
        // Подсчет транзакций
        $transactionCount = 0;
        if ($currentUserId > 0) {
            $db = Database::getConnection();
            $stmt = $db->prepare("
                SELECT COUNT(*) as cnt 
                FROM transactions 
                WHERE (seller_id = ? AND buyer_id = ?) OR (buyer_id = ? AND seller_id = ?)
            ");
            $stmt->execute([$currentUserId, $userId, $currentUserId, $userId]);
            $result = $stmt->fetch(PDO::FETCH_ASSOC);
            $transactionCount = (int)($result['cnt'] ?? 0);
        }
        
        $companyName = null;
        if ($user->getUserType() === 'legal') {
            $company = Company::findByUserId($userId);
            $companyName = $company ? $company->getName() : null;
        }
        
        $result = [[
            'id' => $user->getId(),
            'full_name' => $user->getFullName(),
            'email' => $user->getEmail(),
            'user_type' => $user->getUserType(),
            'is_active' => $user->isActive(),
            'avatar_url' => $user->getAvatarUrl(),
            'initials' => $user->getInitials(),
            'rating' => round($ratingValue, 2),
            'transaction_count' => $transactionCount,
            'company_name' => $companyName
        ]];
        
        echo json_encode(['users' => $result], JSON_UNESCAPED_UNICODE);
        exit;
    }
}

try {
    $db = Database::getConnection();
    
    // Строим запрос с фильтрами
    $whereConditions = ['u.is_system = 0'];
    $params = [];
    
    // Поиск по имени или email
    if (!empty($query)) {
        $whereConditions[] = '(u.full_name LIKE ? OR u.email LIKE ?)';
        $searchTerm = '%' . $query . '%';
        $params[] = $searchTerm;
        $params[] = $searchTerm;
    }
    
    // Фильтр по типу пользователя
    if (!empty($userType) && in_array($userType, ['individual', 'legal'])) {
        $whereConditions[] = 'u.user_type = ?';
        $params[] = $userType;
    }
    
    // Фильтр по активности
    if ($onlyActive) {
        $whereConditions[] = 'u.is_active = 1';
    }
    
    $whereClause = implode(' AND ', $whereConditions);
    
    // Подсчет транзакций с текущим пользователем
    if ($currentUserId > 0) {
        $transactionCountSubquery = "(SELECT COUNT(*) FROM transactions t WHERE ((t.seller_id = u.id AND t.buyer_id = ?) OR (t.buyer_id = u.id AND t.seller_id = ?))) as transaction_count";
    } else {
        $transactionCountSubquery = "0 as transaction_count";
    }
    
    // Запрос с подсчетом транзакций и рейтинга
    $sql = "
        SELECT 
            u.*,
            COALESCE(r.total_rating, 0) as rating,
            $transactionCountSubquery
        FROM users u
        LEFT JOIN ratings r ON u.id = r.user_id
        WHERE $whereClause
    ";
    
    // Добавляем параметры для подсчета транзакций в начало
    $finalParams = [];
    if ($currentUserId > 0) {
        $finalParams[] = $currentUserId;
        $finalParams[] = $currentUserId;
    }
    $finalParams = array_merge($finalParams, $params);
    $params = $finalParams;
    
    // Сортировка
    switch ($sortBy) {
        case 'rating':
            $sql .= " ORDER BY rating DESC, u.full_name ASC";
            break;
        case 'transactions':
            $sql .= " ORDER BY transaction_count DESC, u.full_name ASC";
            break;
        default:
            $sql .= " ORDER BY u.full_name ASC";
    }
    
    $sql .= " LIMIT ?";
    $params[] = $limit;
    
    $stmt = $db->prepare($sql);
    $stmt->execute($params);
    
    $result = [];
    while ($data = $stmt->fetch(PDO::FETCH_ASSOC)) {
        $userId = (int)$data['id'];
        
        // Пропускаем текущего пользователя
        if ($userId === $currentUserId) {
            continue;
        }
        
        $user = User::fromArray($data);
        $ratingValue = (float)($data['rating'] ?? 0);
        $transactionCount = (int)($data['transaction_count'] ?? 0);
        
        // Фильтр по минимальному рейтингу
        if ($minRating > 0 && $ratingValue < $minRating) {
            continue;
        }
        
        // Получаем информацию о компании для юридических лиц
        $companyName = null;
        if ($user->getUserType() === 'legal') {
            $company = Company::findByUserId($userId);
            $companyName = $company ? $company->getName() : null;
        }
        
        $result[] = [
            'id' => $userId,
            'full_name' => $user->getFullName(),
            'email' => $user->getEmail(),
            'user_type' => $user->getUserType(),
            'is_active' => $user->isActive(),
            'avatar_url' => $user->getAvatarUrl(),
            'initials' => $user->getInitials(),
            'rating' => round($ratingValue, 2),
            'transaction_count' => $transactionCount,
            'company_name' => $companyName
        ];
    }
    
    echo json_encode(['users' => $result], JSON_UNESCAPED_UNICODE);
} catch (\Exception $e) {
    echo json_encode(['error' => 'Ошибка при поиске: ' . $e->getMessage()], JSON_UNESCAPED_UNICODE);
}








