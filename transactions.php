<?php
/**
 * Страница списка транзакций
 */

require_once __DIR__ . '/../src/bootstrap.php';

use OGAS\Services\Auth;
use OGAS\Models\User;
use OGAS\Models\Transaction;
use OGAS\Services\TransactionService;
use OGAS\Core\Session;
use OGAS\Core\Security;

Auth::requireAuth();
$user = Auth::user();

if (!$user) {
    Auth::logout();
    header('Location: /login.php');
    exit;
}

$success = Session::getFlash('success');
$error = Session::getFlash('error');

// Обработка действий
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    // CSRF защита
    if (!Security::checkCsrfToken()) {
        Session::flash('error', 'Ошибка безопасности. Обновите страницу и попробуйте снова.');
        header('Location: /transactions.php');
        exit;
    }
    
    $action = $_POST['action'] ?? '';
    $transactionId = (int)($_POST['transaction_id'] ?? 0);
    
    try {
        switch ($action) {
            case 'complete':
                if (TransactionService::complete($transactionId, $user->getId())) {
                    Session::flash('success', 'Транзакция завершена!');
                    header('Location: /transactions.php');
                    exit;
                } else {
                    Session::flash('error', 'Не удалось завершить транзакцию');
                }
                break;
                
            case 'cancel':
                if (TransactionService::cancel($transactionId, $user->getId())) {
                    Session::flash('success', 'Транзакция отменена!');
                    header('Location: /transactions.php');
                    exit;
                } else {
                    Session::flash('error', 'Не удалось отменить транзакцию');
                }
                break;
        }
    } catch (\Exception $e) {
        Session::flash('error', 'Ошибка: ' . $e->getMessage());
    }
    
    header('Location: /transactions.php');
    exit;
}

// Фильтры
$statusFilter = $_GET['status'] ?? null;
$roleFilter = $_GET['role'] ?? null; // 'seller', 'buyer', 'all'
$categoryFilter = $_GET['category'] ?? null;
$communityFilter = isset($_GET['community']) && $_GET['community'] === '1';

// Получаем транзакции
$allTransactions = Transaction::findByUser($user->getId(), $statusFilter);

// Фильтруем по роли
if ($roleFilter === 'seller') {
    $transactions = Transaction::findBySeller($user->getId(), $statusFilter);
} elseif ($roleFilter === 'buyer') {
    $transactions = Transaction::findByBuyer($user->getId(), $statusFilter);
} else {
    $transactions = $allTransactions;
}

// Фильтруем по категории
if ($categoryFilter) {
    $transactions = array_filter($transactions, function($t) use ($categoryFilter) {
        return $t->getCategory() === $categoryFilter;
    });
}

// Фильтруем по общине
if ($communityFilter) {
    $transactions = array_filter($transactions, function($t) {
        return $t->isFromCommunity();
    });
}

// Получаем все уникальные категории для фильтра
use OGAS\Models\Category;
$allCategories = Category::getAllActive(0);
$usedCategories = [];
foreach ($transactions as $t) {
    if ($t->getCategory()) {
        $usedCategories[$t->getCategory()] = true;
    }
}

// Статистика
$stats = TransactionService::getStatistics($user->getId());

// Получаем транзакцию для просмотра деталей (если указан ID)
$viewTransaction = null;
if (isset($_GET['id'])) {
    $viewTransaction = Transaction::findById((int)$_GET['id']);
    if ($viewTransaction && 
        $viewTransaction->getSellerId() !== $user->getId() && 
        $viewTransaction->getBuyerId() !== $user->getId()) {
        $viewTransaction = null; // Не показываем чужие транзакции
    }
}

$title = 'Мои транзакции';
ob_start();
?>
<div class="dashboard">
    <div class="dashboard-header">
        <h2>Мои транзакции</h2>
        <div class="header-actions">
            <a href="/transactions/create.php" class="btn btn-primary">Создать транзакцию</a>
            <a href="/export/transactions.php<?= ($statusFilter || $categoryFilter) ? '?' . http_build_query(['status' => $statusFilter, 'category' => $categoryFilter]) : '' ?>" class="btn btn-secondary">Экспорт CSV</a>
            <a href="/dashboard.php" class="btn btn-secondary">← Назад</a>
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
                <i class="fas fa-exchange-alt"></i>
                <span>Всего транзакций: <strong><?= $stats['total'] ?></strong></span>
            </div>
            <div class="stat-item">
                <i class="fas fa-clock"></i>
                <span>Активные: <strong><?= $stats['active'] ?></strong></span>
            </div>
            <div class="stat-item">
                <i class="fas fa-check-circle"></i>
                <span>Завершённые: <strong><?= $stats['completed'] ?></strong></span>
            </div>
            <div class="stat-item">
                <i class="fas fa-hourglass-half"></i>
                <span>Ожидают: <strong><?= $stats['pending'] ?></strong></span>
            </div>
        </div>
    </div>
    
    <!-- Фильтры -->
    <?php 
    $hasActiveFilters = !empty($statusFilter) || !empty($roleFilter) || !empty($categoryFilter) || $communityFilter;
    ?>
    <div class="filters-wrapper" data-has-filters="<?= $hasActiveFilters ? 'true' : 'false' ?>">
        <div class="filters-toggle-header <?= !$hasActiveFilters ? 'collapsed' : '' ?>">
            <div class="filters-toggle-title">
                <i class="fas fa-filter filters-toggle-icon"></i>
                <span>Фильтры<?= $hasActiveFilters ? ' <span style="color: var(--color-primary); font-weight: 600;">(активны)</span>' : '' ?></span>
            </div>
        </div>
        <div class="search-filters-card <?= !$hasActiveFilters ? 'collapsed' : 'expanded' ?>"<?= !$hasActiveFilters ? ' style="max-height: 0; padding: 0; margin: 0; opacity: 0; overflow: hidden;"' : '' ?>>
            <form method="GET" action="/transactions.php" class="search-filters-form">
                <div class="filters-row">
                    <div class="filter-group">
                        <label for="status">Статус:</label>
                        <select name="status" id="status" class="filter-select" onchange="this.form.submit()">
                            <option value="">Все</option>
                            <option value="pending" <?= $statusFilter === 'pending' ? 'selected' : '' ?>>Ожидают</option>
                            <option value="active" <?= $statusFilter === 'active' ? 'selected' : '' ?>>Активные</option>
                            <option value="completed" <?= $statusFilter === 'completed' ? 'selected' : '' ?>>Завершённые</option>
                            <option value="cancelled" <?= $statusFilter === 'cancelled' ? 'selected' : '' ?>>Отменённые</option>
                        </select>
                    </div>
                    
                    <div class="filter-group">
                        <label for="role">Роль:</label>
                        <select name="role" id="role" class="filter-select" onchange="this.form.submit()">
                            <option value="all" <?= $roleFilter === 'all' || !$roleFilter ? 'selected' : '' ?>>Все</option>
                            <option value="seller" <?= $roleFilter === 'seller' ? 'selected' : '' ?>>Продавец</option>
                            <option value="buyer" <?= $roleFilter === 'buyer' ? 'selected' : '' ?>>Покупатель</option>
                        </select>
                    </div>
                    
                    <div class="filter-group">
                        <label for="category">Категория:</label>
                        <select name="category" id="category" class="filter-select" onchange="this.form.submit()">
                            <option value="">Все категории</option>
                            <?php foreach ($allCategories as $cat): ?>
                                <?php if (isset($usedCategories[$cat->getName()]) || $categoryFilter === $cat->getName()): ?>
                                    <option value="<?= htmlspecialchars($cat->getName()) ?>" 
                                            <?= $categoryFilter === $cat->getName() ? 'selected' : '' ?>>
                                        <?= htmlspecialchars($cat->getIcon() ?? '📦') ?> <?= htmlspecialchars($cat->getName()) ?>
                                    </option>
                                <?php endif; ?>
                            <?php endforeach; ?>
                        </select>
                    </div>
                    
                    <div class="filter-group filter-group-checkbox">
                        <label class="checkbox-filter-label">
                            <input type="checkbox" name="community" value="1" class="checkbox-filter-input" <?= $communityFilter ? 'checked' : '' ?> onchange="this.form.submit()">
                            <span class="checkbox-filter-text">Только связанные с общиной</span>
                        </label>
                    </div>
                    
                    <div class="filter-actions">
                        <?php if ($statusFilter || $categoryFilter || $roleFilter || $communityFilter): ?>
                            <a href="/transactions.php" class="btn btn-secondary">Сбросить</a>
                        <?php endif; ?>
                    </div>
                </div>
            </form>
        </div>
    </div>
    
    <!-- Детали транзакции (если выбрана) -->
    <?php if ($viewTransaction): ?>
        <?php
        $seller = User::findById($viewTransaction->getSellerId());
        $buyer = User::findById($viewTransaction->getBuyerId());
        $bills = TransactionService::getBillsForTransaction($viewTransaction->getId());
        
        // Получаем информацию о категории
        $categoryInfo = null;
        if ($viewTransaction->getCategory()) {
            try {
                $categoryInfo = Category::findByName($viewTransaction->getCategory());
            } catch (\Exception $e) {
                // Игнорируем ошибку, если категория не найдена
            }
        }
        
        $statusLabels = [
            'pending' => ['label' => 'Ожидает', 'color' => '#f59e0b', 'bg' => '#fef3c7'],
            'active' => ['label' => 'Активна', 'color' => '#10b981', 'bg' => '#d1fae5'],
            'completed' => ['label' => 'Завершена', 'color' => '#3b82f6', 'bg' => '#dbeafe'],
            'cancelled' => ['label' => 'Отменена', 'color' => '#ef4444', 'bg' => '#fee2e2']
        ];
        $statusInfo = $statusLabels[$viewTransaction->getStatus()] ?? ['label' => $viewTransaction->getStatus(), 'color' => '#666', 'bg' => '#e5e7eb'];
        
        $transactionTypeLabels = [
            'barter' => 'Бартер',
            'guarantee' => 'Гарантия',
            'community' => 'Община',
            'mixed' => 'Смешанная'
        ];
        
        $isSeller = $viewTransaction->getSellerId() === $user->getId();
        $isBuyer = $viewTransaction->getBuyerId() === $user->getId();
        ?>
        
        <div class="transaction-details-container">
            <!-- Заголовок -->
            <div style="display: flex; justify-content: space-between; align-items: center; margin-bottom: 20px; flex-wrap: wrap; gap: 15px;">
                <div>
                    <h2 style="margin: 0; color: #1f2937; display: flex; align-items: center; gap: 10px;">
                        <i class="fas fa-exchange-alt" style="color: #3b82f6;"></i>
                        Транзакция #<?= $viewTransaction->getId() ?>
                    </h2>
                </div>
                <div style="display: flex; gap: 10px; flex-wrap: wrap;">
                    <a href="/transactions.php" class="btn btn-secondary" style="text-decoration: none;">
                        <i class="fas fa-arrow-left"></i> Вернуться к списку
                    </a>
                    <a href="/transactions/chat.php?id=<?= $viewTransaction->getId() ?>" class="btn btn-primary" style="text-decoration: none;">
                        <i class="fas fa-comments"></i> Открыть чат
                    </a>
                    <?php if (in_array($viewTransaction->getStatus(), ['pending', 'active']) && (!$viewTransaction->isBothConfirmed() || empty($bills))): ?>
                        <button class="btn btn-secondary" onclick="showCreateTicketModal(<?= $viewTransaction->getId() ?>, 'transaction')" style="text-decoration: none;">
                            <i class="fas fa-life-ring"></i> Создать обращение в поддержку
                        </button>
                    <?php endif; ?>
                </div>
            </div>
            
            <!-- Основная информация -->
            <div class="transaction-info-card">
                <div style="display: grid; grid-template-columns: 1fr 1fr; gap: 15px; margin-bottom: 20px;">
                    <div>
                        <div style="font-weight: 600; color: #666; font-size: 13px; margin-bottom: 5px;">ID транзакции</div>
                        <div style="font-size: 18px; font-weight: 700; color: #1e40af;">#<?= $viewTransaction->getId() ?></div>
                    </div>
                    <div>
                        <div style="font-weight: 600; color: #666; font-size: 13px; margin-bottom: 5px;">Статус</div>
                        <span style="display: inline-block; padding: 6px 12px; border-radius: 6px; font-size: 13px; font-weight: 600; background: <?= $statusInfo['bg'] ?>; color: <?= $statusInfo['color'] ?>;">
                            <?= $statusInfo['label'] ?>
                        </span>
                    </div>
                </div>
                
                <?php if ($viewTransaction->getDescription()): ?>
                    <div style="margin-bottom: 20px; padding: 15px; background: #f9fafb; border-radius: 8px; border-left: 4px solid #3b82f6;">
                        <div style="font-weight: 600; color: #666; font-size: 13px; margin-bottom: 8px;">
                            <i class="fas fa-align-left"></i> Описание сделки
                        </div>
                        <div style="color: #333; line-height: 1.6;">
                            <?= nl2br(htmlspecialchars($viewTransaction->getDescription())) ?>
                        </div>
                    </div>
                <?php endif; ?>
                
                <div style="display: grid; grid-template-columns: 1fr 1fr; gap: 15px; margin-bottom: 20px;">
                    <div style="padding: 15px; background: white; border-radius: 8px; border: 1px solid #e5e7eb;">
                        <div style="font-weight: 600; color: #666; font-size: 13px; margin-bottom: 10px;">
                            <i class="fas fa-user-tie"></i> Продавец
                        </div>
                        <div style="display: flex; align-items: center; gap: 10px;">
                            <?php if ($seller): ?>
                                <?= $seller->getAvatarHtml('small', 'transaction-participant-avatar') ?>
                                <div style="flex: 1;">
                                    <a href="/user.php?id=<?= $seller->getId() ?>" style="color: #3b82f6; text-decoration: none; font-weight: 600; display: block;">
                                        <?= htmlspecialchars($seller->getFullName()) ?>
                                    </a>
                                    <?php if ($seller->getEmail()): ?>
                                        <div style="font-size: 12px; color: #666; margin-top: 2px;">
                                            <?= htmlspecialchars($seller->getEmail()) ?>
                                        </div>
                                    <?php endif; ?>
                                    <?php if ($isSeller): ?>
                                        <span style="display: inline-block; margin-top: 4px; padding: 2px 8px; background: #dbeafe; color: #1e40af; border-radius: 4px; font-size: 11px; font-weight: 600;">Вы</span>
                                    <?php endif; ?>
                                </div>
                            <?php else: ?>
                                <span style="color: #999;">Пользователь #<?= $viewTransaction->getSellerId() ?></span>
                            <?php endif; ?>
                        </div>
                        <div style="margin-top: 12px; padding-top: 12px; border-top: 1px solid #f3f4f6;">
                            <span style="font-size: 12px; color: #666;">Подтверждение: </span>
                            <?php if ($viewTransaction->isSellerConfirmed()): ?>
                                <span style="color: #10b981; font-weight: 600;">✅ Подтверждено</span>
                            <?php else: ?>
                                <span style="color: #ef4444; font-weight: 600;">❌ Не подтверждено</span>
                            <?php endif; ?>
                        </div>
                    </div>
                    
                    <div style="padding: 15px; background: white; border-radius: 8px; border: 1px solid #e5e7eb;">
                        <div style="font-weight: 600; color: #666; font-size: 13px; margin-bottom: 10px;">
                            <i class="fas fa-user"></i> Покупатель
                        </div>
                        <div style="display: flex; align-items: center; gap: 10px;">
                            <?php if ($buyer): ?>
                                <?= $buyer->getAvatarHtml('small', 'transaction-participant-avatar') ?>
                                <div style="flex: 1;">
                                    <a href="/user.php?id=<?= $buyer->getId() ?>" style="color: #3b82f6; text-decoration: none; font-weight: 600; display: block;">
                                        <?= htmlspecialchars($buyer->getFullName()) ?>
                                    </a>
                                    <?php if ($buyer->getEmail()): ?>
                                        <div style="font-size: 12px; color: #666; margin-top: 2px;">
                                            <?= htmlspecialchars($buyer->getEmail()) ?>
                                        </div>
                                    <?php endif; ?>
                                    <?php if ($isBuyer): ?>
                                        <span style="display: inline-block; margin-top: 4px; padding: 2px 8px; background: #dbeafe; color: #1e40af; border-radius: 4px; font-size: 11px; font-weight: 600;">Вы</span>
                                    <?php endif; ?>
                                </div>
                            <?php else: ?>
                                <span style="color: #999;">Пользователь #<?= $viewTransaction->getBuyerId() ?></span>
                            <?php endif; ?>
                        </div>
                        <div style="margin-top: 12px; padding-top: 12px; border-top: 1px solid #f3f4f6;">
                            <span style="font-size: 12px; color: #666;">Подтверждение: </span>
                            <?php if ($viewTransaction->isBuyerConfirmed()): ?>
                                <span style="color: #10b981; font-weight: 600;">✅ Подтверждено</span>
                            <?php else: ?>
                                <span style="color: #ef4444; font-weight: 600;">❌ Не подтверждено</span>
                            <?php endif; ?>
                        </div>
                    </div>
                </div>
                
                <div style="display: grid; grid-template-columns: repeat(auto-fit, minmax(150px, 1fr)); gap: 12px; margin-bottom: 20px;">
                    <?php if ($viewTransaction->getCategory()): ?>
                        <div style="padding: 12px; background: white; border-radius: 8px; border: 1px solid #e5e7eb;">
                            <div style="font-weight: 600; color: #666; font-size: 12px; margin-bottom: 5px;">Категория</div>
                            <div style="display: flex; align-items: center; gap: 6px;">
                                <?php if ($categoryInfo && $categoryInfo->getIcon()): ?>
                                    <span style="font-size: 18px;"><?= htmlspecialchars($categoryInfo->getIcon()) ?></span>
                                <?php endif; ?>
                                <span style="color: #333; font-weight: 500;">
                                    <?= htmlspecialchars($categoryInfo ? $categoryInfo->getName() : $viewTransaction->getCategory()) ?>
                                </span>
                            </div>
                        </div>
                    <?php endif; ?>
                    
                    <div style="padding: 12px; background: white; border-radius: 8px; border: 1px solid #e5e7eb;">
                        <div style="font-weight: 600; color: #666; font-size: 12px; margin-bottom: 5px;">Тип сделки</div>
                        <div style="color: #333; font-weight: 500;">
                            <?= $transactionTypeLabels[$viewTransaction->getTransactionType()] ?? $viewTransaction->getTransactionType() ?>
                        </div>
                    </div>
                    
                    <div style="padding: 12px; background: white; border-radius: 8px; border: 1px solid #e5e7eb;">
                        <div style="font-weight: 600; color: #666; font-size: 12px; margin-bottom: 5px;">Дата создания</div>
                        <div style="color: #333; font-weight: 500; font-size: 13px;">
                            <?= $viewTransaction->getCreatedAt() ? date('d.m.Y H:i', strtotime($viewTransaction->getCreatedAt())) : 'Не указана' ?>
                        </div>
                    </div>
                    
                    <?php if ($viewTransaction->getUpdatedAt() && $viewTransaction->getUpdatedAt() !== $viewTransaction->getCreatedAt()): ?>
                        <div style="padding: 12px; background: white; border-radius: 8px; border: 1px solid #e5e7eb;">
                            <div style="font-weight: 600; color: #666; font-size: 12px; margin-bottom: 5px;">Последнее обновление</div>
                            <div style="color: #333; font-weight: 500; font-size: 13px;">
                                <?= date('d.m.Y H:i', strtotime($viewTransaction->getUpdatedAt())) ?>
                            </div>
                        </div>
                    <?php endif; ?>
                </div>
                
                <!-- Связанные вексели -->
                <?php if (!empty($bills)): ?>
                    <div style="margin-top: 20px; padding: 15px; background: white; border-radius: 8px; border: 1px solid #e5e7eb;">
                        <div style="font-weight: 600; color: #1e40af; font-size: 15px; margin-bottom: 15px; display: flex; align-items: center; gap: 8px;">
                            <i class="fas fa-file-invoice"></i> Связанные вексели (<?= count($bills) ?>)
                        </div>
                        <div style="display: flex; flex-direction: column; gap: 10px;">
                            <?php foreach ($bills as $bill): 
                                $billIssuer = User::findById($bill->getIssuerId());
                                $billHolder = User::findById($bill->getHolderId());
                                
                                $billStatusLabels = [
                                    'active' => ['label' => 'Активен', 'color' => '#10b981'],
                                    'paid' => ['label' => 'Погашен', 'color' => '#3b82f6'],
                                    'overdue' => ['label' => 'Просрочен', 'color' => '#ef4444'],
                                    'cancelled' => ['label' => 'Отменён', 'color' => '#999']
                                ];
                                $billStatusInfo = $billStatusLabels[$bill->getStatus()] ?? ['label' => $bill->getStatus(), 'color' => '#666'];
                            ?>
                                <div style="display: flex; justify-content: space-between; align-items: center; padding: 12px; background: #f9fafb; border-radius: 6px; border: 1px solid #e5e7eb;">
                                    <div style="flex: 1;">
                                        <div style="font-weight: 600; color: #333; margin-bottom: 6px;">
                                            Вексель #<?= $bill->getId() ?>
                                        </div>
                                        <div style="font-size: 13px; color: #666;">
                                            <?= htmlspecialchars($billIssuer ? $billIssuer->getFullName() : 'Пользователь #' . $bill->getIssuerId()) ?>
                                            → <?= htmlspecialchars($billHolder ? $billHolder->getFullName() : 'Пользователь #' . $bill->getHolderId()) ?>
                                        </div>
                                        <div style="font-size: 12px; color: #999; margin-top: 4px;">
                                            До <?= date('d.m.Y', strtotime($bill->getMaturityDate())) ?>
                                        </div>
                                    </div>
                                    <div style="text-align: right; margin-left: 15px;">
                                        <div style="font-weight: 700; color: #1e40af; font-size: 18px; margin-bottom: 4px;">
                                            <?= number_format($bill->getNominal(), 2, '.', ' ') ?> ₽
                                        </div>
                                        <div style="font-size: 12px; color: <?= $billStatusInfo['color'] ?>; font-weight: 600;">
                                            <?= $billStatusInfo['label'] ?>
                                        </div>
                                        <a href="/bills.php?id=<?= $bill->getId() ?>" style="display: inline-block; margin-top: 6px; font-size: 12px; color: #3b82f6; text-decoration: none;">
                                            Подробнее →
                                        </a>
                                    </div>
                                </div>
                            <?php endforeach; ?>
                        </div>
                    </div>
                <?php elseif ($viewTransaction->isBothConfirmed()): ?>
                    <div style="margin-top: 20px; padding: 12px; background: #fef3c7; border-radius: 6px; border-left: 3px solid #f59e0b;">
                        <div style="display: flex; align-items: center; gap: 8px; color: #92400e;">
                            <i class="fas fa-info-circle"></i>
                            <span style="font-size: 13px;">
                                Оба участника подтвердили сделку, но вексели ещё не созданы.
                            </span>
                        </div>
                    </div>
                <?php endif; ?>
                
                <!-- Действия -->
                <div style="margin-top: 20px; padding-top: 20px; border-top: 2px solid #e5e7eb; display: flex; gap: 10px; flex-wrap: wrap;">
                    <?php if ($viewTransaction->getStatus() === 'active' && $isSeller): ?>
                        <form method="POST" action="" style="margin: 0;">
                            <?= csrf_field() ?>
                            <input type="hidden" name="action" value="complete">
                            <input type="hidden" name="transaction_id" value="<?= $viewTransaction->getId() ?>">
                            <button type="submit" class="btn btn-primary" onclick="return confirm('Завершить транзакцию?')">
                                <i class="fas fa-check-circle"></i> Завершить транзакцию
                            </button>
                        </form>
                    <?php endif; ?>
                    
                    <?php if (in_array($viewTransaction->getStatus(), ['pending', 'active'])): ?>
                        <form method="POST" action="" style="margin: 0;">
                            <?= csrf_field() ?>
                            <input type="hidden" name="action" value="cancel">
                            <input type="hidden" name="transaction_id" value="<?= $viewTransaction->getId() ?>">
                            <button type="submit" class="btn btn-secondary" onclick="return confirm('Отменить транзакцию?')">
                                <i class="fas fa-times-circle"></i> Отменить транзакцию
                            </button>
                        </form>
                    <?php endif; ?>
                </div>
            </div>
        </div>
           <?php else: ?>
        <!-- Список транзакций -->
        <div class="transactions-list">
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
                            <th>Статус</th>
                            <th>Действия</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php foreach ($transactions as $transaction): ?>
                            <?php
                            $seller = User::findById($transaction->getSellerId());
                            $buyer = User::findById($transaction->getBuyerId());
                            $isSeller = $transaction->getSellerId() === $user->getId();
                            ?>
                            <tr>
                                <td>
                                    #<?= $transaction->getId() ?>
                                    <?php if ($transaction->isFromCommunity() && $transaction->getCommunityRequestId()): ?>
                                        <a href="/community.php?id=<?= $transaction->getCommunityRequestId() ?>" title="Связана с заявкой общины #<?= $transaction->getCommunityRequestId() ?>" style="margin-left: 5px; color: #667eea; text-decoration: none;">🏘️</a>
                                    <?php endif; ?>
                                </td>
                                <td><?= $transaction->getCreatedAt() ? date('d.m.Y', strtotime($transaction->getCreatedAt())) : '-' ?></td>
                                <td>
                                    <?= htmlspecialchars($seller->getFullName()) ?>
                                    <?php if ($isSeller): ?><span class="badge">Вы</span><?php endif; ?>
                                </td>
                                <td>
                                    <?= htmlspecialchars($buyer->getFullName()) ?>
                                    <?php if (!$isSeller): ?><span class="badge">Вы</span><?php endif; ?>
                                </td>
                                <td>
                                    <?= htmlspecialchars(mb_substr($transaction->getDescription() ?? 'Без описания', 0, 50)) ?><?= mb_strlen($transaction->getDescription() ?? '') > 50 ? '...' : '' ?>
                                    <?php if ($transaction->getCategory()): ?>
                                        <?php
                                        $cat = Category::findByName($transaction->getCategory());
                                        if ($cat):
                                        ?>
                                            <br><small class="text-muted">
                                                <?= htmlspecialchars($cat->getIcon() ?? '📦') ?> <?= htmlspecialchars($cat->getName()) ?>
                                            </small>
                                        <?php else: ?>
                                            <br><small class="text-muted"><?= htmlspecialchars($transaction->getCategory()) ?></small>
                                        <?php endif; ?>
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
                                    <?php if ($transaction->isFromCommunity()): ?>
                                        <span class="badge" style="background: #667eea; color: white; font-size: 0.75em; margin-left: 5px;" title="Транзакция связана с общиной">Община</span>
                                    <?php endif; ?>
                                </td>
                                       <td>
                                           <a href="/transactions.php?id=<?= $transaction->getId() ?>" class="btn btn-small">Подробнее</a>
                                           <a href="/transactions/chat.php?id=<?= $transaction->getId() ?>" class="btn btn-small" style="background: #667eea; color: white;">Чат</a>
                                       </td>
                            </tr>
                        <?php endforeach; ?>
                    </tbody>
                </table>
            <?php endif; ?>
        </div>
    <?php endif; ?>
</div>

<!-- Модальное окно для создания заявки в поддержку -->
<div id="createTicketModal" style="display: none; position: fixed; top: 0; left: 0; right: 0; bottom: 0; background: rgba(0,0,0,0.5); z-index: 10000; align-items: center; justify-content: center;">
    <div style="background: white; padding: 30px; border-radius: 8px; max-width: 600px; width: 90%; max-height: 90vh; overflow-y: auto;">
        <h2 style="margin-top: 0;">Создать обращение в поддержку</h2>
        <form id="createTicketForm" onsubmit="return createTicket(event)">
            <?= csrf_field() ?>
            <input type="hidden" id="relatedTransactionId" name="related_transaction_id">
            <input type="hidden" id="relatedBillId" name="related_bill_id">
            
            <div style="margin-bottom: 15px;">
                <label>Тема обращения <span style="color: red;">*</span></label>
                <input type="text" id="ticketSubject" name="subject" class="form-control" required minlength="3" placeholder="Например: Сделка не подтвердилась">
            </div>
            
            <div style="margin-bottom: 15px;">
                <label>Описание проблемы <span style="color: red;">*</span></label>
                <textarea id="ticketMessage" name="message" class="form-control" rows="6" required minlength="10" placeholder="Опишите проблему подробно..."></textarea>
                <small style="color: #666;">Минимум 10 символов</small>
            </div>
            
            <div style="margin-bottom: 20px;">
                <label>Приоритет</label>
                <select id="ticketPriority" name="priority" class="form-control">
                    <option value="medium">Средний</option>
                    <option value="low">Низкий</option>
                    <option value="high">Высокий</option>
                    <option value="urgent">Срочный</option>
                </select>
            </div>
            
            <div style="display: flex; gap: 10px; justify-content: flex-end;">
                <button type="button" class="btn btn-secondary" onclick="closeCreateTicketModal()">Отмена</button>
                <button type="submit" class="btn btn-primary">Создать обращение</button>
            </div>
        </form>
    </div>
</div>

<script>
function showCreateTicketModal(relatedId, type) {
    const modal = document.getElementById('createTicketModal');
    const form = document.getElementById('createTicketForm');
    const relatedTransactionInput = document.getElementById('relatedTransactionId');
    const relatedBillInput = document.getElementById('relatedBillId');
    const subjectInput = document.getElementById('ticketSubject');
    const messageInput = document.getElementById('ticketMessage');
    
    // Очищаем предыдущие значения
    relatedTransactionInput.value = '';
    relatedBillInput.value = '';
    subjectInput.value = '';
    messageInput.value = '';
    
    // Устанавливаем связанный объект
    if (type === 'transaction') {
        relatedTransactionInput.value = relatedId;
        subjectInput.value = 'Проблема с транзакцией #' + relatedId;
        messageInput.value = 'Опишите проблему с транзакцией...\n\n';
    } else if (type === 'bill') {
        relatedBillInput.value = relatedId;
        subjectInput.value = 'Проблема с векселем #' + relatedId;
        messageInput.value = 'Опишите проблему с векселем...\n\n';
    }
    
    modal.style.display = 'flex';
}

function closeCreateTicketModal() {
    document.getElementById('createTicketModal').style.display = 'none';
}

function createTicket(event) {
    event.preventDefault();
    
    const form = event.target;
    const formData = new FormData(form);
    formData.append('action', 'create_ticket');
    
    const submitBtn = form.querySelector('button[type="submit"]');
    const originalText = submitBtn.textContent;
    submitBtn.disabled = true;
    submitBtn.textContent = 'Создание...';
    
    fetch('/api/support.php', {
        method: 'POST',
        body: formData
    })
    .then(response => response.json())
    .then(data => {
        if (data.success) {
            if (typeof Toast !== 'undefined') {
                Toast.success(data.message || 'Обращение успешно создано');
            } else {
                alert(data.message || 'Обращение успешно создано');
            }
            closeCreateTicketModal();
            // Переходим на страницу заявки
            if (data.ticket_id) {
                window.location.href = '/support/ticket.php?id=' + data.ticket_id;
            }
        } else {
            submitBtn.disabled = false;
            submitBtn.textContent = originalText;
            if (typeof Toast !== 'undefined') {
                Toast.error(data.message || 'Ошибка при создании обращения');
            } else {
                alert(data.message || 'Ошибка при создании обращения');
            }
        }
    })
    .catch(error => {
        console.error('Error:', error);
        submitBtn.disabled = false;
        submitBtn.textContent = originalText;
        if (typeof Toast !== 'undefined') {
            Toast.error('Произошла ошибка при отправке запроса');
        } else {
            alert('Произошла ошибка при отправке запроса');
        }
    });
    
    return false;
}

// Закрытие модального окна при клике вне его
document.getElementById('createTicketModal').addEventListener('click', function(e) {
    if (e.target === this) {
        closeCreateTicketModal();
    }
});
</script>

<style>
/* Улучшенный интерфейс деталей транзакции */
.transaction-details-container {
    max-width: 1200px;
    margin: 0 auto;
}

.transaction-info-card {
    background: white;
    border-radius: 12px;
    padding: 24px;
    box-shadow: 0 2px 8px rgba(0,0,0,0.08);
    border: 1px solid #e5e7eb;
}

.transaction-participant-avatar {
    width: 48px !important;
    height: 48px !important;
    border-radius: 50% !important;
    object-fit: cover;
    border: 2px solid #e5e7eb;
    flex-shrink: 0;
}

.transaction-participant-avatar img {
    width: 100%;
    height: 100%;
    object-fit: cover;
    border-radius: 50%;
}

.transaction-participant-avatar div {
    width: 48px !important;
    height: 48px !important;
    border-radius: 50% !important;
    border: 2px solid #e5e7eb;
    display: flex;
    align-items: center;
    justify-content: center;
    font-weight: 600;
    font-size: 18px;
    background: linear-gradient(135deg, #667eea 0%, #764ba2 100%);
    color: white;
}

/* Адаптивность */
@media (max-width: 768px) {
    .transaction-details-container {
        padding: 0 10px;
    }
    
    .transaction-info-card {
        padding: 16px;
    }
    
    .transaction-info-card > div[style*="grid-template-columns"] {
        grid-template-columns: 1fr !important;
    }
    
    .transaction-info-card > div[style*="grid-template-columns: repeat"] {
        grid-template-columns: 1fr !important;
    }
}
</style>

<?php
$content = ob_get_clean();
include __DIR__ . '/../templates/base.php';

