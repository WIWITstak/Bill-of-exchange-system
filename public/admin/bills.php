<?php
/**
 * Страница просмотра всех векселей (админ-панель)
 */

require_once __DIR__ . '/../../src/bootstrap.php';

use OGAS\Services\Auth;
use OGAS\Services\AdminService;
use OGAS\Models\Bill;
use OGAS\Models\User;

Auth::requireAuth();
AdminService::requireAdmin();

// Параметры пагинации и фильтров
$page = max(1, (int)($_GET['page'] ?? 1));
$perPage = 20;
$statusFilter = $_GET['status'] ?? null;

// Получаем вексели
$bills = Bill::findAll($statusFilter, $perPage, ($page - 1) * $perPage);

// Подсчитываем общее количество
$db = \OGAS\Database::getConnection();
$sql = "SELECT COUNT(*) FROM bills";
$params = [];
if ($statusFilter) {
    $sql .= " WHERE status = ?";
    $params[] = $statusFilter;
}
$stmt = $db->prepare($sql);
$stmt->execute($params);
$totalBills = (int)$stmt->fetchColumn();
$totalPages = ceil($totalBills / $perPage);

// Общая сумма по статусам
$statsSql = "SELECT status, COUNT(*) as count, COALESCE(SUM(nominal), 0) as total FROM bills GROUP BY status";
$statsStmt = $db->query($statsSql);
$billStats = [];
while ($row = $statsStmt->fetch(\PDO::FETCH_ASSOC)) {
    $billStats[$row['status']] = [
        'count' => (int)$row['count'],
        'total' => (float)$row['total']
    ];
}

$title = 'Вексели - Админ-панель';
ob_start();
?>
<div class="dashboard">
    <div class="dashboard-header">
        <h2>Просмотр векселей</h2>
        <div class="header-actions">
            <a href="/admin/index.php" class="btn btn-secondary">← Назад</a>
        </div>
    </div>

    <!-- Фильтры -->
    <div class="filters">
        <form method="GET" action="" style="display: flex; gap: 10px; align-items: flex-end;">
            <label>
                Статус:
                <select name="status" onchange="this.form.submit()">
                    <option value="">Все</option>
                    <option value="active" <?= $statusFilter === 'active' ? 'selected' : '' ?>>Активные</option>
                    <option value="paid" <?= $statusFilter === 'paid' ? 'selected' : '' ?>>Погашенные</option>
                    <option value="overdue" <?= $statusFilter === 'overdue' ? 'selected' : '' ?>>Просроченные</option>
                    <option value="cancelled" <?= $statusFilter === 'cancelled' ? 'selected' : '' ?>>Отменённые</option>
                </select>
            </label>
            <?php if ($statusFilter): ?>
                <a href="/admin/bills.php" class="btn btn-secondary">Сбросить</a>
            <?php endif; ?>
        </form>
    </div>

    <!-- Статистика по векселям -->
    <div class="info-card">
        <h3>Статистика векселей</h3>
        <div style="display: grid; grid-template-columns: repeat(auto-fit, minmax(200px, 1fr)); gap: 15px; margin-top: 15px;">
            <?php
            $statusLabels = [
                'active' => 'Активные',
                'paid' => 'Погашенные',
                'overdue' => 'Просроченные',
                'cancelled' => 'Отменённые'
            ];
            foreach ($statusLabels as $status => $label):
                $stat = $billStats[$status] ?? ['count' => 0, 'total' => 0];
            ?>
                <div style="border: 1px solid #ddd; padding: 10px; border-radius: 5px;">
                    <strong><?= $label ?>:</strong><br>
                    Количество: <?= $stat['count'] ?><br>
                    Сумма: <?= number_format($stat['total'], 2) ?> ₽
                </div>
            <?php endforeach; ?>
        </div>
        <p style="margin-top: 15px;"><strong>Всего векселей:</strong> <?= $totalBills ?></p>
    </div>

    <!-- Таблица векселей -->
    <div class="info-card">
        <h3>Вексели</h3>
        <?php if (empty($bills)): ?>
            <p class="text-muted">Векселей не найдено</p>
        <?php else: ?>
            <table class="transactions-table">
                <thead>
                    <tr>
                        <th>ID</th>
                        <th>Выпущен</th>
                        <th>Погашение</th>
                        <th>Эмитент</th>
                        <th>Держатель</th>
                        <th>Номинал</th>
                        <th>Статус</th>
                        <th>Действия</th>
                    </tr>
                </thead>
                <tbody>
                    <?php foreach ($bills as $bill): ?>
                        <?php
                        $issuer = User::findById($bill->getIssuerId());
                        $holder = User::findById($bill->getHolderId());
                        $isOverdue = $bill->getStatus() === 'active' && strtotime($bill->getMaturityDate()) < time();
                        ?>
                        <tr>
                            <td>#<?= $bill->getId() ?></td>
                            <td><?= date('d.m.Y', strtotime($bill->getIssueDate())) ?></td>
                            <td>
                                <?= date('d.m.Y', strtotime($bill->getMaturityDate())) ?>
                                <?php if ($isOverdue): ?>
                                    <br><small style="color: red;">Просрочен!</small>
                                <?php endif; ?>
                            </td>
                            <td>
                                <a href="/user.php?id=<?= $issuer->getId() ?>">
                                    <?= htmlspecialchars($issuer->getFullName()) ?>
                                </a>
                                <br><small><?= htmlspecialchars($issuer->getEmail()) ?></small>
                            </td>
                            <td>
                                <a href="/user.php?id=<?= $holder->getId() ?>">
                                    <?= htmlspecialchars($holder->getFullName()) ?>
                                </a>
                                <br><small><?= htmlspecialchars($holder->getEmail()) ?></small>
                            </td>
                            <td><strong><?= number_format($bill->getNominal(), 2) ?> ₽</strong></td>
                            <td>
                                <?php
                                $statusClass = [
                                    'active' => 'status-active',
                                    'paid' => 'status-completed',
                                    'overdue' => 'status-cancelled',
                                    'cancelled' => 'status-cancelled'
                                ];
                                $statusLabels = [
                                    'active' => 'Активен',
                                    'paid' => 'Погашен',
                                    'overdue' => 'Просрочен',
                                    'cancelled' => 'Отменён'
                                ];
                                $class = $statusClass[$bill->getStatus()] ?? '';
                                $label = $statusLabels[$bill->getStatus()] ?? $bill->getStatus();
                                ?>
                                <span class="status <?= $class ?>"><?= $label ?></span>
                            </td>
                            <td>
                                <a href="/bills.php?id=<?= $bill->getId() ?>" class="btn btn-small">Подробнее</a>
                            </td>
                        </tr>
                    <?php endforeach; ?>
                </tbody>
            </table>

            <!-- Пагинация -->
            <?php if ($totalPages > 1): ?>
                <div class="pagination">
                    <?php if ($page > 1): ?>
                        <a href="?page=<?= $page - 1 ?><?= $statusFilter ? '&status=' . urlencode($statusFilter) : '' ?>" class="btn btn-secondary">← Назад</a>
                    <?php endif; ?>
                    
                    <span>Страница <?= $page ?> из <?= $totalPages ?></span>
                    
                    <?php if ($page < $totalPages): ?>
                        <a href="?page=<?= $page + 1 ?><?= $statusFilter ? '&status=' . urlencode($statusFilter) : '' ?>" class="btn btn-secondary">Вперёд →</a>
                    <?php endif; ?>
                </div>
            <?php endif; ?>
        <?php endif; ?>
    </div>
</div>

<?php
$content = ob_get_clean();
include __DIR__ . '/../../templates/base.php';
?>








