<?php
/**
 * Cron-скрипт для ежемесячного управления подписками
 * 
 * Этот скрипт должен запускаться ежедневно через cron:
 * 0 0 * * * /usr/bin/php /path/to/public/cron/subscriptions.php
 * 
 * Или через планировщик Windows:
 * schtasks /create /tn "OGAS Subscriptions" /tr "php C:\OpenServer\domains\ogas\public\cron\subscriptions.php" /sc daily /st 00:00
 */

require_once __DIR__ . '/../../src/bootstrap.php';

use OGAS\Services\SubscriptionService;

// Защита от прямого доступа через браузер (только CLI)
if (php_sapi_name() !== 'cli') {
    http_response_code(403);
    die('This script can only be run from command line');
}

echo "=== OGAS Subscription Cron Job ===\n";
echo "Date: " . date('Y-m-d H:i:s') . "\n\n";

// 1. Проверяем истекшие подписки
echo "1. Checking expired subscriptions...\n";
$expiredCount = SubscriptionService::checkExpiredSubscriptions();
echo "   Processed {$expiredCount} expired subscriptions\n\n";

// 2. Отправляем напоминания о подписке (за 7 дней до истечения)
echo "2. Sending renewal reminders...\n";
$remindersCount = SubscriptionService::sendRenewalReminders(7);
echo "   Sent {$remindersCount} reminders\n\n";

// 3. Ежемесячное списание (создание векселей для подписок, истекающих сегодня)
// Вексели создаются автоматически при истечении подписки (в checkExpiredSubscriptions)
// Этот метод создаёт вексели для подписок, которые истекают в ближайшие дни (заранее)
echo "3. Monthly billing (creating bills for subscriptions expiring soon)...\n";
$billingResults = SubscriptionService::monthlyBilling();
echo "   Success: {$billingResults['success']}\n";
echo "   Failed: {$billingResults['failed']}\n";
if (!empty($billingResults['errors'])) {
    echo "   Errors:\n";
    foreach ($billingResults['errors'] as $error) {
        echo "     - User ID {$error['user_id']}: {$error['error']}\n";
    }
}
echo "\n";

// 4. Статистика
echo "4. Subscription statistics:\n";
$stats = SubscriptionService::getStatistics();
echo "   Total subscriptions: {$stats['total']}\n";
echo "   Active: {$stats['active']}\n";
echo "   Expired: {$stats['expired']}\n";
echo "   Expiring soon (7 days): {$stats['expiring_soon']}\n\n";

echo "=== Cron job completed ===\n";

