<?php
/**
 * API для получения рейтинга пользователя
 */

require_once __DIR__ . '/../../src/bootstrap.php';

use OGAS\Services\Auth;
use OGAS\Models\Rating;

// Проверяем аутентификацию
Auth::requireAuth();

// Разрешаем только GET запросы
if ($_SERVER['REQUEST_METHOD'] !== 'GET') {
    http_response_code(405);
    echo json_encode(['success' => false, 'message' => 'Метод не разрешен']);
    exit;
}

// Получаем ID пользователя из параметров
$userId = (int)($_GET['user_id'] ?? 0);

// Проверяем, что ID пользователя передан
if ($userId <= 0) {
    http_response_code(400);
    echo json_encode(['success' => false, 'message' => 'Не указан ID пользователя']);
    exit;
}

try {
    // Получаем рейтинг пользователя
    $rating = Rating::findByUserId($userId);
    
    if ($rating) {
        echo json_encode([
            'success' => true,
            'rating' => $rating->getTotalRating(),
            'message' => 'Рейтинг успешно получен'
        ]);
    } else {
        http_response_code(404);
        echo json_encode([
            'success' => false,
            'message' => 'Рейтинг пользователя не найден'
        ]);
    }
} catch (Exception $e) {
    http_response_code(500);
    echo json_encode([
        'success' => false,
        'message' => 'Ошибка сервера: ' . $e->getMessage()
    ]);
}