<?php
/**
 * Страница управления обращениями в поддержку (для администраторов)
 */

require_once __DIR__ . '/../../src/bootstrap.php';

use OGAS\Services\Auth;
use OGAS\Services\AdminService;
use OGAS\Services\SupportService;
use OGAS\Core\SecurityLogger;
use OGAS\Core\Security;

Auth::requireAuth();
AdminService::requireAdmin();

$user = Auth::user();

// Логируем доступ
SecurityLogger::logAdminAccess('view_support_tickets', true);

$title = 'Управление поддержкой';

// Получаем статистику
$stats = SupportService::getStats();

// Фильтры
$status = $_GET['status'] ?? null;
$priority = $_GET['priority'] ?? null;
$assignedTo = isset($_GET['assigned_to']) ? (int)$_GET['assigned_to'] : null;

// Получаем обращения
$tickets = SupportService::getAllTickets($status, $priority, $assignedTo);

ob_start();

// Получаем CSRF токен один раз
$csrfToken = csrf_token();
?>

<div class="dashboard">
    <div class="dashboard-header">
        <h2><i class="fas fa-headset"></i> Управление поддержкой</h2>
    </div>

    <!-- Статистика -->
    <div class="support-stats">
        <div class="stat-card">
            <div class="stat-value"><?= $stats['total'] ?? 0 ?></div>
            <div class="stat-label">Всего обращений</div>
        </div>
        <div class="stat-card stat-open">
            <div class="stat-value"><?= $stats['open'] ?? 0 ?></div>
            <div class="stat-label">Открытых</div>
        </div>
        <div class="stat-card stat-progress">
            <div class="stat-value"><?= $stats['in_progress'] ?? 0 ?></div>
            <div class="stat-label">В работе</div>
        </div>
        <div class="stat-card stat-resolved">
            <div class="stat-value"><?= $stats['resolved'] ?? 0 ?></div>
            <div class="stat-label">Решено</div>
        </div>
        <div class="stat-card stat-closed">
            <div class="stat-value"><?= $stats['closed'] ?? 0 ?></div>
            <div class="stat-label">Закрыто</div>
        </div>
    </div>

    <!-- Фильтры -->
    <div class="support-filters">
        <select id="statusFilter" onchange="applyFilters()">
            <option value="">Все статусы</option>
            <option value="open" <?= $status === 'open' ? 'selected' : '' ?>>Открытые</option>
            <option value="in_progress" <?= $status === 'in_progress' ? 'selected' : '' ?>>В работе</option>
            <option value="resolved" <?= $status === 'resolved' ? 'selected' : '' ?>>Решено</option>
            <option value="closed" <?= $status === 'closed' ? 'selected' : '' ?>>Закрыто</option>
        </select>
        <select id="priorityFilter" onchange="applyFilters()">
            <option value="">Все приоритеты</option>
            <option value="low" <?= $priority === 'low' ? 'selected' : '' ?>>Низкий</option>
            <option value="medium" <?= $priority === 'medium' ? 'selected' : '' ?>>Средний</option>
            <option value="high" <?= $priority === 'high' ? 'selected' : '' ?>>Высокий</option>
            <option value="urgent" <?= $priority === 'urgent' ? 'selected' : '' ?>>Срочный</option>
        </select>
        <button type="button" class="btn btn-secondary" onclick="resetFilters()">Сбросить</button>
    </div>

    <!-- Таблица обращений -->
    <div class="tickets-table-container">
        <table class="tickets-table">
            <thead>
                <tr>
                    <th>ID</th>
                    <th>Тема</th>
                    <th>Пользователь</th>
                    <th>Приоритет</th>
                    <th>Статус</th>
                    <th>Назначено</th>
                    <th>Сообщений</th>
                    <th>Создано</th>
                    <th>Действия</th>
                </tr>
            </thead>
            <tbody>
                <?php if (empty($tickets)): ?>
                    <tr>
                        <td colspan="9" class="empty-state">Нет обращений</td>
                    </tr>
                <?php else: ?>
                    <?php foreach ($tickets as $ticket): ?>
                        <tr>
                            <td>#<?= $ticket['id'] ?></td>
                            <td>
                                <a href="/support/ticket.php?id=<?= $ticket['id'] ?>" class="ticket-link">
                                    <?= htmlspecialchars($ticket['subject']) ?>
                                </a>
                            </td>
                            <td><?= htmlspecialchars($ticket['user_name'] ?? 'Неизвестно') ?></td>
                            <td>
                                <span class="priority-badge priority-<?= $ticket['priority'] ?>">
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
                            </td>
                            <td>
                                <span class="status-badge status-<?= $ticket['status'] ?>">
                                    <?php
                                    $statusLabels = [
                                        'open' => 'Открыто',
                                        'in_progress' => 'В работе',
                                        'resolved' => 'Решено',
                                        'closed' => 'Закрыто'
                                    ];
                                    echo $statusLabels[$ticket['status']] ?? $ticket['status'];
                                    ?>
                                </span>
                            </td>
                            <td>
                                <span id="assigned-<?= $ticket['id'] ?>">
                                    <?= htmlspecialchars($ticket['assigned_name'] ?? '-') ?>
                                </span>
                                <button type="button" class="btn-icon" onclick="showAssignModal(<?= $ticket['id'] ?>, <?= $ticket['assigned_to'] ?? 'null' ?>)" title="Назначить">
                                    <i class="fas fa-user-plus"></i>
                                </button>
                            </td>
                            <td><?= $ticket['message_count'] ?? 0 ?></td>
                            <td><?= date('d.m.Y H:i', strtotime($ticket['created_at'])) ?></td>
                            <td>
                                <div class="ticket-actions">
                                    <select class="status-select" data-ticket-id="<?= $ticket['id'] ?>" onchange="updateTicketStatus(<?= $ticket['id'] ?>, this.value)">
                                        <option value="open" <?= $ticket['status'] === 'open' ? 'selected' : '' ?>>Открыто</option>
                                        <option value="in_progress" <?= $ticket['status'] === 'in_progress' ? 'selected' : '' ?>>В работе</option>
                                        <option value="resolved" <?= $ticket['status'] === 'resolved' ? 'selected' : '' ?>>Решено</option>
                                        <option value="closed" <?= $ticket['status'] === 'closed' ? 'selected' : '' ?>>Закрыто</option>
                                    </select>
                                    <?php if ($ticket['status'] !== 'closed'): ?>
                                    <button type="button" class="btn-icon btn-danger-icon" onclick="closeTicket(<?= $ticket['id'] ?>)" title="Закрыть">
                                        <i class="fas fa-times-circle"></i>
                                    </button>
                                    <?php endif; ?>
                                    <a href="/support/ticket.php?id=<?= $ticket['id'] ?>" class="btn btn-small btn-primary">
                                        Открыть
                                    </a>
                                </div>
                            </td>
                        </tr>
                    <?php endforeach; ?>
                <?php endif; ?>
            </tbody>
        </table>
    </div>
</div>

<!-- Модальное окно для назначения администратора -->
<div id="assignModal" class="modal">
    <div class="modal-content">
        <div class="modal-header">
            <h3>Назначить ответственного</h3>
            <span class="close" onclick="closeModal()">&times;</span>
        </div>
        <div class="modal-body">
            <label for="assignAdminSelect">Выберите администратора:</label>
            <select id="assignAdminSelect" class="form-control">
                <option value="">Загрузка...</option>
            </select>
        </div>
        <div class="modal-footer">
            <button type="button" class="btn btn-secondary" onclick="closeModal()">Отмена</button>
            <button type="button" class="btn btn-primary" onclick="assignTicket()">Назначить</button>
        </div>
    </div>
</div>

<style>
.support-stats {
    display: grid;
    grid-template-columns: repeat(auto-fit, minmax(180px, 1fr));
    gap: 20px;
    margin-bottom: 30px;
}

.stat-card {
    background: white;
    padding: 20px;
    border-radius: 8px;
    box-shadow: 0 2px 4px rgba(0,0,0,0.1);
    text-align: center;
}

.stat-value {
    font-size: 32px;
    font-weight: bold;
    color: #3b82f6;
}

.stat-label {
    color: #666;
    margin-top: 5px;
}

.support-filters {
    display: flex;
    gap: 10px;
    margin-bottom: 20px;
    flex-wrap: wrap;
}

.support-filters select {
    padding: 8px 12px;
    border: 1px solid #ddd;
    border-radius: 4px;
}

.tickets-table-container {
    background: white;
    border-radius: 8px;
    box-shadow: 0 2px 4px rgba(0,0,0,0.1);
    overflow-x: auto;
}

.tickets-table {
    width: 100%;
    border-collapse: collapse;
}

.tickets-table th {
    background: #f9fafb;
    padding: 12px;
    text-align: left;
    font-weight: 600;
    border-bottom: 2px solid #e5e7eb;
}

.tickets-table td {
    padding: 12px;
    border-bottom: 1px solid #f3f4f6;
}

.ticket-link {
    color: #3b82f6;
    text-decoration: none;
    font-weight: 500;
}

.ticket-link:hover {
    text-decoration: underline;
}

.priority-badge,
.status-badge {
    padding: 4px 12px;
    border-radius: 12px;
    font-size: 12px;
    font-weight: 600;
}

.priority-low { background: #e5e7eb; color: #374151; }
.priority-medium { background: #dbeafe; color: #1e40af; }
.priority-high { background: #fef3c7; color: #92400e; }
.priority-urgent { background: #fee2e2; color: #991b1b; }

.status-open { background: #fef3c7; color: #92400e; }
.status-in_progress { background: #dbeafe; color: #1e40af; }
.status-resolved { background: #d1fae5; color: #065f46; }
.status-closed { background: #e5e7eb; color: #374151; }

.empty-state {
    text-align: center;
    padding: 40px;
    color: #666;
}

.ticket-actions {
    display: flex;
    gap: 8px;
    align-items: center;
}

.status-select {
    padding: 4px 8px;
    border: 1px solid #ddd;
    border-radius: 4px;
    font-size: 12px;
    cursor: pointer;
}

.btn-icon {
    background: none;
    border: none;
    cursor: pointer;
    padding: 4px 8px;
    color: #3b82f6;
    font-size: 14px;
    transition: color 0.2s;
}

.btn-icon:hover {
    color: #2563eb;
}

.btn-danger-icon {
    color: #ef4444;
}

.btn-danger-icon:hover {
    color: #dc2626;
}

.modal {
    display: none;
    position: fixed;
    z-index: 2000;
    left: 0;
    top: 0;
    width: 100%;
    height: 100%;
    background-color: rgba(0,0,0,0.5);
}

.modal-content {
    background-color: white;
    margin: 15% auto;
    padding: 20px;
    border-radius: 8px;
    width: 400px;
    max-width: 90%;
}

.modal-header {
    display: flex;
    justify-content: space-between;
    align-items: center;
    margin-bottom: 20px;
}

.modal-header h3 {
    margin: 0;
}

.close {
    color: #aaa;
    font-size: 28px;
    font-weight: bold;
    cursor: pointer;
}

.close:hover {
    color: #000;
}

.modal-body {
    margin-bottom: 20px;
}

.modal-body label {
    display: block;
    margin-bottom: 8px;
    font-weight: 600;
}

.modal-body select {
    width: 100%;
    padding: 8px 12px;
    border: 1px solid #ddd;
    border-radius: 4px;
    font-size: 14px;
}

.modal-footer {
    display: flex;
    justify-content: flex-end;
    gap: 10px;
}
</style>

<script>
// Глобальные функции для работы с обращениями
window.applyFilters = function() {
    const status = document.getElementById('statusFilter').value;
    const priority = document.getElementById('priorityFilter').value;
    
    const params = new URLSearchParams();
    if (status) params.append('status', status);
    if (priority) params.append('priority', priority);
    
    window.location.href = '/admin/support.php?' + params.toString();
};

window.resetFilters = function() {
    window.location.href = '/admin/support.php';
};

let adminsList = [];

// Загрузка списка администраторов
window.loadAdmins = function() {
    fetch('/api/support.php?action=get_admins')
        .then(response => response.json())
        .then(data => {
            if (data.success && data.data) {
                adminsList = data.data;
            }
        })
        .catch(error => {
            console.error('Error loading admins:', error);
        });
};

// Показать модальное окно назначения
window.showAssignModal = function(ticketId, currentAssignedId) {
    console.log('showAssignModal called:', ticketId, currentAssignedId);
    
    const modal = document.getElementById('assignModal');
    const select = document.getElementById('assignAdminSelect');
    
    if (!modal || !select) {
        console.error('Modal elements not found');
        return;
    }
    
    // Очищаем и заполняем список
    select.innerHTML = '<option value="">Не назначено</option>';
    adminsList.forEach(admin => {
        const option = document.createElement('option');
        option.value = admin.id;
        option.textContent = admin.full_name + ' (' + admin.email + ')';
        if (admin.id == currentAssignedId) {
            option.selected = true;
        }
        select.appendChild(option);
    });
    
    select.setAttribute('data-ticket-id', ticketId);
    modal.style.display = 'block';
};

// Закрыть модальное окно
window.closeModal = function() {
    const modal = document.getElementById('assignModal');
    if (modal) {
        modal.style.display = 'none';
    }
};

// Назначить обращение
window.assignTicket = function() {
    console.log('assignTicket called');
    
    const select = document.getElementById('assignAdminSelect');
    if (!select) {
        console.error('Select element not found');
        return;
    }
    
    const ticketId = select.getAttribute('data-ticket-id');
    const adminId = select.value || ''; // Пустая строка для снятия назначения
    
    console.log('Assign ticket:', { ticketId, adminId });
    
    if (!ticketId) {
        console.error('Ticket ID not found');
        return;
    }
    
    const formData = new FormData();
    formData.append('action', 'assign_ticket');
    formData.append('ticket_id', ticketId);
    if (adminId) {
        formData.append('admin_id', adminId);
    }
    const csrfToken = window.csrfToken || '<?= $csrfToken ?>';
    formData.append('csrf_token', csrfToken);
    
    console.log('Sending assign_ticket request:', { ticketId, adminId });
    
    fetch('/api/support.php', {
        method: 'POST',
        body: formData
    })
    .then(response => {
        console.log('assign_ticket response status:', response.status);
        if (!response.ok) {
            return response.text().then(text => {
                console.error('Response error:', text);
                throw new Error('Network response was not ok: ' + response.status);
            });
        }
        return response.json();
    })
    .then(data => {
        console.log('assign_ticket response data:', data);
        if (data.success) {
            if (typeof Toast !== 'undefined') {
                Toast.success(data.message || 'Обращение назначено');
            } else {
                alert(data.message || 'Обращение назначено');
            }
            closeModal();
            location.reload();
        } else {
            console.error('Assign ticket failed:', data);
            if (typeof Toast !== 'undefined') {
                Toast.error(data.message || 'Ошибка при назначении');
            } else {
                alert(data.message || 'Ошибка при назначении');
            }
        }
    })
    .catch(error => {
        console.error('Error assigning ticket:', error);
        if (typeof Toast !== 'undefined') {
            Toast.error('Ошибка при назначении обращения: ' + error.message);
        } else {
            alert('Ошибка при назначении обращения: ' + error.message);
        }
    });
};

// Обновить статус обращения
window.updateTicketStatus = function(ticketId, status) {
    console.log('updateTicketStatus called:', ticketId, status);
    
    if (!ticketId || !status) {
        console.error('Ticket ID and status are required');
        return;
    }
    
    const formData = new FormData();
    formData.append('action', 'update_status');
    formData.append('ticket_id', ticketId);
    formData.append('status', status);
    const csrfToken = window.csrfToken || '<?= $csrfToken ?>';
    formData.append('csrf_token', csrfToken);
    
    console.log('Sending update_status request:', { ticketId, status });
    
    fetch('/api/support.php', {
        method: 'POST',
        body: formData
    })
    .then(response => {
        console.log('update_status response status:', response.status);
        if (!response.ok) {
            return response.text().then(text => {
                console.error('Response error:', text);
                throw new Error('Network response was not ok: ' + response.status);
            });
        }
        return response.json();
    })
    .then(data => {
        console.log('update_status response data:', data);
        if (data.success) {
            if (typeof Toast !== 'undefined') {
                Toast.success(data.message || 'Статус обновлен');
            } else {
                alert(data.message || 'Статус обновлен');
            }
            // Небольшая задержка перед перезагрузкой для лучшего UX
            setTimeout(() => location.reload(), 500);
        } else {
            console.error('Update status failed:', data);
            if (typeof Toast !== 'undefined') {
                Toast.error(data.message || 'Ошибка при обновлении статуса');
            } else {
                alert(data.message || 'Ошибка при обновлении статуса');
            }
            location.reload();
        }
    })
    .catch(error => {
        console.error('Error updating status:', error);
        if (typeof Toast !== 'undefined') {
            Toast.error('Ошибка при обновлении статуса: ' + error.message);
        } else {
            alert('Ошибка при обновлении статуса: ' + error.message);
        }
        location.reload();
    });
};

// Закрыть обращение
window.closeTicket = function(ticketId) {
    console.log('closeTicket called:', ticketId);
    
    if (!ticketId) {
        console.error('Ticket ID is required');
        return;
    }
    
    if (!confirm('Вы уверены, что хотите закрыть это обращение?')) {
        return;
    }
    
    const formData = new FormData();
    formData.append('action', 'close_ticket');
    formData.append('ticket_id', ticketId);
    const csrfToken = window.csrfToken || '<?= $csrfToken ?>';
    formData.append('csrf_token', csrfToken);
    
    console.log('Sending close_ticket request:', { ticketId });
    
    fetch('/api/support.php', {
        method: 'POST',
        body: formData
    })
    .then(response => {
        console.log('close_ticket response status:', response.status);
        if (!response.ok) {
            return response.text().then(text => {
                console.error('Response error:', text);
                throw new Error('Network response was not ok: ' + response.status);
            });
        }
        return response.json();
    })
    .then(data => {
        console.log('close_ticket response data:', data);
        if (data.success) {
            if (typeof Toast !== 'undefined') {
                Toast.success(data.message || 'Обращение закрыто');
            } else {
                alert(data.message || 'Обращение закрыто');
            }
            // Небольшая задержка перед перезагрузкой для лучшего UX
            setTimeout(() => location.reload(), 500);
        } else {
            console.error('Close ticket failed:', data);
            if (typeof Toast !== 'undefined') {
                Toast.error(data.message || 'Ошибка при закрытии обращения');
            } else {
                alert(data.message || 'Ошибка при закрытии обращения');
            }
        }
    })
    .catch(error => {
        console.error('Error closing ticket:', error);
        if (typeof Toast !== 'undefined') {
            Toast.error('Ошибка при закрытии обращения: ' + error.message);
        } else {
            alert('Ошибка при закрытии обращения: ' + error.message);
        }
    });
};

// Закрыть модальное окно при клике вне его
window.addEventListener('click', function(event) {
    const modal = document.getElementById('assignModal');
    if (event.target == modal) {
        closeModal();
    }
});

// Загружаем список администраторов при загрузке страницы
if (document.readyState === 'loading') {
    document.addEventListener('DOMContentLoaded', function() {
        loadAdmins();
    });
} else {
    loadAdmins();
}

// Проверяем, что функции определены
console.log('Support functions initialized:', {
    showAssignModal: typeof window.showAssignModal,
    updateTicketStatus: typeof window.updateTicketStatus,
    closeTicket: typeof window.closeTicket,
    assignTicket: typeof window.assignTicket
});
</script>

<?php
$content = ob_get_clean();
require_once __DIR__ . '/../../templates/base.php';
?>
