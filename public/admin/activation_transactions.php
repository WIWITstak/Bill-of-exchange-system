<?php
/**
 * Админ-панель: Управление транзакциями активации
 */

require_once __DIR__ . '/../../src/bootstrap.php';

use OGAS\Services\AdminService;
use OGAS\Services\ChatService;
use OGAS\Models\Transaction;
use OGAS\Models\User;
use OGAS\Core\Session;
use OGAS\Core\Security;

AdminService::requireAdmin(); // Проверяем права администратора

$error = Session::getFlash('error');
$success = Session::getFlash('success');

// Получаем системного пользователя ОГАС
$systemUser = User::getOrCreateSystemUser();

// Обработка подтверждения сделки от имени системного пользователя
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action'])) {
    // CSRF защита
    if (!Security::checkCsrfToken()) {
        Session::flash('error', 'Ошибка безопасности. Обновите страницу и попробуйте снова.');
        header('Location: /admin/activation_transactions.php');
        exit;
    }
    
    $action = $_POST['action'] ?? '';
    $transactionId = (int)($_POST['transaction_id'] ?? 0);
    
    try {
        switch ($action) {
            case 'confirm_system':
                // Подтверждаем сделку от имени системного пользователя
                if ($transactionId <= 0) {
                    throw new \Exception('Неверный ID транзакции');
                }
                
                $transaction = Transaction::findById($transactionId);
                if (!$transaction) {
                    throw new \Exception('Транзакция не найдена');
                }
                
                // Проверяем, что это транзакция с системным пользователем
                if ($transaction->getSellerId() !== $systemUser->getId() && 
                    $transaction->getBuyerId() !== $systemUser->getId()) {
                    throw new \Exception('Эта транзакция не связана с системным пользователем ОГАС');
                }
                
                // Подтверждаем сделку от имени системного пользователя
                ChatService::confirmTransaction($transactionId, $systemUser->getId());
                
                Session::flash('success', 'Сделка подтверждена от имени ОГАС.');
                break;
                
            default:
                Session::flash('error', 'Неизвестное действие.');
                break;
        }
    } catch (\Exception $e) {
        Session::flash('error', 'Ошибка: ' . $e->getMessage());
    }
    
    header('Location: /admin/activation_transactions.php');
    exit;
}

// Фильтры
$statusFilter = $_GET['status'] ?? null;
$searchQuery = trim($_GET['search'] ?? '');
$page = (int)($_GET['page'] ?? 1);
$limit = 20;
$offset = ($page - 1) * $limit;

// Получаем все транзакции с системным пользователем
$db = \OGAS\Database::getConnection();

$sql = "
    SELECT * FROM transactions 
    WHERE (seller_id = ? OR buyer_id = ?)
    AND category = 'Активация аккаунта'
";

$params = [$systemUser->getId(), $systemUser->getId()];

if ($statusFilter) {
    $sql .= " AND status = ?";
    $params[] = $statusFilter;
}

if ($searchQuery) {
    $searchTerm = '%' . $searchQuery . '%';
    $sql .= " AND (description LIKE ? OR id = ?)";
    $params[] = $searchTerm;
    $params[] = (int)$searchQuery;
}

$sql .= " ORDER BY created_at DESC LIMIT ? OFFSET ?";
$params[] = $limit;
$params[] = $offset;

$stmt = $db->prepare($sql);
$stmt->execute($params);

$transactions = [];
while ($data = $stmt->fetch(\PDO::FETCH_ASSOC)) {
    $transactions[] = Transaction::fromArray($data);
}

// Получаем общее количество
$countSql = "
    SELECT COUNT(*) FROM transactions 
    WHERE (seller_id = ? OR buyer_id = ?)
    AND category = 'Активация аккаунта'
";

$countParams = [$systemUser->getId(), $systemUser->getId()];

if ($statusFilter) {
    $countSql .= " AND status = ?";
    $countParams[] = $statusFilter;
}

if ($searchQuery) {
    $searchTerm = '%' . $searchQuery . '%';
    $countSql .= " AND (description LIKE ? OR id = ?)";
    $countParams[] = $searchTerm;
    $countParams[] = (int)$searchQuery;
}

$countStmt = $db->prepare($countSql);
$countStmt->execute($countParams);
$totalTransactions = (int)$countStmt->fetchColumn();
$totalPages = ceil($totalTransactions / $limit);

// Статистика
$stats = [
    'total' => $totalTransactions,
    'pending' => 0,
    'active' => 0,
    'waiting_system' => 0, // Ожидают подтверждения от системного пользователя
    'waiting_user' => 0    // Ожидают подтверждения от пользователя
];

$allActivationTransactions = Transaction::findByUser($systemUser->getId());
foreach ($allActivationTransactions as $t) {
    if ($t->getCategory() === 'Активация аккаунта') {
        if ($t->getStatus() === 'pending') {
            $stats['pending']++;
            // Определяем, кто должен подтвердить
            if ($t->getSellerId() === $systemUser->getId() && !$t->isSellerConfirmed()) {
                $stats['waiting_system']++;
            } elseif ($t->getBuyerId() === $systemUser->getId() && !$t->isBuyerConfirmed()) {
                $stats['waiting_system']++;
            }
            if (($t->getSellerId() !== $systemUser->getId() && !$t->isSellerConfirmed()) ||
                ($t->getBuyerId() !== $systemUser->getId() && !$t->isBuyerConfirmed())) {
                $stats['waiting_user']++;
            }
        } elseif ($t->getStatus() === 'active') {
            $stats['active']++;
        }
    }
}

$title = 'Транзакции активации';
ob_start();
?>
<div class="dashboard">
    <div class="dashboard-header">
        <h2>Транзакции активации аккаунтов</h2>
        <div class="header-actions">
            <a href="/admin/index.php" class="btn btn-secondary">← В админ-панель</a>
        </div>
    </div>

    <?php if ($error): ?>
        <div class="alert alert-error"><?= htmlspecialchars($error) ?></div>
    <?php endif; ?>

    <?php if ($success): ?>
        <div class="alert alert-success"><?= htmlspecialchars($success) ?></div>
    <?php endif; ?>

    <!-- Статистика -->
    <div class="admin-dashboard-grid" style="display: grid; grid-template-columns: repeat(auto-fit, minmax(200px, 1fr)); gap: 20px; margin-bottom: 20px;">
        <div class="info-card">
            <h3>Всего</h3>
            <p class="stat-number" style="font-size: 2em; font-weight: bold; color: #667eea;"><?= $stats['total'] ?></p>
        </div>
        <div class="info-card">
            <h3>Ожидают</h3>
            <p class="stat-number" style="font-size: 2em; font-weight: bold; color: #f59e0b;"><?= $stats['pending'] ?></p>
            <small>Ожидают подтверждения от системы: <?= $stats['waiting_system'] ?></small><br>
            <small>Ожидают подтверждения от пользователя: <?= $stats['waiting_user'] ?></small>
        </div>
        <div class="info-card">
            <h3>Активированы</h3>
            <p class="stat-number" style="font-size: 2em; font-weight: bold; color: #48bb78;"><?= $stats['active'] ?></p>
        </div>
    </div>

    <!-- Фильтры -->
    <div class="info-card">
        <h3>Фильтры и поиск</h3>
        <form method="GET" action="/admin/activation_transactions.php" style="display: flex; gap: 10px; align-items: flex-end; flex-wrap: wrap;">
            <div class="form-group" style="flex: 1; min-width: 200px;">
                <label for="search">Поиск по описанию или ID:</label>
                <input type="text" id="search" name="search" 
                       value="<?= htmlspecialchars($searchQuery) ?>"
                       placeholder="Введите текст для поиска...">
            </div>
            <div class="form-group">
                <label for="status">Статус:</label>
                <select name="status" id="status">
                    <option value="">Все</option>
                    <option value="pending" <?= $statusFilter === 'pending' ? 'selected' : '' ?>>Ожидают</option>
                    <option value="active" <?= $statusFilter === 'active' ? 'selected' : '' ?>>Активированы</option>
                </select>
            </div>
            <button type="submit" class="btn btn-primary">Найти</button>
            <?php if ($searchQuery || $statusFilter): ?>
                <a href="/admin/activation_transactions.php" class="btn btn-secondary">Сбросить</a>
            <?php endif; ?>
        </form>
    </div>

    <!-- Список транзакций -->
    <div class="info-card">
        <h3>Транзакции активации (Всего: <?= $totalTransactions ?>)</h3>
        
        <?php if (empty($transactions)): ?>
            <p class="text-muted">Транзакций активации не найдено.</p>
        <?php else: ?>
            <table class="transactions-table">
                <thead>
                    <tr>
                        <th>ID</th>
                        <th>Дата</th>
                        <th>Пользователь</th>
                        <th>Описание</th>
                        <th>Подтверждение пользователя</th>
                        <th>Подтверждение ОГАС</th>
                        <th>Статус</th>
                        <th>Действия</th>
                    </tr>
                </thead>
                <tbody>
                    <?php foreach ($transactions as $transaction): ?>
                        <?php
                        // Определяем пользователя (не системного)
                        $userInTransaction = null;
                        if ($transaction->getSellerId() === $systemUser->getId()) {
                            $userInTransaction = User::findById($transaction->getBuyerId());
                            $isSystemSeller = true;
                        } else {
                            $userInTransaction = User::findById($transaction->getSellerId());
                            $isSystemSeller = false;
                        }
                        
                        // Определяем, нужно ли подтверждение от системного пользователя
                        $needsSystemConfirmation = false;
                        if ($isSystemSeller && !$transaction->isSellerConfirmed()) {
                            $needsSystemConfirmation = true;
                        } elseif (!$isSystemSeller && !$transaction->isBuyerConfirmed()) {
                            $needsSystemConfirmation = true;
                        }
                        ?>
                        <tr>
                            <td>#<?= $transaction->getId() ?></td>
                            <td><?= $transaction->getCreatedAt() ? date('d.m.Y H:i', strtotime($transaction->getCreatedAt())) : '-' ?></td>
                            <td>
                                <a href="/user.php?id=<?= $userInTransaction->getId() ?>">
                                    <?= htmlspecialchars($userInTransaction->getFullName()) ?>
                                </a><br>
                                <small><?= htmlspecialchars($userInTransaction->getEmail()) ?></small>
                            </td>
                            <td><?= htmlspecialchars(mb_substr($transaction->getDescription() ?? 'Без описания', 0, 60)) ?><?= mb_strlen($transaction->getDescription() ?? '') > 60 ? '...' : '' ?></td>
                            <td class="text-center">
                                <?php if ($isSystemSeller): ?>
                                    <?= $transaction->isBuyerConfirmed() ? '✅ Подтверждено' : '⏳ Ожидает' ?>
                                <?php else: ?>
                                    <?= $transaction->isSellerConfirmed() ? '✅ Подтверждено' : '⏳ Ожидает' ?>
                                <?php endif; ?>
                            </td>
                            <td class="text-center">
                                <?php if ($isSystemSeller): ?>
                                    <?= $transaction->isSellerConfirmed() ? '✅ Подтверждено' : '⏳ Ожидает' ?>
                                <?php else: ?>
                                    <?= $transaction->isBuyerConfirmed() ? '✅ Подтверждено' : '⏳ Ожидает' ?>
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
                                    'pending' => 'На рассмотрении',
                                    'active' => 'Активна (аккаунт активирован)',
                                    'completed' => 'Завершена',
                                    'cancelled' => 'Отменена'
                                ];
                                $class = $statusClass[$transaction->getStatus()] ?? '';
                                $label = $statusLabels[$transaction->getStatus()] ?? $transaction->getStatus();
                                ?>
                                <span class="status <?= $class ?>"><?= $label ?></span>
                            </td>
                            <td>
                                <a href="/transactions/chat.php?id=<?= $transaction->getId() ?>" class="btn btn-small">Открыть чат</a>
                                <?php if ($needsSystemConfirmation && $transaction->getStatus() === 'pending'): ?>
                                    <form method="POST" style="display:inline; margin-left: 5px;" 
                                          onsubmit="return confirm('Подтвердить сделку от имени ОГАС? После подтверждения аккаунт пользователя будет активирован.');">
                                        <?= csrf_field() ?>
                                        <input type="hidden" name="action" value="confirm_system">
                                        <input type="hidden" name="transaction_id" value="<?= $transaction->getId() ?>">
                                        <button type="submit" class="btn btn-small btn-primary">
                                            ✅ Подтвердить от ОГАС
                                        </button>
                                    </form>
                                <?php endif; ?>
                            </td>
                        </tr>
                    <?php endforeach; ?>
                </tbody>
            </table>

            <?php if ($totalPages > 1): ?>
                <div class="pagination" style="margin-top: 20px;">
                    <?php for ($i = 1; $i <= $totalPages; $i++): ?>
                        <a href="/admin/activation_transactions.php?page=<?= $i ?><?= $statusFilter ? '&status=' . urlencode($statusFilter) : '' ?><?= $searchQuery ? '&search=' . urlencode($searchQuery) : '' ?>"
                           class="btn btn-small <?= $i === $page ? 'btn-primary' : 'btn-secondary' ?>">
                            <?= $i ?>
                        </a>
                    <?php endfor; ?>
                </div>
            <?php endif; ?>
        <?php endif; ?>
    </div>
</div>

<?php
$content = ob_get_clean();
include __DIR__ . '/../../templates/base.php';
?>








