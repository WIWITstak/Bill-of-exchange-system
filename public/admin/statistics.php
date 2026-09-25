<?php
/**
 * Страница детальной статистики системы (админ-панель)
 */

require_once __DIR__ . '/../../src/bootstrap.php';

use OGAS\Services\Auth;
use OGAS\Services\AdminService;
use OGAS\Services\SubscriptionService;

Auth::requireAuth();
AdminService::requireAdmin();

// Получаем общую статистику
$stats = AdminService::getSystemStatistics();
$subscriptionStats = SubscriptionService::getStatistics();

// Дополнительная статистика
$db = \OGAS\Database::getConnection();

// Статистика по пользователям
$stmt = $db->query("
    SELECT user_type, COUNT(*) as count 
    FROM users 
    WHERE is_system = 0 
    GROUP BY user_type
");
$userTypeStats = [];
while ($row = $stmt->fetch(\PDO::FETCH_ASSOC)) {
    $userTypeStats[$row['user_type']] = (int)$row['count'];
}

// Статистика по векселям за последние 30 дней
$stmt = $db->query("
    SELECT DATE(issue_date) as date, COUNT(*) as count, SUM(nominal) as total
    FROM bills
    WHERE issue_date >= DATE_SUB(NOW(), INTERVAL 30 DAY)
    GROUP BY DATE(issue_date)
    ORDER BY date ASC
");
$billsByDate = [];
while ($row = $stmt->fetch(\PDO::FETCH_ASSOC)) {
    $billsByDate[] = [
        'date' => $row['date'],
        'count' => (int)$row['count'],
        'total' => (float)$row['total']
    ];
}

// Статистика по транзакциям за последние 30 дней
$stmt = $db->query("
    SELECT DATE(created_at) as date, COUNT(*) as count
    FROM transactions
    WHERE created_at >= DATE_SUB(NOW(), INTERVAL 30 DAY)
    GROUP BY DATE(created_at)
    ORDER BY date ASC
");
$transactionsByDate = [];
while ($row = $stmt->fetch(\PDO::FETCH_ASSOC)) {
    $transactionsByDate[] = [
        'date' => $row['date'],
        'count' => (int)$row['count']
    ];
}

$title = 'Статистика системы - Админ-панель';
ob_start();
?>
<div class="dashboard">
    <div class="dashboard-header">
        <h2>Детальная статистика системы</h2>
        <div class="header-actions">
            <a href="/admin/index.php" class="btn btn-secondary">← Назад</a>
        </div>
    </div>

    <!-- Общая статистика -->
    <div class="info-card">
        <h3>Общая статистика</h3>
        <div class="stats-grid" style="display: grid; grid-template-columns: repeat(auto-fit, minmax(250px, 1fr)); gap: 20px; margin-top: 20px;">
            <div class="stat-card">
                <h4>Пользователи</h4>
                <p class="stat-number"><?= $stats['users']['total'] ?></p>
                <p class="stat-detail">Всего</p>
                <p class="stat-detail">Активных: <?= $stats['users']['active'] ?></p>
                <p class="stat-detail">Администраторов: <?= $stats['users']['admins'] ?></p>
            </div>
            
            <div class="stat-card">
                <h4>Вексели</h4>
                <p class="stat-number"><?= $stats['bills']['total'] ?></p>
                <p class="stat-detail">Всего</p>
                <p class="stat-detail">Активных: <?= $stats['bills']['active'] ?></p>
                <p class="stat-detail">Сумма активных: <?= number_format($stats['bills']['active_total'], 2) ?> ₽</p>
            </div>
            
            <div class="stat-card">
                <h4>Транзакции</h4>
                <p class="stat-number"><?= $stats['transactions']['total'] ?></p>
                <p class="stat-detail">Всего</p>
                <p class="stat-detail">Активных: <?= $stats['transactions']['active'] ?></p>
                <p class="stat-detail">Завершённых: <?= $stats['transactions']['completed'] ?></p>
            </div>
            
            <div class="stat-card">
                <h4>Подписки</h4>
                <p class="stat-number"><?= $subscriptionStats['total'] ?></p>
                <p class="stat-detail">Всего</p>
                <p class="stat-detail">Активных: <?= $subscriptionStats['active'] ?></p>
                <p class="stat-detail">Истекающих (7 дней): <?= $subscriptionStats['expiring_soon'] ?></p>
            </div>
        </div>
    </div>

    <!-- Статистика по типам пользователей -->
    <div class="info-card">
        <h3>Распределение пользователей по типам</h3>
        <div style="display: grid; grid-template-columns: repeat(auto-fit, minmax(200px, 1fr)); gap: 15px; margin-top: 15px;">
            <div>
                <strong>Физические лица:</strong> <?= $userTypeStats['individual'] ?? 0 ?>
            </div>
            <div>
                <strong>Юридические лица:</strong> <?= $userTypeStats['legal'] ?? 0 ?>
            </div>
        </div>
    </div>

    <!-- Статистика по датам (вексели) -->
    <?php if (!empty($billsByDate)): ?>
        <div class="info-card">
            <h3>Вексели за последние 30 дней</h3>
            <table class="transactions-table">
                <thead>
                    <tr>
                        <th>Дата</th>
                        <th>Количество</th>
                        <th>Сумма</th>
                    </tr>
                </thead>
                <tbody>
                    <?php foreach (array_slice($billsByDate, -10) as $data): ?>
                        <tr>
                            <td><?= date('d.m.Y', strtotime($data['date'])) ?></td>
                            <td><?= $data['count'] ?></td>
                            <td><?= number_format($data['total'], 2) ?> ₽</td>
                        </tr>
                    <?php endforeach; ?>
                </tbody>
            </table>
        </div>
    <?php endif; ?>

    <!-- Статистика по датам (транзакции) -->
    <?php if (!empty($transactionsByDate)): ?>
        <div class="info-card">
            <h3>Транзакции за последние 30 дней</h3>
            <table class="transactions-table">
                <thead>
                    <tr>
                        <th>Дата</th>
                        <th>Количество</th>
                    </tr>
                </thead>
                <tbody>
                    <?php foreach (array_slice($transactionsByDate, -10) as $data): ?>
                        <tr>
                            <td><?= date('d.m.Y', strtotime($data['date'])) ?></td>
                            <td><?= $data['count'] ?></td>
                        </tr>
                    <?php endforeach; ?>
                </tbody>
            </table>
        </div>
    <?php endif; ?>
</div>

<style>
.stat-card {
    background: white;
    border: 1px solid #ddd;
    border-radius: 8px;
    padding: 20px;
    box-shadow: 0 2px 4px rgba(0,0,0,0.1);
}

.stat-card h4 {
    margin: 0 0 15px 0;
    color: #333;
    font-size: 1.2em;
}

.stat-number {
    font-size: 2.5em;
    font-weight: bold;
    color: #667eea;
    margin: 10px 0;
}

.stat-detail {
    margin: 5px 0;
    color: #666;
    font-size: 0.9em;
}
</style>

<?php
$content = ob_get_clean();
include __DIR__ . '/../../templates/base.php';
?>








