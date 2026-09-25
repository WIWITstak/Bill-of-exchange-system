<?php
// Проверяем, авторизован ли пользователь для отображения навигации
use OGAS\Services\Auth;
use OGAS\Models\Notification;
use OGAS\Models\Transaction as TransactionModel;
use OGAS\Services\ChatService;
use OGAS\Services\NotificationService;

$isAuthenticated = Auth::check();
$currentUser = Auth::user();
?>
<!DOCTYPE html>
<html lang="ru">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title><?= $title ?? 'ОГАС' ?> - Общегосударственная автоматизированная система</title>
    <script>
        <?php if ($isAuthenticated && $currentUser): ?>
        // CSRF токен для использования в JavaScript
        window.csrfToken = <?= json_encode(csrf_token(), JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT) ?>;
        
        // Применяем состояние бокового меню ДО загрузки CSS для предотвращения мигания
        try {
            const savedState = localStorage.getItem('sidebarState');
            if (savedState === 'collapsed' || savedState === 'hidden') {
                // Добавляем inline стили для предотвращения мигания
                const sidebarStyle = document.createElement('style');
                sidebarStyle.id = 'sidebar-anti-flash';
                if (savedState === 'collapsed') {
                    sidebarStyle.textContent = `
                        #sidebar { transition: none !important; }
                        #sidebar.collapsed { width: 70px !important; left: 0 !important; }
                        .sidebar.collapsed ~ .app-content { margin-left: 70px !important; width: calc(100% - 70px) !important; }
                    `;
                } else if (savedState === 'hidden') {
                    sidebarStyle.textContent = `
                        #sidebar { transition: none !important; }
                        #sidebar.hidden { width: 0 !important; left: -260px !important; overflow: hidden !important; pointer-events: none !important; }
                        .sidebar.hidden ~ .app-content { margin-left: 0 !important; width: 100% !important; }
                    `;
                }
                document.head.appendChild(sidebarStyle);
                
                // Удаляем стили после загрузки DOM и восстановления состояния
                document.addEventListener('DOMContentLoaded', function() {
                    // Ждем, пока sidebar.js восстановит состояние
                    setTimeout(function() {
                        const styleEl = document.getElementById('sidebar-anti-flash');
                        if (styleEl) {
                            // Добавляем небольшую задержку перед удалением, чтобы состояние успело примениться
                            setTimeout(function() {
                                styleEl.remove();
                            }, 150);
                        }
                    }, 200);
                });
            }
        } catch(e) {
            // localStorage недоступен или ошибка
        }
        <?php endif; ?>
    </script>
    <!-- Font Awesome Icons -->
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.5.1/css/all.min.css" integrity="sha512-DTOQO9RWCH3ppGqcWaEA1BIZOC6xxalwEsw9c2QQeAIftl+Vegovlnee1c9QX4TctnWMn13TZye+giMm8e2LwA==" crossorigin="anonymous" referrerpolicy="no-referrer" />
    <link rel="stylesheet" href="/css/style.css">
    <?php if (isset($additional_css)): ?>
        <?php foreach ($additional_css as $css): ?>
            <link rel="stylesheet" href="<?= $css ?>">
        <?php endforeach; ?>
    <?php endif; ?>
    <!-- Защита от ботов -->
    <script src="/js/bot-protection.js" defer></script>
</head>
<body<?= $isAuthenticated && $currentUser ? ' data-has-sidebar="true"' : '' ?>>
    <div class="app-layout">
        <?php if ($isAuthenticated && $currentUser): ?>
            <!-- Боковое меню -->
            <aside class="sidebar" id="sidebar">
                <div class="sidebar-header">
                    <h2 class="sidebar-logo">
                        <a href="/dashboard.php">ОГАС</a>
                    </h2>
                    <button class="sidebar-toggle-collapse" onclick="toggleSidebarCollapse()" aria-label="Свернуть меню" title="Свернуть меню">
                        <span class="collapse-icon"><i class="fas fa-chevron-left"></i></span>
                        <span class="expand-icon"><i class="fas fa-chevron-right"></i></span>
                    </button>
                    <button class="sidebar-toggle-close" onclick="closeSidebar()" aria-label="Закрыть меню"><i class="fas fa-times"></i></button>
                </div>
                
                <div class="sidebar-user">
                    <div class="sidebar-user-info">
                        <a href="/profile/edit.php" class="sidebar-user-avatar-link" title="Редактировать профиль">
                            <?= $currentUser->getAvatarHtml('small', 'sidebar-user-avatar-image') ?>
                        </a>
                        <div class="sidebar-user-details">
                            <div class="sidebar-user-name"><?= htmlspecialchars($currentUser->getFullName()) ?></div>
                            <div class="sidebar-user-email"><?= htmlspecialchars($currentUser->getEmail()) ?></div>
                        </div>
                    </div>
                </div>
                
                <nav class="sidebar-nav">
                    <!-- Группа: Основные -->
                    <div class="sidebar-group">
                        <a href="/dashboard.php" class="sidebar-link" data-title="Главная">
                            <span class="sidebar-icon"><i class="fas fa-home"></i></span>
                            <span class="sidebar-text">Главная</span>
                        </a>
                        <a href="/transactions.php" class="sidebar-link" data-title="Транзакции">
                            <span class="sidebar-icon"><i class="fas fa-exchange-alt"></i></span>
                            <span class="sidebar-text">Транзакции</span>
                        </a>
                        <a href="/bills.php" class="sidebar-link" data-title="Вексели">
                            <span class="sidebar-icon"><i class="fas fa-file-invoice-dollar"></i></span>
                            <span class="sidebar-text">Вексели</span>
                        </a>
                        <a href="/chats.php" class="sidebar-link" data-title="Чаты">
                            <span class="sidebar-icon"><i class="fas fa-comments"></i></span>
                            <span class="sidebar-text">Чаты</span>
                            <?php
                            $totalUnreadMessages = 0;
                            $userTransactions = TransactionModel::findByUser($currentUser->getId());
                            foreach ($userTransactions as $trans) {
                                $unread = ChatService::getUnreadCount($trans->getId(), $currentUser->getId());
                                if ($unread > 0) {
                                    $totalUnreadMessages += $unread;
                                }
                            }
                            if ($totalUnreadMessages > 0):
                            ?>
                                <span class="sidebar-badge"><?= $totalUnreadMessages ?></span>
                            <?php endif; ?>
                        </a>
                    </div>
                    
                    <!-- Разделитель -->
                    <div class="sidebar-divider"></div>
                    
                    <!-- Группа: Управление -->
                    <div class="sidebar-group">
                        <a href="/depository.php" class="sidebar-link" data-title="Депозитарий">
                            <span class="sidebar-icon"><i class="fas fa-vault"></i></span>
                            <span class="sidebar-text">Депозитарий</span>
                        </a>
                        <a href="/subscriptions.php" class="sidebar-link" data-title="Подписка">
                            <span class="sidebar-icon"><i class="fas fa-credit-card"></i></span>
                            <span class="sidebar-text">Подписка</span>
                        </a>
                        <a href="/rating.php" class="sidebar-link" data-title="Рейтинг">
                            <span class="sidebar-icon"><i class="fas fa-star"></i></span>
                            <span class="sidebar-text">Рейтинг</span>
                        </a>
                        <a href="/charts.php" class="sidebar-link" data-title="Графики">
                            <span class="sidebar-icon"><i class="fas fa-chart-bar"></i></span>
                            <span class="sidebar-text">Графики</span>
                        </a>
                    </div>
                    
                    <!-- Разделитель -->
                    <div class="sidebar-divider"></div>
                    
                    <!-- Группа: Сообщество -->
                    <div class="sidebar-group">
                        <a href="/community.php" class="sidebar-link" data-title="Община">
                            <span class="sidebar-icon"><i class="fas fa-users"></i></span>
                            <span class="sidebar-text">Община</span>
                        </a>
                        <a href="/users.php" class="sidebar-link" data-title="Пользователи">
                            <span class="sidebar-icon"><i class="fas fa-user"></i></span>
                            <span class="sidebar-text">Пользователи</span>
                        </a>
                        <a href="/products.php" class="sidebar-link" data-title="Товары и услуги">
                            <span class="sidebar-icon"><i class="fas fa-box"></i></span>
                            <span class="sidebar-text">Товары и услуги</span>
                        </a>
                        <a href="/products/catalog.php" class="sidebar-link" data-title="Каталог товаров и услуг">
                            <span class="sidebar-icon"><i class="fas fa-th-large"></i></span>
                            <span class="sidebar-text">Каталог</span>
                        </a>
                        <a href="/categories.php" class="sidebar-link" data-title="Категории">
                            <span class="sidebar-icon"><i class="fas fa-folder"></i></span>
                            <span class="sidebar-text">Категории</span>
                        </a>
                    </div>
                    
                    <!-- Разделитель -->
                    <div class="sidebar-divider"></div>
                    
                    <!-- Группа: Поддержка -->
                    <div class="sidebar-group">
                        <a href="/support.php" class="sidebar-link" data-title="Поддержка">
                            <span class="sidebar-icon"><i class="fas fa-headset"></i></span>
                            <span class="sidebar-text">Поддержка</span>
                        </a>
                    </div>
                    
                    <!-- Разделитель -->
                    <div class="sidebar-divider"></div>
                    
                    <!-- Группа: Настройки -->
                    <div class="sidebar-group">
                        <a href="/notifications.php" class="sidebar-link" data-title="Уведомления">
                            <span class="sidebar-icon"><i class="fas fa-bell"></i></span>
                            <span class="sidebar-text">Уведомления</span>
                            <?php
                            $unreadNotificationsCount = \OGAS\Models\Notification::getUnreadCount($currentUser->getId());
                            if ($unreadNotificationsCount > 0):
                            ?>
                                <span class="sidebar-badge"><?= $unreadNotificationsCount ?></span>
                            <?php endif; ?>
                        </a>
                        <a href="/profile/edit.php" class="sidebar-link" data-title="Профиль">
                            <span class="sidebar-icon"><i class="fas fa-user-cog"></i></span>
                            <span class="sidebar-text">Профиль</span>
                        </a>
                    </div>
                    
                    <?php if ($currentUser && $currentUser->isAdmin()): ?>
                        <!-- Разделитель -->
                        <div class="sidebar-divider"></div>
                        
                        <!-- Группа: Администрирование -->
                        <div class="sidebar-group">
                            <a href="/admin/index.php" class="sidebar-link sidebar-link-admin" data-title="Админ-панель">
                                <span class="sidebar-icon"><i class="fas fa-shield-alt"></i></span>
                                <span class="sidebar-text">Админ-панель</span>
                            </a>
                        </div>
                    <?php endif; ?>
                </nav>
                
                <div class="sidebar-footer">
                    <a href="/logout.php" class="sidebar-link sidebar-link-logout" data-title="Выйти">
                        <span class="sidebar-icon"><i class="fas fa-sign-out-alt"></i></span>
                        <span class="sidebar-text">Выйти</span>
                    </a>
                </div>
            </aside>
            
            <!-- Overlay для мобильных устройств -->
            <div class="sidebar-overlay" id="sidebar-overlay"></div>
        <?php endif; ?>
        
        <div class="app-content">
            <header class="app-header">
                <div class="header-left">
                    <?php if ($isAuthenticated && $currentUser): ?>
                        <button class="sidebar-toggle" onclick="showSidebar()" aria-label="Открыть меню" id="sidebarToggleBtn" style="display: none;">
                            <i class="fas fa-bars"></i>
                        </button>
                    <?php endif; ?>
                    <h1 class="app-title">
                        <a href="<?= $isAuthenticated && $currentUser ? '/dashboard.php' : '/index.php' ?>">ОГАС</a>
                    </h1>
                </div>
                <div class="header-right">
                    <?php if ($isAuthenticated && $currentUser): ?>
                        <!-- Быстрые действия в header (не показываем на главной) -->
                        <?php
                        $currentPage = basename($_SERVER['PHP_SELF']);
                        $isDashboard = ($currentPage === 'dashboard.php');
                        if (!$isDashboard):
                        ?>
                        <div class="header-quick-actions" id="headerQuickActions">
                            <!-- Быстрые действия будут добавлены через JavaScript -->
                        </div>
                        <?php endif; ?>
                        
                        <div class="user-menu">
                            <!-- Уведомления с dropdown -->
                            <div class="notifications-dropdown">
                                <button class="notification-bell" id="notificationBell" onclick="toggleNotifications()">
                                    <i class="fas fa-bell"></i>
                                    <?php
                                    $unreadNotificationsCount = Notification::getUnreadCount($currentUser->getId());
                                    if ($unreadNotificationsCount > 0):
                                    ?>
                                        <span class="notification-counter"><?= $unreadNotificationsCount ?></span>
                                    <?php endif; ?>
                                </button>
                                <div class="notifications-panel" id="notificationsPanel">
                                    <div class="notifications-header">
                                        <h3>Уведомления</h3>
                                        <a href="#" class="mark-all-read" id="markAllReadBtn" style="<?= $unreadNotificationsCount > 0 ? '' : 'display: none;' ?>">Отметить все как прочитанные</a>
                                        <a href="/notifications.php" class="view-all">Все уведомления →</a>
                                    </div>
                                    <div class="notifications-list" id="notificationsList">
                                        <?php
                                        $recentNotifications = Notification::findByUserId($currentUser->getId(), false, 10);
                                        if (empty($recentNotifications)):
                                        ?>
                                            <div class="notification-item notification-empty">
                                                <p>У вас нет уведомлений</p>
                                            </div>
                                        <?php else: ?>
                                            <?php foreach ($recentNotifications as $notification): ?>
                                                <div class="notification-item <?= !$notification->isRead() ? 'notification-unread' : '' ?>" 
                                                     data-id="<?= $notification->getId() ?>"
                                                     onclick="window.location.href='<?= NotificationService::getNotificationUrl($notification) ?>'">
                                                    <div class="notification-icon">
                                                        <?php
                                                        $icons = [
                                                            'bill_received' => '<i class="fas fa-file-invoice-dollar"></i>',
                                                            'bill_maturity_reminder' => '<i class="fas fa-clock"></i>',
                                                            'bill_overdue' => '<i class="fas fa-exclamation-triangle"></i>',
                                                            'bill_paid' => '<i class="fas fa-check-circle"></i>',
                                                            'transaction_created' => '<i class="fas fa-exchange-alt"></i>',
                                                            'transaction_completed' => '<i class="fas fa-check-circle"></i>',
                                                            'transaction_cancelled' => '<i class="fas fa-times-circle"></i>',
                                                            'community_request' => '<i class="fas fa-users"></i>',
                                                            'community_guarantor' => '<i class="fas fa-handshake"></i>',
                                                            'subscription_reminder' => '<i class="fas fa-credit-card"></i>',
                                                            'subscription_expired' => '<i class="fas fa-pause-circle"></i>',
                                                            'subscription_bill_issued' => '<i class="fas fa-clipboard-list"></i>',
                                                            'subscription_paid' => '<i class="fas fa-check-circle"></i>',
                                                            'chat_message' => '<i class="fas fa-comment"></i>',
                                                            'account_activated' => '<i class="fas fa-party-horn"></i>'
                                                        ];
                                                        echo $icons[$notification->getType()] ?? '<i class="fas fa-bell"></i>';
                                                        ?>
                                                    </div>
                                                    <div class="notification-content">
                                                        <div class="notification-title"><?= htmlspecialchars($notification->getTitle()) ?></div>
                                                        <div class="notification-message"><?= htmlspecialchars(mb_substr($notification->getMessage(), 0, 80)) ?><?= mb_strlen($notification->getMessage()) > 80 ? '...' : '' ?></div>
                                                        <div class="notification-time"><?= NotificationService::getTimeAgo($notification->getCreatedAt()) ?></div>
                                                    </div>
                                                    <?php if (!$notification->isRead()): ?>
                                                        <div class="notification-unread-indicator"></div>
                                                    <?php endif; ?>
                                                </div>
                                            <?php endforeach; ?>
                                        <?php endif; ?>
                                    </div>
                                </div>
                            </div>
                            
                            <div class="header-user">
                                <span class="user-name"><?= htmlspecialchars($currentUser->getFullName()) ?></span>
                            </div>
                        </div>
                    <?php endif; ?>
                </div>
            </header>
            
            <main class="app-main">
                <?php if (isset($breadcrumbs) && !empty($breadcrumbs)): ?>
                <nav class="breadcrumbs">
                    <a href="/dashboard.php">Главная</a>
                    <?php foreach ($breadcrumbs as $breadcrumb): ?>
                        <span class="breadcrumb-separator">/</span>
                        <?php if (isset($breadcrumb['url'])): ?>
                            <a href="<?= htmlspecialchars($breadcrumb['url']) ?>"><?= htmlspecialchars($breadcrumb['label']) ?></a>
                        <?php else: ?>
                            <span><?= htmlspecialchars($breadcrumb['label']) ?></span>
                        <?php endif; ?>
                    <?php endforeach; ?>
                </nav>
                <?php endif; ?>
                
                <?= $content ?? '' ?>
            </main>
            
            <footer class="app-footer">
                <p>&copy; 2024 ОГАС. Все права защищены.</p>
            </footer>
        </div>
        
        <!-- Мобильное меню (bottom navigation) -->
        <?php if ($isAuthenticated && $currentUser): ?>
        <nav class="mobile-bottom-nav" id="mobileBottomNav">
            <a href="/dashboard.php" class="mobile-nav-item" data-page="dashboard">
                <span class="mobile-nav-icon"><i class="fas fa-home"></i></span>
                <span class="mobile-nav-label">Главная</span>
            </a>
            <a href="/transactions.php" class="mobile-nav-item" data-page="transactions">
                <span class="mobile-nav-icon"><i class="fas fa-exchange-alt"></i></span>
                <span class="mobile-nav-label">Транзакции</span>
            </a>
            <a href="/bills.php" class="mobile-nav-item" data-page="bills">
                <span class="mobile-nav-icon"><i class="fas fa-file-invoice-dollar"></i></span>
                <span class="mobile-nav-label">Вексели</span>
            </a>
            <a href="/chats.php" class="mobile-nav-item" data-page="chats">
                <span class="mobile-nav-icon"><i class="fas fa-comments"></i></span>
                <span class="mobile-nav-label">Чаты</span>
                <?php
                $totalUnreadMessages = 0;
                $userTransactions = TransactionModel::findByUser($currentUser->getId());
                foreach ($userTransactions as $trans) {
                    $unread = ChatService::getUnreadCount($trans->getId(), $currentUser->getId());
                    if ($unread > 0) {
                        $totalUnreadMessages += $unread;
                    }
                }
                if ($totalUnreadMessages > 0):
                ?>
                    <span class="mobile-nav-badge"><?= $totalUnreadMessages ?></span>
                <?php endif; ?>
            </a>
            <a href="/profile/edit.php" class="mobile-nav-item" data-page="profile">
                <span class="mobile-nav-icon"><i class="fas fa-user"></i></span>
                <span class="mobile-nav-label">Профиль</span>
            </a>
        </nav>
        <?php endif; ?>
    </div>
    
    <!-- Sidebar скрипт -->
    <?php if ($isAuthenticated && $currentUser): ?>
    <script src="/js/sidebar.js"></script>
    <?php endif; ?>
    
    <!-- Toast уведомления -->
    <script src="/js/toast.js"></script>
    
    <!-- Переключение темы -->
    
    <!-- Уведомления dropdown -->
    <?php if ($isAuthenticated && $currentUser): ?>
    <script src="/js/notifications.js"></script>
    <?php endif; ?>
    
    <!-- Filters toggle -->
    <script src="/js/filters-toggle.js"></script>
    
    <!-- Быстрые действия в хедере (синхронизация с настройками) -->
    <?php if ($isAuthenticated && $currentUser): ?>
    <script src="/js/header-quick-actions.js"></script>
    <?php endif; ?>
    
    <!-- Объединенный файл всех скриптов чата -->
    <?php if ($isAuthenticated && $currentUser): ?>
    <script>
        window.currentUserId = <?= $currentUser->getId() ?>;
    </script>
    <?php endif; ?>
    <script src="/js/chat-all.js"></script>
    
    <?php
    // Показываем flash-сообщения через Toast
    if ($isAuthenticated) {
        $flashSuccess = \OGAS\Core\Session::getFlash('success');
        $flashError = \OGAS\Core\Session::getFlash('error');
        
        if ($flashSuccess || $flashError) {
            echo '<script>';
            if ($flashSuccess) {
                echo 'Toast.success(' . json_encode($flashSuccess, JSON_UNESCAPED_UNICODE) . ');';
            }
            if ($flashError) {
                echo 'Toast.error(' . json_encode($flashError, JSON_UNESCAPED_UNICODE) . ');';
            }
            echo '</script>';
        }
    }
    ?>
    
    <?php if (isset($additional_js)): ?>
        <?php foreach ($additional_js as $js): ?>
            <script src="<?= $js ?>"></script>
        <?php endforeach; ?>
    <?php endif; ?>
</body>
</html>

