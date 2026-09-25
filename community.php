<?php
/**
 * Страница системы круговой поруки "Община"
 */

require_once __DIR__ . '/../src/bootstrap.php';

use OGAS\Services\Auth;
use OGAS\Models\CommunityRequest;
use OGAS\Models\Company;
use OGAS\Services\CommunityService;
use OGAS\Models\User;
use OGAS\Core\Session;

Auth::requireAuth();
$user = Auth::user();

$error = Session::getFlash('error');
$success = Session::getFlash('success');

// Получаем список компаний для выбора
$allCompanies = [];
$allUsers = User::getAllActive(1000); // Получаем всех активных пользователей
foreach ($allUsers as $u) {
    if ($u->getUserType() === 'legal') {
        $company = Company::findByUserId($u->getId());
        if ($company) {
            $allCompanies[] = ['user' => $u, 'company' => $company];
        }
    }
}

// Создание заявки
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['create_request'])) {
    // CSRF защита
    if (!\OGAS\Core\Security::checkCsrfToken()) {
        Session::flash('error', 'Ошибка безопасности. Обновите страницу и попробуйте снова.');
        header('Location: /community.php');
        exit;
    }
    
    $amount = (int)$_POST['amount'];
    $nominal = (float)$_POST['nominal'];
    $maturityDays = (int)$_POST['maturity_days'];
    $description = trim($_POST['description'] ?? '');
    $targetCompanyUserId = !empty($_POST['target_company_user_id']) ? (int)$_POST['target_company_user_id'] : null;
    $productDescription = trim($_POST['product_description'] ?? '');
    $productPrice = !empty($_POST['product_price']) ? (float)$_POST['product_price'] : null;
    
    if ($amount > 0 && $nominal > 0 && $maturityDays > 0) {
        try {
            CommunityService::createRequest([
                'user_id' => $user->getId(),
                'amount' => $amount,
                'nominal' => $nominal,
                'maturity_days' => $maturityDays,
                'description' => $description,
                'target_company_user_id' => $targetCompanyUserId ?: null,
                'product_description' => $productDescription ?: null,
                'product_price' => $productPrice ?: null
            ]);
            Session::flash('success', 'Заявка успешно создана!');
            header('Location: /community.php');
            exit;
        } catch (Exception $e) {
            Session::flash('error', 'Ошибка при создании заявки: ' . $e->getMessage());
        }
    } else {
        Session::flash('error', 'Заполните все поля корректно');
    }
}

// Стать поручителем
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['become_guarantor'])) {
    $requestId = (int)$_POST['request_id'];
    $billsCount = (int)($_POST['bills_count'] ?? 1);
    
    if (CommunityService::becomeGuarantor($requestId, $user->getId(), $billsCount)) {
        $request = CommunityRequest::findById($requestId);
        if ($request && $request->isFulfilled()) {
            Session::flash('success', 'Вы стали поручителем! Необходимое количество векселей собрано. Групповой чат открыт для подтверждения согласия.');
        } else {
            Session::flash('success', 'Вы стали поручителем!');
        }
        header('Location: /community.php');
        exit;
    } else {
        Session::flash('error', 'Не удалось стать поручителем');
    }
}

// Фильтры
$amountFilter = $_GET['amount_filter'] ?? null; // 'all', 'low', 'medium', 'high'
$maturityFilter = $_GET['maturity_filter'] ?? null; // 'all', 'short', 'medium', 'long'
$searchQuery = trim($_GET['search'] ?? '');
$archiveStatusFilter = $_GET['archive_status'] ?? 'all'; // 'all', 'fulfilled', 'closed', 'cancelled'
$activeTab = $_GET['tab'] ?? 'requests'; // 'requests', 'create', 'archive'

$openRequests = CommunityService::getOpenRequests();
$archivedRequests = CommunityRequest::findUserArchived($user->getId());

// Фильтруем заявки
$filteredRequests = $openRequests;

if ($searchQuery && $activeTab === 'requests') {
    $filteredRequests = array_filter($filteredRequests, function($req) use ($searchQuery) {
        $searchLower = mb_strtolower($searchQuery);
        return strpos(mb_strtolower((string)$req->getId()), $searchLower) !== false ||
               ($req->getDescription() && strpos(mb_strtolower($req->getDescription()), $searchLower) !== false);
    });
}

if ($amountFilter && $amountFilter !== 'all' && $activeTab === 'requests') {
    $filteredRequests = array_filter($filteredRequests, function($req) use ($amountFilter) {
        $nominal = $req->getNominal();
        switch ($amountFilter) {
            case 'low':
                return $nominal < 5000;
            case 'medium':
                return $nominal >= 5000 && $nominal < 50000;
            case 'high':
                return $nominal >= 50000;
        }
        return true;
    });
}

if ($maturityFilter && $maturityFilter !== 'all' && $activeTab === 'requests') {
    $filteredRequests = array_filter($filteredRequests, function($req) use ($maturityFilter) {
        $days = $req->getMaturityDays();
        switch ($maturityFilter) {
            case 'short':
                return $days <= 30;
            case 'medium':
                return $days > 30 && $days <= 90;
            case 'long':
                return $days > 90;
        }
        return true;
    });
}

$filteredRequests = array_values($filteredRequests);

// Фильтруем архивные заявки
$filteredArchivedRequests = $archivedRequests;

if ($searchQuery && $activeTab === 'archive') {
    $filteredArchivedRequests = array_filter($filteredArchivedRequests, function($req) use ($searchQuery) {
        $searchLower = mb_strtolower($searchQuery);
        return strpos(mb_strtolower((string)$req->getId()), $searchLower) !== false ||
               ($req->getDescription() && strpos(mb_strtolower($req->getDescription()), $searchLower) !== false);
    });
}

if ($archiveStatusFilter && $archiveStatusFilter !== 'all' && $activeTab === 'archive') {
    $filteredArchivedRequests = array_filter($filteredArchivedRequests, function($req) use ($archiveStatusFilter) {
        return $req->getStatus() === $archiveStatusFilter;
    });
}

$filteredArchivedRequests = array_values($filteredArchivedRequests);

// Статистика
$stats = [
    'total' => count($openRequests),
    'my_requests' => count(array_filter($openRequests, function($req) use ($user) {
        return $req->getUserId() === $user->getId();
    })),
    'total_amount' => array_sum(array_map(function($req) {
        return $req->getAmount() * $req->getNominal();
    }, $openRequests)),
    'total_collected' => array_sum(array_map(function($req) {
        return $req->getCollectedAmount();
    }, $openRequests)),
    'archived_total' => count($archivedRequests),
    'archived_fulfilled' => count(array_filter($archivedRequests, function($req) {
        return $req->getStatus() === 'fulfilled';
    })),
    'archived_closed' => count(array_filter($archivedRequests, function($req) {
        return $req->getStatus() === 'closed';
    })),
    'archived_cancelled' => count(array_filter($archivedRequests, function($req) {
        return $req->getStatus() === 'cancelled';
    }))
];

$title = 'Система круговой поруки "Община"';
ob_start();
?>
<div class="dashboard">
    <div class="dashboard-header">
        <h2>Система круговой поруки "Община"</h2>
        <a href="/dashboard.php" class="btn btn-secondary">← Назад</a>
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
                <i class="fas fa-file-alt"></i>
                <span>Всего заявок: <strong><?= $stats['total'] ?></strong></span>
            </div>
            <div class="stat-item">
                <i class="fas fa-user"></i>
                <span>Мои заявки: <strong><?= $stats['my_requests'] ?></strong></span>
            </div>
            <div class="stat-item">
                <i class="fas fa-ruble-sign"></i>
                <span>Общая сумма: <strong><?= number_format($stats['total_amount'], 0, '.', ' ') ?> ₽</strong></span>
            </div>
            <div class="stat-item">
                <i class="fas fa-check-circle"></i>
                <span>Собрано векселей: <strong><?= $stats['total_collected'] ?></strong></span>
            </div>
        </div>
    </div>
    
    <!-- Улучшенные табы -->
    <div class="community-tabs-modern">
        <button class="tab-btn-modern <?= $activeTab === 'requests' ? 'active' : '' ?>" onclick="showTab('requests')" id="tab-requests">
            <span class="tab-icon">📋</span>
            <span>Открытые заявки</span>
            <?php if (count($filteredRequests) > 0): ?>
                <span class="tab-badge"><?= count($filteredRequests) ?></span>
            <?php endif; ?>
        </button>
        <button class="tab-btn-modern <?= $activeTab === 'create' ? 'active' : '' ?>" onclick="showTab('create')" id="tab-create">
            <span class="tab-icon">➕</span>
            <span>Создать заявку</span>
        </button>
        <button class="tab-btn-modern <?= $activeTab === 'archive' ? 'active' : '' ?>" onclick="showTab('archive')" id="tab-archive">
            <span class="tab-icon">📦</span>
            <span>Архив</span>
            <?php if (count($archivedRequests) > 0): ?>
                <span class="tab-badge"><?= count($archivedRequests) ?></span>
            <?php endif; ?>
        </button>
    </div>
    
    <!-- Таб: Открытые заявки -->
    <div id="requests-tab" class="tab-content-modern <?= $activeTab === 'requests' ? 'active' : '' ?>">
        <!-- Фильтры -->
        <?php 
        $hasActiveFilters = !empty($searchQuery) || ($amountFilter && $amountFilter !== 'all') || ($maturityFilter && $maturityFilter !== 'all');
        ?>
        <div class="community-filters-wrapper filters-wrapper" data-has-filters="<?= $hasActiveFilters ? 'true' : 'false' ?>">
            <div class="filters-toggle-header <?= !$hasActiveFilters ? 'collapsed' : '' ?>">
                <div class="filters-toggle-title">
                    <i class="fas fa-filter filters-toggle-icon"></i>
                    <span>Фильтры<?= $hasActiveFilters ? ' <span style="color: var(--color-primary); font-weight: 600;">(активны)</span>' : '' ?></span>
                </div>
            </div>
            <div class="search-filters-card <?= !$hasActiveFilters ? 'collapsed' : 'expanded' ?>"<?= !$hasActiveFilters ? ' style="max-height: 0; padding: 0; margin: 0; opacity: 0; overflow: hidden;"' : '' ?>>
                <form method="GET" action="/community.php" class="search-filters-form" id="communityFilterForm">
                    <input type="hidden" name="tab" value="requests">
                    <div class="search-input-group">
                        <div class="search-icon">🔍</div>
                        <input type="text" 
                               id="search" 
                               name="search" 
                               class="search-input"
                               value="<?= htmlspecialchars($searchQuery) ?>"
                               placeholder="Поиск по ID или описанию..." 
                               autocomplete="off">
                        <?php if (!empty($searchQuery)): ?>
                            <button type="button" class="search-clear" onclick="clearCommunitySearch()" title="Очистить">×</button>
                        <?php endif; ?>
                    </div>
                    
                    <div class="filters-row">
                        <div class="filter-group">
                            <label for="amount_filter">Сумма векселя:</label>
                            <select name="amount_filter" id="amount_filter" class="filter-select" onchange="this.form.submit()">
                                <option value="all" <?= $amountFilter === 'all' || !$amountFilter ? 'selected' : '' ?>>Все суммы</option>
                                <option value="low" <?= $amountFilter === 'low' ? 'selected' : '' ?>>До 5 000 ₽</option>
                                <option value="medium" <?= $amountFilter === 'medium' ? 'selected' : '' ?>>5 000 - 50 000 ₽</option>
                                <option value="high" <?= $amountFilter === 'high' ? 'selected' : '' ?>>Более 50 000 ₽</option>
                            </select>
                        </div>
                        
                        <div class="filter-group">
                            <label for="maturity_filter">Срок погашения:</label>
                            <select name="maturity_filter" id="maturity_filter" class="filter-select" onchange="this.form.submit()">
                                <option value="all" <?= $maturityFilter === 'all' || !$maturityFilter ? 'selected' : '' ?>>Все сроки</option>
                                <option value="short" <?= $maturityFilter === 'short' ? 'selected' : '' ?>>Короткий (≤30 дней)</option>
                                <option value="medium" <?= $maturityFilter === 'medium' ? 'selected' : '' ?>>Средний (31-90 дней)</option>
                                <option value="long" <?= $maturityFilter === 'long' ? 'selected' : '' ?>>Долгий (>90 дней)</option>
                            </select>
                        </div>
                        
                        <div class="filter-actions">
                            <?php if ($searchQuery || $amountFilter || $maturityFilter): ?>
                                <a href="/community.php" class="btn btn-secondary">Сбросить</a>
                            <?php endif; ?>
                        </div>
                    </div>
                </form>
            </div>
        </div>
        
        <div class="community-header">
            <h3>Открытые заявки на круговую поруку</h3>
            <div class="community-count">
                Найдено: <strong><?= count($filteredRequests) ?></strong>
            </div>
        </div>
        
        <?php if (empty($filteredRequests)): ?>
            <div class="community-empty">
                <div class="community-empty-icon">🤝</div>
                <h3>Нет открытых заявок</h3>
                <p class="text-muted"><?= empty($openRequests) ? 'Создайте первую заявку на круговую поруку' : 'Попробуйте изменить параметры поиска или фильтры' ?></p>
                <?php if (empty($openRequests)): ?>
                    <button class="btn btn-primary" onclick="showTab('create')">Создать заявку</button>
                <?php else: ?>
                    <a href="/community.php" class="btn btn-primary">Показать все заявки</a>
                <?php endif; ?>
            </div>
        <?php else: ?>
            <div class="requests-grid">
                <?php foreach ($filteredRequests as $request): ?>
                    <?php
                    $requester = User::findById($request->getUserId());
                    $isMyRequest = $request->getUserId() === $user->getId();
                    $collected = $request->getCollectedAmount();
                    $total = $request->getAmount();
                    $progress = $total > 0 ? ($collected / $total) * 100 : 0;
                    $remaining = $total - $collected;
                    $totalAmount = $request->getAmount() * $request->getNominal();
                    
                    // Получаем информацию о компании, если указана
                    $targetCompany = null;
                    $targetCompanyUser = null;
                    if ($request->getTargetCompanyUserId()) {
                        $targetCompanyUser = User::findById($request->getTargetCompanyUserId());
                        if ($targetCompanyUser) {
                            $targetCompany = Company::findByUserId($targetCompanyUser->getId());
                        }
                    }
                    ?>
                    
                    <div class="request-card-modern <?= $isMyRequest ? 'request-card-my' : '' ?>">
                        <div class="request-card-header">
                            <div class="request-requester">
                                <div class="requester-avatar">
                                    <?= $requester ? $requester->getAvatarHtml('medium') : '?' ?>
                                </div>
                                <div class="requester-info">
                                    <h4 class="requester-name">
                                        <a href="/user.php?id=<?= $requester->getId() ?>">
                                            <?= htmlspecialchars($requester->getFullName()) ?>
                                        </a>
                                        <?php if ($isMyRequest): ?>
                                            <span class="my-request-badge">Вы</span>
                                        <?php endif; ?>
                                    </h4>
                                    <div class="request-meta">
                                        <span class="request-id">Заявка #<?= $request->getId() ?></span>
                                        <span class="request-separator">•</span>
                                        <span class="request-date"><?= $request->getCreatedAt() ? date('d.m.Y H:i', strtotime($request->getCreatedAt())) : date('d.m.Y H:i') ?></span>
                                    </div>
                                </div>
                            </div>
                            <span class="request-status-badge status-open-modern">
                                <span class="status-dot"></span>
                                Открыта
                            </span>
                        </div>
                        
                        <div class="request-card-body">
                            <div class="request-stats-row">
                                <div class="request-stat-item">
                                    <div class="stat-icon">💰</div>
                                    <div class="stat-content">
                                        <div class="stat-label">Номинал векселя</div>
                                        <div class="stat-value"><?= number_format($request->getNominal(), 2, '.', ' ') ?> ₽</div>
                                    </div>
                                </div>
                                
                                <div class="request-stat-item">
                                    <div class="stat-icon">📄</div>
                                    <div class="stat-content">
                                        <div class="stat-label">Количество векселей</div>
                                        <div class="stat-value"><?= $total ?> шт</div>
                                    </div>
                                </div>
                                
                                <div class="request-stat-item">
                                    <div class="stat-icon">📅</div>
                                    <div class="stat-content">
                                        <div class="stat-label">Срок погашения</div>
                                        <div class="stat-value"><?= $request->getMaturityDays() ?> дней</div>
                                    </div>
                                </div>
                                
                                <div class="request-stat-item">
                                    <div class="stat-icon">💵</div>
                                    <div class="stat-content">
                                        <div class="stat-label">Общая сумма</div>
                                        <div class="stat-value"><?= number_format($totalAmount, 0, '.', ' ') ?> ₽</div>
                                    </div>
                                </div>
                            </div>
                            
                            <?php if ($targetCompany): ?>
                                <div class="request-product-info">
                                    <div class="product-info-header">
                                        <span class="product-icon">🏢</span>
                                        <strong>Товар/услуга для приобретения:</strong>
                                    </div>
                                    <div class="product-details">
                                        <div class="product-company">
                                            <strong>Компания:</strong> 
                                            <a href="/user.php?id=<?= $targetCompanyUser->getId() ?>">
                                                <?= htmlspecialchars($targetCompany->getName()) ?>
                                            </a>
                                        </div>
                                        <?php if ($request->getProductDescription()): ?>
                                            <div class="product-description">
                                                <strong>Товар/услуга:</strong> <?= htmlspecialchars($request->getProductDescription()) ?>
                                            </div>
                                        <?php endif; ?>
                                        <?php if ($request->getProductPrice()): ?>
                                            <div class="product-price">
                                                <strong>Стоимость:</strong> <?= number_format($request->getProductPrice(), 2, '.', ' ') ?> ₽
                                            </div>
                                        <?php endif; ?>
                                    </div>
                                </div>
                            <?php endif; ?>
                            
                            <?php if ($request->getDescription()): ?>
                                <div class="request-description">
                                    <div class="description-icon">📝</div>
                                    <div class="description-text">
                                        <?= htmlspecialchars($request->getDescription()) ?>
                                    </div>
                                </div>
                            <?php endif; ?>
                            
                            <div class="request-progress-section" id="progressSection_<?= $request->getId() ?>" data-total="<?= $total ?>">
                                <div class="progress-header">
                                    <span class="progress-label">Прогресс сбора:</span>
                                    <span class="progress-value" id="progressValue_<?= $request->getId() ?>"><?= $collected ?> / <?= $total ?> векселей</span>
                                </div>
                                <div class="progress-bar-wrapper">
                                    <div class="progress-bar" id="progressBar_<?= $request->getId() ?>" style="width: <?= $progress ?>%"></div>
                                </div>
                                <div class="progress-footer">
                                    <span class="progress-percent" id="progressPercent_<?= $request->getId() ?>"><?= number_format($progress, 1) ?>%</span>
                                    <span class="progress-remaining" id="progressRemaining_<?= $request->getId() ?>">Осталось: <?= $remaining ?> векселей</span>
                                </div>
                            </div>
                            
                            <div class="request-guarantors-info">
                                <div class="guarantors-icon">🤝</div>
                                <div class="guarantors-text">
                                    Поручителей: <strong id="guarantorsCount_<?= $request->getId() ?>"><?= $request->getGuarantorsCount() ?></strong>
                                    <span id="fulfilledStatus_<?= $request->getId() ?>" style="color: #10b981; margin-left: 10px; display: <?= $request->isFulfilled() ? 'inline' : 'none' ?>;">
                                        ✅ Собрано необходимое количество
                                    </span>
                                </div>
                            </div>
                            
                            <?php if ($request->isFulfilled()): 
                                $isParticipant = in_array($user->getId(), $request->getChatParticipants());
                            ?>
                                <div style="margin-top: 15px; padding-top: 15px; border-top: 1px solid #ddd;">
                                    <?php if ($isParticipant): ?>
                                        <a href="/community/chat.php?id=<?= $request->getId() ?>" 
                                           class="btn-become-guarantor" 
                                           style="width: 100%; text-align: center; justify-content: center; text-decoration: none; background: linear-gradient(135deg, #667eea 0%, #764ba2 100%);">
                                            <span>💬</span>
                                            <span>Перейти в групповой чат</span>
                                        </a>
                                    <?php else: ?>
                                        <div style="padding: 10px; background: #fff3cd; border: 1px solid #ffc107; border-radius: 8px; text-align: center; color: #856404; font-size: 0.9em;">
                                            ⚠️ Групповой чат открыт. Вы не являетесь участником этой заявки.
                                        </div>
                                    <?php endif; ?>
                                </div>
                            <?php endif; ?>
                        </div>
                        
                        <?php if (!$isMyRequest && $request->getStatus() === 'open' && $remaining > 0): ?>
                            <div class="request-card-footer">
                                <form method="POST" class="guarantor-form-modern">
                                <?= csrf_field() ?>
                                <input type="hidden" name="request_id" value="<?= $request->getId() ?>">
                                    <div class="guarantor-form-row">
                                        <div class="guarantor-form-group">
                                            <label for="bills_count_<?= $request->getId() ?>">Количество векселей:</label>
                                            <input type="number" 
                                                   id="bills_count_<?= $request->getId() ?>" 
                                                   name="bills_count" 
                                                   value="1" 
                                                   min="1" 
                                                   max="<?= $remaining ?>" 
                                                   required
                                                   class="guarantor-input">
                                </div>
                                        <button type="submit" name="become_guarantor" class="btn-become-guarantor">
                                            <span>🤝</span>
                                            <span>Стать поручителем</span>
                                </button>
                                    </div>
                            </form>
                            </div>
                        <?php elseif ($isMyRequest): ?>
                            <div class="request-card-footer">
                                <div class="my-request-message">
                                    <span>👤</span>
                                    <span>Это ваша заявка</span>
                                </div>
                            </div>
                        <?php endif; ?>
                    </div>
                <?php endforeach; ?>
            </div>
        <?php endif; ?>
    </div>
    
    <!-- Таб: Архив -->
    <div id="archive-tab" class="tab-content-modern <?= $activeTab === 'archive' ? 'active' : '' ?>">
        <!-- Фильтры для архива -->
        <?php 
        $hasActiveArchiveFilters = !empty($searchQuery) || ($archiveStatusFilter && $archiveStatusFilter !== 'all');
        ?>
        <div class="community-filters-wrapper filters-wrapper" data-has-filters="<?= $hasActiveArchiveFilters ? 'true' : 'false' ?>">
            <div class="filters-toggle-header <?= !$hasActiveArchiveFilters ? 'collapsed' : '' ?>">
                <div class="filters-toggle-title">
                    <i class="fas fa-filter filters-toggle-icon"></i>
                    <span>Фильтры<?= $hasActiveArchiveFilters ? ' <span style="color: var(--color-primary); font-weight: 600;">(активны)</span>' : '' ?></span>
                </div>
            </div>
            <div class="search-filters-card <?= !$hasActiveArchiveFilters ? 'collapsed' : 'expanded' ?>"<?= !$hasActiveArchiveFilters ? ' style="max-height: 0; padding: 0; margin: 0; opacity: 0; overflow: hidden;"' : '' ?>>
                <form method="GET" action="/community.php" class="search-filters-form" id="archiveFilterForm">
                    <input type="hidden" name="tab" value="archive">
                    <div class="search-input-group">
                        <div class="search-icon">🔍</div>
                        <input type="text" 
                               id="archive_search" 
                               name="search" 
                               class="search-input"
                               value="<?= htmlspecialchars($searchQuery) ?>"
                               placeholder="Поиск по ID или описанию..." 
                               autocomplete="off">
                        <?php if (!empty($searchQuery)): ?>
                            <button type="button" class="search-clear" onclick="clearArchiveSearch()" title="Очистить">×</button>
                        <?php endif; ?>
                    </div>
                    
                    <div class="filters-row">
                        <div class="filter-group">
                            <label for="archive_status">Статус:</label>
                            <select name="archive_status" id="archive_status" class="filter-select" onchange="this.form.submit()">
                                <option value="all" <?= $archiveStatusFilter === 'all' ? 'selected' : '' ?>>Все статусы</option>
                                <option value="fulfilled" <?= $archiveStatusFilter === 'fulfilled' ? 'selected' : '' ?>>Выполненные</option>
                                <option value="closed" <?= $archiveStatusFilter === 'closed' ? 'selected' : '' ?>>Закрытые</option>
                                <option value="cancelled" <?= $archiveStatusFilter === 'cancelled' ? 'selected' : '' ?>>Отменённые</option>
                            </select>
                        </div>
                        
                        <div class="filter-actions">
                            <?php if ($searchQuery || $archiveStatusFilter !== 'all'): ?>
                                <a href="/community.php?tab=archive" class="btn btn-secondary">Сбросить</a>
                            <?php endif; ?>
                        </div>
                    </div>
                </form>
            </div>
        </div>
        
        <div class="community-header">
            <h3>Архив заявок</h3>
            <div class="community-count">
                Найдено: <strong><?= count($filteredArchivedRequests) ?></strong>
            </div>
        </div>
        
        <?php if (empty($filteredArchivedRequests)): ?>
            <div class="community-empty">
                <div class="community-empty-icon">📦</div>
                <h3>Архив пуст</h3>
                <p class="text-muted"><?= empty($archivedRequests) ? 'Завершённые заявки будут отображаться здесь' : 'Попробуйте изменить параметры поиска или фильтры' ?></p>
                <?php if (!empty($archivedRequests)): ?>
                    <a href="/community.php?tab=archive" class="btn btn-primary">Показать все заявки</a>
                <?php endif; ?>
            </div>
        <?php else: ?>
            <div class="requests-grid">
                <?php foreach ($filteredArchivedRequests as $request): ?>
                    <?php
                    $requester = User::findById($request->getUserId());
                    $isMyRequest = $request->getUserId() === $user->getId();
                    $collected = $request->getCollectedAmount();
                    $total = $request->getAmount();
                    $progress = $total > 0 ? ($collected / $total) * 100 : 0;
                    $totalAmount = $request->getAmount() * $request->getNominal();
                    
                    // Статус для архива
                    $statusLabels = [
                        'fulfilled' => 'Выполнена',
                        'closed' => 'Закрыта',
                        'cancelled' => 'Отменена'
                    ];
                    $statusClasses = [
                        'fulfilled' => 'status-fulfilled-modern',
                        'closed' => 'status-closed-modern',
                        'cancelled' => 'status-cancelled-modern'
                    ];
                    $statusLabel = $statusLabels[$request->getStatus()] ?? $request->getStatus();
                    $statusClass = $statusClasses[$request->getStatus()] ?? '';
                    
                    // Получаем информацию о компании, если указана
                    $targetCompany = null;
                    $targetCompanyUser = null;
                    if ($request->getTargetCompanyUserId()) {
                        $targetCompanyUser = User::findById($request->getTargetCompanyUserId());
                        if ($targetCompanyUser) {
                            $targetCompany = Company::findByUserId($targetCompanyUser->getId());
                        }
                    }
                    ?>
                    
                    <div class="request-card-modern request-card-archived <?= $isMyRequest ? 'request-card-my' : '' ?>">
                        <div class="request-card-header">
                            <div class="request-requester">
                                <div class="requester-avatar">
                                    <?= $requester ? $requester->getAvatarHtml('medium') : '?' ?>
                                </div>
                                <div class="requester-info">
                                    <h4 class="requester-name">
                                        <a href="/user.php?id=<?= $requester->getId() ?>">
                                            <?= htmlspecialchars($requester->getFullName()) ?>
                                        </a>
                                        <?php if ($isMyRequest): ?>
                                            <span class="my-request-badge">Вы</span>
                                        <?php endif; ?>
                                    </h4>
                                    <div class="request-meta">
                                        <span class="request-id">Заявка #<?= $request->getId() ?></span>
                                        <span class="request-separator">•</span>
                                        <span class="request-date"><?= $request->getCreatedAt() ? date('d.m.Y H:i', strtotime($request->getCreatedAt())) : date('d.m.Y H:i') ?></span>
                                        <?php if ($request->getUpdatedAt() && $request->getUpdatedAt() !== $request->getCreatedAt()): ?>
                                            <span class="request-separator">•</span>
                                            <span class="request-date">Завершена: <?= date('d.m.Y H:i', strtotime($request->getUpdatedAt())) ?></span>
                                        <?php endif; ?>
                                    </div>
                                </div>
                            </div>
                            <span class="request-status-badge <?= $statusClass ?>">
                                <span class="status-dot"></span>
                                <?= htmlspecialchars($statusLabel) ?>
                            </span>
                        </div>
                        
                        <div class="request-card-body">
                            <div class="request-stats-row">
                                <div class="request-stat-item">
                                    <div class="stat-icon">💰</div>
                                    <div class="stat-content">
                                        <div class="stat-label">Номинал векселя</div>
                                        <div class="stat-value"><?= number_format($request->getNominal(), 2, '.', ' ') ?> ₽</div>
                                    </div>
                                </div>
                                
                                <div class="request-stat-item">
                                    <div class="stat-icon">📄</div>
                                    <div class="stat-content">
                                        <div class="stat-label">Количество векселей</div>
                                        <div class="stat-value"><?= $total ?> шт</div>
                                    </div>
                                </div>
                                
                                <div class="request-stat-item">
                                    <div class="stat-icon">📅</div>
                                    <div class="stat-content">
                                        <div class="stat-label">Срок погашения</div>
                                        <div class="stat-value"><?= $request->getMaturityDays() ?> дней</div>
                                    </div>
                                </div>
                                
                                <div class="request-stat-item">
                                    <div class="stat-icon">💵</div>
                                    <div class="stat-content">
                                        <div class="stat-label">Общая сумма</div>
                                        <div class="stat-value"><?= number_format($totalAmount, 0, '.', ' ') ?> ₽</div>
                                    </div>
                                </div>
                            </div>
                            
                            <?php if ($targetCompany): ?>
                                <div class="request-product-info">
                                    <div class="product-info-header">
                                        <span class="product-icon">🏢</span>
                                        <strong>Товар/услуга для приобретения:</strong>
                                    </div>
                                    <div class="product-details">
                                        <div class="product-company">
                                            <strong>Компания:</strong> 
                                            <a href="/user.php?id=<?= $targetCompanyUser->getId() ?>">
                                                <?= htmlspecialchars($targetCompany->getName()) ?>
                                            </a>
                                        </div>
                                        <?php if ($request->getProductDescription()): ?>
                                            <div class="product-description">
                                                <strong>Товар/услуга:</strong> <?= htmlspecialchars($request->getProductDescription()) ?>
                                            </div>
                                        <?php endif; ?>
                                        <?php if ($request->getProductPrice()): ?>
                                            <div class="product-price">
                                                <strong>Стоимость:</strong> <?= number_format($request->getProductPrice(), 2, '.', ' ') ?> ₽
                                            </div>
                                        <?php endif; ?>
                                    </div>
                                </div>
                            <?php endif; ?>
                            
                            <?php if ($request->getDescription()): ?>
                                <div class="request-description">
                                    <div class="description-icon">📝</div>
                                    <div class="description-text">
                                        <?= htmlspecialchars($request->getDescription()) ?>
                                    </div>
                                </div>
                            <?php endif; ?>
                            
                            <div class="request-progress-section">
                                <div class="progress-header">
                                    <span class="progress-label">Итоговый результат:</span>
                                    <span class="progress-value"><?= $collected ?> / <?= $total ?> векселей</span>
                                </div>
                                <div class="progress-bar-wrapper">
                                    <div class="progress-bar" style="width: <?= $progress ?>%"></div>
                                </div>
                                <div class="progress-footer">
                                    <span class="progress-percent"><?= number_format($progress, 1) ?>%</span>
                                    <span class="progress-remaining">Поручителей: <?= $request->getGuarantorsCount() ?></span>
                                </div>
                            </div>
                            
                            <?php if ($request->getStatus() === 'fulfilled'): 
                                $isParticipant = in_array($user->getId(), $request->getChatParticipants());
                                if ($isParticipant):
                            ?>
                                <div style="margin-top: 15px; padding-top: 15px; border-top: 1px solid #ddd;">
                                    <a href="/community/chat.php?id=<?= $request->getId() ?>" 
                                       class="btn-become-guarantor" 
                                       style="width: 100%; text-align: center; justify-content: center; text-decoration: none; background: linear-gradient(135deg, #667eea 0%, #764ba2 100%);">
                                        <span>💬</span>
                                        <span>Перейти в групповой чат</span>
                                    </a>
                                </div>
                            <?php endif; endif; ?>
                        </div>
                    </div>
                <?php endforeach; ?>
            </div>
        <?php endif; ?>
    </div>
    
    <!-- Таб: Создать заявку -->
    <div id="create-tab" class="tab-content-modern <?= $activeTab === 'create' ? 'active' : '' ?>">
        <div class="create-request-wrapper">
            <div class="create-request-card">
                <div class="create-request-header">
        <h3>Создать заявку на круговую поруку</h3>
                    <p class="text-muted">Заполните форму ниже, чтобы создать заявку на получение векселей через систему круговой поруки</p>
                </div>
                
                <form method="POST" class="create-request-form-modern">
                    <?= csrf_field() ?>
                    <div class="form-row">
                        <div class="form-group-modern">
                            <label for="amount">
                                <span class="label-icon">📄</span>
                                Количество векселей:
                            </label>
                            <input type="number" 
                                   id="amount" 
                                   name="amount" 
                                   min="1" 
                                   required
                                   placeholder="Например: 5"
                                   class="form-input-modern">
                            <small class="form-hint">Укажите, сколько векселей вам необходимо</small>
                        </div>
                        
                        <div class="form-group-modern">
                            <label for="nominal">
                                <span class="label-icon">💰</span>
                                Номинал векселя (₽):
                            </label>
                            <input type="number" 
                                   id="nominal" 
                                   name="nominal" 
                                   step="0.01" 
                                   min="0.01" 
                                   value="1000" 
                                   required
                                   placeholder="1000.00"
                                   class="form-input-modern">
                            <small class="form-hint">Номинал одного векселя</small>
                        </div>
                    </div>
                    
                    <div class="form-row">
                        <div class="form-group-modern">
                            <label for="maturity_days">
                                <span class="label-icon">📅</span>
                                Срок погашения (дней):
                            </label>
                            <input type="number" 
                                   id="maturity_days" 
                                   name="maturity_days" 
                                   min="1" 
                                   required
                                   placeholder="Например: 30"
                                   class="form-input-modern">
                            <small class="form-hint">Через сколько дней вексель должен быть погашен</small>
                        </div>
                    </div>
                    
                    <div class="form-group-modern">
                        <label for="target_company_user_id">
                            <span class="label-icon">🏢</span>
                            Компания-продавец (необязательно):
                        </label>
                        <select id="target_company_user_id" 
                                name="target_company_user_id"
                                class="form-input-modern">
                            <option value="">Не указано</option>
                            <?php foreach ($allCompanies as $comp): ?>
                                <option value="<?= $comp['user']->getId() ?>">
                                    <?= htmlspecialchars($comp['company']->getName()) ?> (<?= htmlspecialchars($comp['user']->getFullName()) ?>)
                                </option>
                            <?php endforeach; ?>
                        </select>
                        <small class="form-hint">Выберите компанию, у которой планируете приобрести товар/услугу</small>
            </div>
            
                    <div class="form-group-modern">
                        <label for="product_description">
                            <span class="label-icon">📦</span>
                            Описание товара/услуги для приобретения (необязательно):
                        </label>
                        <textarea id="product_description" 
                                  name="product_description" 
                                  rows="3"
                                  placeholder="Например: Недвижимость для музыкальных репетиций от ЗАО 'ПИКовые стройки'"
                                  class="form-textarea-modern"></textarea>
                        <small class="form-hint">Опишите, что вы хотите приобрести</small>
            </div>
            
                    <div class="form-group-modern">
                        <label for="product_price">
                            <span class="label-icon">💵</span>
                            Стоимость товара/услуги (₽, необязательно):
                        </label>
                        <input type="number" 
                               id="product_price" 
                               name="product_price" 
                               step="0.01" 
                               min="0"
                               placeholder="Например: 3000000"
                               class="form-input-modern">
                        <small class="form-hint">Общая стоимость приобретаемого товара/услуги</small>
            </div>
            
                    <div class="form-group-modern">
                        <label for="description">
                            <span class="label-icon">📝</span>
                            Описание и обоснование заявки:
                        </label>
                        <textarea id="description" 
                                  name="description" 
                                  rows="5"
                                  placeholder="Опишите цель заявки, обоснование необходимости векселей и механизм погашения..."
                                  class="form-textarea-modern"></textarea>
                        <small class="form-hint">Подробно расскажите, для чего вам нужны вексели, как вы планируете их использовать и как будете погашать обязательства</small>
            </div>
            
                    <div class="form-actions-modern">
                        <button type="submit" name="create_request" class="btn-create-request">
                            <span>➕</span>
                            <span>Создать заявку</span>
                        </button>
                        <a href="/community.php" class="btn-cancel-request">Отмена</a>
                    </div>
        </form>
            </div>
    </div>
</div>

<script>
function showTab(tab) {
        // Сохраняем текущие фильтры
        const url = new URL(window.location.href);
        url.searchParams.set('tab', tab);
        if (tab === 'requests' || tab === 'archive') {
            // Сохраняем фильтры для соответствующей вкладки
            if (tab === 'archive') {
                url.searchParams.delete('amount_filter');
                url.searchParams.delete('maturity_filter');
            } else {
                url.searchParams.delete('archive_status');
            }
        } else {
            // Очищаем все фильтры при переходе на создание заявки
            url.searchParams.delete('search');
            url.searchParams.delete('amount_filter');
            url.searchParams.delete('maturity_filter');
            url.searchParams.delete('archive_status');
        }
        
        window.location.href = url.toString();
    }
    
    function clearCommunitySearch() {
        document.getElementById('search').value = '';
        document.getElementById('communityFilterForm').submit();
    }
    
    function clearArchiveSearch() {
        document.getElementById('archive_search').value = '';
        document.getElementById('archiveFilterForm').submit();
    }
    
    // Реалтайм обновление прогресс-баров общины
    let communityProgressIntervals = [];
    
    document.addEventListener('DOMContentLoaded', function() {
        // Получаем все заявки на странице
        const requestCards = document.querySelectorAll('[id^="progressSection_"]');
        
        requestCards.forEach(section => {
            const requestId = section.id.replace('progressSection_', '');
            
            // Обновление прогресса каждые 3 секунды
            const intervalId = setInterval(function() {
                updateCommunityProgress(requestId);
            }, 3000);
            
            communityProgressIntervals.push(intervalId);
        });
        
        // Обновление при возвращении на страницу
        document.addEventListener('visibilitychange', function() {
            if (!document.hidden) {
                requestCards.forEach(section => {
                    const requestId = section.id.replace('progressSection_', '');
                    updateCommunityProgress(requestId);
                });
            }
        });
        
        // Очистка интервалов при уходе со страницы
        window.addEventListener('beforeunload', function() {
            communityProgressIntervals.forEach(intervalId => {
                if (intervalId) {
                    clearInterval(intervalId);
                }
            });
        });
    });
    
    function updateCommunityProgress(requestId) {
        fetch(`/api/chat.php?action=get_community_status&community_request_id=${requestId}`)
            .then(response => response.json())
            .then(data => {
                if (data.success) {
                    // Получаем total из data-атрибута
                    const progressSection = document.getElementById(`progressSection_${requestId}`);
                    const total = progressSection ? parseInt(progressSection.dataset.total || '0') : 0;
                    
                    // Обновляем прогресс-бар
                    const progressBar = document.getElementById(`progressBar_${requestId}`);
                    const progressValue = document.getElementById(`progressValue_${requestId}`);
                    const progressPercent = document.getElementById(`progressPercent_${requestId}`);
                    const progressRemaining = document.getElementById(`progressRemaining_${requestId}`);
                    const guarantorsCount = document.getElementById(`guarantorsCount_${requestId}`);
                    const fulfilledStatus = document.getElementById(`fulfilledStatus_${requestId}`);
                    
                    if (progressBar) {
                        progressBar.style.width = data.progress_percent + '%';
                    }
                    
                    if (progressValue && total > 0) {
                        progressValue.textContent = `${data.collected} / ${total} векселей`;
                    }
                    
                    if (progressPercent) {
                        progressPercent.textContent = data.progress_percent.toFixed(1) + '%';
                    }
                    
                    if (progressRemaining && total > 0) {
                        const remaining = Math.max(0, total - data.collected);
                        progressRemaining.textContent = `Осталось: ${remaining} векселей`;
                    }
                    
                    if (guarantorsCount) {
                        guarantorsCount.textContent = data.guarantors_count;
                    }
                    
                    if (fulfilledStatus) {
                        if (data.is_fulfilled) {
                            fulfilledStatus.style.display = 'inline';
                        } else {
                            fulfilledStatus.style.display = 'none';
                        }
                    }
                }
            })
            .catch(error => {
                console.error(`Ошибка при обновлении прогресса заявки #${requestId}:`, error);
            });
}
</script>
</div>
<?php
$content = ob_get_clean();
include __DIR__ . '/../templates/base.php';

