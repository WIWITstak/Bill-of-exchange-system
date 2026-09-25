<?php
/**
 * API для получения истории цен товара/услуги
 */

// Включаем буферизацию вывода для предотвращения случайных выводов
ob_start();

// Отключаем вывод ошибок, чтобы не испортить JSON
error_reporting(E_ALL);
ini_set('display_errors', 0);
ini_set('log_errors', 1);

// Подавляем все возможные выводы
set_error_handler(function($errno, $errstr, $errfile, $errline) {
    // Логируем ошибки, но не выводим их
    error_log("PHP Error ($errno): $errstr in $errfile on line $errline");
    return true; // Подавляем стандартный обработчик
});

require_once __DIR__ . '/../../src/bootstrap.php';

// Очищаем все возможные выводы
ob_clean();

use OGAS\Services\Auth;
use OGAS\Database;
use OGAS\Core\Security;
use OGAS\Core\RateLimiter;

// Устанавливаем заголовок JSON до любых выводов
header('Content-Type: application/json; charset=utf-8');

// Rate limiting (100 запросов в минуту с одного IP)
$clientIp = Security::getClientIp();
RateLimiter::requireLimit($clientIp, 100, 60);

Auth::requireAuth();
$user = Auth::user();

if (!$user) {
    ob_clean();
    echo json_encode(['success' => false, 'message' => 'Необходима авторизация'], JSON_UNESCAPED_UNICODE);
    ob_end_flush();
    exit;
}

$productId = (int)($_GET['id'] ?? 0);

if ($productId <= 0) {
    ob_clean();
    echo json_encode(['success' => false, 'message' => 'Неверный ID товара'], JSON_UNESCAPED_UNICODE);
    ob_end_flush();
    exit;
}

try {
    $db = Database::getConnection();
    
    // Получаем текущую начальную цену товара (base_price)
    // Используем COALESCE для обратной совместимости, если base_price еще не добавлен
    $stmt = $db->prepare("SELECT COALESCE(base_price, price) as base_price, price FROM products WHERE id = ?");
    $stmt->execute([$productId]);
    $currentProduct = $stmt->fetch(PDO::FETCH_ASSOC);
    
    if (!$currentProduct) {
        ob_clean();
        echo json_encode(['success' => false, 'message' => 'Товар не найден'], JSON_UNESCAPED_UNICODE);
        ob_end_flush();
        exit;
    }
    
    // Используем base_price (начальная цена), которая не зависит от рейтинга покупателя
    $currentBasePrice = (float)($currentProduct['base_price'] ?? $currentProduct['price']);
    $prices = [];
    
    // Проверяем, существует ли таблица истории цен
    try {
        // Получаем историю начальных цен за последние 30 дней
        // История хранит начальные цены (base_price), которые устанавливает продавец
        $stmt = $db->prepare("
            SELECT price, created_at 
            FROM product_prices_history 
            WHERE product_id = ? 
            AND created_at >= DATE_SUB(NOW(), INTERVAL 30 DAY)
            ORDER BY created_at ASC
        ");
        $stmt->execute([$productId]);
        $history = $stmt->fetchAll(PDO::FETCH_ASSOC);
        
        // Обрабатываем историю начальных цен
        foreach ($history as $item) {
            $prices[] = [
                'price' => (float)$item['price'], // Это начальная цена (base_price)
                'date' => $item['created_at']
            ];
        }
    } catch (PDOException $e) {
        // Если таблица не существует, просто продолжаем с пустой историей
        // Это нормально для новых установок
        $history = [];
    }
    
    // Добавляем текущую начальную цену
    $lastPrice = !empty($prices) ? end($prices)['price'] : null;
    
    if ($lastPrice !== $currentBasePrice || empty($prices)) {
        $prices[] = [
            'price' => $currentBasePrice, // Начальная цена (base_price)
            'date' => date('Y-m-d H:i:s')
        ];
    }
    
    // Очищаем буфер перед отправкой JSON
    ob_clean();
    
    echo json_encode([
        'success' => true,
        'prices' => $prices
    ], JSON_UNESCAPED_UNICODE);
    
} catch (Exception $e) {
    // Логируем ошибку для отладки, но не выводим её пользователю
    error_log('Price history API error: ' . $e->getMessage());
    
    // Очищаем буфер перед отправкой ошибки
    ob_clean();
    
    echo json_encode([
        'success' => false,
        'message' => 'Ошибка получения данных'
    ], JSON_UNESCAPED_UNICODE);
}

// Закрываем буфер и отправляем вывод
ob_end_flush();

