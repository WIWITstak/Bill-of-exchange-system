<?php
/**
 * Страница просмотра всех транзакций (админ-панель)
 */

require_once __DIR__ . '/../../src/bootstrap.php';

use OGAS\Services\Auth;
use OGAS\Services\AdminService;
use OGAS\Models\Transaction;
use OGAS\Models\User;
use OGAS\Models\Category;

Auth::requireAuth();
AdminService::requireAdmin();

// Параметры пагинации и фильтров
$page = max(1, (int)($_GET['page'] ?? 1));
$perPage = 20;
$statusFilter = $_GET['status'] ?? null;

// Получаем транзакции
$transactions = Transaction::findAll($statusFilter, $perPage, ($page - 1) * $perPage);

// Подсчитываем общее количество
$db = \OGAS\Database::getConnection();
$sql = "SELECT COUNT(*) FROM transactions";
$params = [];
if ($statusFilter) {
    $sql .= " WHERE status = ?";
    $params[] = $statusFilter;
}
$stmt = $db->prepare($sql);
$stmt->execute($params);
$totalTransactions = (int)$stmt->fetchColumn();
$totalPages = ceil($totalTransactions / $perPage);

$title = 'Транзакции - Админ-панель';
ob_start();
?>
<div class="dashboard">
    <div class="dashboard-header">
        <h2>Просмотр транзакций</h2>
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
                    <option value="pending" <?= $statusFilter === 'pending' ? 'selected' : '' ?>>Ожидают</option>
                    <option value="active" <?= $statusFilter === 'active' ? 'selected' : '' ?>>Активные</option>
                    <option value="completed" <?= $statusFilter === 'completed' ? 'selected' : '' ?>>Завершённые</option>
                    <option value="cancelled" <?= $statusFilter === 'cancelled' ? 'selected' : '' ?>>Отменённые</option>
                </select>
            </label>
            <?php if ($statusFilter): ?>
                <a href="/admin/transactions.php" class="btn btn-secondary">Сбросить</a>
            <?php endif; ?>
        </form>
    </div>

    <!-- Статистика -->
    <div class="info-card">
        <p><strong>Всего транзакций:</strong> <?= $totalTransactions ?></p>
    </div>

    <!-- Таблица транзакций -->
    <div class="info-card">
        <h3>Транзакции</h3>
        <?php if (empty($transactions)): ?>
            <p class="text-muted">Транзакций не найдено</p>
        <?php else: ?>
            <table class="transactions-table">
                <thead>
                    <tr>
                        <th>ID</th>
                        <th>Дата</th>
                        <th>Продавец</th>
                        <th>Покупатель</th>
                        <th>Описание</th>
                        <th>Категория</th>
                        <th>Статус</th>
                        <th>Действия</th>
                    </tr>
                </thead>
                <tbody>
                    <?php foreach ($transactions as $transaction): ?>
                        <?php
                        $seller = User::findById($transaction->getSellerId());
                        $buyer = User::findById($transaction->getBuyerId());
                        $category = $transaction->getCategory() ? Category::findByName($transaction->getCategory()) : null;
                        ?>
                        <tr>
                            <td>#<?= $transaction->getId() ?></td>
                            <td><?= $transaction->getCreatedAt() ? date('d.m.Y H:i', strtotime($transaction->getCreatedAt())) : '-' ?></td>
                            <td>
                                <a href="/user.php?id=<?= $seller->getId() ?>">
                                    <?= htmlspecialchars($seller->getFullName()) ?>
                                </a>
                                <br><small><?= htmlspecialchars($seller->getEmail()) ?></small>
                            </td>
                            <td>
                                <a href="/user.php?id=<?= $buyer->getId() ?>">
                                    <?= htmlspecialchars($buyer->getFullName()) ?>
                                </a>
                                <br><small><?= htmlspecialchars($buyer->getEmail()) ?></small>
                            </td>
                            <td><?= htmlspecialchars(mb_substr($transaction->getDescription() ?? 'Без описания', 0, 50)) ?><?= mb_strlen($transaction->getDescription() ?? '') > 50 ? '...' : '' ?></td>
                            <td>
                                <?php if ($category): ?>
                                    <?= htmlspecialchars($category->getIcon() ?? '📦') ?> <?= htmlspecialchars($category->getName()) ?>
                                <?php elseif ($transaction->getCategory()): ?>
                                    <?= htmlspecialchars($transaction->getCategory()) ?>
                                <?php else: ?>
                                    <span class="text-muted">-</span>
                                <?php endif; ?>
                            </td>
                            <td>
                                <?php
                                $statusClass = [
                                    'pending' => 'status-pending',
                                    'active' => 'status-active',
                                    'completed' => 'status-completed',
                                    'cancelled' => 'status-cancelled'
                                ];
                                $statusLabels = [
                                    'pending' => 'Ожидает',
                                    'active' => 'Активна',
                                    'completed' => 'Завершена',
                                    'cancelled' => 'Отменена'
                                ];
                                $class = $statusClass[$transaction->getStatus()] ?? '';
                                $label = $statusLabels[$transaction->getStatus()] ?? $transaction->getStatus();
                                ?>
                                <span class="status <?= $class ?>"><?= $label ?></span>
                            </td>
                            <td>
                                <a href="/transactions.php?id=<?= $transaction->getId() ?>" class="btn btn-small">Подробнее</a>
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








