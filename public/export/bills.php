<?php
/**
 * Экспорт векселей в CSV
 */

require_once __DIR__ . '/../../src/bootstrap.php';

use OGAS\Services\Auth;
use OGAS\Models\Bill;
use OGAS\Models\User;

Auth::requireAuth();
$user = Auth::user();

if (!$user) {
    Auth::logout();
    header('Location: /login.php');
    exit;
}

// Тип экспорта: issued (выпущенные) или held (полученные)
$type = $_GET['type'] ?? 'all';
$statusFilter = $_GET['status'] ?? null;

// Получаем вексели
$bills = [];
if ($type === 'issued' || $type === 'all') {
    $issued = Bill::findByIssuer($user->getId(), $statusFilter);
    $bills = array_merge($bills, $issued);
}

if ($type === 'held' || $type === 'all') {
    $held = Bill::findByHolder($user->getId(), $statusFilter);
    $bills = array_merge($bills, $held);
}

// Убираем дубликаты (если есть)
$bills = array_unique($bills, SORT_REGULAR);

// Сортируем по дате создания
usort($bills, function($a, $b) {
    $dateA = strtotime($a->getIssueDate());
    $dateB = strtotime($b->getIssueDate());
    return $dateB - $dateA;
});

// Заголовки для CSV
$filename = 'bills_' . date('Y-m-d_His') . '.csv';

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
    'Тип',
    'Номинал (₽)',
    'Дата выпуска',
    'Срок погашения',
    'Дата погашения',
    'Статус',
    'Выпустил',
    'Email выпустившего',
    'Держатель',
    'Email держателя'
], ';');

// Данные
foreach ($bills as $bill) {
    $issuer = User::findById($bill->getIssuerId());
    $holder = User::findById($bill->getHolderId());
    
    $statusLabels = [
        'active' => 'Активен',
        'paid' => 'Погашен',
        'overdue' => 'Просрочен',
        'cancelled' => 'Отменён'
    ];
    
    $billType = '';
    if ($bill->getIssuerId() === $user->getId()) {
        $billType = 'Выпущенный мной';
    } elseif ($bill->getHolderId() === $user->getId()) {
        $billType = 'На моём счету';
    }
    
    fputcsv($output, [
        $bill->getId(),
        $billType,
        number_format($bill->getNominal(), 2, '.', ''),
        date('d.m.Y H:i', strtotime($bill->getIssueDate())),
        date('d.m.Y', strtotime($bill->getMaturityDate())),
        $bill->getPaymentDate() ? date('d.m.Y H:i', strtotime($bill->getPaymentDate())) : '',
        $statusLabels[$bill->getStatus()] ?? $bill->getStatus(),
        $issuer ? $issuer->getFullName() : '',
        $issuer ? $issuer->getEmail() : '',
        $holder ? $holder->getFullName() : '',
        $holder ? $holder->getEmail() : ''
    ], ';');
}

fclose($output);
exit;








