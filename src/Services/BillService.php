<?php

namespace OGAS\Services;

use OGAS\Models\Bill;
use OGAS\Models\Rating;
use OGAS\Services\NotificationService;
use OGAS\Services\SubscriptionService;
use OGAS\Services\BillPdfService;

/**
 * Сервис для работы с векселями
 */
class BillService
{
    /**
     * Выпустить вексель (при продаже товара/услуги)
     */
    public static function issue(int $issuerId, int $holderId, float $nominal, int $maturityDays): Bill
    {
        // Проверяем, что эмитент и держатель не являются одним и тем же пользователем
        if ($issuerId === $holderId) {
            throw new \InvalidArgumentException('Эмитент и держатель векселя не могут быть одним и тем же пользователем');
        }
        
        // Проверяем валидность суммы
        if ($nominal <= 0) {
            throw new \InvalidArgumentException('Номинал векселя должен быть больше нуля');
        }
        
        // Проверяем валидность срока погашения
        if ($maturityDays < 1 || $maturityDays > 3650) { // Максимум 10 лет
            throw new \InvalidArgumentException('Срок погашения должен быть от 1 до 3650 дней');
        }
        
        $maturityDate = date('Y-m-d H:i:s', strtotime("+{$maturityDays} days"));
        
        $bill = Bill::create([
            'issuer_id' => $issuerId,
            'holder_id' => $holderId,
            'nominal' => $nominal,
            'maturity_date' => $maturityDate
        ]);
        
        // Обновляем рейтинг после выпуска векселя
        $rating = Rating::findByUserId($issuerId);
        $rating->recalculate();
        
        // Создаем уведомление для получателя векселя
        NotificationService::notifyBillReceived($holderId, $bill->getId());
        
        // Генерируем PDF для печати (в фоновом режиме, чтобы не блокировать)
        try {
            BillPdfService::savePdf($bill);
        } catch (\Exception $e) {
            // Логируем ошибку, но не прерываем процесс
            error_log('Error generating PDF for bill #' . $bill->getId() . ': ' . $e->getMessage());
        }
        
        return $bill;
    }
    
    /**
     * Погасить вексель
     */
    public static function pay(int $billId): bool
    {
        $bill = Bill::findById($billId);
        
        if (!$bill || $bill->getStatus() !== 'active') {
            return false;
        }
        
        if ($bill->pay()) {
            // Обновляем рейтинг после погашения
            $rating = Rating::findByUserId($bill->getIssuerId());
            $rating->recalculate();
            
            // Проверяем, связан ли вексель с подпиской
            SubscriptionService::processPayment($bill->getId());
            
            // Создаем уведомления о погашении
            NotificationService::notifyBillPaid($bill->getIssuerId(), $bill->getId());
            NotificationService::notifyBillPaid($bill->getHolderId(), $bill->getId());
            
            return true;
        }
        
        return false;
    }
    
    /**
     * Обновить статусы просроченных векселей
     */
    public static function updateOverdueBills(int $userId): void
    {
        $bills = Bill::findByIssuer($userId, 'active');
        
        foreach ($bills as $bill) {
            $bill->updateOverdueStatus();
        }
        
        // Пересчитываем рейтинг после обновления статусов
        $rating = Rating::findByUserId($userId);
        $rating->recalculate();
    }
    
    /**
     * Получить статистику по векселям пользователя
     */
    public static function getStatistics(int $userId): array
    {
        $issued = Bill::findByIssuer($userId);
        $held = Bill::findByHolder($userId);
        
        $totalIssued = array_sum(array_map(fn($b) => $b->getNominal(), $issued));
        $totalHeld = array_sum(array_map(fn($b) => $b->getNominal(), $held));
        
        $activeIssued = array_filter($issued, fn($b) => $b->getStatus() === 'active');
        $activeHeld = array_filter($held, fn($b) => $b->getStatus() === 'active');
        
        $activeIssuedTotal = array_sum(array_map(fn($b) => $b->getNominal(), $activeIssued));
        $activeHeldTotal = array_sum(array_map(fn($b) => $b->getNominal(), $activeHeld));
        
        return [
            'issued_count' => count($issued),
            'issued_total' => $totalIssued,
            'active_issued_count' => count($activeIssued),
            'active_issued_total' => $activeIssuedTotal,
            'held_count' => count($held),
            'held_total' => $totalHeld,
            'active_held_count' => count($activeHeld),
            'active_held_total' => $activeHeldTotal
        ];
    }
}

