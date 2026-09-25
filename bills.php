<?php
/**
 * Страница управления векселями
 */

require_once __DIR__ . '/../src/bootstrap.php';

use OGAS\Services\Auth;
use OGAS\Models\Bill;
use OGAS\Models\Transaction;
use OGAS\Models\User;
use OGAS\Models\CommunityRequest;
use OGAS\Services\BillService;
use OGAS\Services\NotificationService;
use OGAS\Services\TransactionService;
use OGAS\Core\Security;
use OGAS\Core\Session;

Auth::requireAuth();
$user = Auth::user();

// Обновляем статусы просроченных векселей
BillService::updateOverdueBills($user->getId());

// Проверяем уведомления (напоминания о погашении, просрочки)
NotificationService::checkMaturityReminders($user->getId());
NotificationService::checkOverdueBills($user->getId());

// Фильтры
$statusFilter = $_GET['status'] ?? null; // active, paid, overdue, cancelled
$communityFilter = isset($_GET['community']) && $_GET['community'] === '1';
$transactionFilter = $_GET['transaction'] ?? null; // ID транзакции или 'all' для всех связанных
$dateFromFilter = $_GET['date_from'] ?? null;
$dateToFilter = $_GET['date_to'] ?? null;
$maturityFromFilter = $_GET['maturity_from'] ?? null;
$maturityToFilter = $_GET['maturity_to'] ?? null;
$nominalFromFilter = $_GET['nominal_from'] ?? null;
$nominalToFilter = $_GET['nominal_to'] ?? null;
$maturityTypeFilter = $_GET['maturity_type'] ?? null; // all, overdue, soon, normal

// Получаем все вексели пользователя
$allIssuedBills = Bill::findByIssuer($user->getId(), $statusFilter);
$allHeldBills = Bill::findByHolder($user->getId(), $statusFilter);

// Получаем транзакции пользователя для фильтра
$userTransactions = Transaction::findByUser($user->getId(), 'active');

// Предварительно загружаем связанные вексели для всех транзакций (для оптимизации)
$transactionBillsMap = []; // [transactionId => [billIds]]
$transactionsById = []; // [transactionId => Transaction] - для быстрого доступа
foreach ($userTransactions as $trans) {
    $transId = $trans->getId();
    $transactionsById[$transId] = $trans;
    $bills = TransactionService::getBillsForTransaction($transId);
    $transactionBillsMap[$transId] = array_map(fn($b) => $b->getId(), $bills);
}

// Функция для фильтрации векселей
$filterBills = function($bills) use ($communityFilter, $transactionFilter, $dateFromFilter, $dateToFilter, 
                                     $maturityFromFilter, $maturityToFilter, $nominalFromFilter, 
                                     $nominalToFilter, $maturityTypeFilter, $transactionBillsMap, $transactionsById) {
    return array_filter($bills, function($bill) use ($communityFilter, $transactionFilter, $dateFromFilter, $dateToFilter,
                                                      $maturityFromFilter, $maturityToFilter, $nominalFromFilter,
                                                      $nominalToFilter, $maturityTypeFilter, $transactionBillsMap) {
        // Фильтр по общине
        if ($communityFilter && !$bill->isFromCommunity()) {
            return false;
        }
        
        // Фильтр по транзакциям
        if ($transactionFilter) {
            $billId = $bill->getId();
            $hasTransaction = false;
            
            // Проверяем, есть ли вексель в любой транзакции
            foreach ($transactionBillsMap as $billIds) {
                if (in_array($billId, $billIds)) {
                    $hasTransaction = true;
                    break;
                }
            }
            
            if ($transactionFilter === 'none') {
                // Показать вексели не связанные с транзакциями
                if ($hasTransaction) {
                    return false;
                }
            } elseif ($transactionFilter !== 'all') {
                // Показать вексели связанные с конкретной транзакцией
                $transactionBillIds = $transactionBillsMap[(int)$transactionFilter] ?? [];
                if (!in_array($billId, $transactionBillIds)) {
                    return false;
                }
            } else {
                // Показать вексели связанные с любыми транзакциями
                if (!$hasTransaction) {
                    return false;
                }
            }
        }
        
        // Фильтр по дате выпуска
        if ($dateFromFilter && strtotime($bill->getIssueDate()) < strtotime($dateFromFilter)) {
            return false;
        }
        if ($dateToFilter && strtotime($bill->getIssueDate()) > strtotime($dateToFilter . ' 23:59:59')) {
            return false;
        }
        
        // Фильтр по дате погашения
        if ($maturityFromFilter && strtotime($bill->getMaturityDate()) < strtotime($maturityFromFilter)) {
            return false;
        }
        if ($maturityToFilter && strtotime($bill->getMaturityDate()) > strtotime($maturityToFilter . ' 23:59:59')) {
            return false;
        }
        
        // Фильтр по номиналу
        if ($nominalFromFilter && $bill->getNominal() < (float)$nominalFromFilter) {
            return false;
        }
        if ($nominalToFilter && $bill->getNominal() > (float)$nominalToFilter) {
            return false;
        }
        
        // Фильтр по типу срочности погашения
        if ($maturityTypeFilter && $maturityTypeFilter !== 'all') {
            $now = time();
            $maturityTime = strtotime($bill->getMaturityDate());
            $sevenDays = strtotime('+7 days');
            
            switch ($maturityTypeFilter) {
                case 'overdue':
                    if ($bill->getStatus() !== 'overdue' && ($maturityTime >= $now || $bill->getStatus() === 'paid')) {
                        return false;
                    }
                    break;
                case 'soon':
                    if ($maturityTime > $sevenDays || $maturityTime < $now) {
                        return false;
                    }
                    break;
                case 'normal':
                    if ($maturityTime <= $sevenDays) {
                        return false;
                    }
                    break;
            }
        }
        
        return true;
    });
};

// Применяем фильтры
$issuedBills = $filterBills($allIssuedBills);
$heldBills = $filterBills($allHeldBills);

$statistics = BillService::getStatistics($user->getId());

// Просмотр конкретного векселя (если передан ID)
$viewBill = null;
if (isset($_GET['id'])) {
    $billId = (int)$_GET['id'];
    $viewBill = Bill::findById($billId);
    
    if ($viewBill) {
        // Проверяем доступ к векселю
        $isParticipant = ($viewBill->getIssuerId() === $user->getId() || $viewBill->getHolderId() === $user->getId());
        $isAdmin = $user->isAdmin();
        
        // Проверяем, является ли вексель частью депозитария (публичного реестра)
        $isInDepository = false;
        if (!$isParticipant && !$isAdmin) {
            // Ищем связанную транзакцию в депозитарии
            $db = \OGAS\Database::getConnection();
            $stmt = $db->prepare("
                SELECT t.* 
                FROM transactions t
                WHERE (
                    (t.seller_id = ? AND t.buyer_id = ?) OR
                    (t.seller_id = ? AND t.buyer_id = ?)
                )
                AND (t.status = 'active' OR t.status = 'completed')
                AND t.seller_confirmed = 1 
                AND t.buyer_confirmed = 1
                AND t.created_at <= ?
                LIMIT 1
            ");
            $billIssueDate = $viewBill->getIssueDate() ?? date('Y-m-d H:i:s');
            $stmt->execute([
                $viewBill->getIssuerId(),
                $viewBill->getHolderId(),
                $viewBill->getHolderId(),
                $viewBill->getIssuerId(),
                $billIssueDate
            ]);
            
            $transactionData = $stmt->fetch(\PDO::FETCH_ASSOC);
            if ($transactionData) {
                $isInDepository = true;
            }
        }
        
        // Если нет доступа, скрываем вексель
        if (!$isParticipant && !$isAdmin && !$isInDepository) {
            $viewBill = null;
        }
    }
}

// Обработка погашения векселя
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['pay_bill'])) {
    // CSRF защита
    if (!Security::checkCsrfToken()) {
        Session::flash('error', 'Ошибка безопасности. Обновите страницу и попробуйте снова.');
        header('Location: /bills.php');
        exit;
    }
    
    $billId = (int)$_POST['bill_id'];
    if (BillService::pay($billId)) {
        header('Location: /bills.php?success=1');
        exit;
    }
}

$title = 'Мои вексели';
ob_start();
?>
<div class="dashboard">
    <div class="dashboard-header">
        <h2><?= $viewBill ? 'Вексель #' . $viewBill->getId() : 'Мои вексели' ?></h2>
        <div class="header-actions">
            <?php if ($viewBill): ?>
                <a href="/bills.php" class="btn btn-secondary">← Вернуться к списку</a>
                <a href="/api/bill_pdf.php?id=<?= $viewBill->getId() ?>" class="btn btn-primary" target="_blank">
                    <i class="fas fa-download"></i> Скачать PDF
                </a>
            <?php else: ?>
                <a href="/export/bills.php" class="btn btn-secondary">Экспорт всех CSV</a>
                <a href="/export/bills.php?type=issued" class="btn btn-secondary">Экспорт выпущенных</a>
                <a href="/export/bills.php?type=held" class="btn btn-secondary">Экспорт полученных</a>
                <a href="/dashboard.php" class="btn btn-secondary">← Назад</a>
            <?php endif; ?>
        </div>
    </div>
    
    <?php if (isset($_GET['success'])): ?>
        <div class="alert alert-success">Вексель успешно погашен!</div>
    <?php endif; ?>
    
    <?php if (!$viewBill && isset($_GET['id'])): ?>
        <div class="alert alert-error">Вексель не найден или у вас нет доступа к нему.</div>
    <?php endif; ?>
    
    <!-- Статистика -->
    <div class="catalog-info">
        <div class="catalog-stats">
            <div class="stat-item">
                <i class="fas fa-file-invoice-dollar"></i>
                <span>Выпущенные вексели: <strong><?= $statistics['issued_count'] ?></strong> (<?= number_format($statistics['issued_total'], 2, '.', ' ') ?> ₽)</span>
            </div>
            <div class="stat-item">
                <i class="fas fa-wallet"></i>
                <span>Вексели на счету: <strong><?= $statistics['held_count'] ?></strong> (<?= number_format($statistics['held_total'], 2, '.', ' ') ?> ₽)</span>
            </div>
            <div class="stat-item">
                <i class="fas fa-check-circle"></i>
                <span>Активных выпущенных: <strong><?= $statistics['active_issued_count'] ?></strong> (<?= number_format($statistics['active_issued_total'], 2, '.', ' ') ?> ₽)</span>
            </div>
            <div class="stat-item">
                <i class="fas fa-check"></i>
                <span>Активных на счету: <strong><?= $statistics['active_held_count'] ?></strong> (<?= number_format($statistics['active_held_total'], 2, '.', ' ') ?> ₽)</span>
            </div>
        </div>
    </div>
    
    <div class="bills-tabs">
        <button class="tab-btn active" onclick="showTab('issued')">Выпущенные мной</button>
        <button class="tab-btn" onclick="showTab('held')">На моём счету</button>
    </div>
    
    <!-- Фильтры -->
    <?php 
    $hasActiveFilters = !empty($statusFilter) || $communityFilter || !empty($transactionFilter) || !empty($dateFromFilter) || !empty($dateToFilter) || !empty($maturityFromFilter) || !empty($maturityToFilter) || !empty($nominalFromFilter) || !empty($nominalToFilter) || !empty($maturityTypeFilter);
    ?>
    <div class="filters-wrapper" data-has-filters="<?= $hasActiveFilters ? 'true' : 'false' ?>">
        <div class="filters-toggle-header <?= !$hasActiveFilters ? 'collapsed' : '' ?>">
            <div class="filters-toggle-title">
                <i class="fas fa-filter filters-toggle-icon"></i>
                <span>Фильтры<?= $hasActiveFilters ? ' <span style="color: var(--color-primary); font-weight: 600;">(активны)</span>' : '' ?></span>
            </div>
        </div>
        <div class="search-filters-card <?= !$hasActiveFilters ? 'collapsed' : 'expanded' ?>"<?= !$hasActiveFilters ? ' style="max-height: 0; padding: 0; margin: 0; opacity: 0; overflow: hidden;"' : '' ?>>
            <form method="GET" id="billsFilterForm" class="search-filters-form" style="display: grid; grid-template-columns: repeat(auto-fit, minmax(180px, 1fr)); gap: 12px;">
            
            <!-- Фильтр по статусу -->
            <div class="filter-group">
                <label for="status" style="display: block; margin-bottom: 4px; font-weight: 500; font-size: 0.8125rem;">Статус:</label>
                <select name="status" id="status" class="filter-select" onchange="this.form.submit()">
                    <option value="">Все статусы</option>
                    <option value="active" <?= $statusFilter === 'active' ? 'selected' : '' ?>>Активные</option>
                    <option value="paid" <?= $statusFilter === 'paid' ? 'selected' : '' ?>>Погашенные</option>
                    <option value="overdue" <?= $statusFilter === 'overdue' ? 'selected' : '' ?>>Просроченные</option>
                    <option value="cancelled" <?= $statusFilter === 'cancelled' ? 'selected' : '' ?>>Отменённые</option>
                </select>
            </div>
            
            <!-- Фильтр по транзакциям -->
            <div class="filter-group">
                <label for="transaction" style="display: block; margin-bottom: 4px; font-weight: 500; font-size: 0.8125rem;">Транзакции:</label>
                <select name="transaction" id="transaction" class="filter-select" onchange="this.form.submit()">
                    <option value="">Все вексели</option>
                    <option value="all" <?= $transactionFilter === 'all' ? 'selected' : '' ?>>Связанные с транзакциями</option>
                    <option value="none" <?= $transactionFilter === 'none' ? 'selected' : '' ?>>Без транзакций</option>
                    <?php foreach ($userTransactions as $trans): ?>
                        <option value="<?= $trans->getId() ?>" <?= $transactionFilter == $trans->getId() ? 'selected' : '' ?>>
                            Транзакция #<?= $trans->getId() ?> - <?= htmlspecialchars(mb_substr($trans->getDescription() ?? 'Без описания', 0, 30)) ?>
                        </option>
                    <?php endforeach; ?>
                </select>
            </div>
            
            <!-- Фильтр по дате выпуска -->
            <div class="filter-group">
                <label for="date_from" style="display: block; margin-bottom: 4px; font-weight: 500; font-size: 0.8125rem;">Дата выпуска от:</label>
                <input type="date" name="date_from" id="date_from" class="filter-input" value="<?= htmlspecialchars($dateFromFilter ?? '') ?>" onchange="this.form.submit()">
            </div>
            
            <div class="filter-group">
                <label for="date_to" style="display: block; margin-bottom: 4px; font-weight: 500; font-size: 0.8125rem;">Дата выпуска до:</label>
                <input type="date" name="date_to" id="date_to" class="filter-input" value="<?= htmlspecialchars($dateToFilter ?? '') ?>" onchange="this.form.submit()">
            </div>
            
            <!-- Фильтр по сроку погашения -->
            <div class="filter-group">
                <label for="maturity_from" style="display: block; margin-bottom: 4px; font-weight: 500; font-size: 0.8125rem;">Срок погашения от:</label>
                <input type="date" name="maturity_from" id="maturity_from" class="filter-input" value="<?= htmlspecialchars($maturityFromFilter ?? '') ?>" onchange="this.form.submit()">
            </div>
            
            <div class="filter-group">
                <label for="maturity_to" style="display: block; margin-bottom: 4px; font-weight: 500; font-size: 0.8125rem;">Срок погашения до:</label>
                <input type="date" name="maturity_to" id="maturity_to" class="filter-input" value="<?= htmlspecialchars($maturityToFilter ?? '') ?>" onchange="this.form.submit()">
            </div>
            
            <!-- Фильтр по типу срочности -->
            <div class="filter-group">
                <label for="maturity_type" style="display: block; margin-bottom: 4px; font-weight: 500; font-size: 0.8125rem;">Срочность погашения:</label>
                <select name="maturity_type" id="maturity_type" class="filter-select" onchange="this.form.submit()">
                    <option value="all" <?= !$maturityTypeFilter || $maturityTypeFilter === 'all' ? 'selected' : '' ?>>Все</option>
                    <option value="overdue" <?= $maturityTypeFilter === 'overdue' ? 'selected' : '' ?>>Просроченные</option>
                    <option value="soon" <?= $maturityTypeFilter === 'soon' ? 'selected' : '' ?>>Скоро погашение (≤7 дней)</option>
                    <option value="normal" <?= $maturityTypeFilter === 'normal' ? 'selected' : '' ?>>Обычные (>7 дней)</option>
                </select>
            </div>
            
            <!-- Фильтр по номиналу -->
            <div class="filter-group">
                <label for="nominal_from" style="display: block; margin-bottom: 4px; font-weight: 500; font-size: 0.8125rem;">Номинал от (₽):</label>
                <input type="number" name="nominal_from" id="nominal_from" class="filter-input" value="<?= htmlspecialchars($nominalFromFilter ?? '') ?>" 
                       step="0.01" min="0" placeholder="0" onchange="this.form.submit()">
            </div>
            
            <div class="filter-group">
                <label for="nominal_to" style="display: block; margin-bottom: 4px; font-weight: 500; font-size: 0.8125rem;">Номинал до (₽):</label>
                <input type="number" name="nominal_to" id="nominal_to" class="filter-input" value="<?= htmlspecialchars($nominalToFilter ?? '') ?>" 
                       step="0.01" min="0" placeholder="0" onchange="this.form.submit()">
            </div>
            
            <!-- Фильтр по общине -->
            <div class="filter-group" style="display: flex; align-items: flex-end;">
                <label style="display: flex; align-items: center; gap: 8px; cursor: pointer; padding: 8px; background: var(--bg-primary); border: 1px solid var(--border-color); border-radius: var(--radius-md); width: 100%;">
                    <input type="checkbox" name="community" value="1" <?= $communityFilter ? 'checked' : '' ?> onchange="this.form.submit()">
                    <span>Только связанные с общиной</span>
                </label>
            </div>
            
            <!-- Кнопка сброса -->
            <?php if ($statusFilter || $communityFilter || $transactionFilter || $dateFromFilter || $dateToFilter || 
                      $maturityFromFilter || $maturityToFilter || $nominalFromFilter || $nominalToFilter || $maturityTypeFilter): ?>
                <div class="filter-actions" style="display: flex; align-items: flex-end;">
                    <a href="/bills.php" class="btn btn-secondary">Сбросить все фильтры</a>
                </div>
            <?php endif; ?>
            
        </form>
        </div>
    </div>
    
    <div id="issued-tab" class="tab-content active">
        <h3>Выпущенные вексели (мои обязательства)</h3>
        <table class="bills-table">
            <thead>
                <tr>
                    <th>ID</th>
                    <th>Держатель</th>
                    <th>Номинал</th>
                    <th>Выпущен</th>
                    <th>Срок погашения</th>
                    <th>Статус</th>
                    <th>Действия</th>
                </tr>
            </thead>
            <tbody>
                <?php if (empty($issuedBills)): ?>
                    <tr><td colspan="7" class="text-center">Нет выпущенных векселей</td></tr>
                <?php else: ?>
                    <?php 
                    // Предзагружаем данные пользователей для оптимизации
                    $holderIds = array_unique(array_map(fn($b) => $b->getHolderId(), $issuedBills));
                    $holdersMap = [];
                    foreach ($holderIds as $holderId) {
                        $holder = User::findById($holderId);
                        if ($holder) {
                            $holdersMap[$holderId] = $holder;
                        }
                    }
                    
                    // Группируем вексели по транзакциям
                    $billsByTransaction = [];
                    $billsWithoutTransaction = [];
                    
                    foreach ($issuedBills as $bill) {
                        $billId = $bill->getId();
                        $relatedTransaction = null;
                        
                        foreach ($transactionBillsMap as $transId => $billIds) {
                            if (in_array($billId, $billIds)) {
                                $relatedTransaction = $transactionsById[$transId] ?? null;
                                break;
                            }
                        }
                        
                        if ($relatedTransaction) {
                            $transId = $relatedTransaction->getId();
                            if (!isset($billsByTransaction[$transId])) {
                                $billsByTransaction[$transId] = [
                                    'transaction' => $relatedTransaction,
                                    'bills' => []
                                ];
                            }
                            $billsByTransaction[$transId]['bills'][] = $bill;
                        } else {
                            $billsWithoutTransaction[] = $bill;
                        }
                    }
                    
                    // Предзагружаем участников транзакций для оптимизации
                    $transactionParticipants = [];
                    foreach ($billsByTransaction as $transId => $group) {
                        $trans = $group['transaction'];
                        if (!isset($transactionParticipants[$trans->getSellerId()])) {
                            $seller = User::findById($trans->getSellerId());
                            if ($seller) {
                                $transactionParticipants[$trans->getSellerId()] = $seller;
                            }
                        }
                        if (!isset($transactionParticipants[$trans->getBuyerId()])) {
                            $buyer = User::findById($trans->getBuyerId());
                            if ($buyer) {
                                $transactionParticipants[$trans->getBuyerId()] = $buyer;
                            }
                        }
                    }
                    
                    // Выводим вексели, сгруппированные по транзакциям
                    foreach ($billsByTransaction as $transId => $group): 
                        $transaction = $group['transaction'];
                        $seller = $transactionParticipants[$transaction->getSellerId()] ?? User::findById($transaction->getSellerId());
                        $buyer = $transactionParticipants[$transaction->getBuyerId()] ?? User::findById($transaction->getBuyerId());
                        $transactionBills = $group['bills'];
                        $totalAmount = array_sum(array_map(fn($b) => $b->getNominal(), $transactionBills));
                    ?>
                        <tr class="transaction-group-header" style="background: #f0f4ff; border-top: 2px solid #667eea;">
                            <td colspan="7" style="padding: 12px 16px;">
                                <div style="display: flex; align-items: center; gap: 12px; flex-wrap: wrap;">
                                    <span style="font-weight: 600; color: #667eea;">💼 Транзакция #<?= $transaction->getId() ?></span>
                                    <span style="color: #666;">•</span>
                                    <span style="color: #333;">
                                        <?= htmlspecialchars($seller->getFullName()) ?> → <?= htmlspecialchars($buyer->getFullName()) ?>
                                    </span>
                                    <?php if ($transaction->getDescription()): ?>
                                        <span style="color: #666;">•</span>
                                        <span style="color: #666; font-style: italic;"><?= htmlspecialchars(mb_substr($transaction->getDescription(), 0, 50)) ?><?= mb_strlen($transaction->getDescription()) > 50 ? '...' : '' ?></span>
                                    <?php endif; ?>
                                    <span style="margin-left: auto; color: #10b981; font-weight: 600;">
                                        <?= count($transactionBills) ?> векселей на <?= number_format($totalAmount, 2, '.', ' ') ?> ₽
                                    </span>
                                    <a href="/transactions.php?id=<?= $transaction->getId() ?>" style="color: #667eea; text-decoration: none; font-size: 0.9em;">
                                        Перейти →
                                    </a>
                                </div>
                            </td>
                        </tr>
                        <?php foreach ($transactionBills as $bill): 
                            $holder = $holdersMap[$bill->getHolderId()] ?? null;
                        ?>
                            <tr class="transaction-group-item">
                                <td>
                                    #<?= $bill->getId() ?>
                                    <?php if ($bill->isFromCommunity()): ?>
                                        <a href="/community.php?id=<?= $bill->getCommunityRequestId() ?>" title="Связан с заявкой общины #<?= $bill->getCommunityRequestId() ?>" style="margin-left: 5px; color: #667eea; text-decoration: none;">🏘️</a>
                                    <?php endif; ?>
                                </td>
                                <td>
                                    <?php if ($holder): ?>
                                        <a href="/user.php?id=<?= $holder->getId() ?>" style="color: #667eea; text-decoration: none;">
                                            <?= htmlspecialchars($holder->getFullName()) ?>
                                        </a>
                                    <?php else: ?>
                                        Пользователь #<?= $bill->getHolderId() ?>
                                    <?php endif; ?>
                                </td>
                                <td><?= number_format($bill->getNominal(), 2, '.', ' ') ?> ₽</td>
                                <td><?= date('d.m.Y', strtotime($bill->getIssueDate())) ?></td>
                                <td><?= date('d.m.Y', strtotime($bill->getMaturityDate())) ?></td>
                                <td>
                                    <span class="status-badge status-<?= $bill->getStatus() ?>">
                                        <?php
                                        $statuses = [
                                            'active' => 'Активен',
                                            'paid' => 'Погашен',
                                            'overdue' => 'Просрочен',
                                            'cancelled' => 'Отменён'
                                        ];
                                        echo $statuses[$bill->getStatus()] ?? $bill->getStatus();
                                        ?>
                                    </span>
                                    <?php if ($bill->isFromCommunity()): ?>
                                        <span class="status-badge" style="background: #667eea; color: white; font-size: 0.75em; margin-left: 5px;" title="Вексель создан через общину">Община</span>
                                    <?php endif; ?>
                                </td>
                                <td>
                                    <div style="display: flex; gap: 5px; align-items: center; flex-wrap: wrap;">
                                        <a href="/api/bill_pdf.php?id=<?= $bill->getId() ?>" 
                                           class="btn btn-small" 
                                           style="background: #667eea; color: white; text-decoration: none; padding: 4px 8px; font-size: 0.85em;"
                                           target="_blank"
                                           title="Скачать PDF векселя">
                                            📄 PDF
                                        </a>
                                        <?php if ($bill->getStatus() === 'active'): ?>
                                            <form method="POST" style="display:inline;">
                                                <?= csrf_field() ?>
                                                <input type="hidden" name="bill_id" value="<?= $bill->getId() ?>">
                                                <button type="submit" name="pay_bill" class="btn btn-small">Погасить</button>
                                            </form>
                                        <?php endif; ?>
                                    </div>
                                </td>
                            </tr>
                        <?php endforeach; ?>
                    <?php endforeach; ?>
                    
                    <?php if (!empty($billsWithoutTransaction)): ?>
                        <tr class="transaction-group-header" style="background: #f8f9fa; border-top: 2px solid #ddd;">
                            <td colspan="7" style="padding: 12px 16px;">
                                <div style="display: flex; align-items: center; gap: 12px;">
                                    <span style="font-weight: 600; color: #666;">📋 Вексели без транзакции</span>
                                    <span style="margin-left: auto; color: #666; font-weight: 600;">
                                        <?= count($billsWithoutTransaction) ?> векселей
                                    </span>
                                </div>
                            </td>
                        </tr>
                        <?php foreach ($billsWithoutTransaction as $bill): 
                            $holder = $holdersMap[$bill->getHolderId()] ?? null;
                        ?>
                            <tr class="transaction-group-item">
                                <td>
                                    #<?= $bill->getId() ?>
                                    <?php if ($bill->isFromCommunity()): ?>
                                        <a href="/community.php?id=<?= $bill->getCommunityRequestId() ?>" title="Связан с заявкой общины #<?= $bill->getCommunityRequestId() ?>" style="margin-left: 5px; color: #667eea; text-decoration: none;">🏘️</a>
                                    <?php endif; ?>
                                </td>
                                <td>
                                    <?php if ($holder): ?>
                                        <a href="/user.php?id=<?= $holder->getId() ?>" style="color: #667eea; text-decoration: none;">
                                            <?= htmlspecialchars($holder->getFullName()) ?>
                                        </a>
                                    <?php else: ?>
                                        Пользователь #<?= $bill->getHolderId() ?>
                                    <?php endif; ?>
                                </td>
                                <td><?= number_format($bill->getNominal(), 2, '.', ' ') ?> ₽</td>
                                <td><?= date('d.m.Y', strtotime($bill->getIssueDate())) ?></td>
                                <td><?= date('d.m.Y', strtotime($bill->getMaturityDate())) ?></td>
                                <td>
                                    <span class="status-badge status-<?= $bill->getStatus() ?>">
                                        <?php
                                        $statuses = [
                                            'active' => 'Активен',
                                            'paid' => 'Погашен',
                                            'overdue' => 'Просрочен',
                                            'cancelled' => 'Отменён'
                                        ];
                                        echo $statuses[$bill->getStatus()] ?? $bill->getStatus();
                                        ?>
                                    </span>
                                    <?php if ($bill->isFromCommunity()): ?>
                                        <span class="status-badge" style="background: #667eea; color: white; font-size: 0.75em; margin-left: 5px;" title="Вексель создан через общину">Община</span>
                                    <?php endif; ?>
                                </td>
                                <td>
                                    <div style="display: flex; gap: 5px; align-items: center; flex-wrap: wrap;">
                                        <a href="/api/bill_pdf.php?id=<?= $bill->getId() ?>" 
                                           class="btn btn-small" 
                                           style="background: #667eea; color: white; text-decoration: none; padding: 4px 8px; font-size: 0.85em;"
                                           target="_blank"
                                           title="Скачать PDF векселя">
                                            📄 PDF
                                        </a>
                                        <?php if ($bill->getStatus() === 'active'): ?>
                                            <form method="POST" style="display:inline;">
                                                <?= csrf_field() ?>
                                                <input type="hidden" name="bill_id" value="<?= $bill->getId() ?>">
                                                <button type="submit" name="pay_bill" class="btn btn-small">Погасить</button>
                                            </form>
                                        <?php endif; ?>
                                    </div>
                                </td>
                            </tr>
                        <?php endforeach; ?>
                    <?php endif; ?>
                <?php endif; ?>
            </tbody>
        </table>
    </div>
    
    <div id="held-tab" class="tab-content">
        <h3>Вексели на моём счету (мои активы)</h3>
        <table class="bills-table">
            <thead>
                <tr>
                    <th>ID</th>
                    <th>Выпустил</th>
                    <th>Номинал</th>
                    <th>Получен</th>
                    <th>Срок погашения</th>
                    <th>Статус</th>
                    <th>Действия</th>
                </tr>
            </thead>
            <tbody>
                <?php if (empty($heldBills)): ?>
                    <tr><td colspan="7" class="text-center">Нет векселей на счету</td></tr>
                <?php else: ?>
                    <?php 
                    // Предзагружаем данные пользователей для оптимизации
                    $issuerIds = array_unique(array_map(fn($b) => $b->getIssuerId(), $heldBills));
                    $issuersMap = [];
                    foreach ($issuerIds as $issuerId) {
                        $issuer = User::findById($issuerId);
                        if ($issuer) {
                            $issuersMap[$issuerId] = $issuer;
                        }
                    }
                    
                    // Группируем вексели по транзакциям
                    $heldBillsByTransaction = [];
                    $heldBillsWithoutTransaction = [];
                    
                    foreach ($heldBills as $bill) {
                        $billId = $bill->getId();
                        $relatedTransaction = null;
                        
                        foreach ($transactionBillsMap as $transId => $billIds) {
                            if (in_array($billId, $billIds)) {
                                $relatedTransaction = $transactionsById[$transId] ?? null;
                                break;
                            }
                        }
                        
                        if ($relatedTransaction) {
                            $transId = $relatedTransaction->getId();
                            if (!isset($heldBillsByTransaction[$transId])) {
                                $heldBillsByTransaction[$transId] = [
                                    'transaction' => $relatedTransaction,
                                    'bills' => []
                                ];
                            }
                            $heldBillsByTransaction[$transId]['bills'][] = $bill;
                        } else {
                            $heldBillsWithoutTransaction[] = $bill;
                        }
                    }
                    
                    // Предзагружаем участников транзакций для оптимизации
                    $heldTransactionParticipants = [];
                    foreach ($heldBillsByTransaction as $transId => $group) {
                        $trans = $group['transaction'];
                        if (!isset($heldTransactionParticipants[$trans->getSellerId()])) {
                            $seller = User::findById($trans->getSellerId());
                            if ($seller) {
                                $heldTransactionParticipants[$trans->getSellerId()] = $seller;
                            }
                        }
                        if (!isset($heldTransactionParticipants[$trans->getBuyerId()])) {
                            $buyer = User::findById($trans->getBuyerId());
                            if ($buyer) {
                                $heldTransactionParticipants[$trans->getBuyerId()] = $buyer;
                            }
                        }
                    }
                    
                    // Выводим вексели, сгруппированные по транзакциям
                    foreach ($heldBillsByTransaction as $transId => $group): 
                        $transaction = $group['transaction'];
                        $seller = $heldTransactionParticipants[$transaction->getSellerId()] ?? User::findById($transaction->getSellerId());
                        $buyer = $heldTransactionParticipants[$transaction->getBuyerId()] ?? User::findById($transaction->getBuyerId());
                        $transactionBills = $group['bills'];
                        $totalAmount = array_sum(array_map(fn($b) => $b->getNominal(), $transactionBills));
                    ?>
                        <tr class="transaction-group-header" style="background: #f0f4ff; border-top: 2px solid #667eea;">
                            <td colspan="6" style="padding: 12px 16px;">
                                <div style="display: flex; align-items: center; gap: 12px; flex-wrap: wrap;">
                                    <span style="font-weight: 600; color: #667eea;">💼 Транзакция #<?= $transaction->getId() ?></span>
                                    <span style="color: #666;">•</span>
                                    <span style="color: #333;">
                                        <?= htmlspecialchars($seller->getFullName()) ?> → <?= htmlspecialchars($buyer->getFullName()) ?>
                                    </span>
                                    <?php if ($transaction->getDescription()): ?>
                                        <span style="color: #666;">•</span>
                                        <span style="color: #666; font-style: italic;"><?= htmlspecialchars(mb_substr($transaction->getDescription(), 0, 50)) ?><?= mb_strlen($transaction->getDescription()) > 50 ? '...' : '' ?></span>
                                    <?php endif; ?>
                                    <span style="margin-left: auto; color: #10b981; font-weight: 600;">
                                        <?= count($transactionBills) ?> векселей на <?= number_format($totalAmount, 2, '.', ' ') ?> ₽
                                    </span>
                                    <a href="/transactions.php?id=<?= $transaction->getId() ?>" style="color: #667eea; text-decoration: none; font-size: 0.9em;">
                                        Перейти →
                                    </a>
                                </div>
                            </td>
                        </tr>
                        <?php foreach ($transactionBills as $bill): 
                            $issuer = $issuersMap[$bill->getIssuerId()] ?? null;
                        ?>
                            <tr class="transaction-group-item">
                                <td>
                                    #<?= $bill->getId() ?>
                                    <?php if ($bill->isFromCommunity()): ?>
                                        <a href="/community.php?id=<?= $bill->getCommunityRequestId() ?>" title="Связан с заявкой общины #<?= $bill->getCommunityRequestId() ?>" style="margin-left: 5px; color: #667eea; text-decoration: none;">🏘️</a>
                                    <?php endif; ?>
                                </td>
                                <td>
                                    <?php if ($issuer): ?>
                                        <a href="/user.php?id=<?= $issuer->getId() ?>" style="color: #667eea; text-decoration: none;">
                                            <?= htmlspecialchars($issuer->getFullName()) ?>
                                        </a>
                                    <?php else: ?>
                                        Пользователь #<?= $bill->getIssuerId() ?>
                                    <?php endif; ?>
                                </td>
                                <td><?= number_format($bill->getNominal(), 2, '.', ' ') ?> ₽</td>
                                <td><?= date('d.m.Y', strtotime($bill->getIssueDate())) ?></td>
                                <td><?= date('d.m.Y', strtotime($bill->getMaturityDate())) ?></td>
                                <td>
                                    <span class="status-badge status-<?= $bill->getStatus() ?>">
                                        <?php
                                        $statuses = [
                                            'active' => 'Активен',
                                            'paid' => 'Погашен',
                                            'overdue' => 'Просрочен',
                                            'cancelled' => 'Отменён'
                                        ];
                                        echo $statuses[$bill->getStatus()] ?? $bill->getStatus();
                                        ?>
                                    </span>
                                    <?php if ($bill->isFromCommunity()): ?>
                                        <span class="status-badge" style="background: #667eea; color: white; font-size: 0.75em; margin-left: 5px;" title="Вексель создан через общину">Община</span>
                                    <?php endif; ?>
                                </td>
                                <td>
                                    <a href="/api/bill_pdf.php?id=<?= $bill->getId() ?>" 
                                       class="btn btn-small" 
                                       style="background: #667eea; color: white; text-decoration: none; padding: 4px 8px; font-size: 0.85em;"
                                       target="_blank"
                                       title="Скачать PDF векселя">
                                        📄 PDF
                                    </a>
                                </td>
                            </tr>
                        <?php endforeach; ?>
                    <?php endforeach; ?>
                    
                    <?php if (!empty($heldBillsWithoutTransaction)): ?>
                        <tr class="transaction-group-header" style="background: #f8f9fa; border-top: 2px solid #ddd;">
                            <td colspan="7" style="padding: 12px 16px;">
                                <div style="display: flex; align-items: center; gap: 12px;">
                                    <span style="font-weight: 600; color: #666;">📋 Вексели без транзакции</span>
                                    <span style="margin-left: auto; color: #666; font-weight: 600;">
                                        <?= count($heldBillsWithoutTransaction) ?> векселей
                                    </span>
                                </div>
                            </td>
                        </tr>
                        <?php foreach ($heldBillsWithoutTransaction as $bill): 
                            $issuer = $issuersMap[$bill->getIssuerId()] ?? null;
                        ?>
                            <tr class="transaction-group-item">
                                <td>
                                    #<?= $bill->getId() ?>
                                    <?php if ($bill->isFromCommunity()): ?>
                                        <a href="/community.php?id=<?= $bill->getCommunityRequestId() ?>" title="Связан с заявкой общины #<?= $bill->getCommunityRequestId() ?>" style="margin-left: 5px; color: #667eea; text-decoration: none;">🏘️</a>
                                    <?php endif; ?>
                                </td>
                                <td>
                                    <?php if ($issuer): ?>
                                        <a href="/user.php?id=<?= $issuer->getId() ?>" style="color: #667eea; text-decoration: none;">
                                            <?= htmlspecialchars($issuer->getFullName()) ?>
                                        </a>
                                    <?php else: ?>
                                        Пользователь #<?= $bill->getIssuerId() ?>
                                    <?php endif; ?>
                                </td>
                                <td><?= number_format($bill->getNominal(), 2, '.', ' ') ?> ₽</td>
                                <td><?= date('d.m.Y', strtotime($bill->getIssueDate())) ?></td>
                                <td><?= date('d.m.Y', strtotime($bill->getMaturityDate())) ?></td>
                                <td>
                                    <span class="status-badge status-<?= $bill->getStatus() ?>">
                                        <?php
                                        $statuses = [
                                            'active' => 'Активен',
                                            'paid' => 'Погашен',
                                            'overdue' => 'Просрочен',
                                            'cancelled' => 'Отменён'
                                        ];
                                        echo $statuses[$bill->getStatus()] ?? $bill->getStatus();
                                        ?>
                                    </span>
                                    <?php if ($bill->isFromCommunity()): ?>
                                        <span class="status-badge" style="background: #667eea; color: white; font-size: 0.75em; margin-left: 5px;" title="Вексель создан через общину">Община</span>
                                    <?php endif; ?>
                                </td>
                                <td>
                                    <a href="/api/bill_pdf.php?id=<?= $bill->getId() ?>" 
                                       class="btn btn-small" 
                                       style="background: #667eea; color: white; text-decoration: none; padding: 4px 8px; font-size: 0.85em;"
                                       target="_blank"
                                       title="Скачать PDF векселя">
                                        📄 PDF
                                    </a>
                                </td>
                            </tr>
                        <?php endforeach; ?>
                    <?php endif; ?>
                <?php endif; ?>
            </tbody>
        </table>
    </div>
</div>

<script>
function showTab(tab) {
    document.querySelectorAll('.tab-content').forEach(t => t.classList.remove('active'));
    document.querySelectorAll('.tab-btn').forEach(b => b.classList.remove('active'));
    
    document.getElementById(tab + '-tab').classList.add('active');
    event.target.classList.add('active');
}
</script>
<?php
$content = ob_get_clean();
include __DIR__ . '/../templates/base.php';

