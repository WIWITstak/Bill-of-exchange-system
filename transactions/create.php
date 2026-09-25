<?php
/**
 * Страница создания транзакции
 */

require_once __DIR__ . '/../../src/bootstrap.php';

use OGAS\Services\Auth;
use OGAS\Models\User;
use OGAS\Models\Transaction;
use OGAS\Models\Product;
use OGAS\Models\Rating;
use OGAS\Models\Company;
use OGAS\Models\Category;
use OGAS\Services\TransactionService;
use OGAS\Core\Session;
use OGAS\Database;

Auth::requireAuth();
$user = Auth::user();

if (!$user) {
    Auth::logout();
    header('Location: /login.php');
    exit;
}

// Проверяем, что аккаунт активирован (для создания обычных транзакций)
// Транзакция активации создаётся автоматически при регистрации, поэтому не требуется проверка
if (!$user->isActive()) {
    Session::flash('error', 'Для создания транзакций необходимо активировать аккаунт. Перейдите в раздел активации.');
    header('Location: /activate.php');
    exit;
}

$error = '';
$success = '';

// Проверяем, передан ли buyer_id или seller_id в GET (для создания транзакции с конкретным пользователем)
// seller_id используется в каталоге - это ID владельца товара, который будет buyer
$preselectedBuyerId = isset($_GET['buyer_id']) ? (int)$_GET['buyer_id'] : 0;
if (!$preselectedBuyerId && isset($_GET['seller_id'])) {
    // Если передан seller_id (из каталога), это означает что этот пользователь будет buyer
    $preselectedBuyerId = (int)$_GET['seller_id'];
}

$preselectedBuyer = null;
$preselectedProductId = isset($_GET['product_id']) ? (int)$_GET['product_id'] : 0;
$preselectedProduct = null;

if ($preselectedBuyerId > 0) {
    $preselectedBuyer = User::findById($preselectedBuyerId);
    if (!$preselectedBuyer || $preselectedBuyer->getId() === $user->getId()) {
        $preselectedBuyerId = 0;
        $preselectedBuyer = null;
    }
}

// Если передан product_id, загружаем информацию о товаре
if ($preselectedProductId > 0) {
    $preselectedProduct = Product::findById($preselectedProductId);
    // Проверяем что товар существует и принадлежит не текущему пользователю
    if (!$preselectedProduct) {
        $preselectedProductId = 0;
        $preselectedProduct = null;
    } else {
            // Получаем user_id из данных товара
        $productUserId = $preselectedProduct->getUserId();
        if ($productUserId && $productUserId === $user->getId()) {
            // Товар принадлежит текущему пользователю, не используем его
            $preselectedProductId = 0;
            $preselectedProduct = null;
        }
    }
}

// Обработка создания транзакции
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $buyerId = (int)($_POST['buyer_id'] ?? 0);
    $description = trim($_POST['description'] ?? '');
    $category = trim($_POST['category'] ?? '');
    $amount = !empty($_POST['amount']) ? (float)$_POST['amount'] : 0;
    $maturityDays = !empty($_POST['maturity_days']) ? (int)$_POST['maturity_days'] : 0;
    $transactionType = trim($_POST['transaction_type'] ?? 'barter');
    
    // Валидация
    if ($buyerId <= 0) {
        $error = 'Выберите покупателя';
    } elseif ($buyerId === $user->getId()) {
        $error = 'Нельзя создать транзакцию с самим собой';
    } elseif (empty($description) || mb_strlen($description) < 10) {
        $error = 'Описание транзакции обязательно (минимум 10 символов)';
    } elseif ($amount < 0) {
        $error = 'Сумма не может быть отрицательной';
    } elseif ($maturityDays > 0 && $maturityDays < 1) {
        $error = 'Срок погашения должен быть не менее 1 дня';
    } elseif ($maturityDays > 365) {
        $error = 'Срок погашения не может превышать 365 дней';
    } elseif ($maturityDays < 0) {
        $error = 'Срок погашения не может быть отрицательным';
    } elseif (!in_array($transactionType, ['barter', 'guarantee', 'community', 'mixed'])) {
        $error = 'Некорректный тип транзакции';
    } else {
        try {
            // Проверяем, существует ли покупатель
            $buyer = User::findById($buyerId);
            if (!$buyer) {
                $error = 'Покупатель не найден';
            } else {
                // Создаём транзакцию (всегда в статусе pending - на рассмотрении)
                $transaction = TransactionService::create([
                    'seller_id' => $user->getId(),
                    'buyer_id' => $buyerId,
                    'description' => $description,
                    'category' => $category ?: null,
                    'transaction_type' => in_array($transactionType, ['barter', 'guarantee', 'community', 'mixed']) ? $transactionType : 'barter'
                ]);
                
                Session::flash('success', 'Транзакция создана! Теперь оба участника должны подтвердить сделку в чате.');
                header('Location: /transactions/chat.php?id=' . $transaction->getId());
                exit;
            }
        } catch (\Exception $e) {
            $error = 'Ошибка при создании транзакции: ' . $e->getMessage();
        }
    }
}

// Поиск пользователей для формы
$searchQuery = $_GET['search'] ?? '';
$users = [];
if ($searchQuery && strlen($searchQuery) >= 2) {
    $users = User::search($searchQuery, 10);
} elseif (empty($searchQuery)) {
    // Показываем последних активных пользователей
    $users = User::getAllActive(10);
}

// Получаем недавние контакты (пользователи, с которыми были транзакции)
$recentContacts = [];
$recentTransactions = Transaction::findByUser($user->getId());
$contactIds = [];
$contactTransactionCounts = []; // Подсчет количества транзакций с каждым контактом

foreach ($recentTransactions as $trans) {
    $otherId = $trans->getSellerId() === $user->getId() ? $trans->getBuyerId() : $trans->getSellerId();
    
    // Подсчитываем количество транзакций
    if ($otherId !== $user->getId()) {
        $contactTransactionCounts[$otherId] = ($contactTransactionCounts[$otherId] ?? 0) + 1;
    }
    
    if ($otherId !== $user->getId() && !in_array($otherId, $contactIds)) {
        $contactIds[] = $otherId;
        $contactUser = User::findById($otherId);
        if ($contactUser && $contactUser->isActive()) {
            $recentContacts[] = $contactUser;
        }
        if (count($recentContacts) >= 8) break; // Увеличили до 8 контактов
    }
}

// Получаем товары/услуги пользователя для быстрого выбора
$userProducts = Product::findByUserId($user->getId(), null, true); // Только доступные

// Получаем популярные категории для быстрого выбора
$popularCategories = Category::getAllActive(0); // Все активные категории
$popularCategories = array_slice($popularCategories, 0, 12); // Первые 12

$title = 'Создать транзакцию';
ob_start();
?>
<div class="dashboard">
    <div class="dashboard-header">
        <h2>Создать транзакцию</h2>
        <a href="/transactions.php" class="btn btn-secondary">← Назад к списку</a>
    </div>
    
    <?php if ($error): ?>
        <div class="alert alert-error"><?= htmlspecialchars($error) ?></div>
    <?php endif; ?>
    
    <?php if ($success): ?>
        <div class="alert alert-success"><?= htmlspecialchars($success) ?></div>
    <?php endif; ?>
    
    <div class="transaction-create">
        <form method="POST" action="" class="transaction-form" id="transactionForm">
            <?= csrf_field() ?>
            <!-- Шаги создания транзакции -->
            <div class="transaction-steps">
                <div class="step active" data-step="1">
                    <div class="step-number">1</div>
                    <div class="step-label">Выбор покупателя</div>
                </div>
                <div class="step" data-step="2">
                    <div class="step-number">2</div>
                    <div class="step-label">Описание сделки</div>
                </div>
                <div class="step" data-step="3">
                    <div class="step-number">3</div>
                    <div class="step-label">Условия</div>
                </div>
            </div>
            
            <div class="form-section" id="step1">
                <h3>👤 Выберите покупателя</h3>
                
                <div class="form-group">
                    <label for="search_user">Поиск покупателя:</label>
                    
                    <!-- Компактные фильтры поиска -->
                    <div class="user-search-filters-wrapper filters-wrapper" data-has-filters="false">
                        <div class="filters-toggle-header collapsed" id="buyer-filters-toggle">
                            <div class="filters-toggle-title">
                                <span class="filters-toggle-icon">⚙️</span>
                                <span>Фильтры поиска</span>
                                <span id="active-filters-count" class="active-filters-count"></span>
                            </div>
                            <button type="button" class="btn-filter-reset-small" id="btn-reset-filters" title="Сбросить фильтры">↺</button>
                        </div>
                        
                        <div class="user-search-filters collapsed" id="buyer-filters-content" style="max-height: 0; padding: 0; margin: 0; opacity: 0; overflow: hidden;">
                            <div class="search-filters-row">
                                <div class="filter-group-inline">
                                    <label for="filter_user_type" class="filter-label">Тип пользователя:</label>
                                    <select id="filter_user_type" class="filter-select-small">
                                        <option value="">Все типы</option>
                                        <option value="individual">👤 Физ. лицо</option>
                                        <option value="legal">🏢 Юр. лицо</option>
                                    </select>
                                </div>
                                <div class="filter-group-inline">
                                    <label for="filter_min_rating" class="filter-label">Минимальный рейтинг:</label>
                                    <select id="filter_min_rating" class="filter-select-small">
                                        <option value="0">⭐ Любой</option>
                                        <option value="3">⭐ 3.0+</option>
                                        <option value="4">⭐ 4.0+</option>
                                        <option value="4.5">⭐ 4.5+</option>
                                    </select>
                                </div>
                                <div class="filter-group-inline">
                                    <label for="filter_sort" class="filter-label">Сортировать по:</label>
                                    <select id="filter_sort" class="filter-select-small">
                                        <option value="name">📝 Имени</option>
                                        <option value="rating">⭐ Рейтингу</option>
                                        <option value="transactions">📊 Количеству сделок</option>
                                    </select>
                                </div>
                                <div class="filter-group-inline">
                                    <label class="filter-checkbox-label">
                                        <input type="checkbox" id="filter_only_active" checked>
                                        <span>✓ Только активные</span>
                                    </label>
                                </div>
                            </div>
                        </div>
                    </div>
                    
                    <div class="user-search-wrapper">
                        <div class="user-search-input-wrapper">
                            <input type="text" id="search_user" name="search" 
                                   placeholder="Введите имя, email или ID пользователя..." 
                                   autocomplete="off">
                            <div class="search-icon">🔍</div>
                            <button type="button" class="btn-show-all-users" id="btn-show-all" title="Показать всех пользователей">
                                <span class="btn-icon">📋</span>
                                <span class="btn-text">Все</span>
                            </button>
                            <div id="user-search-results" class="user-search-results">
                                <div class="search-placeholder">
                                    <div class="search-placeholder-icon">👥</div>
                                    <div class="search-placeholder-text">Начните вводить имя или нажмите "Все" для просмотра пользователей</div>
                                </div>
                            </div>
                        </div>
                        <div id="search-results-info" class="search-results-info" style="display: none;">
                            <span id="search-results-count">0</span> пользователей
                        </div>
                    </div>
                    
                    <!-- Недавние контакты -->
                    <?php if (!empty($recentContacts)): ?>
                        <div class="recent-contacts">
                            <div class="recent-contacts-header">
                                <div class="recent-contacts-label">
                                    <span class="label-icon">🕒</span>
                                    <span>Недавние контакты</span>
                                    <span class="contacts-count">(<?= count($recentContacts) ?>)</span>
                                </div>
                            </div>
                            <div class="recent-contacts-list">
                                <?php foreach ($recentContacts as $contact): 
                                    $contactRating = Rating::findByUserId($contact->getId());
                                    $ratingValue = $contactRating ? $contactRating->getTotalRating() : 0;
                                    $company = $contact->getUserType() === 'legal' ? Company::findByUserId($contact->getId()) : null;
                                    $transactionCount = $contactTransactionCounts[$contact->getId()] ?? 0;
                                ?>
                                    <div class="contact-card" 
                                         data-user-id="<?= $contact->getId() ?>"
                                         data-user-name="<?= htmlspecialchars($contact->getFullName()) ?>"
                                         data-user-email="<?= htmlspecialchars($contact->getEmail()) ?>"
                                         data-user-type="<?= $contact->getUserType() ?>"
                                         data-user-rating="<?= $ratingValue ?>"
                                         data-user-initials="<?= htmlspecialchars($contact->getInitials()) ?>"
                                         data-user-avatar="<?= htmlspecialchars($contact->getAvatarUrl()) ?>"
                                         data-transaction-count="<?= $transactionCount ?>"
                                         <?php if ($company): ?>data-company-name="<?= htmlspecialchars($company->getName()) ?>"<?php endif; ?>>
                                        <div class="contact-avatar">
                                            <?= $contact->getAvatarHtml('small') ?>
                                        </div>
                                        <div class="contact-info">
                                            <div class="contact-name"><?= htmlspecialchars($contact->getFullName()) ?></div>
                                            <?php if ($company): ?>
                                                <div class="contact-company"><?= htmlspecialchars($company->getName()) ?></div>
                                            <?php endif; ?>
                                            <div class="contact-meta">
                                                <div class="contact-rating">
                                                    <?php if ($ratingValue > 0): ?>
                                                        <span class="rating-value" title="Рейтинг">⭐ <?= number_format($ratingValue, 2) ?></span>
                                                    <?php else: ?>
                                                        <span class="rating-no">Нет рейтинга</span>
                                                    <?php endif; ?>
                                                    <span class="contact-type"><?= $contact->getUserType() === 'legal' ? '🏢 Юр. лицо' : '👤 Физ. лицо' ?></span>
                                                </div>
                                                <?php if ($transactionCount > 0): ?>
                                                    <div class="contact-transactions" title="Количество сделок с вами">
                                                        📊 <?= $transactionCount ?> <?= $transactionCount === 1 ? 'сделка' : ($transactionCount < 5 ? 'сделки' : 'сделок') ?>
                                                    </div>
                                                <?php endif; ?>
                                            </div>
                                        </div>
                                        <div class="contact-actions">
                                            <a href="/user.php?id=<?= $contact->getId() ?>" class="btn-view-profile" target="_blank" title="Открыть профиль">
                                                👁️
                                            </a>
                                            <button type="button" class="btn-select-contact">Выбрать</button>
                                        </div>
                                    </div>
                                <?php endforeach; ?>
                            </div>
                        </div>
                    <?php endif; ?>
                    
                    <!-- Выбранный покупатель -->
                    <div id="selected-buyer" class="selected-buyer" style="display: none;">
                        <div class="selected-buyer-card">
                            <div class="selected-buyer-avatar" id="selected-buyer-avatar"></div>
                            <div class="selected-buyer-info">
                                <div class="selected-buyer-name" id="selected-buyer-name"></div>
                                <div class="selected-buyer-details" id="selected-buyer-details"></div>
                                <!-- История транзакций с этим покупателем -->
                                <div id="buyer-transaction-history" class="buyer-transaction-history" style="display: none;">
                                    <div class="history-label">История сделок:</div>
                                    <div id="buyer-transaction-list" class="buyer-transaction-list"></div>
                                </div>
                            </div>
                            <button type="button" class="btn-remove-buyer" id="btn-remove-buyer">✕</button>
                        </div>
                        <input type="hidden" name="buyer_id" id="buyer_id" value="<?= $preselectedBuyer ? $preselectedBuyer->getId() : '' ?>">
                    </div>
                    
                    <?php if ($preselectedBuyer): 
                        $preselectedRating = Rating::findByUserId($preselectedBuyer->getId());
                        $preselectedRatingValue = $preselectedRating ? $preselectedRating->getTotalRating() : 0;
                        
                        // Подсчитываем количество транзакций с этим пользователем
                        $preselectedTransactionCount = 0;
                        if ($preselectedBuyerId > 0) {
                            $db = Database::getConnection();
                            $stmt = $db->prepare("
                                SELECT COUNT(*) as cnt 
                                FROM transactions 
                                WHERE (seller_id = ? AND buyer_id = ?) OR (buyer_id = ? AND seller_id = ?)
                            ");
                            $stmt->execute([$user->getId(), $preselectedBuyerId, $user->getId(), $preselectedBuyerId]);
                            $result = $stmt->fetch(\PDO::FETCH_ASSOC);
                            $preselectedTransactionCount = (int)($result['cnt'] ?? 0);
                        }
                        
                        $preselectedCompany = $preselectedBuyer->getUserType() === 'legal' ? Company::findByUserId($preselectedBuyer->getId()) : null;
                    ?>
                        <script>
                            document.addEventListener('DOMContentLoaded', function() {
                                // Автоматически выбираем покупателя
                                selectBuyer({
                                    id: <?= $preselectedBuyer->getId() ?>,
                                    full_name: <?= json_encode($preselectedBuyer->getFullName()) ?>,
                                    email: <?= json_encode($preselectedBuyer->getEmail()) ?>,
                                    user_type: <?= json_encode($preselectedBuyer->getUserType()) ?>,
                                    avatar_url: <?= json_encode($preselectedBuyer->getAvatarUrl()) ?>,
                                    initials: <?= json_encode($preselectedBuyer->getInitials()) ?>,
                                    rating: <?= round($preselectedRatingValue, 2) ?>,
                                    transaction_count: <?= $preselectedTransactionCount ?>,
                                    company_name: <?= $preselectedCompany ? json_encode($preselectedCompany->getName()) : 'null' ?>
                                });
                                
                                // Автоматически переходим на следующий шаг если покупатель выбран
                                setTimeout(function() {
                                    // Проверяем что selectBuyer был вызван и selectedBuyer установлен
                                    if (typeof selectedBuyer !== 'undefined' && selectedBuyer && typeof goToStep === 'function') {
                                        // Убеждаемся что buyer_id установлен
                                        const buyerIdInput = document.getElementById('buyer_id');
                                        if (buyerIdInput && buyerIdInput.value) {
                                            // Прокручиваем к началу формы
                                            window.scrollTo({ top: 0, behavior: 'smooth' });
                                            // Переходим на шаг 2
                                            goToStep(2);
                                        }
                                    }
                                }, 400);
                            });
                        </script>
                    <?php endif; ?>
                    
                    <?php if ($preselectedProduct): ?>
                        <script>
                            document.addEventListener('DOMContentLoaded', function() {
                                // Автоматически заполняем данные товара
                                const productId = <?= $preselectedProduct->getId() ?>;
                                const productName = <?= json_encode($preselectedProduct->getName()) ?>;
                                const productDescription = <?= json_encode($preselectedProduct->getDescription()) ?>;
                                const productCategory = <?= json_encode($preselectedProduct->getCategory()) ?>;
                                
                                // Заполняем описание
                                const descriptionInput = document.getElementById('description');
                                if (descriptionInput) {
                                    let description = productName;
                                    if (productDescription && productDescription.trim()) {
                                        description += '. ' + productDescription;
                                    }
                                    descriptionInput.value = description;
                                    descriptionInput.dispatchEvent(new Event('input'));
                                }
                                
                                // Заполняем категорию
                                const categoryInput = document.getElementById('category');
                                if (categoryInput && productCategory) {
                                    categoryInput.value = productCategory;
                                    categoryInput.dispatchEvent(new Event('input'));
                                }
                                
                                // Обновляем счетчик символов
                                const descriptionCounter = document.getElementById('description-counter');
                                if (descriptionCounter && descriptionInput) {
                                    descriptionCounter.textContent = descriptionInput.value.length;
                                }
                            });
                        </script>
                    <?php endif; ?>
                </div>
                
                <div class="form-actions-step">
                    <button type="button" class="btn btn-primary btn-next-step" onclick="goToStep(2)" disabled>Далее →</button>
                </div>
            </div>
            
            <div class="form-section" id="step2" style="display: none;">
                <h3>📦 Описание товара/услуги</h3>
                
                <!-- Выбор из своих товаров/услуг -->
                <?php if (!empty($userProducts)): ?>
                    <div class="form-group">
                        <label>Или выберите из своих товаров/услуг:</label>
                        <div class="products-quick-select">
                            <?php foreach (array_slice($userProducts, 0, 5) as $product): ?>
                                <div class="product-quick-item" data-product-id="<?= $product->getId() ?>">
                                    <div class="product-quick-name"><?= htmlspecialchars($product->getName()) ?></div>
                                    <div class="product-quick-desc"><?= htmlspecialchars(mb_substr($product->getDescription(), 0, 100)) ?><?= mb_strlen($product->getDescription()) > 100 ? '...' : '' ?></div>
                                    <div class="product-quick-meta">
                                        <span class="product-category"><?= htmlspecialchars($product->getCategory() ?: 'Без категории') ?></span>
                                        <?php if ($product->getPrice() > 0): ?>
                                            <span class="product-price"><?= number_format($product->getPrice(), 2) ?> ₽</span>
                                        <?php endif; ?>
                                    </div>
                                    <button type="button" class="btn-use-product">Использовать</button>
                                </div>
                            <?php endforeach; ?>
                        </div>
                    </div>
                <?php endif; ?>
                
                <div class="form-group">
                    <label for="description">Описание товара/услуги: <span class="required">*</span></label>
                    <textarea id="description" name="description" rows="5" required 
                              placeholder="Опишите подробно, что вы продаёте. Укажите характеристики, количество, условия поставки и другие важные детали..."
                              maxlength="2000"><?= htmlspecialchars($_POST['description'] ?? '') ?></textarea>
                    <div class="char-counter"><span id="description-counter">0</span> / 2000 символов</div>
                </div>
                
                <div class="form-group">
                    <label for="category">Категория:</label>
                    
                    <!-- Популярные категории для быстрого выбора -->
                    <?php if (!empty($popularCategories)): ?>
                        <div class="popular-categories">
                            <div class="popular-categories-label">Популярные категории:</div>
                            <div class="categories-grid" id="categories-grid">
                                <?php foreach ($popularCategories as $cat): ?>
                                    <div class="category-card" 
                                         data-category-name="<?= htmlspecialchars($cat->getName()) ?>"
                                         data-category-icon="<?= htmlspecialchars($cat->getIcon() ?? '📦') ?>"
                                         onclick="selectCategoryCard(this)">
                                        <div class="category-card-icon"><?= htmlspecialchars($cat->getIcon() ?? '📦') ?></div>
                                        <div class="category-card-name"><?= htmlspecialchars($cat->getName()) ?></div>
                                        <?php if ($cat->getDescription()): ?>
                                            <div class="category-card-desc"><?= htmlspecialchars(mb_substr($cat->getDescription(), 0, 50)) ?><?= mb_strlen($cat->getDescription()) > 50 ? '...' : '' ?></div>
                                        <?php endif; ?>
                                    </div>
                                <?php endforeach; ?>
                            </div>
                        </div>
                    <?php endif; ?>
                    
                    <!-- Поле для поиска или ввода своей категории -->
                    <div class="category-input-wrapper" style="margin-top: 15px;">
                        <input type="text" id="category" name="category" 
                               value="<?= htmlspecialchars($_POST['category'] ?? '') ?>"
                               placeholder="Или начните вводить название категории..."
                               autocomplete="off">
                        <div id="category-suggestions" class="category-suggestions"></div>
                    </div>
                    <small class="form-hint">Выберите категорию из списка или введите свою</small>
                </div>
                
                <div class="form-actions-step">
                    <button type="button" class="btn btn-secondary" onclick="goToStep(1)">← Назад</button>
                    <button type="button" class="btn btn-primary btn-next-step" onclick="goToStep(3)">Далее →</button>
                </div>
            </div>
            
            <div class="form-section" id="step3" style="display: none;">
                <h3>⚙️ Условия сделки</h3>
                
                <div class="info-box info-box-primary">
                    <div class="info-box-icon">ℹ️</div>
                    <div class="info-box-content">
                        <strong>Важно:</strong> После создания транзакции оба участника должны подтвердить сделку в чате. Только после этого можно будет создать вексель.
                    </div>
                </div>
                
                <!-- Тип транзакции -->
                <div class="form-group">
                    <label for="transaction_type">Тип транзакции:</label>
                    <div class="transaction-type-selector">
                        <label class="transaction-type-option">
                            <input type="radio" name="transaction_type" value="barter" id="type_barter" checked>
                            <div class="type-option-content">
                                <div class="type-option-icon">🔄</div>
                                <div class="type-option-info">
                                    <div class="type-option-name">Бартер</div>
                                    <div class="type-option-desc">Обмен товарами или услугами</div>
                                </div>
                            </div>
                        </label>
                        <label class="transaction-type-option">
                            <input type="radio" name="transaction_type" value="guarantee" id="type_guarantee">
                            <div class="type-option-content">
                                <div class="type-option-icon">🛡️</div>
                                <div class="type-option-info">
                                    <div class="type-option-name">Гарантия</div>
                                    <div class="type-option-desc">С поручительством</div>
                                </div>
                            </div>
                        </label>
                        <label class="transaction-type-option">
                            <input type="radio" name="transaction_type" value="community" id="type_community">
                            <div class="type-option-content">
                                <div class="type-option-icon">🏘️</div>
                                <div class="type-option-info">
                                    <div class="type-option-name">Община</div>
                                    <div class="type-option-desc">Через общину</div>
                                </div>
                            </div>
                        </label>
                        <label class="transaction-type-option">
                            <input type="radio" name="transaction_type" value="mixed" id="type_mixed">
                            <div class="type-option-content">
                                <div class="type-option-icon">🔀</div>
                                <div class="type-option-info">
                                    <div class="type-option-name">Смешанная</div>
                                    <div class="type-option-desc">Комбинация типов</div>
                                </div>
                            </div>
                        </label>
                    </div>
                </div>
                
                <div class="form-row">
                    <div class="form-group form-group-half">
                        <label for="amount">Предполагаемая сумма векселя (₽):</label>
                        <input type="number" id="amount" name="amount" 
                               step="0.01" min="0" 
                               value="<?= htmlspecialchars($_POST['amount'] ?? '') ?>"
                               placeholder="Не указано">
                        <small class="form-hint">Информационное поле. Финальная сумма устанавливается в чате</small>
                    </div>
                    
                    <div class="form-group form-group-half">
                        <label for="maturity_days">Срок погашения (дней):</label>
                        <input type="number" id="maturity_days" name="maturity_days" 
                               min="1" max="365" 
                               value="<?= htmlspecialchars($_POST['maturity_days'] ?? '30') ?>"
                               placeholder="30">
                        <small class="form-hint">Информационное поле. Финальный срок устанавливается в чате</small>
                    </div>
                </div>
                
                <!-- Предварительный просмотр -->
                <div id="transaction-preview" class="transaction-preview" style="display: none;">
                    <h4>📋 Предварительный просмотр</h4>
                    <div class="preview-content">
                        <div class="preview-item">
                            <span class="preview-label">Покупатель:</span>
                            <span id="preview-buyer" class="preview-value">-</span>
                        </div>
                        <div class="preview-item">
                            <span class="preview-label">Тип:</span>
                            <span id="preview-type" class="preview-value">Бартер</span>
                        </div>
                        <div class="preview-item">
                            <span class="preview-label">Категория:</span>
                            <span id="preview-category" class="preview-value">-</span>
                        </div>
                        <div class="preview-item preview-description">
                            <span class="preview-label">Описание:</span>
                            <div id="preview-description" class="preview-value">-</div>
                        </div>
                    </div>
                </div>
                
                <div class="form-actions-step">
                    <button type="button" class="btn btn-secondary" onclick="goToStep(2)">← Назад</button>
                    <button type="submit" class="btn btn-primary btn-create-transaction">
                        <span class="btn-icon">✨</span>
                        Создать транзакцию
                    </button>
                    <a href="/transactions.php" class="btn btn-link">Отмена</a>
                </div>
            </div>
        </form>
    </div>
</div>

<script src="/js/transaction-create.js"></script>
<script>
// Инициализация после загрузки страницы
document.addEventListener('DOMContentLoaded', function() {
    initTransactionCreate();
});
</script>

<?php
$content = ob_get_clean();
include __DIR__ . '/../../templates/base.php';

