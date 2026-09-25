<?php
/**
 * Страница обращений в поддержку (для пользователей)
 */

require_once __DIR__ . '/../src/bootstrap.php';

use OGAS\Services\Auth;
use OGAS\Services\SupportService;
use OGAS\Models\Transaction;
use OGAS\Models\Bill;
use OGAS\Models\User;

Auth::requireAuth();

$user = Auth::user();

$title = 'Поддержка';

// Получаем статистику
$stats = SupportService::getStats($user->getId());

// Получаем обращения
$status = $_GET['status'] ?? null;
$tickets = SupportService::getUserTickets($user->getId(), $status);

// Получаем транзакции пользователя для выбора в форме
$userTransactions = Transaction::findByUser($user->getId(), null); // Все транзакции

// Получаем вексели пользователя для выбора в форме (как выпущенные, так и полученные)
$issuedBills = Bill::findByIssuer($user->getId(), null);
$heldBills = Bill::findByHolder($user->getId(), null);

// Объединяем и убираем дубликаты по ID
$userBills = [];
$seenBillIds = [];
foreach (array_merge($issuedBills, $heldBills) as $bill) {
    $billId = $bill->getId();
    if (!isset($seenBillIds[$billId])) {
        $userBills[] = $bill;
        $seenBillIds[$billId] = true;
    }
}

ob_start();
?>

<div class="dashboard">
    <div class="dashboard-header">
        <h2><i class="fas fa-headset"></i> Поддержка</h2>
        <div class="header-actions">
            <button type="button" class="btn btn-primary" onclick="showCreateTicketModal()">
                <i class="fas fa-plus"></i> Создать обращение
            </button>
            <a href="/dashboard.php" class="btn btn-secondary">← В кабинет</a>
        </div>
    </div>

    <!-- Статистика -->
    <div class="catalog-info">
        <div class="catalog-stats">
            <div class="stat-item">
                <i class="fas fa-inbox"></i>
                <span>Всего обращений: <strong><?= $stats['total'] ?? 0 ?></strong></span>
            </div>
            <div class="stat-item">
                <i class="fas fa-folder-open"></i>
                <span>Открытых: <strong><?= $stats['open'] ?? 0 ?></strong></span>
            </div>
            <div class="stat-item">
                <i class="fas fa-spinner"></i>
                <span>В работе: <strong><?= $stats['in_progress'] ?? 0 ?></strong></span>
            </div>
            <div class="stat-item">
                <i class="fas fa-check-circle"></i>
                <span>Решено: <strong><?= $stats['resolved'] ?? 0 ?></strong></span>
            </div>
        </div>
    </div>

    <!-- Фильтры -->
    <div class="support-filters-wrapper">
        <div class="support-filters">
            <a href="/support.php" class="filter-btn <?= !$status ? 'active' : '' ?>">
                <i class="fas fa-list"></i> Все
            </a>
            <a href="/support.php?status=open" class="filter-btn <?= $status === 'open' ? 'active' : '' ?>">
                <i class="fas fa-folder-open"></i> Открытые
            </a>
            <a href="/support.php?status=in_progress" class="filter-btn <?= $status === 'in_progress' ? 'active' : '' ?>">
                <i class="fas fa-spinner"></i> В работе
            </a>
            <a href="/support.php?status=resolved" class="filter-btn <?= $status === 'resolved' ? 'active' : '' ?>">
                <i class="fas fa-check-circle"></i> Решено
            </a>
            <a href="/support.php?status=closed" class="filter-btn <?= $status === 'closed' ? 'active' : '' ?>">
                <i class="fas fa-archive"></i> Закрыто
            </a>
        </div>
    </div>

    <!-- Список обращений -->
    <div class="tickets-list">
        <?php if (empty($tickets)): ?>
            <div class="support-empty-state">
                <div class="support-empty-icon">
                    <i class="fas fa-inbox"></i>
                </div>
                <h3>У вас пока нет обращений</h3>
                <p>Создайте первое обращение в поддержку, если у вас возникли вопросы или проблемы</p>
                <button type="button" class="btn btn-primary" onclick="showCreateTicketModal()">
                    <i class="fas fa-plus"></i> Создать первое обращение
                </button>
            </div>
        <?php else: ?>
            <?php foreach ($tickets as $ticket): ?>
                <div class="ticket-card-modern" onclick="openTicket(<?= $ticket['id'] ?>)">
                    <div class="ticket-card-header">
                        <div class="ticket-card-main">
                            <div class="ticket-subject">
                                <i class="fas fa-ticket-alt ticket-icon"></i>
                                <?= htmlspecialchars($ticket['subject']) ?>
                            </div>
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
                        </div>
                    </div>
                    <div class="ticket-card-body">
                        <div class="ticket-meta">
                            <span class="ticket-priority-badge priority-<?= $ticket['priority'] ?>">
                                <i class="fas fa-<?= $ticket['priority'] === 'urgent' ? 'exclamation-triangle' : ($ticket['priority'] === 'high' ? 'arrow-up' : ($ticket['priority'] === 'low' ? 'arrow-down' : 'minus')) ?>"></i>
                                <?php
                                $priorityLabels = [
                                    'low' => 'Низкий',
                                    'medium' => 'Средний',
                                    'high' => 'Высокий',
                                    'urgent' => 'Срочный'
                                ];
                                echo $priorityLabels[$ticket['priority']] ?? $ticket['priority'];
                                ?>
                            </span>
                            <span class="ticket-date">
                                <i class="fas fa-calendar-alt"></i>
                                <?= date('d.m.Y H:i', strtotime($ticket['created_at'])) ?>
                            </span>
                            <?php if ($ticket['message_count'] > 0): ?>
                                <span class="ticket-messages">
                                    <i class="fas fa-comments"></i> <?= $ticket['message_count'] ?> сообщений
                                </span>
                            <?php endif; ?>
                        </div>
                    </div>
                    <div class="ticket-card-arrow">
                        <i class="fas fa-chevron-right"></i>
                    </div>
                </div>
            <?php endforeach; ?>
        <?php endif; ?>
    </div>
</div>

<!-- Модальное окно создания обращения -->
<div id="createTicketModal" class="modal" style="display: none;">
    <div class="modal-content">
        <div class="modal-header">
            <h3><i class="fas fa-plus-circle"></i> Создать обращение</h3>
            <button type="button" class="modal-close" onclick="closeCreateTicketModal()">&times;</button>
        </div>
        <div class="modal-body">
            <form id="createTicketForm" onsubmit="return createTicket(event)">
                <?= csrf_field() ?>
                <div class="form-group">
                    <label for="ticketSubject">Тема обращения *</label>
                    <input type="text" id="ticketSubject" name="subject" required minlength="3" maxlength="255" placeholder="Опишите кратко суть проблемы">
                </div>
                <div class="form-group">
                    <label for="ticketPriority">Приоритет</label>
                    <select id="ticketPriority" name="priority">
                        <option value="low">Низкий</option>
                        <option value="medium" selected>Средний</option>
                        <option value="high">Высокий</option>
                        <option value="urgent">Срочный</option>
                    </select>
                </div>
                
                <!-- Связь с транзакцией или векселем -->
                <div class="form-group">
                    <label for="ticketRelationType">Связано с (опционально)</label>
                    <select id="ticketRelationType" name="relation_type" onchange="toggleRelationSelect()">
                        <option value="">Без связи</option>
                        <option value="transaction">Транзакция</option>
                        <option value="bill">Вексель</option>
                    </select>
                </div>
                
                <!-- Выбор транзакции -->
                <div class="form-group" id="transactionSelectGroup" style="display: none;">
                    <label for="ticketTransactionId">Выберите транзакцию *</label>
                    <select id="ticketTransactionId" name="related_transaction_id">
                        <option value="">-- Выберите транзакцию --</option>
                        <?php foreach ($userTransactions as $transaction): 
                            $seller = User::findById($transaction->getSellerId());
                            $buyer = User::findById($transaction->getBuyerId());
                            $isSeller = $transaction->getSellerId() === $user->getId();
                            $counterparty = $isSeller ? $buyer : $seller;
                            
                            $statusLabels = [
                                'pending' => 'Ожидает',
                                'active' => 'Активна',
                                'completed' => 'Завершена',
                                'cancelled' => 'Отменена'
                            ];
                            $statusLabel = $statusLabels[$transaction->getStatus()] ?? $transaction->getStatus();
                            $description = $transaction->getDescription() ? mb_substr($transaction->getDescription(), 0, 50) : 'Без описания';
                        ?>
                            <option value="<?= $transaction->getId() ?>">
                                #<?= $transaction->getId() ?> - <?= htmlspecialchars($counterparty->getFullName()) ?> (<?= $statusLabel ?>) - <?= htmlspecialchars($description) ?>
                            </option>
                        <?php endforeach; ?>
                    </select>
                    <small class="form-help-text">
                        Если проблема связана с конкретной транзакцией, выберите её здесь
                    </small>
                </div>
                
                <!-- Выбор векселя -->
                <div class="form-group" id="billSelectGroup" style="display: none;">
                    <label for="ticketBillId">Выберите вексель *</label>
                    <select id="ticketBillId" name="related_bill_id">
                        <option value="">-- Выберите вексель --</option>
                        <?php 
                        // $userBills уже без дубликатов
                        foreach ($userBills as $bill): 
                            $issuer = User::findById($bill->getIssuerId());
                            $holder = User::findById($bill->getHolderId());
                            $isIssuer = $bill->getIssuerId() === $user->getId();
                            $counterparty = $isIssuer ? $holder : $issuer;
                            
                            $billStatusLabels = [
                                'active' => 'Активен',
                                'paid' => 'Погашен',
                                'overdue' => 'Просрочен',
                                'cancelled' => 'Отменён'
                            ];
                            $billStatusLabel = $billStatusLabels[$bill->getStatus()] ?? $bill->getStatus();
                        ?>
                            <option value="<?= $bill->getId() ?>">
                                #<?= $bill->getId() ?> - <?= htmlspecialchars($counterparty->getFullName()) ?> - <?= number_format($bill->getNominal(), 2) ?> ₽ (<?= $billStatusLabel ?>)
                            </option>
                        <?php endforeach; ?>
                    </select>
                    <small class="form-help-text">
                        Если проблема связана с конкретным векселем, выберите его здесь
                    </small>
                </div>
                
                <div class="form-group">
                    <label for="ticketMessage">Сообщение *</label>
                    <textarea id="ticketMessage" name="message" required minlength="10" rows="6" placeholder="Подробно опишите вашу проблему или вопрос"></textarea>
                </div>
                <div class="modal-footer">
                    <button type="button" class="btn btn-secondary" onclick="closeCreateTicketModal()">Отмена</button>
                    <button type="submit" class="btn btn-primary">Создать обращение</button>
                </div>
            </form>
        </div>
    </div>
</div>


<script>
function showCreateTicketModal() {
    document.getElementById('createTicketModal').style.display = 'flex';
    document.body.style.overflow = 'hidden'; // Блокируем скролл страницы
    // Сбрасываем форму при открытии
    document.getElementById('createTicketForm').reset();
    toggleRelationSelect(); // Скрываем/показываем селекты
}

function closeCreateTicketModal() {
    document.getElementById('createTicketModal').style.display = 'none';
    document.body.style.overflow = ''; // Разблокируем скролл
    document.getElementById('createTicketForm').reset();
    toggleRelationSelect(); // Скрываем селекты при закрытии
}

function toggleRelationSelect() {
    const relationType = document.getElementById('ticketRelationType').value;
    const transactionGroup = document.getElementById('transactionSelectGroup');
    const billGroup = document.getElementById('billSelectGroup');
    
    // Скрываем оба селекта
    transactionGroup.style.display = 'none';
    billGroup.style.display = 'none';
    
    // Сбрасываем значения
    document.getElementById('ticketTransactionId').value = '';
    document.getElementById('ticketBillId').value = '';
    
    // Показываем нужный селект в зависимости от выбранного типа
    if (relationType === 'transaction') {
        transactionGroup.style.display = 'block';
    } else if (relationType === 'bill') {
        billGroup.style.display = 'block';
    }
}

function createTicket(event) {
    event.preventDefault();
    
    const form = event.target;
    const formData = new FormData(form);
    formData.append('action', 'create_ticket');
    
    // Проверяем, что если выбран тип связи, то выбрана и сама связь
    const relationType = document.getElementById('ticketRelationType').value;
    if (relationType === 'transaction') {
        const transactionId = document.getElementById('ticketTransactionId').value;
        if (!transactionId) {
            if (typeof Toast !== 'undefined') {
                Toast.error('Пожалуйста, выберите транзакцию');
            } else {
                alert('Пожалуйста, выберите транзакцию');
            }
            return false;
        }
        // Удаляем related_bill_id, если был заполнен
        formData.delete('related_bill_id');
    } else if (relationType === 'bill') {
        const billId = document.getElementById('ticketBillId').value;
        if (!billId) {
            if (typeof Toast !== 'undefined') {
                Toast.error('Пожалуйста, выберите вексель');
            } else {
                alert('Пожалуйста, выберите вексель');
            }
            return false;
        }
        // Удаляем related_transaction_id, если был заполнен
        formData.delete('related_transaction_id');
    } else {
        // Если тип связи не выбран, удаляем оба поля
        formData.delete('related_transaction_id');
        formData.delete('related_bill_id');
    }
    
    // Удаляем relation_type из данных (он не нужен в API)
    formData.delete('relation_type');
    
    const submitBtn = form.querySelector('button[type="submit"]');
    const originalText = submitBtn.textContent;
    submitBtn.disabled = true;
    submitBtn.textContent = 'Создание...';
    
    fetch('/api/support.php', {
        method: 'POST',
        body: formData
    })
    .then(response => response.json())
    .then(data => {
        submitBtn.disabled = false;
        submitBtn.textContent = originalText;
        
        if (data.success) {
            if (typeof Toast !== 'undefined') {
                Toast.success(data.message || 'Обращение успешно создано');
            } else {
                alert(data.message || 'Обращение успешно создано');
            }
            closeCreateTicketModal();
            window.location.href = '/support/ticket.php?id=' + data.ticket_id;
        } else {
            if (typeof Toast !== 'undefined') {
                Toast.error(data.message || 'Ошибка при создании обращения');
            } else {
                alert(data.message || 'Ошибка при создании обращения');
            }
        }
    })
    .catch(error => {
        console.error('Error:', error);
        submitBtn.disabled = false;
        submitBtn.textContent = originalText;
        if (typeof Toast !== 'undefined') {
            Toast.error('Ошибка при создании обращения');
        } else {
            alert('Ошибка при создании обращения');
        }
    });
    
    return false;
}

function openTicket(ticketId) {
    window.location.href = '/support/ticket.php?id=' + ticketId;
}

// Закрытие модального окна при клике вне его и по ESC
document.addEventListener('DOMContentLoaded', function() {
    const modal = document.getElementById('createTicketModal');
    if (modal) {
        // Закрытие при клике вне модального окна
        modal.addEventListener('click', function(e) {
            if (e.target === modal) {
                closeCreateTicketModal();
            }
        });
        
        // Закрытие при нажатии ESC
        document.addEventListener('keydown', function(e) {
            if (e.key === 'Escape' && modal.style.display === 'flex') {
                closeCreateTicketModal();
            }
        });
    }
});
</script>

<?php
$content = ob_get_clean();
require_once __DIR__ . '/../templates/base.php';
?>


