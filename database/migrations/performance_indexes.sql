-- ============================================================
-- МИГРАЦИЯ: Индексы для оптимизации производительности
-- ============================================================
-- Дата: 2025-12-07
-- Описание: Добавление составных индексов для ускорения запросов
-- ============================================================

USE `ogas`;

-- ============================================================
-- ТРАНЗАКЦИИ
-- ============================================================

-- Индекс для поиска транзакций продавца с фильтром по статусу
CREATE INDEX IF NOT EXISTS idx_transactions_seller_status 
ON transactions(seller_id, status, created_at DESC);

-- Индекс для поиска транзакций покупателя с фильтром по статусу
CREATE INDEX IF NOT EXISTS idx_transactions_buyer_status 
ON transactions(buyer_id, status, created_at DESC);

-- Индекс для сортировки по дате создания
CREATE INDEX IF NOT EXISTS idx_transactions_created 
ON transactions(created_at DESC);

-- Индекс для поиска по категории
CREATE INDEX IF NOT EXISTS idx_transactions_category 
ON transactions(category);

-- Индекс для общинных транзакций
CREATE INDEX IF NOT EXISTS idx_transactions_community 
ON transactions(community_request_id, status);

-- Индекс для подтверждений транзакций
CREATE INDEX IF NOT EXISTS idx_transactions_confirmed 
ON transactions(seller_confirmed, buyer_confirmed, status);

-- ============================================================
-- ВЕКСЕЛИ
-- ============================================================

-- Индекс для поиска векселей выпустителя с фильтром по статусу
CREATE INDEX IF NOT EXISTS idx_bills_issuer_status 
ON bills(issuer_id, status, issue_date DESC);

-- Индекс для поиска векселей держателя с фильтром по статусу
CREATE INDEX IF NOT EXISTS idx_bills_holder_status 
ON bills(holder_id, status, issue_date DESC);

-- Индекс для поиска просроченных векселей
CREATE INDEX IF NOT EXISTS idx_bills_maturity 
ON bills(maturity_date, status);

-- Индекс для поиска векселей по дате погашения
CREATE INDEX IF NOT EXISTS idx_bills_payment 
ON bills(payment_date, status);

-- Индекс для общинных векселей
CREATE INDEX IF NOT EXISTS idx_bills_community 
ON bills(community_request_id, status);

-- Индекс для поиска по гаранту
CREATE INDEX IF NOT EXISTS idx_bills_guarantor 
ON bills(community_guarantor_id, status);

-- ============================================================
-- СООБЩЕНИЯ
-- ============================================================

-- Индекс для поиска сообщений транзакции с фильтром по прочтению
CREATE INDEX IF NOT EXISTS idx_messages_transaction_read 
ON messages(transaction_id, is_read, created_at DESC);

-- Индекс для сообщений общины
CREATE INDEX IF NOT EXISTS idx_messages_community_read 
ON messages(community_request_id, is_read, created_at DESC);

-- Индекс для поиска сообщений пользователя
CREATE INDEX IF NOT EXISTS idx_messages_user 
ON messages(user_id, created_at DESC);

-- Индекс для сортировки по дате
CREATE INDEX IF NOT EXISTS idx_messages_created 
ON messages(created_at DESC);

-- ============================================================
-- ТОВАРЫ И УСЛУГИ
-- ============================================================

-- Индекс для поиска товаров пользователя по категории
CREATE INDEX IF NOT EXISTS idx_products_user_category 
ON products(user_id, category, is_available);

-- Индекс для поиска по типу и доступности
CREATE INDEX IF NOT EXISTS idx_products_type_available 
ON products(type, is_available, created_at DESC);

-- Индекс для поиска по цене
CREATE INDEX IF NOT EXISTS idx_products_price 
ON products(base_price, is_available);

-- ============================================================
-- РЕЙТИНГИ
-- ============================================================

-- Индекс для сортировки по баллам
CREATE INDEX IF NOT EXISTS idx_ratings_score 
ON ratings(score DESC);

-- Индекс для поиска по последнему обновлению
CREATE INDEX IF NOT EXISTS idx_ratings_updated 
ON ratings(updated_at DESC);

-- Составной индекс для активных пользователей с высоким рейтингом
CREATE INDEX IF NOT EXISTS idx_ratings_active_high 
ON ratings(user_id, score DESC) 
WHERE score > 50;

-- ============================================================
-- ПОЛЬЗОВАТЕЛИ
-- ============================================================

-- Индекс для поиска активных пользователей по типу
CREATE INDEX IF NOT EXISTS idx_users_active_type 
ON users(is_active, user_type, created_at DESC);

-- Индекс для администраторов
CREATE INDEX IF NOT EXISTS idx_users_admin 
ON users(is_admin, is_active);

-- ============================================================
-- КАТЕГОРИИ
-- ============================================================

-- Индекс для активных категорий с сортировкой
CREATE INDEX IF NOT EXISTS idx_categories_active_sort 
ON categories(is_active, sort_order, name);

-- Индекс для вложенных категорий
CREATE INDEX IF NOT EXISTS idx_categories_parent_active 
ON categories(parent_id, is_active, sort_order);

-- ============================================================
-- ЗАЯВКИ ОБЩИНЫ
-- ============================================================

-- Индекс для поиска по пользователю и статусу
CREATE INDEX IF NOT EXISTS idx_community_requests_user_status 
ON community_requests(user_id, status, created_at DESC);

-- Индекс для поиска открытых заявок
CREATE INDEX IF NOT EXISTS idx_community_requests_status 
ON community_requests(status, created_at DESC);

-- ============================================================
-- ПОРУЧИТЕЛИ ОБЩИНЫ
-- ============================================================

-- Индекс для поиска по поручителю
CREATE INDEX IF NOT EXISTS idx_community_guarantors_guarantor 
ON community_guarantors(guarantor_id, status);

-- Индекс для поиска по заявке
CREATE INDEX IF NOT EXISTS idx_community_guarantors_request 
ON community_guarantors(request_id, status);

-- ============================================================
-- УВЕДОМЛЕНИЯ
-- ============================================================

-- Индекс для непрочитанных уведомлений пользователя
CREATE INDEX IF NOT EXISTS idx_notifications_user_read 
ON notifications(user_id, is_read, created_at DESC);

-- Индекс для поиска по типу уведомления
CREATE INDEX IF NOT EXISTS idx_notifications_type 
ON notifications(type, user_id, is_read);

-- ============================================================
-- СЕССИИ ПОЛЬЗОВАТЕЛЕЙ
-- ============================================================

-- Индекс для поиска активных сессий пользователя
CREATE INDEX IF NOT EXISTS idx_user_sessions_user_active 
ON user_sessions(user_id, last_activity);

-- Индекс для очистки старых сессий
CREATE INDEX IF NOT EXISTS idx_user_sessions_last_activity 
ON user_sessions(last_activity);

-- ============================================================
-- ТИКЕТЫ ПОДДЕРЖКИ
-- ============================================================

-- Индекс для поиска тикетов по пользователю и статусу
CREATE INDEX IF NOT EXISTS idx_support_tickets_user_status 
ON support_tickets(user_id, status, created_at DESC);

-- Индекс для поиска по статусу и приоритету
CREATE INDEX IF NOT EXISTS idx_support_tickets_status_priority 
ON support_tickets(status, priority, created_at DESC);

-- Индекс для назначенных тикетов
CREATE INDEX IF NOT EXISTS idx_support_tickets_assigned 
ON support_tickets(assigned_to, status);

-- ============================================================
-- ПОДПИСКИ
-- ============================================================

-- Индекс для активных подписок пользователя
CREATE INDEX IF NOT EXISTS idx_subscriptions_user_active 
ON subscriptions(user_id, status, end_date);

-- Индекс для истекающих подписок
CREATE INDEX IF NOT EXISTS idx_subscriptions_expiring 
ON subscriptions(end_date, status);

-- ============================================================
-- ПРОВЕРКА СОЗДАННЫХ ИНДЕКСОВ
-- ============================================================

-- Показать все индексы таблицы transactions
-- SHOW INDEXES FROM transactions;

-- Показать все индексы таблицы bills
-- SHOW INDEXES FROM bills;

-- Показать все индексы таблицы messages
-- SHOW INDEXES FROM messages;

-- ============================================================
-- АНАЛИЗ ЗАПРОСОВ (для тестирования производительности)
-- ============================================================

-- Примеры запросов для тестирования индексов:

-- 1. Поиск транзакций продавца по статусу (должен использовать idx_transactions_seller_status)
-- EXPLAIN SELECT * FROM transactions WHERE seller_id = 1 AND status = 'active' ORDER BY created_at DESC;

-- 2. Поиск векселей держателя (должен использовать idx_bills_holder_status)
-- EXPLAIN SELECT * FROM bills WHERE holder_id = 1 AND status = 'active' ORDER BY issue_date DESC;

-- 3. Подсчет непрочитанных сообщений (должен использовать idx_messages_transaction_read)
-- EXPLAIN SELECT COUNT(*) FROM messages WHERE transaction_id = 1 AND is_read = 0;

-- 4. Поиск товаров по категории (должен использовать idx_products_user_category)
-- EXPLAIN SELECT * FROM products WHERE user_id = 1 AND category = 'Электроника' AND is_available = 1;

-- ============================================================
-- ОПТИМИЗАЦИЯ ТАБЛИЦ
-- ============================================================

-- После создания индексов рекомендуется оптимизировать таблицы
OPTIMIZE TABLE transactions;
OPTIMIZE TABLE bills;
OPTIMIZE TABLE messages;
OPTIMIZE TABLE products;
OPTIMIZE TABLE ratings;
OPTIMIZE TABLE categories;
OPTIMIZE TABLE community_requests;
OPTIMIZE TABLE notifications;
OPTIMIZE TABLE user_sessions;

-- ============================================================
-- ЗАВЕРШЕНИЕ
-- ============================================================

SELECT 'Индексы для оптимизации производительности созданы успешно!' as Status;





