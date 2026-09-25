<?php
/**
 * API для генерации и скачивания PDF векселей
 */

require_once __DIR__ . '/../../src/bootstrap.php';

use OGAS\Services\Auth;
use OGAS\Models\Bill;
use OGAS\Services\BillPdfService;
use OGAS\Core\Security;
use OGAS\Core\RateLimiter;

// Rate limiting (30 запросов в минуту для генерации PDF)
$clientIp = Security::getClientIp();
RateLimiter::requireLimit($clientIp, 30, 60);

Auth::requireAuth();
$user = Auth::user();

if (!$user) {
    http_response_code(401);
    die('Unauthorized');
}

$billId = (int)($_GET['id'] ?? 0);

if ($billId === 0) {
    http_response_code(400);
    die('Invalid bill ID');
}

$bill = Bill::findById($billId);

if (!$bill) {
    http_response_code(404);
    die('Bill not found');
}

// Проверяем доступ
// Разрешаем доступ если:
// 1. Пользователь - векселедатель или векселедержатель
// 2. Пользователь - администратор
// 3. Вексель находится в депозитарии (связан с транзакцией, которая подтверждена и активна/завершена)
$isParticipant = ($bill->getIssuerId() === $user->getId() || $bill->getHolderId() === $user->getId());
$isAdmin = $user->isAdmin();

// Проверяем, является ли вексель частью депозитария (публичного реестра)
$isInDepository = false;
if (!$isParticipant && !$isAdmin) {
    // Ищем связанную транзакцию в депозитарии (публичном реестре)
    $db = \OGAS\Database::getConnection();
    $stmt = $db->prepare("
        SELECT t.* 
        FROM transactions t
        WHERE (
            (t.seller_id = ? AND t.buyer_id = ?) OR
            (t.seller_id = ? AND t.buyer_id = ?)
        )
        AND (t.status = 'active' OR t.status = 'completed')
        AND t.seller_confirmed = 1 
        AND t.buyer_confirmed = 1
        AND t.created_at <= ?
        LIMIT 1
    ");
    $billIssueDate = $bill->getIssueDate() ?? date('Y-m-d H:i:s');
    $stmt->execute([
        $bill->getIssuerId(),
        $bill->getHolderId(),
        $bill->getHolderId(),
        $bill->getIssuerId(),
        $billIssueDate
    ]);
    
    $transactionData = $stmt->fetch(\PDO::FETCH_ASSOC);
    if ($transactionData) {
        $isInDepository = true;
    }
}

if (!$isParticipant && !$isAdmin && !$isInDepository) {
    http_response_code(403);
    die('Access denied');
}

try {
    // Проверяем, есть ли сохранённый путь к файлу в базе данных
    $pdfPath = null;
    if ($bill->getFilePath()) {
        // Используем путь из базы данных (относительный путь от корня проекта)
        $pdfPath = __DIR__ . '/../../' . $bill->getFilePath();
    }
    
    // Если пути нет или файл не существует, генерируем PDF
    if (!$pdfPath || !file_exists($pdfPath)) {
        // Генерируем PDF и сохраняем путь в базу данных
        $pdfPath = BillPdfService::savePdf($bill);
    }
    
    // Проверяем, что файл существует
    if (!file_exists($pdfPath)) {
        http_response_code(500);
        die('Error generating PDF');
    }
    
    // Отправляем файл
    $filename = 'Вексель_' . $bill->getId() . '.pdf';
    
    header('Content-Type: application/pdf');
    header('Content-Disposition: attachment; filename="' . $filename . '"');
    header('Content-Length: ' . filesize($pdfPath));
    header('Cache-Control: private, max-age=0, must-revalidate');
    header('Pragma: public');
    
    readfile($pdfPath);
    exit;
    
} catch (\Exception $e) {
    http_response_code(500);
    error_log('Error generating PDF for bill #' . $billId . ': ' . $e->getMessage());
    die('Error: ' . $e->getMessage());
}

