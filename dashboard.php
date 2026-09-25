<?php
/**
 * Рабочий кабинет пользователя (базовая версия)
 */

require_once __DIR__ . '/../src/bootstrap.php';

use OGAS\Services\Auth;
use OGAS\Models\User;
use OGAS\Models\Company;
use OGAS\Models\Rating;
use OGAS\Models\Transaction;
use OGAS\Models\Bill;
use OGAS\Services\BillService;
use OGAS\Services\TransactionService;

// Требуем авторизацию
Auth::requireAuth();

$user = Auth::user();

// Проверяем, что пользователь найден
if (!$user) {
    // Если пользователь не найден (удалён из БД), выходим
    Auth::logout();
    header('Location: /login.php?error=user_not_found');
    exit;
}

// Получаем статистику
$rating = Rating::findByUserId($user->getId());
$billStats = BillService::getStatistics($user->getId());
$transactionStats = TransactionService::getStatistics($user->getId());

// Получаем статистику по чатам (непрочитанные сообщения)
use OGAS\Models\Message;
$unreadChatsCount = 0;
$db = \OGAS\Database::getConnection();
// Непрочитанные в транзакционных чатах
$stmt = $db->prepare("
    SELECT COUNT(DISTINCT m.transaction_id) as count
    FROM messages m
    INNER JOIN transactions t ON t.id = m.transaction_id
    WHERE m.is_read = 0 
    AND m.user_id != ?
    AND (t.seller_id = ? OR t.buyer_id = ?)
    AND m.transaction_id IS NOT NULL
");
$stmt->execute([$user->getId(), $user->getId(), $user->getId()]);
$unreadTransactionChats = (int)$stmt->fetchColumn();

// Непрочитанные в чатах общины
$stmt = $db->prepare("
    SELECT COUNT(DISTINCT m.community_request_id) as count
    FROM messages m
    INNER JOIN community_requests cr ON cr.id = m.community_request_id
    WHERE m.is_read = 0 
    AND m.user_id != ?
    AND m.community_request_id IS NOT NULL
    AND (cr.user_id = ? OR EXISTS (
        SELECT 1 FROM community_guarantors cg 
        WHERE cg.request_id = cr.id AND cg.guarantor_id = ? AND cg.status = 'active'
    ))
");
$stmt->execute([$user->getId(), $user->getId(), $user->getId()]);
$unreadCommunityChats = (int)$stmt->fetchColumn();

$unreadChatsCount = $unreadTransactionChats + $unreadCommunityChats;

// Получаем статистику по общине (активные запросы, где пользователь участвует)
use OGAS\Models\CommunityRequest;
$activeCommunityRequests = 0;
$stmt = $db->prepare("
    SELECT COUNT(DISTINCT cr.id) FROM community_requests cr
    WHERE cr.status = 'open'
    AND (cr.user_id = ? OR EXISTS (
        SELECT 1 FROM community_guarantors cg 
        WHERE cg.request_id = cr.id AND cg.guarantor_id = ? AND cg.status = 'active'
    ))
");
$stmt->execute([$user->getId(), $user->getId()]);
$activeCommunityRequests = (int)$stmt->fetchColumn();

// Выпущенные вексели
$issuedBills = Bill::findByIssuer($user->getId(), 'active');
$issuedBillsCount = count($issuedBills);
$issuedBillsTotal = array_sum(array_map(fn($b) => $b->getNominal(), $issuedBills));

// Просроченные вексели (активные и просроченные)
$overdueBillsCount = 0;
$stmt = $db->prepare("
    SELECT COUNT(*) FROM bills 
    WHERE (issuer_id = ? OR holder_id = ?)
    AND status = 'active'
    AND maturity_date < CURDATE()
");
$stmt->execute([$user->getId(), $user->getId()]);
$overdueBillsCount = (int)$stmt->fetchColumn();

// Депозитарий - договоры с векселями
$depositoryContractsCount = 0;
$stmt = $db->prepare("
    SELECT COUNT(DISTINCT t.id) FROM transactions t
    WHERE EXISTS (
        SELECT 1 FROM bills b 
        WHERE (
            (b.issuer_id = t.seller_id AND b.holder_id = t.buyer_id) OR
            (b.issuer_id = t.buyer_id AND b.holder_id = t.seller_id)
        )
    )
    AND (t.seller_id = ? OR t.buyer_id = ?)
");
$stmt->execute([$user->getId(), $user->getId()]);
$depositoryContractsCount = (int)$stmt->fetchColumn();

// Получаем информацию о предприятии (для юрлиц)
$company = null;
if ($user->getUserType() === 'legal') {
    $company = Company::findByUserId($user->getId());
}

// Проверяем уведомления (напоминания о погашении, просрочки)
use OGAS\Services\NotificationService;
use OGAS\Models\Notification;
NotificationService::checkMaturityReminders($user->getId());
NotificationService::checkOverdueBills($user->getId());

// Получаем количество непрочитанных уведомлений
$unreadNotificationsCount = Notification::getUnreadCount($user->getId());

$title = 'Рабочий кабинет';
ob_start();
?>
<div class="dashboard">
    <div class="dashboard-header">
        <h2>Рабочий кабинет</h2>
        <div class="user-info">
            <span>Добро пожаловать, <?= htmlspecialchars($user->getFullName()) ?>!</span>
            <a href="/logout.php" class="btn btn-secondary">Выйти</a>
        </div>
    </div>
    
    <!-- Быстрые действия -->
    <div class="dashboard-quick-actions-wrapper">
        <div class="dashboard-quick-actions-header">
            <h3><i class="fas fa-bolt"></i> Быстрые действия</h3>
            <button class="btn-quick-actions-settings" onclick="quickActionsSettings.toggle()" title="Настроить быстрые действия">
                <i class="fas fa-cog"></i> Настроить
            </button>
        </div>
        
        <div class="dashboard-quick-actions" id="quickActions">
            <!-- Быстрые действия будут добавлены через JavaScript -->
        </div>
        
        <!-- Панель настройки быстрых действий -->
        <div class="quick-actions-settings-panel" id="quickActionsSettings" style="display: none;">
            <div class="quick-actions-settings-header">
                <h4>Настройка быстрых действий</h4>
                <button class="btn-close-settings" onclick="quickActionsSettings.toggle()">✕</button>
            </div>
            <div class="quick-actions-settings-content">
                <p style="margin-bottom: 15px; color: #666; font-size: 0.9em;">Перетащите действия для изменения порядка. Включите/отключите действия и выберите, какие показывать в хедере.</p>
                <div class="quick-actions-settings-list" id="quickActionsSettingsList">
                    <!-- Список всех доступных действий -->
                </div>
                <div class="quick-actions-settings-actions">
                    <button class="btn btn-primary" onclick="quickActionsSettings.save()">Сохранить</button>
                    <button class="btn btn-secondary" onclick="quickActionsSettings.reset()">Сбросить по умолчанию</button>
                </div>
            </div>
        </div>
    </div>
    
    <div class="dashboard-content">
        <!-- Информация о пользователе -->
        <div class="info-card dashboard-user-info">
            <div class="dashboard-user-header">
                <div class="dashboard-user-avatar">
                    <?= $user->getAvatarHtml('dashboard') ?>
                </div>
                <div class="dashboard-user-main">
                    <h3><?= htmlspecialchars($user->getFullName()) ?></h3>
                    <div class="dashboard-user-meta">
                        <span class="user-type-badge <?= $user->getUserType() === 'legal' ? 'user-type-legal' : 'user-type-individual' ?>">
                            <?= $user->getUserType() === 'legal' ? '🏢 Юридическое лицо' : '👤 Физическое лицо' ?>
                        </span>
                        <?php if ($user->isActive()): ?>
                            <span class="status-badge status-active">✓ Активирован</span>
                        <?php else: ?>
                            <span class="status-badge status-inactive">⏸ Не активирован</span>
                        <?php endif; ?>
                    </div>
                </div>
            </div>
            
            <div class="dashboard-user-details">
                <div class="info-row">
                    <span class="info-label">Email:</span>
                    <span class="info-value"><?= htmlspecialchars($user->getEmail()) ?></span>
                </div>
                
                <?php if ($user->getUserType() === 'legal' && $company): ?>
                    <div class="info-divider"></div>
                    <h4 style="margin: 8px 0 5px 0; color: #667eea;">🏢 Информация о предприятии</h4>
                    <div class="info-row">
                        <span class="info-label">Название:</span>
                        <span class="info-value"><?= htmlspecialchars($company->getName()) ?></span>
                    </div>
                    <?php if ($company->getAddress()): ?>
                        <div class="info-row">
                            <span class="info-label">Адрес:</span>
                            <span class="info-value"><?= htmlspecialchars($company->getAddress()) ?></span>
                        </div>
                    <?php endif; ?>
                    <?php if ($company->getOkvedCode()): ?>
                        <div class="info-row">
                            <span class="info-label">Код ОКВЭД:</span>
                            <span class="info-value"><?= htmlspecialchars($company->getOkvedCode()) ?></span>
                        </div>
                    <?php endif; ?>
                    <?php if ($company->getEmployeeCount() > 0): ?>
                        <div class="info-row">
                            <span class="info-label">Количество сотрудников:</span>
                            <span class="info-value"><?= $company->getEmployeeCount() ?></span>
                        </div>
                    <?php endif; ?>
                <?php elseif ($user->getUserType() === 'individual'): ?>
                    <div class="info-divider"></div>
                    <h4 style="margin: 8px 0 5px 0; color: #667eea;">👤 Персональная информация</h4>
                    <div class="info-row">
                        <span class="info-label">ФИО:</span>
                        <span class="info-value"><?= htmlspecialchars($user->getFullName()) ?></span>
                    </div>
                    <div class="info-row">
                        <span class="info-label">Тип пользователя:</span>
                        <span class="info-value">Физическое лицо</span>
                    </div>
                <?php endif; ?>
                
                <?php if (!$user->isActive()): ?>
                    <div class="info-divider"></div>
                    <div class="info-note">
                        <small><i class="fas fa-exclamation-triangle"></i> Для активации аккаунта необходимо заключить сделку с ОГАС</small>
                    </div>
                <?php endif; ?>
            </div>
            
            <div class="dashboard-user-actions">
                <a href="/profile/edit.php" class="dashboard-action-btn dashboard-action-btn-secondary">
                    <i class="fas fa-edit"></i>
                    <span>Редактировать профиль</span>
                </a>
                <?php if ($user->getUserType() === 'legal'): ?>
                    <a href="/company.php" class="dashboard-action-btn dashboard-action-btn-secondary">
                        <i class="fas fa-building"></i>
                        <span>Информация о предприятии</span>
                    </a>
                <?php endif; ?>
                <a href="/subscriptions.php" class="dashboard-action-btn dashboard-action-btn-secondary">
                    <i class="fas fa-credit-card"></i>
                    <span>Подписка</span>
                </a>
            </div>
        </div>
        
        <!-- Статистика в карточках -->
        <div class="dashboard-stats-wrapper">
            <div class="dashboard-stats-header">
                <h3><i class="fas fa-chart-bar"></i> Статистика</h3>
                <button class="btn-stats-settings" onclick="statsSettings.toggle()" title="Настроить карточки статистики">
                    <i class="fas fa-cog"></i> Настроить
                </button>
            </div>
            
            <div class="dashboard-stats-carousel-wrapper">
                <button class="stats-carousel-nav stats-carousel-prev" id="statsCarouselPrev" aria-label="Предыдущая страница">
                    <i class="fas fa-chevron-left"></i>
                </button>
                <div class="dashboard-stats-grid" id="statsGrid">
                    <!-- Карточки будут добавлены через JavaScript -->
                </div>
                <button class="stats-carousel-nav stats-carousel-next" id="statsCarouselNext" aria-label="Следующая страница">
                    <i class="fas fa-chevron-right"></i>
                </button>
            </div>
            <div class="stats-carousel-indicators" id="statsCarouselIndicators">
                <!-- Индикаторы страниц будут добавлены через JavaScript -->
            </div>
            
            <!-- Панель настройки карточек статистики -->
            <div class="stats-settings-panel" id="statsSettings" style="display: none;">
                <div class="stats-settings-header">
                    <h4>Настройка карточек статистики</h4>
                    <button class="btn-close-settings" onclick="statsSettings.toggle()">✕</button>
                </div>
                <div class="stats-settings-content">
                    <p style="margin-bottom: 15px; color: #666; font-size: 0.9em;">Перетащите карточки для изменения порядка. Отключите ненужные карточки.</p>
                    <div class="stats-settings-list" id="statsSettingsList">
                        <!-- Список всех доступных карточек -->
                    </div>
                    <div class="stats-settings-actions">
                        <button class="btn btn-primary" onclick="statsSettings.save()">Сохранить</button>
                        <button class="btn btn-secondary" onclick="statsSettings.reset()">Сбросить по умолчанию</button>
                    </div>
                </div>
            </div>
        </div>
        
        <!-- Данные для карточек (скрытые, для использования в JavaScript) -->
        <script type="application/json" id="statsData">
        <?php
        $statsData = [
            'rating' => [
                'label' => 'Рейтинг "Око"',
                'value' => number_format($rating->getTotalRating(), 2),
                'sublabel' => 'Дубли: ' . number_format($rating->getDoubles(), 2),
                'link' => '/rating.php',
                'linkText' => 'Подробнее →'
            ],
            'bills' => [
                'label' => 'Вексели',
                'value' => (string)$billStats['held_count'],
                'sublabel' => 'На счету: ' . number_format($billStats['held_total'], 0, '.', ' ') . ' ₽',
                'link' => '/bills.php',
                'linkText' => 'Управление →'
            ],
            'transactions' => [
                'label' => 'Транзакции',
                'value' => (string)$transactionStats['total'],
                'sublabel' => 'Активных: ' . $transactionStats['active'],
                'link' => '/transactions.php',
                'linkText' => 'Все транзакции →'
            ],
            'notifications' => [
                'label' => 'Уведомления',
                'value' => $unreadNotificationsCount > 0 ? (string)$unreadNotificationsCount : '0',
                'sublabel' => $unreadNotificationsCount > 0 ? 'Непрочитанных' : 'Все прочитаны',
                'link' => '/notifications.php',
                'linkText' => 'Открыть →'
            ],
            'chats' => [
                'label' => 'Чаты',
                'value' => $unreadChatsCount > 0 ? (string)$unreadChatsCount : '0',
                'sublabel' => $unreadChatsCount > 0 ? 'Непрочитанных чатов' : 'Все прочитаны',
                'link' => '/chats.php',
                'linkText' => 'Открыть →'
            ],
            'community' => [
                'label' => 'Община',
                'value' => (string)$activeCommunityRequests,
                'sublabel' => $activeCommunityRequests > 0 ? 'Активных запросов' : 'Нет активных',
                'link' => '/community.php',
                'linkText' => 'Открыть →'
            ],
            'issued_bills' => [
                'label' => 'Выпущенные вексели',
                'value' => (string)$issuedBillsCount,
                'sublabel' => 'На сумму: ' . number_format($issuedBillsTotal, 0, '.', ' ') . ' ₽',
                'link' => '/bills.php?type=issued',
                'linkText' => 'Управление →'
            ],
            'overdue_bills' => [
                'label' => 'Просроченные вексели',
                'value' => (string)$overdueBillsCount,
                'sublabel' => $overdueBillsCount > 0 ? 'Требуют внимания' : 'Нет просроченных',
                'link' => '/bills.php?status=overdue',
                'linkText' => 'Просмотреть →'
            ],
            'depository' => [
                'label' => 'Депозитарий',
                'value' => (string)$depositoryContractsCount,
                'sublabel' => $depositoryContractsCount > 0 ? 'Договоров с векселями' : 'Нет договоров',
                'link' => '/depository.php',
                'linkText' => 'Открыть →'
            ]
        ];
        echo json_encode($statsData, JSON_UNESCAPED_UNICODE | JSON_HEX_QUOT | JSON_HEX_AMP);
        ?>
        </script>
    </div>

<script>
// Конфигурация быстрых действий
const quickActionsConfig = {
    // Все доступные действия
    available: [
        { id: 'transactions', icon: '🔄', title: 'Транзакции', url: '/transactions.php', color: '#667eea' },
        { id: 'create_transaction', icon: '➕', title: 'Создать транзакцию', url: '/transactions/create.php', color: '#48bb78' },
        { id: 'bills', icon: '💰', title: 'Вексели', url: '/bills.php', color: '#f59e0b' },
        { id: 'chats', icon: '💬', title: 'Чаты', url: '/chats.php', color: '#8b5cf6' },
        { id: 'depository', icon: '🏦', title: 'Депозитарий', url: '/depository.php', color: '#ec4899' },
        { id: 'subscriptions', icon: '💳', title: 'Подписка', url: '/subscriptions.php', color: '#06b6d4' },
        { id: 'rating', icon: '⭐', title: 'Рейтинг', url: '/rating.php', color: '#f97316' },
        { id: 'community', icon: '👥', title: 'Община', url: '/community.php', color: '#10b981' },
        { id: 'users', icon: '👤', title: 'Пользователи', url: '/users.php', color: '#3b82f6' },
        { id: 'products', icon: '📦', title: 'Товары и услуги', url: '/products.php', color: '#8b5cf6' },
        { id: 'categories', icon: '📁', title: 'Категории', url: '/categories.php', color: '#6366f1' },
        { id: 'notifications', icon: '🔔', title: 'Уведомления', url: '/notifications.php', color: '#ef4444', badge: <?= $unreadNotificationsCount ?> },
        { id: 'profile', icon: '👤', title: 'Мой профиль', url: '/user.php?id=<?= $user->getId() ?>', color: '#14b8a6' },
        <?php if ($user->getUserType() === 'legal'): ?>
        { id: 'company', icon: '🏢', title: 'Предприятие', url: '/company.php', color: '#6366f1' },
        <?php endif; ?>
    ],
    
    // Получить сохраненную конфигурацию
    getSaved: function() {
        const saved = localStorage.getItem('dashboard_quick_actions');
        if (saved) {
            try {
                return JSON.parse(saved);
            } catch (e) {
                return null;
            }
        }
        return null;
    },
    
    // Сохранить конфигурацию
    save: function(order) {
        localStorage.setItem('dashboard_quick_actions', JSON.stringify(order));
    },
    
    // Получить порядок действий (по умолчанию или сохраненный)
    getOrder: function() {
        const saved = this.getSaved();
        if (saved && saved.order && Array.isArray(saved.order)) {
            return saved.order;
        }
        
        // Порядок по умолчанию (первые 8)
        const defaultOrder = [
            'transactions', 'create_transaction', 'bills', 'chats',
            'rating', 'community', 'notifications', 'users'
        ];
        
        const isLegal = <?= $user->getUserType() === 'legal' ? 'true' : 'false' ?>;
        if (!isLegal) {
            return defaultOrder.filter(id => id !== 'company');
        }
        return defaultOrder;
    },
    
    // Получить действие по ID
    getById: function(id) {
        return this.available.find(action => action.id === id);
    }
};

// Управление быстрыми действиями
const quickActionsManager = {
    container: null,
    
    init: function() {
        this.container = document.getElementById('quickActions');
        if (!this.container) return;
        
        this.render();
    },
    
    render: function() {
        if (!this.container) {
            console.error('Quick actions container not found');
            return;
        }
        
        if (typeof quickActionsConfig === 'undefined') {
            console.error('quickActionsConfig is not defined');
            return;
        }
        
        let order = quickActionsConfig.getOrder();
        if (!order || order.length === 0) {
            console.warn('No quick actions order found, using default');
            const defaultOrder = quickActionsConfig.available ? quickActionsConfig.available.map(a => a.id) : [];
            if (defaultOrder.length === 0) {
                console.error('No actions available');
                return;
            }
            order = defaultOrder;
        }
        
        const savedConfig = quickActionsConfig.getSaved();
        const enabledActions = savedConfig && savedConfig.enabled ? savedConfig.enabled : {};
        
        this.container.innerHTML = '';
        
        order.forEach((actionId, index) => {
            const action = quickActionsConfig.getById(actionId);
            if (!action) {
                console.warn('Action not found:', actionId);
                return;
            }
            
            // Проверяем, включено ли действие (по умолчанию включено, если не указано иное)
            const isEnabled = !enabledActions.hasOwnProperty(actionId) || enabledActions[actionId] !== false;
            if (!isEnabled) {
                return;
            }
            
            const actionEl = document.createElement('a');
            actionEl.href = action.url;
            actionEl.className = 'quick-action-card';
            actionEl.dataset.actionId = actionId;
            actionEl.draggable = true;
            
            const iconMap = {
                '🔄': '<i class="fas fa-exchange-alt"></i>',
                '➕': '<i class="fas fa-plus"></i>',
                '💰': '<i class="fas fa-money-bill-wave"></i>',
                '💬': '<i class="fas fa-comments"></i>',
                '🏦': '<i class="fas fa-vault"></i>',
                '💳': '<i class="fas fa-credit-card"></i>',
                '⭐': '<i class="fas fa-star"></i>',
                '👥': '<i class="fas fa-users"></i>',
                '👤': '<i class="fas fa-user"></i>',
                '📁': '<i class="fas fa-folder"></i>',
                '🔔': '<i class="fas fa-bell"></i>',
                '🏢': '<i class="fas fa-building"></i>'
            };
            const iconHtml = iconMap[action.icon] || `<span style="font-size: 1.8em;">${action.icon}</span>`;
            
            actionEl.innerHTML = `
                <div class="quick-action-icon" style="background: linear-gradient(135deg, ${action.color}22 0%, ${action.color}44 100%);">
                    ${iconHtml}
                </div>
                <div class="quick-action-title">${action.title}</div>
                ${action.badge > 0 ? `<span class="quick-action-badge">${action.badge}</span>` : ''}
            `;
            
            // Drag & Drop
            actionEl.addEventListener('dragstart', (e) => {
                e.dataTransfer.setData('text/plain', actionId);
                e.dataTransfer.effectAllowed = 'move';
                actionEl.classList.add('dragging');
            });
            
            actionEl.addEventListener('dragend', () => {
                actionEl.classList.remove('dragging');
            });
            
            actionEl.addEventListener('dragover', (e) => {
                e.preventDefault();
                e.dataTransfer.dropEffect = 'move';
                const afterElement = this.getDragAfterElement(this.container, e.clientX);
                const dragging = document.querySelector('.dragging');
                if (afterElement == null) {
                    this.container.appendChild(dragging);
                } else {
                    this.container.insertBefore(dragging, afterElement);
                }
            });
            
            actionEl.addEventListener('drop', (e) => {
                e.preventDefault();
            });
            
            this.container.appendChild(actionEl);
        });
        
        // Если нет сохраненных настроек, сохраняем текущий порядок после небольшой задержки
        // чтобы элементы успели отрендериться
        if (!savedConfig && this.container.children.length > 0) {
            setTimeout(() => {
                this.saveOrder();
            }, 100);
        }
    },
    
    getDragAfterElement: function(container, x) {
        const draggableElements = [...container.querySelectorAll('.quick-action-card:not(.dragging)')];
        
        return draggableElements.reduce((closest, child) => {
            const box = child.getBoundingClientRect();
            const offset = x - box.left - box.width / 2;
            
            if (offset < 0 && offset > closest.offset) {
                return { offset: offset, element: child };
            } else {
                return closest;
            }
        }, { offset: Number.NEGATIVE_INFINITY }).element;
    },
    
    saveOrder: function() {
        const cards = Array.from(this.container.querySelectorAll('.quick-action-card'));
        const order = cards.map(card => card.dataset.actionId);
        
        const savedConfig = quickActionsConfig.getSaved();
        let showInHeader = savedConfig ? savedConfig.showInHeader : null;
        
        // Если showInHeader еще не инициализирован, устанавливаем для первых 3 действий
        if (!showInHeader || Object.keys(showInHeader).length === 0) {
            showInHeader = {};
            order.slice(0, 3).forEach(actionId => {
                showInHeader[actionId] = true;
            });
        }
        
        quickActionsConfig.save({
            order: order,
            enabled: savedConfig && savedConfig.enabled ? savedConfig.enabled : {},
            showInHeader: showInHeader
        });
        
        // Отправляем событие для синхронизации хедера
        window.dispatchEvent(new CustomEvent('quickActionsUpdated'));
    }
};

// Настройки быстрых действий
const quickActionsSettings = {
    panel: null,
    list: null,
    
    init: function() {
        this.panel = document.getElementById('quickActionsSettings');
        this.list = document.getElementById('quickActionsSettingsList');
        this.renderSettings();
    },
    
    toggle: function() {
        if (!this.panel) return;
        const isVisible = this.panel.style.display !== 'none';
        this.panel.style.display = isVisible ? 'none' : 'block';
        if (!isVisible) {
            this.renderSettings();
        }
    },
    
    renderSettings: function() {
        if (!this.list) return;
        
        const order = quickActionsConfig.getOrder();
        const savedConfig = quickActionsConfig.getSaved();
        const enabledActions = savedConfig && savedConfig.enabled ? savedConfig.enabled : {};
        
        this.list.innerHTML = '';
        
        const enabledIds = new Set(order);
        
        // Сначала добавляем действия в порядке из настроек
        const showInHeader = savedConfig && savedConfig.showInHeader ? savedConfig.showInHeader : {};
        
        order.forEach((actionId) => {
            const action = quickActionsConfig.getById(actionId);
            if (!action) return;
            
            const item = document.createElement('div');
            item.className = 'quick-action-settings-item';
            item.dataset.actionId = actionId;
            item.draggable = true;
            
            const isEnabled = enabledActions.hasOwnProperty(actionId) ? enabledActions[actionId] : true;
            const isShownInHeader = showInHeader.hasOwnProperty(actionId) ? showInHeader[actionId] : false;
            
            const settingsIconMap = {
                '🔄': '<i class="fas fa-exchange-alt"></i>',
                '➕': '<i class="fas fa-plus"></i>',
                '💰': '<i class="fas fa-money-bill-wave"></i>',
                '💬': '<i class="fas fa-comments"></i>',
                '🏦': '<i class="fas fa-vault"></i>',
                '💳': '<i class="fas fa-credit-card"></i>',
                '⭐': '<i class="fas fa-star"></i>',
                '👥': '<i class="fas fa-users"></i>',
                '👤': '<i class="fas fa-user"></i>',
                '📁': '<i class="fas fa-folder"></i>',
                '🔔': '<i class="fas fa-bell"></i>',
                '🏢': '<i class="fas fa-building"></i>'
            };
            const settingsIconHtml = settingsIconMap[action.icon] || action.icon;
            
            item.innerHTML = `
                <div class="quick-action-settings-handle"><i class="fas fa-grip-vertical"></i></div>
                <div class="quick-action-settings-icon">${settingsIconHtml}</div>
                <div class="quick-action-settings-title">${action.title}</div>
                <div class="quick-action-settings-controls">
                    <label class="quick-action-settings-toggle-label">
                        <input type="checkbox" class="toggle-enabled" ${isEnabled ? 'checked' : ''} onchange="quickActionsSettings.toggleAction('${actionId}', this.checked)">
                        <span class="toggle-slider"></span>
                        <span class="toggle-label-text">Быстрые действия</span>
                    </label>
                    <label class="quick-action-settings-toggle-label">
                        <input type="checkbox" class="toggle-header" ${isShownInHeader ? 'checked' : ''} onchange="quickActionsSettings.toggleHeaderAction('${actionId}', this.checked)">
                        <span class="toggle-slider"></span>
                        <span class="toggle-label-text">Хедер</span>
                    </label>
                </div>
            `;
            
            // Drag & Drop для настроек
            item.addEventListener('dragstart', (e) => {
                e.dataTransfer.setData('text/plain', actionId);
                e.dataTransfer.effectAllowed = 'move';
                item.classList.add('dragging');
            });
            
            item.addEventListener('dragend', () => {
                item.classList.remove('dragging');
            });
            
            item.addEventListener('dragover', (e) => {
                e.preventDefault();
                e.dataTransfer.dropEffect = 'move';
                const afterElement = this.getDragAfterElement(this.list, e.clientY);
                const dragging = document.querySelector('.quick-action-settings-item.dragging');
                if (dragging) {
                    if (afterElement == null) {
                        this.list.appendChild(dragging);
                    } else {
                        this.list.insertBefore(dragging, afterElement);
                    }
                }
            });
            
            this.list.appendChild(item);
        });
        
        // Добавляем отключенные действия
        const allActions = quickActionsConfig.available;
        
        allActions.forEach(action => {
            if (!enabledIds.has(action.id)) {
                const isEnabled = enabledActions.hasOwnProperty(action.id) ? enabledActions[action.id] : false;
                const isShownInHeader = showInHeader.hasOwnProperty(action.id) ? showInHeader[action.id] : false;
                
                const item = document.createElement('div');
                item.className = 'quick-action-settings-item';
                item.dataset.actionId = action.id;
                
                const settingsIconMap = {
                    '🔄': '<i class="fas fa-exchange-alt"></i>',
                    '➕': '<i class="fas fa-plus"></i>',
                    '💰': '<i class="fas fa-money-bill-wave"></i>',
                    '💬': '<i class="fas fa-comments"></i>',
                    '🏦': '<i class="fas fa-vault"></i>',
                    '💳': '<i class="fas fa-credit-card"></i>',
                    '⭐': '<i class="fas fa-star"></i>',
                    '👥': '<i class="fas fa-users"></i>',
                    '👤': '<i class="fas fa-user"></i>',
                    '📁': '<i class="fas fa-folder"></i>',
                    '🔔': '<i class="fas fa-bell"></i>',
                    '🏢': '<i class="fas fa-building"></i>'
                };
                const settingsIconHtml = settingsIconMap[action.icon] || action.icon;
                
                item.innerHTML = `
                    <div class="quick-action-settings-handle" style="opacity: 0.3;">☰</div>
                    <div class="quick-action-settings-icon">${settingsIconHtml}</div>
                    <div class="quick-action-settings-title">${action.title}</div>
                    <div class="quick-action-settings-controls">
                        <label class="quick-action-settings-toggle-label">
                            <input type="checkbox" class="toggle-enabled" ${isEnabled ? 'checked' : ''} onchange="quickActionsSettings.toggleAction('${action.id}', this.checked)">
                            <span class="toggle-slider"></span>
                            <span class="toggle-label-text">Быстрые действия</span>
                        </label>
                        <label class="quick-action-settings-toggle-label">
                            <input type="checkbox" class="toggle-header" ${isShownInHeader ? 'checked' : ''} onchange="quickActionsSettings.toggleHeaderAction('${action.id}', this.checked)">
                            <span class="toggle-slider"></span>
                            <span class="toggle-label-text">Хедер</span>
                        </label>
                    </div>
                `;
                
                this.list.appendChild(item);
            }
        });
    },
    
    getDragAfterElement: function(container, y) {
        const draggableElements = [...container.querySelectorAll('.quick-action-settings-item:not(.dragging)')];
        
        return draggableElements.reduce((closest, child) => {
            const box = child.getBoundingClientRect();
            const offset = y - box.top - box.height / 2;
            
            if (offset < 0 && offset > closest.offset) {
                return { offset: offset, element: child };
            } else {
                return closest;
            }
        }, { offset: Number.NEGATIVE_INFINITY }).element;
    },
    
    toggleAction: function(actionId, enabled) {
        const savedConfig = quickActionsConfig.getSaved();
        let config;
        
        if (!savedConfig) {
            const order = quickActionsConfig.getOrder();
            config = {
                order: order,
                enabled: {},
                showInHeader: {}
            };
        } else {
            config = savedConfig;
        }
        
        // Инициализируем объекты, если их нет (обратная совместимость)
        if (!config.enabled) {
            config.enabled = {};
        }
        if (!config.showInHeader) {
            config.showInHeader = {};
        }
        
        config.enabled[actionId] = enabled;
        
        // Если действие включено и его нет в порядке, добавляем в конец
        if (enabled && !config.order.includes(actionId)) {
            config.order.push(actionId);
        } else if (!enabled && config.order.includes(actionId)) {
            // Удаляем из порядка, если отключено
            config.order = config.order.filter(id => id !== actionId);
            // Также удаляем из showInHeader, если отключено
            if (config.showInHeader[actionId]) {
                config.showInHeader[actionId] = false;
            }
        }
        
        quickActionsConfig.save(config);
        quickActionsManager.render();
        
        // Отправляем событие для синхронизации хедера
        window.dispatchEvent(new CustomEvent('quickActionsUpdated'));
    },
    
    toggleHeaderAction: function(actionId, showInHeader) {
        const savedConfig = quickActionsConfig.getSaved();
        let config;
        
        if (!savedConfig) {
            const order = quickActionsConfig.getOrder();
            config = {
                order: order,
                enabled: {},
                showInHeader: {}
            };
        } else {
            config = savedConfig;
        }
        
        // Инициализируем объекты, если их нет (обратная совместимость)
        if (!config.enabled) {
            config.enabled = {};
        }
        if (!config.showInHeader) {
            config.showInHeader = {};
        }
        
        config.showInHeader[actionId] = showInHeader;
        
        quickActionsConfig.save(config);
        
        // Отправляем событие для синхронизации хедера
        window.dispatchEvent(new CustomEvent('quickActionsUpdated'));
    },
    
    save: function() {
        // Сохраняем новый порядок из настроек
        const items = Array.from(this.list.querySelectorAll('.quick-action-settings-item'));
        const order = [];
        const enabled = {};
        const showInHeader = {};
        
        items.forEach(item => {
            const actionId = item.dataset.actionId;
            const enabledCheckbox = item.querySelector('input.toggle-enabled');
            const headerCheckbox = item.querySelector('input.toggle-header');
            
            const isEnabled = enabledCheckbox ? enabledCheckbox.checked : false;
            const isShownInHeader = headerCheckbox ? headerCheckbox.checked : false;
            
            enabled[actionId] = isEnabled;
            showInHeader[actionId] = isShownInHeader;
            
            if (isEnabled) {
                order.push(actionId);
            }
        });
        
        quickActionsConfig.save({ order, enabled, showInHeader });
        quickActionsManager.render();
        this.toggle();
        
        // Отправляем событие для синхронизации хедера
        window.dispatchEvent(new CustomEvent('quickActionsUpdated'));
    },
    
    reset: function() {
        if (confirm('Сбросить все настройки быстрых действий?')) {
            localStorage.removeItem('dashboard_quick_actions');
            quickActionsManager.render();
            this.renderSettings();
            
            // Отправляем событие для синхронизации хедера
            window.dispatchEvent(new CustomEvent('quickActionsUpdated'));
        }
    }
};

// Конфигурация карточек статистики
const statsCardsConfig = {
    // Все доступные карточки
    available: [
        { id: 'rating', icon: '⭐', title: 'Рейтинг "Око"', cardClass: 'stat-card-rating' },
        { id: 'bills', icon: '💰', title: 'Вексели', cardClass: 'stat-card-bills' },
        { id: 'transactions', icon: '🔄', title: 'Транзакции', cardClass: 'stat-card-transactions' },
        { id: 'notifications', icon: '🔔', title: 'Уведомления', cardClass: 'stat-card-notifications' },
        { id: 'chats', icon: '💬', title: 'Чаты', cardClass: 'stat-card-chats' },
        { id: 'community', icon: '👥', title: 'Община', cardClass: 'stat-card-community' },
        { id: 'issued_bills', icon: '📄', title: 'Выпущенные вексели', cardClass: 'stat-card-issued-bills' },
        { id: 'overdue_bills', icon: '⚠️', title: 'Просроченные вексели', cardClass: 'stat-card-overdue-bills' },
        { id: 'depository', icon: '🏦', title: 'Депозитарий', cardClass: 'stat-card-depository' }
    ],
    
    // Получить сохраненную конфигурацию
    getSaved: function() {
        const saved = localStorage.getItem('dashboard_stats_cards');
        if (saved) {
            try {
                return JSON.parse(saved);
            } catch (e) {
                return null;
            }
        }
        return null;
    },
    
    // Сохранить конфигурацию
    save: function(config) {
        localStorage.setItem('dashboard_stats_cards', JSON.stringify(config));
    },
    
    // Получить порядок карточек (по умолчанию или сохраненный)
    getOrder: function() {
        const saved = this.getSaved();
        if (saved && saved.order && Array.isArray(saved.order)) {
            return saved.order;
        }
        
        // Порядок по умолчанию (основные карточки)
        return ['rating', 'bills', 'transactions', 'notifications', 'chats', 'community'];
    },
    
    // Получить карточку по ID
    getById: function(id) {
        return this.available.find(card => card.id === id);
    }
};

// Управление карточками статистики
const statsCardsManager = {
    container: null,
    indicators: null,
    data: null,
    currentPage: 0,
    cardsPerPage: 4,
    allCards: [],
    pages: [],
    
    init: function() {
        this.container = document.getElementById('statsGrid');
        this.indicators = document.getElementById('statsCarouselIndicators');
        if (!this.container) {
            console.error('Stats container not found');
            return;
        }
        
        // Загружаем данные из JSON
        const dataEl = document.getElementById('statsData');
        if (dataEl) {
            try {
                const dataText = dataEl.textContent.trim();
                if (dataText) {
                    this.data = JSON.parse(dataText);
                } else {
                    console.warn('Stats data is empty');
                    this.data = {};
                }
            } catch (e) {
                console.error('Error parsing stats data:', e);
                this.data = {};
            }
        } else {
            console.warn('Stats data element not found');
            this.data = {};
        }
        
        this.render();
        this.initNavigation();
    },
    
    render: function() {
        if (!this.container) {
            console.error('Stats container not found');
            return;
        }
        
        if (typeof statsCardsConfig === 'undefined') {
            console.error('statsCardsConfig is not defined');
            return;
        }
        
        if (!this.data || typeof this.data !== 'object') {
            console.warn('Stats data not loaded, waiting...');
            setTimeout(() => {
                if (this.data && typeof this.data === 'object') {
                    this.render();
                } else {
                    console.error('Stats data still not available');
                }
            }, 100);
            return;
        }
        
        const order = statsCardsConfig.getOrder();
        if (!order || order.length === 0) {
            console.warn('No stats cards order found');
            return;
        }
        
        const savedConfig = statsCardsConfig.getSaved();
        const enabledCards = savedConfig && savedConfig.enabled ? savedConfig.enabled : null;
        
        // Собираем все включенные карточки
        this.allCards = [];
        
        order.forEach((cardId) => {
            const card = statsCardsConfig.getById(cardId);
            if (!card) {
                console.warn('Card config not found for:', cardId);
                return;
            }
            
            // Проверяем, включена ли карточка
            if (enabledCards && enabledCards.hasOwnProperty(cardId) && !enabledCards[cardId]) {
                return;
            }
            
            const cardData = this.data && this.data[cardId] ? this.data[cardId] : null;
            if (!cardData) {
                console.warn('Card data not found for:', cardId);
                return;
            }
            
            this.allCards.push({ cardId, card, cardData });
        });
        
        // Разбиваем на страницы
        this.pages = [];
        for (let i = 0; i < this.allCards.length; i += this.cardsPerPage) {
            this.pages.push(this.allCards.slice(i, i + this.cardsPerPage));
        }
        
        // Рендерим текущую страницу
        if (this.allCards.length > 0) {
            this.renderPage();
            this.renderIndicators();
            
            // Если нет сохраненных настроек, сохраняем текущий порядок
            if (!savedConfig) {
                setTimeout(() => {
                    this.saveOrder();
                }, 100);
            }
        } else {
            console.warn('No cards to display');
        }
    },
    
    createCardElement: function(cardId, card, cardData) {
        const cardEl = document.createElement('div');
        cardEl.className = `dashboard-stat-card ${card.cardClass}`;
        cardEl.dataset.cardId = cardId;
        cardEl.draggable = true;
        
        const statIconMap = {
            '⭐': '<i class="fas fa-star"></i>',
            '💰': '<i class="fas fa-money-bill-wave"></i>',
            '🔄': '<i class="fas fa-exchange-alt"></i>',
            '🔔': '<i class="fas fa-bell"></i>',
            '💬': '<i class="fas fa-comments"></i>',
            '👥': '<i class="fas fa-users"></i>',
            '📄': '<i class="fas fa-file-invoice"></i>',
            '⚠️': '<i class="fas fa-exclamation-triangle"></i>',
            '🏦': '<i class="fas fa-vault"></i>'
        };
        const statIconHtml = statIconMap[card.icon] || card.icon;
        
        cardEl.innerHTML = `
            <div class="stat-card-icon">${statIconHtml}</div>
            <div class="stat-card-content">
                <div class="stat-card-label">${cardData.label}</div>
                <div class="stat-card-value">${cardData.value}</div>
                <div class="stat-card-sublabel">${cardData.sublabel}</div>
                <a href="${cardData.link}" class="stat-card-link">${cardData.linkText}</a>
            </div>
        `;
        
        // Drag & Drop
        cardEl.addEventListener('dragstart', (e) => {
            e.dataTransfer.setData('text/plain', cardId);
            e.dataTransfer.effectAllowed = 'move';
            cardEl.classList.add('dragging');
        });
        
        cardEl.addEventListener('dragover', (e) => {
            e.preventDefault();
            e.dataTransfer.dropEffect = 'move';
            const afterElement = this.getDragAfterElement(this.container, e.clientX);
            const dragging = document.querySelector('.dashboard-stat-card.dragging');
            if (dragging) {
                if (afterElement == null) {
                    this.container.appendChild(dragging);
                } else {
                    this.container.insertBefore(dragging, afterElement);
                }
            }
        });
        
        cardEl.addEventListener('drop', (e) => {
            e.preventDefault();
        });
        
        const dragEndHandler = () => {
            cardEl.classList.remove('dragging');
            
            // После перетаскивания обновляем порядок карточек
            setTimeout(() => {
                const currentCards = Array.from(this.container.querySelectorAll('.dashboard-stat-card'));
                const currentPageOrder = currentCards.map(card => card.dataset.cardId);
                
                // Обновляем порядок карточек в текущей странице
                const currentPageCards = this.pages[this.currentPage] || [];
                const reorderedPageCards = [];
                
                currentPageOrder.forEach(cardId => {
                    const card = currentPageCards.find(c => c.cardId === cardId);
                    if (card) {
                        reorderedPageCards.push(card);
                    }
                });
                
                // Обновляем порядок в allCards
                let allCardsReordered = [];
                this.pages.forEach((page, pageIndex) => {
                    if (pageIndex === this.currentPage) {
                        allCardsReordered = allCardsReordered.concat(reorderedPageCards);
                    } else {
                        allCardsReordered = allCardsReordered.concat(page);
                    }
                });
                
                this.allCards = allCardsReordered;
                
                // Пересоздаем все страницы
                this.rebuildPages();
                this.saveOrder();
            }, 50);
        };
        
        cardEl.addEventListener('dragend', dragEndHandler);
        
        return cardEl;
    },
    
    rebuildPages: function() {
        // Пересоздаем страницы на основе текущего порядка allCards
        this.pages = [];
        for (let i = 0; i < this.allCards.length; i += this.cardsPerPage) {
            this.pages.push(this.allCards.slice(i, i + this.cardsPerPage));
        }
        
        // Если текущая страница больше не существует, переходим на последнюю
        if (this.currentPage >= this.pages.length && this.pages.length > 0) {
            this.currentPage = this.pages.length - 1;
        }
        
        this.renderPage();
        this.renderIndicators();
    },
    
    renderPage: function() {
        this.container.innerHTML = '';
        
        if (this.pages.length === 0) {
            return;
        }
        
        const currentPage = this.pages[this.currentPage] || [];
        
        currentPage.forEach(({ cardId, card, cardData }) => {
            const cardEl = this.createCardElement(cardId, card, cardData);
            this.container.appendChild(cardEl);
        });
        
        // Обновляем видимость кнопок навигации
        this.updateNavigation();
    },
    
    renderIndicators: function() {
        if (!this.indicators) return;
        
        this.indicators.innerHTML = '';
        
        if (this.pages.length <= 1) {
            return; // Не показываем индикаторы, если страница одна
        }
        
        this.pages.forEach((page, index) => {
            const indicator = document.createElement('button');
            indicator.className = `stats-carousel-dot ${index === this.currentPage ? 'active' : ''}`;
            indicator.setAttribute('aria-label', `Страница ${index + 1}`);
            indicator.onclick = () => this.goToPage(index);
            this.indicators.appendChild(indicator);
        });
    },
    
    initNavigation: function() {
        const prevBtn = document.getElementById('statsCarouselPrev');
        const nextBtn = document.getElementById('statsCarouselNext');
        
        if (prevBtn) {
            prevBtn.addEventListener('click', () => this.prevPage());
        }
        
        if (nextBtn) {
            nextBtn.addEventListener('click', () => this.nextPage());
        }
    },
    
    updateNavigation: function() {
        const prevBtn = document.getElementById('statsCarouselPrev');
        const nextBtn = document.getElementById('statsCarouselNext');
        
        if (prevBtn) {
            prevBtn.style.display = this.currentPage === 0 ? 'none' : 'flex';
            prevBtn.disabled = this.currentPage === 0;
        }
        
        if (nextBtn) {
            nextBtn.style.display = this.currentPage >= this.pages.length - 1 ? 'none' : 'flex';
            nextBtn.disabled = this.currentPage >= this.pages.length - 1;
        }
    },
    
    goToPage: function(pageIndex) {
        if (pageIndex < 0 || pageIndex >= this.pages.length) return;
        
        this.currentPage = pageIndex;
        this.renderPage();
        this.renderIndicators();
    },
    
    nextPage: function() {
        if (this.currentPage < this.pages.length - 1) {
            this.goToPage(this.currentPage + 1);
        }
    },
    
    prevPage: function() {
        if (this.currentPage > 0) {
            this.goToPage(this.currentPage - 1);
        }
    },
    
    getDragAfterElement: function(container, x) {
        const draggableElements = [...container.querySelectorAll('.dashboard-stat-card:not(.dragging)')];
        
        return draggableElements.reduce((closest, child) => {
            const box = child.getBoundingClientRect();
            const offset = x - box.left - box.width / 2;
            
            if (offset < 0 && offset > closest.offset) {
                return { offset: offset, element: child };
            } else {
                return closest;
            }
        }, { offset: Number.NEGATIVE_INFINITY }).element;
    },
    
    saveOrder: function() {
        // Используем сохраненный порядок из allCards, так как карточки теперь на разных страницах
        const order = this.allCards.map(item => item.cardId);
        
        const savedConfig = statsCardsConfig.getSaved();
        statsCardsConfig.save({
            order: order,
            enabled: savedConfig ? savedConfig.enabled : null
        });
    }
};

// Настройки карточек статистики
const statsSettings = {
    panel: null,
    list: null,
    
    init: function() {
        this.panel = document.getElementById('statsSettings');
        this.list = document.getElementById('statsSettingsList');
        this.renderSettings();
    },
    
    toggle: function() {
        if (!this.panel) return;
        const isVisible = this.panel.style.display !== 'none';
        this.panel.style.display = isVisible ? 'none' : 'block';
        if (!isVisible) {
            this.renderSettings();
        }
    },
    
    renderSettings: function() {
        if (!this.list) return;
        
        const order = statsCardsConfig.getOrder();
        const savedConfig = statsCardsConfig.getSaved();
        const enabledCards = savedConfig && savedConfig.enabled ? savedConfig.enabled : {};
        
        this.list.innerHTML = '';
        
        const enabledIds = new Set(order);
        
        // Сначала добавляем карточки в порядке из настроек
        order.forEach((cardId) => {
            const card = statsCardsConfig.getById(cardId);
            if (!card) return;
            
            const item = document.createElement('div');
            item.className = 'stats-settings-item';
            item.dataset.cardId = cardId;
            item.draggable = true;
            
            const isEnabled = enabledCards.hasOwnProperty(cardId) ? enabledCards[cardId] : true;
            
            const statsSettingsIconMap = {
                '⭐': '<i class="fas fa-star"></i>',
                '💰': '<i class="fas fa-money-bill-wave"></i>',
                '🔄': '<i class="fas fa-exchange-alt"></i>',
                '🔔': '<i class="fas fa-bell"></i>',
                '💬': '<i class="fas fa-comments"></i>',
                '👥': '<i class="fas fa-users"></i>',
                '📄': '<i class="fas fa-file-invoice"></i>',
                '⚠️': '<i class="fas fa-exclamation-triangle"></i>',
                '🏦': '<i class="fas fa-vault"></i>'
            };
            const statsSettingsIconHtml = statsSettingsIconMap[card.icon] || card.icon;
            
            item.innerHTML = `
                <div class="stats-settings-handle"><i class="fas fa-grip-vertical"></i></div>
                <div class="stats-settings-icon">${statsSettingsIconHtml}</div>
                <div class="stats-settings-title">${card.title}</div>
                <label class="stats-settings-toggle">
                    <input type="checkbox" ${isEnabled ? 'checked' : ''} onchange="statsSettings.toggleCard('${cardId}', this.checked)">
                    <span class="toggle-slider"></span>
                </label>
            `;
            
            // Drag & Drop для настроек
            item.addEventListener('dragstart', (e) => {
                e.dataTransfer.setData('text/plain', cardId);
                e.dataTransfer.effectAllowed = 'move';
                item.classList.add('dragging');
            });
            
            item.addEventListener('dragend', () => {
                item.classList.remove('dragging');
            });
            
            item.addEventListener('dragover', (e) => {
                e.preventDefault();
                e.dataTransfer.dropEffect = 'move';
                const afterElement = this.getDragAfterElement(this.list, e.clientY);
                const dragging = document.querySelector('.stats-settings-item.dragging');
                if (dragging) {
                    if (afterElement == null) {
                        this.list.appendChild(dragging);
                    } else {
                        this.list.insertBefore(dragging, afterElement);
                    }
                }
            });
            
            this.list.appendChild(item);
        });
        
        // Добавляем отключенные карточки
        const allCards = statsCardsConfig.available;
        
        allCards.forEach(card => {
            if (!enabledIds.has(card.id)) {
                const isEnabled = enabledCards.hasOwnProperty(card.id) ? enabledCards[card.id] : false;
                
                const item = document.createElement('div');
                item.className = 'stats-settings-item';
                item.dataset.cardId = card.id;
                
                item.innerHTML = `
                    <div class="stats-settings-handle" style="opacity: 0.3;">☰</div>
                    <div class="stats-settings-icon">${card.icon}</div>
                    <div class="stats-settings-title">${card.title}</div>
                    <label class="stats-settings-toggle">
                        <input type="checkbox" ${isEnabled ? 'checked' : ''} onchange="statsSettings.toggleCard('${card.id}', this.checked)">
                        <span class="toggle-slider"></span>
                    </label>
                `;
                
                this.list.appendChild(item);
            }
        });
    },
    
    getDragAfterElement: function(container, y) {
        const draggableElements = [...container.querySelectorAll('.stats-settings-item:not(.dragging)')];
        
        return draggableElements.reduce((closest, child) => {
            const box = child.getBoundingClientRect();
            const offset = y - box.top - box.height / 2;
            
            if (offset < 0 && offset > closest.offset) {
                return { offset: offset, element: child };
            } else {
                return closest;
            }
        }, { offset: Number.NEGATIVE_INFINITY }).element;
    },
    
    toggleCard: function(cardId, enabled) {
        const savedConfig = statsCardsConfig.getSaved();
        if (!savedConfig) {
            const order = statsCardsConfig.getOrder();
            statsCardsConfig.save({
                order: order,
                enabled: {}
            });
        }
        
        const config = statsCardsConfig.getSaved();
        if (!config.enabled) {
            config.enabled = {};
        }
        config.enabled[cardId] = enabled;
        
        // Если карточка включена и её нет в порядке, добавляем в конец
        if (enabled && !config.order.includes(cardId)) {
            config.order.push(cardId);
        } else if (!enabled && config.order.includes(cardId)) {
            // Удаляем из порядка, если отключена
            config.order = config.order.filter(id => id !== cardId);
        }
        
        statsCardsConfig.save(config);
    },
    
    save: function() {
        // Сохраняем новый порядок из настроек
        const items = Array.from(this.list.querySelectorAll('.stats-settings-item'));
        const order = [];
        const enabled = {};
        
        items.forEach(item => {
            const cardId = item.dataset.cardId;
            const checkbox = item.querySelector('input[type="checkbox"]');
            const isEnabled = checkbox ? checkbox.checked : false;
            
            enabled[cardId] = isEnabled;
            if (isEnabled) {
                order.push(cardId);
            }
        });
        
        statsCardsConfig.save({ order, enabled });
        statsCardsManager.render();
        this.toggle();
    },
    
    reset: function() {
        if (confirm('Сбросить все настройки карточек статистики?')) {
            localStorage.removeItem('dashboard_stats_cards');
            statsCardsManager.render();
            this.renderSettings();
        }
    }
};

// Инициализация
document.addEventListener('DOMContentLoaded', function() {
    try {
        console.log('Dashboard initialization started');
        
        if (typeof quickActionsManager !== 'undefined') {
            quickActionsManager.init();
        } else {
            console.error('quickActionsManager is not defined');
        }
        
        if (typeof quickActionsSettings !== 'undefined') {
            quickActionsSettings.init();
        } else {
            console.error('quickActionsSettings is not defined');
        }
        
        if (typeof statsCardsManager !== 'undefined') {
            statsCardsManager.init();
        } else {
            console.error('statsCardsManager is not defined');
        }
        
        if (typeof statsSettings !== 'undefined') {
            statsSettings.init();
        } else {
            console.error('statsSettings is not defined');
        }
        
        // Сохраняем порядок после перетаскивания
        const quickActions = document.getElementById('quickActions');
        if (quickActions) {
            quickActions.addEventListener('dragend', function() {
                if (typeof quickActionsManager !== 'undefined' && quickActionsManager.saveOrder) {
                    quickActionsManager.saveOrder();
                }
            });
        }
        
        const statsGrid = document.getElementById('statsGrid');
        if (statsGrid) {
            statsGrid.addEventListener('dragend', function() {
                if (typeof statsCardsManager !== 'undefined' && statsCardsManager.saveOrder) {
                    statsCardsManager.saveOrder();
                }
            });
        }
        
        console.log('Dashboard initialization completed');
    } catch (error) {
        console.error('Error during dashboard initialization:', error);
    }
});
</script>
</div>
<?php
$content = ob_get_clean();
include __DIR__ . '/../templates/base.php';

