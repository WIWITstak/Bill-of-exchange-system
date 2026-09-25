<?php
/**
 * Каталог товаров и услуг (общий пул всех пользователей)
 */

require_once __DIR__ . '/../../src/bootstrap.php';

use OGAS\Services\Auth;
use OGAS\Models\Product;
use OGAS\Models\User;
use OGAS\Models\Rating;
use OGAS\Core\Session;

Auth::requireAuth();
$user = Auth::user();

if (!$user) {
    Auth::logout();
    header('Location: /login.php');
    exit;
}

// Получаем рейтинг пользователя для расчета динамических цен
$userRating = Rating::findByUserId($user->getId());
$userRatingValue = $userRating ? $userRating->getEffectiveRating() : 1.0;

$error = Session::getFlash('error');
$success = Session::getFlash('success');

// Фильтры
$typeFilter = $_GET['type'] ?? null;
$categoryFilter = $_GET['category'] ?? null;
$searchQuery = $_GET['search'] ?? '';

// Получаем все товары/услуги
$filters = ['is_available' => true]; // Только доступные

// Можно добавить фильтр чтобы скрывать свои товары
$excludeOwn = isset($_GET['exclude_own']) && $_GET['exclude_own'] === '1';
if ($excludeOwn) {
    $filters['exclude_user_id'] = $user->getId();
}

if ($typeFilter) {
    $filters['type'] = $typeFilter;
}

if ($categoryFilter) {
    $filters['category'] = $categoryFilter;
}

if ($searchQuery) {
    $filters['search'] = $searchQuery;
}

// Получаем товары через findAll
$allProducts = Product::findAll($filters);

$categories = Product::getCategories();

// Пагинация
$page = max(1, (int)($_GET['page'] ?? 1));
$perPage = 12;
$totalProducts = count($allProducts);
$totalPages = ceil($totalProducts / $perPage);
$offset = ($page - 1) * $perPage;
$products = array_slice($allProducts, $offset, $perPage);

$title = 'Каталог товаров и услуг';
ob_start();
?>
<div class="dashboard">
    <div class="dashboard-header">
        <h2>Каталог товаров и услуг</h2>
        <div class="header-actions">
            <a href="/products.php" class="btn btn-secondary">
                <i class="fas fa-box"></i> Мои товары
            </a>
            <a href="/products/create.php" class="btn btn-primary">
                <i class="fas fa-plus"></i> Добавить
            </a>
        </div>
    </div>

    <?php if ($error): ?>
        <div class="alert alert-error"><?= htmlspecialchars($error) ?></div>
    <?php endif; ?>

    <?php if ($success): ?>
        <div class="alert alert-success"><?= htmlspecialchars($success) ?></div>
    <?php endif; ?>

    <!-- Информация о каталоге -->
    <div class="catalog-info">
        <!-- Информация о динамическом ценообразовании -->
        <div class="catalog-dynamic-pricing-info" style="background: var(--bg-secondary, #f5f5f5); padding: 16px; border-radius: 8px; margin-bottom: 20px; border-left: 4px solid var(--color-primary, #007bff);">
            <div style="display: flex; align-items: start; gap: 12px;">
                <div style="font-size: 1.5em;">💡</div>
                <div style="flex: 1;">
                    <h4 style="margin: 0 0 8px 0; font-size: 1.1em; color: var(--text-primary, #333);">
                        Динамическое ценообразование
                    </h4>
                    <p style="margin: 0; font-size: 0.95em; color: var(--text-secondary, #666); line-height: 1.6;">
                        Цены в каталоге рассчитываются индивидуально для каждого пользователя на основе рейтинга "Око". 
                        <strong>Ваш текущий рейтинг: <?= number_format($userRatingValue, 2) ?></strong>
                        <?php if ($userRatingValue > 1.0): ?>
                            — вы получаете скидку! Чем выше рейтинг, тем ниже цена.
                        <?php elseif ($userRatingValue == 1.0): ?>
                            — базовый рейтинг. Повышайте рейтинг для получения скидок.
                        <?php endif; ?>
                    </p>
                    <p style="margin: 8px 0 0 0; font-size: 0.85em; color: var(--text-muted, #999);">
                        Формула: Фактическая цена = Начальная цена / Рейтинг покупателя
                    </p>
                </div>
            </div>
        </div>
        
        <div class="catalog-stats">
            <div class="stat-item">
                <i class="fas fa-box"></i>
                <span>Товаров: <strong><?= count(array_filter($allProducts, fn($p) => $p->getType() === 'product')) ?></strong></span>
            </div>
            <div class="stat-item">
                <i class="fas fa-briefcase"></i>
                <span>Услуг: <strong><?= count(array_filter($allProducts, fn($p) => $p->getType() === 'service')) ?></strong></span>
            </div>
            <div class="stat-item">
                <i class="fas fa-list"></i>
                <span>Всего: <strong><?= $totalProducts ?></strong></span>
            </div>
        </div>
    </div>

    <!-- Фильтры -->
    <?php 
    $hasActiveFilters = !empty($searchQuery) || !empty($typeFilter) || !empty($categoryFilter) || $excludeOwn;
    ?>
    <div class="filters-wrapper" data-has-filters="<?= $hasActiveFilters ? 'true' : 'false' ?>">
        <div class="filters-toggle-header <?= !$hasActiveFilters ? 'collapsed' : '' ?>">
            <div class="filters-toggle-title">
                <i class="fas fa-filter filters-toggle-icon"></i>
                <span>Фильтры<?= $hasActiveFilters ? ' <span style="color: var(--color-primary); font-weight: 600;">(активны)</span>' : '' ?></span>
            </div>
        </div>
        <div class="search-filters-card <?= !$hasActiveFilters ? 'collapsed' : 'expanded' ?>"<?= !$hasActiveFilters ? ' style="max-height: 0; padding: 0; margin: 0; opacity: 0; overflow: hidden;"' : '' ?>>
            <form method="GET" action="/products/catalog.php" class="search-filters-form">
                <div class="filters-row">
                <div class="filter-group">
                    <label for="search">Поиск:</label>
                    <input type="text" 
                           name="search" 
                           id="search" 
                           class="filter-input" 
                           placeholder="Название, описание или продавец..."
                           value="<?= htmlspecialchars($searchQuery) ?>">
                </div>

                <div class="filter-group">
                    <label for="type">Тип:</label>
                    <select name="type" id="type" class="filter-select">
                        <option value="">Все</option>
                        <option value="product" <?= $typeFilter === 'product' ? 'selected' : '' ?>>Товары</option>
                        <option value="service" <?= $typeFilter === 'service' ? 'selected' : '' ?>>Услуги</option>
                    </select>
                </div>

                <?php if (!empty($categories)): ?>
                <div class="filter-group">
                    <label for="category">Категория:</label>
                    <select name="category" id="category" class="filter-select">
                        <option value="">Все категории</option>
                        <?php foreach ($categories as $cat): ?>
                            <option value="<?= htmlspecialchars($cat) ?>" <?= $categoryFilter === $cat ? 'selected' : '' ?>>
                                <?= htmlspecialchars($cat) ?>
                            </option>
                        <?php endforeach; ?>
                    </select>
                </div>
                <?php endif; ?>

                <div class="filter-group">
                    <label class="form-checkbox">
                        <input type="checkbox" 
                               name="exclude_own" 
                               value="1" 
                               <?= $excludeOwn ? 'checked' : '' ?>>
                        <span>Скрыть мои товары</span>
                    </label>
                </div>

                <div class="filter-actions">
                    <button type="submit" class="btn btn-primary">Применить</button>
                    <?php if ($typeFilter || $categoryFilter || $searchQuery || $excludeOwn): ?>
                        <a href="/products/catalog.php" class="btn btn-secondary">Сбросить</a>
                    <?php endif; ?>
                </div>
            </div>
        </form>
        </div>
    </div>

    <!-- Список товаров/услуг -->
    <?php if (empty($products)): ?>
        <div class="empty-state">
            <div class="empty-state-icon">
                <i class="fas fa-search"></i>
            </div>
            <h3>Товары и услуги не найдены</h3>
            <p>Попробуйте изменить параметры поиска или фильтры</p>
        </div>
    <?php else: ?>
        <!-- Переключатели -->
        <div class="controls-wrapper">
            <div class="view-toggle-wrapper">
                <div class="view-toggle">
                    <button class="view-toggle-btn active" data-view="cards" title="Карточки">
                        <i class="fas fa-th-large"></i>
                    </button>
                    <button class="view-toggle-btn" data-view="table" title="Таблица">
                        <i class="fas fa-table"></i>
                    </button>
                </div>
            </div>
            
            <!-- Переключатель режима отображения графика -->
            <div class="chart-mode-toggle-wrapper">
                <div class="chart-mode-toggle">
                    <button class="chart-mode-btn active" data-mode="modal" title="Модальное окно">
                        <i class="fas fa-window-maximize"></i>
                        <span>Окно</span>
                    </button>
                    <button class="chart-mode-btn" data-mode="tooltip" title="Тултип">
                        <i class="fas fa-comment-alt"></i>
                        <span>Тултип</span>
                    </button>
                </div>
            </div>
        </div>

        <!-- Карточное отображение -->
        <div class="products-grid catalog-grid" id="catalogCardsView">
            <?php foreach ($products as $product): 
                $seller = User::findById($product->getUserId());
                $isOwn = $product->getUserId() === $user->getId();
            ?>
                <div class="product-card <?= $isOwn ? 'product-own' : '' ?>">
                    <?php if (!empty($product->getImages())): ?>
                        <div class="product-image">
                            <img src="<?= htmlspecialchars($product->getImages()[0]) ?>" alt="<?= htmlspecialchars($product->getName()) ?>">
                        </div>
                    <?php else: ?>
                        <div class="product-image product-no-image">
                            <i class="fas fa-<?= $product->getType() === 'service' ? 'briefcase' : 'box' ?>"></i>
                        </div>
                    <?php endif; ?>

                    <div class="product-content">
                        <div class="product-header">
                            <h3 class="product-title"><?= htmlspecialchars($product->getName()) ?></h3>
                            <span class="product-type-badge product-type-<?= $product->getType() ?>">
                                <?= $product->getType() === 'service' ? 'Услуга' : 'Товар' ?>
                            </span>
                        </div>

                        <?php if ($seller): ?>
                            <div class="product-seller">
                                <i class="fas fa-user"></i>
                                <a href="/user.php?id=<?= $seller->getId() ?>"><?= htmlspecialchars($seller->getFullName()) ?></a>
                                <?php if ($isOwn): ?>
                                    <span class="product-seller-badge">(Вы)</span>
                                <?php endif; ?>
                            </div>
                        <?php endif; ?>

                        <?php if ($product->getCategory()): ?>
                            <div class="product-category">
                                <i class="fas fa-tag"></i>
                                <?= htmlspecialchars($product->getCategory()) ?>
                            </div>
                        <?php endif; ?>

                        <?php if ($product->getDescription()): ?>
                            <p class="product-description">
                                <?= htmlspecialchars(mb_substr($product->getDescription(), 0, 100)) ?>
                                <?= mb_strlen($product->getDescription()) > 100 ? '...' : '' ?>
                            </p>
                        <?php endif; ?>

                        <div class="product-footer">
                            <div class="product-price price-with-chart" data-product-id="<?= $product->getId() ?>">
                                <?php
                                $doublesPrice = $product->getDoublesPrice();
                                $actualPrice = $product->getActualPrice($userRatingValue);
                                $basePrice = $product->getBasePrice(); // Для обратной совместимости
                                ?>
                                <div class="price-info">
                                    <span class="price-value actual-price">
                                        <?= number_format($actualPrice, 2, '.', ' ') ?> ₽
                                    </span>
                                    <?php if ($doublesPrice > 0): ?>
                                        <div class="doubles-price-info" title="Цена в дублях">
                                            <span class="doubles-value"><?= number_format($doublesPrice, 2, '.', ' ') ?> дублей</span>
                                        </div>
                                    <?php endif; ?>
                                    <?php if ($basePrice != $actualPrice && $doublesPrice == 0): ?>
                                        <span class="base-price">(начальная: <?= number_format($basePrice, 2, '.', ' ') ?> ₽)</span>
                                    <?php endif; ?>
                                </div>
                                <?php if ($product->getUnit()): ?>
                                    <span class="product-unit">/ <?= htmlspecialchars($product->getUnit()) ?></span>
                                <?php endif; ?>
                                <?php if ($userRatingValue > 1.0): ?>
                                    <div class="price-discount-badge" title="Ваш рейтинг: <?= number_format($userRatingValue, 2) ?>">
                                        <i class="fas fa-star"></i> Скидка за рейтинг
                                    </div>
                                <?php endif; ?>
                            </div>

                            <?php if ($product->getQuantity() !== null): ?>
                                <div class="product-quantity">
                                    Осталось: <?= $product->getQuantity() ?>
                                </div>
                            <?php endif; ?>
                        </div>

                        <div class="product-status">
                            <span class="status status-active">
                                <i class="fas fa-check-circle"></i> Доступно
                            </span>
                        </div>

                        <div class="product-actions">
                            <?php if ($isOwn): ?>
                                <a href="/products/edit.php?id=<?= $product->getId() ?>" class="btn btn-secondary btn-sm">
                                    <i class="fas fa-edit"></i> Редактировать
                                </a>
                            <?php else: ?>
                                <a href="/user.php?id=<?= $seller->getId() ?>" class="btn btn-primary btn-sm">
                                    <i class="fas fa-user"></i> Профиль
                                </a>
                                <a href="/transactions/create.php?buyer_id=<?= $seller->getId() ?>&product_id=<?= $product->getId() ?>" 
                                   class="btn btn-success btn-sm">
                                    <i class="fas fa-shopping-cart"></i> Заказать
                                </a>
                            <?php endif; ?>
                        </div>
                    </div>
                </div>
            <?php endforeach; ?>
        </div>

        <!-- Табличное отображение -->
        <div class="products-table-wrapper" id="catalogTableView" style="display: none;">
            <table class="products-table">
                <thead>
                    <tr>
                        <th>Название</th>
                        <th>Продавец</th>
                        <th>Тип</th>
                        <th>Категория</th>
                        <th>Цена</th>
                        <th>Количество</th>
                        <th>Статус</th>
                        <th>Действия</th>
                    </tr>
                </thead>
                <tbody>
                    <?php foreach ($products as $product): 
                        $seller = User::findById($product->getUserId());
                        $isOwn = $product->getUserId() === $user->getId();
                    ?>
                        <tr class="<?= $isOwn ? 'table-row-own' : '' ?>">
                            <td>
                                <div class="table-product-name">
                                    <?php if (!empty($product->getImages())): ?>
                                        <img src="<?= htmlspecialchars($product->getImages()[0]) ?>" 
                                             alt="<?= htmlspecialchars($product->getName()) ?>" 
                                             class="table-product-image">
                                    <?php else: ?>
                                        <div class="table-product-image table-product-no-image">
                                            <i class="fas fa-<?= $product->getType() === 'service' ? 'briefcase' : 'box' ?>"></i>
                                        </div>
                                    <?php endif; ?>
                                    <div class="table-product-info">
                                        <strong><?= htmlspecialchars($product->getName()) ?></strong>
                                        <?php if ($product->getDescription()): ?>
                                            <span class="table-product-desc">
                                                <?= htmlspecialchars(mb_substr($product->getDescription(), 0, 50)) ?>
                                                <?= mb_strlen($product->getDescription()) > 50 ? '...' : '' ?>
                                            </span>
                                        <?php endif; ?>
                                    </div>
                                </div>
                            </td>
                            <td>
                                <div class="table-seller">
                                    <a href="/user.php?id=<?= $seller->getId() ?>">
                                        <?= htmlspecialchars($seller->getFullName()) ?>
                                    </a>
                                    <?php if ($isOwn): ?>
                                        <span class="product-seller-badge">(Вы)</span>
                                    <?php endif; ?>
                                </div>
                            </td>
                            <td>
                                <span class="product-type-badge product-type-<?= $product->getType() ?>">
                                    <?= $product->getType() === 'service' ? 'Услуга' : 'Товар' ?>
                                </span>
                            </td>
                            <td>
                                <?php if ($product->getCategory()): ?>
                                    <i class="fas fa-tag"></i> <?= htmlspecialchars($product->getCategory()) ?>
                                <?php else: ?>
                                    <span class="text-muted">—</span>
                                <?php endif; ?>
                            </td>
                            <td>
                                <div class="table-price price-with-chart" data-product-id="<?= $product->getId() ?>">
                                    <?php
                                    $doublesPrice = $product->getDoublesPrice();
                                    $actualPrice = $product->getActualPrice($userRatingValue);
                                    $basePrice = $product->getBasePrice(); // Для обратной совместимости
                                    ?>
                                    <div class="price-info">
                                        <span class="price-value actual-price">
                                            <strong><?= number_format($actualPrice, 2, '.', ' ') ?> ₽</strong>
                                        </span>
                                        <?php if ($doublesPrice > 0): ?>
                                            <div class="doubles-price-info" title="Цена в дублях">
                                                <span class="doubles-value"><?= number_format($doublesPrice, 2, '.', ' ') ?> дублей</span>
                                            </div>
                                        <?php endif; ?>
                                        <?php if ($basePrice != $actualPrice && $doublesPrice == 0): ?>
                                            <span class="base-price">(начальная: <?= number_format($basePrice, 2, '.', ' ') ?> ₽)</span>
                                        <?php endif; ?>
                                    </div>
                                    <?php if ($product->getUnit()): ?>
                                        <span class="table-unit">/ <?= htmlspecialchars($product->getUnit()) ?></span>
                                    <?php endif; ?>
                                    <?php if ($userRatingValue > 1.0): ?>
                                        <div class="price-discount-badge" title="Ваш рейтинг: <?= number_format($userRatingValue, 2) ?>">
                                            <i class="fas fa-star"></i> Скидка
                                        </div>
                                    <?php endif; ?>
                                </div>
                            </td>
                            <td>
                                <?php if ($product->getQuantity() !== null): ?>
                                    <?= $product->getQuantity() ?>
                                <?php else: ?>
                                    <span class="text-muted">∞</span>
                                <?php endif; ?>
                            </td>
                            <td>
                                <span class="status status-active">
                                    <i class="fas fa-check-circle"></i> Доступно
                                </span>
                            </td>
                            <td>
                                <div class="table-actions">
                                    <?php if ($isOwn): ?>
                                        <a href="/products/edit.php?id=<?= $product->getId() ?>" 
                                           class="btn-icon" 
                                           title="Редактировать">
                                            <i class="fas fa-edit"></i>
                                        </a>
                                    <?php else: ?>
                                        <a href="/user.php?id=<?= $seller->getId() ?>" 
                                           class="btn-icon" 
                                           title="Профиль продавца">
                                            <i class="fas fa-user"></i>
                                        </a>
                                        <a href="/transactions/create.php?buyer_id=<?= $seller->getId() ?>&product_id=<?= $product->getId() ?>" 
                                           class="btn-icon btn-icon-success" 
                                           title="Заказать">
                                            <i class="fas fa-shopping-cart"></i>
                                        </a>
                                    <?php endif; ?>
                                </div>
                            </td>
                        </tr>
                    <?php endforeach; ?>
                </tbody>
            </table>
        </div>

        <!-- Модальные окна с графиками для всех товаров на странице -->
        <?php foreach ($products as $product): ?>
            <div class="price-modal" id="price-modal-<?= $product->getId() ?>">
                <div class="price-modal-content">
                    <div class="price-modal-header">
                        <h3>Динамика начальной цены</h3>
                        <button class="price-modal-close" onclick="closePriceChart(<?= $product->getId() ?>)">×</button>
                    </div>
                    <div class="price-modal-body" style="display: flex; gap: 16px; align-items: flex-start;">
                        <div style="flex-shrink: 0; width: 200px; padding: 10px; background: var(--bg-secondary, #f5f5f5); border-radius: 6px; font-size: 0.85em; color: var(--text-secondary, #666);">
                            <strong>ℹ️ Информация:</strong>
                            <ul style="margin: 6px 0 0 18px; padding: 0; line-height: 1.4;">
                                <li><strong style="color: #2c3e50;">Начальная цена</strong> (пунктир) — цена продавца</li>
                                <li><strong style="color: #27ae60;">Фактическая цена</strong> (сплошная) — цена для вас (рейтинг: <?= number_format($userRatingValue, 2) ?>)</li>
                            </ul>
                            <div style="margin-top: 6px; font-size: 0.8em;">
                                Формула: Фактическая = Начальная / Рейтинг
                            </div>
                        </div>
                        <div style="flex: 1; min-width: 0;">
                            <canvas id="price-chart-<?= $product->getId() ?>" width="1000" height="210"></canvas>
                        </div>
                    </div>
                </div>
            </div>
        <?php endforeach; ?>

        <!-- Пагинация -->
        <?php if ($totalPages > 1): ?>
            <div class="pagination-wrapper">
                <div class="pagination">
                    <?php if ($page > 1): ?>
                        <a href="?<?= http_build_query(array_merge($_GET, ['page' => $page - 1])) ?>" class="pagination-link">
                            <i class="fas fa-chevron-left"></i> Назад
                        </a>
                    <?php endif; ?>

                    <?php for ($i = max(1, $page - 2); $i <= min($totalPages, $page + 2); $i++): ?>
                        <a href="?<?= http_build_query(array_merge($_GET, ['page' => $i])) ?>" 
                           class="pagination-link <?= $i === $page ? 'pagination-active' : '' ?>">
                            <?= $i ?>
                        </a>
                    <?php endfor; ?>

                    <?php if ($page < $totalPages): ?>
                        <a href="?<?= http_build_query(array_merge($_GET, ['page' => $page + 1])) ?>" class="pagination-link">
                            Вперед <i class="fas fa-chevron-right"></i>
                        </a>
                    <?php endif; ?>
                </div>
            </div>
        <?php endif; ?>
    <?php endif; ?>
</div>

<!-- Chart.js для графиков -->
<script src="https://cdn.jsdelivr.net/npm/chart.js@4.4.0/dist/chart.umd.min.js"></script>
<!-- Tippy.js для тултипов (Popper.js - зависимость) -->
<link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/tippy.js@6.3.7/dist/tippy.css">
<script src="https://cdn.jsdelivr.net/npm/@popperjs/core@2.11.8/dist/umd/popper.min.js"></script>
<script src="https://cdn.jsdelivr.net/npm/tippy.js@6.3.7/dist/tippy-bundle.umd.min.js"></script>

<script>
// График динамики цен с поддержкой модального окна и тултипа

(function() {
    // Хранилище графиков и данных
    const charts = {}; // Ключ: `${productId}-${instanceKey}` - отдельный график для каждого canvas
    const chartDataCache = {}; // Ключ: productId - кэш данных для переиспользования
    const tooltipInstances = {}; // Ключ: `${productId}-${elementIndex}`
    
    // Текущий режим отображения (modal или tooltip)
    let chartMode = localStorage.getItem('chart_mode') || 'modal';
    
    // Функция для получения данных графика
    function fetchChartData(productId) {
        const userRating = <?= $userRatingValue ?>;
        
        return fetch(`/api/product_price_history.php?id=${productId}`)
            .then(response => response.json())
            .then(data => {
                if (!data.success || !data.prices || data.prices.length === 0) {
                    return null;
                }
                
                // Подготавливаем данные
                const labels = data.prices.map(item => {
                    const date = new Date(item.date);
                    return date.toLocaleDateString('ru-RU', { day: '2-digit', month: '2-digit' });
                });
                
                // Начальные цены (base_price)
                const basePrices = data.prices.map(item => item.price);
                
                // Фактические цены для текущего пользователя (base_price / userRating)
                const actualPrices = basePrices.map(basePrice => basePrice / userRating);
                
                return { 
                    labels, 
                    basePrices, 
                    actualPrices,
                    userRating 
                };
            })
            .catch(error => {
                console.error('Ошибка загрузки графика:', error);
                return null;
            });
    }
    
    // Создать график на canvas
    function createChart(canvas, data) {
        if (!data) {
            const ctx = canvas.getContext('2d');
            ctx.fillStyle = '#999';
            ctx.font = '14px Arial';
            ctx.textAlign = 'center';
            ctx.fillText('Нет данных', canvas.width / 2, canvas.height / 2);
            return null;
        }
        
        // Цвета для графика
        const colors = {
            basePrice: {
                border: '#2c3e50',
                background: 'rgba(44, 62, 80, 0.1)',
                point: '#2c3e50'
            },
            actualPrice: {
                border: '#27ae60',
                background: 'rgba(39, 174, 96, 0.1)',
                point: '#27ae60'
            },
            grid: 'rgba(0, 0, 0, 0.05)',
            text: '#333'
        };
        
        // Создаем график с двумя линиями
        return new Chart(canvas.getContext('2d'), {
            type: 'line',
            data: {
                labels: data.labels,
                datasets: [
                    {
                        label: 'Начальная цена, ₽',
                        data: data.basePrices,
                        borderColor: colors.basePrice.border,
                        backgroundColor: colors.basePrice.background,
                        borderWidth: 2,
                        fill: false,
                        tension: 0.4,
                        pointRadius: 4,
                        pointHoverRadius: 6,
                        pointBackgroundColor: 'transparent',
                        pointHoverBackgroundColor: 'transparent',
                        pointBorderColor: colors.basePrice.border,
                        pointHoverBorderColor: colors.basePrice.border,
                        pointBorderWidth: 2,
                        pointHoverBorderWidth: 2,
                        pointStyle: 'circle',
                        hoverBackgroundColor: 'transparent',
                        hoverBorderColor: colors.basePrice.border,
                        borderDash: [5, 5] // Пунктирная линия для начальной цены
                    },
                    {
                        label: 'Фактическая цена (ваша), ₽',
                        data: data.actualPrices,
                        borderColor: colors.actualPrice.border,
                        backgroundColor: colors.actualPrice.background,
                        borderWidth: 2,
                        fill: true,
                        tension: 0.4,
                        pointRadius: 4,
                        pointHoverRadius: 6,
                        pointBackgroundColor: 'transparent',
                        pointHoverBackgroundColor: 'transparent',
                        pointBorderColor: colors.actualPrice.border,
                        pointHoverBorderColor: colors.actualPrice.border,
                        pointBorderWidth: 2,
                        pointHoverBorderWidth: 2,
                        pointStyle: 'circle',
                        hoverBackgroundColor: 'transparent',
                        hoverBorderColor: colors.actualPrice.border
                    }
                ]
            },
            options: {
                responsive: true,
                maintainAspectRatio: false,
                layout: {
                    padding: {
                        top: 5,
                        bottom: 5,
                        left: 5,
                        right: 5
                    }
                },
                plugins: {
                    legend: { 
                        display: true,
                        position: 'top',
                        labels: {
                            usePointStyle: true,
                            padding: 15,
                            font: {
                                size: 12
                            },
                            color: colors.text
                        }
                    },
                    tooltip: {
                        backgroundColor: 'rgba(0, 0, 0, 0.8)',
                        titleColor: '#fff',
                        bodyColor: '#fff',
                        borderColor: 'transparent',
                        borderWidth: 0,
                        padding: 12,
                        displayColors: true,
                        caretSize: 5,
                        titleFont: {
                            size: 13,
                            weight: 'bold'
                        },
                        bodyFont: {
                            size: 13
                        },
                        boxPadding: 6,
                        callbacks: {
                            title: (items) => {
                                if (items.length > 0) {
                                    const label = items[0].label;
                                    return label || 'Дата';
                                }
                                return '';
                            },
                            label: (ctx) => {
                                const value = ctx.parsed.y;
                                const formattedPrice = value.toLocaleString('ru-RU', {
                                    minimumFractionDigits: 2,
                                    maximumFractionDigits: 2
                                });
                                
                                // Определяем, какая это линия (начальная или фактическая)
                                const datasetLabel = ctx.dataset.label || '';
                                return datasetLabel + ': ' + formattedPrice + ' ₽';
                            },
                            afterLabel: (items) => {
                                // Показываем разницу между начальной и фактической ценой
                                if (items.length === 2) {
                                    const basePriceItem = items.find(item => item.datasetIndex === 0);
                                    const actualPriceItem = items.find(item => item.datasetIndex === 1);
                                    
                                    if (basePriceItem && actualPriceItem) {
                                        const basePrice = basePriceItem.parsed.y;
                                        const actualPrice = actualPriceItem.parsed.y;
                                        const difference = basePrice - actualPrice;
                                        const discountPercent = ((difference / basePrice) * 100).toFixed(1);
                                        
                                        if (difference > 0) {
                                            return '\\nСкидка: ' + discountPercent + '% (' + difference.toLocaleString('ru-RU', {
                                                minimumFractionDigits: 2,
                                                maximumFractionDigits: 2
                                            }) + ' ₽)';
                                        }
                                    }
                                }
                                return '';
                            },
                            labelTextColor: () => '#fff'
                        }
                    },
                    interaction: {
                        intersect: false,
                        mode: 'index'
                    }
                },
                scales: {
                    y: {
                        beginAtZero: false,
                        ticks: {
                            color: colors.text,
                            callback: (value) => value.toFixed(2) + ' ₽'
                        },
                        grid: { color: colors.grid }
                    },
                    x: {
                        ticks: { color: colors.text },
                        grid: { display: false }
                    }
                }
            }
        });
    }
    
    // Открыть модальное окно с графиком
    function openChart(productId) {
        // Проверяем, что мы в режиме modal
        if (chartMode !== 'modal') {
            return;
        }
        
        const modal = document.getElementById(`price-modal-${productId}`);
        if (!modal) return;
        
        // Показываем модальное окно с flexbox для центрирования
        modal.style.display = 'flex';
        modal.style.alignItems = 'center';
        modal.style.justifyContent = 'center';
        
        const modalChartKey = `modal-${productId}`;
        const canvas = document.getElementById(`price-chart-${productId}`);
        if (!canvas) return;
        
        // Проверяем, существует ли график и он валиден
        let chartExists = charts[modalChartKey] && charts[modalChartKey].canvas === canvas;
        
        // Если график не существует или не связан с текущим canvas, создаем его
        if (!chartExists) {
            // Удаляем старый график, если он есть, но связан с другим canvas
            if (charts[modalChartKey]) {
                try {
                    charts[modalChartKey].destroy();
                } catch (e) {
                    // Игнорируем ошибки при удалении
                }
                delete charts[modalChartKey];
            }
            
            // Показываем индикатор загрузки
            const ctx = canvas.getContext('2d');
            ctx.clearRect(0, 0, canvas.width, canvas.height);
            ctx.fillStyle = '#999';
            ctx.font = '14px Arial';
            ctx.textAlign = 'center';
            ctx.fillText('Загрузка данных...', canvas.width / 2, canvas.height / 2);
            
            // Если данные уже есть в кэше, используем их
            if (chartDataCache[productId]) {
                // Небольшая задержка для отрисовки после показа модального окна
                setTimeout(() => {
                    try {
                        charts[modalChartKey] = createChart(canvas, chartDataCache[productId]);
                    } catch (e) {
                        console.error('Ошибка создания графика в модальном окне:', e);
                        ctx.fillText('Ошибка создания графика', canvas.width / 2, canvas.height / 2);
                    }
                }, 100);
            } else {
                fetchChartData(productId).then(data => {
                    if (data) {
                        chartDataCache[productId] = data;
                        try {
                            charts[modalChartKey] = createChart(canvas, data);
                        } catch (e) {
                            console.error('Ошибка создания графика в модальном окне:', e);
                            ctx.fillText('Ошибка создания графика', canvas.width / 2, canvas.height / 2);
                        }
                    } else {
                        ctx.fillText('Нет данных', canvas.width / 2, canvas.height / 2);
                    }
                }).catch(error => {
                    console.error('Ошибка загрузки данных для модального окна:', error);
                    ctx.fillText('Ошибка загрузки', canvas.width / 2, canvas.height / 2);
                });
            }
        } else {
            // Обновляем размер существующего графика
            setTimeout(() => {
                try {
                    if (charts[modalChartKey] && typeof charts[modalChartKey].resize === 'function') {
                        charts[modalChartKey].resize();
                    }
                } catch (e) {
                    console.error('Ошибка обновления размера графика:', e);
                    // Пересоздаем график при ошибке
                    delete charts[modalChartKey];
                    if (chartDataCache[productId]) {
                        charts[modalChartKey] = createChart(canvas, chartDataCache[productId]);
                    }
                }
            }, 100);
        }
    }
    
    // Закрыть модальное окно
    function closeChart(productId) {
        const modal = document.getElementById(`price-modal-${productId}`);
        if (modal) {
            modal.style.display = 'none';
        }
    }
    
    // Инициализировать тултип для цены
    function initTooltip(priceElement, productId, instanceKey) {
        // Удаляем существующий тултип, если есть
        if (tooltipInstances[instanceKey]) {
            try {
                tooltipInstances[instanceKey].destroy();
            } catch (e) {
                // Игнорируем ошибки при удалении
            }
            delete tooltipInstances[instanceKey];
        }
        
        // Проверяем, что элемент видим
        const rect = priceElement.getBoundingClientRect();
        if (rect.width === 0 && rect.height === 0) {
            // Элемент не видим, пропускаем
            return;
        }
        
        // Создаем контейнер для графика в тултипе
        const tooltipContent = document.createElement('div');
        tooltipContent.className = 'price-tooltip-content';
        
        // Добавляем заголовок и информацию о ценах
        const tooltipHeader = document.createElement('div');
        tooltipHeader.style.cssText = 'padding: 8px 12px; background: var(--bg-secondary, #f5f5f5); border-radius: 6px 6px 0 0; font-size: 0.85em; color: var(--text-secondary, #666); border-bottom: 1px solid var(--bg-tertiary, #e0e0e0);';
        tooltipHeader.innerHTML = '<strong>📊 Динамика цен</strong><br><small>Показаны начальная и фактическая цены. Ваш рейтинг: <?= number_format($userRatingValue, 2) ?></small>';
        tooltipContent.appendChild(tooltipHeader);
        
        // Контейнер для загрузки и графика
        const tooltipBody = document.createElement('div');
        tooltipBody.style.cssText = 'padding: 8px;';
        tooltipBody.innerHTML = '<div class="price-tooltip-loading">Загрузка...</div>';
        tooltipContent.appendChild(tooltipBody);
        
        // Создаем canvas для графика
        const canvas = document.createElement('canvas');
        canvas.width = 420;
        canvas.height = 200;
        canvas.style.display = 'none';
        canvas.style.maxWidth = '100%';
        canvas.style.height = 'auto';
        tooltipBody.appendChild(canvas);
        
        // Проверяем, что Tippy.js доступен
        if (typeof tippy === 'undefined') {
            console.error('Tippy.js не доступен для инициализации тултипа');
            return;
        }
        
        // Инициализируем Tippy
        try {
            tooltipInstances[instanceKey] = tippy(priceElement, {
                content: tooltipContent,
                allowHTML: true,
                interactive: true,
                trigger: 'mouseenter focus',
                placement: 'top',
                theme: 'price-chart',
                animation: 'fade',
                delay: [200, 0],
                boundary: 'viewport',
                flip: true,
                flipOnUpdate: true,
                offset: [0, 10],
                maxWidth: 450,
                onShow(instance) {
                    // Проверяем, что мы все еще в режиме tooltip
                    if (chartMode !== 'tooltip') {
                        instance.hide();
                        return;
                    }
                    
                    const chartKey = `${productId}-${instanceKey}`;
                    
                    // Проверяем валидность существующего графика
                    let chartExists = charts[chartKey] && charts[chartKey].canvas === canvas;
                    
                    // Если график уже создан и валиден для этого canvas, просто обновляем размер
                    if (chartExists) {
                        const loadingEl = tooltipContent.querySelector('.price-tooltip-loading');
                        if (loadingEl) {
                            loadingEl.style.display = 'none';
                        }
                        // Убеждаемся, что заголовок виден
                        const header = tooltipContent.querySelector('div:first-child');
                        if (header && header.textContent.includes('Динамика')) {
                            header.style.display = 'block';
                        }
                        canvas.style.display = 'block';
                        setTimeout(() => {
                            try {
                                if (charts[chartKey] && typeof charts[chartKey].resize === 'function') {
                                    charts[chartKey].resize();
                                }
                            } catch (e) {
                                console.error('Ошибка обновления размера графика в тултипе:', e);
                                // Пересоздаем график при ошибке
                                delete charts[chartKey];
                                if (chartDataCache[productId]) {
                                    charts[chartKey] = createChart(canvas, chartDataCache[productId]);
                                }
                            }
                        }, 50);
                        return;
                    }
                    
                    // Убеждаемся, что заголовок виден
                    const header = tooltipContent.querySelector('div:first-child');
                    if (header && header.textContent.includes('Динамика')) {
                        header.style.display = 'block';
                    }
                    
                    // Удаляем старый график, если он существует, но связан с другим canvas
                    if (charts[chartKey]) {
                        try {
                            charts[chartKey].destroy();
                        } catch (e) {
                            // Игнорируем ошибки
                        }
                        delete charts[chartKey];
                    }
                    
                    // Если данные уже есть в кэше, используем их
                    if (chartDataCache[productId]) {
                        const loadingEl = tooltipContent.querySelector('.price-tooltip-loading');
                        if (loadingEl) {
                            loadingEl.style.display = 'none';
                        }
                        // Убеждаемся, что заголовок виден
                        const header = tooltipContent.querySelector('div:first-child');
                        if (header) {
                            header.style.display = 'block';
                        }
                        canvas.style.display = 'block';
                        // Небольшая задержка для корректной инициализации
                        setTimeout(() => {
                            try {
                                // Проверяем, что мы все еще в режиме tooltip
                                if (chartMode !== 'tooltip') {
                                    return;
                                }
                                charts[chartKey] = createChart(canvas, chartDataCache[productId]);
                            } catch (e) {
                                console.error('Ошибка создания графика в тултипе:', e);
                            }
                        }, 100);
                        return;
                    }
                    
                    // Загружаем данные при показе тултипа
                    fetchChartData(productId).then(data => {
                        // Проверяем, что мы все еще в режиме tooltip
                        if (chartMode !== 'tooltip') {
                            return;
                        }
                        
                        if (data) {
                            // Сохраняем данные в кэш
                            chartDataCache[productId] = data;
                            
                            const loadingEl = tooltipContent.querySelector('.price-tooltip-loading');
                            if (loadingEl) {
                                loadingEl.style.display = 'none';
                            }
                            // Убеждаемся, что заголовок виден
                            const header = tooltipContent.querySelector('div:first-child');
                            if (header && header.textContent.includes('Динамика')) {
                                header.style.display = 'block';
                            }
                            canvas.style.display = 'block';
                            try {
                                charts[chartKey] = createChart(canvas, data);
                            } catch (e) {
                                console.error('Ошибка создания графика в тултипе:', e);
                                if (loadingEl) {
                                    loadingEl.textContent = 'Ошибка создания графика';
                                    loadingEl.style.display = 'block';
                                }
                            }
                        } else {
                            const loadingEl = tooltipContent.querySelector('.price-tooltip-loading');
                            if (loadingEl) {
                                loadingEl.textContent = 'Нет данных';
                            }
                            // Убеждаемся, что заголовок виден даже при отсутствии данных
                            const header = tooltipContent.querySelector('div:first-child');
                            if (header && header.textContent.includes('Динамика')) {
                                header.style.display = 'block';
                            }
                        }
                    }).catch(error => {
                        console.error('Ошибка загрузки данных для тултипа:', error);
                        const loadingEl = tooltipContent.querySelector('.price-tooltip-loading');
                        if (loadingEl) {
                            loadingEl.textContent = 'Ошибка загрузки';
                        }
                    });
                }
            });
        } catch (e) {
            console.error('Ошибка инициализации тултипа:', e);
        }
    }
    
    // Инициализировать все тултипы (только для видимых элементов)
    function initAllTooltips() {
        // Проверяем, какой вид сейчас активен
        const cardsView = document.getElementById('catalogCardsView');
        const tableView = document.getElementById('catalogTableView');
        const isTableViewActive = tableView && tableView.style.display !== 'none';
        const isCardsViewActive = cardsView && cardsView.style.display !== 'none';
        
        // Инициализируем тултипы только для видимых элементов
        document.querySelectorAll('.price-with-chart').forEach((element, index) => {
            const productId = element.getAttribute('data-product-id');
            if (!productId) return;
            
            // Проверяем, в каком контейнере находится элемент
            const isInCards = element.closest('#catalogCardsView') !== null;
            const isInTable = element.closest('#catalogTableView') !== null;
            
            // Инициализируем только для элементов в видимом контейнере
            if ((isInCards && isCardsViewActive) || (isInTable && isTableViewActive)) {
                // Создаем уникальный ключ для каждого экземпляра
                const instanceKey = `${productId}-${isInTable ? 'table' : 'card'}-${index}`;
                initTooltip(element, productId, instanceKey);
            }
        });
    }
    
    // Удалить все тултипы
    function destroyAllTooltips() {
        // Собираем все ключи графиков тултипов перед удалением
        const tooltipChartKeys = [];
        
        Object.keys(tooltipInstances).forEach(key => {
            // Формируем ключ графика для этого тултипа
            // instanceKey имеет формат: productId-table/index или productId-card/index
            const parts = key.split('-');
            if (parts.length >= 3 && (parts[1] === 'table' || parts[1] === 'card')) {
                // Ключ графика: productId-instanceKey
                const tooltipChartKey = `${parts[0]}-${key}`;
                tooltipChartKeys.push(tooltipChartKey);
            }
            
            if (tooltipInstances[key] && tooltipInstances[key].destroy) {
                try {
                    tooltipInstances[key].destroy();
                } catch (e) {
                    console.error('Ошибка при удалении тултипа:', e);
                }
            }
            delete tooltipInstances[key];
        });
        
        // Удаляем графики тултипов (не модальные окна)
        tooltipChartKeys.forEach(chartKey => {
            if (charts[chartKey]) {
                try {
                    charts[chartKey].destroy();
                } catch (e) {
                    // Игнорируем ошибки
                }
                delete charts[chartKey];
            }
        });
        
        // Также удаляем все графики, ключи которых содержат 'table' или 'card' и не начинаются с 'modal-'
        Object.keys(charts).forEach(chartKey => {
            if (!chartKey.startsWith('modal-') && (chartKey.includes('-table-') || chartKey.includes('-card-'))) {
                try {
                    charts[chartKey].destroy();
                } catch (e) {
                    // Игнорируем ошибки
                }
                delete charts[chartKey];
            }
        });
    }
    
    // Переключить режим отображения
    function switchChartMode(mode) {
        const previousMode = chartMode;
        chartMode = mode;
        localStorage.setItem('chart_mode', mode);
        
        // Обновляем активную кнопку
        document.querySelectorAll('.chart-mode-btn').forEach(btn => {
            if (btn.getAttribute('data-mode') === mode) {
                btn.classList.add('active');
            } else {
                btn.classList.remove('active');
            }
        });
        
        // Если переключаемся с tooltip на modal - закрываем все модальные окна (если были открыты)
        if (previousMode === 'tooltip' && mode === 'modal') {
            // Закрываем все открытые модальные окна
            document.querySelectorAll('.price-modal').forEach(modal => {
                modal.style.display = 'none';
            });
        }
        
        // Переинициализируем обработчики
        // Уничтожаем все тултипы и их графики перед переключением
        destroyAllTooltips();
        
        if (mode === 'tooltip') {
            // Небольшая задержка для корректной инициализации DOM после удаления
            setTimeout(() => {
                // Очищаем все тултипы перед инициализацией новых (на случай если что-то осталось)
                Object.keys(tooltipInstances).forEach(key => {
                    if (tooltipInstances[key] && tooltipInstances[key].destroy) {
                        try {
                            tooltipInstances[key].destroy();
                        } catch (e) {
                            // Игнорируем ошибки
                        }
                    }
                    delete tooltipInstances[key];
                });
                // Инициализируем тултипы заново
                initAllTooltips();
            }, 300);
        }
        
        // При переключении на modal не нужно ничего делать - графики уже есть в модальных окнах
        // и они будут созданы при первом открытии модального окна
    }
    
    // Экспортируем функцию для переинициализации при переключении видов
    window.reinitChartTooltips = function() {
        if (chartMode === 'tooltip') {
            destroyAllTooltips();
            setTimeout(() => {
                initAllTooltips();
            }, 100);
        }
    };
    
    // Инициализация обработчиков
    document.addEventListener('DOMContentLoaded', function() {
        // Проверяем, что Tippy.js загружен
        if (typeof tippy === 'undefined') {
            console.error('Tippy.js не загружен! Проверьте подключение библиотеки.');
            // Отключаем режим тултипа, если библиотека не загружена
            chartMode = 'modal';
            localStorage.setItem('chart_mode', 'modal');
        }
        
        // Применяем сохраненный режим
        const savedMode = localStorage.getItem('chart_mode') || 'modal';
        chartMode = savedMode;
        switchChartMode(savedMode);
        
        // Обработчик клика на цене (только для режима modal)
        document.addEventListener('click', function(e) {
            // Клик по кнопке закрытия
            if (e.target.classList.contains('price-modal-close')) {
                const modal = e.target.closest('.price-modal');
                if (modal) {
                    const productId = modal.id.replace('price-modal-', '');
                    closeChart(productId);
                }
                return;
            }
            
            // Пропускаем клики внутри модального окна
            if (e.target.closest('.price-modal')) {
                return;
            }
            
            // Клик по цене (только в режиме modal)
            if (chartMode === 'modal') {
                const priceElement = e.target.closest('.price-with-chart') || 
                                    e.target.closest('.price-value')?.parentElement;
                
                if (priceElement && priceElement.classList.contains('price-with-chart')) {
                    const productId = priceElement.getAttribute('data-product-id');
                    if (productId) {
                        openChart(productId);
                    }
                }
            }
        });
        
        // Обработчики переключения режима
        document.querySelectorAll('.chart-mode-btn').forEach(btn => {
            btn.addEventListener('click', function() {
                const mode = this.getAttribute('data-mode');
                switchChartMode(mode);
            });
        });
        
        // Визуальная подсказка при наведении
        document.addEventListener('mouseover', function(e) {
            if (e.target.closest('.price-with-chart')) {
                const element = e.target.closest('.price-with-chart');
                if (element) {
                    element.style.cursor = chartMode === 'modal' ? 'pointer' : 'default';
                }
            }
        });
    });
    
    // Глобальная функция для кнопки закрытия в HTML
    window.closePriceChart = function(productId) {
        closeChart(productId);
    };
})();

// Переключение вида отображения в каталоге
(function() {
    const savedView = localStorage.getItem('catalog_view') || 'cards';
    const cardsView = document.getElementById('catalogCardsView');
    const tableView = document.getElementById('catalogTableView');
    const viewToggleBtns = document.querySelectorAll('.view-toggle-btn');

    if (!cardsView || !tableView) return;

    function switchView(view) {
        if (view === 'table') {
            cardsView.style.display = 'none';
            tableView.style.display = 'block';
            viewToggleBtns[0].classList.remove('active');
            viewToggleBtns[1].classList.add('active');
        } else {
            cardsView.style.display = 'grid';
            tableView.style.display = 'none';
            viewToggleBtns[0].classList.add('active');
            viewToggleBtns[1].classList.remove('active');
        }
        localStorage.setItem('catalog_view', view);
        
        // Переинициализируем тултипы после переключения вида
        if (window.reinitChartTooltips) {
            window.reinitChartTooltips();
        }
    }

    // Применяем сохраненный вид
    switchView(savedView);

    // Обработчики кликов
    viewToggleBtns.forEach(btn => {
        btn.addEventListener('click', function() {
            const view = this.getAttribute('data-view');
            switchView(view);
        });
    });
})();
</script>

<?php
$content = ob_get_clean();
include __DIR__ . '/../../templates/base.php';
?>

