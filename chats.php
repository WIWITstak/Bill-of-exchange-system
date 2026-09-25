<?php
/**
 * Страница быстрого доступа к чатам
 */

require_once __DIR__ . '/../src/bootstrap.php';

use OGAS\Services\Auth;
use OGAS\Models\User;
use OGAS\Models\Transaction;
use OGAS\Models\CommunityRequest;
use OGAS\Services\ChatService;
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
$hasUnread = isset($_GET['unread']) && $_GET['unread'] === '1';
$searchQuery = ''; // Поиск деактивирован
$chatTypeFilter = $_GET['chat_type'] ?? null; // 'transaction', 'community', или null для всех

// Получаем все транзакции пользователя
$allTransactions = Transaction::findByUser($user->getId(), $statusFilter);

// Фильтруем транзакции с непрочитанными сообщениями, если нужно
$transactions = [];
foreach ($allTransactions as $transaction) {
    // Фильтр по непрочитанным
    if ($hasUnread) {
        $unreadCount = ChatService::getUnreadCount($transaction->getId(), $user->getId());
        if ($unreadCount === 0) {
            continue;
        }
    }
    
    // Фильтр по типу чата
    if ($chatTypeFilter === 'community') {
        continue;
    }
    
    // Поиск деактивирован
    $transactions[] = $transaction;
}

// Получаем групповые чаты общины
$allCommunityChats = CommunityRequest::findUserCommunityChats($user->getId());

// Фильтруем групповые чаты с непрочитанными сообщениями, если нужно
$communityChats = [];
foreach ($allCommunityChats as $request) {
    // Фильтр по непрочитанным
    if ($hasUnread) {
        $unreadCount = ChatService::getCommunityUnreadCount($request->getId(), $user->getId());
        if ($unreadCount === 0) {
            continue;
        }
    }
    
    // Фильтр по типу чата
    if ($chatTypeFilter === 'transaction') {
        continue;
    }
    
    // Поиск деактивирован
    $communityChats[] = $request;
}

// Сортируем транзакции: сначала с непрочитанными, затем по дате обновления
usort($transactions, function($a, $b) use ($user) {
    $unreadA = ChatService::getUnreadCount($a->getId(), $user->getId());
    $unreadB = ChatService::getUnreadCount($b->getId(), $user->getId());
    
    if ($unreadA > 0 && $unreadB === 0) return -1;
    if ($unreadA === 0 && $unreadB > 0) return 1;
    
    return strtotime($b->getUpdatedAt() ?? $b->getCreatedAt()) - strtotime($a->getUpdatedAt() ?? $a->getCreatedAt());
});

// Сортируем групповые чаты общины: сначала с непрочитанными, затем по дате обновления
usort($communityChats, function($a, $b) use ($user) {
    $unreadA = ChatService::getCommunityUnreadCount($a->getId(), $user->getId());
    $unreadB = ChatService::getCommunityUnreadCount($b->getId(), $user->getId());
    
    if ($unreadA > 0 && $unreadB === 0) return -1;
    if ($unreadA === 0 && $unreadB > 0) return 1;
    
    return strtotime($b->getUpdatedAt() ?? $b->getCreatedAt()) - strtotime($a->getUpdatedAt() ?? $a->getCreatedAt());
});

// Объединяем все чаты: сначала непрочитанные групповые чаты, затем непрочитанные транзакции, затем остальные
$allChats = [];
$unreadCommunityChats = [];
$readCommunityChats = [];
$unreadTransactions = [];
$readTransactions = [];

foreach ($communityChats as $request) {
    $unreadCount = ChatService::getCommunityUnreadCount($request->getId(), $user->getId());
    if ($unreadCount > 0) {
        $unreadCommunityChats[] = ['type' => 'community', 'data' => $request];
    } else {
        $readCommunityChats[] = ['type' => 'community', 'data' => $request];
    }
}

foreach ($transactions as $transaction) {
    $unreadCount = ChatService::getUnreadCount($transaction->getId(), $user->getId());
    if ($unreadCount > 0) {
        $unreadTransactions[] = ['type' => 'transaction', 'data' => $transaction];
    } else {
        $readTransactions[] = ['type' => 'transaction', 'data' => $transaction];
    }
}

$allChats = array_merge($unreadCommunityChats, $unreadTransactions, $readCommunityChats, $readTransactions);

$title = 'Чаты';
ob_start();
?>
<div class="telegram-chat-layout">
    <!-- Левая панель: список чатов -->
    <div class="telegram-sidebar">
        <div class="telegram-sidebar-header">
            <div style="flex: 1;">
                <h2>Чаты</h2>
                <?php if ($statusFilter || $hasUnread || $chatTypeFilter): ?>
                    <div style="font-size: 0.875rem; color: var(--text-secondary); margin-top: 4px;">
                        Найдено: <strong style="color: var(--color-primary);"><?= count($allChats) ?></strong>
                    </div>
                <?php endif; ?>
            </div>
            <a href="/dashboard.php" class="btn-icon-back" title="В кабинет">
                <i class="fas fa-arrow-left"></i>
            </a>
        </div>

        <?php if ($error): ?>
            <div class="alert alert-error"><?= htmlspecialchars($error) ?></div>
        <?php endif; ?>

        <?php if ($success): ?>
            <div class="alert alert-success"><?= htmlspecialchars($success) ?></div>
        <?php endif; ?>

        <!-- Фильтры -->
        <?php 
        $hasActiveFilters = $statusFilter || $hasUnread || $chatTypeFilter;
        ?>
        <div class="filters-wrapper chats-filters-wrapper" data-has-filters="<?= $hasActiveFilters ? 'true' : 'false' ?>">
            <div class="filters-toggle-header <?= !$hasActiveFilters ? 'collapsed' : '' ?>">
                <div class="filters-toggle-title">
                    <i class="fas fa-chevron-down filters-toggle-icon"></i>
                    <span>Фильтры<?= $hasActiveFilters ? ' <span style="color: var(--color-primary); font-weight: 600;">(активны)</span>' : '' ?></span>
                </div>
            </div>
            <div class="search-filters-card <?= !$hasActiveFilters ? 'collapsed' : 'expanded' ?>"<?= !$hasActiveFilters ? ' style="max-height: 0; padding: 0; margin: 0; opacity: 0; overflow: hidden;"' : '' ?>>
                <form method="GET" action="/chats.php" class="search-filters-form" id="chatsFilterForm">
                    <div class="filters-row">
                        <div class="filter-group">
                            <label for="chat_type">Тип чата:</label>
                            <select name="chat_type" id="chat_type" class="filter-select" onchange="this.form.submit()">
                                <option value="">Все чаты</option>
                                <option value="transaction" <?= $chatTypeFilter === 'transaction' ? 'selected' : '' ?>>Транзакции</option>
                                <option value="community" <?= $chatTypeFilter === 'community' ? 'selected' : '' ?>>Община</option>
                            </select>
                        </div>
                        
                        <div class="filter-group">
                            <label for="status">Статус транзакций:</label>
                            <select name="status" id="status" class="filter-select" onchange="this.form.submit()">
                                <option value="">Все статусы</option>
                                <option value="pending" <?= $statusFilter === 'pending' ? 'selected' : '' ?>>На рассмотрении</option>
                                <option value="active" <?= $statusFilter === 'active' ? 'selected' : '' ?>>Активные</option>
                                <option value="completed" <?= $statusFilter === 'completed' ? 'selected' : '' ?>>Завершённые</option>
                                <option value="cancelled" <?= $statusFilter === 'cancelled' ? 'selected' : '' ?>>Отменённые</option>
                            </select>
                        </div>
                        
                        <div class="filter-group filter-group-checkbox">
                            <label class="checkbox-filter-label">
                                <input type="checkbox" 
                                       name="unread" 
                                       value="1" 
                                       class="checkbox-filter-input"
                                       id="unread"
                                       <?= $hasUnread ? 'checked' : '' ?> 
                                       onchange="this.form.submit()">
                                <span class="checkbox-filter-text">Только непрочитанные</span>
                            </label>
                        </div>
                        
                        <div class="filter-actions">
                            <?php if ($statusFilter || $hasUnread || $chatTypeFilter): ?>
                                <a href="/chats.php" class="btn btn-secondary">
                                    <i class="fas fa-times"></i> Сбросить
                                </a>
                            <?php endif; ?>
                        </div>
                    </div>
                </form>
            </div>
        </div>

        <!-- Список чатов -->
        <div class="telegram-chats-list">
            <?php if (empty($allChats)): ?>
                <div class="telegram-empty">
                    <div class="telegram-empty-icon">💬</div>
                    <h3><?= ($statusFilter || $hasUnread || $chatTypeFilter) ? 'Чаты не найдены' : 'У вас пока нет чатов' ?></h3>
                    <p><?= ($statusFilter || $hasUnread || $chatTypeFilter) ? 'Попробуйте изменить параметры фильтров' : 'Создайте транзакцию, чтобы начать общение' ?></p>
                    <?php if ($statusFilter || $hasUnread || $chatTypeFilter): ?>
                        <a href="/chats.php" class="btn btn-primary">Сбросить фильтры</a>
                    <?php else: ?>
                        <a href="/transactions/create.php" class="btn btn-primary">Создать транзакцию</a>
                    <?php endif; ?>
                </div>
            <?php else: ?>
                <?php foreach ($allChats as $chatItem): 
                    $chatType = $chatItem['type'];
                    
                    if ($chatType === 'community'):
                        $request = $chatItem['data'];
                        $isRequester = $request->getUserId() === $user->getId();
                        $guarantors = $request->getGuarantors();
                        $guarantorsCount = count($guarantors);
                        
                        // Получаем заявителя для названия чата
                        $requesterUser = User::findById($request->getUserId());
                        $requesterName = $requesterUser ? $requesterUser->getFullName() : 'Пользователь #' . $request->getUserId();
                        
                        // Формируем название чата: заявитель + описание/продукт или просто заявитель
                        $chatTitle = '🏘️ ' . htmlspecialchars($requesterName);
                        if ($request->getProductDescription()) {
                            $productDesc = mb_substr($request->getProductDescription(), 0, 40);
                            $chatTitle .= ' - ' . htmlspecialchars($productDesc) . (mb_strlen($request->getProductDescription()) > 40 ? '...' : '');
                        } elseif ($request->getDescription()) {
                            $desc = mb_substr($request->getDescription(), 0, 40);
                            $chatTitle .= ' - ' . htmlspecialchars($desc) . (mb_strlen($request->getDescription()) > 40 ? '...' : '');
                        }
                        $chatTitle .= ' (#' . $request->getId() . ')';
                        
                        // Получаем последнее сообщение
                        $messages = ChatService::getCommunityMessages($request->getId());
                        $lastMessage = !empty($messages) ? end($messages) : null;
                        
                        // Количество непрочитанных
                        $unreadCount = ChatService::getCommunityUnreadCount($request->getId(), $user->getId());
                        
                        // Форматируем дату последнего сообщения
                        $lastMessageDate = null;
                        if ($lastMessage) {
                            $lastMessageTime = strtotime($lastMessage->getCreatedAt());
                            $now = time();
                            $diff = $now - $lastMessageTime;
                            
                            if ($diff < 60) {
                                $lastMessageDate = 'только что';
                            } elseif ($diff < 3600) {
                                $minutes = floor($diff / 60);
                                $lastMessageDate = $minutes . ' мин назад';
                            } elseif ($diff < 86400) {
                                $hours = floor($diff / 3600);
                                $lastMessageDate = $hours . ' ч назад';
                            } elseif ($diff < 604800) {
                                $days = floor($diff / 86400);
                                $lastMessageDate = $days . ' дн назад';
                            } else {
                                $lastMessageDate = date('d.m.Y', $lastMessageTime);
                            }
                        } else {
                            $requestTime = strtotime($request->getUpdatedAt() ?? $request->getCreatedAt());
                            $lastMessageDate = date('d.m.Y', $requestTime);
                        }
                        
                        // Определяем автора последнего сообщения
                        $lastMessageAuthor = null;
                        if ($lastMessage) {
                            $lastMessageUser = User::findById($lastMessage->getUserId());
                            $isLastMessageOwn = $lastMessage->getUserId() === $user->getId();
                            $lastMessageAuthor = $isLastMessageOwn ? 'Вы' : htmlspecialchars($lastMessageUser->getFullName());
                        }
                        
                        // Проверяем статус подтверждения всех поручителей
                        $allConfirmed = $request->allGuarantorsConfirmed();
                        $confirmedCount = $request->getConfirmedGuarantorsCount();
                ?>
                    <a href="/community/chat.php?id=<?= $request->getId() ?>" 
                       class="telegram-chat-item <?= $unreadCount > 0 ? 'telegram-chat-unread' : '' ?>" 
                       data-chat-type="community" 
                       data-chat-id="<?= $request->getId() ?>"
                       <?php if ($lastMessage): ?>data-last-message-time="<?= htmlspecialchars($lastMessage->getCreatedAt()) ?>"<?php endif; ?>>
                        <div class="telegram-chat-avatar">
                            <span class="telegram-avatar-icon">🏘️</span>
                        </div>
                        
                        <div class="telegram-chat-content">
                            <div class="telegram-chat-header">
                                <h4 class="telegram-chat-title"><?= $chatTitle ?></h4>
                                <span class="telegram-chat-time"><?= $lastMessageDate ?></span>
                            </div>
                            
                            <div class="telegram-chat-preview">
                                <?php if ($lastMessage): ?>
                                    <span class="telegram-preview-text">
                                        <?= $lastMessageAuthor ?>: <?= htmlspecialchars(mb_substr($lastMessage->getMessage(), 0, 50)) ?><?= mb_strlen($lastMessage->getMessage()) > 50 ? '...' : '' ?>
                                    </span>
                                <?php else: ?>
                                    <span class="telegram-preview-text telegram-preview-empty">Нет сообщений</span>
                                <?php endif; ?>
                                
                                <?php if ($unreadCount > 0): ?>
                                    <span class="telegram-unread-badge"><?= $unreadCount ?></span>
                                <?php endif; ?>
                            </div>
                        </div>
                    </a>
                <?php else: // transaction chat
                    $transaction = $chatItem['data'];
                ?>
                    <?php
                    // Определяем второго участника
                    $otherUser = null;
                    $isSeller = $transaction->getSellerId() === $user->getId();
                    
                    if ($isSeller) {
                        $otherUser = User::findById($transaction->getBuyerId());
                    } else {
                        $otherUser = User::findById($transaction->getSellerId());
                    }
                    
                    // Получаем последнее сообщение
                    $messages = ChatService::getMessages($transaction->getId(), 1);
                    $lastMessage = !empty($messages) ? end($messages) : null;
                    
                    // Количество непрочитанных
                    $unreadCount = ChatService::getUnreadCount($transaction->getId(), $user->getId());
                    
                    // Статус подтверждения
                    $userConfirmed = $isSeller ? $transaction->isSellerConfirmed() : $transaction->isBuyerConfirmed();
                    $otherConfirmed = $isSeller ? $transaction->isBuyerConfirmed() : $transaction->isSellerConfirmed();
                    
                    // Определяем роль пользователя в транзакции
                    $userRole = $isSeller ? 'Продавец' : 'Покупатель';
                    $otherRole = $isSeller ? 'Покупатель' : 'Продавец';
                    ?>
                    <?php
                    // Получаем аватар второго участника
                    $avatarLetter = null;
                    $chatTitle = null;
                    if ($transaction->getCategory() === 'Активация аккаунта') {
                        $chatTitle = '🔐 Активация аккаунта';
                        $avatarLetter = '🔐';
                    } else {
                        $chatTitle = htmlspecialchars($otherUser->getFullName());
                        $avatarLetter = null; // Будет использоваться getAvatarHtml()
                    }
                    
                    // Форматируем дату последнего сообщения
                    $lastMessageDate = null;
                    if ($lastMessage) {
                        $lastMessageTime = strtotime($lastMessage->getCreatedAt());
                        $now = time();
                        $diff = $now - $lastMessageTime;
                        
                        if ($diff < 60) {
                            $lastMessageDate = 'только что';
                        } elseif ($diff < 3600) {
                            $minutes = floor($diff / 60);
                            $lastMessageDate = $minutes . ' мин назад';
                        } elseif ($diff < 86400) {
                            $hours = floor($diff / 3600);
                            $lastMessageDate = $hours . ' ч назад';
                        } elseif ($diff < 604800) {
                            $days = floor($diff / 86400);
                            $lastMessageDate = $days . ' дн назад';
                        } else {
                            $lastMessageDate = date('d.m.Y', $lastMessageTime);
                        }
                    } else {
                        $transactionTime = strtotime($transaction->getUpdatedAt() ?? $transaction->getCreatedAt());
                        $lastMessageDate = date('d.m.Y', $transactionTime);
                    }
                    
                    // Определяем автора последнего сообщения
                    $lastMessageAuthor = null;
                    if ($lastMessage) {
                        $lastMessageUser = User::findById($lastMessage->getUserId());
                        $isLastMessageOwn = $lastMessage->getUserId() === $user->getId();
                        $lastMessageAuthor = $isLastMessageOwn ? 'Вы' : htmlspecialchars($lastMessageUser->getFullName());
                    }
                    ?>
                    
                    <a href="/transactions/chat.php?id=<?= $transaction->getId() ?>" 
                       class="telegram-chat-item <?= $unreadCount > 0 ? 'telegram-chat-unread' : '' ?>" 
                       data-chat-type="transaction" 
                       data-chat-id="<?= $transaction->getId() ?>"
                       <?php if ($lastMessage): ?>data-last-message-time="<?= htmlspecialchars($lastMessage->getCreatedAt()) ?>"<?php endif; ?>>
                        <div class="telegram-chat-avatar">
                            <?php if ($transaction->getCategory() === 'Активация аккаунта'): ?>
                                <span class="telegram-avatar-icon">🔐</span>
                            <?php else: ?>
                                <?= $otherUser ? $otherUser->getAvatarHtml('small', 'telegram-chat-avatar-img') : '?' ?>
                            <?php endif; ?>
                        </div>
                        
                        <div class="telegram-chat-content">
                            <div class="telegram-chat-header">
                                <h4 class="telegram-chat-title"><?= $chatTitle ?></h4>
                                <span class="telegram-chat-time"><?= $lastMessageDate ?></span>
                            </div>
                            
                            <div class="telegram-chat-preview">
                                <?php if ($lastMessage): ?>
                                    <span class="telegram-preview-text">
                                        <?= $lastMessageAuthor ?>: <?= htmlspecialchars(mb_substr($lastMessage->getMessage(), 0, 50)) ?><?= mb_strlen($lastMessage->getMessage()) > 50 ? '...' : '' ?>
                                    </span>
                                <?php else: ?>
                                    <span class="telegram-preview-text telegram-preview-empty">Нет сообщений</span>
                                <?php endif; ?>
                                
                                <?php if ($unreadCount > 0): ?>
                                    <span class="telegram-unread-badge"><?= $unreadCount ?></span>
                                <?php endif; ?>
                            </div>
                        </div>
                    </a>
                <?php 
                    endif; // end if transaction
                endforeach; ?>
            <?php endif; ?>
        </div>
    </div>

    <!-- Правая панель: окно чата -->
    <div class="telegram-chat-window">
        <div class="telegram-chat-placeholder">
            <div class="telegram-placeholder-icon">
                <i class="fas fa-comments"></i>
            </div>
            <h3>Выберите чат</h3>
            <p>Выберите чат из списка слева, чтобы начать общение</p>
        </div>
    </div>
</div>

<script>
// Поиск деактивирован
// Все скрипты чата уже загружены через chat-all.js в base.php
</script>

<?php
$content = ob_get_clean();
include __DIR__ . '/../templates/base.php';
?>

