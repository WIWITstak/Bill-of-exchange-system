<?php
/**
 * Страница поиска пользователей
 */

require_once __DIR__ . '/../src/bootstrap.php';

use OGAS\Services\Auth;
use OGAS\Models\User;
use OGAS\Models\Rating;

// Получаем поисковый запрос и фильтры
$searchQuery = trim($_GET['search'] ?? '');
$userTypeFilter = $_GET['user_type'] ?? null;
$statusFilter = $_GET['status'] ?? null;
$users = [];

// Выполняем поиск
if (!empty($searchQuery) && strlen($searchQuery) >= 2) {
    $allUsers = User::search($searchQuery, 100);
} elseif (empty($searchQuery)) {
    // Показываем активных пользователей по умолчанию
    $allUsers = User::getAllActive(100);
} else {
    $allUsers = [];
}

// Фильтруем пользователей по типу и статусу, исключаем системных пользователей
$users = $allUsers;
// Исключаем системных пользователей
$users = array_filter($users, function($user) {
    return !$user->isSystem();
});

if ($userTypeFilter) {
    $users = array_filter($users, function($user) use ($userTypeFilter) {
        return $user->getUserType() === $userTypeFilter;
    });
}
if ($statusFilter === 'active') {
    $users = array_filter($users, function($user) {
        return $user->isActive();
    });
} elseif ($statusFilter === 'inactive') {
    $users = array_filter($users, function($user) {
        return !$user->isActive();
    });
}
$users = array_values($users); // Переиндексируем массив

$title = 'Поиск пользователей';
ob_start();
?>
<div class="dashboard">
    <div class="dashboard-header">
        <h2>Поиск пользователей</h2>
        <a href="/dashboard.php" class="btn btn-secondary">← Назад</a>
    </div>
    
    <!-- Улучшенная форма поиска и фильтров -->
    <?php 
    $hasActiveFilters = !empty($searchQuery) || !empty($_GET['user_type']) || !empty($_GET['status']);
    ?>
    <div class="users-search-wrapper filters-wrapper" data-has-filters="<?= $hasActiveFilters ? 'true' : 'false' ?>">
        <div class="filters-toggle-header <?= !$hasActiveFilters ? 'collapsed' : '' ?>">
            <div class="filters-toggle-title">
                <i class="fas fa-filter filters-toggle-icon"></i>
                <span>Фильтры<?= $hasActiveFilters ? ' <span style="color: var(--color-primary); font-weight: 600;">(активны)</span>' : '' ?></span>
            </div>
        </div>
        <div class="search-filters-card <?= !$hasActiveFilters ? 'collapsed' : 'expanded' ?>"<?= !$hasActiveFilters ? ' style="max-height: 0; padding: 0; margin: 0; opacity: 0; overflow: hidden;"' : '' ?>>
            <form method="GET" action="" class="search-filters-form" id="searchForm">
                <div class="search-input-group">
                    <div class="search-icon">🔍</div>
                    <input type="text" 
                           id="search" 
                           name="search" 
                           class="search-input"
                           value="<?= htmlspecialchars($searchQuery) ?>"
                           placeholder="Поиск по имени или email..." 
                           autocomplete="off"
                           minlength="2">
                    <?php if (!empty($searchQuery)): ?>
                        <button type="button" class="search-clear" onclick="clearSearch()" title="Очистить">×</button>
                    <?php endif; ?>
                </div>
                
                <div class="filters-row">
                    <div class="filter-group">
                        <label for="user_type">Тип пользователя:</label>
                        <select name="user_type" id="user_type" class="filter-select" onchange="this.form.submit()">
                            <option value="">Все типы</option>
                            <option value="individual" <?= isset($_GET['user_type']) && $_GET['user_type'] === 'individual' ? 'selected' : '' ?>>
                                Физическое лицо
                            </option>
                            <option value="legal" <?= isset($_GET['user_type']) && $_GET['user_type'] === 'legal' ? 'selected' : '' ?>>
                                Юридическое лицо
                            </option>
                        </select>
                    </div>
                    
                    <div class="filter-group">
                        <label for="status">Статус:</label>
                        <select name="status" id="status" class="filter-select" onchange="this.form.submit()">
                            <option value="">Все</option>
                            <option value="active" <?= isset($_GET['status']) && $_GET['status'] === 'active' ? 'selected' : '' ?>>
                                Активные
                            </option>
                            <option value="inactive" <?= isset($_GET['status']) && $_GET['status'] === 'inactive' ? 'selected' : '' ?>>
                                Неактивные
                            </option>
                        </select>
                    </div>
                    
                    <div class="filter-actions">
                        <button type="submit" class="btn btn-primary search-submit-btn">
                            <span class="btn-icon">🔍</span>
                            Найти
                        </button>
                        <?php if (!empty($searchQuery) || !empty($_GET['user_type']) || !empty($_GET['status'])): ?>
                            <a href="/users.php" class="btn btn-secondary">Сбросить</a>
                        <?php endif; ?>
                    </div>
                </div>
            </form>
        </div>
        </div>
    </div>
    
    <!-- Результаты поиска -->
    <div class="users-results">
        <?php if (empty($users)): ?>
            <div class="users-empty">
                <div class="users-empty-icon">👤</div>
                <h3>Пользователи не найдены</h3>
                <p class="text-muted">Попробуйте изменить параметры поиска или фильтры</p>
                <a href="/users.php" class="btn btn-primary">Показать всех пользователей</a>
            </div>
        <?php else: ?>
            <div class="users-header">
                <h3>
                    <?php if (!empty($searchQuery)): ?>
                        Результаты поиска
                    <?php else: ?>
                        Пользователи системы
                    <?php endif; ?>
                </h3>
                <div class="users-count">
                    Найдено: <strong><?= count($users) ?></strong>
                </div>
            </div>

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
            <div class="users-grid" id="usersCardsView">
                <?php foreach ($users as $foundUser): ?>
                    <?php
                    $rating = Rating::findByUserId($foundUser->getId());
                    $userTypeLabel = $foundUser->getUserType() === 'legal' ? 'Юридическое лицо' : 'Физическое лицо';
                    $userTypeIcon = $foundUser->getUserType() === 'legal' ? '🏢' : '👤';
                    ?>
                    <div class="user-card-modern">
                        <div class="user-card-header">
                            <div class="user-avatar-large">
                                <?= $foundUser->getAvatarHtml('medium') ?>
                            </div>
                            <div class="user-card-title">
                                <h4>
                                    <a href="/user.php?id=<?= $foundUser->getId() ?>" class="user-name-link">
                                        <?= htmlspecialchars($foundUser->getFullName()) ?>
                                    </a>
                                </h4>
                                <div class="user-type-badge">
                                    <span class="user-type-icon"><?= $userTypeIcon ?></span>
                                    <span><?= $userTypeLabel ?></span>
                                </div>
                            </div>
                        </div>
                        
                        <div class="user-card-body">
                            <div class="stat-item">
                                <i class="fas fa-star"></i>
                                <span>Рейтинг "Око": <strong><?= number_format($rating->getTotalRating(), 2) ?></strong></span>
                            </div>
                            
                            <div class="stat-item">
                                <i class="fas fa-coins"></i>
                                <span>Дубли: <strong><?= number_format($rating->getDoubles(), 2) ?></strong></span>
                            </div>
                            
                            <div class="user-status-badge">
                                <?php if ($foundUser->isActive()): ?>
                                    <span class="status-badge status-active-modern">
                                        <span class="status-dot"></span>
                                        Активен
                                    </span>
                                <?php else: ?>
                                    <span class="status-badge status-inactive-modern">
                                        <span class="status-dot"></span>
                                        Не активирован
                                    </span>
                                <?php endif; ?>
                            </div>
                        </div>
                        
                        <div class="user-card-footer">
                            <a href="/user.php?id=<?= $foundUser->getId() ?>" class="btn-view-profile">
                                <span>Просмотр профиля</span>
                                <span class="btn-arrow">→</span>
                            </a>
                        </div>
                    </div>
                <?php endforeach; ?>
            </div>

            <!-- Табличное отображение -->
            <div class="users-table-wrapper" id="usersTableView" style="display: none;">
                <table class="users-table">
                    <thead>
                        <tr>
                            <th>Пользователь</th>
                            <th>Email</th>
                            <th>Тип</th>
                            <th>Рейтинг "Око"</th>
                            <th>Дубли</th>
                            <th>Статус</th>
                            <th>Дата регистрации</th>
                            <th>Действия</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php foreach ($users as $foundUser): 
                            $rating = Rating::findByUserId($foundUser->getId());
                            $userTypeLabel = $foundUser->getUserType() === 'legal' ? 'Юридическое лицо' : 'Физическое лицо';
                            $userTypeIcon = $foundUser->getUserType() === 'legal' ? '🏢' : '👤';
                        ?>
                            <tr>
                                <td>
                                    <div class="table-user-name">
                                        <div class="table-user-avatar">
                                            <?= $foundUser->getAvatarHtml('table') ?>
                                        </div>
                                        <div class="table-user-info">
                                            <strong>
                                                <a href="/user.php?id=<?= $foundUser->getId() ?>" class="user-name-link">
                                                    <?= htmlspecialchars($foundUser->getFullName()) ?>
                                                </a>
                                            </strong>
                                        </div>
                                    </div>
                                </td>
                                <td>
                                    <span class="table-user-email"><?= htmlspecialchars($foundUser->getEmail()) ?></span>
                                </td>
                                <td>
                                    <div class="table-user-type">
                                        <span class="user-type-icon"><?= $userTypeIcon ?></span>
                                        <span><?= $userTypeLabel ?></span>
                                    </div>
                                </td>
                                <td>
                                    <div class="table-rating">
                                        <i class="fas fa-star"></i>
                                        <strong><?= number_format($rating->getTotalRating(), 2) ?></strong>
                                    </div>
                                </td>
                                <td>
                                    <div class="table-doubles">
                                        <i class="fas fa-coins"></i>
                                        <strong><?= number_format($rating->getDoubles(), 2) ?></strong>
                                    </div>
                                </td>
                                <td>
                                    <?php if ($foundUser->isActive()): ?>
                                        <span class="status-badge status-active-modern">
                                            <span class="status-dot"></span>
                                            Активен
                                        </span>
                                    <?php else: ?>
                                        <span class="status-badge status-inactive-modern">
                                            <span class="status-dot"></span>
                                            Не активирован
                                        </span>
                                    <?php endif; ?>
                                </td>
                                <td>
                                    <span class="table-date">
                                        <?= date('d.m.Y', strtotime($foundUser->getCreatedAt())) ?>
                                    </span>
                                </td>
                                <td>
                                    <div class="table-actions">
                                        <a href="/user.php?id=<?= $foundUser->getId() ?>" 
                                           class="btn-icon" 
                                           title="Просмотр профиля">
                                            <i class="fas fa-eye"></i>
                                        </a>
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
    function clearSearch() {
        document.getElementById('search').value = '';
        document.getElementById('searchForm').submit();
    }
    
    // Переключение вида отображения пользователей
    (function() {
        const savedView = localStorage.getItem('users_view') || 'cards';
        const cardsView = document.getElementById('usersCardsView');
        const tableView = document.getElementById('usersTableView');
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
            localStorage.setItem('users_view', view);
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
    
    // Автопоиск при вводе (опционально, можно задержку добавить)
    // document.getElementById('search')?.addEventListener('input', function() {
    //     if (this.value.length >= 2) {
    //         // Можно добавить AJAX поиск здесь
    //     }
    // });
    </script>
</div>

<?php
$content = ob_get_clean();
include __DIR__ . '/../templates/base.php';

