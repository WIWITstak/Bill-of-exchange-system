<?php
/**
 * API endpoint для поиска категорий (автокомплит)
 */

require_once __DIR__ . '/../../src/bootstrap.php';

use OGAS\Models\Category;
use OGAS\Core\Security;
use OGAS\Core\RateLimiter;

header('Content-Type: application/json; charset=utf-8');

// Rate limiting (200 запросов в минуту с одного IP для автокомплита)
$clientIp = Security::getClientIp();
RateLimiter::requireLimit($clientIp, 200, 60);

$query = trim($_GET['q'] ?? '');

try {
    if (empty($query)) {
        // Если запрос пустой, возвращаем популярные категории
        $categories = Category::getAllActive(0);
        $categories = array_slice($categories, 0, 10);
    } else {
        // Поиск категорий
        $categories = Category::search($query, 10);
    }
    
    $result = [];
    foreach ($categories as $category) {
        $result[] = [
            'id' => $category->getId(),
            'name' => $category->getName(),
            'icon' => $category->getIcon(),
            'description' => $category->getDescription()
        ];
    }
    
    echo json_encode(['categories' => $result], JSON_UNESCAPED_UNICODE);
} catch (\Exception $e) {
    echo json_encode(['error' => 'Ошибка при поиске: ' . $e->getMessage()], JSON_UNESCAPED_UNICODE);
}








