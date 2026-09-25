<?php
/**
 * API для массового скачивания PDF векселей (ZIP архив)
 */

// Отключаем вывод ошибок и включаем буферизацию вывода
error_reporting(E_ALL);
ini_set('display_errors', '0');

// Включаем буферизацию вывода ДО загрузки bootstrap
if (!ob_get_level()) {
    ob_start();
}

// Загружаем bootstrap
require_once __DIR__ . '/../../src/bootstrap.php';

// Очищаем весь вывод (включая предупреждения), который мог появиться
ob_end_clean();

use OGAS\Services\Auth;
use OGAS\Models\Bill;
use OGAS\Services\BillPdfService;
use OGAS\Core\Security;
use OGAS\Core\RateLimiter;

// ZipArchive - встроенный класс PHP, не требует импорта

// Rate limiting (10 запросов в минуту для массовой генерации PDF)
$clientIp = Security::getClientIp();
RateLimiter::requireLimit($clientIp, 10, 60);

Auth::requireAuth();
$user = Auth::user();

if (!$user) {
    http_response_code(401);
    die('Unauthorized');
}

// Получаем массив ID векселей
$billIds = $_POST['bill_ids'] ?? $_GET['bill_ids'] ?? [];

if (empty($billIds)) {
    http_response_code(400);
    die('No bills selected');
}

// Если передан строкой через GET, парсим
if (is_string($billIds)) {
    $billIds = explode(',', $billIds);
}

// Преобразуем в массив целых чисел
$billIds = array_map('intval', $billIds);
$billIds = array_filter($billIds, function($id) { return $id > 0; });

if (empty($billIds)) {
    http_response_code(400);
    die('Invalid bill IDs');
}

// Ограничиваем количество для безопасности
if (count($billIds) > 100) {
    http_response_code(400);
    die('Too many bills selected (max 100)');
}

try {
    // Получаем вексели и проверяем доступ
    $bills = [];
    foreach ($billIds as $billId) {
        $bill = Bill::findById($billId);
        
        if (!$bill) {
            continue;
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
            continue; // Пропускаем вексели без доступа
        }
        
        $bills[] = $bill;
    }
    
    if (empty($bills)) {
        http_response_code(404);
        die('No accessible bills found');
    }
    
    // Создаём временную директорию для архива
    $tempDir = sys_get_temp_dir() . '/bills_zip_' . uniqid();
    if (!mkdir($tempDir, 0755, true)) {
        throw new \Exception('Failed to create temp directory');
    }
    
    // Генерируем PDF для каждого векселя
    $pdfFiles = [];
    foreach ($bills as $bill) {
        // Проверяем, есть ли уже PDF
        $pdfPath = BillPdfService::getPdfPath($bill);
        
        if (!file_exists($pdfPath)) {
            // Генерируем PDF, если его ещё нет
            $pdfPath = BillPdfService::savePdf($bill);
        }
        
        if (file_exists($pdfPath)) {
            // Копируем файл во временную директорию с понятным именем
            $filename = 'Вексель_' . $bill->getId() . '.pdf';
            $tempPdfPath = $tempDir . '/' . $filename;
            copy($pdfPath, $tempPdfPath);
            $pdfFiles[] = $tempPdfPath;
        }
    }
    
    if (empty($pdfFiles)) {
        // Удаляем временную директорию
        array_map('unlink', glob($tempDir . '/*'));
        rmdir($tempDir);
        http_response_code(500);
        die('Failed to generate PDFs');
    }
    
    // Создаём ZIP архив
    $zipFilename = 'Вексели_' . date('Y-m-d_His') . '.zip';
    $zipPath = $tempDir . '/' . $zipFilename;
    
    $zip = new ZipArchive();
    if ($zip->open($zipPath, ZipArchive::CREATE | ZipArchive::OVERWRITE) !== TRUE) {
        // Удаляем временные файлы
        array_map('unlink', $pdfFiles);
        array_map('unlink', glob($tempDir . '/*'));
        rmdir($tempDir);
        throw new \Exception('Failed to create ZIP archive');
    }
    
    // Добавляем PDF файлы в архив
    foreach ($pdfFiles as $pdfFile) {
        $zip->addFile($pdfFile, basename($pdfFile));
    }
    
    $zip->close();
    
    // Удаляем временные PDF файлы
    array_map('unlink', $pdfFiles);
    
    // Отправляем ZIP архив
    // Убеждаемся, что нет активного буфера вывода
    while (ob_get_level()) {
        ob_end_clean();
    }
    
    // Устанавливаем заголовки для ZIP файла
    header('Content-Type: application/zip');
    header('Content-Disposition: attachment; filename="' . $zipFilename . '"');
    header('Content-Length: ' . filesize($zipPath));
    header('Cache-Control: private, max-age=0, must-revalidate');
    header('Pragma: public');
    
    readfile($zipPath);
    
    // Удаляем ZIP файл и временную директорию
    unlink($zipPath);
    rmdir($tempDir);
    
    exit;
    
} catch (\Exception $e) {
    http_response_code(500);
    error_log('Error creating bulk PDF ZIP: ' . $e->getMessage());
    die('Error: ' . $e->getMessage());
}

