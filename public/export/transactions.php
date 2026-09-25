<?php
/**
 * Экспорт транзакций в CSV
 */

require_once __DIR__ . '/../../src/bootstrap.php';

use OGAS\Services\Auth;
use OGAS\Models\Transaction;
use OGAS\Models\User;
use OGAS\Models\Category;

Auth::requireAuth();
$user = Auth::user();

if (!$user) {
    Auth::logout();
    header('Location: /login.php');
    exit;
}

// Получаем транзакции
$allTransactions = Transaction::findByUser($user->getId());

// Фильтры (опционально)
$statusFilter = $_GET['status'] ?? null;
$categoryFilter = $_GET['category'] ?? null;

$transactions = $allTransactions;

// Фильтруем по статусу
if ($statusFilter) {
    $transactions = array_filter($transactions, function($t) use ($statusFilter) {
        return $t->getStatus() === $statusFilter;
    });
}

// Фильтруем по категории
if ($categoryFilter) {
    $transactions = array_filter($transactions, function($t) use ($categoryFilter) {
        return $t->getCategory() === $categoryFilter;
    });
}

// Заголовки для CSV
$filename = 'transactions_' . date('Y-m-d_His') . '.csv';

header('Content-Type: text/csv; charset=utf-8');
header('Content-Disposition: attachment; filename="' . $filename . '"');
header('Pragma: no-cache');
header('Expires: 0');

// Открываем output stream
$output = fopen('php://output', 'w');

// Добавляем BOM для корректного отображения кириллицы в Excel
fprintf($output, chr(0xEF).chr(0xBB).chr(0xBF));

// Заголовки CSV
fputcsv($output, [
    'ID',
    'Дата создания',
    'Статус',
    'Тип транзакции',
    'Категория',
    'Описание',
    'Продавец',
    'Email продавца',
    'Покупатель',
    'Email покупателя',
    'Роль'
], ';');

// Данные
foreach ($transactions as $transaction) {
    $seller = User::findById($transaction->getSellerId());
    $buyer = User::findById($transaction->getBuyerId());
    
    $statusLabels = [
        'pending' => 'Ожидает',
        'active' => 'Активна',
        'completed' => 'Завершена',
        'cancelled' => 'Отменена'
    ];
    
    $typeLabels = [
        'barter' => 'Бартер',
        'guarantee' => 'Поручительство',
        'community' => 'Община',
        'mixed' => 'Смешанная'
    ];
    
    $role = '';
    if ($transaction->getSellerId() === $user->getId()) {
        $role = 'Продавец';
    } elseif ($transaction->getBuyerId() === $user->getId()) {
        $role = 'Покупатель';
    }
    
    $category = $transaction->getCategory();
    if ($category) {
        $cat = Category::findByName($category);
        if ($cat) {
            $category = $cat->getName();
        }
    }
    
    fputcsv($output, [
        $transaction->getId(),
        $transaction->getCreatedAt() ? date('d.m.Y H:i', strtotime($transaction->getCreatedAt())) : '',
        $statusLabels[$transaction->getStatus()] ?? $transaction->getStatus(),
        $typeLabels[$transaction->getTransactionType()] ?? $transaction->getTransactionType(),
        $category ?: '',
        $transaction->getDescription() ?: '',
        $seller ? $seller->getFullName() : '',
        $seller ? $seller->getEmail() : '',
        $buyer ? $buyer->getFullName() : '',
        $buyer ? $buyer->getEmail() : '',
        $role
    ], ';');
}

fclose($output);
exit;








