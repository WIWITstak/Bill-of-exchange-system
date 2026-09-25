<?php
/**
 * Страница просмотра обращения с чатом
 */

require_once __DIR__ . '/../../src/bootstrap.php';

use OGAS\Services\Auth;
use OGAS\Services\AdminService;
use OGAS\Services\SupportService;

Auth::requireAuth();

$user = Auth::user();
$isAdmin = AdminService::check();

$ticketId = (int)($_GET['id'] ?? 0);

if (!$ticketId) {
    header('Location: /support.php');
    exit;
}

$ticket = SupportService::getTicketWithMessages($ticketId, $user->getId(), $isAdmin);

if (!$ticket) {
    header('Location: /support.php');
    exit;
}

$title = 'Обращение #' . $ticketId;

ob_start();
?>

<div class="ticket-view-container">
    <div class="ticket-view-header">
        <div class="ticket-header-left">
            <a href="/support.php" class="btn-back-link">
                <i class="fas fa-arrow-left"></i>
            </a>
            <div class="ticket-header-info">
                <h2 class="ticket-title">
                    <i class="fas fa-ticket-alt"></i>
                    Обращение #<?= $ticketId ?>
                </h2>
                <div class="ticket-meta-header">
                    <div class="ticket-status-badge status-<?= $ticket['status'] ?>">
                        <?php
                        $statusLabels = [
                            'open' => 'Открыто',
                            'in_progress' => 'В работе',
                            'resolved' => 'Решено',
                            'closed' => 'Закрыто'
                        ];
                        echo $statusLabels[$ticket['status']] ?? $ticket['status'];
                        ?>
                    </div>
                    <span class="ticket-created-date">
                        <i class="fas fa-calendar"></i>
                        <?= date('d.m.Y H:i', strtotime($ticket['created_at'])) ?>
                    </span>
                </div>
            </div>
        </div>
    </div>

    <?php
    // Получаем информацию о связанной транзакции или векселе
    $relatedTransaction = null;
    $relatedBill = null;
    
    if (!empty($ticket['related_transaction_id'])) {
        $relatedTransaction = \OGAS\Models\Transaction::findById($ticket['related_transaction_id']);
    }
    
    if (!empty($ticket['related_bill_id'])) {
        $relatedBill = \OGAS\Models\Bill::findById($ticket['related_bill_id']);
    }
    
    $priorityLabels = [
        'low' => 'Низкий',
        'medium' => 'Средний',
        'high' => 'Высокий',
        'urgent' => 'Срочный'
    ];
    ?>

    <!-- Чат на всю ширину -->
    <div class="ticket-chat-fullwidth">
        <div class="ticket-chat" id="ticketChat">
        <div class="chat-header">
            <div class="chat-header-left">
                <div class="chat-header-title">
                    <i class="fas fa-comments"></i>
                    <span>Переписка</span>
                </div>
                <div class="chat-ticket-info">
                    <div class="ticket-info-compact">
                        <div class="ticket-subject-compact">
                            <i class="fas fa-heading"></i>
                            <?= htmlspecialchars($ticket['subject']) ?>
                        </div>
                        <div class="ticket-priority-compact">
                            <span class="priority-badge priority-<?= $ticket['priority'] ?>">
                                <?= $priorityLabels[$ticket['priority']] ?? $ticket['priority'] ?>
                            </span>
                        </div>
                    </div>
                </div>
            </div>
            <div class="chat-header-right">
                <?php if ($relatedTransaction || $relatedBill): ?>
                    <button type="button" class="btn-related-object" onclick="showRelatedObjectModal()">
                        <i class="fas fa-link"></i> Связанный объект
                    </button>
                <?php endif; ?>
                <div class="chat-status">
                    <span class="status-indicator" id="wsStatus">
                        <i class="fas fa-circle"></i> <span>Подключение...</span>
                    </span>
                </div>
            </div>
        </div>
        <div class="chat-messages" id="chatMessages">
            <?php foreach ($ticket['messages'] ?? [] as $message): ?>
                <div class="message <?= $message['is_admin'] ? 'message-admin' : 'message-user' ?>" data-message-id="<?= $message['id'] ?>">
                    <div class="message-avatar">
                        <?php
                        $messageUser = \OGAS\Models\User::findById($message['user_id']);
                        if ($messageUser) {
                            echo $messageUser->getAvatarHtml('small', 'message-avatar-img');
                        } else {
                            echo '<div class="message-avatar-placeholder">' . mb_substr($message['user_name'] ?? 'П', 0, 1) . '</div>';
                        }
                        ?>
                    </div>
                    <div class="message-body">
                        <div class="message-header">
                            <span class="message-author">
                                <?= htmlspecialchars($message['user_name'] ?? 'Пользователь') ?>
                                <?php if ($message['is_admin']): ?>
                                    <span class="admin-badge"><i class="fas fa-shield-alt"></i> Поддержка</span>
                                <?php endif; ?>
                            </span>
                            <span class="message-time"><?= date('d.m.Y H:i', strtotime($message['created_at'])) ?></span>
                        </div>
                        <div class="message-content"><?= nl2br(htmlspecialchars($message['message'])) ?></div>
                    </div>
                </div>
            <?php endforeach; ?>
        </div>
        
        <?php if ($ticket['status'] !== 'closed'): ?>
            <div class="chat-input">
                <form id="messageForm" onsubmit="return sendMessage(event)">
                    <?= csrf_field() ?>
                    <input type="hidden" name="ticket_id" value="<?= $ticketId ?>">
                    <div class="input-wrapper">
                        <textarea 
                            id="messageInput" 
                            name="message" 
                            placeholder="Введите сообщение..." 
                            rows="1"
                            required
                            maxlength="10000"
                        ></textarea>
                        <button type="submit" class="btn btn-primary send-btn" id="sendBtn">
                            <i class="fas fa-paper-plane"></i>
                        </button>
                    </div>
                    <div class="input-footer">
                        <span class="char-count"><span id="charCount">0</span>/10000</span>
                    </div>
                </form>
            </div>
        <?php else: ?>
            <div class="chat-closed">
                <p><i class="fas fa-lock"></i> Обращение закрыто. Новые сообщения недоступны.</p>
            </div>
        <?php endif; ?>
        </div>
    </div>
</div>

<!-- Модальное окно связанного объекта -->
<?php if ($relatedTransaction || $relatedBill): ?>
    <?php
    // Подготовка данных для модального окна
    if ($relatedTransaction) {
        $seller = \OGAS\Models\User::findById($relatedTransaction->getSellerId());
        $buyer = \OGAS\Models\User::findById($relatedTransaction->getBuyerId());
        $bills = \OGAS\Services\TransactionService::getBillsForTransaction($relatedTransaction->getId());
        
        $categoryInfo = null;
        if ($relatedTransaction->getCategory()) {
            try {
                $categoryInfo = \OGAS\Models\Category::findByName($relatedTransaction->getCategory());
            } catch (\Exception $e) {
                // Игнорируем ошибку
            }
        }
        
        $statusLabels = [
            'pending' => ['label' => 'Ожидает', 'color' => '#f59e0b', 'bg' => '#fef3c7'],
            'active' => ['label' => 'Активна', 'color' => '#10b981', 'bg' => '#d1fae5'],
            'completed' => ['label' => 'Завершена', 'color' => '#3b82f6', 'bg' => '#dbeafe'],
            'cancelled' => ['label' => 'Отменена', 'color' => '#ef4444', 'bg' => '#fee2e2']
        ];
        $statusInfo = $statusLabels[$relatedTransaction->getStatus()] ?? ['label' => $relatedTransaction->getStatus(), 'color' => '#666', 'bg' => '#e5e7eb'];
        
        $transactionTypeLabels = [
            'barter' => 'Бартер',
            'guarantee' => 'Гарантия',
            'community' => 'Община',
            'mixed' => 'Смешанная'
        ];
    }
    
    if ($relatedBill) {
        $issuer = \OGAS\Models\User::findById($relatedBill->getIssuerId());
        $holder = \OGAS\Models\User::findById($relatedBill->getHolderId());
        
        $billStatusLabels = [
            'active' => ['label' => 'Активен', 'color' => '#10b981', 'bg' => '#d1fae5'],
            'paid' => ['label' => 'Погашен', 'color' => '#3b82f6', 'bg' => '#dbeafe'],
            'overdue' => ['label' => 'Просрочен', 'color' => '#ef4444', 'bg' => '#fee2e2'],
            'cancelled' => ['label' => 'Отменён', 'color' => '#999', 'bg' => '#f3f4f6']
        ];
        $billStatusInfo = $billStatusLabels[$relatedBill->getStatus()] ?? ['label' => $relatedBill->getStatus(), 'color' => '#666', 'bg' => '#e5e7eb'];
    }
    ?>
    <div id="relatedObjectModal" class="modal" style="display: none;">
        <div class="modal-content modal-large">
            <div class="modal-header">
                <h3><i class="fas fa-link"></i> Связанный объект</h3>
                <button type="button" class="modal-close" onclick="closeRelatedObjectModal()">&times;</button>
            </div>
            <div class="modal-body">
                <?php if ($relatedTransaction): ?>
                    <div class="related-object-content">
                        <div style="display: grid; grid-template-columns: 1fr 1fr; gap: 15px; margin-bottom: 20px;">
                            <div>
                                <div style="font-weight: 600; color: #666; font-size: 13px; margin-bottom: 5px;">ID транзакции</div>
                                <div style="font-size: 18px; font-weight: 700; color: #1e40af;">#<?= $relatedTransaction->getId() ?></div>
                            </div>
                            <div>
                                <div style="font-weight: 600; color: #666; font-size: 13px; margin-bottom: 5px;">Статус</div>
                                <span style="display: inline-block; padding: 6px 12px; border-radius: 6px; font-size: 13px; font-weight: 600; background: <?= $statusInfo['bg'] ?>; color: <?= $statusInfo['color'] ?>;">
                                    <?= $statusInfo['label'] ?>
                                </span>
                            </div>
                        </div>
                        
                        <?php if ($relatedTransaction->getDescription()): ?>
                            <div style="margin-bottom: 15px; padding: 12px; background: white; border-radius: 6px; border: 1px solid #e5e7eb;">
                                <div style="font-weight: 600; color: #666; font-size: 13px; margin-bottom: 8px;">
                                    <i class="fas fa-align-left"></i> Описание сделки
                                </div>
                                <div style="color: #333; line-height: 1.6;">
                                    <?= nl2br(htmlspecialchars($relatedTransaction->getDescription())) ?>
                                </div>
                            </div>
                        <?php endif; ?>
                        
                        <div style="display: grid; grid-template-columns: 1fr 1fr; gap: 15px; margin-bottom: 15px;">
                            <div style="padding: 12px; background: white; border-radius: 6px; border: 1px solid #e5e7eb;">
                                <div style="font-weight: 600; color: #666; font-size: 13px; margin-bottom: 8px;">
                                    <i class="fas fa-user-tie"></i> Продавец
                                </div>
                                <div style="display: flex; align-items: center; gap: 8px;">
                                    <?php if ($seller): ?>
                                        <?= $seller->getAvatarHtml('small', 'transaction-participant-avatar') ?>
                                        <div>
                                            <a href="/user.php?id=<?= $seller->getId() ?>" style="color: #3b82f6; text-decoration: none; font-weight: 600;">
                                                <?= htmlspecialchars($seller->getFullName()) ?>
                                            </a>
                                            <?php if ($seller->getEmail()): ?>
                                                <div style="font-size: 12px; color: #666; margin-top: 2px;">
                                                    <?= htmlspecialchars($seller->getEmail()) ?>
                                                </div>
                                            <?php endif; ?>
                                        </div>
                                    <?php else: ?>
                                        <span style="color: #999;">Пользователь #<?= $relatedTransaction->getSellerId() ?></span>
                                    <?php endif; ?>
                                </div>
                                <div style="margin-top: 8px; padding-top: 8px; border-top: 1px solid #f3f4f6;">
                                    <span style="font-size: 12px; color: #666;">Подтверждение: </span>
                                    <?php if ($relatedTransaction->isSellerConfirmed()): ?>
                                        <span style="color: #10b981; font-weight: 600;">✅ Подтверждено</span>
                                    <?php else: ?>
                                        <span style="color: #ef4444; font-weight: 600;">❌ Не подтверждено</span>
                                    <?php endif; ?>
                                </div>
                            </div>
                            
                            <div style="padding: 12px; background: white; border-radius: 6px; border: 1px solid #e5e7eb;">
                                <div style="font-weight: 600; color: #666; font-size: 13px; margin-bottom: 8px;">
                                    <i class="fas fa-user"></i> Покупатель
                                </div>
                                <div style="display: flex; align-items: center; gap: 8px;">
                                    <?php if ($buyer): ?>
                                        <?= $buyer->getAvatarHtml('small', 'transaction-participant-avatar') ?>
                                        <div>
                                            <a href="/user.php?id=<?= $buyer->getId() ?>" style="color: #3b82f6; text-decoration: none; font-weight: 600;">
                                                <?= htmlspecialchars($buyer->getFullName()) ?>
                                            </a>
                                            <?php if ($buyer->getEmail()): ?>
                                                <div style="font-size: 12px; color: #666; margin-top: 2px;">
                                                    <?= htmlspecialchars($buyer->getEmail()) ?>
                                                </div>
                                            <?php endif; ?>
                                        </div>
                                    <?php else: ?>
                                        <span style="color: #999;">Пользователь #<?= $relatedTransaction->getBuyerId() ?></span>
                                    <?php endif; ?>
                                </div>
                                <div style="margin-top: 8px; padding-top: 8px; border-top: 1px solid #f3f4f6;">
                                    <span style="font-size: 12px; color: #666;">Подтверждение: </span>
                                    <?php if ($relatedTransaction->isBuyerConfirmed()): ?>
                                        <span style="color: #10b981; font-weight: 600;">✅ Подтверждено</span>
                                    <?php else: ?>
                                        <span style="color: #ef4444; font-weight: 600;">❌ Не подтверждено</span>
                                    <?php endif; ?>
                                </div>
                            </div>
                        </div>
                        
                        <div style="display: grid; grid-template-columns: repeat(auto-fit, minmax(150px, 1fr)); gap: 12px; margin-bottom: 15px;">
                            <?php if ($relatedTransaction->getCategory()): ?>
                                <div style="padding: 10px; background: white; border-radius: 6px; border: 1px solid #e5e7eb;">
                                    <div style="font-weight: 600; color: #666; font-size: 12px; margin-bottom: 4px;">Категория</div>
                                    <div style="display: flex; align-items: center; gap: 6px;">
                                        <?php if ($categoryInfo && $categoryInfo->getIcon()): ?>
                                            <span><?= htmlspecialchars($categoryInfo->getIcon()) ?></span>
                                        <?php endif; ?>
                                        <span style="color: #333; font-weight: 500;">
                                            <?= htmlspecialchars($categoryInfo ? $categoryInfo->getName() : $relatedTransaction->getCategory()) ?>
                                        </span>
                                    </div>
                                </div>
                            <?php endif; ?>
                            
                            <div style="padding: 10px; background: white; border-radius: 6px; border: 1px solid #e5e7eb;">
                                <div style="font-weight: 600; color: #666; font-size: 12px; margin-bottom: 4px;">Тип сделки</div>
                                <div style="color: #333; font-weight: 500;">
                                    <?= $transactionTypeLabels[$relatedTransaction->getTransactionType()] ?? $relatedTransaction->getTransactionType() ?>
                                </div>
                            </div>
                            
                            <div style="padding: 10px; background: white; border-radius: 6px; border: 1px solid #e5e7eb;">
                                <div style="font-weight: 600; color: #666; font-size: 12px; margin-bottom: 4px;">Дата создания</div>
                                <div style="color: #333; font-weight: 500; font-size: 13px;">
                                    <?= $relatedTransaction->getCreatedAt() ? date('d.m.Y H:i', strtotime($relatedTransaction->getCreatedAt())) : 'Не указана' ?>
                                </div>
                            </div>
                            
                            <?php if ($relatedTransaction->getUpdatedAt() && $relatedTransaction->getUpdatedAt() !== $relatedTransaction->getCreatedAt()): ?>
                                <div style="padding: 10px; background: white; border-radius: 6px; border: 1px solid #e5e7eb;">
                                    <div style="font-weight: 600; color: #666; font-size: 12px; margin-bottom: 4px;">Последнее обновление</div>
                                    <div style="color: #333; font-weight: 500; font-size: 13px;">
                                        <?= date('d.m.Y H:i', strtotime($relatedTransaction->getUpdatedAt())) ?>
                                    </div>
                                </div>
                            <?php endif; ?>
                        </div>
                        
                        <?php if (!empty($bills)): ?>
                            <div style="margin-top: 15px; padding: 15px; background: white; border-radius: 6px; border: 1px solid #e5e7eb;">
                                <div style="font-weight: 600; color: #1e40af; font-size: 15px; margin-bottom: 12px; display: flex; align-items: center; gap: 8px;">
                                    <i class="fas fa-file-invoice"></i> Связанные вексели (<?= count($bills) ?>)
                                </div>
                                <div style="display: flex; flex-direction: column; gap: 8px;">
                                    <?php foreach ($bills as $bill): 
                                        $billIssuer = \OGAS\Models\User::findById($bill->getIssuerId());
                                        $billHolder = \OGAS\Models\User::findById($bill->getHolderId());
                                        
                                        $billStatusLabels = [
                                            'active' => ['label' => 'Активен', 'color' => '#10b981'],
                                            'paid' => ['label' => 'Погашен', 'color' => '#3b82f6'],
                                            'overdue' => ['label' => 'Просрочен', 'color' => '#ef4444'],
                                            'cancelled' => ['label' => 'Отменён', 'color' => '#999']
                                        ];
                                        $billStatusInfo = $billStatusLabels[$bill->getStatus()] ?? ['label' => $bill->getStatus(), 'color' => '#666'];
                                    ?>
                                        <div style="display: flex; justify-content: space-between; align-items: center; padding: 10px; background: #f9fafb; border-radius: 4px;">
                                            <div style="flex: 1;">
                                                <div style="font-weight: 600; color: #333; margin-bottom: 4px;">
                                                    Вексель #<?= $bill->getId() ?>
                                                </div>
                                                <div style="font-size: 13px; color: #666;">
                                                    <?= htmlspecialchars($billIssuer ? $billIssuer->getFullName() : 'Пользователь #' . $bill->getIssuerId()) ?>
                                                    → <?= htmlspecialchars($billHolder ? $billHolder->getFullName() : 'Пользователь #' . $bill->getHolderId()) ?>
                                                </div>
                                            </div>
                                            <div style="text-align: right; margin-left: 15px;">
                                                <div style="font-weight: 700; color: #1e40af; font-size: 16px;">
                                                    <?= number_format($bill->getNominal(), 2, '.', ' ') ?> ₽
                                                </div>
                                                <div style="font-size: 12px; color: <?= $billStatusInfo['color'] ?>; font-weight: 600;">
                                                    <?= $billStatusInfo['label'] ?>
                                                </div>
                                                <div style="font-size: 11px; color: #666; margin-top: 2px;">
                                                    До <?= date('d.m.Y', strtotime($bill->getMaturityDate())) ?>
                                                </div>
                                            </div>
                                        </div>
                                    <?php endforeach; ?>
                                </div>
                            </div>
                        <?php elseif ($relatedTransaction->isBothConfirmed()): ?>
                            <div style="margin-top: 15px; padding: 12px; background: #fef3c7; border-radius: 6px; border-left: 3px solid #f59e0b;">
                                <div style="display: flex; align-items: center; gap: 8px; color: #92400e;">
                                    <i class="fas fa-info-circle"></i>
                                    <span style="font-size: 13px;">
                                        Оба участника подтвердили сделку, но вексели ещё не созданы.
                                    </span>
                                </div>
                            </div>
                        <?php endif; ?>
                        
                        <?php if ($isAdmin && $relatedTransaction): ?>
                            <div style="margin-top: 20px; padding: 20px; background: #fef3c7; border-radius: 8px; border-left: 4px solid #f59e0b;">
                                <h3 style="margin-top: 0; margin-bottom: 15px; color: #92400e; display: flex; align-items: center; gap: 8px;">
                                    <i class="fas fa-tools"></i> Панель исправления
                                </h3>
                                
                                <div id="adminFixPanel">
                                    <div class="admin-action-group">
                                        <h4 style="margin-top: 0; margin-bottom: 10px; color: #92400e;">Исправить транзакцию</h4>
                                        <p style="color: #666; font-size: 14px; margin-bottom: 15px;">
                                            Если сделка не подтвердилась автоматически, вы можете принудительно подтвердить её.
                                        </p>
                                        <div style="display: flex; gap: 10px; flex-wrap: wrap; margin-bottom: 15px;">
                                            <label style="display: flex; align-items: center; gap: 5px; cursor: pointer;">
                                                <input type="checkbox" id="confirmSeller" checked>
                                                Подтвердить продавца
                                            </label>
                                            <label style="display: flex; align-items: center; gap: 5px; cursor: pointer;">
                                                <input type="checkbox" id="confirmBuyer" checked>
                                                Подтвердить покупателя
                                            </label>
                                        </div>
                                        <button 
                                            class="btn btn-primary" 
                                            onclick="adminForceConfirmTransaction()"
                                            style="margin-top: 10px;"
                                        >
                                            <i class="fas fa-check-circle"></i> Принудительно подтвердить сделку
                                        </button>
                                    </div>
                                    
                                    <?php if (!$relatedTransaction->isBothConfirmed()): ?>
                                        <div class="admin-action-group" style="margin-top: 20px; padding-top: 20px; border-top: 1px solid #e5e7eb;">
                                            <h4 style="margin-top: 0; margin-bottom: 10px; color: #92400e;">Создать вексель вручную</h4>
                                            <p style="color: #666; font-size: 14px; margin-bottom: 15px;">
                                                Если вексель не создался автоматически, вы можете создать его вручную.
                                            </p>
                                            <form id="createBillForm" onsubmit="return adminForceCreateBill(event)">
                                                <?= csrf_field() ?>
                                                <input type="hidden" id="ticketId" value="<?= $ticketId ?>">
                                                <input type="hidden" id="transactionId" value="<?= $relatedTransaction->getId() ?>">
                                                
                                                <div style="display: grid; grid-template-columns: 1fr 1fr; gap: 15px; margin-bottom: 15px;">
                                                    <div>
                                                        <label style="display: block; margin-bottom: 5px; font-weight: 600; color: #666;">Выпустил (issuer):</label>
                                                        <select id="billIssuerId" class="form-control" required>
                                                            <option value="<?= $relatedTransaction->getSellerId() ?>">
                                                                <?= htmlspecialchars($seller ? $seller->getFullName() : 'Продавец') ?>
                                                            </option>
                                                            <option value="<?= $relatedTransaction->getBuyerId() ?>">
                                                                <?= htmlspecialchars($buyer ? $buyer->getFullName() : 'Покупатель') ?>
                                                            </option>
                                                        </select>
                                                    </div>
                                                    <div>
                                                        <label style="display: block; margin-bottom: 5px; font-weight: 600; color: #666;">Получил (holder):</label>
                                                        <select id="billHolderId" class="form-control" required>
                                                            <option value="<?= $relatedTransaction->getBuyerId() ?>">
                                                                <?= htmlspecialchars($buyer ? $buyer->getFullName() : 'Покупатель') ?>
                                                            </option>
                                                            <option value="<?= $relatedTransaction->getSellerId() ?>">
                                                                <?= htmlspecialchars($seller ? $seller->getFullName() : 'Продавец') ?>
                                                            </option>
                                                        </select>
                                                    </div>
                                                </div>
                                                
                                                <div style="display: grid; grid-template-columns: 1fr 1fr; gap: 15px; margin-bottom: 15px;">
                                                    <div>
                                                        <label style="display: block; margin-bottom: 5px; font-weight: 600; color: #666;">Номинал (₽):</label>
                                                        <input type="number" id="billNominal" class="form-control" step="0.01" min="0.01" required>
                                                    </div>
                                                    <div>
                                                        <label style="display: block; margin-bottom: 5px; font-weight: 600; color: #666;">Срок погашения (дней):</label>
                                                        <input type="number" id="billMaturityDays" class="form-control" min="1" value="30" required>
                                                    </div>
                                                </div>
                                                
                                                <button type="submit" class="btn btn-primary">
                                                    <i class="fas fa-file-invoice"></i> Создать вексель
                                                </button>
                                            </form>
                                        </div>
                                    <?php endif; ?>
                                </div>
                            </div>
                        <?php endif; ?>
                    </div>
                <?php elseif ($relatedBill): ?>
                    <div class="related-object-content">
                        <div style="display: grid; grid-template-columns: 1fr 1fr; gap: 15px; margin-bottom: 20px;">
                            <div>
                                <div style="font-weight: 600; color: #666; font-size: 13px; margin-bottom: 5px;">ID векселя</div>
                                <div style="font-size: 18px; font-weight: 700; color: #1e40af;">#<?= $relatedBill->getId() ?></div>
                            </div>
                            <div>
                                <div style="font-weight: 600; color: #666; font-size: 13px; margin-bottom: 5px;">Статус</div>
                                <span style="display: inline-block; padding: 6px 12px; border-radius: 6px; font-size: 13px; font-weight: 600; background: <?= $billStatusInfo['bg'] ?>; color: <?= $billStatusInfo['color'] ?>;">
                                    <?= $billStatusInfo['label'] ?>
                                </span>
                            </div>
                        </div>
                        
                        <div style="display: grid; grid-template-columns: 1fr 1fr; gap: 15px; margin-bottom: 15px;">
                            <div style="padding: 12px; background: white; border-radius: 6px; border: 1px solid #e5e7eb;">
                                <div style="font-weight: 600; color: #666; font-size: 13px; margin-bottom: 8px;">
                                    <i class="fas fa-hand-holding-usd"></i> Выпустил (Issuer)
                                </div>
                                <div style="display: flex; align-items: center; gap: 8px;">
                                    <?php if ($issuer): ?>
                                        <?= $issuer->getAvatarHtml('small', 'transaction-participant-avatar') ?>
                                        <div>
                                            <a href="/user.php?id=<?= $issuer->getId() ?>" style="color: #3b82f6; text-decoration: none; font-weight: 600;">
                                                <?= htmlspecialchars($issuer->getFullName()) ?>
                                            </a>
                                            <?php if ($issuer->getEmail()): ?>
                                                <div style="font-size: 12px; color: #666; margin-top: 2px;">
                                                    <?= htmlspecialchars($issuer->getEmail()) ?>
                                                </div>
                                            <?php endif; ?>
                                        </div>
                                    <?php else: ?>
                                        <span style="color: #999;">Пользователь #<?= $relatedBill->getIssuerId() ?></span>
                                    <?php endif; ?>
                                </div>
                            </div>
                            
                            <div style="padding: 12px; background: white; border-radius: 6px; border: 1px solid #e5e7eb;">
                                <div style="font-weight: 600; color: #666; font-size: 13px; margin-bottom: 8px;">
                                    <i class="fas fa-user-check"></i> Получил (Holder)
                                </div>
                                <div style="display: flex; align-items: center; gap: 8px;">
                                    <?php if ($holder): ?>
                                        <?= $holder->getAvatarHtml('small', 'transaction-participant-avatar') ?>
                                        <div>
                                            <a href="/user.php?id=<?= $holder->getId() ?>" style="color: #3b82f6; text-decoration: none; font-weight: 600;">
                                                <?= htmlspecialchars($holder->getFullName()) ?>
                                            </a>
                                            <?php if ($holder->getEmail()): ?>
                                                <div style="font-size: 12px; color: #666; margin-top: 2px;">
                                                    <?= htmlspecialchars($holder->getEmail()) ?>
                                                </div>
                                            <?php endif; ?>
                                        </div>
                                    <?php else: ?>
                                        <span style="color: #999;">Пользователь #<?= $relatedBill->getHolderId() ?></span>
                                    <?php endif; ?>
                                </div>
                            </div>
                        </div>
                        
                        <div style="display: grid; grid-template-columns: repeat(auto-fit, minmax(150px, 1fr)); gap: 12px; margin-bottom: 15px;">
                            <div style="padding: 12px; background: white; border-radius: 6px; border: 1px solid #e5e7eb;">
                                <div style="font-weight: 600; color: #666; font-size: 13px; margin-bottom: 5px;">
                                    <i class="fas fa-ruble-sign"></i> Номинал
                                </div>
                                <div style="font-size: 20px; font-weight: 700; color: #10b981;">
                                    <?= number_format($relatedBill->getNominal(), 2, '.', ' ') ?> ₽
                                </div>
                            </div>
                            
                            <div style="padding: 12px; background: white; border-radius: 6px; border: 1px solid #e5e7eb;">
                                <div style="font-weight: 600; color: #666; font-size: 13px; margin-bottom: 5px;">
                                    <i class="fas fa-calendar-plus"></i> Дата выпуска
                                </div>
                                <div style="color: #333; font-weight: 500; font-size: 13px;">
                                    <?= date('d.m.Y H:i', strtotime($relatedBill->getIssueDate())) ?>
                                </div>
                            </div>
                            
                            <div style="padding: 12px; background: white; border-radius: 6px; border: 1px solid #e5e7eb;">
                                <div style="font-weight: 600; color: #666; font-size: 13px; margin-bottom: 5px;">
                                    <i class="fas fa-calendar-check"></i> Срок погашения
                                </div>
                                <div style="color: #333; font-weight: 500; font-size: 13px;">
                                    <?= date('d.m.Y', strtotime($relatedBill->getMaturityDate())) ?>
                                </div>
                                <?php if ($relatedBill->isOverdue() || strtotime($relatedBill->getMaturityDate()) < time()): ?>
                                    <div style="font-size: 11px; color: #ef4444; margin-top: 4px; font-weight: 600;">
                                        ⚠️ Просрочен
                                    </div>
                                <?php endif; ?>
                            </div>
                            
                            <?php if ($relatedBill->getPaymentDate()): ?>
                                <div style="padding: 12px; background: white; border-radius: 6px; border: 1px solid #e5e7eb;">
                                    <div style="font-weight: 600; color: #666; font-size: 13px; margin-bottom: 5px;">
                                        <i class="fas fa-check-circle"></i> Дата погашения
                                    </div>
                                    <div style="color: #333; font-weight: 500; font-size: 13px;">
                                        <?= date('d.m.Y H:i', strtotime($relatedBill->getPaymentDate())) ?>
                                    </div>
                                </div>
                            <?php endif; ?>
                        </div>
                        
                        <?php if ($relatedBill->getFilePath()): ?>
                            <div style="margin-top: 15px; padding: 12px; background: white; border-radius: 6px; border: 1px solid #e5e7eb;">
                                <div style="font-weight: 600; color: #666; font-size: 13px; margin-bottom: 8px;">
                                    <i class="fas fa-file-pdf"></i> PDF документ
                                </div>
                                <a href="/api/bill_pdf.php?id=<?= $relatedBill->getId() ?>" 
                                   target="_blank" 
                                   class="btn btn-small" 
                                   style="background: #ef4444; color: white; text-decoration: none; display: inline-flex; align-items: center; gap: 6px;">
                                    <i class="fas fa-download"></i> Скачать PDF
                                </a>
                            </div>
                        <?php endif; ?>
                    </div>
                <?php endif; ?>
            </div>
        </div>
    </div>
<?php endif; ?>


<style>
.ticket-view-container {
    max-width: 1400px;
    margin: 0 auto;
    padding: 20px;
}

.ticket-layout-compact {
    display: grid;
    grid-template-columns: 380px 1fr;
    gap: 20px;
    align-items: start;
}

.ticket-info-column {
    display: flex;
    flex-direction: column;
    gap: 16px;
    position: sticky;
    top: 20px;
    max-height: calc(100vh - 40px);
    overflow-y: auto;
}

.ticket-chat-column {
    min-height: 600px;
}

/* Чат на всю ширину */
.ticket-chat-fullwidth {
    width: 100%;
    max-width: 800px;
    margin: 0 auto;
    min-height: 400px;
}

/* Заголовок чата с информацией о тикете */
.chat-header {
    padding: 12px 16px;
    border-bottom: 1px solid var(--border-color);
    background: var(--bg-primary);
    border-radius: var(--radius-lg) var(--radius-lg) 0 0;
    display: flex;
    align-items: center;
    justify-content: space-between;
    gap: 16px;
}

.chat-header-left {
    display: flex;
    align-items: center;
    gap: 16px;
    flex: 1;
    min-width: 0;
}

.chat-header-right {
    display: flex;
    align-items: center;
    gap: 12px;
    flex-shrink: 0;
}

.chat-ticket-info {
    display: flex;
    align-items: center;
    gap: 12px;
    flex: 1;
    min-width: 0;
}

.ticket-info-compact {
    display: flex;
    align-items: center;
    gap: 12px;
    flex: 1;
    min-width: 0;
}

.ticket-subject-compact {
    display: flex;
    align-items: center;
    gap: 6px;
    color: var(--text-primary);
    font-weight: 600;
    font-size: 0.95em;
    flex: 1;
    min-width: 0;
}

.ticket-subject-compact i {
    color: var(--color-primary);
    font-size: 0.85em;
    flex-shrink: 0;
}

.ticket-priority-compact {
    flex-shrink: 0;
}

.btn-related-object {
    display: inline-flex;
    align-items: center;
    gap: 6px;
    padding: 8px 14px;
    background: var(--color-primary);
    color: white;
    border: none;
    border-radius: var(--radius-md);
    font-size: 0.875em;
    font-weight: 500;
    cursor: pointer;
    transition: all var(--transition-base);
    white-space: nowrap;
}

.btn-related-object:hover {
    background: var(--color-primary-dark);
    transform: translateY(-1px);
    box-shadow: 0 2px 8px rgba(59, 130, 246, 0.3);
}

/* Модальные окна */
.modal-large {
    max-width: 1200px;
}

.ticket-view-header {
    margin-bottom: 24px;
    padding-bottom: 16px;
    border-bottom: 2px solid var(--border-color-light);
}

.ticket-header-left {
    display: flex;
    align-items: flex-start;
    gap: 16px;
}

.btn-back-link {
    display: flex;
    align-items: center;
    justify-content: center;
    width: 40px;
    height: 40px;
    border-radius: var(--radius-md);
    background: var(--bg-secondary);
    color: var(--text-primary);
    text-decoration: none;
    transition: all var(--transition-base);
    flex-shrink: 0;
    border: 1px solid var(--border-color);
}

.btn-back-link:hover {
    background: var(--color-primary);
    color: white;
    transform: translateX(-2px);
    box-shadow: var(--shadow-sm);
}

.ticket-header-info {
    flex: 1;
    min-width: 0;
}

.ticket-title {
    margin: 0 0 8px 0;
    font-size: 1.5em;
    font-weight: 700;
    color: var(--text-primary);
    display: flex;
    align-items: center;
    gap: 10px;
}

.ticket-title i {
    color: var(--color-primary);
    font-size: 0.9em;
}

.ticket-meta-header {
    display: flex;
    align-items: center;
    gap: 12px;
    flex-wrap: wrap;
}

.ticket-status-badge {
    padding: 6px 14px;
    border-radius: var(--radius-lg);
    font-size: 0.85em;
    font-weight: 600;
    display: inline-flex;
    align-items: center;
    gap: 6px;
}

.status-open {
    background: #fef3c7;
    color: #92400e;
}

.status-in_progress {
    background: #dbeafe;
    color: #1e40af;
}

.status-resolved {
    background: #d1fae5;
    color: #065f46;
}

.status-closed {
    background: #e5e7eb;
    color: #374151;
}

.ticket-created-date {
    display: inline-flex;
    align-items: center;
    gap: 6px;
    color: var(--text-secondary);
    font-size: 0.875em;
}

.ticket-created-date i {
    font-size: 0.85em;
}

.ticket-info-card {
    background: var(--bg-primary);
    border-radius: var(--radius-xl);
    box-shadow: var(--shadow-md);
    border: 1px solid var(--border-color);
    transition: all var(--transition-base);
    position: relative;
    overflow: hidden;
}

.ticket-info-card::before {
    content: '';
    position: absolute;
    top: 0;
    left: 0;
    width: 4px;
    height: 100%;
    background: var(--gradient-primary-vertical);
    opacity: 0;
    transition: opacity var(--transition-base);
}

.ticket-info-card:hover::before {
    opacity: 1;
}

.ticket-info-card.card-main {
    padding: 16px;
}

.card-header-icon {
    display: flex;
    align-items: center;
    gap: 8px;
    margin-bottom: 12px;
    color: var(--color-primary);
    font-size: 1.1em;
}

.card-content {
    display: flex;
    flex-direction: column;
    gap: 0;
}

.info-row {
    display: flex;
    padding: 10px 0;
    border-bottom: 1px solid var(--border-color-light);
    font-size: 0.9em;
    align-items: flex-start;
    gap: 12px;
}

.info-row:last-child {
    border-bottom: none;
}

.info-label {
    font-weight: 600;
    width: 120px;
    color: var(--text-secondary);
    flex-shrink: 0;
    font-size: 0.875em;
    display: flex;
    align-items: center;
    gap: 6px;
}

.info-label i {
    font-size: 0.85em;
    width: 16px;
    text-align: center;
}

.info-value {
    flex: 1;
    color: var(--text-primary);
    font-size: 0.875em;
    word-wrap: break-word;
    min-width: 0;
}

.info-subject {
    font-weight: 600;
    color: var(--text-primary);
}

.priority-badge {
    display: inline-flex;
    align-items: center;
    gap: 4px;
    padding: 4px 10px;
    border-radius: var(--radius-md);
    font-size: 0.85em;
    font-weight: 600;
    white-space: nowrap;
}

.priority-low {
    background: #e5e7eb;
    color: #374151;
}

.priority-medium {
    background: #dbeafe;
    color: #1e40af;
}

.priority-high {
    background: #fef3c7;
    color: #92400e;
}

.priority-urgent {
    background: #fee2e2;
    color: #991b1b;
}

.ticket-chat {
    background: var(--bg-primary);
    border-radius: var(--radius-lg);
    box-shadow: var(--shadow-md);
    border: 1px solid var(--border-color);
    display: flex;
    flex-direction: column;
    height: calc(100vh - 200px);
    min-height: 400px;
    max-height: 600px;
    overflow: hidden;
}

.chat-header {
    padding: 14px 20px;
    border-bottom: 1px solid var(--border-color);
    background: var(--bg-primary);
    border-radius: var(--radius-lg) var(--radius-lg) 0 0;
    display: flex;
    align-items: center;
    justify-content: space-between;
}

.chat-header-title {
    font-weight: 600;
    color: var(--text-primary);
    font-size: 0.95em;
    display: flex;
    align-items: center;
    gap: 8px;
}

.chat-header-title i {
    color: var(--color-primary);
}

.chat-status {
    display: flex;
    align-items: center;
    gap: 8px;
    font-size: 0.8em;
    color: var(--text-secondary);
}

.status-indicator {
    display: inline-flex;
    align-items: center;
    gap: 6px;
}

.status-indicator i {
    font-size: 8px;
    animation: pulse 2s infinite;
    color: #9ca3af;
}

.status-indicator.connected i {
    color: #10b981;
    animation: none;
}

.status-indicator.disconnected i {
    color: #ef4444;
    animation: none;
}

@keyframes pulse {
    0%, 100% { opacity: 1; }
    50% { opacity: 0.5; }
}

.chat-messages {
    flex: 1;
    overflow-y: auto;
    padding: 12px;
    display: flex;
    flex-direction: column;
    gap: 10px;
    background: var(--bg-secondary);
}

.message {
    display: flex;
    gap: 8px;
    max-width: 75%;
    animation: messageSlideIn 0.3s ease-out;
}

.message-user {
    align-self: flex-start;
}

.message-admin {
    align-self: flex-end;
    flex-direction: row-reverse;
}

@keyframes messageSlideIn {
    from {
        opacity: 0;
        transform: translateY(10px);
    }
    to {
        opacity: 1;
        transform: translateY(0);
    }
}

.message-avatar {
    flex-shrink: 0;
    width: 28px;
    height: 28px;
    border-radius: 50%;
    overflow: hidden;
}

.message-avatar-img {
    width: 100%;
    height: 100%;
    object-fit: cover;
}

.message-avatar-placeholder {
    width: 100%;
    height: 100%;
    background: linear-gradient(135deg, #667eea 0%, #764ba2 100%);
    color: white;
    display: flex;
    align-items: center;
    justify-content: center;
    font-weight: 600;
    font-size: 14px;
}

.message-body {
    flex: 1;
    min-width: 0;
}

.message-header {
    display: flex;
    justify-content: space-between;
    align-items: center;
    margin-bottom: 4px;
    font-size: 11px;
    gap: 8px;
}

.message-author {
    font-weight: 600;
    color: #374151;
    display: flex;
    align-items: center;
    gap: 6px;
}

.admin-badge {
    background: #3b82f6;
    color: white;
    padding: 2px 6px;
    border-radius: 4px;
    font-size: 10px;
    font-weight: 500;
}

.message-time {
    color: #9ca3af;
    white-space: nowrap;
}

.message-content {
    background: white;
    padding: 8px 12px;
    border-radius: 10px;
    color: #1f2937;
    line-height: 1.4;
    word-wrap: break-word;
    box-shadow: 0 2px 4px rgba(0,0,0,0.08);
    font-size: 0.85em;
    transition: all var(--transition-base);
}

.message-user .message-content {
    background: #f3f4f6;
    border-bottom-left-radius: 4px;
    border: 1px solid #e5e7eb;
}

.message-admin .message-content {
    background: linear-gradient(135deg, #3b82f6 0%, #2563eb 100%);
    color: white;
    border-bottom-right-radius: 4px;
    box-shadow: 0 2px 8px rgba(59, 130, 246, 0.3);
}

.chat-input {
    padding: 12px 16px;
    border-top: 1px solid var(--border-color);
    background: var(--bg-primary);
}

.input-wrapper {
    display: flex;
    gap: 10px;
    align-items: flex-end;
}

.chat-input textarea {
    flex: 1;
    padding: 12px 16px;
    border: 2px solid #e5e7eb;
    border-radius: 12px;
    resize: none;
    font-family: inherit;
    font-size: 14px;
    line-height: 1.5;
    transition: border-color 0.2s;
    max-height: 120px;
    overflow-y: auto;
}

.chat-input textarea:focus {
    outline: none;
    border-color: #3b82f6;
}

.send-btn {
    width: 44px;
    height: 44px;
    border-radius: 12px;
    padding: 0;
    display: flex;
    align-items: center;
    justify-content: center;
    flex-shrink: 0;
    transition: transform 0.2s;
}

.send-btn:hover {
    transform: scale(1.05);
}

.send-btn:active {
    transform: scale(0.95);
}

.input-footer {
    display: flex;
    justify-content: flex-end;
    margin-top: 6px;
}

.char-count {
    font-size: 11px;
    color: #9ca3af;
}

.chat-closed {
    padding: 20px;
    text-align: center;
    color: #666;
    border-top: 1px solid #e5e7eb;
    background: #f9fafb;
}

.chat-closed i {
    margin-right: 8px;
    color: #9ca3af;
}

.transaction-participant-avatar {
    width: 40px !important;
    height: 40px !important;
    border-radius: 50% !important;
    object-fit: cover;
    border: 2px solid #e5e7eb;
    flex-shrink: 0;
}

.transaction-participant-avatar img {
    width: 100%;
    height: 100%;
    object-fit: cover;
    border-radius: 50%;
}

.transaction-participant-avatar div {
    width: 40px !important;
    height: 40px !important;
    border-radius: 50% !important;
    border: 2px solid #e5e7eb;
    display: flex;
    align-items: center;
    justify-content: center;
}

.admin-action-group h4 {
    margin-top: 0;
    margin-bottom: 8px;
    color: #1f2937;
    font-size: 16px;
}

.admin-action-group {
    margin-bottom: 20px;
}

.btn-small {
    padding: 6px 12px;
    font-size: 13px;
    border-radius: 6px;
    text-decoration: none;
    display: inline-flex;
    align-items: center;
    gap: 6px;
    transition: all 0.2s;
    border: none;
    cursor: pointer;
    font-weight: 500;
}

.btn-small:hover {
    opacity: 0.9;
    transform: translateY(-1px);
}

.form-control {
    width: 100%;
    padding: 10px;
    border: 1px solid #ddd;
    border-radius: 6px;
    font-size: 14px;
    font-family: inherit;
}

.form-control:focus {
    outline: none;
    border-color: #3b82f6;
    box-shadow: 0 0 0 3px rgba(59, 130, 246, 0.1);
}

/* Карточка связанного объекта */
.ticket-info-card.card-related {
    background: linear-gradient(135deg, #eff6ff 0%, #f0f9ff 100%);
    border-left: 4px solid #3b82f6;
    padding: 16px;
}

.card-header-collapsible {
    display: flex;
    justify-content: space-between;
    align-items: center;
    cursor: pointer;
    user-select: none;
    margin-bottom: 0;
}

.card-header-left {
    display: flex;
    align-items: center;
    gap: 10px;
    flex: 1;
    min-width: 0;
}

.card-header-title {
    margin: 0;
    font-size: 1em;
    font-weight: 600;
    color: #1e40af;
}

.card-toggle-icon {
    font-size: 0.85em;
    color: #1e40af;
    transition: transform 0.3s ease;
}

.card-collapsible-content {
    margin-top: 16px;
    display: none;
    max-height: 400px;
    overflow-y: auto;
    animation: slideDown 0.3s ease-out;
}

.card-collapsible-content.show {
    display: block;
}

.card-header-actions {
    display: flex;
    gap: 8px;
    align-items: center;
    flex-shrink: 0;
}

.btn-link-compact {
    display: inline-flex;
    align-items: center;
    gap: 6px;
    padding: 6px 12px;
    background: #3b82f6;
    color: white;
    text-decoration: none;
    border-radius: var(--radius-md);
    font-size: 0.85em;
    font-weight: 500;
    transition: all var(--transition-base);
    white-space: nowrap;
}

.btn-link-compact:hover {
    background: #2563eb;
    transform: translateY(-1px);
    box-shadow: 0 2px 8px rgba(59, 130, 246, 0.3);
}

@keyframes slideDown {
    from {
        opacity: 0;
        transform: translateY(-10px);
    }
    to {
        opacity: 1;
        transform: translateY(0);
    }
}

/* Адаптивность для мобильных устройств */
@media (max-width: 1200px) {
    .ticket-layout-compact {
        grid-template-columns: 1fr;
    }
    
    .ticket-info-column {
        position: static;
        max-height: none;
        order: 2;
    }
    
    .ticket-chat-column {
        order: 1;
    }
    
    .ticket-chat {
        height: 600px;
    }
}

@media (max-width: 768px) {
    .ticket-view-container {
        padding: 12px;
    }
    
    .ticket-info-card {
        padding: 12px;
    }
    
    .ticket-info-card > div[style*="grid-template-columns"] {
        grid-template-columns: 1fr !important;
    }
    
    .ticket-chat {
        height: 500px;
    }
    
    .info-label {
        width: 90px;
        font-size: 0.8em;
    }
    
    .info-value {
        font-size: 0.8em;
    }
}
</style>

<script>
// Глобальные переменные для чата поддержки
const ticketId = <?= $ticketId ?>;
const currentUserId = <?= $user->getId() ?>;
const isAdmin = <?= $isAdmin ? 'true' : 'false' ?>;
let wsConnected = false;

// Экранирование HTML
function escapeHtml(text) {
    const div = document.createElement('div');
    div.textContent = text;
    return div.innerHTML;
}

// Автопрокрутка вниз
function scrollToBottom() {
    const chatMessages = document.getElementById('chatMessages');
    if (chatMessages) {
        requestAnimationFrame(function() {
            chatMessages.scrollTop = chatMessages.scrollHeight;
        });
    }
}

// Обновление счетчика символов
function updateCharCount() {
    const messageInput = document.getElementById('messageInput');
    const charCount = document.getElementById('charCount');
    if (messageInput && charCount) {
        charCount.textContent = messageInput.value.length;
    }
}

// Автоматическое изменение высоты textarea
function autoResizeTextarea() {
    const textarea = document.getElementById('messageInput');
    if (textarea) {
        textarea.style.height = 'auto';
        textarea.style.height = Math.min(textarea.scrollHeight, 120) + 'px';
    }
}

// Отправка сообщения (глобальная функция для вызова из HTML)
function sendMessage(event) {
    event.preventDefault();
    
    const messageInput = document.getElementById('messageInput');
    const messageText = messageInput.value.trim();
    
    if (!messageText) {
        return false;
    }
    
    const sendBtn = document.getElementById('sendBtn');
    const originalHtml = sendBtn.innerHTML;
    sendBtn.disabled = true;
    sendBtn.innerHTML = '<i class="fas fa-spinner fa-spin"></i>';
    
    // Пробуем отправить через WebSocket
    if (typeof window.chatWebSocket !== 'undefined' && 
        window.chatWebSocket.isAuthenticated && 
        window.chatWebSocket.ws && 
        window.chatWebSocket.ws.readyState === WebSocket.OPEN) {
        
        const sent = window.chatWebSocket.send({
            type: 'send_message',
            chat_type: 'support',
            chat_id: ticketId,
            message: messageText
        });
        
        if (sent) {
            // Очищаем поле ввода сразу для лучшего UX
            messageInput.value = '';
            updateCharCount();
            sendBtn.disabled = false;
            sendBtn.innerHTML = originalHtml;
            
            // Сообщение будет добавлено через WebSocket событие new_message
            // Добавляем временное сообщение для оптимистичного обновления UI
            const tempMessage = {
                id: 'temp_' + Date.now(),
                user_id: currentUserId,
                user_name: 'Вы',
                user_avatar_html: '',
                message: messageText,
                created_at: new Date().toISOString(),
                is_admin: isAdmin,
                is_temp: true
            };
            
            // Добавляем временное сообщение
            addSupportMessageToChat(tempMessage);
            
            // Удалим временное сообщение, когда придет реальное
            setTimeout(function() {
                const tempMsgEl = document.querySelector('[data-message-id="' + tempMessage.id + '"]');
                if (tempMsgEl) {
                    tempMsgEl.remove();
                }
            }, 5000);
            
            return false;
        }
    }
    
    // Fallback через API
    const formData = new FormData(event.target);
    formData.append('action', 'add_message');
    
    fetch('/api/support.php', {
        method: 'POST',
        body: formData
    })
    .then(response => response.text())
    .then(text => {
        try {
            const data = JSON.parse(text);
            if (data.success) {
                messageInput.value = '';
                updateCharCount();
                location.reload();
            } else {
                if (typeof Toast !== 'undefined') {
                    Toast.error(data.message || 'Ошибка при отправке сообщения');
                } else {
                    alert(data.message || 'Ошибка при отправке сообщения');
                }
                sendBtn.disabled = false;
                sendBtn.innerHTML = originalHtml;
            }
        } catch (e) {
            console.error('Invalid JSON:', text);
            if (typeof Toast !== 'undefined') {
                Toast.error('Ошибка при отправке сообщения');
            } else {
                alert('Ошибка при отправке сообщения');
            }
            sendBtn.disabled = false;
            sendBtn.innerHTML = originalHtml;
        }
    })
    .catch(error => {
        console.error('Error:', error);
        if (typeof Toast !== 'undefined') {
            Toast.error('Ошибка при отправке сообщения');
        } else {
            alert('Ошибка при отправке сообщения');
        }
        sendBtn.disabled = false;
        sendBtn.innerHTML = originalHtml;
    });
    
    return false;
}

// Флаг для предотвращения дублирования обработчиков
let wsHandlersRegistered = false;

// Инициализация WebSocket
function initWebSocket() {
    if (typeof window.chatWebSocket === 'undefined') {
        updateWSStatus('disconnected');
        return;
    }
    
    // Регистрируем обработчики только один раз
    if (!wsHandlersRegistered) {
        // Обработчик новых сообщений
        const supportMessageHandler = function(data) {
            if (!data) {
                return;
            }
            
            if (data.chat_type === 'support' && data.chat_id === ticketId && data.message) {
                try {
                    addSupportMessageToChat(data.message);
                } catch (error) {
                    // Игнорируем ошибки
                }
            }
        };
        
        window.chatWebSocket.onMessageType('new_message', supportMessageHandler);
        
        // Также слушаем все сообщения через прямое событие WebSocket
        if (window.chatWebSocket.ws) {
            window.chatWebSocket.ws.addEventListener('message', function(event) {
                try {
                    const data = JSON.parse(event.data);
                    if (data.type === 'new_message' && data.chat_type === 'support' && data.chat_id === ticketId) {
                        supportMessageHandler(data);
                    }
                } catch (e) {
                    // Не JSON сообщение, игнорируем
                }
            });
        }
        
        // Обработчик статуса подключения
        window.chatWebSocket.onMessageType('auth_success', function() {
            subscribeToTicket();
            updateWSStatus('connected');
        });
        
        // Обработчик ошибок WebSocket
        window.addEventListener('websocket-error', function() {
            updateWSStatus('disconnected');
        });
        
        // Обработчик закрытия соединения
        if (window.chatWebSocket.ws) {
            window.chatWebSocket.ws.addEventListener('close', function() {
                updateWSStatus('disconnected');
            });
        }
        
        wsHandlersRegistered = true;
    }
    
    // Подключаемся к WebSocket, если еще не подключены
    if (window.chatWebSocket.ws && window.chatWebSocket.ws.readyState === WebSocket.OPEN) {
        // Уже подключено
        if (window.chatWebSocket.isAuthenticated) {
            subscribeToTicket();
            updateWSStatus('connected');
        } else {
            // Ждем аутентификации
            const checkAuth = setInterval(function() {
                if (window.chatWebSocket.isAuthenticated) {
                    clearInterval(checkAuth);
                    subscribeToTicket();
                    updateWSStatus('connected');
                }
            }, 100);
            
            setTimeout(function() {
                clearInterval(checkAuth);
            }, 5000);
        }
    } else if (!window.chatWebSocket.isConnecting && !window.chatWebSocket.isAuthenticated) {
        // Не подключено, подключаемся
        window.chatWebSocket.connect(currentUserId);
        
        // Ждем аутентификации
        const checkAuth = setInterval(function() {
            if (window.chatWebSocket.isAuthenticated) {
                clearInterval(checkAuth);
                subscribeToTicket();
                updateWSStatus('connected');
            }
        }, 100);
        
        // Таймаут подключения
        setTimeout(function() {
            clearInterval(checkAuth);
            if (!window.chatWebSocket.isAuthenticated) {
                updateWSStatus('disconnected');
            }
        }, 10000);
    }
}

// Подписка на обращение
function subscribeToTicket() {
    if (typeof window.chatWebSocket === 'undefined' || !window.chatWebSocket.isAuthenticated) {
        return;
    }
    
    window.chatWebSocket.send({
        type: 'subscribe_chat',
        chat_type: 'support',
        chat_id: ticketId
    });
}

// Обновление статуса WebSocket
function updateWSStatus(status) {
    const statusEl = document.getElementById('wsStatus');
    if (!statusEl) return;
    
    statusEl.className = 'status-indicator ' + status;
    
    switch(status) {
        case 'connected':
            statusEl.innerHTML = '<i class="fas fa-circle"></i> <span>Подключено</span>';
            wsConnected = true;
            break;
        case 'disconnected':
            statusEl.innerHTML = '<i class="fas fa-circle"></i> <span>Не подключено</span>';
            wsConnected = false;
            break;
        default:
            statusEl.innerHTML = '<i class="fas fa-circle"></i> <span>Подключение...</span>';
            wsConnected = false;
    }
}

// Добавление сообщения в чат поддержки
function addSupportMessageToChat(messageData) {
    const chatMessages = document.getElementById('chatMessages');
    if (!chatMessages) {
        return;
    }
    
    // Проверяем, не добавлено ли уже это сообщение
    if (messageData.id && document.querySelector('[data-message-id="' + messageData.id + '"]')) {
        return;
    }
    
    // Удаляем временное сообщение, если есть
    if (!messageData.is_temp && messageData.id) {
        const tempMsg = document.querySelector('[data-message-id^="temp_"]');
        if (tempMsg) {
            tempMsg.remove();
        }
    }
    
    const isOwn = messageData.user_id === currentUserId;
    const isAdminMsg = messageData.is_admin || false;
    const messageClass = isAdminMsg ? 'message-admin' : 'message-user';
    
    const messageEl = document.createElement('div');
    messageEl.className = 'message ' + messageClass;
    messageEl.setAttribute('data-message-id', messageData.id || 'temp_' + Date.now());
    
    const time = new Date(messageData.created_at).toLocaleString('ru-RU', {
        day: '2-digit',
        month: '2-digit',
        year: 'numeric',
        hour: '2-digit',
        minute: '2-digit'
    });
    
    // Формируем HTML для аватара
    let avatarHtml = messageData.user_avatar_html;
    if (!avatarHtml) {
        const initial = messageData.user_name ? messageData.user_name.charAt(0) : 'П';
        avatarHtml = '<div class="message-avatar-placeholder">' + escapeHtml(initial) + '</div>';
    }
    
    // Формируем HTML для автора
    let authorHtml = escapeHtml(messageData.user_name || 'Пользователь');
    if (isAdminMsg) {
        authorHtml += '<span class="admin-badge"><i class="fas fa-shield-alt"></i> Поддержка</span>';
    }
    
    // Формируем HTML для содержимого сообщения
    const messageContent = escapeHtml(messageData.message).replace(/\n/g, '<br>');
    
    messageEl.innerHTML = 
        '<div class="message-avatar">' + avatarHtml + '</div>' +
        '<div class="message-body">' +
            '<div class="message-header">' +
                '<span class="message-author">' + authorHtml + '</span>' +
                '<span class="message-time">' + escapeHtml(time) + '</span>' +
            '</div>' +
            '<div class="message-content">' + messageContent + '</div>' +
        '</div>';
    
    chatMessages.appendChild(messageEl);
    scrollToBottom();
}

// Инициализация UI элементов
function initUI() {
    scrollToBottom();
    
    const messageInput = document.getElementById('messageInput');
    if (messageInput) {
        messageInput.addEventListener('input', function() {
            updateCharCount();
            autoResizeTextarea();
        });
        
        messageInput.addEventListener('keydown', function(e) {
            if (e.key === 'Enter' && !e.shiftKey) {
                e.preventDefault();
                document.getElementById('messageForm').dispatchEvent(new Event('submit'));
            }
        });
        
        // Инициализируем счетчик символов
        updateCharCount();
    }
    
    // Периодическая проверка подключения
    setInterval(function() {
        if (typeof window.chatWebSocket !== 'undefined') {
            if (window.chatWebSocket.isAuthenticated && !wsConnected) {
                subscribeToTicket();
                updateWSStatus('connected');
            } else if (!window.chatWebSocket.isAuthenticated && wsConnected) {
                updateWSStatus('disconnected');
            }
        }
    }, 5000);
}

// Ждем загрузки chat-all.js из base.php
function initSupportChat() {
    // Проверяем, загружен ли скрипт
    if (typeof window.chatWebSocket === 'undefined') {
        setTimeout(initSupportChat, 100);
        return;
    }
    
    // Инициализируем UI
    if (document.readyState === 'loading') {
        document.addEventListener('DOMContentLoaded', function() {
            initUI();
            initWebSocket();
        });
    } else {
        // DOM уже загружен
        initUI();
        initWebSocket();
    }
}

// Запускаем инициализацию
initSupportChat();

// Функции для модального окна связанного объекта
function showRelatedObjectModal() {
    const modal = document.getElementById('relatedObjectModal');
    if (modal) {
        modal.style.display = 'flex';
        document.body.style.overflow = 'hidden';
    }
}

function closeRelatedObjectModal() {
    const modal = document.getElementById('relatedObjectModal');
    if (modal) {
        modal.style.display = 'none';
        document.body.style.overflow = '';
    }
}

// Функции для модального окна админ панели
function showAdminFixModal() {
    const modal = document.getElementById('adminFixModal');
    if (modal) {
        modal.style.display = 'flex';
        document.body.style.overflow = 'hidden';
    }
}

function closeAdminFixModal() {
    const modal = document.getElementById('adminFixModal');
    if (modal) {
        modal.style.display = 'none';
        document.body.style.overflow = '';
    }
}

// Закрытие модальных окон по клику вне области
document.addEventListener('DOMContentLoaded', function() {
    // Закрытие по клику на backdrop
    document.addEventListener('click', function(e) {
        if (e.target.classList.contains('modal')) {
            e.target.style.display = 'none';
            document.body.style.overflow = '';
        }
    });
    
    // Закрытие по ESC
    document.addEventListener('keydown', function(e) {
        if (e.key === 'Escape') {
            closeRelatedObjectModal();
            closeAdminFixModal();
        }
    });
});

// Функции для администраторской панели исправления
<?php if ($isAdmin && ($relatedTransaction || $relatedBill)): ?>
function adminForceConfirmTransaction() {
    const ticketId = <?= $ticketId ?>;
    const transactionId = <?= $relatedTransaction ? $relatedTransaction->getId() : 0 ?>;
    const confirmSeller = document.getElementById('confirmSeller').checked;
    const confirmBuyer = document.getElementById('confirmBuyer').checked;
    const csrfToken = window.csrfToken;
    
    if (!confirmSeller && !confirmBuyer) {
        if (typeof Toast !== 'undefined') {
            Toast.error('Выберите хотя бы одного участника для подтверждения');
        } else {
            alert('Выберите хотя бы одного участника для подтверждения');
        }
        return;
    }
    
    if (!confirm('Вы уверены, что хотите принудительно подтвердить сделку?')) {
        return;
    }
    
    const formData = new FormData();
    formData.append('action', 'admin_force_confirm_transaction');
    formData.append('ticket_id', ticketId);
    formData.append('transaction_id', transactionId);
    formData.append('confirm_seller', confirmSeller ? '1' : '0');
    formData.append('confirm_buyer', confirmBuyer ? '1' : '0');
    formData.append('_csrf_token', csrfToken);
    
    fetch('/api/support.php', {
        method: 'POST',
        body: formData
    })
    .then(response => response.json())
    .then(data => {
        if (data.success) {
            if (typeof Toast !== 'undefined') {
                Toast.success(data.message || 'Сделка успешно подтверждена');
            } else {
                alert(data.message || 'Сделка успешно подтверждена');
            }
            // Перезагружаем страницу для обновления данных
            setTimeout(() => {
                window.location.reload();
            }, 1500);
        } else {
            if (typeof Toast !== 'undefined') {
                Toast.error(data.message || 'Ошибка при подтверждении сделки');
            } else {
                alert(data.message || 'Ошибка при подтверждении сделки');
            }
        }
    })
    .catch(error => {
        console.error('Error:', error);
        if (typeof Toast !== 'undefined') {
            Toast.error('Произошла ошибка при отправке запроса');
        } else {
            alert('Произошла ошибка при отправке запроса');
        }
    });
}

function adminForceCreateBill(event) {
    event.preventDefault();
    
    const ticketId = document.getElementById('ticketId').value;
    const transactionId = document.getElementById('transactionId').value;
    const issuerId = document.getElementById('billIssuerId').value;
    const holderId = document.getElementById('billHolderId').value;
    const nominal = parseFloat(document.getElementById('billNominal').value);
    const maturityDays = parseInt(document.getElementById('billMaturityDays').value);
    const csrfToken = window.csrfToken;
    
    if (nominal <= 0) {
        if (typeof Toast !== 'undefined') {
            Toast.error('Номинал должен быть больше нуля');
        } else {
            alert('Номинал должен быть больше нуля');
        }
        return false;
    }
    
    if (maturityDays <= 0) {
        if (typeof Toast !== 'undefined') {
            Toast.error('Срок погашения должен быть больше нуля');
        } else {
            alert('Срок погашения должен быть больше нуля');
        }
        return false;
    }
    
    if (!confirm(`Создать вексель на сумму ${nominal.toFixed(2)} ₽ со сроком погашения ${maturityDays} дней?`)) {
        return false;
    }
    
    const formData = new FormData();
    formData.append('action', 'admin_force_create_bill');
    formData.append('ticket_id', ticketId);
    formData.append('transaction_id', transactionId);
    formData.append('issuer_id', issuerId);
    formData.append('holder_id', holderId);
    formData.append('nominal', nominal);
    formData.append('maturity_days', maturityDays);
    formData.append('_csrf_token', csrfToken);
    
    const submitBtn = event.target.querySelector('button[type="submit"]');
    const originalText = submitBtn.innerHTML;
    submitBtn.disabled = true;
    submitBtn.innerHTML = '<i class="fas fa-spinner fa-spin"></i> Создание...';
    
    fetch('/api/support.php', {
        method: 'POST',
        body: formData
    })
    .then(response => response.json())
    .then(data => {
        if (data.success) {
            if (typeof Toast !== 'undefined') {
                Toast.success(data.message || 'Вексель успешно создан');
            } else {
                alert(data.message || 'Вексель успешно создан');
            }
            // Перезагружаем страницу для обновления данных
            setTimeout(() => {
                window.location.reload();
            }, 1500);
        } else {
            submitBtn.disabled = false;
            submitBtn.innerHTML = originalText;
            if (typeof Toast !== 'undefined') {
                Toast.error(data.message || 'Ошибка при создании векселя');
            } else {
                alert(data.message || 'Ошибка при создании векселя');
            }
        }
    })
    .catch(error => {
        console.error('Error:', error);
        submitBtn.disabled = false;
        submitBtn.innerHTML = originalText;
        if (typeof Toast !== 'undefined') {
            Toast.error('Произошла ошибка при отправке запроса');
        } else {
            alert('Произошла ошибка при отправке запроса');
        }
    });
    
    return false;
}
<?php endif; ?>
</script>

<?php
$content = ob_get_clean();
require_once __DIR__ . '/../../templates/base.php';
?>
