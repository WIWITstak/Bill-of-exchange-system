<?php
/**
 * API для переключения статуса доступности товара/услуги
 */

require_once __DIR__ . '/../../src/bootstrap.php';

use OGAS\Services\Auth;
use OGAS\Models\Product;

header('Content-Type: application/json');

Auth::requireAuth();
$user = Auth::user();

if (!$user) {
    echo json_encode(['success' => false, 'message' => 'Необходима авторизация']);
    exit;
}

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    echo json_encode(['success' => false, 'message' => 'Неверный метод запроса']);
    exit;
}

$productId = (int)($_POST['id'] ?? 0);
$isAvailable = isset($_POST['is_available']) && $_POST['is_available'] === '1';

$product = Product::findById($productId);

if (!$product) {
    echo json_encode(['success' => false, 'message' => 'Товар/услуга не найден(а)']);
    exit;
}

if ($product->getUserId() !== $user->getId()) {
    echo json_encode(['success' => false, 'message' => 'У вас нет доступа к этому товару/услуге']);
    exit;
}

$product->setIsAvailable($isAvailable);

if ($product->save()) {
    echo json_encode(['success' => true, 'message' => 'Статус успешно изменен']);
} else {
    echo json_encode(['success' => false, 'message' => 'Ошибка при изменении статуса']);
}

