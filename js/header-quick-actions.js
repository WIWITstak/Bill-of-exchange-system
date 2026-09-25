/**
 * Синхронизация быстрых действий в хедере с настройками из dashboard
 */

(function() {
    'use strict';

    // Маппинг ID действий на иконки Font Awesome
    const iconMap = {
        'transactions': '<i class="fas fa-exchange-alt"></i>',
        'create_transaction': '<i class="fas fa-plus"></i>',
        'bills': '<i class="fas fa-file-invoice-dollar"></i>',
        'chats': '<i class="fas fa-comments"></i>',
        'depository': '<i class="fas fa-vault"></i>',
        'subscriptions': '<i class="fas fa-credit-card"></i>',
        'rating': '<i class="fas fa-star"></i>',
        'community': '<i class="fas fa-users"></i>',
        'users': '<i class="fas fa-user"></i>',
        'products': '<i class="fas fa-box"></i>',
        'categories': '<i class="fas fa-folder"></i>',
        'notifications': '<i class="fas fa-bell"></i>',
        'profile': '<i class="fas fa-user-circle"></i>',
        'company': '<i class="fas fa-building"></i>'
    };

    // Все доступные действия (базовая конфигурация)
    // Для хедера используем короткие названия
    const defaultActions = {
        'transactions': { title: 'Транзакции', url: '/transactions.php', headerTitle: 'Транзакции' },
        'create_transaction': { title: 'Транзакция', url: '/transactions/create.php', headerTitle: 'Транзакция' },
        'bills': { title: 'Вексели', url: '/bills.php', headerTitle: 'Вексели' },
        'chats': { title: 'Чаты', url: '/chats.php', headerTitle: 'Чаты' },
        'depository': { title: 'Депозитарий', url: '/depository.php', headerTitle: 'Депозитарий' },
        'subscriptions': { title: 'Подписка', url: '/subscriptions.php', headerTitle: 'Подписка' },
        'rating': { title: 'Рейтинг', url: '/rating.php', headerTitle: 'Рейтинг' },
        'community': { title: 'Община', url: '/community.php', headerTitle: 'Община' },
        'users': { title: 'Пользователи', url: '/users.php', headerTitle: 'Пользователи' },
        'products': { title: 'Товары', url: '/products.php', headerTitle: 'Товары' },
        'categories': { title: 'Категории', url: '/categories.php', headerTitle: 'Категории' },
        'notifications': { title: 'Уведомления', url: '/notifications.php', headerTitle: 'Уведомления' },
        'profile': { title: 'Профиль', url: '/profile/edit.php', headerTitle: 'Профиль' },
        'company': { title: 'Предприятие', url: '/company.php', headerTitle: 'Предприятие' }
    };

    // Порядок по умолчанию для хедера (первые 3 действия)
    const defaultHeaderOrder = ['create_transaction', 'bills', 'community'];

    // Получить сохраненную конфигурацию
    function getSavedConfig() {
        const saved = localStorage.getItem('dashboard_quick_actions');
        if (saved) {
            try {
                return JSON.parse(saved);
            } catch (e) {
                return null;
            }
        }
        return null;
    }

    // Получить порядок действий для хедера
    function getHeaderActionsOrder() {
        const savedConfig = getSavedConfig();
        if (savedConfig && savedConfig.order && Array.isArray(savedConfig.order)) {
            const enabledActions = savedConfig.enabled || {};
            const showInHeader = savedConfig.showInHeader || {};
            
            // Фильтруем только включенные действия
            const enabledOrder = savedConfig.order.filter(id => {
                return !enabledActions.hasOwnProperty(id) || enabledActions[id] !== false;
            });
            
            // Если есть настройки showInHeader, фильтруем по ним
            // Если настроек нет, показываем все включенные действия
            const hasHeaderSettings = Object.keys(showInHeader).length > 0;
            
            if (hasHeaderSettings) {
                // Фильтруем только те, что явно помечены для показа в хедере
                return enabledOrder.filter(id => {
                    return showInHeader.hasOwnProperty(id) && showInHeader[id] === true;
                });
            } else {
                // Если нет настроек showInHeader, показываем все включенные действия
                return enabledOrder;
            }
        }
        
        // Если нет настроек, показываем действия по умолчанию
        return defaultHeaderOrder;
    }

    // Рендерить быстрые действия в хедере
    function renderHeaderQuickActions() {
        const container = document.querySelector('.header-quick-actions');
        if (!container) return;

        const order = getHeaderActionsOrder();

        container.innerHTML = '';

        order.forEach(actionId => {
            const actionConfig = defaultActions[actionId];
            if (!actionConfig) return;

            const actionEl = document.createElement('a');
            actionEl.href = actionConfig.url;
            actionEl.className = 'header-quick-action';
            actionEl.title = actionConfig.headerTitle || actionConfig.title;

            const icon = iconMap[actionId] || '<i class="fas fa-circle"></i>';
            const displayTitle = actionConfig.headerTitle || actionConfig.title;
            actionEl.innerHTML = icon + '<span>' + displayTitle + '</span>';

            container.appendChild(actionEl);
        });
    }

    // Слушать изменения в localStorage от других вкладок/окон
    window.addEventListener('storage', function(e) {
        if (e.key === 'dashboard_quick_actions') {
            renderHeaderQuickActions();
        }
    });

    // Слушать кастомные события изменения настроек (для синхронизации в той же вкладке)
    window.addEventListener('quickActionsUpdated', function() {
        renderHeaderQuickActions();
    });

    // Инициализация при загрузке DOM
    if (document.readyState === 'loading') {
        document.addEventListener('DOMContentLoaded', renderHeaderQuickActions);
    } else {
        renderHeaderQuickActions();
    }
})();

