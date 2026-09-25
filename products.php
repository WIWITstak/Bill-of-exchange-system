<?php
/**
 * Страница товаров и услуг
 */

require_once __DIR__ . '/../src/bootstrap.php';

use OGAS\Services\Auth;
use OGAS\Models\Product;
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
$typeFilter = $_GET['type'] ?? null;
$categoryFilter = $_GET['category'] ?? null;
$searchQuery = $_GET['search'] ?? '';
$availabilityFilter = $_GET['availability'] ?? null;

// Получаем товары/услуги пользователя
$filters = ['user_id' => $user->getId()];

if ($typeFilter) {
    $filters['type'] = $typeFilter;
}

if ($categoryFilter) {
    $filters['category'] = $categoryFilter;
}

if ($searchQuery) {
    $filters['search'] = $searchQuery;
}

if ($availabilityFilter !== null && $availabilityFilter !== '') {
    $filters['is_available'] = $availabilityFilter === '1';
}

$products = Product::findAll($filters);
$categories = Product::getCategories();

// Статистика
$productsCount = Product::countByUserId($user->getId(), 'product');
$servicesCount = Product::countByUserId($user->getId(), 'service');
$totalCount = Product::countByUserId($user->getId());

// Дополнительная статистика
$availableCount = count(array_filter($products, fn($p) => $p->isAvailable()));
$unavailableCount = count($products) - $availableCount;

$title = 'Товары и услуги';
ob_start();
?>
<div class="dashboard">
    <div class="dashboard-header">
        <h2>Мои товары и услуги</h2>
        <div class="header-actions">
            <a href="/products/catalog.php" class="btn btn-secondary">
                <i class="fas fa-th-large"></i> Каталог
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

    <!-- Статистика -->
    <div class="catalog-info">
        <div class="catalog-stats">
            <div class="stat-item">
                <i class="fas fa-box"></i>
                <span>Товаров: <strong><?= $productsCount ?></strong></span>
            </div>
            <div class="stat-item">
                <i class="fas fa-briefcase"></i>
                <span>Услуг: <strong><?= $servicesCount ?></strong></span>
            </div>
            <div class="stat-item">
                <i class="fas fa-list"></i>
                <span>Всего: <strong><?= $totalCount ?></strong></span>
            </div>
            <?php if ($totalCount > 0): ?>
            <div class="stat-item">
                <i class="fas fa-check-circle"></i>
                <span>Доступно: <strong><?= $availableCount ?></strong></span>
            </div>
            <?php endif; ?>
        </div>
    </div>

    <!-- Фильтры -->
    <?php 
    $hasActiveFilters = !empty($searchQuery) || !empty($typeFilter) || !empty($categoryFilter) || $availabilityFilter !== null;
    ?>
    <div class="filters-wrapper" data-has-filters="<?= $hasActiveFilters ? 'true' : 'false' ?>">
        <div class="filters-toggle-header <?= !$hasActiveFilters ? 'collapsed' : '' ?>">
            <div class="filters-toggle-title">
                <i class="fas fa-filter filters-toggle-icon"></i>
                <span>Фильтры<?= $hasActiveFilters ? ' <span style="color: var(--color-primary); font-weight: 600;">(активны)</span>' : '' ?></span>
            </div>
        </div>
        <div class="search-filters-card <?= !$hasActiveFilters ? 'collapsed' : 'expanded' ?>"<?= !$hasActiveFilters ? ' style="max-height: 0; padding: 0; margin: 0; opacity: 0; overflow: hidden;"' : '' ?>>
            <form method="GET" action="/products.php" class="search-filters-form">
                <div class="filters-row">
                <div class="filter-group">
                    <label for="search">Поиск:</label>
                    <input type="text" 
                           name="search" 
                           id="search" 
                           class="filter-input" 
                           placeholder="Название или описание..."
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
                    <label for="availability">Доступность:</label>
                    <select name="availability" id="availability" class="filter-select">
                        <option value="">Все</option>
                        <option value="1" <?= $availabilityFilter === '1' ? 'selected' : '' ?>>Доступно</option>
                        <option value="0" <?= $availabilityFilter === '0' ? 'selected' : '' ?>>Недоступно</option>
                    </select>
                </div>

                <div class="filter-actions">
                    <button type="submit" class="btn btn-primary">
                        <i class="fas fa-filter"></i> Применить
                    </button>
                    <?php if ($typeFilter || $categoryFilter || $searchQuery || $availabilityFilter !== null): ?>
                        <a href="/products.php" class="btn btn-secondary">
                            <i class="fas fa-times"></i> Сбросить
                        </a>
                    <?php endif; ?>
                </div>
            </div>
        </form>
        </div>
    </div>

    <!-- Список товаров/услуг -->
    <?php if (empty($products)): ?>
        <div class="products-empty-state">
            <div class="products-empty-icon">
                <i class="fas fa-box-open"></i>
            </div>
            <div class="products-empty-content">
                <h3>У вас пока нет товаров или услуг</h3>
                <p>Добавьте свои товары или услуги, чтобы другие пользователи могли их найти в каталоге</p>
                <div class="products-empty-actions">
                    <a href="/products/create.php" class="btn btn-primary btn-large">
                        <i class="fas fa-plus"></i> Добавить первый товар
                    </a>
                    <a href="/products/create.php?type=service" class="btn btn-secondary btn-large">
                        <i class="fas fa-briefcase"></i> Добавить услугу
                    </a>
                </div>
                <div class="products-empty-hint">
                    <i class="fas fa-info-circle"></i>
                    <span>Вы можете добавлять неограниченное количество товаров и услуг</span>
                </div>
            </div>
        </div>
    <?php else: ?>
        <!-- Переключатель вида -->
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

        <!-- Карточное отображение -->
        <div class="products-grid" id="productsCardsView">
            <?php foreach ($products as $product): ?>
                <div class="product-card">
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
                            <div class="product-price">
                                <?= number_format($product->getPrice(), 2, '.', ' ') ?> ₽
                                <?php if ($product->getUnit()): ?>
                                    <span class="product-unit">/ <?= htmlspecialchars($product->getUnit()) ?></span>
                                <?php endif; ?>
                            </div>

                            <?php if ($product->getQuantity() !== null): ?>
                                <div class="product-quantity">
                                    В наличии: <?= $product->getQuantity() ?>
                                </div>
                            <?php endif; ?>
                        </div>

                        <div class="product-status-actions">
                            <div class="product-status">
                                <?php if ($product->isAvailable()): ?>
                                    <span class="status status-active">
                                        <i class="fas fa-check-circle"></i> Доступно
                                    </span>
                                <?php else: ?>
                                    <span class="status status-pending">
                                        <i class="fas fa-pause-circle"></i> Недоступно
                                    </span>
                                <?php endif; ?>
                            </div>
                            <button onclick="toggleAvailability(<?= $product->getId() ?>, <?= $product->isAvailable() ? 'true' : 'false' ?>)" 
                                    class="btn-toggle-status" 
                                    title="<?= $product->isAvailable() ? 'Сделать недоступным' : 'Сделать доступным' ?>">
                                <i class="fas fa-<?= $product->isAvailable() ? 'pause' : 'play' ?>"></i>
                            </button>
                        </div>

                        <div class="product-actions">
                            <a href="/products/edit.php?id=<?= $product->getId() ?>" class="btn btn-secondary btn-sm">
                                <i class="fas fa-edit"></i> Редактировать
                            </a>
                            <button onclick="deleteProduct(<?= $product->getId() ?>)" class="btn btn-danger btn-sm">
                                <i class="fas fa-trash"></i> Удалить
                            </button>
                        </div>
                    </div>
                </div>
            <?php endforeach; ?>
        </div>

        <!-- Табличное отображение -->
        <div class="products-table-wrapper" id="productsTableView" style="display: none;">
            <table class="products-table">
                <thead>
                    <tr>
                        <th>Название</th>
                        <th>Тип</th>
                        <th>Категория</th>
                        <th>Цена</th>
                        <th>Количество</th>
                        <th>Статус</th>
                        <th>Дата создания</th>
                        <th>Действия</th>
                    </tr>
                </thead>
                <tbody>
                    <?php foreach ($products as $product): ?>
                        <tr>
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
                                <strong class="table-price"><?= number_format($product->getPrice(), 2, '.', ' ') ?> ₽</strong>
                                <?php if ($product->getUnit()): ?>
                                    <span class="table-unit">/ <?= htmlspecialchars($product->getUnit()) ?></span>
                                <?php endif; ?>
                            </td>
                            <td>
                                <?php if ($product->getQuantity() !== null): ?>
                                    <?= $product->getQuantity() ?>
                                <?php else: ?>
                                    <span class="text-muted">∞</span>
                                <?php endif; ?>
                            </td>
                            <td>
                                <?php if ($product->isAvailable()): ?>
                                    <span class="status status-active">
                                        <i class="fas fa-check-circle"></i> Доступно
                                    </span>
                                <?php else: ?>
                                    <span class="status status-pending">
                                        <i class="fas fa-pause-circle"></i> Недоступно
                                    </span>
                                <?php endif; ?>
                            </td>
                            <td>
                                <span class="table-date">
                                    <?= date('d.m.Y', strtotime($product->getCreatedAt())) ?>
                                </span>
                            </td>
                            <td>
                                <div class="table-actions">
                                    <button onclick="toggleAvailability(<?= $product->getId() ?>, <?= $product->isAvailable() ? 'true' : 'false' ?>)" 
                                            class="btn-icon" 
                                            title="<?= $product->isAvailable() ? 'Сделать недоступным' : 'Сделать доступным' ?>">
                                        <i class="fas fa-<?= $product->isAvailable() ? 'pause' : 'play' ?>"></i>
                                    </button>
                                    <a href="/products/edit.php?id=<?= $product->getId() ?>" 
                                       class="btn-icon" 
                                       title="Редактировать">
                                        <i class="fas fa-edit"></i>
                                    </a>
                                    <button onclick="deleteProduct(<?= $product->getId() ?>)" 
                                            class="btn-icon btn-icon-danger" 
                                            title="Удалить">
                                        <i class="fas fa-trash"></i>
                                    </button>
                                </div>
                            </td>
                        </tr>
                    <?php endforeach; ?>
                </tbody>
            </table>
        </div>
    <?php endif; ?>
</div>

<script>
// Переключение вида отображения
(function() {
    const savedView = localStorage.getItem('products_view') || 'cards';
    const cardsView = document.getElementById('productsCardsView');
    const tableView = document.getElementById('productsTableView');
    const viewToggleBtns = document.querySelectorAll('.view-toggle-btn');

    function switchView(view) {
        // Проверяем существование элементов перед обращением к их свойствам
        if (!cardsView || !tableView) {
            return; // Элементы не найдены, выходим из функции
        }

        if (view === 'table') {
            cardsView.style.display = 'none';
            tableView.style.display = 'block';
            if (viewToggleBtns.length >= 2) {
                viewToggleBtns[0].classList.remove('active');
                viewToggleBtns[1].classList.add('active');
            }
        } else {
            cardsView.style.display = 'grid';
            tableView.style.display = 'none';
            if (viewToggleBtns.length >= 2) {
                viewToggleBtns[0].classList.add('active');
                viewToggleBtns[1].classList.remove('active');
            }
        }
        localStorage.setItem('products_view', view);
    }

    // Применяем сохраненный вид только если элементы существуют
    if (cardsView && tableView) {
        switchView(savedView);
    }

    // Обработчики кликов
    viewToggleBtns.forEach(btn => {
        btn.addEventListener('click', function() {
            const view = this.getAttribute('data-view');
            switchView(view);
        });
    });
})();

function deleteProduct(productId) {
    if (!confirm('Вы уверены, что хотите удалить этот товар/услугу?')) {
        return;
    }

    fetch('/products/delete.php', {
        method: 'POST',
        headers: {
            'Content-Type': 'application/x-www-form-urlencoded',
        },
        body: 'id=' + productId
    })
    .then(response => response.json())
    .then(data => {
        if (data.success) {
            window.location.reload();
        } else {
            alert('Ошибка: ' + data.message);
        }
    })
    .catch(error => {
        console.error('Error:', error);
        alert('Произошла ошибка при удалении');
    });
}

function toggleAvailability(productId, currentStatus) {
    const newStatus = currentStatus === true ? false : true;
    
    fetch('/products/toggle-availability.php', {
        method: 'POST',
        headers: {
            'Content-Type': 'application/x-www-form-urlencoded',
        },
        body: 'id=' + productId + '&is_available=' + (newStatus ? '1' : '0')
    })
    .then(response => response.json())
    .then(data => {
        if (data.success) {
            window.location.reload();
        } else {
            alert('Ошибка: ' + data.message);
        }
    })
    .catch(error => {
        console.error('Error:', error);
        alert('Произошла ошибка при изменении статуса');
    });
}
</script>

<?php
$content = ob_get_clean();
include __DIR__ . '/../templates/base.php';
?>

