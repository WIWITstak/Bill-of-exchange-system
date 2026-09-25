-- ============================================================
-- ИНДЕКСЫ ДЛЯ ОПТИМИЗАЦИИ ПРОИЗВОДИТЕЛЬНОСТИ ОГАС
-- Применение через phpMyAdmin
-- ============================================================

USE `ogas`;

-- ТРАНЗАКЦИИ
CREATE INDEX IF NOT EXISTS idx_transactions_seller_status ON transactions(seller_id, status, created_at DESC);
CREATE INDEX IF NOT EXISTS idx_transactions_buyer_status ON transactions(buyer_id, status, created_at DESC);
CREATE INDEX IF NOT EXISTS idx_transactions_created ON transactions(created_at DESC);
CREATE INDEX IF NOT EXISTS idx_transactions_category ON transactions(category);
CREATE INDEX IF NOT EXISTS idx_transactions_community ON transactions(community_request_id, status);
CREATE INDEX IF NOT EXISTS idx_transactions_confirmed ON transactions(seller_confirmed, buyer_confirmed, status);

-- ВЕКСЕЛИ
CREATE INDEX IF NOT EXISTS idx_bills_issuer_status ON bills(issuer_id, status, issue_date DESC);
CREATE INDEX IF NOT EXISTS idx_bills_holder_status ON bills(holder_id, status, issue_date DESC);
CREATE INDEX IF NOT EXISTS idx_bills_maturity ON bills(maturity_date, status);
CREATE INDEX IF NOT EXISTS idx_bills_payment ON bills(payment_date, status);
CREATE INDEX IF NOT EXISTS idx_bills_community ON bills(community_request_id, status);
CREATE INDEX IF NOT EXISTS idx_bills_guarantor ON bills(community_guarantor_id, status);

-- СООБЩЕНИЯ
CREATE INDEX IF NOT EXISTS idx_messages_transaction_read ON messages(transaction_id, is_read, created_at DESC);
CREATE INDEX IF NOT EXISTS idx_messages_community_read ON messages(community_request_id, is_read, created_at DESC);
CREATE INDEX IF NOT EXISTS idx_messages_user ON messages(user_id, created_at DESC);
CREATE INDEX IF NOT EXISTS idx_messages_created ON messages(created_at DESC);

-- ТОВАРЫ И УСЛУГИ
CREATE INDEX IF NOT EXISTS idx_products_user_category ON products(user_id, category, is_available);
CREATE INDEX IF NOT EXISTS idx_products_type_available ON products(type, is_available, created_at DESC);
CREATE INDEX IF NOT EXISTS idx_products_price ON products(base_price, is_available);

-- РЕЙТИНГИ
CREATE INDEX IF NOT EXISTS idx_ratings_score ON ratings(score DESC);
CREATE INDEX IF NOT EXISTS idx_ratings_updated ON ratings(updated_at DESC);

-- ПОЛЬЗОВАТЕЛИ
CREATE INDEX IF NOT EXISTS idx_users_active_type ON users(is_active, user_type, created_at DESC);
CREATE INDEX IF NOT EXISTS idx_users_admin ON users(is_admin, is_active);

-- КАТЕГОРИИ
CREATE INDEX IF NOT EXISTS idx_categories_active_sort ON categories(is_active, sort_order, name);
CREATE INDEX IF NOT EXISTS idx_categories_parent_active ON categories(parent_id, is_active, sort_order);

-- ЗАЯВКИ ОБЩИНЫ
CREATE INDEX IF NOT EXISTS idx_community_requests_user_status ON community_requests(user_id, status, created_at DESC);
CREATE INDEX IF NOT EXISTS idx_community_requests_status ON community_requests(status, created_at DESC);

-- ПОРУЧИТЕЛИ ОБЩИНЫ
CREATE INDEX IF NOT EXISTS idx_community_guarantors_guarantor ON community_guarantors(guarantor_id, status);
CREATE INDEX IF NOT EXISTS idx_community_guarantors_request ON community_guarantors(request_id, status);

-- УВЕДОМЛЕНИЯ
CREATE INDEX IF NOT EXISTS idx_notifications_user_read ON notifications(user_id, is_read, created_at DESC);
CREATE INDEX IF NOT EXISTS idx_notifications_type ON notifications(type, user_id, is_read);

-- СЕССИИ ПОЛЬЗОВАТЕЛЕЙ
CREATE INDEX IF NOT EXISTS idx_user_sessions_user_active ON user_sessions(user_id, last_activity);
CREATE INDEX IF NOT EXISTS idx_user_sessions_last_activity ON user_sessions(last_activity);

-- ТИКЕТЫ ПОДДЕРЖКИ
CREATE INDEX IF NOT EXISTS idx_support_tickets_user_status ON support_tickets(user_id, status, created_at DESC);
CREATE INDEX IF NOT EXISTS idx_support_tickets_status_priority ON support_tickets(status, priority, created_at DESC);
CREATE INDEX IF NOT EXISTS idx_support_tickets_assigned ON support_tickets(assigned_to, status);

-- ПОДПИСКИ
CREATE INDEX IF NOT EXISTS idx_subscriptions_user_active ON subscriptions(user_id, status, end_date);
CREATE INDEX IF NOT EXISTS idx_subscriptions_expiring ON subscriptions(end_date, status);

-- ОПТИМИЗАЦИЯ ТАБЛИЦ
OPTIMIZE TABLE transactions;
OPTIMIZE TABLE bills;
OPTIMIZE TABLE messages;
OPTIMIZE TABLE products;
OPTIMIZE TABLE ratings;
OPTIMIZE TABLE categories;
OPTIMIZE TABLE community_requests;
OPTIMIZE TABLE notifications;
OPTIMIZE TABLE user_sessions;

SELECT 'Индексы применены успешно!' as Status;





