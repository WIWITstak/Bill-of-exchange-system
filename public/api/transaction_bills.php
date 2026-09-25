<?php
/**
 * API для получения векселей по транзакции
 */

require_once __DIR__ . '/../../src/bootstrap.php';

use OGAS\Services\Auth;
use OGAS\Models\Transaction;
use OGAS\Models\Bill;
use OGAS\Services\TransactionService;
use OGAS\Core\Security;
use OGAS\Core\RateLimiter;

header('Content-Type: application/json; charset=UTF-8');

// Rate limiting (100 запросов в минуту с одного IP)
$clientIp = Security::getClientIp();
RateLimiter::requireLimit($clientIp, 100, 60);

Auth::requireAuth();
$user = Auth::user();

if (!$user) {
    http_response_code(401);
    echo json_encode(['error' => 'Unauthorized']);
    exit;
}

$transactionId = (int)($_GET['transaction_id'] ?? 0);

if ($transactionId <= 0) {
    http_response_code(400);
    echo json_encode(['error' => 'Transaction ID required']);
    exit;
}

try {
    $transaction = Transaction::findById($transactionId);
    
    if (!$transaction) {
        http_response_code(404);
        echo json_encode(['error' => 'Transaction not found']);
        exit;
    }
    
    // Получаем вексели для транзакции (нужно для проверки доступа)
    $bills = TransactionService::getBillsForTransaction($transactionId);
    
    // Проверяем доступ
    // Разрешаем доступ если:
    // 1. Пользователь - участник транзакции (продавец или покупатель)
    // 2. Пользователь - администратор
    // 3. Транзакция находится в депозитарии (имеет вексели, подтверждена обеими сторонами, статус active/completed)
    $isParticipant = ($transaction->getSellerId() === $user->getId() || 
                      $transaction->getBuyerId() === $user->getId());
    $isAdmin = $user->isAdmin();
    
    // Проверяем, является ли транзакция частью депозитария (публичного реестра)
    $isInDepository = false;
    if (($transaction->getStatus() === 'active' || $transaction->getStatus() === 'completed') &&
        $transaction->isSellerConfirmed() && 
        $transaction->isBuyerConfirmed() &&
        !empty($bills)) {
        $isInDepository = true;
    }
    
    if (!$isParticipant && !$isAdmin && !$isInDepository) {
        http_response_code(403);
        echo json_encode(['error' => 'Access denied']);
        exit;
    }
    
    // Форматируем данные для ответа
    $billsData = [];
    foreach ($bills as $bill) {
        $maturityInfo = '-';
        if ($bill->getMaturityDate()) {
            $maturityTimestamp = strtotime($bill->getMaturityDate());
            $now = time();
            $daysLeft = floor(($maturityTimestamp - $now) / 86400);
            
            if ($daysLeft < 0) {
                $maturityInfo = 'Просрочен (' . abs($daysLeft) . ' дн)';
            } elseif ($daysLeft === 0) {
                $maturityInfo = 'Сегодня';
            } elseif ($daysLeft <= 7) {
                $maturityInfo = 'Через ' . $daysLeft . ' дн';
            } else {
                $maturityInfo = date('d.m.Y', $maturityTimestamp);
            }
        }
        
        $billStatusLabels = [
            'active' => 'Активен',
            'paid' => 'Погашен',
            'overdue' => 'Просрочен',
            'cancelled' => 'Отменён'
        ];
        
        $billsData[] = [
            'id' => $bill->getId(),
            'nominal' => $bill->getNominal(),
            'maturity_date' => $bill->getMaturityDate(),
            'maturity_info' => $maturityInfo,
            'status' => $bill->getStatus(),
            'status_label' => $billStatusLabels[$bill->getStatus()] ?? $bill->getStatus(),
            'issue_date' => $bill->getIssueDate(),
            'pdf_url' => '/api/bill_pdf.php?id=' . $bill->getId()
        ];
    }
    
    echo json_encode([
        'success' => true,
        'transaction' => [
            'id' => $transaction->getId(),
            'description' => $transaction->getDescription(),
            'status' => $transaction->getStatus()
        ],
        'bills' => $billsData
    ]);
    
} catch (\Exception $e) {
    http_response_code(500);
    echo json_encode(['error' => $e->getMessage()]);
}






