<?php
/**
 * Страница отслеживания подписки пользователя
 */

require_once __DIR__ . '/../src/bootstrap.php';

use OGAS\Services\Auth;
use OGAS\Models\Subscription;
use OGAS\Models\Bill;
use OGAS\Models\User;
use OGAS\Services\SubscriptionService;
use OGAS\Core\Session;

Auth::requireAuth();
$user = Auth::user();

if (!$user) {
    Auth::logout();
    header('Location: /login.php');
    exit;
}

$error = Session::getFlash('error');
$success = Session::getFlash('success');

// Получаем текущую активную подписку
$activeSubscription = Subscription::findActiveByUserId($user->getId());

// Получаем все подписки пользователя (для истории)
$allSubscriptions = Subscription::findByUserId($user->getId());

// Получаем вексель текущей подписки, если есть
$currentBill = null;
if ($activeSubscription && $activeSubscription->getBillId()) {
    $currentBill = Bill::findById($activeSubscription->getBillId());
}

// Рассчитываем сумму следующей подписки
$nextSubscriptionAmount = SubscriptionService::calculateAmount($user);

// Статистика подписок
$stats = [
    'total' => count($allSubscriptions),
    'active' => 0,
    'expired' => 0,
    'total_amount' => 0
];

foreach ($allSubscriptions as $sub) {
    if ($sub->getStatus() === 'active') {
        $stats['active']++;
    } elseif ($sub->getStatus() === 'expired') {
        $stats['expired']++;
    }
    $stats['total_amount'] += $sub->getAmount();
}

$title = 'Моя подписка';
ob_start();
?>
<div class="dashboard">
    <div class="dashboard-header">
        <h2>Моя подписка</h2>
        <div class="header-actions">
            <a href="/dashboard.php" class="btn btn-secondary">← В кабинет</a>
        </div>
    </div>

    <?php if ($error): ?>
        <div class="alert alert-error"><?= htmlspecialchars($error) ?></div>
    <?php endif; ?>

    <?php if ($success): ?>
        <div class="alert alert-success"><?= htmlspecialchars($success) ?></div>
    <?php endif; ?>

    <!-- Статистика подписок -->
    <div class="catalog-info">
        <div class="catalog-stats">
            <div class="stat-item">
                <i class="fas fa-credit-card"></i>
                <span>Всего подписок: <strong><?= $stats['total'] ?></strong></span>
            </div>
            <div class="stat-item">
                <i class="fas fa-check-circle"></i>
                <span>Активных: <strong><?= $stats['active'] ?></strong></span>
            </div>
            <div class="stat-item">
                <i class="fas fa-times-circle"></i>
                <span>Истекших: <strong><?= $stats['expired'] ?></strong></span>
            </div>
            <div class="stat-item">
                <i class="fas fa-coins"></i>
                <span>Общая сумма: <strong><?= number_format($stats['total_amount'], 2, '.', ' ') ?> дублей</strong></span>
            </div>
        </div>
    </div>

    <!-- Текущая подписка -->
    <div class="info-card" style="margin-top: 20px;">
        <h3>Текущая подписка</h3>
        <?php if ($activeSubscription): ?>
            <?php
            $daysRemaining = 0;
            $isExpired = false;
            if ($activeSubscription->getEndDate()) {
                $endTimestamp = strtotime($activeSubscription->getEndDate());
                $nowTimestamp = time();
                $daysRemaining = max(0, floor(($endTimestamp - $nowTimestamp) / 86400));
                $isExpired = $endTimestamp < $nowTimestamp;
            }
            ?>
            <div class="subscription-current">
                <div class="subscription-info-row">
                    <div class="subscription-info-item">
                        <label>Статус:</label>
                        <span class="status status-<?= $activeSubscription->getStatus() ?>">
                            <?php
                            if ($isExpired) {
                                echo 'Истекла';
                            } elseif ($activeSubscription->getStatus() === 'active') {
                                echo 'Активна';
                            } else {
                                echo ucfirst($activeSubscription->getStatus());
                            }
                            ?>
                        </span>
                    </div>
                    <div class="subscription-info-item">
                        <label>Сумма:</label>
                        <strong><?= number_format($activeSubscription->getAmount(), 2, '.', ' ') ?> дублей</strong>
                    </div>
                    <div class="subscription-info-item">
                        <label>Период:</label>
                        <strong><?= $activeSubscription->getPeriodDays() ?> дней</strong>
                    </div>
                </div>
                
                <div class="subscription-info-row">
                    <div class="subscription-info-item">
                        <label>Дата начала:</label>
                        <span><?= date('d.m.Y H:i', strtotime($activeSubscription->getStartDate())) ?></span>
                    </div>
                    <div class="subscription-info-item">
                        <label>Дата окончания:</label>
                        <span><?= date('d.m.Y H:i', strtotime($activeSubscription->getEndDate())) ?></span>
                    </div>
                    <div class="subscription-info-item">
                        <label>Дней осталось:</label>
                        <?php if ($isExpired): ?>
                            <span class="text-danger">Истекла</span>
                        <?php elseif ($daysRemaining <= 7): ?>
                            <span class="text-warning"><?= $daysRemaining ?> дней</span>
                        <?php else: ?>
                            <span class="text-success"><?= $daysRemaining ?> дней</span>
                        <?php endif; ?>
                    </div>
                </div>

                <?php if ($currentBill): ?>
                    <div class="subscription-bill-info" style="margin-top: 20px; padding: 15px; background: #f8f9fa; border-radius: 5px;">
                        <h4>Вексель подписки</h4>
                        <div class="subscription-info-row">
                            <div class="subscription-info-item">
                                <label>Номер векселя:</label>
                                <a href="/bills.php?id=<?= $currentBill->getId() ?>">#<?= $currentBill->getId() ?></a>
                            </div>
                            <div class="subscription-info-item">
                                <label>Номинальная стоимость:</label>
                                <strong><?= number_format($currentBill->getNominal(), 2, '.', ' ') ?> дублей</strong>
                            </div>
                            <div class="subscription-info-item">
                                <label>Статус векселя:</label>
                                <span class="status status-<?= $currentBill->getStatus() ?>">
                                    <?php
                                    $billStatusLabels = [
                                        'active' => 'Активен',
                                        'paid' => 'Погашен',
                                        'overdue' => 'Просрочен'
                                    ];
                                    echo $billStatusLabels[$currentBill->getStatus()] ?? $currentBill->getStatus();
                                    ?>
                                </span>
                            </div>
                        </div>
                        <div class="subscription-info-row">
                            <div class="subscription-info-item">
                                <label>Срок погашения:</label>
                                <span><?= date('d.m.Y', strtotime($currentBill->getMaturityDate())) ?></span>
                            </div>
                            <div class="subscription-info-item">
                                <label>Дата оплаты:</label>
                                <?php if ($currentBill->getPaymentDate()): ?>
                                    <span class="text-success"><?= date('d.m.Y H:i', strtotime($currentBill->getPaymentDate())) ?></span>
                                <?php else: ?>
                                    <span class="text-muted">Не оплачен</span>
                                <?php endif; ?>
                            </div>
                            <div class="subscription-info-item">
                                <?php if ($currentBill->getStatus() === 'active'): ?>
                                    <a href="/bills.php?id=<?= $currentBill->getId() ?>" class="btn btn-primary btn-small">Погасить вексель</a>
                                <?php endif; ?>
                            </div>
                        </div>
                    </div>
                <?php else: ?>
                    <div class="subscription-bill-info" style="margin-top: 20px; padding: 15px; background: #fff3cd; border-radius: 5px; color: #856404;">
                        <strong>⚠️ Вексель не найден</strong>
                        <p>Подписка не связана с векселем. Возможно, вексель был удалён или не был создан.</p>
                    </div>
                <?php endif; ?>

                <?php if ($daysRemaining <= 7 && !$isExpired): ?>
                    <div class="alert alert-warning" style="margin-top: 15px;">
                        <strong>⚠️ Внимание!</strong> Подписка истекает через <?= $daysRemaining ?> <?= $daysRemaining === 1 ? 'день' : ($daysRemaining < 5 ? 'дня' : 'дней') ?>.
                        Вам будет автоматически создан новый вексель на сумму <?= number_format($nextSubscriptionAmount, 2, '.', ' ') ?> дублей.
                    </div>
                <?php elseif ($isExpired): ?>
                    <div class="alert alert-error" style="margin-top: 15px;">
                        <strong>❌ Подписка истекла</strong>
                        <p>Ваша подписка истекла. Для продолжения использования системы вам необходимо продлить подписку. Вам будет автоматически создан новый вексель на сумму <?= number_format($nextSubscriptionAmount, 2, '.', ' ') ?> дублей.</p>
                    </div>
                <?php endif; ?>
            </div>
        <?php else: ?>
            <div class="subscription-current">
                <p class="text-muted">У вас нет активной подписки.</p>
                <?php if (!$user->isActive()): ?>
                    <p>Для активации аккаунта и оформления подписки перейдите в <a href="/activate.php">раздел активации</a>.</p>
                <?php else: ?>
                    <p>Подписка будет автоматически создана при ежемесячном списании.</p>
                <?php endif; ?>
            </div>
        <?php endif; ?>
    </div>

    <!-- История подписок -->
    <div class="info-card" style="margin-top: 20px;">
        <h3>История подписок (<?= count($allSubscriptions) ?>)</h3>
        <?php if (empty($allSubscriptions)): ?>
            <p class="text-muted">История подписок пуста.</p>
        <?php else: ?>
            <table class="subscriptions-table">
                <thead>
                    <tr>
                        <th>Дата начала</th>
                        <th>Дата окончания</th>
                        <th>Сумма</th>
                        <th>Период</th>
                        <th>Статус</th>
                        <th>Вексель</th>
                        <th>Дата оплаты</th>
                    </tr>
                </thead>
                <tbody>
                    <?php foreach ($allSubscriptions as $subscription): ?>
                        <?php
                        $bill = null;
                        if ($subscription->getBillId()) {
                            $bill = Bill::findById($subscription->getBillId());
                        }
                        $isCurrent = $activeSubscription && $activeSubscription->getId() === $subscription->getId();
                        ?>
                        <tr class="<?= $isCurrent ? 'subscription-current-row' : '' ?>">
                            <td><?= date('d.m.Y H:i', strtotime($subscription->getStartDate())) ?></td>
                            <td><?= date('d.m.Y H:i', strtotime($subscription->getEndDate())) ?></td>
                            <td><strong><?= number_format($subscription->getAmount(), 2, '.', ' ') ?> дублей</strong></td>
                            <td><?= $subscription->getPeriodDays() ?> дней</td>
                            <td>
                                <span class="status status-<?= $subscription->getStatus() ?>">
                                    <?php
                                    $statusLabels = [
                                        'active' => 'Активна',
                                        'expired' => 'Истекла',
                                        'cancelled' => 'Отменена'
                                    ];
                                    echo $statusLabels[$subscription->getStatus()] ?? $subscription->getStatus();
                                    ?>
                                </span>
                                <?php if ($isCurrent): ?>
                                    <span class="badge badge-primary">Текущая</span>
                                <?php endif; ?>
                            </td>
                            <td>
                                <?php if ($bill): ?>
                                    <a href="/bills.php?id=<?= $bill->getId() ?>">#<?= $bill->getId() ?></a>
                                    <span class="status status-<?= $bill->getStatus() ?> status-small">
                                        <?php
                                        $billStatusLabels = [
                                            'active' => 'Активен',
                                            'paid' => 'Погашен',
                                            'overdue' => 'Просрочен'
                                        ];
                                        echo $billStatusLabels[$bill->getStatus()] ?? $bill->getStatus();
                                        ?>
                                    </span>
                                <?php else: ?>
                                    <span class="text-muted">—</span>
                                <?php endif; ?>
                            </td>
                            <td>
                                <?php if ($subscription->getPaidAt()): ?>
                                    <span class="text-success"><?= date('d.m.Y H:i', strtotime($subscription->getPaidAt())) ?></span>
                                <?php else: ?>
                                    <span class="text-muted">—</span>
                                <?php endif; ?>
                            </td>
                        </tr>
                    <?php endforeach; ?>
                </tbody>
            </table>
        <?php endif; ?>
    </div>

    <!-- Информация о следующем списании -->
    <?php if ($activeSubscription && !$isExpired): ?>
        <div class="info-card" style="margin-top: 20px; background: #f0f7ff; border-left: 4px solid #667eea;">
            <h3>📅 Следующее списание</h3>
            <p>
                <strong>Дата:</strong> <?= date('d.m.Y', strtotime($activeSubscription->getEndDate())) ?><br>
                <strong>Сумма:</strong> <?= number_format($nextSubscriptionAmount, 2, '.', ' ') ?> дублей<br>
                <strong>Дней до списания:</strong> <?= $daysRemaining ?> дней
            </p>
            <p class="text-muted" style="margin-top: 10px; font-size: 0.9em;">
                Вексель на подписку будет автоматически создан в день окончания текущей подписки.
                Вам будет отправлено уведомление о создании нового векселя.
            </p>
        </div>
    <?php endif; ?>
</div>

<style>
.subscription-stats-grid {
    display: grid;
    grid-template-columns: repeat(auto-fit, minmax(200px, 1fr));
    gap: 15px;
    margin-bottom: 20px;
}

.stat-card {
    background: white;
    padding: 20px;
    border-radius: 8px;
    box-shadow: 0 2px 4px rgba(0,0,0,0.1);
    text-align: center;
}

.stat-card h4 {
    margin: 0 0 10px 0;
    color: #666;
    font-size: 0.9em;
    font-weight: normal;
}

.stat-card .stat-number {
    margin: 0;
    font-size: 2em;
    font-weight: bold;
    color: #667eea;
}

.subscription-current {
    padding: 20px 0;
}

.subscription-info-row {
    display: grid;
    grid-template-columns: repeat(auto-fit, minmax(200px, 1fr));
    gap: 20px;
    margin-bottom: 15px;
}

.subscription-info-item {
    display: flex;
    flex-direction: column;
    gap: 5px;
}

.subscription-info-item label {
    font-size: 0.9em;
    color: #666;
    font-weight: 500;
}

.subscription-info-item strong {
    color: #333;
    font-size: 1.1em;
}

.subscription-bill-info h4 {
    margin: 0 0 15px 0;
    color: #333;
}

.subscriptions-table {
    width: 100%;
    border-collapse: collapse;
    margin-top: 15px;
}

.subscriptions-table th,
.subscriptions-table td {
    padding: 12px;
    text-align: left;
    border-bottom: 1px solid #eee;
}

.subscriptions-table th {
    background: #f8f9fa;
    font-weight: 600;
    color: #333;
}

.subscriptions-table tr:hover {
    background: #f8f9fa;
}

.subscription-current-row {
    background: #f0f7ff !important;
}

.status-small {
    font-size: 0.85em;
    padding: 2px 8px;
    margin-left: 5px;
}

.badge {
    display: inline-block;
    padding: 2px 8px;
    border-radius: 4px;
    font-size: 0.8em;
    margin-left: 5px;
}

.badge-primary {
    background: #667eea;
    color: white;
}

.text-danger {
    color: #dc3545;
}

.text-warning {
    color: #ffc107;
}

.text-success {
    color: #28a745;
}

.text-muted {
    color: #999;
}

@media (max-width: 768px) {
    .subscription-stats-grid {
        grid-template-columns: repeat(2, 1fr);
    }
    
    .subscription-info-row {
        grid-template-columns: 1fr;
    }
    
    .subscriptions-table {
        font-size: 0.9em;
    }
    
    .subscriptions-table th,
    .subscriptions-table td {
        padding: 8px;
    }
}
</style>

<?php
$content = ob_get_clean();
include __DIR__ . '/../templates/base.php';
?>

