/**
 * Объединенный файл всех скриптов чата
 * Автоматически сгенерирован из отдельных файлов
 * 
 * Порядок объединения:
 * 1. chat-websocket.js - ChatWebSocketManager (базовый WebSocket + счетчики непрочитанных)
 * 2. chat-manager.js - ChatManager (управление чатами)
 * 3. chat.js - Вспомогательные функции (updateTransactionStatusFromData, addMessageToChat)
 * 4. chats.js - Telegram-подобный интерфейс (loadChat, initEmbeddedChat)
 * 
 * Создано: 2025-11-26T07:13:36.358Z
 */


// ============================================================================
// chat-websocket.js
// ============================================================================

class ChatWebSocketManager {
    constructor() {
        this.ws = null;
        this.reconnectAttempts = 0;
        this.maxReconnectAttempts = 5;
        this.reconnectDelay = 1000; // Начальная задержка в мс
        this.isConnecting = false;
        this.isAuthenticated = false;
        this.useWebSocket = true; // Флаг для отключения WebSocket при ошибках безопасности
        this.userId = null;
        this.subscribedChats = new Set(); // Множество подписанных чатов (chatKey)
        this.chatManagers = new Map(); // chatKey => ChatManager instance
        this.messageHandlers = new Map(); // type => [handlers]
        this.pingInterval = null;
        this.pongTimeout = null;
        
        // Для обновления счетчиков непрочитанных
        this.timeUpdateInterval = null; // Интервал для обновления времени
        this.lastUpdateTime = 0; // Время последнего обновления счетчиков
        this.updateDebounceDelay = 1000; // Задержка для debounce обновлений (1 секунда)
        this.updateTimeout = null; // Таймер для debounce
        this.updateInterval = null; // Интервал для fallback polling
        
        // Настройки
        this.config = {
            wsUrl: null, // Будет определен динамически при подключении
            pingInterval: 30000, // 30 секунд
            pongTimeout: 5000, // 5 секунд
            reconnectDelay: 1000,
            maxReconnectDelay: 30000
        };
    }
    
    /**
     * Подключение к WebSocket серверу
     */
    connect(userId) {
        // Если WebSocket отключен (из-за ошибок безопасности), не пытаемся подключиться
        if (this.useWebSocket === false) {

            return;
        }
        
        if (this.isConnecting || (this.ws && this.ws.readyState === WebSocket.OPEN)) {

            return;
        }
        
        this.userId = userId;
        this.isConnecting = true;
        
        // Определяем URL WebSocket сервера динамически
        // ВСЕГДА переопределяем заново, без проверки существующего значения
        const isHttps = typeof window !== 'undefined' && window.location && window.location.protocol === 'https:';
        const hostname = typeof window !== 'undefined' && window.location ? window.location.hostname : 'localhost';

        // Для HTTPS используем wss:// на порту 8082 (прямое SSL подключение)
        // Для HTTP используем ws:// напрямую
        if (isHttps) {
            // Прямое SSL подключение к порту 8082 (без reverse proxy)
            this.config.wsUrl = `wss://${hostname}:8082`;
        } else {
            // Прямое подключение к порту
            this.config.wsUrl = `ws://${hostname}:8082`;
        }
        
        // Убираем слэш в конце, если есть
        this.config.wsUrl = this.config.wsUrl.replace(/\/+$/, '');
        
        // Финальная проверка: если URL все еще содержит ws:// для HTTPS, это критическая ошибка
        if (isHttps && this.config.wsUrl.startsWith('ws://')) {
            console.error('[ChatWebSocket] CRITICAL ERROR: Detected ws:// for HTTPS site!');
            console.error('[ChatWebSocket] This will be blocked by browser. Disabling WebSocket.');
            this.useWebSocket = false;
            this.notifyWebSocketDisabled();
            return;
        }

        // Проверяем валидность URL
        if (!this.config.wsUrl || this.config.wsUrl.includes('undefined') || this.config.wsUrl.includes('null')) {
            console.error('[ChatWebSocket] Invalid WebSocket URL:', this.config.wsUrl);
            console.error('[ChatWebSocket] Hostname:', typeof window !== 'undefined' && window.location ? window.location.hostname : 'undefined');
            this.isConnecting = false;
            return;
        }
        
        try {
            this.ws = new WebSocket(this.config.wsUrl);
            
            this.ws.onopen = () => {

                this.isConnecting = false;
                this.reconnectAttempts = 0;
                
                // Аутентификация
                this.authenticate();
            };
            
            this.ws.onmessage = (event) => {
                try {
                    this.handleMessage(event);
                } catch (error) {
                    console.error('[ChatWebSocket] Ошибка обработки сообщения:', error);
                    console.error('[ChatWebSocket] Данные сообщения:', event.data);
                }
            };
            
            this.ws.onerror = (error) => {
                console.error('[ChatWebSocket] WebSocket connection error');
                console.error('[ChatWebSocket] URL:', this.config.wsUrl);
                console.error('[ChatWebSocket] Error details:', error);
                
                // Проверяем, если это SecurityError (mixed content), отключаем WebSocket
                // НО не блокируем переподключение для обычных ошибок (сервер не запущен и т.д.)
                const errorString = error ? (error.message || error.toString() || '') : '';
                if (errorString.includes('insecure') || errorString.includes('Mixed Content') || errorString.includes('SecurityError')) {
                    console.error('[ChatWebSocket] Insecure WebSocket blocked (SecurityError). Disabling WebSocket.');
                    console.error('[ChatWebSocket] Chat functionality will use AJAX fallback.');
                    this.isConnecting = false;
                    this.disconnect();
                    // Устанавливаем флаг, что WebSocket отключен из-за SecurityError
                    this.useWebSocket = false;
                    this.notifyWebSocketDisabled();
                    return;
                }
                
                // Для обычных ошибок подключения просто логируем и продолжаем попытки переподключения

                this.isConnecting = false;
                // Не отключаем WebSocket для обычных ошибок - onclose обработает переподключение
            };
            
            this.ws.onclose = (event) => {

                this.isAuthenticated = false;
                this.isConnecting = false;
                this.stopPing();
                
                // Отключаем WebSocket только при реальных SecurityError
                // Коды 1006, 1002, 1003 могут означать просто, что сервер не запущен
                if (event.reason && (event.reason.includes('SecurityError') || event.reason.includes('Mixed Content'))) {

                    return;
                }
                
                // Переподключение - продолжаем попытки даже после ошибок подключения
                if (this.reconnectAttempts < this.maxReconnectAttempts && this.useWebSocket !== false) {
                    this.reconnectAttempts++;
                    const delay = Math.min(
                        this.config.reconnectDelay * Math.pow(2, this.reconnectAttempts - 1),
                        this.config.maxReconnectDelay
                    );
                    setTimeout(() => {
                        if (this.useWebSocket !== false) {
                            this.connect(this.userId);
                        }
                    }, delay);
                } else {
                    if (this.useWebSocket === false) {
                        console.error('[ChatWebSocket] WebSocket disabled. Will not reconnect.');
                    } else {

                        // Сбрасываем счетчик через некоторое время для повторной попытки
                        setTimeout(() => {
                            if (this.useWebSocket !== false) {
                                this.reconnectAttempts = 0;

                                this.connect(this.userId);
                            }
                        }, 10000); // Повторная попытка через 10 секунд
                    }
                }
            };
            
        } catch (error) {
            console.error('[ChatWebSocket] Connection error:', error);
            console.error('[ChatWebSocket] Error name:', error.name);
            console.error('[ChatWebSocket] Error message:', error.message);
            
            // Если это SecurityError (mixed content), отключаем WebSocket
            if (error.name === 'SecurityError' || 
                (error.message && (error.message.includes('insecure') || error.message.includes('Mixed Content')))) {

                console.error('[ChatWebSocket] WebSocket will be disabled. Chat functionality requires WebSocket connection.');
                
                return;
            }
            
            this.isConnecting = false;
        }
    }
    
    /**
     * Аутентификация
     */
    authenticate() {
        if (!this.userId) {
            console.error('[ChatWebSocket] Cannot authenticate: userId not set');
            return;
        }
        
        // Получаем токен аутентификации (CSRF токен или session ID)
        const token = window.csrfToken || this.getSessionToken() || '';
        
        console.log('[ChatWebSocket] Отправка запроса аутентификации', {
            userId: this.userId,
            hasToken: !!token,
            tokenLength: token.length
        });
        
        const authSent = this.send({
            type: 'auth',
            userId: this.userId,
            token: token
        });
        
        if (!authSent) {
            console.error('[ChatWebSocket] Не удалось отправить запрос аутентификации');
        }
    }
    
    /**
     * Получить токен сессии (session ID из cookie)
     */
    getSessionToken() {
        // Пытаемся получить session ID из cookie
        const cookies = document.cookie.split(';');
        for (let cookie of cookies) {
            const [name, value] = cookie.trim().split('=');
            if (name === 'PHPSESSID' || name.startsWith('PHPSESSID')) {
                return value;
            }
        }
        return null;
    }
    
    /**
     * Отправка сообщения
     */
    send(data) {
        if (!this.ws || this.ws.readyState !== WebSocket.OPEN) {

            return false;
        }
        
        try {
            this.ws.send(JSON.stringify(data));
            return true;
        } catch (error) {
            console.error('[ChatWebSocket] Send error:', error);
            return false;
        }
    }
    
    /**
     * Обработка входящих сообщений
     */
    handleMessage(event) {
        try {
            const data = JSON.parse(event.data);
            
            // Обработка ping/pong
            if (data.type === 'ping') {
                this.send({ type: 'pong' });
                return;
            }
            
            if (data.type === 'auth_success') {
                console.log('[ChatWebSocket] Аутентификация успешна', data);
                this.isAuthenticated = true;
                this.reconnectAttempts = 0; // Сбрасываем счетчик переподключений
                this.startPing();
                
                // Переподписываемся на все активные чаты
                this.resubscribeAll();
                
                // Запрашиваем начальные счетчики непрочитанных
                this.requestUnreadCounts();
                
                // Запускаем периодическое обновление времени
                this.startTimeUpdateInterval();
                
                return;
            }
            
            // Обработка ошибок аутентификации
            if (data.type === 'error' && data.message && data.message.includes('аутентификац')) {
                console.error('[ChatWebSocket] Ошибка аутентификации:', data.message);
                this.isAuthenticated = false;
                // Не переподключаемся автоматически при ошибке аутентификации
                return;
            }
            
            // Обработка сообщений для счетчиков непрочитанных
            this.handleUnreadCountsMessage(data);
            
            // Вызываем обработчики для этого типа сообщения
            if (this.messageHandlers.has(data.type)) {
                this.messageHandlers.get(data.type).forEach(handler => {
                    try {
                        handler(data);
                    } catch (error) {
                        console.error('[ChatWebSocket] Handler error:', error);
                    }
                });
            }
            
        } catch (error) {
            console.error('[ChatWebSocket] Message parse error:', error);
        }
    }
    
    /**
     * Подписка на чат
     */
    subscribeChat(chatType, chatId, chatManager) {
        if (!this.isAuthenticated) {

            return;
        }
        
        const chatKey = `${chatType}_${chatId}`;
        
        if (this.subscribedChats.has(chatKey)) {

            // Обновляем ссылку на менеджер
            this.chatManagers.set(chatKey, chatManager);
            return;
        }
        
        this.send({
            type: 'subscribe_chat',
            chat_type: chatType,
            chat_id: chatId
        });
        
        this.subscribedChats.add(chatKey);
        this.chatManagers.set(chatKey, chatManager);

    }
    
    /**
     * Отписка от чата
     */
    unsubscribeChat(chatType, chatId) {
        const chatKey = `${chatType}_${chatId}`;
        
        if (!this.subscribedChats.has(chatKey)) {
            return;
        }
        
        if (this.isAuthenticated) {
            this.send({
                type: 'unsubscribe_chat',
                chat_type: chatType,
                chat_id: chatId
            });
        }
        
        this.subscribedChats.delete(chatKey);
        this.chatManagers.delete(chatKey);

    }
    
    /**
     * Переподписка на все активные чаты
     */
    resubscribeAll() {
        for (const chatKey of this.subscribedChats) {
            const [chatType, chatId] = chatKey.split('_');
            const chatManager = this.chatManagers.get(chatKey);
            
            if (chatManager) {
                this.subscribeChat(chatType, parseInt(chatId), chatManager);
            }
        }
    }
    
    /**
     * Отправка сообщения в чат через WebSocket
     */
    sendChatMessage(chatType, chatId, message) {
        if (!this.isAuthenticated) {
            console.error('[ChatWebSocket] Cannot send message: not authenticated');
            return false;
        }
        
        if (!this.ws || this.ws.readyState !== WebSocket.OPEN) {
            console.error('[ChatWebSocket] Cannot send message: WebSocket not connected');
            return false;
        }
        
        return this.send({
            type: 'send_message',
            chat_type: chatType,
            chat_id: chatId,
            message: message
        });
    }
    
    /**
     * Отметить сообщения как прочитанные
     */
    markAsRead(chatType, chatId) {
        if (!this.isAuthenticated) {
            console.error('[ChatWebSocket] Cannot mark as read: not authenticated');
            return false;
        }
        
        return this.send({
            type: 'mark_read',
            chat_type: chatType,
            chat_id: chatId
        });
    }
    
    /**
     * Подтвердить транзакцию
     */
    confirmTransaction(transactionId) {
        if (!this.isAuthenticated) {
            console.error('[ChatWebSocket] Cannot confirm transaction: not authenticated');
            return false;
        }
        
        return this.send({
            type: 'confirm_transaction',
            transaction_id: transactionId
        });
    }
    
    /**
     * Регистрация обработчика сообщений
     */
    onMessageType(type, handler) {
        if (!this.messageHandlers.has(type)) {
            this.messageHandlers.set(type, []);
        }
        this.messageHandlers.get(type).push(handler);
    }
    
    /**
     * Удаление обработчика сообщений
     */
    offMessageType(type, handler) {
        if (!this.messageHandlers.has(type)) {
            return;
        }
        
        const handlers = this.messageHandlers.get(type);
        const index = handlers.indexOf(handler);
        if (index > -1) {
            handlers.splice(index, 1);
        }
    }
    
    /**
     * Запуск ping
     */
    startPing() {
        this.stopPing();
        
        this.pingInterval = setInterval(() => {
            if (this.ws && this.ws.readyState === WebSocket.OPEN) {
                // Сервер отправляет ping, мы отвечаем pong
                // Просто проверяем, что соединение живое
            }
        }, this.config.pingInterval);
    }
    
    /**
     * Остановка ping
     */
    stopPing() {
        if (this.pingInterval) {
            clearInterval(this.pingInterval);
            this.pingInterval = null;
        }
        if (this.pongTimeout) {
            clearTimeout(this.pongTimeout);
            this.pongTimeout = null;
        }
    }
    
    /**
     * Уведомить все ChatManager, что WebSocket отключен
     */
    notifyWebSocketDisabled() {
        // Вызываем обработчик, если он зарегистрирован
        if (this.messageHandlers.has('websocket_disabled')) {
            this.messageHandlers.get('websocket_disabled').forEach(handler => {
                try {
                    handler();
                } catch (error) {
                    console.error('[ChatWebSocket] Error in websocket_disabled handler:', error);
                }
            });
        }
        
        // Также уведомляем через событие
        if (typeof window !== 'undefined') {
            window.dispatchEvent(new CustomEvent('websocket-disabled'));
        }
    }
    
    /**
     * Отключение
     */
    disconnect() {
        this.stopPing();
        this.stopTimeUpdate();
        this.stopFallbackPolling();
        
        if (this.ws) {
            this.ws.close();
            this.ws = null;
        }
        
        this.isAuthenticated = false;
        this.subscribedChats.clear();
        this.chatManagers.clear();
        this.messageHandlers.clear();
    }
    
    // ========================================================================
    // Методы для обновления счетчиков непрочитанных сообщений
    // ========================================================================
    
    /**
     * Обработка сообщений WebSocket для счетчиков непрочитанных
     */
    handleUnreadCountsMessage(data) {
        switch (data.type) {
            case 'unread_counts_update':
                // Debounce обновлений - обновляем только если прошло достаточно времени с последнего обновления
                const now = Date.now();
                if (now - this.lastUpdateTime < this.updateDebounceDelay) {
                    // Слишком частое обновление - отменяем предыдущее и планируем новое
                    if (this.updateTimeout) {
                        clearTimeout(this.updateTimeout);
                    }
                    this.updateTimeout = setTimeout(() => {
                        if (data.unread_counts) {
                            this.updateChatUnreadBadges(data.unread_counts);
                        }
                        this.lastUpdateTime = Date.now();
                        this.updateTimeout = null;
                    }, this.updateDebounceDelay);
                } else {
                    // Обновляем сразу
                    if (data.unread_counts) {
                        this.updateChatUnreadBadges(data.unread_counts);
                    }
                    this.lastUpdateTime = now;
                }
                break;
                
            case 'new_message':
                // Обновляем последнее сообщение в списке чатов (если сообщение не из открытого чата)
                if (data.message && data.chat_type && data.chat_id) {
                    // Проверяем, не открыт ли этот чат
                    const chatKey = `${data.chat_type}_${data.chat_id}`;
                    if (!this.chatManagers.has(chatKey)) {
                        // Чат не открыт, обновляем превью в списке
                        this.updateLastMessage(data.chat_type, data.chat_id, data.message);
                    }
                    // Запрашиваем обновление счетчиков
                    this.requestUnreadCounts();
                }
                break;
                
            case 'mark_read_success':
                // После отметки как прочитанное обновляем счетчик для этого чата
                if (data.chat_type && data.chat_id) {
                    this.updateChatBadge(data.chat_type, data.chat_id, 0);
                    // Запрашиваем полное обновление счетчиков
                    this.requestUnreadCounts();
                }
                break;
                
            case 'transaction_status_updated':
            case 'transaction_confirmed':
                // При обновлении статуса транзакции обновляем счетчики
                if (data.transaction_id) {
                    this.requestUnreadCounts();
                }
                break;
        }
    }
    
    /**
     * Запрос обновления счетчиков через WebSocket
     */
    requestUnreadCounts() {
        if (this.isAuthenticated && this.ws && this.ws.readyState === WebSocket.OPEN) {
            this.send({
                type: 'get_unread_counts'
            });
        }
    }
    
    /**
     * Обновление всех непрочитанных счетчиков через WebSocket
     */
    updateAllUnreadCounts() {
        if (this.isAuthenticated && this.ws && this.ws.readyState === WebSocket.OPEN) {
            this.requestUnreadCounts();
        }
    }
    
    /**
     * Обновление бейджей непрочитанных сообщений в списке чатов
     */
    updateChatUnreadBadges(unreadCounts) {
        // Собираем все чаты с непрочитанными сообщениями
        const unreadMap = new Map();
        
        if (unreadCounts.transactions) {
            unreadCounts.transactions.forEach(item => {
                unreadMap.set(`transaction_${item.id}`, item.count);
                this.updateChatBadge('transaction', item.id, item.count);
            });
        }
        
        if (unreadCounts.community) {
            unreadCounts.community.forEach(item => {
                unreadMap.set(`community_${item.id}`, item.count);
                this.updateChatBadge('community', item.id, item.count);
            });
        }
        
        // Удаляем бейджи у чатов, которых нет в списке непрочитанных
        const allChatItems = document.querySelectorAll('.telegram-chat-item');
        allChatItems.forEach(item => {
            const chatType = item.getAttribute('data-chat-type');
            const chatId = item.getAttribute('data-chat-id');
            
            if (!chatType || !chatId) return;
            
            const key = `${chatType}_${chatId}`;
            if (!unreadMap.has(key)) {
                this.updateChatBadge(chatType, chatId, 0);
            }
        });
    }
    
    /**
     * Обновление бейджа для конкретного чата
     */
    updateChatBadge(chatType, chatId, count) {
        const chatItem = document.querySelector(
            `.telegram-chat-item[data-chat-type="${chatType}"][data-chat-id="${chatId}"]`
        );
        
        if (!chatItem) return;
        
        const badge = chatItem.querySelector('.telegram-unread-badge');
        const preview = chatItem.querySelector('.telegram-chat-preview');
        
        if (count > 0) {
            // Добавляем или обновляем бейдж
            if (badge) {
                badge.textContent = count;
            } else if (preview) {
                const newBadge = document.createElement('span');
                newBadge.className = 'telegram-unread-badge';
                newBadge.textContent = count;
                preview.appendChild(newBadge);
            }
            
            // Добавляем класс непрочитанного
            chatItem.classList.add('telegram-chat-unread');
        } else {
            // Удаляем бейдж
            if (badge) {
                badge.remove();
            }
            
            // Удаляем класс непрочитанного
            chatItem.classList.remove('telegram-chat-unread');
        }
    }
    
    /**
     * Обновление превью последнего сообщения в списке чатов
     */
    updateLastMessage(chatType, chatId, messageData) {
        const chatItem = document.querySelector(
            `.telegram-chat-item[data-chat-type="${chatType}"][data-chat-id="${chatId}"]`
        );
        
        if (!chatItem) {
            return;
        }
        
        const preview = chatItem.querySelector('.telegram-chat-preview');
        const timeElement = chatItem.querySelector('.telegram-chat-time');
        
        if (!preview) {
            return;
        }
        
        // Проверяем наличие данных сообщения
        if (!messageData || !messageData.created_at) {
            return;
        }
        
        // Проверяем, не является ли это сообщение старше уже сохраненного
        const existingLastMessageTime = chatItem.dataset.lastMessageTime;
        if (existingLastMessageTime) {
            const existingTime = new Date(existingLastMessageTime).getTime();
            const newTime = new Date(messageData.created_at).getTime();
            if (newTime < existingTime) {
                return;
            }
        }
        
        // Форматируем время
        const timeString = this.formatRelativeTime(messageData.created_at);
        
        // Определяем автора (свои сообщения показываем как "Вы")
        const isOwn = messageData.is_own === true || messageData.is_own === 'true' || messageData.is_own === 1;
        const authorName = isOwn ? 'Вы' : (messageData.user_name || 'Неизвестный');
        
        // Форматируем текст сообщения (максимум 50 символов)
        let messageText = String(messageData.message || '');
        if (messageText.length > 50) {
            messageText = messageText.substring(0, 50) + '...';
        }
        // Экранируем HTML
        messageText = messageText.replace(/&/g, '&amp;').replace(/</g, '&lt;').replace(/>/g, '&gt;');
        
        // Формируем текст превью
        const previewTextContent = authorName + ': ' + messageText;
        
        // Обновляем текст превью
        const previewText = preview.querySelector('.telegram-preview-text');
        if (previewText) {
            previewText.textContent = previewTextContent;
        } else {
            preview.innerHTML = '<span class="telegram-preview-text">' + previewTextContent + '</span>';
        }
        
        // Сохраняем время последнего сообщения в data-атрибуте
        chatItem.dataset.lastMessageTime = messageData.created_at;
        
        // Сохраняем последнее сообщение в localStorage для восстановления при обновлении страницы
        const storageKey = `chat_last_message_${chatType}_${chatId}`;
        try {
            localStorage.setItem(storageKey, JSON.stringify({
                message: messageText,
                authorName: authorName,
                createdAt: messageData.created_at,
                isOwn: isOwn
            }));
        } catch (e) {
            // localStorage недоступен
        }
        
        // Обновляем время
        if (timeElement) {
            timeElement.textContent = timeString;
        }
        
        // Перемещаем чат в начало списка
        const chatList = chatItem.closest('.telegram-chat-list') || chatItem.closest('.telegram-chats-list');
        if (chatList && chatItem !== chatList.firstElementChild) {
            chatList.insertBefore(chatItem, chatList.firstElementChild);
        }
    }
    
    /**
     * Форматирование времени относительного (только что, X мин назад, и т.д.)
     */
    formatRelativeTime(createdAt) {
        const now = new Date();
        const messageTime = new Date(createdAt);
        const diff = Math.floor((now - messageTime) / 1000); // разница в секундах
        
        if (diff < 60) {
            return 'только что';
        } else if (diff < 3600) {
            const minutes = Math.floor(diff / 60);
            return minutes + ' мин назад';
        } else if (diff < 86400) {
            const hours = Math.floor(diff / 3600);
            return hours + ' ч назад';
        } else if (diff < 604800) {
            const days = Math.floor(diff / 86400);
            return days + ' дн назад';
        } else {
            return messageTime.toLocaleDateString('ru-RU', { day: '2-digit', month: '2-digit' });
        }
    }
    
    /**
     * Периодическое обновление времени в списке чатов (каждую минуту)
     */
    startTimeUpdateInterval() {
        if (this.timeUpdateInterval) {
            clearInterval(this.timeUpdateInterval);
        }
        
        this.timeUpdateInterval = setInterval(() => {
            if (this.isAuthenticated) {
                this.updateAllChatTimes();
            }
        }, 60000); // каждую минуту
    }
    
    /**
     * Остановка обновления времени
     */
    stopTimeUpdate() {
        if (this.timeUpdateInterval) {
            clearInterval(this.timeUpdateInterval);
            this.timeUpdateInterval = null;
        }
        if (this.updateTimeout) {
            clearTimeout(this.updateTimeout);
            this.updateTimeout = null;
        }
    }
    
    /**
     * Обновление времени для всех чатов в списке
     */
    updateAllChatTimes() {
        const chatItems = document.querySelectorAll('.telegram-chat-item');
        chatItems.forEach(chatItem => {
            const timeElement = chatItem.querySelector('.telegram-chat-time');
            
            if (!timeElement) return;
            
            // Получаем время последнего сообщения из data-атрибута
            const lastMessageTime = chatItem.dataset.lastMessageTime;
            if (!lastMessageTime) return;
            
            // Пересчитываем и обновляем время
            const timeString = this.formatRelativeTime(lastMessageTime);
            timeElement.textContent = timeString;
        });
    }
    
    /**
     * Периодическая проверка подключения WebSocket
     */
    startFallbackPolling() {
        if (this.updateInterval) {
            clearInterval(this.updateInterval);
        }
        
        this.updateInterval = setInterval(() => {
            // Если WebSocket не подключен, пытаемся переподключиться
            if (!this.isAuthenticated || !this.ws || this.ws.readyState !== WebSocket.OPEN) {
                if (this.userId) {
                    this.connect(this.userId);
                }
            }
        }, 30000); // каждые 30 секунд
    }
    
    /**
     * Остановка fallback polling
     */
    stopFallbackPolling() {
        if (this.updateInterval) {
            clearInterval(this.updateInterval);
            this.updateInterval = null;
        }
    }
    
    /**
     * Инициализация времени последних сообщений из HTML
     * Сохраняет время последнего сообщения из PHP-рендеринга в data-атрибуты
     * И восстанавливает последнее сообщение из localStorage, если оно новее
     */
    initializeLastMessageTimes() {
        const chatItems = document.querySelectorAll('.telegram-chat-item');
        chatItems.forEach(chatItem => {
            const chatType = chatItem.getAttribute('data-chat-type');
            const chatId = chatItem.getAttribute('data-chat-id');
            
            if (!chatType || !chatId) return;
            
            // Проверяем, есть ли время в data-last-message-time (из PHP)
            const phpLastMessageTime = chatItem.getAttribute('data-last-message-time');
            if (phpLastMessageTime) {
                // Сохраняем время из PHP в dataset для использования в JavaScript
                chatItem.dataset.lastMessageTime = phpLastMessageTime;
            }
            
            // Проверяем, есть ли сохраненное последнее сообщение в localStorage
            const storageKey = `chat_last_message_${chatType}_${chatId}`;
            try {
                const savedLastMessage = localStorage.getItem(storageKey);
                if (savedLastMessage) {
                    const lastMessageData = JSON.parse(savedLastMessage);
                    
                    // Сравниваем время сохраненного сообщения с временем из PHP
                    const savedTime = new Date(lastMessageData.createdAt).getTime();
                    const phpTime = phpLastMessageTime ? new Date(phpLastMessageTime).getTime() : 0;
                    
                    // Если сохраненное сообщение новее, чем из PHP, восстанавливаем его
                    if (savedTime > phpTime) {
                        // Восстанавливаем последнее сообщение
                        const preview = chatItem.querySelector('.telegram-chat-preview');
                        const timeElement = chatItem.querySelector('.telegram-chat-time');
                        
                        if (preview) {
                            const previewText = preview.querySelector('.telegram-preview-text');
                            const previewTextContent = lastMessageData.authorName + ': ' + lastMessageData.message;
                            
                            if (previewText) {
                                previewText.textContent = previewTextContent;
                            } else {
                                preview.innerHTML = '<span class="telegram-preview-text">' + previewTextContent + '</span>';
                            }
                            
                            // Обновляем время
                            if (timeElement) {
                                timeElement.textContent = this.formatRelativeTime(lastMessageData.createdAt);
                            }
                            
                            // Обновляем data-атрибут
                            chatItem.dataset.lastMessageTime = lastMessageData.createdAt;
                        }
                    }
                }
            } catch (e) {
                // Ошибка при работе с localStorage
            }
        });
    }
}

// Глобальный экземпляр
if (typeof window !== 'undefined') {
    window.chatWebSocket = new ChatWebSocketManager();
}




// ============================================================================
// chat-manager.js
// ============================================================================

class ChatManager {
    constructor(options = {}) {
        // Основные параметры
        this.chatId = options.chatId || null;
        this.chatType = options.chatType || 'transaction'; // 'transaction' | 'community'
        this.container = options.container || document;
        this.isEmbedded = options.isEmbedded || false;
        
        // Элементы DOM
        this.messagesContainer = null;
        this.messageForm = null;
        this.messageInput = null;
        
        // Состояние
        this.lastMessageDate = null;
        this.isScrolledToBottom = true;
        this.isInitialized = false;
        
        // Интервалы и контроллеры
        this.updateInterval = null;
        
        // Настройки
        this.config = {
            scrollThreshold: 100, // пикселей
            maxTextareaHeight: 150 // пикселей
        };
        
        // Колбэки
        this.onMessageSent = options.onMessageSent || null;
        this.onNewMessage = options.onNewMessage || null;
        this.onStatusChanged = options.onStatusChanged || null;
        
        // Привязка методов
        this.handleFormSubmit = this.handleFormSubmit.bind(this);
        this.handleKeydown = this.handleKeydown.bind(this);
        this.handleScroll = this.handleScroll.bind(this);
        this.handleTextareaInput = this.handleTextareaInput.bind(this);
    }
    
    /**
     * Инициализация чата
     */
    init() {
        if (this.isInitialized) {
            return;
        }
        
        // Поиск элементов
        this.messagesContainer = this.container.querySelector('#chatMessages') || 
                                 this.container.querySelector('.chat-messages');
        this.messageForm = this.container.querySelector('#messageForm') || 
                          this.container.querySelector('.chat-input-form');
        this.messageInput = this.container.querySelector('#messageInput') || 
                           this.container.querySelector('textarea[name="message"], input[name="message"]');
        
        // Проверка наличия необходимых элементов
        if (!this.messagesContainer || !this.messageForm || !this.messageInput) {
            return false;
        }
        
        // Инициализация даты последнего сообщения
        this.initLastMessageDate();
        
        // Подключение обработчиков
        this.attachEventHandlers();
        
        // Автопрокрутка вниз
        this.scrollToBottom();
        
        // Подключение к WebSocket через глобальный менеджер
        this.initWebSocket();
        
        // Обновление при возвращении на страницу
        this.attachVisibilityHandler();
        
        // Очистка при уходе со страницы
        this.attachBeforeUnloadHandler();
        
        this.isInitialized = true;
        
        return true;
    }
    
    /**
     * Инициализация даты последнего сообщения
     */
    initLastMessageDate() {
        const lastMessage = this.messagesContainer.querySelector('.message-item:last-child');
        if (lastMessage && lastMessage.dataset.createdAt) {
            this.lastMessageDate = lastMessage.dataset.createdAt;
        } else {
            // Устанавливаем дату 1 час назад для первого обновления
            const now = new Date();
            now.setHours(now.getHours() - 1);
            this.lastMessageDate = now.toISOString();
        }
    }
    
    /**
     * Подключение обработчиков событий
     */
    attachEventHandlers() {
        // Обработчик отправки формы
        this.messageForm.addEventListener('submit', this.handleFormSubmit);
        
        // Обработчик клавиатуры (Enter для отправки, Shift+Enter для новой строки)
        this.messageInput.addEventListener('keydown', this.handleKeydown);
        
        // Автоматическое изменение размера textarea
        this.messageInput.addEventListener('input', this.handleTextareaInput);
        
        // Отслеживание прокрутки
        this.messagesContainer.addEventListener('scroll', this.handleScroll);
    }
    
    /**
     * Обработчик отправки формы
     */
    handleFormSubmit(e) {
        e.preventDefault();
        e.stopPropagation();
        
        if (!this.chatId) {
            this.showError('Ошибка: ID чата не установлен');
            return false;
        }
        
        const message = this.messageInput.value.trim();
        if (!message) {
            return false;
        }
        
        this.sendMessage(message);
        return false;
    }
    
    /**
     * Обработчик нажатия клавиш
     */
    handleKeydown(e) {
        if (e.key === 'Enter' && !e.shiftKey) {
            e.preventDefault();
            this.messageForm.dispatchEvent(new Event('submit', { cancelable: true, bubbles: true }));
        }
    }
    
    /**
     * Обработчик прокрутки
     */
    handleScroll() {
        const threshold = this.config.scrollThreshold;
        this.isScrolledToBottom = 
            this.messagesContainer.scrollHeight - 
            this.messagesContainer.scrollTop - 
            this.messagesContainer.clientHeight < threshold;
    }
    
    /**
     * Обработчик изменения размера textarea
     */
    handleTextareaInput() {
        this.messageInput.style.height = 'auto';
        this.messageInput.style.height = Math.min(this.messageInput.scrollHeight, this.config.maxTextareaHeight) + 'px';
    }
    
    /**
     * Отправка сообщения
     */
    async sendMessage(messageText) {
        if (!this.chatId) {
            return;
        }
        
        // Блокируем форму
        const submitButton = this.messageForm.querySelector('button[type="submit"]');
        const originalButtonHTML = submitButton ? submitButton.innerHTML : '';
        if (submitButton) {
            submitButton.disabled = true;
            submitButton.innerHTML = 'Отправка...';
        }
        
        try {
            // Отправка через ChatWebSocketManager через WebSocket
            if (typeof window.chatWebSocket === 'undefined') {
                throw new Error('ChatWebSocketManager не доступен');
            }
            
            const sent = window.chatWebSocket.sendChatMessage(this.chatType, this.chatId, messageText);
            
            if (!sent) {
                throw new Error('Не удалось отправить сообщение через WebSocket');
            }
            
            // Очищаем поле ввода сразу (optimistic update)
            this.messageInput.value = '';
            this.messageInput.style.height = 'auto';
            
            // Сообщение будет добавлено через WebSocket callback
        } catch (error) {
            this.showError('Ошибка при отправке сообщения: ' + error.message);
            // Возвращаем текст в поле
            this.messageInput.value = messageText;
        } finally {
            // Разблокируем форму
            if (submitButton) {
                submitButton.disabled = false;
                submitButton.innerHTML = originalButtonHTML;
            }
            this.messageInput.focus();
        }
    }
    
    /**
     * Добавление сообщения в чат
     */
    addMessageToChat(messageData, isOwn) {
        if (!this.messagesContainer) return;
        
        // Проверяем, нет ли уже такого сообщения
        const existingMessage = this.messagesContainer.querySelector(
            `.message-item[data-message-id="${messageData.id}"]`
        );
        if (existingMessage) {
            return;
        }
        
        // Форматируем время
        const createdAt = new Date(messageData.created_at);
        const timeString = this.formatTime(createdAt);
        
        // Создаём элемент сообщения
        const messageDiv = document.createElement('div');
        messageDiv.className = `message-item ${isOwn ? 'message-own' : 'message-other'}`;
        messageDiv.dataset.createdAt = messageData.created_at;
        messageDiv.dataset.messageId = messageData.id;
        
        // Аватар - используем HTML из сервера или создаем из URL
        let avatarHtml = '';
        if (messageData.user_avatar_html) {
            // Используем готовый HTML аватара с сервера
            avatarHtml = messageData.user_avatar_html;
        } else if (messageData.user_avatar_url) {
            // Используем URL для создания изображения
            avatarHtml = `<img src="${this.escapeHtml(messageData.user_avatar_url)}" alt="${this.escapeHtml(messageData.user_name || '')}" class="message-avatar-image" loading="lazy">`;
        } else {
            // Fallback - показываем первую букву имени
            const avatarLetter = (messageData.user_name || '?').charAt(0).toUpperCase();
            avatarHtml = `<div class="message-avatar-placeholder">${avatarLetter}</div>`;
        }
        
        messageDiv.innerHTML = `
            <div class="message-avatar">${avatarHtml}</div>
            <div class="message-content">
                <div class="message-header">
                    <span class="message-author">${this.escapeHtml(messageData.user_name || 'Неизвестный')}</span>
                    <span class="message-time">${timeString}</span>
                </div>
                <div class="message-body">${this.formatMessageText(messageData.message)}</div>
            </div>
        `;
        
        // Добавляем с анимацией
        this.messagesContainer.appendChild(messageDiv);
        messageDiv.style.opacity = '0';
        messageDiv.style.transform = 'translateY(10px)';
        
        setTimeout(() => {
            messageDiv.style.transition = 'all 0.3s ease';
            messageDiv.style.opacity = '1';
            messageDiv.style.transform = 'translateY(0)';
        }, 10);
        
        // Вызываем колбэк
        if (this.onNewMessage) {
            this.onNewMessage(messageData, isOwn);
        }
    }
    
    /**
     * Обновление сообщений
     */
    async updateMessages() {
        if (!this.chatId) return;
        
        try {
            const url = this.buildMessagesUrl();
            
            const response = await fetch(url);
            const data = await response.json();
            
            if (data.success && data.messages) {
                const existingMessageIds = new Set();
                this.messagesContainer.querySelectorAll('.message-item[data-message-id]').forEach(el => {
                    const msgId = el.dataset.messageId;
                    if (msgId) existingMessageIds.add(msgId);
                });
                
                let hasNewMessages = false;
                const wasAtBottom = this.isScrolledToBottom;
                
                data.messages.forEach(msg => {
                    if (!existingMessageIds.has(msg.id.toString())) {
                        this.addMessageToChat(msg, msg.is_own);
                        hasNewMessages = true;
                        
                        // Обновляем lastMessageDate
                        if (!this.lastMessageDate || new Date(msg.created_at) > new Date(this.lastMessageDate)) {
                            this.lastMessageDate = msg.created_at;
                        }
                    }
                });
                
                // Прокручиваем вниз, если пользователь был внизу или есть новые сообщения
                if (wasAtBottom || hasNewMessages) {
                    this.scrollToBottom();
                }
                
                // Отмечаем как прочитанное ТОЛЬКО если чат открыт и видим пользователю
                if (hasNewMessages && this.isChatVisible()) {
                    this.markAsRead();
                }
            }
        } catch (error) {
            // Ошибка обновления сообщений
        }
    }
    
    /**
     * Построение URL для получения сообщений
     */
    buildMessagesUrl() {
        const baseUrl = this.chatType === 'community'
            ? '/api/chat.php?action=get_community_messages'
            : '/api/chat.php?action=get_messages';
        
        const params = new URLSearchParams();
        if (this.chatType === 'community') {
            params.append('community_request_id', this.chatId);
        } else {
            params.append('transaction_id', this.chatId);
        }
        
        if (this.lastMessageDate) {
            params.append('after_date', this.lastMessageDate);
        }
        
        return `${baseUrl}&${params.toString()}`;
    }
    
    /**
     * Инициализация WebSocket подключения для чата
     */
    initWebSocket() {
        // Проверяем, что WebSocket менеджер доступен
        if (typeof window.chatWebSocket === 'undefined') {
            // Загружаем WebSocket менеджер
            const script = document.createElement('script');
            script.src = '/js/chat-websocket.js';
            script.onload = () => {
                this.initWebSocket();
            };
            document.head.appendChild(script);
            return;
        }
        
        // Получаем userId из window.currentUserId или из элемента страницы
        const userId = window.currentUserId || (document.body.dataset.userId ? parseInt(document.body.dataset.userId) : null);
        
        if (!userId) {
            return;
        }
        
        // Подключаемся к WebSocket серверу
        if (!window.chatWebSocket.isAuthenticated && !window.chatWebSocket.isConnecting) {
            window.chatWebSocket.connect(userId);
        }
        
        // Подписываемся на переподключение WebSocket (только если WebSocket был отключен из-за SecurityError)
        window.chatWebSocket.onMessageType('websocket_disabled', () => {
            // Не пытаемся переподключаться автоматически, так как это SecurityError
        });
        
        // Также слушаем событие window для отключения WebSocket
        window.addEventListener('websocket-disabled', () => {
            // Не пытаемся переподключаться автоматически, так как это SecurityError
        });
        
        // Регистрируем обработчики сообщений
        // Обработка уведомления о создании векселя
        window.chatWebSocket.onMessageType('bill_created', (data) => {
            console.log('[Chat] Вексель создан:', data);
            
            // Если это для текущего чата, обновляем чат
            if (data.transaction_id && chatManager && 
                chatManager.chatType === 'transaction' && 
                chatManager.chatId === data.transaction_id) {
                
                // Перезагружаем чат, чтобы показать новое сообщение о векселе
                setTimeout(() => {
                    if (chatManager && typeof chatManager.loadMessages === 'function') {
                        chatManager.loadMessages();
                    }
                }, 500);
            }
            
            // Показываем уведомление пользователю
            if (typeof Toast !== 'undefined') {
                Toast.success(`Вексель #${data.bill.id} создан!`);
            } else {
                console.log(`Вексель #${data.bill.id} создан: ${data.bill.nominal} ₽`);
            }
        });
        
        window.chatWebSocket.onMessageType('new_message', (data) => {
            this.handleWebSocketMessage(data);
        });
        
        window.chatWebSocket.onMessageType('chat_subscribed', (data) => {
            // Подписка на чат успешна
        });
        
        // Обработчик успешной отметки как прочитанного
        window.chatWebSocket.onMessageType('mark_read_success', (data) => {
            if (data.chat_type === this.chatType && data.chat_id === this.chatId) {
                // Обновляем бейдж непрочитанных в списке чатов
                if (window.chatWebSocket && this.isEmbedded) {
                    window.chatWebSocket.updateChatBadge(this.chatType, this.chatId, 0);
                }
            }
        });
        
        // Обработчик обновления статуса транзакции
        window.chatWebSocket.onMessageType('transaction_status_updated', (data) => {
            if (this.chatType === 'transaction' && data.transaction_id === this.chatId) {
                if (this.onStatusChanged && data.transaction_status) {
                    this.onStatusChanged(data.transaction_status);
                }
            }
        });
        
        // Обработчик подтверждения транзакции
        window.chatWebSocket.onMessageType('transaction_confirmed', (data) => {
            if (this.chatType === 'transaction' && data.transaction_id === this.chatId) {
                if (this.onStatusChanged && data.transaction_status) {
                    this.onStatusChanged(data.transaction_status);
                }
            }
        });
        
        // Обработчик уведомления о создании векселя
        window.chatWebSocket.onMessageType('bill_created', (data) => {
            if (this.chatType === 'transaction' && data.transaction_id === this.chatId) {
                // Перезагружаем сообщения, чтобы показать новое сообщение о векселе
                setTimeout(() => {
                    this.loadMessages();
                }, 500);
                
                // Показываем уведомление
                if (typeof Toast !== 'undefined') {
                    Toast.success(`Вексель #${data.bill.id} создан!`);
                }
            }
        });
        
        // Подписываемся на чат
        if (this.chatId && window.chatWebSocket.isAuthenticated) {
            window.chatWebSocket.subscribeChat(this.chatType, this.chatId, this);
        } else {
            // Подпишемся после аутентификации
            window.chatWebSocket.onMessageType('auth_success', () => {
                if (this.chatId) {
                    window.chatWebSocket.subscribeChat(this.chatType, this.chatId, this);
                }
            });
        }
    }
    
    /**
     * Обработка сообщения от WebSocket
     */
    handleWebSocketMessage(data) {
        // Проверяем, что сообщение для этого чата
        if (data.chat_type !== this.chatType || data.chat_id !== this.chatId) {
            return;
        }
        
        // Для embedded чатов проверяем, что чат действительно открыт
        // Не обрабатываем сообщения для неоткрытых embedded чатов
        if (this.isEmbedded && !this.isChatVisible()) {
            return;
        }
        
        if (data.message) {
            const existingMessageIds = new Set();
            this.messagesContainer.querySelectorAll('.message-item[data-message-id]').forEach(el => {
                const msgId = el.dataset.messageId;
                if (msgId) existingMessageIds.add(msgId);
            });
            
            // Проверяем, что сообщение еще не добавлено
            if (!existingMessageIds.has(data.message.id.toString())) {
                const wasAtBottom = this.isScrolledToBottom;
                
                // Добавляем сообщение
                this.addMessageToChat(data.message, data.message.is_own);
                
                // Обновляем дату последнего сообщения
                if (!this.lastMessageDate || new Date(data.message.created_at) > new Date(this.lastMessageDate)) {
                    this.lastMessageDate = data.message.created_at;
                }
                
                // Прокручиваем вниз, если пользователь был внизу
                if (wasAtBottom) {
                    this.scrollToBottom();
                }
                
                // Отмечаем как прочитанное ТОЛЬКО если:
                // 1. Чат открыт и видим пользователю
                // 2. Сообщение не свое (свои сообщения не нужно помечать как прочитанные)
                if (this.isChatVisible() && !data.message.is_own) {
                    this.markAsRead();
                }
                
                // Вызываем колбэк
                if (this.onNewMessage) {
                    this.onNewMessage(data.message);
                }
                
                // Обновляем последнее сообщение в списке чатов (если есть)
                // Это нужно и для входящих сообщений, и для подтверждений отправки
                if (window.chatWebSocket && typeof window.chatWebSocket.updateLastMessage === 'function') {
                    window.chatWebSocket.updateLastMessage(this.chatType, this.chatId, data.message);
                }
            }
        }
        
        // Обрабатываем уведомление о создании векселя
        if (data.type === 'bill_created') {
            // Проверяем, что это для текущего чата
            if (data.transaction_id && this.chatType === 'transaction' && this.chatId === data.transaction_id) {
                // Перезагружаем сообщения, чтобы показать новое сообщение о векселе
                setTimeout(() => {
                    this.loadMessages();
                }, 500);
            }
            return;
        }
        
        // Обрабатываем изменения статуса транзакции
        if (data.transaction_status && this.onStatusChanged) {
            this.onStatusChanged(data.transaction_status);
        }
    }

    /**
     * Прокрутка вниз
     */
    scrollToBottom() {
        if (this.messagesContainer) {
            this.messagesContainer.scrollTop = this.messagesContainer.scrollHeight;
        }
    }
    
    /**
     * Проверка, видим ли чат пользователю
     */
    isChatVisible() {
        if (!this.messagesContainer) {
            return false;
        }
        
        // Для embedded чатов проверяем, видим ли контейнер
        if (this.isEmbedded) {
            const container = this.messagesContainer.closest('.telegram-chat-window') || 
                             this.messagesContainer.closest('.chat-container') ||
                             this.messagesContainer.closest('.telegram-chat-content') ||
                             this.messagesContainer.parentElement;
            
            if (container) {
                const rect = container.getBoundingClientRect();
                const style = window.getComputedStyle(container);
                
                // Проверяем, что элемент видим на странице
                const isVisible = style.display !== 'none' && 
                                  style.visibility !== 'hidden' && 
                                  parseFloat(style.opacity) > 0 &&
                                  rect.width > 0 && 
                                  rect.height > 0 &&
                                  rect.top < window.innerHeight &&
                                  rect.bottom > 0 &&
                                  rect.left < window.innerWidth &&
                                  rect.right > 0;
                
                // Также проверяем, что страница видима (не в фоне)
                const isPageVisible = !document.hidden;
                
                return isVisible && isPageVisible;
            }
            return false;
        }
        
        // Для обычных чатов (на отдельной странице) всегда видим, если страница видима
        return !document.hidden;
    }
    
    /**
     * Отметить сообщения как прочитанные через WebSocket
     */
    markAsRead() {
        if (!this.chatId) return;
        
        if (typeof window.chatWebSocket === 'undefined' || !window.chatWebSocket.isAuthenticated) {
            return;
        }
        
        const sent = window.chatWebSocket.markAsRead(this.chatType, this.chatId);
        
        if (sent && this.isEmbedded) {
            // Обновляем бейдж непрочитанных в списке чатов
            window.chatWebSocket.updateChatBadge(this.chatType, this.chatId, 0);
        }
    }
    
    /**
     * Подключение обработчика видимости страницы
     */
    attachVisibilityHandler() {
        document.addEventListener('visibilitychange', () => {
            if (!document.hidden) {
                this.updateMessages();
            }
        });
    }
    
    /**
     * Подключение обработчика ухода со страницы
     */
    attachBeforeUnloadHandler() {
        window.addEventListener('beforeunload', () => {
            this.destroy();
        });
    }
    
    /**
     * Уничтожение менеджера чата (очистка ресурсов)
     */
    destroy() {
        
        // Отписываемся от WebSocket чата
        if (this.chatId && typeof window.chatWebSocket !== 'undefined') {
            window.chatWebSocket.unsubscribeChat(this.chatType, this.chatId);
        }
        
        // Удаляем обработчики
        if (this.messageForm) {
            this.messageForm.removeEventListener('submit', this.handleFormSubmit);
        }
        
        if (this.messageInput) {
            this.messageInput.removeEventListener('keydown', this.handleKeydown);
            this.messageInput.removeEventListener('input', this.handleTextareaInput);
        }
        
        if (this.messagesContainer) {
            this.messagesContainer.removeEventListener('scroll', this.handleScroll);
        }
        
        this.isInitialized = false;
    }
    
    /**
     * Форматирование времени
     */
    formatTime(date) {
        const now = new Date();
        const diff = now - date;
        const seconds = Math.floor(diff / 1000);
        const minutes = Math.floor(seconds / 60);
        const hours = Math.floor(minutes / 60);
        const days = Math.floor(hours / 24);
        
        if (days > 0) {
            return date.toLocaleDateString('ru-RU', { day: '2-digit', month: '2-digit' }) + ' ' +
                   date.toLocaleTimeString('ru-RU', { hour: '2-digit', minute: '2-digit' });
        } else if (hours > 0) {
            return hours + ' ' + this.getHoursWord(hours) + ' назад';
        } else if (minutes > 0) {
            return minutes + ' ' + this.getMinutesWord(minutes) + ' назад';
        } else {
            return 'только что';
        }
    }
    
    /**
     * Вспомогательные функции для склонения
     */
    getMinutesWord(minutes) {
        if (minutes % 10 === 1 && minutes % 100 !== 11) return 'минуту';
        if ([2, 3, 4].includes(minutes % 10) && ![12, 13, 14].includes(minutes % 100)) return 'минуты';
        return 'минут';
    }
    
    getHoursWord(hours) {
        if (hours % 10 === 1 && hours % 100 !== 11) return 'час';
        if ([2, 3, 4].includes(hours % 10) && ![12, 13, 14].includes(hours % 100)) return 'часа';
        return 'часов';
    }
    
    /**
     * Экранирование HTML
     */
    escapeHtml(text) {
        const div = document.createElement('div');
        div.textContent = text;
        return div.innerHTML;
    }
    
    /**
     * Форматирование текста сообщения
     */
    formatMessageText(text) {
        if (!text) return '';
        
        let formatted = this.escapeHtml(text);
        formatted = formatted.replace(/\n/g, '<br>');
        
        // Преобразуем URL в кликабельные ссылки
        const urlRegex = /((https?:\/\/[^\s<]+)|(\/public\/[^\s<]+))/gi;
        formatted = formatted.replace(urlRegex, (url) => {
            if (url.startsWith('/')) {
                const displayUrl = url.replace('/api/bill_pdf.php?id=', 'PDF векселя #');
                return `<a href="${url}" target="_blank" style="color: #667eea; text-decoration: underline; font-weight: 500;">📄 ${displayUrl}</a>`;
            }
            return `<a href="${url}" target="_blank" style="color: #667eea; text-decoration: underline;">${url}</a>`;
        });
        
        return formatted;
    }
    
    /**
     * Показ ошибки
     */
    showError(message) {
        if (typeof Toast !== 'undefined') {
            Toast.error(message);
        } else {
            alert(message);
        }
    }
}

// Экспорт для использования в других модулях
if (typeof window !== 'undefined') {
    window.ChatManager = ChatManager;
}




// ============================================================================
// Инициализация обновления счетчиков непрочитанных сообщений
// ============================================================================

// Функция восстановления последних сообщений из localStorage (выполняется до инициализации WebSocket)
function restoreLastMessagesFromStorage() {
    const chatItems = document.querySelectorAll('.telegram-chat-item');
    chatItems.forEach(chatItem => {
        const chatType = chatItem.getAttribute('data-chat-type');
        const chatId = chatItem.getAttribute('data-chat-id');
        
        if (!chatType || !chatId) return;
        
        const storageKey = `chat_last_message_${chatType}_${chatId}`;
        try {
            const savedLastMessage = localStorage.getItem(storageKey);
            if (savedLastMessage) {
                const lastMessageData = JSON.parse(savedLastMessage);
                const phpLastMessageTime = chatItem.getAttribute('data-last-message-time');
                const savedTime = new Date(lastMessageData.createdAt).getTime();
                const phpTime = phpLastMessageTime ? new Date(phpLastMessageTime).getTime() : 0;
                
                // Если сохраненное сообщение новее, чем из PHP, восстанавливаем его
                if (savedTime > phpTime) {
                    const preview = chatItem.querySelector('.telegram-chat-preview');
                    const timeElement = chatItem.querySelector('.telegram-chat-time');
                    
                    if (preview) {
                        // Восстанавливаем текст превью
                        const previewText = preview.querySelector('.telegram-preview-text');
                        const previewTextContent = lastMessageData.authorName + ': ' + lastMessageData.message;
                        
                        if (previewText) {
                            previewText.textContent = previewTextContent;
                        } else {
                            preview.innerHTML = '<span class="telegram-preview-text">' + previewTextContent + '</span>';
                        }
                        
                        // Форматируем и обновляем время
                        if (timeElement && window.chatWebSocket && typeof window.chatWebSocket.formatRelativeTime === 'function') {
                            timeElement.textContent = window.chatWebSocket.formatRelativeTime(lastMessageData.createdAt);
                        } else if (timeElement) {
                            // Fallback, если chatWebSocket еще не инициализирован
                            const now = new Date();
                            const messageTime = new Date(lastMessageData.createdAt);
                            const diff = Math.floor((now - messageTime) / 1000);
                            
                            let timeString = '';
                            if (diff < 60) {
                                timeString = 'только что';
                            } else if (diff < 3600) {
                                timeString = Math.floor(diff / 60) + ' мин назад';
                            } else if (diff < 86400) {
                                timeString = Math.floor(diff / 3600) + ' ч назад';
                            } else if (diff < 604800) {
                                timeString = Math.floor(diff / 86400) + ' дн назад';
                            } else {
                                timeString = messageTime.toLocaleDateString('ru-RU', { day: '2-digit', month: '2-digit' });
                            }
                            timeElement.textContent = timeString;
                        }
                        
                        // Сохраняем время в data-атрибут
                        chatItem.dataset.lastMessageTime = lastMessageData.createdAt;
                    }
                }
            }
        } catch (e) {
            // Ошибка при работе с localStorage
        }
    });
}

// Восстанавливаем сразу, как только DOM готов
if (document.readyState === 'loading') {
    document.addEventListener('DOMContentLoaded', restoreLastMessagesFromStorage);
} else {
    // DOM уже загружен
    restoreLastMessagesFromStorage();
}

document.addEventListener('DOMContentLoaded', function() {
    // Проверяем, есть ли список чатов на странице
    const chatList = document.querySelector('.telegram-chat-list') || document.querySelector('.telegram-chats-list');

    if (chatList && window.chatWebSocket) {
        // Инициализируем счетчики непрочитанных через chatWebSocket
        const userId = window.currentUserId || null;
        if (userId) {
            // Инициализируем время последних сообщений
            window.chatWebSocket.initializeLastMessageTimes();
            
            // Запускаем fallback polling для проверки подключения
            window.chatWebSocket.startFallbackPolling();
            
            // Первоначальное обновление счетчиков
            setTimeout(() => {
                window.chatWebSocket.updateAllUnreadCounts();
            }, 500);
            
            // Обновление при возвращении на страницу
            document.addEventListener('visibilitychange', () => {
                if (!document.hidden && window.chatWebSocket && window.chatWebSocket.isAuthenticated) {
                    window.chatWebSocket.requestUnreadCounts();
                }
            });
        }
    }
});
// ============================================================================
// chat.js
// ============================================================================

/**
 * JavaScript для улучшенного чата на основе ChatManager
 * Обертка для обратной совместимости
 */

// Глобальные переменные для совместимости (используются только для встроенных чатов в chats.php)
window.chatManager = null;

/**
 * Добавление сообщения в чат (для обратной совместимости)
 */
function addMessageToChat(messageData, isOwn) {
    if (window.chatManager) {
        window.chatManager.addMessageToChat(messageData, isOwn);
    }
}

/**
 * Обновление статуса транзакции из данных (для обратной совместимости)
 */
function updateTransactionStatusFromData(statusData) {
    // Обновляем статус продавца
    const sellerConfirmation = document.getElementById('sellerConfirmation');
    if (sellerConfirmation) {
        if (statusData.seller_confirmed) {
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
        if (statusData.buyer_confirmed) {
            buyerConfirmation.className = 'status status-active';
            buyerConfirmation.textContent = '✅';
        } else {
            buyerConfirmation.className = 'status status-pending';
            buyerConfirmation.textContent = '⏳';
        }
    }
    
    // Обновляем кнопку подтверждения
    const confirmButtonContainer = document.getElementById('confirmButtonContainer');
    if (confirmButtonContainer && !statusData.both_confirmed) {
        const isCurrentUserSeller = typeof window.isSeller !== 'undefined' ? window.isSeller : false;
        const userConfirmed = isCurrentUserSeller ? statusData.seller_confirmed : statusData.buyer_confirmed;
        
        if (!userConfirmed && confirmButtonContainer.innerHTML.indexOf('Подтвердить') === -1) {
            const form = document.createElement('form');
            form.method = 'POST';
            form.className = 'confirm-form-inline';
            const csrfToken = window.csrfToken || '';
            form.innerHTML = `
                <input type="hidden" name="_csrf_token" value="${csrfToken}">
                <input type="hidden" name="action" value="confirm_transaction">
                <button type="submit" class="btn btn-primary btn-small">✅ Подтвердить</button>
            `;
            form.addEventListener('submit', function(e) {
                e.preventDefault();
                const transactionId = parseInt(form.closest('[data-transaction-id]')?.getAttribute('data-transaction-id') || 
                                           document.querySelector('input[name="transaction_id"]')?.value || 
                                           window.currentTransactionId || 0);
                if (transactionId && typeof window.confirmTransaction === 'function') {
                    window.confirmTransaction(e, transactionId);
                }
                return false;
            });
            confirmButtonContainer.innerHTML = '';
            confirmButtonContainer.appendChild(form);
        } else if (userConfirmed && confirmButtonContainer.innerHTML.indexOf('Вы подтвердили') === -1) {
            confirmButtonContainer.innerHTML = '<span class="confirmation-confirmed">✅ Вы подтвердили</span>';
        }
    }
    
    // Если оба подтвердили, показываем кнопку создания векселя
    if (statusData.both_confirmed) {
        if (confirmButtonContainer) {
            confirmButtonContainer.innerHTML = '';
        }
        
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

/**
 * Обновление статуса транзакции (для обратной совместимости)
 */
function updateTransactionStatus(transactionId) {
    fetch(`/api/chat.php?action=get_transaction_status&transaction_id=${transactionId}`)
        .then(response => response.json())
        .then(data => {
            if (data.success && data.transaction_status) {
                updateTransactionStatusFromData(data.transaction_status);
            }
        })
        .catch(error => {
            console.error('[Chat] Error updating transaction status:', error);
        });
}

// Экспорт функций в глобальную область для обратной совместимости
if (typeof window !== 'undefined') {
    window.addMessageToChat = addMessageToChat;
    window.updateTransactionStatusFromData = updateTransactionStatusFromData;
    window.updateTransactionStatus = updateTransactionStatus;
    window.openBillModal = openBillModal;
    window.closeBillModal = closeBillModal;
}



// ============================================================================
// chats.js
// ============================================================================

﻿/**
 * Telegram-подобный интерфейс чатов (на основе ChatManager)
 */

// Хранилище менеджеров чатов для встроенных чатов
const embeddedChatManagers = new Map();

document.addEventListener('DOMContentLoaded', function() {
    const chatItems = document.querySelectorAll('.telegram-chat-item');
    const chatWindow = document.querySelector('.telegram-chat-window');
    
    if (!chatWindow) return;
    
    chatItems.forEach(item => {
        item.addEventListener('click', function(e) {
            e.preventDefault();
            
            const chatType = this.getAttribute('data-chat-type');
            const chatId = this.getAttribute('data-chat-id');
            const href = this.getAttribute('href');
            
            if (!chatType || !chatId) {
                window.location.href = href;
                return;
            }
            
            chatItems.forEach(ci => ci.classList.remove('active'));
            this.classList.add('active');
            
            loadChat(chatType, chatId, chatWindow);
        });
    });
    
    // Обработка URL параметров для автоматической загрузки чата
    const urlParams = new URLSearchParams(window.location.search);
    const chatTypeParam = urlParams.get('chat_type');
    const chatIdParam = urlParams.get('chat_id');
    
    if (chatTypeParam && chatIdParam) {
        const targetItem = document.querySelector(
            `.telegram-chat-item[data-chat-type="${chatTypeParam}"][data-chat-id="${chatIdParam}"]`
        );
        if (targetItem) {
            targetItem.click();
        }
    }
});

/**
 * Загружает чат в правую панель
 */
function loadChat(chatType, chatId, container) {
    container.innerHTML = '<div class="telegram-chat-loading"><i class="fas fa-spinner fa-spin"></i> Загрузка...</div>';
    
    // Отмечаем сообщения как прочитанные при открытии чата
    if (window.chatWebSocket) {
        // Временно обновляем счетчик на 0
        window.chatWebSocket.updateChatBadge(chatType, chatId, 0);
    }
    
    let url = '';
    if (chatType === 'transaction') {
        url = `/transactions/chat.php?id=${chatId}&embed=1`;
    } else if (chatType === 'community') {
        url = `/community/chat.php?id=${chatId}&embed=1`;
    } else {
        container.innerHTML = '<div class="telegram-chat-error">Неизвестный тип чата</div>';
        return;
    }
    
    // Уничтожаем предыдущий менеджер для этого контейнера
    const managerKey = `${chatType}_${chatId}`;
    if (embeddedChatManagers.has(managerKey)) {
        embeddedChatManagers.get(managerKey).destroy();
        embeddedChatManagers.delete(managerKey);
    }
    
    // Загружаем чат через fetch
    fetch(url)
        .then(response => {
            if (!response.ok) {
                throw new Error('Network response was not ok');
            }
            return response.text();
        })
        .then(html => {
            const chatWrapper = document.createElement('div');
            chatWrapper.className = 'telegram-chat-content-wrapper';
            chatWrapper.innerHTML = html;
            
            container.innerHTML = '';
            container.appendChild(chatWrapper);
            
            // Инициализируем менеджер чата
            initEmbeddedChat(chatType, chatId, chatWrapper);
            
            // Обновляем счетчики непрочитанных после загрузки чата
            // (сообщения будут отмечены как прочитанные автоматически)
            setTimeout(() => {
                if (window.chatWebSocket) {
                    window.chatWebSocket.requestUnreadCounts();
                }
            }, 500);
            
            // Выполняем встроенные скрипты
            const scripts = chatWrapper.querySelectorAll('script');
            scripts.forEach(script => {
                if (script.src) return;
                
                try {
                    // Выполняем скрипт в глобальной области видимости
                    const scriptFunc = new Function(script.textContent);
                    scriptFunc.call(window);
                } catch (error) {
                    console.error('[Chats] Ошибка выполнения встроенного скрипта:', error);
                }
            });
            
            // Убеждаемся, что глобальные функции доступны после выполнения скриптов
            // (функции должны быть определены в chat-all.js)
            if (typeof window.confirmTransaction === 'undefined') {
                console.error('[Chats] confirmTransaction не определена! Убедитесь, что chat-all.js загружен.');
            }
            
        })
        .catch(error => {
            console.error('[Chats] Ошибка загрузки чата:', error);
            container.innerHTML = '<div class="telegram-chat-error">Ошибка загрузки чата. Попробуйте обновить страницу.</div>';
        });
}

/**
 * Инициализирует менеджер чата для встроенного чата
 */
function initEmbeddedChat(chatType, chatId, container) {

    // Проверяем, что ChatManager загружен
    if (typeof ChatManager === 'undefined') {
        console.error('[Chats] ChatManager class not found. Make sure chat-manager.js is loaded');
        setTimeout(() => initEmbeddedChat(chatType, chatId, container), 100);
        return;
    }
    
    // Создаем менеджер чата
    const chatManager = new ChatManager({
        chatId: parseInt(chatId),
        chatType: chatType,
        container: container,
        isEmbedded: true
    });
    
    // Инициализируем
    if (chatManager.init()) {
        const managerKey = `${chatType}_${chatId}`;
        embeddedChatManagers.set(managerKey, chatManager);

        setTimeout(() => {
            chatManager.scrollToBottom();
            // Отмечаем сообщения как прочитанные при открытии чата
            if (chatManager.isChatVisible()) {
                chatManager.markAsRead();
            }
        }, 300); // Увеличиваем задержку, чтобы убедиться, что чат полностью загружен
        
        attachTransactionHandlers(container, chatType, chatId);
    } else {
        console.error('[Chats] Failed to initialize embedded chat');
    }
}

/**
 * Подключение обработчиков для транзакций
 */
function attachTransactionHandlers(container, chatType, chatId) {
    // Обработка подтверждения транзакции (может быть несколько форм на странице)
    const confirmForms = container.querySelectorAll('.confirm-form-inline');
    confirmForms.forEach(confirmForm => {
        // Удаляем inline-обработчик onsubmit, если он есть
        const onsubmitAttr = confirmForm.getAttribute('onsubmit');
        let transactionId = null;
        
        if (onsubmitAttr && onsubmitAttr.includes('confirmTransaction')) {
            // Извлекаем transaction_id из атрибута onsubmit
            const match = onsubmitAttr.match(/confirmTransaction\(event,\s*(\d+)\)/);
            if (match) {
                transactionId = parseInt(match[1]);
                // Удаляем inline-обработчик
                confirmForm.removeAttribute('onsubmit');
            }
        }
        
        // Если transaction_id не найден в onsubmit, ищем в форме
        if (!transactionId) {
            const transactionIdInput = confirmForm.querySelector('input[name="transaction_id"]');
            transactionId = transactionIdInput ? parseInt(transactionIdInput.value) : (chatType === 'transaction' ? chatId : null);
        }
        
        // Добавляем обработчик через addEventListener
        confirmForm.addEventListener('submit', function(e) {
            e.preventDefault();
            
            if (!transactionId) {
                alert('Ошибка: не найден ID транзакции');
                return false;
            }
            
            // Используем window.confirmTransaction если доступна, иначе handleTransactionConfirm
            if (typeof window.confirmTransaction === 'function') {
                return window.confirmTransaction(e, transactionId);
            } else {
                // Fallback на handleTransactionConfirm
                if (!confirm('Подтвердить сделку?')) return false;
                handleTransactionConfirm(chatType, transactionId, chatId);
                return false;
            }
        });
    });
    
    // Обработка создания векселя
    const billForm = container.querySelector('#billForm');
    if (billForm) {
        billForm.addEventListener('submit', function(e) {
            e.preventDefault();
            const formData = new FormData(this);
            handleBillCreate(formData, chatType, chatId, container);
        });
    }
    
    // Обработка подтверждения поручителя
    const confirmGuarantorBtn = container.querySelector('button[name="confirm"]');
    if (confirmGuarantorBtn) {
        const confirmForm = confirmGuarantorBtn.closest('form');
        if (confirmForm) {
            confirmForm.addEventListener('submit', function(e) {
                e.preventDefault();
                if (!confirm('Подтвердить своё согласие быть поручителем?')) return;
                const formData = new FormData(this);
                handleGuarantorConfirm(formData, chatType, chatId);
            });
        }
    }
}

/**
 * Обработка подтверждения транзакции
 */
async function handleTransactionConfirm(chatType, transactionId, communityRequestId = null) {
    try {
        // Используем WebSocket, если доступен
        if (typeof window.chatWebSocket !== 'undefined' && window.chatWebSocket.isAuthenticated) {
            const sent = window.chatWebSocket.confirmTransaction(transactionId);
            
            if (sent) {

                // Обновление UI произойдет через WebSocket событие transaction_confirmed
                // Ожидаем подтверждения и обновляем чат
                const handler = function(data) {
                    if (data.transaction_id === transactionId) {
                        // Обновляем статус транзакции в UI
                        updateTransactionStatusInCommunityChat(transactionId, data.transaction_status);
                        
                        // Если это обычный чат транзакции, перезагружаем чат
                        if (chatType === 'transaction') {
                            const chatWindow = document.querySelector('.telegram-chat-window');
                            if (chatWindow) {
                                loadChat(chatType, transactionId, chatWindow);
                            }
                        } else if (chatType === 'community' && communityRequestId) {
                            // Для общины обновляем только статус транзакции, не перезагружаем весь чат
                            // Статус уже обновлен через updateTransactionStatusInCommunityChat
                        }
                        // Удаляем обработчик после использования
                        window.chatWebSocket.offMessageType('transaction_confirmed', handler);
                    }
                };
                window.chatWebSocket.onMessageType('transaction_confirmed', handler);
                
                // Также обрабатываем обновление статуса (для других участников)
                const statusHandler = function(data) {
                    if (data.transaction_id === transactionId) {
                        // Обновляем статус транзакции в UI
                        updateTransactionStatusInCommunityChat(transactionId, data.transaction_status);
                        
                        // Если это обычный чат транзакции, перезагружаем чат
                        if (chatType === 'transaction') {
                            const chatWindow = document.querySelector('.telegram-chat-window');
                            if (chatWindow) {
                                loadChat(chatType, transactionId, chatWindow);
                            }
                        }
                        // Не удаляем обработчик, так как он может пригодиться для будущих обновлений
                    }
                };
                window.chatWebSocket.onMessageType('transaction_status_updated', statusHandler);
            } else {
                throw new Error('Failed to send confirm transaction via WebSocket');
            }
        } else {
            throw new Error('Failed to send confirm transaction via WebSocket');
        }
    } catch (error) {
        console.error('[Chats] Ошибка подтверждения транзакции:', error);
        alert('Ошибка подтверждения сделки');
    }
}

/**
 * Обновляет статус транзакции в UI чата общины
 */
function updateTransactionStatusInCommunityChat(transactionId, statusData) {
    const transactionItem = document.querySelector(`[data-transaction-id="${transactionId}"]`);
    if (!transactionItem) return;
    
    const statusElement = transactionItem.querySelector(`#transactionStatus-${transactionId}`);
    const buttonContainer = transactionItem.querySelector(`#transactionConfirmButton-${transactionId}`);
    
    if (!statusElement) return;
    
    // Определяем, является ли пользователь продавцом или покупателем
    // Проверяем текст внутри транзакции
    const transactionText = transactionItem.textContent || '';
    const isSeller = transactionText.includes('продавец') || transactionText.includes('Продавец');
    const userConfirmed = isSeller ? statusData.seller_confirmed : statusData.buyer_confirmed;
    
    // Обновляем статус
    if (userConfirmed) {
        statusElement.className = 'status status-active';
        statusElement.style.color = '#10b981';
        statusElement.textContent = '✅ Подтверждено';
    } else {
        statusElement.className = 'status status-pending';
        statusElement.style.color = '#f59e0b';
        statusElement.textContent = '⏳ Ожидает подтверждения';
    }
    
    // Обновляем кнопку подтверждения
    if (buttonContainer) {
        if (userConfirmed) {
            buttonContainer.innerHTML = '<span class="confirmation-confirmed" style="color: #10b981; font-weight: bold;">✅ Вы подтвердили</span>';
        } else {
            // Показываем кнопку подтверждения, если еще не подтверждено
            const confirmForm = transactionItem.querySelector('.confirm-form-inline');
            if (!confirmForm || !confirmForm.querySelector('button[type="submit"]')) {
                const csrfToken = window.csrfToken || '';
                buttonContainer.innerHTML = `
                    <form method="POST" class="confirm-form-inline" data-transaction-id="${transactionId}" style="margin: 0;">
                        <input type="hidden" name="_csrf_token" value="${csrfToken}">
                        <input type="hidden" name="action" value="confirm_transaction">
                        <input type="hidden" name="transaction_id" value="${transactionId}">
                        <button type="submit" class="btn btn-primary btn-small" style="width: 100%;">
                            ✅ Подтвердить сделку
                        </button>
                    </form>
                `;
                // Подключаем обработчик для новой формы через WebSocket
                const newForm = buttonContainer.querySelector('.confirm-form-inline');
                if (newForm) {
                    newForm.addEventListener('submit', function(e) {
                        e.preventDefault();
                        if (typeof window.confirmTransaction === 'function') {
                            window.confirmTransaction(e, transactionId);
                        }
                        return false;
                    });
                }
            }
        }
    }
}

/**
 * Открывает модальное окно создания векселя
 * Работает как в обычном режиме, так и во встроенном чате
 */
function openBillModal() {
    // Ищем модальное окно в текущем контексте
    // Сначала проверяем во встроенном чате
    const chatWrapper = document.querySelector('.telegram-chat-content-wrapper');
    let modal = chatWrapper ? chatWrapper.querySelector('#billModal') : null;
    
    // Если не найдено во встроенном чате, ищем в основном документе
    if (!modal) {
        modal = document.getElementById('billModal');
    }
    
    if (modal) {
        modal.classList.add('active');
        document.body.style.overflow = 'hidden';
        
        // Сбрасываем форму и ошибки
        const form = modal.querySelector('#billForm');
        if (form) {
            form.reset();
            
            // Восстанавливаем значения по умолчанию
            const nominalInput = modal.querySelector('#nominal');
            if (nominalInput) {
                nominalInput.value = '1000';
            }
            
            const maturityDaysInput = modal.querySelector('#maturity_days');
            if (maturityDaysInput) {
                maturityDaysInput.value = '30';
            }
            
            // Восстанавливаем выбранного эмитента (если есть данные в скрипте)
            const issuerSelect = modal.querySelector('#issuer_id');
            if (issuerSelect && issuerSelect.options.length > 1) {
                // Выбираем первый доступный вариант (кроме пустого)
                for (let i = 1; i < issuerSelect.options.length; i++) {
                    if (issuerSelect.options[i].value) {
                        issuerSelect.value = issuerSelect.options[i].value;
                        break;
                    }
                }
            }
        }
        
        // Скрываем ошибки формы
        const errorDiv = modal.querySelector('#billFormError');
        if (errorDiv) {
            errorDiv.style.display = 'none';
        }
        
        // Проверяем валидность формы, если есть такая функция
        if (typeof checkBillFormValidity === 'function') {
            checkBillFormValidity();
        }
    } else {
        console.warn('[Chats] Модальное окно создания векселя не найдено. Убедитесь, что чат загружен полностью.');
    }
}

/**
 * Закрывает модальное окно создания векселя
 */
function closeBillModal() {
    // Ищем модальное окно в текущем контексте
    const chatWrapper = document.querySelector('.telegram-chat-content-wrapper');
    let modal = chatWrapper ? chatWrapper.querySelector('#billModal') : null;
    
    // Если не найдено во встроенном чате, ищем в основном документе
    if (!modal) {
        modal = document.getElementById('billModal');
    }
    
    if (modal) {
        modal.classList.remove('active');
        document.body.style.overflow = '';
    }
}

/**
 * Обработка создания векселя
 */
async function handleBillCreate(formData, chatType, chatId, container) {
    try {
        const response = await fetch(`/transactions/chat.php?id=${chatId}&embed=1`, {
            method: 'POST',
            body: formData
        });
        
        if (response.ok) {
            closeBillModal();
            
            const chatWindow = document.querySelector('.telegram-chat-window');
            if (chatWindow) {
                loadChat(chatType, chatId, chatWindow);
            }
        } else {
            throw new Error('HTTP error: ' + response.status);
        }
    } catch (error) {
        console.error('[Chats] Ошибка создания векселя:', error);
        alert('Ошибка создания векселя');
    }
}

/**
 * Обработка подтверждения поручителя
 */
async function handleGuarantorConfirm(formData, chatType, chatId) {
    try {
        const response = await fetch(`/community/chat.php?id=${chatId}&embed=1`, {
            method: 'POST',
            body: formData
        });
        
        if (response.ok) {
            // Пытаемся получить JSON ответ
            const contentType = response.headers.get('content-type');
            if (contentType && contentType.includes('application/json')) {
                const data = await response.json();
                
                if (data.success) {
                    // Обновляем UI без перезагрузки страницы
                    updateGuarantorConfirmStatus(chatId, data);
                } else {
                    alert('Ошибка: ' + (data.error || 'Не удалось подтвердить согласие'));
                }
            } else {
                // Если ответ HTML, значит произошел редирект - перезагружаем чат
                const chatWindow = document.querySelector('.telegram-chat-window');
                if (chatWindow) {
                    loadChat(chatType, chatId, chatWindow);
                }
            }
        } else {
            throw new Error('HTTP error: ' + response.status);
        }
    } catch (error) {
        console.error('[Chats] Ошибка подтверждения поручителя:', error);
        alert('Ошибка подтверждения');
    }
}

/**
 * Обновляет статус подтверждения поручителя в UI
 */
function updateGuarantorConfirmStatus(communityRequestId, data) {
    // Обновляем счетчик подтверждений
    const confirmedCountEl = document.getElementById('confirmedCount');
    const guarantorsCountEl = document.getElementById('guarantorsCount');
    if (confirmedCountEl) confirmedCountEl.textContent = data.confirmed_count || 0;
    if (guarantorsCountEl) guarantorsCountEl.textContent = data.guarantors_count || 0;
    
    // Удаляем кнопку подтверждения
    const confirmContainer = document.getElementById('confirmGuarantorContainer');
    if (confirmContainer) {
        confirmContainer.innerHTML = '';
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
        }
    }
    
    // Обновляем статус текущего пользователя в списке поручителей
    const currentUserId = window.currentUserId;
    if (currentUserId) {
        const guarantorItem = document.querySelector(`[data-guarantor-id="${currentUserId}"]`);
        if (guarantorItem) {
            const statusEl = guarantorItem.querySelector('.guarantor-status');
            if (statusEl) {
                statusEl.style.color = '#10b981';
                statusEl.textContent = '✅ Подтверждено';
            }
        }
    }
    
    // Обновляем общий статус через функцию updateCommunityStatus
    if (typeof updateCommunityStatus === 'function') {
        updateCommunityStatus(communityRequestId, window.currentUserId, true);
    }
}

/**
 * Глобальная функция подтверждения транзакции (для использования в inline-обработчиках)
 * Используется в transactions/chat.php
 */
function confirmTransaction(event, transactionId) {
    event.preventDefault();
    
    if (!confirm('Подтвердить сделку?')) {
        return false;
    }
    
    const form = event.target;
    const button = form.querySelector('button[type="submit"]');
    const originalText = button ? button.textContent : '';
    
    if (button) {
        button.disabled = true;
        button.textContent = 'Подтверждение...';
    }
    
    // Используем WebSocket для подтверждения транзакции
    if (typeof window.chatWebSocket === 'undefined' || !window.chatWebSocket.isAuthenticated) {
        if (button) {
            button.disabled = false;
            button.textContent = originalText;
        }
        alert('Ошибка: WebSocket не подключен');
        return false;
    }
    
    const sent = window.chatWebSocket.confirmTransaction(transactionId);
    
    if (!sent) {
        if (button) {
            button.disabled = false;
            button.textContent = originalText;
        }
        alert('Ошибка: Не удалось отправить запрос на подтверждение');
        return false;
    }
    
    // Подписываемся на события для обновления UI
    const confirmationHandler = function(data) {
        if (data.transaction_id === transactionId) {
            // Обновляем статус
            if (typeof updateTransactionStatusFromData === 'function' && data.transaction_status) {
                updateTransactionStatusFromData(data.transaction_status);
            } else if (typeof updateTransactionStatus === 'function') {
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
                const isCurrentUserSeller = typeof window.isSeller !== 'undefined' ? window.isSeller : false;
                const userConfirmed = isCurrentUserSeller ? data.transaction_status.seller_confirmed : data.transaction_status.buyer_confirmed;
                
                if (userConfirmed) {
                    confirmButtonContainer.innerHTML = '<span class="confirmation-confirmed">✅ Вы подтвердили</span>';
                }
            }
            
            if (button) {
                button.disabled = false;
                button.textContent = originalText;
            }
            
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
    
    return false;
}

// Экспорт глобальных функций для использования в inline-обработчиках
// Важно: экспортируем сразу после определения, чтобы функции были доступны глобально
if (typeof window !== 'undefined') {
    window.confirmTransaction = confirmTransaction;
}


