<?php
/**
 * Страница чата для транзакции
 */

require_once __DIR__ . '/../../src/bootstrap.php';

use OGAS\Services\Auth;
use OGAS\Models\User;
use OGAS\Models\Transaction;
use OGAS\Services\ChatService;
use OGAS\Services\BillService;
use OGAS\Core\Session;

Auth::requireAuth();
$user = Auth::user();

if (!$user) {
    Auth::logout();
    header('Location: /login.php');
    exit;
}

$transactionId = (int)($_GET['id'] ?? 0);
if ($transactionId === 0) {
    header('Location: /transactions.php?error=transaction_not_found');
    exit;
}

$transaction = Transaction::findById($transactionId);
if (!$transaction) {
    error_log(sprintf(
        '[transactions/chat.php] Транзакция не найдена: transactionId=%d, userId=%d',
        $transactionId,
        $user->getId()
    ));
    header('Location: /transactions.php?error=transaction_not_found');
    exit;
}

// Проверяем доступ к чату
$userId = $user->getId();
if (!ChatService::checkAccess($transactionId, $userId)) {
    error_log(sprintf(
        '[transactions/chat.php] Доступ запрещён: transactionId=%d, userId=%d, sellerId=%d, buyerId=%d, userEmail=%s',
        $transactionId,
        $userId,
        $transaction->getSellerId(),
        $transaction->getBuyerId(),
        $user->getEmail()
    ));
    header('Location: /transactions.php?error=access_denied');
    exit;
}

// Если это не встроенный чат (embed=1), перенаправляем на основной список чатов
$isEmbedded = isset($_GET['embed']) && $_GET['embed'] === '1';
if (!$isEmbedded) {
    header('Location: /chats.php?chat_type=transaction&chat_id=' . $transactionId);
    exit;
}

$error = '';
$success = '';

// Обработка отправки сообщения теперь через AJAX в /api/chat.php
// Старая обработка через POST убрана для избежания редиректов

// Обработка подтверждения транзакции теперь через AJAX в /api/chat.php
// Старая обработка через POST убрана для избежания редиректов

// Обработка создания векселя из чата
// Можно оставить для fallback, но лучше перевести на AJAX
// Пока оставляем для обратной совместимости, но без редиректа
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action']) && $_POST['action'] === 'create_bill') {
    // CSRF защита
    if (!\OGAS\Core\Security::checkCsrfToken()) {
        $error = 'Ошибка безопасности. Обновите страницу и попробуйте снова.';
    } else {
        $issuerId = (int)($_POST['issuer_id'] ?? 0);
        $holderId = (int)($_POST['holder_id'] ?? 0);
        $nominal = (float)($_POST['nominal'] ?? 0);
        $maturityDays = (int)($_POST['maturity_days'] ?? 30);
        $productId = (int)($_POST['product_id'] ?? 0);
        
        // Валидация полей
        if ($issuerId <= 0 || $holderId <= 0) {
            $error = 'Выберите эмитента и держателя векселя';
        } elseif ($issuerId === $holderId) {
            $error = 'Эмитент и держатель векселя не могут быть одним и тем же пользователем';
        } elseif ($nominal <= 0) {
            $error = 'Номинал векселя должен быть больше нуля';
        } elseif ($maturityDays < 1 || $maturityDays > 3650) {
            $error = 'Срок погашения должен быть от 1 до 3650 дней (максимум 10 лет)';
        } else {
            try {
                $bill = ChatService::createBillFromChat($transactionId, $issuerId, $holderId, $nominal, $maturityDays, $productId);
                $success = 'Вексель #' . $bill->getId() . ' успешно создан! PDF векселя отправлен в чат.';
                // Не делаем редирект - Long Polling обновит сообщения автоматически
                // Сообщение о создании векселя уже отправлено в чат через ChatService
            } catch (\Exception $e) {
                $error = 'Ошибка: ' . $e->getMessage();
            }
        }
    }
}

// Получаем участников транзакции
$seller = User::findById($transaction->getSellerId());
$buyer = User::findById($transaction->getBuyerId());
$isSeller = $transaction->getSellerId() === $user->getId();

// Получаем сообщения
$messages = ChatService::getMessages($transactionId);

// Отмечаем сообщения как прочитанные
ChatService::markAsRead($transactionId, $user->getId());

// Проверяем режим встраивания (для Telegram-подобного интерфейса)
$isEmbedded = isset($_GET['embed']) && $_GET['embed'] === '1';

$title = 'Чат транзакции #' . $transactionId;

if (!$isEmbedded) {
    ob_start();
}
?>
<div class="dashboard <?= $isEmbedded ? 'chat-embedded' : '' ?>">
    <?php if (!$isEmbedded): ?>
    <div class="dashboard-header">
        <h2>Чат транзакции #<?= $transactionId ?></h2>
        <div class="header-actions">
            <a href="/transactions.php?id=<?= $transactionId ?>" class="btn btn-secondary">← К транзакции</a>
        </div>
    </div>
    <?php endif; ?>

    <?php if ($error): ?>
        <div class="alert alert-error"><?= htmlspecialchars($error) ?></div>
    <?php endif; ?>

    <?php if ($success): ?>
        <div class="alert alert-success"><?= htmlspecialchars($success) ?></div>
    <?php endif; ?>

    <!-- Компактная информация о транзакции -->
    <div class="transaction-info-compact">
        <div class="transaction-info-header">
            <div class="transaction-participants">
                <span class="participant"><?= htmlspecialchars($seller->getFullName()) ?> <?= $isSeller ? '<span class="badge">Вы</span>' : '' ?></span>
                <span class="separator">↔</span>
                <span class="participant"><?= htmlspecialchars($buyer->getFullName()) ?> <?= !$isSeller ? '<span class="badge">Вы</span>' : '' ?></span>
            </div>
            <div class="transaction-status-badge">
                <?php
                $statusLabels = [
                    'pending' => 'На рассмотрении',
                    'active' => 'Активна',
                    'completed' => 'Завершена',
                    'cancelled' => 'Отменена'
                ];
                $statusClass = [
                    'pending' => 'status-pending',
                    'active' => 'status-active',
                    'completed' => 'status-completed',
                    'cancelled' => 'status-cancelled'
                ];
                $status = $transaction->getStatus();
                ?>
                <span class="status <?= $statusClass[$status] ?? '' ?>"><?= $statusLabels[$status] ?? $status ?></span>
            </div>
        </div>
        
        <!-- Статус подтверждения - компактный -->
        <div class="confirmation-status-compact">
            <div class="confirmation-items">
                <div class="confirmation-item">
                    <span class="confirmation-label">Продавец:</span>
                    <span id="sellerConfirmation" class="status <?= $transaction->isSellerConfirmed() ? 'status-active' : 'status-pending' ?>">
                        <?= $transaction->isSellerConfirmed() ? '✅' : '⏳' ?>
                    </span>
                </div>
                <div class="confirmation-item">
                    <span class="confirmation-label">Покупатель:</span>
                    <span id="buyerConfirmation" class="status <?= $transaction->isBuyerConfirmed() ? 'status-active' : 'status-pending' ?>">
                        <?= $transaction->isBuyerConfirmed() ? '✅' : '⏳' ?>
                    </span>
                </div>
            </div>
            
            <div id="confirmButtonContainer">
                <?php
                $canConfirm = false;
                $isConfirmed = false;
                if ($isSeller && !$transaction->isSellerConfirmed()) {
                    $canConfirm = true;
                } elseif (!$isSeller && !$transaction->isBuyerConfirmed()) {
                    $canConfirm = true;
                } elseif ($isSeller && $transaction->isSellerConfirmed()) {
                    $isConfirmed = true;
                } elseif (!$isSeller && $transaction->isBuyerConfirmed()) {
                    $isConfirmed = true;
                }
                ?>
                
                <?php if ($canConfirm): ?>
                    <form method="POST" action="" class="confirm-form-inline" id="confirmTransactionForm" onsubmit="return confirmTransaction(event, <?= $transactionId ?>);">
                        <?= csrf_field() ?>
                        <input type="hidden" name="action" value="confirm_transaction">
                        <button type="submit" class="btn btn-primary btn-small">✅ Подтвердить</button>
                    </form>
                <?php elseif ($isConfirmed): ?>
                    <span class="confirmation-confirmed">✅ Вы подтвердили</span>
                <?php endif; ?>
            </div>
        </div>
    </div>

    <!-- Чат -->
    <div class="chat-container">
        <!-- Кнопка создания векселя (появляется когда оба подтвердили) -->
        <?php if ($transaction->isBothConfirmed()): ?>
        <div class="chat-bill-button-wrapper">
            <button type="button" class="chat-create-bill-btn" onclick="openBillModal()">
                📄 Создать вексель
            </button>
        </div>
        <?php endif; ?>
        
        <div class="chat-messages" id="chatMessages">
            <?php if (empty($messages)): ?>
                <div class="chat-empty">
                    <div class="chat-empty-icon">💬</div>
                    <p class="chat-empty-text">Пока нет сообщений. Начните переписку!</p>
                </div>
            <?php else: ?>
                <?php foreach ($messages as $msg): ?>
                    <?php
                    $messageUser = User::findById($msg->getUserId());
                    $isCurrentUser = $msg->getUserId() === $user->getId();
                    // Аватар будет получен через getAvatarHtml()
                    ?>
                    <div class="message-item <?= $isCurrentUser ? 'message-own' : 'message-other' ?>" 
                         data-message-id="<?= $msg->getId() ?>"
                         data-created-at="<?= htmlspecialchars($msg->getCreatedAt()) ?>">
                        <div class="message-avatar"><?= $messageUser ? $messageUser->getAvatarHtml('small') : '?' ?></div>
                        <div class="message-content">
                            <div class="message-header">
                                <span class="message-author"><?= htmlspecialchars($messageUser->getFullName()) ?></span>
                                <span class="message-time" data-time="<?= htmlspecialchars($msg->getCreatedAt()) ?>">
                                    <?= date('d.m.Y H:i', strtotime($msg->getCreatedAt())) ?>
                                </span>
                            </div>
                            <div class="message-body">
                                <?php
                                $messageText = htmlspecialchars($msg->getMessage());
                                // Преобразуем переносы строк в <br>
                                $messageText = nl2br($messageText);
                                // Преобразуем URL в кликабельные ссылки
                                $messageText = preg_replace_callback(
                                    '/((https?:\/\/[^\s<]+)|(\/public\/[^\s<]+))/i',
                                    function($matches) {
                                        $url = $matches[0];
                                        // Если это ссылка на PDF векселя
                                        if (preg_match('/\/public\/api\/bill_pdf\.php\?id=(\d+)/', $url, $pdfMatches)) {
                                            $billId = $pdfMatches[1];
                                            return '<a href="' . htmlspecialchars($url) . '" target="_blank" style="color: #667eea; text-decoration: underline; font-weight: 500;">📄 PDF векселя #' . $billId . '</a>';
                                        }
                                        // Если это другой /public/ путь
                                        if (strpos($url, '/') === 0) {
                                            $displayUrl = htmlspecialchars(str_replace('/', '', $url));
                                            return '<a href="' . htmlspecialchars($url) . '" target="_blank" style="color: #667eea; text-decoration: underline;">' . $displayUrl . '</a>';
                                        }
                                        // Полный URL
                                        return '<a href="' . htmlspecialchars($url) . '" target="_blank" style="color: #667eea; text-decoration: underline;">' . htmlspecialchars($url) . '</a>';
                                    },
                                    $messageText
                                );
                                echo $messageText;
                                ?>
                            </div>
                        </div>
                    </div>
                <?php endforeach; ?>
            <?php endif; ?>
        </div>

        <!-- Форма отправки сообщения -->
        <div class="chat-input-wrapper">
            <form id="messageForm" class="chat-input-form" onsubmit="return false;">
                <div class="chat-input-container">
                    <textarea 
                        name="message" 
                        id="messageInput"
                        rows="1" 
                        placeholder="Введите сообщение... (Enter для отправки, Shift+Enter для новой строки)"
                        required
                        class="chat-textarea"
                    ></textarea>
                    <button type="submit" class="chat-send-btn" title="Отправить (Enter)">
                        <i class="fas fa-arrow-up"></i>
                    </button>
                </div>
            </form>
        </div>
    </div>

    <!-- Модальное окно создания векселя -->
    <div class="bill-modal" id="billModal">
        <div class="bill-modal-content">
            <div class="bill-modal-header">
                <h3>Создать вексель</h3>
                <button type="button" class="bill-modal-close" onclick="closeBillModal()">×</button>
            </div>
            <div class="bill-modal-body">
                <p class="text-muted" style="margin-bottom: 20px;">Оба участника подтвердили сделку. Теперь можно создать вексель.</p>
                
                <form method="POST" action="" id="billForm" onsubmit="return validateBillForm(event);">
                    <?= csrf_field() ?>
                    <input type="hidden" name="action" value="create_bill">
                    
                    <div id="billFormError" class="alert alert-error" style="display: none; margin-bottom: 15px;"></div>
                    
                    <div class="form-group">
                        <label for="issuer_id">Эмитент (кто выпускает вексель):</label>
                        <select name="issuer_id" id="issuer_id" required onchange="checkBillFormValidity()">
                            <option value="">Выберите...</option>
                            <option value="<?= $seller->getId() ?>" <?= $isSeller ? 'selected' : '' ?>>
                                <?= htmlspecialchars($seller->getFullName()) ?> (Продавец)
                            </option>
                            <option value="<?= $buyer->getId() ?>" <?= !$isSeller ? 'selected' : '' ?>>
                                <?= htmlspecialchars($buyer->getFullName()) ?> (Покупатель)
                            </option>
                        </select>
                    </div>

                    <div class="form-group">
                        <label for="holder_id">Держатель (кто получает вексель):</label>
                        <select name="holder_id" id="holder_id" required onchange="checkBillFormValidity()">
                            <option value="">Выберите...</option>
                            <option value="<?= $seller->getId() ?>">
                                <?= htmlspecialchars($seller->getFullName()) ?> (Продавец)
                            </option>
                            <option value="<?= $buyer->getId() ?>">
                                <?= htmlspecialchars($buyer->getFullName()) ?> (Покупатель)
                            </option>
                        </select>
                        <small class="form-text text-muted">Держатель должен отличаться от эмитента</small>
                    </div>

                    <div class="form-group">
                        <label for="product_select">Выберите товар/услугу (для расчета по системе дублей):</label>
                        <select name="product_id" id="product_select" onchange="calculateNominal()">
                            <option value="">Не использовать товар</option>
                            <?php
                            $userProducts = \OGAS\Models\Product::findByUserId($user->getId(), null, true);
                            foreach ($userProducts as $product):
                                $doublesPrice = $product->getDoublesPrice();
                                if ($doublesPrice > 0): // Только товары с ценой в дублях
                            ?>
                                <option value="<?= $product->getId() ?>"
                                        data-doubles-price="<?= $doublesPrice ?>"
                                        data-base-price="<?= $product->getBasePrice() ?>">
                                    <?= htmlspecialchars($product->getName()) ?>
                                    (в дублях: <?= number_format($doublesPrice, 2) ?>)
                                </option>
                            <?php
                                endif;
                            endforeach;
                            ?>
                        </select>
                        <small class="form-text text-muted">Выберите товар для автоматического расчета номинала векселя по системе дублей</small>
                    </div>

                    <div class="form-group">
                        <label for="nominal">Номинал векселя (₽):</label>
                        <input type="number" name="nominal" id="nominal"
                               step="0.01" min="0.01" max="999999999.99" required
                               placeholder="1000.00"
                               value="1000"
                               onchange="checkBillFormValidity()">
                        <small class="form-text text-muted">Минимальная сумма: 0.01 ₽</small>
                    </div>

                    <div class="form-group">
                        <label for="maturity_days">Срок погашения (дней):</label>
                        <input type="number" name="maturity_days" id="maturity_days" 
                               min="1" max="3650" required
                               placeholder="30"
                               value="30"
                               onchange="checkBillFormValidity()">
                        <small class="form-text text-muted">От 1 до 3650 дней (максимум 10 лет)</small>
                    </div>

                    <div class="form-actions">
                        <button type="button" class="btn btn-secondary" onclick="closeBillModal()">Отмена</button>
                        <button type="submit" class="btn btn-primary" id="billSubmitBtn">Создать вексель</button>
                    </div>
                </form>
            </div>
        </div>
    </div>
</div>


<script>
// Инициализация улучшенного чата
document.addEventListener('DOMContentLoaded', function() {
    // Сохраняем информацию о роли пользователя для Long Polling
    window.isSeller = <?= $isSeller ? 'true' : 'false' ?>;
    
    // Инициализация чата происходит через loadChat() в chats.js для встроенных чатов
    // Статус теперь обновляется через Long Polling в chat.js
    // Оставляем только одноразовую проверку при загрузке
    updateTransactionStatus(<?= $transactionId ?>);
    
    // Обновление при возвращении на страницу
    document.addEventListener('visibilitychange', function() {
        if (!document.hidden) {
            updateTransactionStatus(<?= $transactionId ?>);
        }
    });
});

function updateTransactionStatus(transactionId) {
    fetch(`/api/chat.php?action=get_transaction_status&transaction_id=${transactionId}`)
        .then(response => response.json())
        .then(data => {
            if (data.success) {
                // Обновляем статус продавца
                const sellerConfirmation = document.getElementById('sellerConfirmation');
                if (sellerConfirmation) {
                    if (data.seller_confirmed) {
                        sellerConfirmation.className = 'status status-active';
                        sellerConfirmation.textContent = '✅';
                    } else {
                        sellerConfirmation.className = 'status status-pending';
                        sellerConfirmation.textContent = '⏳';
                    }
                }
                
                // Обновляем статус покупателя
                const buyerConfirmation = document.getElementById('buyerConfirmation');
                if (buyerConfirmation) {
                    if (data.buyer_confirmed) {
                        buyerConfirmation.className = 'status status-active';
                        buyerConfirmation.textContent = '✅';
                    } else {
                        buyerConfirmation.className = 'status status-pending';
                        buyerConfirmation.textContent = '⏳';
                    }
                }
                
                // Обновляем кнопку подтверждения и статус
                const confirmButtonContainer = document.getElementById('confirmButtonContainer');
                if (confirmButtonContainer && !data.both_confirmed) {
                    const isCurrentUserSeller = <?= $isSeller ? 'true' : 'false' ?>;
                    const userConfirmed = isCurrentUserSeller ? data.seller_confirmed : data.buyer_confirmed;
                    
                    if (!userConfirmed && confirmButtonContainer.innerHTML.indexOf('Подтвердить') === -1) {
                        const csrfToken = window.csrfToken || '';
                        confirmButtonContainer.innerHTML = `
                            <form method="POST" action="" class="confirm-form-inline" onsubmit="return confirm('Подтвердить сделку?');">
                                <input type="hidden" name="_csrf_token" value="${csrfToken}">
                                <input type="hidden" name="action" value="confirm_transaction">
                                <button type="submit" class="btn btn-primary btn-small">✅ Подтвердить</button>
                            </form>
                        `;
                    } else if (userConfirmed && confirmButtonContainer.innerHTML.indexOf('Вы подтвердили') === -1) {
                        confirmButtonContainer.innerHTML = '<span class="confirmation-confirmed">✅ Вы подтвердили</span>';
                    }
                }
                
                // Если оба подтвердили, скрываем кнопку и показываем кнопку создания векселя
                if (data.both_confirmed) {
                    if (confirmButtonContainer) {
                        confirmButtonContainer.innerHTML = '';
                    }
                    
                    // Показываем кнопку создания векселя, если её нет
                    const billButtonWrapper = document.querySelector('.chat-bill-button-wrapper');
                    if (!billButtonWrapper) {
                        const chatContainer = document.querySelector('.chat-container');
                        if (chatContainer) {
                            const wrapper = document.createElement('div');
                            wrapper.className = 'chat-bill-button-wrapper';
                            wrapper.innerHTML = `
                                <button type="button" class="chat-create-bill-btn" onclick="openBillModal()">
                                    📄 Создать вексель
                                </button>
                            `;
                            chatContainer.insertBefore(wrapper, chatContainer.firstChild);
                        }
                    }
                }
            }
        })
        .catch(error => {
            console.error('Ошибка при обновлении статуса транзакции:', error);
        });
}

// Функция подтверждения сделки через WebSocket (с fallback на AJAX)
function confirmTransaction(event, transactionId) {
    event.preventDefault();
    
    if (!confirm('Подтвердить сделку?')) {
        return false;
    }
    
    const form = event.target;
    const button = form.querySelector('button[type="submit"]');
    const originalText = button.textContent;
    button.disabled = true;
    button.textContent = 'Подтверждение...';
    
    // Используем WebSocket, если доступен
    if (typeof window.chatWebSocket !== 'undefined' && window.chatWebSocket.isAuthenticated) {
        const sent = window.chatWebSocket.confirmTransaction(transactionId);
        
        if (sent) {
            console.log('[TransactionChat] Transaction confirm sent via WebSocket');
            
            // Подписываемся на события для обновления UI
            const confirmationHandler = function(data) {
                if (data.transaction_id === transactionId) {
                    // Обновляем статус
                    if (typeof updateTransactionStatusFromData === 'function' && data.transaction_status) {
                        updateTransactionStatusFromData(data.transaction_status);
                    } else {
                        updateTransactionStatus(transactionId);
                    }
                    
                    // Показываем уведомление
                    if (typeof Toast !== 'undefined') {
                        Toast.success(data.message || 'Сделка подтверждена!');
                    } else {
                        alert(data.message || 'Сделка подтверждена!');
                    }
                    
                    // Обновляем кнопку подтверждения
                    const confirmButtonContainer = document.getElementById('confirmButtonContainer');
                    if (confirmButtonContainer && data.transaction_status) {
                        const isCurrentUserSeller = window.isSeller || false;
                        const userConfirmed = isCurrentUserSeller ? data.transaction_status.seller_confirmed : data.transaction_status.buyer_confirmed;
                        
                        if (userConfirmed) {
                            confirmButtonContainer.innerHTML = '<span class="confirmation-confirmed">✅ Вы подтвердили</span>';
                        }
                    }
                    
                    button.disabled = false;
                    button.textContent = originalText;
                    
                    // Удаляем обработчик после использования
                    window.chatWebSocket.offMessageType('transaction_confirmed', confirmationHandler);
                }
            };
            
            window.chatWebSocket.onMessageType('transaction_confirmed', confirmationHandler);
            
            // Также обрабатываем обновление статуса
            const statusHandler = function(data) {
                if (data.transaction_id === transactionId) {
                    if (typeof updateTransactionStatusFromData === 'function' && data.transaction_status) {
                        updateTransactionStatusFromData(data.transaction_status);
                    }
                    window.chatWebSocket.offMessageType('transaction_status_updated', statusHandler);
                }
            };
            window.chatWebSocket.onMessageType('transaction_status_updated', statusHandler);
            
            // Обработка ошибок
            setTimeout(() => {
                window.chatWebSocket.onMessageType('error', function(data) {
                    if (data.message && data.message.includes('транзакци')) {
                        const errorMsg = data.message || 'Ошибка при подтверждении сделки';
                        if (typeof Toast !== 'undefined') {
                            Toast.error(errorMsg);
                        } else {
                            alert(errorMsg);
                        }
                        button.disabled = false;
                        button.textContent = originalText;
                    }
                });
            }, 100);
            
        } else {
            // Fallback на AJAX
            console.warn('[TransactionChat] WebSocket send failed, using AJAX fallback');
            confirmTransactionAjax(transactionId, button, originalText);
        }
    } else {
        // Fallback на AJAX, если WebSocket недоступен
        console.warn('[TransactionChat] WebSocket not available, using AJAX fallback');
        confirmTransactionAjax(transactionId, button, originalText);
    }
    
    return false;
}

// Fallback функция для AJAX подтверждения
function confirmTransactionAjax(transactionId, button, originalText) {
    const formData = new FormData();
    formData.append('action', 'confirm_transaction');
    formData.append('transaction_id', transactionId);
    
    fetch('/api/chat.php', {
        method: 'POST',
        body: formData
    })
    .then(response => response.json())
    .then(data => {
        if (data.success) {
            // Обновляем статус немедленно
            if (typeof updateTransactionStatusFromData === 'function') {
                updateTransactionStatusFromData(data);
            } else {
                updateTransactionStatus(transactionId);
            }
            
            // Показываем уведомление
            if (typeof Toast !== 'undefined') {
                Toast.success(data.message || 'Сделка подтверждена!');
            } else {
                alert(data.message || 'Сделка подтверждена!');
            }
            
            // Обновляем кнопку подтверждения
            const confirmButtonContainer = document.getElementById('confirmButtonContainer');
            if (confirmButtonContainer && data.seller_confirmed !== undefined) {
                const isCurrentUserSeller = window.isSeller || false;
                const userConfirmed = isCurrentUserSeller ? data.seller_confirmed : data.buyer_confirmed;
                
                if (userConfirmed) {
                    confirmButtonContainer.innerHTML = '<span class="confirmation-confirmed">✅ Вы подтвердили</span>';
                }
            }
        } else {
            const errorMsg = data.error || 'Ошибка при подтверждении сделки';
            if (typeof Toast !== 'undefined') {
                Toast.error(errorMsg);
            } else {
                alert(errorMsg);
            }
            button.disabled = false;
            button.textContent = originalText;
        }
    })
    .catch(error => {
        console.error('Ошибка при подтверждении сделки:', error);
        const errorMsg = 'Ошибка сети при подтверждении сделки';
        if (typeof Toast !== 'undefined') {
            Toast.error(errorMsg);
        } else {
            alert(errorMsg);
        }
        button.disabled = false;
        button.textContent = originalText;
    });
}

// Функции для модального окна векселя
function openBillModal() {
    const modal = document.getElementById('billModal');
    if (modal) {
        modal.classList.add('active');
        document.body.style.overflow = 'hidden';
        // Сбрасываем форму и ошибки
        const form = document.getElementById('billForm');
        if (form) {
            form.reset();
            // Восстанавливаем значения по умолчанию
            document.getElementById('nominal').value = '1000';
            document.getElementById('maturity_days').value = '30';
            // Восстанавливаем выбранного эмитента
            const isSeller = <?= $isSeller ? 'true' : 'false' ?>;
            if (isSeller) {
                document.getElementById('issuer_id').value = '<?= $seller->getId() ?>';
            } else {
                document.getElementById('issuer_id').value = '<?= $buyer->getId() ?>';
            }
        }
        hideBillFormError();
        checkBillFormValidity();
    }
}

function closeBillModal() {
    const modal = document.getElementById('billModal');
    if (modal) {
        modal.classList.remove('active');
        document.body.style.overflow = '';
        // Сбрасываем форму
        const form = document.getElementById('billForm');
        if (form) {
            form.reset();
        }
        hideBillFormError();
    }
}

function showBillFormError(message) {
    const errorDiv = document.getElementById('billFormError');
    if (errorDiv) {
        errorDiv.textContent = message;
        errorDiv.style.display = 'block';
    }
}

function hideBillFormError() {
    const errorDiv = document.getElementById('billFormError');
    if (errorDiv) {
        errorDiv.style.display = 'none';
        errorDiv.textContent = '';
    }
}

function checkBillFormValidity() {
    const issuerId = document.getElementById('issuer_id').value;
    const holderId = document.getElementById('holder_id').value;
    const nominal = parseFloat(document.getElementById('nominal').value) || 0;
    const maturityDays = parseInt(document.getElementById('maturity_days').value) || 0;
    const submitBtn = document.getElementById('billSubmitBtn');
    
    hideBillFormError();
    
    // Проверка: эмитент и держатель не могут быть одинаковыми
    if (issuerId && holderId && issuerId === holderId) {
        showBillFormError('Эмитент и держатель векселя не могут быть одним и тем же пользователем');
        if (submitBtn) {
            submitBtn.disabled = true;
        }
        return false;
    }
    
    // Проверка номинала
    if (nominal <= 0) {
        showBillFormError('Номинал векселя должен быть больше нуля');
        if (submitBtn) {
            submitBtn.disabled = true;
        }
        return false;
    }
    
    // Проверка срока погашения
    if (maturityDays < 1 || maturityDays > 3650) {
        showBillFormError('Срок погашения должен быть от 1 до 3650 дней');
        if (submitBtn) {
            submitBtn.disabled = true;
        }
        return false;
    }
    
    // Если все проверки пройдены, включаем кнопку
    if (submitBtn) {
        submitBtn.disabled = false;
    }
    return true;
}

function validateBillForm(event) {
    event.preventDefault();
    
    const issuerId = document.getElementById('issuer_id').value;
    const holderId = document.getElementById('holder_id').value;
    const nominal = parseFloat(document.getElementById('nominal').value) || 0;
    const maturityDays = parseInt(document.getElementById('maturity_days').value) || 0;
    
    // Проверки
    if (!issuerId || !holderId) {
        showBillFormError('Выберите эмитента и держателя векселя');
        return false;
    }
    
    if (issuerId === holderId) {
        showBillFormError('Эмитент и держатель векселя не могут быть одним и тем же пользователем');
        return false;
    }
    
    if (nominal <= 0) {
        showBillFormError('Номинал векселя должен быть больше нуля');
        return false;
    }
    
    if (maturityDays < 1 || maturityDays > 3650) {
        showBillFormError('Срок погашения должен быть от 1 до 3650 дней (максимум 10 лет)');
        return false;
    }
    
    // Подтверждение
    if (!confirm('Создать вексель?')) {
        return false;
    }
    
    // Отправка формы
    const form = event.target;
    const formData = new FormData(form);
    
    // Блокируем кнопку отправки
    const submitBtn = document.getElementById('billSubmitBtn');
    if (submitBtn) {
        submitBtn.disabled = true;
        submitBtn.textContent = 'Создание...';
    }
    
    // Отправляем через fetch для лучшей обработки ошибок
    fetch(form.action || window.location.href, {
        method: 'POST',
        body: formData
    })
    .then(response => response.text())
    .then(html => {
        // Проверяем, есть ли ошибка в ответе
        const parser = new DOMParser();
        const doc = parser.parseFromString(html, 'text/html');
        const errorAlert = doc.querySelector('.alert-error');
        
        if (errorAlert) {
            showBillFormError(errorAlert.textContent.trim());
            if (submitBtn) {
                submitBtn.disabled = false;
                submitBtn.textContent = 'Создать вексель';
            }
        } else {
            // Успешно - закрываем модальное окно и обновляем страницу
            closeBillModal();
            // Обновляем сообщения чата через Long Polling или перезагружаем
            if (typeof updateTransactionStatus === 'function') {
                updateTransactionStatus(<?= $transactionId ?>);
            }
            // Перезагружаем чат, если есть такая функция
            if (typeof loadChat === 'function') {
                setTimeout(() => {
                    loadChat('transaction', <?= $transactionId ?>, document.querySelector('.telegram-chat-window'));
                }, 500);
            } else {
                location.reload();
            }
        }
    })
    .catch(error => {
        console.error('Ошибка создания векселя:', error);
        showBillFormError('Произошла ошибка при создании векселя. Попробуйте снова.');
        if (submitBtn) {
            submitBtn.disabled = false;
            submitBtn.textContent = 'Создать вексель';
        }
    });
    
    return false;
}

// Функция для расчета номинала векселя по новой формуле P = C/R
function calculateNominal() {
    const productSelect = document.getElementById('product_select');
    const nominalInput = document.getElementById('nominal');

    if (productSelect.value === '') {
        // Если товар не выбран, выходим из функции
        return;
    }

    // Получаем информацию о товаре из выбранного элемента
    const selectedOption = productSelect.options[productSelect.selectedIndex];
    const doublesPrice = parseFloat(selectedOption.getAttribute('data-doubles-price'));

    if (isNaN(doublesPrice)) {
        alert('У выбранного товара не указана цена в дублях');
        return;
    }

    // Здесь нужно получить рейтинг покупателя (держателя векселя)
    // Для упрощения возьмем его из select holder_id
    const holderSelect = document.getElementById('holder_id');
    const holderId = holderSelect.value;

    if (!holderId) {
        alert('Сначала выберите держателя векселя');
        return;
    }

    // Выполняем AJAX-запрос для получения рейтинга пользователя
    fetch('/api/rating.php?user_id=' + holderId)
        .then(response => response.json())
        .then(ratingData => {
            if (ratingData.success) {
                const rating = ratingData.rating;
                const calculatedNominal = doublesPrice / rating;

                // Устанавливаем рассчитанный номинал в поле ввода
                nominalInput.value = calculatedNominal.toFixed(2);

                // Показываем пользователю, что цена была рассчитана
                alert(`Номинал векселя рассчитан по системе дублей:\nЦена в дублях: ${doublesPrice}\nРейтинг держателя: ${rating}\nРассчитанный номинал: ${calculatedNominal.toFixed(2)} ₽`);

                // Проверяем валидность формы
                checkBillFormValidity();
            } else {
                alert('Ошибка получения рейтинга пользователя: ' + ratingData.message);
            }
        })
        .catch(error => {
            console.error('Ошибка при получении рейтинга:', error);
            alert('Ошибка получения рейтинга пользователя. Попробуйте снова.');
        });
}

// Закрытие модального окна при клике вне его
document.addEventListener('DOMContentLoaded', function() {
    const modal = document.getElementById('billModal');
    if (modal) {
        modal.addEventListener('click', function(e) {
            if (e.target === modal) {
                closeBillModal();
            }
        });
    }

    // Закрытие по ESC
    document.addEventListener('keydown', function(e) {
        if (e.key === 'Escape') {
            closeBillModal();
        }
    });
});
</script>

<?php
if ($isEmbedded) {
    // В режиме встраивания выводим только содержимое без шаблона
    echo '</div>'; // Закрываем .dashboard
} else {
    // В обычном режиме используем шаблон
    // Все скрипты чата уже загружены через chat-all.js в base.php
    // $additional_js = []; // Не нужно - chat-all.js уже подключен в base.php
    $content = ob_get_clean();
    include __DIR__ . '/../../templates/base.php';
}
?>

