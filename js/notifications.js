/**
 * JavaScript для dropdown уведомлений
 */

// Переключение отображения панели уведомлений
function toggleNotifications(event) {
    if (event) {
        event.stopPropagation();
    }
    
    const panel = document.getElementById('notificationsPanel');
    if (!panel) return;
    
    const isOpening = !panel.classList.contains('active');
    panel.classList.toggle('active');
    
    // Если панель открыта, загружаем уведомления и обновляем счётчик
    if (panel.classList.contains('active')) {
        loadNotifications();
        updateNotificationsCount();
    }
}

// Загрузить список уведомлений
function loadNotifications() {
    const list = document.getElementById('notificationsList');
    if (!list) return;
    
    // Показываем индикатор загрузки
    list.innerHTML = '<div class="notification-item notification-empty"><p>Загрузка...</p></div>';
    
    fetch('/api/notifications.php?action=get_recent&limit=10')
        .then(response => response.json())
        .then(data => {
            if (data.success && data.notifications) {
                // Обновляем видимость кнопки "Отметить все как прочитанные"
                const markAllBtn = document.getElementById('markAllReadBtn');
                const hasUnread = data.notifications.some(n => !n.is_read);
                if (markAllBtn) {
                    markAllBtn.style.display = hasUnread ? '' : 'none';
                }
                
                if (data.notifications.length === 0) {
                    list.innerHTML = '<div class="notification-item notification-empty"><p>У вас нет уведомлений</p></div>';
                } else {
                    list.innerHTML = '';
                    data.notifications.forEach(notif => {
                        const icon = getNotificationIcon(notif.type);
                        const item = document.createElement('div');
                        item.className = `notification-item ${!notif.is_read ? 'notification-unread' : ''}`;
                        item.setAttribute('data-id', notif.id);
                        
                        item.innerHTML = `
                            <div class="notification-icon">${icon}</div>
                            <div class="notification-content">
                                <div class="notification-title">${escapeHtml(notif.title)}</div>
                                <div class="notification-message">${escapeHtml(notif.message.length > 80 ? notif.message.substring(0, 80) + '...' : notif.message)}</div>
                                <div class="notification-time">${escapeHtml(notif.time_ago)}</div>
                            </div>
                            ${!notif.is_read ? '<div class="notification-unread-indicator"></div>' : ''}
                        `;
                        
                        // Обработчик клика: отмечаем как прочитанное и переходим по URL
                        item.addEventListener('click', function(e) {
                            if (!notif.is_read) {
                                markNotificationAsRead(notif.id, this);
                            }
                            window.location.href = notif.url;
                        });
                        
                        list.appendChild(item);
                    });
                }
            } else {
                list.innerHTML = '<div class="notification-item notification-empty"><p>Ошибка загрузки уведомлений</p></div>';
            }
        })
        .catch(error => {
            console.error('Ошибка при загрузке уведомлений:', error);
            list.innerHTML = '<div class="notification-item notification-empty"><p>Ошибка загрузки уведомлений</p></div>';
        });
}

// Получить иконку для типа уведомления
function getNotificationIcon(type) {
    const icons = {
        'bill_received': '📄',
        'bill_maturity_reminder': '⏰',
        'bill_overdue': '⚠️',
        'bill_paid': '✅',
        'transaction_created': '🔄',
        'transaction_completed': '✅',
        'transaction_cancelled': '❌',
        'community_request': '👥',
        'community_guarantor': '🤝',
        'subscription_reminder': '💳',
        'subscription_expired': '⏸️',
        'subscription_bill_issued': '📋',
        'subscription_paid': '✅',
        'chat_message': '💬',
        'account_activated': '🎉'
    };
    return icons[type] || '🔔';
}

// Экранирование HTML
function escapeHtml(text) {
    const div = document.createElement('div');
    div.textContent = text;
    return div.innerHTML;
}

// Отметить все уведомления как прочитанные
function markAllNotificationsAsRead() {
    fetch('/api/notifications.php?action=mark_all_read', {
        method: 'GET'
    })
    .then(response => response.json())
    .then(data => {
        if (data.success) {
            // Обновляем список уведомлений
            loadNotifications();
            // Обновляем счётчик
            updateNotificationsCount();
            // Скрываем кнопку "Отметить все как прочитанные"
            const markAllBtn = document.getElementById('markAllReadBtn');
            if (markAllBtn) {
                markAllBtn.style.display = 'none';
            }
        }
    })
    .catch(error => {
        console.error('Ошибка при отметке всех уведомлений:', error);
    });
}

// Закрытие панели при клике вне её
document.addEventListener('DOMContentLoaded', function() {
    document.addEventListener('click', function(event) {
        const dropdown = document.querySelector('.notifications-dropdown');
        const panel = document.getElementById('notificationsPanel');
        const bell = document.getElementById('notificationBell');
        
        if (dropdown && panel && bell && !dropdown.contains(event.target)) {
            panel.classList.remove('active');
        }
    });
    
    // Обработка клика на кнопку "Отметить все как прочитанные"
    const markAllBtn = document.getElementById('markAllReadBtn');
    if (markAllBtn) {
        markAllBtn.addEventListener('click', function(e) {
            e.preventDefault();
            markAllNotificationsAsRead();
        });
    }
});

// Отметить уведомление как прочитанное
function markNotificationAsRead(notificationId, element) {
    if (!notificationId || !element) return;
    
    fetch('/api/notifications.php', {
        method: 'POST',
        headers: {
            'Content-Type': 'application/x-www-form-urlencoded',
        },
        body: 'action=mark_read&id=' + encodeURIComponent(notificationId)
    })
    .then(response => response.json())
    .then(data => {
        if (data.success) {
            element.classList.remove('notification-unread');
            element.querySelector('.notification-unread-indicator')?.remove();
            updateNotificationsCount();
            
            // Проверяем, остались ли непрочитанные уведомления
            const unreadItems = document.querySelectorAll('.notification-item.notification-unread');
            const markAllBtn = document.getElementById('markAllReadBtn');
            if (markAllBtn) {
                markAllBtn.style.display = unreadItems.length > 0 ? '' : 'none';
            }
        }
    })
    .catch(error => {
        console.error('Ошибка при отметке уведомления:', error);
    });
}

// Обновить счётчик уведомлений
function updateNotificationsCount() {
    fetch('/api/notifications.php?action=get_unread_count')
        .then(response => response.json())
        .then(data => {
            if (data.success) {
                const counter = document.querySelector('.notification-counter');
                if (data.count > 0) {
                    if (counter) {
                        counter.textContent = data.count;
                    } else {
                        // Создаём счётчик, если его нет
                        const bell = document.getElementById('notificationBell');
                        if (bell) {
                            const newCounter = document.createElement('span');
                            newCounter.className = 'notification-counter';
                            newCounter.textContent = data.count;
                            bell.appendChild(newCounter);
                        }
                    }
                } else {
                    // Удаляем счётчик, если уведомлений нет
                    const counter = document.querySelector('.notification-counter');
                    if (counter) {
                        counter.remove();
                    }
                }
            }
        })
        .catch(error => {
            console.error('Ошибка при обновлении счётчика:', error);
        });
}

// Автообновление уведомлений каждые 30 секунд
let notificationsUpdateInterval = setInterval(function() {
    const panel = document.getElementById('notificationsPanel');
    if (panel && panel.classList.contains('active')) {
        loadNotifications();
        updateNotificationsCount();
    }
}, 30000);

// Обновление при возвращении на страницу
document.addEventListener('visibilitychange', function() {
    if (!document.hidden) {
        const panel = document.getElementById('notificationsPanel');
        if (panel && panel.classList.contains('active')) {
            loadNotifications();
            updateNotificationsCount();
        } else {
            // Обновляем счётчик даже если панель закрыта
            updateNotificationsCount();
        }
    }
});

// Очистка интервала при уходе со страницы
window.addEventListener('beforeunload', function() {
    if (notificationsUpdateInterval) {
        clearInterval(notificationsUpdateInterval);
    }
});

