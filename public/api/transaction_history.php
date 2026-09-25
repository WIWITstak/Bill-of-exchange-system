<?php
/**
 * API endpoint для получения истории транзакций с конкретным пользователем
 */

require_once __DIR__ . '/../../src/bootstrap.php';

use OGAS\Services\Auth;
use OGAS\Models\Transaction;
use OGAS\Models\User;
use OGAS\Core\Security;
use OGAS\Core\RateLimiter;

header('Content-Type: application/json; charset=utf-8');

// Rate limiting (100 запросов в минуту с одного IP)
$clientIp = Security::getClientIp();
RateLimiter::requireLimit($clientIp, 100, 60);

Auth::requireAuth();
$user = Auth::user();

if (!$user) {
    echo json_encode(['error' => 'Не авторизован'], JSON_UNESCAPED_UNICODE);
    exit;
}

$buyerId = isset($_GET['buyer_id']) ? (int)$_GET['buyer_id'] : 0;

if ($buyerId <= 0) {
    echo json_encode(['error' => 'Не указан ID покупателя'], JSON_UNESCAPED_UNICODE);
    exit;
}

try {
    // Проверяем что buyerId не равен текущему пользователю
    if ($buyerId === $user->getId()) {
        echo json_encode(['error' => 'Нельзя получить историю с самим собой'], JSON_UNESCAPED_UNICODE);
        exit;
    }
    
    // Получаем транзакции где текущий пользователь продавец, а buyerId - покупатель
    $transactionsAsSeller = Transaction::findBySeller($user->getId());
    // И транзакции где текущий пользователь покупатель, а buyerId - продавец
    $transactionsAsBuyer = Transaction::findByBuyer($user->getId());
    
    // Фильтруем только те, где участвует buyerId
    $relatedTransactions = [];
    foreach ($transactionsAsSeller as $trans) {
        if ($trans && $trans->getBuyerId() === $buyerId) {
            $relatedTransactions[] = $trans;
        }
    }
    foreach ($transactionsAsBuyer as $trans) {
        if ($trans && $trans->getSellerId() === $buyerId) {
            $relatedTransactions[] = $trans;
        }
    }
    
    // Сортируем по дате создания (новые первые)
    usort($relatedTransactions, function($a, $b) {
        $dateA = $a->getCreatedAt() ? strtotime($a->getCreatedAt()) : 0;
        $dateB = $b->getCreatedAt() ? strtotime($b->getCreatedAt()) : 0;
        return $dateB - $dateA;
    });
    
    // Берем только последние 5
    $relatedTransactions = array_slice($relatedTransactions, 0, 5);
    
    $result = [];
    foreach ($relatedTransactions as $trans) {
        $otherUser = $trans->getSellerId() === $user->getId() 
            ? User::findById($trans->getBuyerId())
            : User::findById($trans->getSellerId());
        
        $description = $trans->getDescription() ?? '';
        $result[] = [
            'id' => $trans->getId(),
            'description' => mb_strlen($description) > 100 ? mb_substr($description, 0, 100) . '...' : $description,
            'category' => $trans->getCategory(),
            'status' => $trans->getStatus(),
            'created_at' => $trans->getCreatedAt() ?: date('Y-m-d H:i:s'),
            'role' => $trans->getSellerId() === $user->getId() ? 'seller' : 'buyer',
            'other_user_name' => $otherUser ? $otherUser->getFullName() : 'Пользователь'
        ];
    }
    
    echo json_encode(['transactions' => $result], JSON_UNESCAPED_UNICODE);
} catch (\Exception $e) {
    echo json_encode(['error' => 'Ошибка: ' . $e->getMessage()], JSON_UNESCAPED_UNICODE);
}

