<?php
/**
 * Страница группового чата для заявки общины
 */

require_once __DIR__ . '/../../src/bootstrap.php';

use OGAS\Services\Auth;
use OGAS\Models\CommunityRequest;
use OGAS\Models\User;
use OGAS\Models\Transaction;
use OGAS\Services\CommunityService;
use OGAS\Services\ChatService;
use OGAS\Core\Session;

Auth::requireAuth();
$user = Auth::user();

$requestId = (int)($_GET['id'] ?? 0);
$request = CommunityRequest::findById($requestId);

if (!$request) {
    Session::flash('error', 'Заявка не найдена');
    header('Location: /community.php');
    exit;
}

// Проверяем доступ к чату
if (!ChatService::checkCommunityAccess($requestId, $user->getId())) {
    Session::flash('error', 'У вас нет доступа к этому чату');
    header('Location: /community.php');
    exit;
}

// Если это не встроенный чат (embed=1), перенаправляем на основной список чатов
$isEmbedded = isset($_GET['embed']) && $_GET['embed'] === '1';
if (!$isEmbedded) {
    header('Location: /chats.php?chat_type=community&chat_id=' . $requestId);
    exit;
}

$requester = User::findById($request->getUserId());
$guarantors = $request->getGuarantors();
$participants = $request->getChatParticipants();

// Проверяем, является ли пользователь поручителем и подтвердил ли он согласие
$isRequester = $request->getUserId() === $user->getId();
$isGuarantor = false;
$hasConfirmed = false;
$guarantorData = null;

foreach ($guarantors as $g) {
    if ((int)$g['guarantor_id'] === $user->getId()) {
        $isGuarantor = true;
        $hasConfirmed = (bool)$g['confirmed'];
        $guarantorData = $g;
        break;
    }
}

// Подтверждение согласия поручителя
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['confirm']) && $isGuarantor && !$hasConfirmed) {
    // CSRF защита
    if (!\OGAS\Core\Security::checkCsrfToken()) {
        if ($isEmbedded) {
            header('Content-Type: application/json');
            echo json_encode([
                'success' => false,
                'error' => 'Ошибка безопасности. Обновите страницу и попробуйте снова.'
            ], JSON_UNESCAPED_UNICODE);
            exit;
        } else {
            Session::flash('error', 'Ошибка безопасности. Обновите страницу и попробуйте снова.');
            header('Location: /community/chat.php?id=' . $requestId);
            exit;
        }
    }
    
    if (CommunityService::confirmGuarantor($requestId, $user->getId())) {
        // В режиме встраивания возвращаем JSON
        if ($isEmbedded) {
            header('Content-Type: application/json');
            
            // Получаем обновленную информацию о заявке
            $request = CommunityRequest::findById($requestId);
            $confirmedCount = $request ? $request->getConfirmedGuarantorsCount() : 0;
            $guarantorsCount = $request ? $request->getGuarantorsCount() : 0;
            $allConfirmed = $request ? $request->allGuarantorsConfirmed() : false;
            
            echo json_encode([
                'success' => true,
                'message' => 'Вы подтвердили своё согласие!',
                'confirmed_count' => $confirmedCount,
                'guarantors_count' => $guarantorsCount,
                'all_confirmed' => $allConfirmed
            ], JSON_UNESCAPED_UNICODE);
            exit;
        } else {
            Session::flash('success', 'Вы подтвердили своё согласие!');
            header('Location: /community/chat.php?id=' . $requestId);
            exit;
        }
    } else {
        if ($isEmbedded) {
            header('Content-Type: application/json');
            echo json_encode([
                'success' => false,
                'error' => 'Не удалось подтвердить согласие'
            ], JSON_UNESCAPED_UNICODE);
            exit;
        } else {
            Session::flash('error', 'Не удалось подтвердить согласие');
        }
    }
}

// Подтверждение транзакции общины теперь работает только через WebSocket
// POST-обработка удалена - используется window.chatWebSocket.confirmTransaction()

$error = Session::getFlash('error');
$success = Session::getFlash('success');

$messages = ChatService::getCommunityMessages($requestId);

// Получаем информацию о поручителях
$guarantorsInfo = [];
foreach ($guarantors as $g) {
    $guarantorUser = User::findById((int)$g['guarantor_id']);
    if ($guarantorUser) {
        $guarantorsInfo[] = [
            'user' => $guarantorUser,
            'bills_count' => (int)$g['bills_count'],
            'confirmed' => (bool)$g['confirmed'],
            'confirmed_at' => $g['confirmed_at'] ?? null
        ];
    }
}

$confirmedCount = $request->getConfirmedGuarantorsCount();
$guarantorsCount = $request->getGuarantorsCount();
$allConfirmed = $request->allGuarantorsConfirmed();
$isFulfilled = $request->isFulfilled();

// Получаем транзакции, связанные с общиной
$communityTransactions = Transaction::findByCommunityRequest($requestId);
$userTransactions = [];
foreach ($communityTransactions as $transaction) {
    if ($transaction->getSellerId() === $user->getId() || $transaction->getBuyerId() === $user->getId()) {
        $userTransactions[] = $transaction;
    }
}

// Формируем название чата
$chatTitleText = '🏘️ ' . htmlspecialchars($requester->getFullName());
if ($request->getProductDescription()) {
    $chatTitleText .= ' - ' . htmlspecialchars(mb_substr($request->getProductDescription(), 0, 50));
    if (mb_strlen($request->getProductDescription()) > 50) {
        $chatTitleText .= '...';
    }
} elseif ($request->getDescription()) {
    $chatTitleText .= ' - ' . htmlspecialchars(mb_substr($request->getDescription(), 0, 50));
    if (mb_strlen($request->getDescription()) > 50) {
        $chatTitleText .= '...';
    }
}
$chatTitleText .= ' (#' . $requestId . ')';

// Проверяем режим встраивания (для Telegram-подобного интерфейса)
$isEmbedded = isset($_GET['embed']) && $_GET['embed'] === '1';

$title = $chatTitleText;

if (!$isEmbedded) {
    ob_start();
}
?>
<div class="dashboard <?= $isEmbedded ? 'chat-embedded' : '' ?>">
    <?php if (!$isEmbedded): ?>
    <div class="dashboard-header">
        <h2><?= $chatTitleText ?></h2>
        <div class="header-actions">
            <a href="/community.php" class="btn btn-secondary">← Назад к заявкам</a>
        </div>
    </div>
    <?php endif; ?>
    
    <?php if ($error): ?>
        <div class="alert alert-error"><?= htmlspecialchars($error) ?></div>
    <?php endif; ?>
    
    <?php if ($success): ?>
        <div class="alert alert-success"><?= htmlspecialchars($success) ?></div>
    <?php endif; ?>
    
    <!-- Информация о заявке -->
    <div class="community-chat-info" style="background: white; border-radius: 12px; padding: 20px; margin-bottom: 20px; box-shadow: 0 2px 8px rgba(0,0,0,0.1);">
        <h3 style="margin-top: 0;">Информация о заявке</h3>
        <div style="display: grid; grid-template-columns: repeat(auto-fit, minmax(200px, 1fr)); gap: 15px; margin-bottom: 15px;">
            <div>
                <strong>Заявитель:</strong><br>
                <a href="/user.php?id=<?= $requester->getId() ?>"><?= htmlspecialchars($requester->getFullName()) ?></a>
            </div>
            <div>
                <strong>Номинал векселя:</strong><br>
                <?= number_format($request->getNominal(), 2, '.', ' ') ?> ₽
            </div>
            <div>
                <strong>Количество векселей:</strong><br>
                <?= $request->getCollectedAmount() ?> / <?= $request->getAmount() ?>
            </div>
            <div>
                <strong>Срок погашения:</strong><br>
                <?= $request->getMaturityDays() ?> дней
            </div>
        </div>
        
        <!-- Статус поручителей -->
        <div style="margin-top: 20px; padding-top: 20px; border-top: 1px solid #ddd;">
            <h4>Статус поручителей (<span id="confirmedCount"><?= $confirmedCount ?></span> / <span id="guarantorsCount"><?= $guarantorsCount ?></span> подтвердили)</h4>
            <div id="guarantorsList" style="display: grid; grid-template-columns: repeat(auto-fit, minmax(250px, 1fr)); gap: 10px; margin-top: 10px;">
                <?php foreach ($guarantorsInfo as $info): ?>
                    <div class="guarantor-item" data-guarantor-id="<?= $info['user']->getId() ?>" style="padding: 10px; background: #f8f9fa; border-radius: 8px; display: flex; align-items: center; gap: 10px;">
                        <div style="flex: 1;">
                            <strong><?= htmlspecialchars($info['user']->getFullName()) ?></strong>
                            <div style="font-size: 0.85em; color: #666;">
                                <?= $info['bills_count'] ?> векселей
                            </div>
                        </div>
                        <span class="guarantor-status" style="color: <?= $info['confirmed'] ? '#10b981' : '#f59e0b' ?>; font-weight: bold;">
                            <?= $info['confirmed'] ? '✅ Подтверждено' : '⏳ Ожидает подтверждения' ?>
                        </span>
                    </div>
                <?php endforeach; ?>
            </div>
        </div>
        
        <!-- Кнопка подтверждения для поручителя -->
        <div id="confirmGuarantorContainer">
            <?php if ($isGuarantor && !$hasConfirmed && $isFulfilled): ?>
                <div style="margin-top: 20px; padding: 15px; background: #fff3cd; border: 2px solid #ffc107; border-radius: 8px;">
                    <p style="margin: 0 0 10px 0;"><strong>⚠️ Внимание!</strong> Для завершения заявки все поручители должны подтвердить своё согласие.</p>
                    <form method="POST" style="margin: 0;" onsubmit="return handleGuarantorConfirmForm(event, <?= $requestId ?>);">
                        <button type="submit" name="confirm" value="1" class="btn btn-primary" style="background: #10b981; border: none;">
                            ✅ Подтвердить своё согласие
                        </button>
                    </form>
                </div>
            <?php endif; ?>
        </div>
        
        <!-- Статус финализации -->
        <div id="finalizationStatus">
            <?php if ($allConfirmed): ?>
                <div style="margin-top: 20px; padding: 15px; background: #d1fae5; border: 2px solid #10b981; border-radius: 8px; color: #065f46;">
                    <strong>✅ Все поручители подтвердили согласие!</strong><br>
                    Вексели и транзакции созданы. Заявка выполнена.
                </div>
            <?php elseif ($isFulfilled && !$allConfirmed): ?>
                <div style="margin-top: 20px; padding: 15px; background: #fef3c7; border: 2px solid #f59e0b; border-radius: 8px; color: #92400e;">
                    <strong>⏳ Ожидается подтверждение всех поручителей</strong><br>
                    После подтверждения всех поручителей будут созданы вексели и транзакции.
                </div>
            <?php endif; ?>
        </div>
        
        <!-- Транзакции общины -->
        <?php if (!empty($userTransactions)): ?>
            <div style="margin-top: 20px; padding-top: 20px; border-top: 1px solid #ddd;">
                <h4>Сделки общины</h4>
                <div id="communityTransactionsList" style="display: grid; grid-template-columns: repeat(auto-fit, minmax(300px, 1fr)); gap: 15px; margin-top: 10px;">
                    <?php foreach ($userTransactions as $transaction): ?>
                        <?php
                        $isSeller = $transaction->getSellerId() === $user->getId();
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
                        
                        $otherParty = $isSeller ? User::findById($transaction->getBuyerId()) : User::findById($transaction->getSellerId());
                        ?>
                        <div class="community-transaction-item" data-transaction-id="<?= $transaction->getId() ?>" style="padding: 15px; background: #f8f9fa; border-radius: 8px; border: 1px solid #ddd;">
                            <div style="margin-bottom: 10px;">
                                <strong>Сделка #<?= $transaction->getId() ?></strong>
                                <div style="font-size: 0.85em; color: #666; margin-top: 5px;">
                                    <?= $isSeller ? 'Покупатель' : 'Продавец' ?>: 
                                    <a href="/user.php?id=<?= $otherParty->getId() ?>"><?= htmlspecialchars($otherParty->getFullName()) ?></a>
                                </div>
                            </div>
                            
                            <div style="display: flex; align-items: center; gap: 10px; margin-bottom: 10px;">
                                <span style="font-size: 0.9em;">
                                    <?= $isSeller ? 'Вы (продавец)' : 'Вы (покупатель)' ?>:
                                </span>
                                <span id="transactionStatus-<?= $transaction->getId() ?>" class="status <?= $isConfirmed ? 'status-active' : 'status-pending' ?>" style="color: <?= $isConfirmed ? '#10b981' : '#f59e0b' ?>; font-weight: bold;">
                                    <?= $isConfirmed ? '✅ Подтверждено' : '⏳ Ожидает подтверждения' ?>
                                </span>
                            </div>
                            
                            <div id="transactionConfirmButton-<?= $transaction->getId() ?>">
                                <?php if ($canConfirm): ?>
                                    <form method="POST" class="confirm-form-inline" data-transaction-id="<?= $transaction->getId() ?>" style="margin: 0;">
                                        <?= csrf_field() ?>
                                        <input type="hidden" name="action" value="confirm_transaction">
                                        <input type="hidden" name="transaction_id" value="<?= $transaction->getId() ?>">
                                        <button type="submit" class="btn btn-primary btn-small" style="width: 100%;">
                                            ✅ Подтвердить сделку
                                        </button>
                                    </form>
                                <?php elseif ($isConfirmed): ?>
                                    <span class="confirmation-confirmed" style="color: #10b981; font-weight: bold;">✅ Вы подтвердили</span>
                                <?php endif; ?>
                            </div>
                        </div>
                    <?php endforeach; ?>
                </div>
            </div>
        <?php endif; ?>
    </div>
    
    <!-- Чат -->
    <div class="chat-container" style="height: calc(100vh - 500px); min-height: 400px;">
        <div class="chat-messages" id="chatMessages">
            <?php if (empty($messages)): ?>
                <div class="chat-empty">
                    <div class="chat-empty-icon">💬</div>
                    <div class="chat-empty-text">Нет сообщений. Начните общение!</div>
                </div>
            <?php else: ?>
                <?php foreach ($messages as $msg): ?>
                    <?php
                    $messageUser = User::findById($msg->getUserId());
                    $isOwn = $msg->getUserId() === $user->getId();
                    ?>
                    <div class="message-item <?= $isOwn ? 'message-own' : 'message-other' ?>" 
                         data-message-id="<?= $msg->getId() ?>"
                         data-created-at="<?= htmlspecialchars($msg->getCreatedAt()) ?>">
                        <div class="message-avatar">
                            <?= $messageUser ? $messageUser->getAvatarHtml('small') : '?' ?>
                        </div>
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
</div>

<script>
// Все скрипты чата уже загружены через chat-all.js в base.php
// Инициализируем групповой чат общины с реалтайм обновлением
document.addEventListener('DOMContentLoaded', function() {
    // Инициализация чата происходит через loadChat() в chats.js для встроенных чатов
    // Обновление статуса поручителей каждые 3 секунды
    let statusUpdateInterval = setInterval(function() {
        updateCommunityStatus(<?= $requestId ?>, <?= $user->getId() ?>, <?= $isGuarantor ? 'true' : 'false' ?>);
    }, 3000);
    
    // Обновление при возвращении на страницу
    document.addEventListener('visibilitychange', function() {
        if (!document.hidden) {
            updateCommunityStatus(<?= $requestId ?>, <?= $user->getId() ?>, <?= $isGuarantor ? 'true' : 'false' ?>);
        }
    });
    
    // Очистка интервала при уходе со страницы
    window.addEventListener('beforeunload', function() {
        if (statusUpdateInterval) {
            clearInterval(statusUpdateInterval);
        }
    });
    
    // Обработчик WebSocket для обновления статуса транзакций общины в реальном времени
    if (typeof window.chatWebSocket !== 'undefined') {
        // Обработчик обновления статуса транзакции
        window.chatWebSocket.onMessageType('transaction_status_updated', function(data) {
            if (data.transaction_id && data.transaction_status) {
                // Проверяем, связана ли транзакция с этой общиной
                const transactionItem = document.querySelector(`[data-transaction-id="${data.transaction_id}"]`);
                if (transactionItem) {
                    updateTransactionStatusInCommunityChat(data.transaction_id, data.transaction_status);
                }
            }
        });
        
        // Обработчик подтверждения транзакции
        window.chatWebSocket.onMessageType('transaction_confirmed', function(data) {
            if (data.transaction_id && data.transaction_status) {
                const transactionItem = document.querySelector(`[data-transaction-id="${data.transaction_id}"]`);
                if (transactionItem) {
                    updateTransactionStatusInCommunityChat(data.transaction_id, data.transaction_status);
                }
            }
        });
    }
});

// Глобальная функция для обработки подтверждения поручителя (используется в inline-обработчиках)
function handleGuarantorConfirmForm(event, communityRequestId) {
    event.preventDefault();
    if (!confirm('Подтвердить своё согласие быть поручителем?')) {
        return false;
    }
    
    const form = event.target;
    const formData = new FormData(form);
    
    // Используем fetch напрямую, так как handleGuarantorConfirm может быть недоступна
    fetch(`/community/chat.php?id=${communityRequestId}&embed=1`, {
        method: 'POST',
        body: formData
    })
    .then(response => {
        const contentType = response.headers.get('content-type');
        if (contentType && contentType.includes('application/json')) {
            return response.json();
        } else {
            // Если ответ HTML, перезагружаем чат
            const chatWindow = document.querySelector('.telegram-chat-window');
            if (chatWindow) {
                if (typeof loadChat === 'function') {
                    loadChat('community', communityRequestId, chatWindow);
                } else {
                    location.reload();
                }
            } else {
                location.reload();
            }
            return null;
        }
    })
    .then(data => {
        if (data && data.success) {
            // Обновляем UI
            if (typeof updateGuarantorConfirmStatus === 'function') {
                updateGuarantorConfirmStatus(communityRequestId, data);
            } else {
                // Fallback - перезагружаем страницу
                location.reload();
            }
        } else if (data && !data.success) {
            alert('Ошибка: ' + (data.error || 'Не удалось подтвердить согласие'));
        }
    })
    .catch(error => {
        console.error('Ошибка подтверждения поручителя:', error);
        alert('Ошибка подтверждения');
    });
    
    return false;
}

function updateCommunityStatus(communityRequestId, currentUserId, isGuarantor) {
    fetch(`/api/chat.php?action=get_community_status&community_request_id=${communityRequestId}`)
        .then(response => response.json())
        .then(data => {
            if (data.success) {
                // Обновляем счетчик подтверждений
                const confirmedCountEl = document.getElementById('confirmedCount');
                const guarantorsCountEl = document.getElementById('guarantorsCount');
                if (confirmedCountEl) confirmedCountEl.textContent = data.confirmed_count;
                if (guarantorsCountEl) guarantorsCountEl.textContent = data.guarantors_count;
                
                // Обновляем статус каждого поручителя
                const guarantorsList = document.getElementById('guarantorsList');
                if (guarantorsList && data.guarantors) {
                    data.guarantors.forEach(guarantor => {
                        const guarantorItem = guarantorsList.querySelector(`[data-guarantor-id="${guarantor.id}"]`);
                        if (guarantorItem) {
                            const statusEl = guarantorItem.querySelector('.guarantor-status');
                            if (statusEl) {
                                if (guarantor.confirmed) {
                                    statusEl.style.color = '#10b981';
                                    statusEl.textContent = '✅ Подтверждено';
                                } else {
                                    statusEl.style.color = '#f59e0b';
                                    statusEl.textContent = '⏳ Ожидает подтверждения';
                                }
                            }
                        }
                    });
                }
                
                // Обновляем кнопку подтверждения
                const confirmContainer = document.getElementById('confirmGuarantorContainer');
                if (confirmContainer && isGuarantor && data.is_fulfilled && !data.all_confirmed) {
                    // Проверяем, подтвердил ли текущий пользователь
                    const currentUserGuarantor = data.guarantors.find(g => g.id === currentUserId);
                    if (currentUserGuarantor && !currentUserGuarantor.confirmed && confirmContainer.innerHTML.indexOf('Подтвердить') === -1) {
                        confirmContainer.innerHTML = `
                            <div style="margin-top: 20px; padding: 15px; background: #fff3cd; border: 2px solid #ffc107; border-radius: 8px;">
                                <p style="margin: 0 0 10px 0;"><strong>⚠️ Внимание!</strong> Для завершения заявки все поручители должны подтвердить своё согласие.</p>
                                <form method="POST" class="confirm-guarantor-form" style="margin: 0;" onsubmit="return handleGuarantorConfirmForm(event, <?= $requestId ?>);">
                                    <button type="submit" name="confirm" value="1" class="btn btn-primary" style="background: #10b981; border: none;">
                                        ✅ Подтвердить своё согласие
                                    </button>
                                </form>
                            </div>
                        `;
                    } else if (currentUserGuarantor && currentUserGuarantor.confirmed) {
                        confirmContainer.innerHTML = '';
                    }
                }
                
                // Обновляем статус финализации
                const finalizationStatus = document.getElementById('finalizationStatus');
                if (finalizationStatus) {
                    if (data.all_confirmed) {
                        finalizationStatus.innerHTML = `
                            <div style="margin-top: 20px; padding: 15px; background: #d1fae5; border: 2px solid #10b981; border-radius: 8px; color: #065f46;">
                                <strong>✅ Все поручители подтвердили согласие!</strong><br>
                                Вексели и транзакции созданы. Заявка выполнена.
                            </div>
                        `;
                        const confirmContainer = document.getElementById('confirmGuarantorContainer');
                        if (confirmContainer) confirmContainer.innerHTML = '';
                    } else if (data.is_fulfilled) {
                        finalizationStatus.innerHTML = `
                            <div style="margin-top: 20px; padding: 15px; background: #fef3c7; border: 2px solid #f59e0b; border-radius: 8px; color: #92400e;">
                                <strong>⏳ Ожидается подтверждение всех поручителей</strong><br>
                                После подтверждения всех поручителей будут созданы вексели и транзакции.
                            </div>
                        `;
                    }
                }
            }
        })
        .catch(error => {
            console.error('Ошибка при обновлении статуса общины:', error);
        });
}
</script>
<?php
if ($isEmbedded) {
    // В режиме встраивания выводим только содержимое без шаблона
    echo '</div>'; // Закрываем .dashboard
} else {
    // В обычном режиме используем шаблон
    $content = ob_get_clean();
    include __DIR__ . '/../../templates/base.php';
}
?>


