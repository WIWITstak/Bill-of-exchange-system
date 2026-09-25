<?php
/**
 * Депозитарий - страница со всеми вексельными сделками
 */

require_once __DIR__ . '/../src/bootstrap.php';

use OGAS\Services\Auth;
use OGAS\Models\Transaction;
use OGAS\Models\Bill;
use OGAS\Models\User;
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

// Фильтры
$statusFilter = $_GET['status'] ?? null;
$searchQuery = trim($_GET['search'] ?? '');
$dateFrom = $_GET['date_from'] ?? null;
$dateTo = $_GET['date_to'] ?? null;
$amountFrom = $_GET['amount_from'] ?? null;
$amountTo = $_GET['amount_to'] ?? null;
$maturityFilter = $_GET['maturity'] ?? null; // 'all', 'overdue', 'soon', 'normal'
$page = (int)($_GET['page'] ?? 1);
$limit = 20;
$offset = ($page - 1) * $limit;

// Получаем транзакции со статусом active или completed (где есть вексели)
$db = \OGAS\Database::getConnection();

// Строим запрос для поиска транзакций с векселями
// Используем подзапрос для получения всех векселей по транзакции
$sql = "
    SELECT DISTINCT t.*
    FROM transactions t
    WHERE EXISTS (
        SELECT 1 FROM bills b 
        WHERE (
            (b.issuer_id = t.seller_id AND b.holder_id = t.buyer_id) OR
            (b.issuer_id = t.buyer_id AND b.holder_id = t.seller_id)
        )
    )
    AND (t.status = 'active' OR t.status = 'completed')
    AND t.seller_confirmed = 1 
    AND t.buyer_confirmed = 1
";

$params = [];

if ($statusFilter) {
    $sql .= " AND t.status = ?";
    $params[] = $statusFilter;
}

if ($searchQuery) {
    $searchTerm = '%' . $searchQuery . '%';
    $sql .= " AND (t.description LIKE ? OR t.id = ?)";
    $params[] = $searchTerm;
    $params[] = (int)$searchQuery;
}

// Фильтр по дате создания
if ($dateFrom) {
    $sql .= " AND DATE(t.created_at) >= ?";
    $params[] = $dateFrom;
}

if ($dateTo) {
    $sql .= " AND DATE(t.created_at) <= ?";
    $params[] = $dateTo;
}

// Фильтр по сумме векселя
if ($amountFrom !== null && $amountFrom !== '') {
    $sql .= " AND EXISTS (
        SELECT 1 FROM bills b 
        WHERE (
            (b.issuer_id = t.seller_id AND b.holder_id = t.buyer_id) OR
            (b.issuer_id = t.buyer_id AND b.holder_id = t.seller_id)
        ) AND b.nominal >= ?
    )";
    $params[] = (float)$amountFrom;
}

if ($amountTo !== null && $amountTo !== '') {
    $sql .= " AND EXISTS (
        SELECT 1 FROM bills b 
        WHERE (
            (b.issuer_id = t.seller_id AND b.holder_id = t.buyer_id) OR
            (b.issuer_id = t.buyer_id AND b.holder_id = t.seller_id)
        ) AND b.nominal <= ?
    )";
    $params[] = (float)$amountTo;
}

// Фильтр по сроку погашения
if ($maturityFilter) {
    $now = date('Y-m-d');
    switch ($maturityFilter) {
        case 'overdue':
            $sql .= " AND EXISTS (
                SELECT 1 FROM bills b 
                WHERE (
                    (b.issuer_id = t.seller_id AND b.holder_id = t.buyer_id) OR
                    (b.issuer_id = t.buyer_id AND b.holder_id = t.seller_id)
                ) AND b.maturity_date < ?
            )";
            $params[] = $now;
            break;
        case 'soon':
            $sevenDays = date('Y-m-d', strtotime('+7 days'));
            $sql .= " AND EXISTS (
                SELECT 1 FROM bills b 
                WHERE (
                    (b.issuer_id = t.seller_id AND b.holder_id = t.buyer_id) OR
                    (b.issuer_id = t.buyer_id AND b.holder_id = t.seller_id)
                ) AND b.maturity_date >= ? AND b.maturity_date <= ?
            )";
            $params[] = $now;
            $params[] = $sevenDays;
            break;
        case 'normal':
            $sevenDays = date('Y-m-d', strtotime('+7 days'));
            $sql .= " AND EXISTS (
                SELECT 1 FROM bills b 
                WHERE (
                    (b.issuer_id = t.seller_id AND b.holder_id = t.buyer_id) OR
                    (b.issuer_id = t.buyer_id AND b.holder_id = t.seller_id)
                ) AND b.maturity_date > ?
            )";
            $params[] = $sevenDays;
            break;
    }
}

$sql .= " ORDER BY t.created_at DESC LIMIT ? OFFSET ?";
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
    SELECT COUNT(DISTINCT t.id) 
    FROM transactions t
    WHERE EXISTS (
        SELECT 1 FROM bills b 
        WHERE (
            (b.issuer_id = t.seller_id AND b.holder_id = t.buyer_id) OR
            (b.issuer_id = t.buyer_id AND b.holder_id = t.seller_id)
        )
    )
    AND (t.status = 'active' OR t.status = 'completed')
    AND t.seller_confirmed = 1 
    AND t.buyer_confirmed = 1
";

$countParams = [];

if ($statusFilter) {
    $countSql .= " AND t.status = ?";
    $countParams[] = $statusFilter;
}

if ($searchQuery) {
    $searchTerm = '%' . $searchQuery . '%';
    $countSql .= " AND (t.description LIKE ? OR t.id = ?)";
    $countParams[] = $searchTerm;
    $countParams[] = (int)$searchQuery;
}

// Фильтр по дате создания
if ($dateFrom) {
    $countSql .= " AND DATE(t.created_at) >= ?";
    $countParams[] = $dateFrom;
}

if ($dateTo) {
    $countSql .= " AND DATE(t.created_at) <= ?";
    $countParams[] = $dateTo;
}

// Фильтр по сумме векселя
if ($amountFrom !== null && $amountFrom !== '') {
    $countSql .= " AND EXISTS (
        SELECT 1 FROM bills b 
        WHERE (
            (b.issuer_id = t.seller_id AND b.holder_id = t.buyer_id) OR
            (b.issuer_id = t.buyer_id AND b.holder_id = t.seller_id)
        ) AND b.nominal >= ?
    )";
    $countParams[] = (float)$amountFrom;
}

if ($amountTo !== null && $amountTo !== '') {
    $countSql .= " AND EXISTS (
        SELECT 1 FROM bills b 
        WHERE (
            (b.issuer_id = t.seller_id AND b.holder_id = t.buyer_id) OR
            (b.issuer_id = t.buyer_id AND b.holder_id = t.seller_id)
        ) AND b.nominal <= ?
    )";
    $countParams[] = (float)$amountTo;
}

// Фильтр по сроку погашения
if ($maturityFilter) {
    $now = date('Y-m-d');
    switch ($maturityFilter) {
        case 'overdue':
            $countSql .= " AND EXISTS (
                SELECT 1 FROM bills b 
                WHERE (
                    (b.issuer_id = t.seller_id AND b.holder_id = t.buyer_id) OR
                    (b.issuer_id = t.buyer_id AND b.holder_id = t.seller_id)
                ) AND b.maturity_date < ?
            )";
            $countParams[] = $now;
            break;
        case 'soon':
            $sevenDays = date('Y-m-d', strtotime('+7 days'));
            $countSql .= " AND EXISTS (
                SELECT 1 FROM bills b 
                WHERE (
                    (b.issuer_id = t.seller_id AND b.holder_id = t.buyer_id) OR
                    (b.issuer_id = t.buyer_id AND b.holder_id = t.seller_id)
                ) AND b.maturity_date >= ? AND b.maturity_date <= ?
            )";
            $countParams[] = $now;
            $countParams[] = $sevenDays;
            break;
        case 'normal':
            $sevenDays = date('Y-m-d', strtotime('+7 days'));
            $countSql .= " AND EXISTS (
                SELECT 1 FROM bills b 
                WHERE (
                    (b.issuer_id = t.seller_id AND b.holder_id = t.buyer_id) OR
                    (b.issuer_id = t.buyer_id AND b.holder_id = t.seller_id)
                ) AND b.maturity_date > ?
            )";
            $countParams[] = $sevenDays;
            break;
    }
}

$countStmt = $db->prepare($countSql);
$countStmt->execute($countParams);
$totalTransactions = (int)$countStmt->fetchColumn();
$totalPages = ceil($totalTransactions / $limit);

// Статистика
$stats = [
    'total' => $totalTransactions,
    'active' => 0,
    'completed' => 0,
    'total_bills_nominal' => 0
];

// Получаем все вексели для статистики одним запросом
$statsSql = "
    SELECT b.nominal, t.id as transaction_id, t.status as transaction_status
    FROM bills b
    INNER JOIN transactions t ON (
        (b.issuer_id = t.seller_id AND b.holder_id = t.buyer_id) OR
        (b.issuer_id = t.buyer_id AND b.holder_id = t.seller_id)
    )
    WHERE (t.status = 'active' OR t.status = 'completed')
    AND t.seller_confirmed = 1 
    AND t.buyer_confirmed = 1
";

$statsParams = [];

$billsStmt = $db->prepare($statsSql);
$billsStmt->execute($statsParams);
$billsData = $billsStmt->fetchAll(\PDO::FETCH_ASSOC);

$processedTransactions = [];
foreach ($billsData as $billData) {
    $transactionId = $billData['transaction_id'];
    if (!isset($processedTransactions[$transactionId])) {
        $processedTransactions[$transactionId] = true;
        if ($billData['transaction_status'] === 'active') {
            $stats['active']++;
        } elseif ($billData['transaction_status'] === 'completed') {
            $stats['completed']++;
        }
    }
    $stats['total_bills_nominal'] += (float)$billData['nominal'];
}

$title = 'Депозитарий - Реестр векселей и договоров';
ob_start();
?>
<div class="dashboard">
    <div class="dashboard-header">
        <h2>Депозитарий - Реестр векселей и договоров</h2>
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

    <!-- Статистика -->
    <div class="catalog-info">
        <div class="catalog-stats">
            <div class="stat-item">
                <i class="fas fa-file-contract"></i>
                <span>Всего договоров: <strong><?= $stats['total'] ?></strong></span>
            </div>
            <div class="stat-item">
                <i class="fas fa-check-circle"></i>
                <span>Активных договоров: <strong><?= $stats['active'] ?></strong></span>
            </div>
            <div class="stat-item">
                <i class="fas fa-check-double"></i>
                <span>Завершённых договоров: <strong><?= $stats['completed'] ?></strong></span>
            </div>
            <div class="stat-item">
                <i class="fas fa-ruble-sign"></i>
                <span>Сумма векселей в реестре: <strong><?= number_format($stats['total_bills_nominal'], 0, '.', ' ') ?> ₽</strong></span>
            </div>
        </div>
    </div>

    <!-- Компактные фильтры -->
    <?php 
    $hasActiveFilters = !empty($searchQuery) || !empty($statusFilter) || !empty($dateFrom) || !empty($dateTo) || !empty($amountFrom) || !empty($amountTo) || !empty($maturityFilter);
    ?>
    <div class="depository-filters-wrapper filters-wrapper" data-has-filters="<?= $hasActiveFilters ? 'true' : 'false' ?>">
        <div class="filters-toggle-header <?= !$hasActiveFilters ? 'collapsed' : '' ?>">
            <div class="filters-toggle-title">
                <i class="fas fa-filter filters-toggle-icon"></i>
                <span>Фильтры<?= $hasActiveFilters ? ' <span style="color: var(--color-primary); font-weight: 600;">(активны)</span>' : '' ?></span>
            </div>
        </div>
        <div class="search-filters-card-compact search-filters-card <?= !$hasActiveFilters ? 'collapsed' : 'expanded' ?>"<?= !$hasActiveFilters ? ' style="max-height: 0; padding: 0; margin: 0; opacity: 0; overflow: hidden;"' : '' ?>>
            <form method="GET" action="/depository.php" class="search-filters-form-compact" id="depositoryFilterForm">
                <div class="filters-row-compact">
                    <div class="search-input-group-compact">
                        <div class="search-icon-compact">🔍</div>
                        <input type="text" 
                               id="search" 
                               name="search" 
                               class="search-input-compact"
                               value="<?= htmlspecialchars($searchQuery) ?>"
                               placeholder="Поиск по описанию или ID..." 
                               autocomplete="off">
                        <?php if (!empty($searchQuery)): ?>
                            <button type="button" class="search-clear-compact" onclick="clearDepositorySearch()" title="Очистить">×</button>
                        <?php endif; ?>
                    </div>
                    
                    <select name="status" id="status" class="filter-select-compact">
                        <option value="">Все статусы</option>
                        <option value="active" <?= $statusFilter === 'active' ? 'selected' : '' ?>>Активные</option>
                        <option value="completed" <?= $statusFilter === 'completed' ? 'selected' : '' ?>>Завершённые</option>
                    </select>
                    
                    <div class="date-range-compact">
                        <input type="date" 
                               name="date_from" 
                               id="date_from" 
                               class="filter-input-compact date-input"
                               value="<?= htmlspecialchars($dateFrom ?? '') ?>"
                               title="Дата от">
                        <span class="date-separator">—</span>
                        <input type="date" 
                               name="date_to" 
                               id="date_to" 
                               class="filter-input-compact date-input"
                               value="<?= htmlspecialchars($dateTo ?? '') ?>"
                               title="Дата до">
                    </div>
                    
                    <div class="amount-range-compact">
                        <input type="number" 
                               name="amount_from" 
                               id="amount_from" 
                               class="filter-input-compact amount-input"
                               step="0.01"
                               min="0"
                               placeholder="От"
                               value="<?= htmlspecialchars($amountFrom ?? '') ?>"
                               title="Сумма от">
                        <span class="amount-separator">—</span>
                        <input type="number" 
                               name="amount_to" 
                               id="amount_to" 
                               class="filter-input-compact amount-input"
                               step="0.01"
                               min="0"
                               placeholder="До"
                               value="<?= htmlspecialchars($amountTo ?? '') ?>"
                               title="Сумма до">
                    </div>
                    
                    <select name="maturity" id="maturity" class="filter-select-compact">
                        <option value="">Все сроки</option>
                        <option value="overdue" <?= $maturityFilter === 'overdue' ? 'selected' : '' ?>>Просроченные</option>
                        <option value="soon" <?= $maturityFilter === 'soon' ? 'selected' : '' ?>>Скоро</option>
                        <option value="normal" <?= $maturityFilter === 'normal' ? 'selected' : '' ?>>Нормальный</option>
                    </select>
                    
                    <button type="submit" class="btn-filter-submit-compact" title="Применить фильтры">
                        🔍
                    </button>
                    
                    <?php if ($searchQuery || $statusFilter || $dateFrom || $dateTo || $amountFrom || $amountTo || $maturityFilter): ?>
                        <a href="/depository.php" class="btn-filter-reset-compact" title="Сбросить фильтры">
                            ✕
                        </a>
                    <?php endif; ?>
                </div>
            </form>
        </div>
        </div>
    </div>
    
    <script>
    function clearDepositorySearch() {
        document.getElementById('search').value = '';
        document.getElementById('depositoryFilterForm').submit();
    }
    
    // Управление выбором векселей
    function toggleSelectAll(checkbox) {
        const transactionCheckboxes = document.querySelectorAll('.transaction-checkbox');
        transactionCheckboxes.forEach(cb => {
            cb.checked = checkbox.checked;
        });
        updateBulkActions();
    }
    
    function updateBulkActions() {
        const selectedTransactions = Array.from(document.querySelectorAll('.transaction-checkbox:checked'))
            .map(cb => parseInt(cb.dataset.transactionId));
        
        const bulkActions = document.getElementById('bulkActions');
        const selectedCount = document.getElementById('selectedCount');
        
        if (bulkActions) {
            if (selectedTransactions.length > 0) {
                bulkActions.style.display = 'flex';
                if (selectedCount) {
                    selectedCount.textContent = `Выбрано договоров: ${selectedTransactions.length}`;
                }
            } else {
                bulkActions.style.display = 'none';
            }
        }
        
        // Обновляем состояние "Выбрать все"
        const allTransactions = document.querySelectorAll('.transaction-checkbox');
        const selectAll = document.getElementById('selectAll');
        if (allTransactions.length > 0 && selectAll) {
            selectAll.checked = selectedTransactions.length === allTransactions.length;
            selectAll.indeterminate = selectedTransactions.length > 0 && selectedTransactions.length < allTransactions.length;
        }
    }
    
    function downloadSelectedBills() {
        // Получаем выбранные договоры и загружаем их вексели
        const selectedTransactions = Array.from(document.querySelectorAll('.transaction-checkbox:checked'))
            .map(cb => parseInt(cb.dataset.transactionId));
        
        if (selectedTransactions.length === 0) {
            alert('Выберите договоры для скачивания векселей');
            return;
        }
        
        // Открываем первый договор в модальном окне
        if (selectedTransactions.length > 0) {
            openTransactionBills(selectedTransactions[0]);
            alert('Откройте модальное окно и выберите вексели для скачивания');
        }
    }
    
    function clearSelection() {
        document.querySelectorAll('.transaction-checkbox').forEach(cb => {
            cb.checked = false;
        });
        const selectAll = document.getElementById('selectAll');
        if (selectAll) {
            selectAll.checked = false;
            selectAll.indeterminate = false;
        }
        updateBulkActions();
    }
    
    // Открытие модального окна с векселями
    function openTransactionBills(transactionId) {
        const modal = document.getElementById('billsModal');
        const modalTitle = document.getElementById('billsModalTitle');
        const modalTable = document.getElementById('billsModalTable');
        const modalBulkActions = document.getElementById('modalBulkActions');
        
        if (!modal || !modalTitle || !modalTable) return;
        
        // Показываем модальное окно
        modal.style.display = 'flex';
        document.body.style.overflow = 'hidden';
        
        // Скрываем панель массовых действий
        if (modalBulkActions) {
            modalBulkActions.style.display = 'none';
        }
        
        // Устанавливаем заголовок
        modalTitle.textContent = 'Загрузка...';
        
        // Очищаем таблицу
        modalTable.innerHTML = '<tr><td colspan="6" style="text-align: center; padding: 20px;">Загрузка векселей...</td></tr>';
        
        // Загружаем вексели через API
        fetch(`/api/transaction_bills.php?transaction_id=${transactionId}`)
            .then(response => response.json())
            .then(data => {
                if (data.success) {
                    modalTitle.textContent = `Договор #${data.transaction.id} - Вексели (${data.bills.length})`;
                    
                    if (data.bills.length === 0) {
                        modalTable.innerHTML = '<tr><td colspan="6" style="text-align: center; padding: 20px; color: #718096;">Вексели не найдены</td></tr>';
                    } else {
                        let html = '';
                        data.bills.forEach(bill => {
                            const maturityClass = bill.maturity_info.includes('Просрочен') ? 'bill-expired' : 
                                                 bill.maturity_info.includes('Через') ? 'bill-soon' : 
                                                 bill.maturity_info === 'Сегодня' ? 'bill-today' : '';
                            
                            html += `
                                <tr>
                                    <td>
                                        <input type="checkbox" 
                                               class="modal-bill-checkbox" 
                                               data-bill-id="${bill.id}"
                                               onchange="updateModalBulkActions()">
                                    </td>
                                    <td>
                                        <a href="/bills.php?id=${bill.id}" class="table-bill-link">
                                            📄 #${bill.id}
                                        </a>
                                    </td>
                                    <td>${parseFloat(bill.nominal).toLocaleString('ru-RU', {minimumFractionDigits: 2, maximumFractionDigits: 2})} ₽</td>
                                    <td><span class="${maturityClass}">${bill.maturity_info}</span></td>
                                    <td>
                                        <span class="status status-${bill.status}">${bill.status_label}</span>
                                    </td>
                                    <td>
                                        <a href="${bill.pdf_url}" 
                                           class="btn btn-small" 
                                           style="background: #667eea; color: white; text-decoration: none; padding: 3px 8px; font-size: 0.8em; border-radius: 4px;"
                                           target="_blank"
                                           title="Скачать PDF векселя">
                                            📥 PDF
                                        </a>
                                    </td>
                                </tr>
                            `;
                        });
                        modalTable.innerHTML = html;
                    }
                } else {
                    modalTitle.textContent = 'Ошибка';
                    modalTable.innerHTML = `<tr><td colspan="6" style="text-align: center; padding: 20px; color: #e53e3e;">${data.error || 'Не удалось загрузить вексели'}</td></tr>`;
                }
            })
            .catch(error => {
                modalTitle.textContent = 'Ошибка';
                modalTable.innerHTML = '<tr><td colspan="6" style="text-align: center; padding: 20px; color: #e53e3e;">Ошибка загрузки данных</td></tr>';
                console.error('Error loading bills:', error);
            });
    }
    
    // Закрытие модального окна
    function closeBillsModal() {
        const modal = document.getElementById('billsModal');
        if (modal) {
            modal.style.display = 'none';
            document.body.style.overflow = '';
        }
    }
    
    // Закрытие по клику вне модального окна
    window.onclick = function(event) {
        const modal = document.getElementById('billsModal');
        if (event.target === modal) {
            closeBillsModal();
        }
    }
    
    // Закрытие по ESC
    document.addEventListener('keydown', function(event) {
        if (event.key === 'Escape') {
            closeBillsModal();
        }
    });
    
    function updateModalBulkActions() {
        const selectedBills = Array.from(document.querySelectorAll('.modal-bill-checkbox:checked'))
            .map(cb => parseInt(cb.dataset.billId));
        
        const modalBulkActions = document.getElementById('modalBulkActions');
        const modalSelectedCount = document.getElementById('modalSelectedCount');
        
        if (modalBulkActions) {
            if (selectedBills.length > 0) {
                modalBulkActions.style.display = 'flex';
                if (modalSelectedCount) {
                    modalSelectedCount.textContent = `Выбрано: ${selectedBills.length}`;
                }
            } else {
                modalBulkActions.style.display = 'none';
            }
        }
    }
    
    function downloadSelectedModalBills() {
        const selectedBills = Array.from(document.querySelectorAll('.modal-bill-checkbox:checked'))
            .map(cb => parseInt(cb.dataset.billId));
        
        if (selectedBills.length === 0) {
            alert('Выберите вексели для скачивания');
            return;
        }
        
        if (selectedBills.length > 100) {
            alert('Можно скачать максимум 100 векселей за раз');
            return;
        }
        
        // Создаём форму для отправки POST запроса
        const form = document.createElement('form');
        form.method = 'POST';
        form.action = '/api/bills_bulk_pdf.php';
        
        selectedBills.forEach(billId => {
            const input = document.createElement('input');
            input.type = 'hidden';
            input.name = 'bill_ids[]';
            input.value = billId;
            form.appendChild(input);
        });
        
        document.body.appendChild(form);
        form.submit();
        document.body.removeChild(form);
    }
    
    // Скачивание всех векселей договора
    function downloadAllTransactionBills(transactionId) {
        // Получаем все вексели для договора через API
        fetch(`/api/transaction_bills.php?transaction_id=${transactionId}`)
            .then(response => response.json())
            .then(data => {
                if (data.success && data.bills.length > 0) {
                    const billIds = data.bills.map(bill => bill.id);
                    
                    if (billIds.length > 100) {
                        alert('В договоре больше 100 векселей. Пожалуйста, используйте модальное окно для выборочного скачивания.');
                        return;
                    }
                    
                    // Создаём форму для отправки POST запроса
                    const form = document.createElement('form');
                    form.method = 'POST';
                    form.action = '/api/bills_bulk_pdf.php';
                    
                    billIds.forEach(billId => {
                        const input = document.createElement('input');
                        input.type = 'hidden';
                        input.name = 'bill_ids[]';
                        input.value = billId;
                        form.appendChild(input);
                    });
                    
                    document.body.appendChild(form);
                    form.submit();
                    document.body.removeChild(form);
                } else {
                    alert('В договоре нет векселей для скачивания');
                }
            })
            .catch(error => {
                console.error('Error loading bills:', error);
                alert('Ошибка при загрузке векселей договора');
            });
    }
    
    // Инициализация при загрузке страницы
    document.addEventListener('DOMContentLoaded', function() {
        updateBulkActions();
    });
    </script>

    <!-- Список транзакций с векселями -->
    <div class="depository-results">
        <?php if (empty($transactions)): ?>
            <div class="depository-empty">
                <div class="depository-empty-icon">🏦</div>
                <h3>Договоров с векселями не найдено</h3>
                <p class="text-muted">Попробуйте изменить параметры поиска или фильтры</p>
                <a href="/depository.php" class="btn btn-primary">Показать все договоры</a>
            </div>
        <?php else: ?>
            <div class="depository-header">
                <div class="depository-bulk-actions" id="bulkActions" style="display: none;">
                    <span class="bulk-selected-count" id="selectedCount">0</span>
                    <button type="button" class="btn btn-secondary" onclick="clearSelection()">
                        ✕ Снять выбор
                    </button>
                </div>
            </div>
            
            <!-- Модальное окно с векселями -->
            <div id="billsModal" class="bills-modal" style="display: none;">
                <div class="bills-modal-content">
                    <div class="bills-modal-header">
                        <h3 id="billsModalTitle">Вексели договора</h3>
                        <button type="button" class="bills-modal-close" onclick="closeBillsModal()" title="Закрыть">×</button>
                    </div>
                    <div class="bills-modal-body">
                        <div class="modal-bulk-actions" id="modalBulkActions" style="display: none;">
                            <span class="bulk-selected-count" id="modalSelectedCount">0</span>
                            <button type="button" class="btn btn-primary" onclick="downloadSelectedModalBills()">
                                📥 Скачать выбранные PDF
                            </button>
                            <button type="button" class="btn btn-secondary" onclick="document.querySelectorAll('.modal-bill-checkbox').forEach(cb => cb.checked = false); updateModalBulkActions();">
                                ✕ Снять выбор
                            </button>
                        </div>
                        <div class="bills-modal-table-wrapper">
                            <table class="bills-modal-table">
                                <thead>
                                    <tr>
                                        <th style="width: 40px;">
                                            <input type="checkbox" id="modalSelectAll" onchange="document.querySelectorAll('.modal-bill-checkbox').forEach(cb => cb.checked = this.checked); updateModalBulkActions();">
                                        </th>
                                        <th>ID векселя</th>
                                        <th>Номинал</th>
                                        <th>Срок погашения</th>
                                        <th>Статус</th>
                                        <th>Действия</th>
                                    </tr>
                                </thead>
                                <tbody id="billsModalTable">
                                    <tr>
                                        <td colspan="6" style="text-align: center; padding: 20px;">Загрузка...</td>
                                    </tr>
                                </tbody>
                            </table>
                        </div>
                    </div>
                </div>
            </div>
            
            <div class="depository-table-wrapper">
                <table class="depository-table-modern">
                    <thead>
                        <tr>
                            <th style="width: 40px;">
                                <input type="checkbox" id="selectAll" onchange="toggleSelectAll(this)">
                            </th>
                            <th>ID договора</th>
                            <th>Дата</th>
                            <th>Продавец</th>
                            <th>Покупатель</th>
                            <th>Описание</th>
                            <th>Вексели</th>
                            <th>Статус</th>
                            <th>Действия</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php 
                        // Получаем количество векселей для каждой транзакции
                        $transactionIds = array_map(function($t) { return $t->getId(); }, $transactions);
                        $billsCountByTransaction = [];
                        if (!empty($transactionIds)) {
                            $placeholders = implode(',', array_fill(0, count($transactionIds), '?'));
                            $billsSql = "
                                SELECT t.id as transaction_id, COUNT(b.id) as bills_count
                                FROM transactions t
                                LEFT JOIN bills b ON (
                                    (b.issuer_id = t.seller_id AND b.holder_id = t.buyer_id) OR
                                    (b.issuer_id = t.buyer_id AND b.holder_id = t.seller_id)
                                )
                                WHERE t.id IN ($placeholders)
                                GROUP BY t.id
                            ";
                            $billsStmt = $db->prepare($billsSql);
                            $billsStmt->execute($transactionIds);
                            while ($billData = $billsStmt->fetch(\PDO::FETCH_ASSOC)) {
                                $billsCountByTransaction[$billData['transaction_id']] = (int)$billData['bills_count'];
                            }
                        }
                        
                        foreach ($transactions as $transaction): ?>
                            <?php
                            $seller = User::findById($transaction->getSellerId());
                            $buyer = User::findById($transaction->getBuyerId());
                            
                            // Получаем количество векселей
                            $billsCount = $billsCountByTransaction[$transaction->getId()] ?? 0;
                            
                            // Статус
                            $statusClass = [
                                'pending' => 'status-pending',
                                'active' => 'status-active',
                                'completed' => 'status-completed',
                                'cancelled' => 'status-cancelled'
                            ];
                            $statusLabels = [
                                'pending' => 'На рассмотрении',
                                'active' => 'Активна',
                                'completed' => 'Завершена',
                                'cancelled' => 'Отменена'
                            ];
                            $statusClassValue = $statusClass[$transaction->getStatus()] ?? '';
                            $statusLabel = $statusLabels[$transaction->getStatus()] ?? $transaction->getStatus();
                            
                            // Дата
                            $createdDate = $transaction->getCreatedAt() ? date('d.m.Y H:i', strtotime($transaction->getCreatedAt())) : '-';
                            
                            ?>
                            <tr class="transaction-row clickable-row" 
                                data-transaction-id="<?= $transaction->getId() ?>" 
                                onclick="openTransactionBills(<?= $transaction->getId() ?>)"
                                style="cursor: pointer;">
                                <td onclick="event.stopPropagation();">
                                    <?php if ($billsCount > 0): ?>
                                        <input type="checkbox" 
                                               class="transaction-checkbox" 
                                               data-transaction-id="<?= $transaction->getId() ?>"
                                               onchange="updateBulkActions(); event.stopPropagation();">
                                    <?php endif; ?>
                                </td>
                                <td>
                                    <span class="table-id-badge">#<?= $transaction->getId() ?></span>
                                </td>
                                <td>
                                    <span class="table-date"><?= $createdDate ?></span>
                                </td>
                                <td>
                                    <a href="/user.php?id=<?= $seller->getId() ?>" 
                                       class="table-user-link"
                                       onclick="event.stopPropagation();">
                                        <?= htmlspecialchars($seller->getFullName()) ?>
                                    </a>
                                </td>
                                <td>
                                    <a href="/user.php?id=<?= $buyer->getId() ?>" 
                                       class="table-user-link"
                                       onclick="event.stopPropagation();">
                                        <?= htmlspecialchars($buyer->getFullName()) ?>
                                    </a>
                                </td>
                                <td>
                                    <span class="table-description" title="<?= htmlspecialchars($transaction->getDescription() ?? '') ?>">
                                        <?= htmlspecialchars(mb_substr($transaction->getDescription() ?? 'Без описания', 0, 50)) ?>
                                        <?= mb_strlen($transaction->getDescription() ?? '') > 50 ? '...' : '' ?>
                                    </span>
                                </td>
                                <td>
                                    <?php if ($billsCount > 0): ?>
                                        <button type="button" 
                                                class="btn-view-bills" 
                                                onclick="event.stopPropagation(); openTransactionBills(<?= $transaction->getId() ?>)"
                                                title="Показать вексели договора">
                                            <span class="bills-count-display">📄 <?= $billsCount ?> векселей</span>
                                        </button>
                                    <?php else: ?>
                                        <span class="text-muted">Вексели не найдены</span>
                                    <?php endif; ?>
                                </td>
                                <td>
                                    <span class="status <?= $statusClassValue ?>"><?= $statusLabel ?></span>
                                </td>
                                <td onclick="event.stopPropagation();">
                                    <?php if ($billsCount > 0): ?>
                                        <button type="button" 
                                                class="btn-download-all-bills" 
                                                onclick="event.stopPropagation(); downloadAllTransactionBills(<?= $transaction->getId() ?>)"
                                                title="Скачать все PDF векселей договора">
                                            📥 Скачать все PDF (<?= $billsCount ?>)
                                        </button>
                                    <?php else: ?>
                                        <span class="text-muted">-</span>
                                    <?php endif; ?>
                                </td>
                            </tr>
                        <?php endforeach; ?>
                    </tbody>
                </table>
            </div>

            <?php if ($totalPages > 1): ?>
                <?php
                // Формируем строку параметров для пагинации
                $queryParams = [];
                if ($statusFilter) $queryParams[] = 'status=' . urlencode($statusFilter);
                if ($searchQuery) $queryParams[] = 'search=' . urlencode($searchQuery);
                if ($dateFrom) $queryParams[] = 'date_from=' . urlencode($dateFrom);
                if ($dateTo) $queryParams[] = 'date_to=' . urlencode($dateTo);
                if ($amountFrom !== null && $amountFrom !== '') $queryParams[] = 'amount_from=' . urlencode($amountFrom);
                if ($amountTo !== null && $amountTo !== '') $queryParams[] = 'amount_to=' . urlencode($amountTo);
                if ($maturityFilter) $queryParams[] = 'maturity=' . urlencode($maturityFilter);
                $queryString = !empty($queryParams) ? '&' . implode('&', $queryParams) : '';
                ?>
                <div class="depository-pagination">
                    <?php if ($page > 1): ?>
                        <a href="/depository.php?page=<?= $page - 1 ?><?= $queryString ?>" 
                           class="pagination-btn pagination-prev">← Назад</a>
                    <?php endif; ?>
                    
                    <div class="pagination-numbers">
                        <?php
                        $startPage = max(1, $page - 2);
                        $endPage = min($totalPages, $page + 2);
                        
                        if ($startPage > 1): ?>
                            <a href="/depository.php?page=1<?= $queryString ?>" 
                               class="pagination-btn">1</a>
                            <?php if ($startPage > 2): ?>
                                <span class="pagination-dots">...</span>
                            <?php endif; ?>
                        <?php endif; ?>
                        
                        <?php for ($i = $startPage; $i <= $endPage; $i++): ?>
                            <a href="/depository.php?page=<?= $i ?><?= $queryString ?>"
                               class="pagination-btn <?= $i === $page ? 'pagination-active' : '' ?>">
                                <?= $i ?>
                            </a>
                        <?php endfor; ?>
                        
                        <?php if ($endPage < $totalPages): ?>
                            <?php if ($endPage < $totalPages - 1): ?>
                                <span class="pagination-dots">...</span>
                            <?php endif; ?>
                            <a href="/depository.php?page=<?= $totalPages ?><?= $queryString ?>" 
                               class="pagination-btn"><?= $totalPages ?></a>
                        <?php endif; ?>
                    </div>
                    
                    <?php if ($page < $totalPages): ?>
                        <a href="/depository.php?page=<?= $page + 1 ?><?= $queryString ?>" 
                           class="pagination-btn pagination-next">Вперёд →</a>
                    <?php endif; ?>
                </div>
            <?php endif; ?>
        <?php endif; ?>
    </div>
</div>

<?php
$content = ob_get_clean();
include __DIR__ . '/../templates/base.php';
?>


