-- ============================================================
-- ПОЛНЫЙ СКРИПТ СОЗДАНИЯ БАЗЫ ДАННЫХ ОГАС
-- ============================================================
-- Этот скрипт создает базу данных со всеми необходимыми таблицами
-- Включает все миграции и изменения:
-- - Поле avatar_path в таблице users (из migration_add_avatar.sql)
-- - Поле image_path в таблице messages (из migration_add_message_images.sql)
-- - Таблица user_sessions (из migrations/add_user_sessions_table.sql)
-- - Таблицы support_tickets и support_messages (из migrations с поддержкой второй линии)
-- 
-- ИСПОЛЬЗОВАНИЕ:
-- 1. Через командную строку MySQL:
--    mysql -u root -p < complete_database.sql
-- 
-- 2. Через MySQL Workbench или phpMyAdmin:
--    Выполните весь скрипт целиком
-- 
-- 3. Через PHP скрипт:
--    См. scripts/create_database.php
-- ============================================================

-- Удаляем базу данных если она существует (ОСТОРОЖНО: удалит все данные!)
-- Раскомментируйте следующие 2 строки, если нужно пересоздать БД с нуля:
-- DROP DATABASE IF EXISTS `ogas`;
-- CREATE DATABASE `ogas` CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci;

-- Создаем базу данных если её нет
CREATE DATABASE IF NOT EXISTS `ogas` 
CHARACTER SET utf8mb4 
COLLATE utf8mb4_unicode_ci;

-- Используем созданную базу данных
USE `ogas`;

-- ============================================================
-- ТАБЛИЦЫ (в правильном порядке для внешних ключей)
-- ============================================================

-- 1. Таблица пользователей (базовая таблица)
-- Включает поле avatar_path из миграции migration_add_avatar.sql
CREATE TABLE IF NOT EXISTS `users` (
    `id` INT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
    `email` VARCHAR(255) NOT NULL UNIQUE,
    `password_hash` VARCHAR(255) NOT NULL,
    `user_type` ENUM('individual', 'legal') NOT NULL DEFAULT 'individual',
    `full_name` VARCHAR(255) NOT NULL,
    `avatar_path` VARCHAR(500) NULL COMMENT 'Путь к фото профиля',
    `is_active` TINYINT(1) NOT NULL DEFAULT 0 COMMENT 'Активирован ли аккаунт',
    `is_system` TINYINT(1) NOT NULL DEFAULT 0 COMMENT 'Системный пользователь (нельзя удалить)',
    `is_admin` TINYINT(1) NOT NULL DEFAULT 0 COMMENT 'Администратор системы',
    `created_at` TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    `updated_at` TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    INDEX `idx_email` (`email`),
    INDEX `idx_user_type` (`user_type`),
    INDEX `idx_is_active` (`is_active`),
    INDEX `idx_is_system` (`is_system`),
    INDEX `idx_is_admin` (`is_admin`),
    INDEX `idx_avatar_path` (`avatar_path`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- 2. Таблица предприятий (для юридических лиц)
CREATE TABLE IF NOT EXISTS `companies` (
    `id` INT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
    `user_id` INT UNSIGNED NOT NULL,
    `name` VARCHAR(255) NOT NULL,
    `address` TEXT,
    `okved_code` VARCHAR(50),
    `employee_count` INT UNSIGNED DEFAULT 0,
    `created_at` TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    `updated_at` TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    FOREIGN KEY (`user_id`) REFERENCES `users`(`id`) ON DELETE CASCADE,
    INDEX `idx_user_id` (`user_id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- 3. Таблица категорий товаров и услуг (без внешних ключей)
CREATE TABLE IF NOT EXISTS `categories` (
    `id` INT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
    `name` VARCHAR(100) NOT NULL UNIQUE COMMENT 'Название категории',
    `description` TEXT COMMENT 'Описание категории',
    `icon` VARCHAR(50) COMMENT 'Иконка категории (CSS класс или emoji)',
    `parent_id` INT UNSIGNED NULL COMMENT 'Родительская категория (для вложенных категорий)',
    `sort_order` INT UNSIGNED DEFAULT 0 COMMENT 'Порядок сортировки',
    `is_active` TINYINT(1) NOT NULL DEFAULT 1 COMMENT 'Активна ли категория',
    `created_at` TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    `updated_at` TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    FOREIGN KEY (`parent_id`) REFERENCES `categories`(`id`) ON DELETE SET NULL,
    INDEX `idx_name` (`name`),
    INDEX `idx_parent` (`parent_id`),
    INDEX `idx_active` (`is_active`),
    INDEX `idx_sort` (`sort_order`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- 4. Таблица товаров и услуг
CREATE TABLE IF NOT EXISTS `products` (
    `id` INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    `user_id` INT UNSIGNED NOT NULL,
    `name` VARCHAR(255) NOT NULL,
    `description` TEXT,
    `type` ENUM('product', 'service') NOT NULL DEFAULT 'product',
    `category` VARCHAR(100),
    `price` DECIMAL(10, 2) NOT NULL DEFAULT 0.00,
    `base_price` DECIMAL(10, 2) NOT NULL DEFAULT 0.00 COMMENT 'Начальная цена в рублях (цена продавца)',
    `doubles_price` DECIMAL(15, 2) NOT NULL DEFAULT 0.00 COMMENT 'Цена товара в дублях (новая система)',
    `unit` VARCHAR(50) DEFAULT 'шт',
    `quantity` INT DEFAULT NULL,
    `is_available` BOOLEAN DEFAULT TRUE,
    `images` TEXT,
    `created_at` TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    `updated_at` TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    FOREIGN KEY (`user_id`) REFERENCES `users`(`id`) ON DELETE CASCADE,
    INDEX `idx_user_id` (`user_id`),
    INDEX `idx_type` (`type`),
    INDEX `idx_is_available` (`is_available`),
    INDEX `idx_created_at` (`created_at`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- 5. Таблица истории цен товаров и услуг
CREATE TABLE IF NOT EXISTS `product_prices_history` (
    `id` INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    `product_id` INT UNSIGNED NOT NULL,
    `price` DECIMAL(10, 2) NOT NULL,
    `created_at` TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    FOREIGN KEY (`product_id`) REFERENCES `products`(`id`) ON DELETE CASCADE,
    INDEX `idx_product_id` (`product_id`),
    INDEX `idx_created_at` (`created_at`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- 6. Таблица для системы круговой поруки "Община"
CREATE TABLE IF NOT EXISTS `community_requests` (
    `id` INT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
    `user_id` INT UNSIGNED NOT NULL,
    `amount` INT UNSIGNED NOT NULL COMMENT 'Количество векселей',
    `nominal` DECIMAL(15,2) NOT NULL DEFAULT 1000.00,
    `description` TEXT,
    `maturity_days` INT UNSIGNED NOT NULL COMMENT 'Срок погашения в днях',
    `status` ENUM('open', 'fulfilled', 'closed', 'cancelled') NOT NULL DEFAULT 'open',
    `target_company_user_id` INT UNSIGNED NULL COMMENT 'ID пользователя компании-продавца (юридическое лицо)',
    `product_description` TEXT NULL COMMENT 'Описание целевого товара/услуги для приобретения',
    `product_price` DECIMAL(15,2) NULL COMMENT 'Стоимость товара/услуги',
    `created_at` TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    `updated_at` TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    FOREIGN KEY (`user_id`) REFERENCES `users`(`id`) ON DELETE CASCADE,
    FOREIGN KEY (`target_company_user_id`) REFERENCES `users`(`id`) ON DELETE SET NULL,
    INDEX `idx_user` (`user_id`),
    INDEX `idx_status` (`status`),
    INDEX `idx_target_company` (`target_company_user_id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- 7. Таблица поручителей в системе "Община"
CREATE TABLE IF NOT EXISTS `community_guarantors` (
    `id` INT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
    `request_id` INT UNSIGNED NOT NULL,
    `guarantor_id` INT UNSIGNED NOT NULL,
    `bills_count` INT UNSIGNED NOT NULL DEFAULT 1,
    `status` ENUM('active', 'fulfilled', 'cancelled') NOT NULL DEFAULT 'active',
    `confirmed` TINYINT(1) NOT NULL DEFAULT 0 COMMENT 'Поручитель подтвердил согласие в групповом чате',
    `confirmed_at` TIMESTAMP NULL COMMENT 'Дата подтверждения согласия',
    `created_at` TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    FOREIGN KEY (`request_id`) REFERENCES `community_requests`(`id`) ON DELETE CASCADE,
    FOREIGN KEY (`guarantor_id`) REFERENCES `users`(`id`) ON DELETE RESTRICT,
    INDEX `idx_request` (`request_id`),
    INDEX `idx_guarantor` (`guarantor_id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- 8. Таблица векселей (зависит от community_requests и community_guarantors)
CREATE TABLE IF NOT EXISTS `bills` (
    `id` INT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
    `issuer_id` INT UNSIGNED NOT NULL COMMENT 'Выпустивший вексель',
    `holder_id` INT UNSIGNED NOT NULL COMMENT 'Держатель векселя',
    `nominal` DECIMAL(15,2) NOT NULL DEFAULT 1000.00 COMMENT 'Номинал векселя',
    `issue_date` TIMESTAMP DEFAULT CURRENT_TIMESTAMP COMMENT 'Дата выпуска',
    `maturity_date` TIMESTAMP NOT NULL COMMENT 'Срок погашения',
    `payment_date` TIMESTAMP NULL COMMENT 'Дата фактического погашения',
    `status` ENUM('active', 'paid', 'overdue', 'cancelled') NOT NULL DEFAULT 'active',
    `file_path` VARCHAR(500) NULL COMMENT 'Путь к PDF файлу векселя',
    `community_request_id` INT UNSIGNED NULL COMMENT 'ID заявки общины',
    `community_guarantor_id` INT UNSIGNED NULL COMMENT 'ID поручителя в общине',
    `created_at` TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    `updated_at` TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    FOREIGN KEY (`issuer_id`) REFERENCES `users`(`id`) ON DELETE RESTRICT,
    FOREIGN KEY (`holder_id`) REFERENCES `users`(`id`) ON DELETE RESTRICT,
    FOREIGN KEY (`community_request_id`) REFERENCES `community_requests`(`id`) ON DELETE SET NULL,
    FOREIGN KEY (`community_guarantor_id`) REFERENCES `community_guarantors`(`id`) ON DELETE SET NULL,
    INDEX `idx_issuer` (`issuer_id`),
    INDEX `idx_holder` (`holder_id`),
    INDEX `idx_status` (`status`),
    INDEX `idx_maturity_date` (`maturity_date`),
    INDEX `idx_file_path` (`file_path`),
    INDEX `idx_community_request` (`community_request_id`),
    INDEX `idx_community_guarantor` (`community_guarantor_id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- 9. Таблица сделок
CREATE TABLE IF NOT EXISTS `transactions` (
    `id` INT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
    `seller_id` INT UNSIGNED NOT NULL,
    `buyer_id` INT UNSIGNED NOT NULL,
    `description` TEXT,
    `category` VARCHAR(100),
    `transaction_type` ENUM('barter', 'guarantee', 'community', 'mixed') NOT NULL DEFAULT 'barter',
    `status` ENUM('pending', 'active', 'completed', 'cancelled') NOT NULL DEFAULT 'pending',
    `seller_confirmed` TINYINT(1) NOT NULL DEFAULT 0 COMMENT 'Продавец подтвердил сделку',
    `buyer_confirmed` TINYINT(1) NOT NULL DEFAULT 0 COMMENT 'Покупатель подтвердил сделку',
    `community_request_id` INT UNSIGNED NULL COMMENT 'ID заявки общины',
    `created_at` TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    `updated_at` TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    FOREIGN KEY (`seller_id`) REFERENCES `users`(`id`) ON DELETE RESTRICT,
    FOREIGN KEY (`buyer_id`) REFERENCES `users`(`id`) ON DELETE RESTRICT,
    FOREIGN KEY (`community_request_id`) REFERENCES `community_requests`(`id`) ON DELETE SET NULL,
    INDEX `idx_seller` (`seller_id`),
    INDEX `idx_buyer` (`buyer_id`),
    INDEX `idx_status` (`status`),
    INDEX `idx_category` (`category`),
    INDEX `idx_community_request` (`community_request_id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- 10. Таблица для сообщений чата в транзакциях и групповых чатах общины
-- Включает поле image_path из миграции migration_add_message_images.sql
CREATE TABLE IF NOT EXISTS `messages` (
    `id` INT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
    `transaction_id` INT UNSIGNED NULL COMMENT 'ID транзакции (NULL для групповых чатов общины)',
    `community_request_id` INT UNSIGNED NULL COMMENT 'ID заявки общины для группового чата',
    `user_id` INT UNSIGNED NOT NULL COMMENT 'ID пользователя, отправившего сообщение',
    `message` TEXT NOT NULL COMMENT 'Текст сообщения',
    `image_path` VARCHAR(255) NULL DEFAULT NULL COMMENT 'Путь к изображению в сообщении',
    `is_read` TINYINT(1) NOT NULL DEFAULT 0 COMMENT 'Прочитано ли сообщение',
    `created_at` TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    FOREIGN KEY (`transaction_id`) REFERENCES `transactions`(`id`) ON DELETE CASCADE,
    FOREIGN KEY (`community_request_id`) REFERENCES `community_requests`(`id`) ON DELETE CASCADE,
    FOREIGN KEY (`user_id`) REFERENCES `users`(`id`) ON DELETE CASCADE,
    INDEX `idx_transaction_id` (`transaction_id`),
    INDEX `idx_community_request` (`community_request_id`),
    INDEX `idx_user_id` (`user_id`),
    INDEX `idx_created_at` (`created_at`),
    INDEX `idx_transaction_created` (`transaction_id`, `created_at`),
    INDEX `idx_image_path` (`image_path`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- 11. Таблица рейтинговой системы "Око"
CREATE TABLE IF NOT EXISTS `ratings` (
    `id` INT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
    `user_id` INT UNSIGNED NOT NULL UNIQUE,
    `balance_score` DECIMAL(10,2) NOT NULL DEFAULT 0 COMMENT 'Балансовый расчёт',
    `payment_discipline` DECIMAL(5,2) NOT NULL DEFAULT 0 COMMENT 'Дисциплина погашаемости (%)',
    `early_payment_avg` DECIMAL(5,2) NOT NULL DEFAULT 1.0 COMMENT 'Усреднённое значение досрочного погашения',
    `total_rating` DECIMAL(10,2) NOT NULL DEFAULT 0 COMMENT 'Общий рейтинг',
    `doubles` DECIMAL(15,2) NOT NULL DEFAULT 0 COMMENT 'Дубли (репутационный капитал)',
    `updated_at` TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    FOREIGN KEY (`user_id`) REFERENCES `users`(`id`) ON DELETE CASCADE,
    INDEX `idx_rating` (`total_rating`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- 12. Таблица истории рейтинга "Око"
CREATE TABLE IF NOT EXISTS `rating_history` (
    `id` INT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
    `user_id` INT UNSIGNED NOT NULL,
    `total_rating` DECIMAL(10,2) NOT NULL,
    `balance_score` DECIMAL(10,2) NOT NULL,
    `payment_discipline` DECIMAL(5,2) NOT NULL,
    `early_payment_avg` DECIMAL(5,2) NOT NULL,
    `doubles` DECIMAL(15,2) NOT NULL,
    `created_at` TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    FOREIGN KEY (`user_id`) REFERENCES `users`(`id`) ON DELETE CASCADE,
    INDEX `idx_user_id` (`user_id`),
    INDEX `idx_created_at` (`created_at`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- 13. Таблица для токенов сброса пароля
CREATE TABLE IF NOT EXISTS `password_resets` (
    `id` INT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
    `user_id` INT UNSIGNED NOT NULL,
    `token` VARCHAR(64) NOT NULL UNIQUE,
    `expires_at` TIMESTAMP NOT NULL,
    `used_at` TIMESTAMP NULL COMMENT 'Дата использования токена',
    `created_at` TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    FOREIGN KEY (`user_id`) REFERENCES `users`(`id`) ON DELETE CASCADE,
    INDEX `idx_token` (`token`),
    INDEX `idx_user_id` (`user_id`),
    INDEX `idx_expires_at` (`expires_at`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- 14. Таблица для системы уведомлений
CREATE TABLE IF NOT EXISTS `notifications` (
    `id` INT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
    `user_id` INT UNSIGNED NOT NULL COMMENT 'Пользователь, которому отправлено уведомление',
    `type` VARCHAR(50) NOT NULL COMMENT 'Тип уведомления',
    `title` VARCHAR(255) NOT NULL COMMENT 'Заголовок уведомления',
    `message` TEXT NOT NULL COMMENT 'Текст уведомления',
    `related_id` INT UNSIGNED NULL COMMENT 'ID связанного объекта (вексель, транзакция и т.д.)',
    `related_type` VARCHAR(50) NULL COMMENT 'Тип связанного объекта (bill, transaction, community_request и т.д.)',
    `is_read` TINYINT(1) NOT NULL DEFAULT 0 COMMENT 'Прочитано ли уведомление',
    `read_at` TIMESTAMP NULL COMMENT 'Дата прочтения',
    `created_at` TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    FOREIGN KEY (`user_id`) REFERENCES `users`(`id`) ON DELETE CASCADE,
    INDEX `idx_user_id` (`user_id`),
    INDEX `idx_type` (`type`),
    INDEX `idx_is_read` (`is_read`),
    INDEX `idx_created_at` (`created_at`),
    INDEX `idx_user_read` (`user_id`, `is_read`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- 15. Таблица для системы подписок
CREATE TABLE IF NOT EXISTS `subscriptions` (
    `id` INT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
    `user_id` INT UNSIGNED NOT NULL COMMENT 'Пользователь',
    `amount` DECIMAL(10,2) NOT NULL COMMENT 'Сумма подписки',
    `period_days` INT UNSIGNED NOT NULL DEFAULT 30 COMMENT 'Период подписки в днях',
    `status` ENUM('active', 'expired', 'cancelled') NOT NULL DEFAULT 'active' COMMENT 'Статус подписки',
    `start_date` TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP COMMENT 'Дата начала подписки',
    `end_date` TIMESTAMP NOT NULL COMMENT 'Дата окончания подписки',
    `bill_id` INT UNSIGNED NULL COMMENT 'ID векселя для оплаты подписки',
    `paid_at` TIMESTAMP NULL COMMENT 'Дата оплаты подписки',
    `reminder_sent` TINYINT(1) NOT NULL DEFAULT 0 COMMENT 'Отправлено ли напоминание',
    `created_at` TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    `updated_at` TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    FOREIGN KEY (`user_id`) REFERENCES `users`(`id`) ON DELETE CASCADE,
    FOREIGN KEY (`bill_id`) REFERENCES `bills`(`id`) ON DELETE SET NULL,
    INDEX `idx_user_id` (`user_id`),
    INDEX `idx_status` (`status`),
    INDEX `idx_end_date` (`end_date`),
    INDEX `idx_user_status` (`user_id`, `status`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- 16. Таблица для отслеживания активных сессий пользователей
-- Из миграции migrations/add_user_sessions_table.sql
CREATE TABLE IF NOT EXISTS `user_sessions` (
    `id` INT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
    `user_id` INT UNSIGNED NOT NULL COMMENT 'ID пользователя',
    `session_id` VARCHAR(128) NOT NULL COMMENT 'ID сессии PHP',
    `ip_address` VARCHAR(45) NOT NULL COMMENT 'IP адрес',
    `user_agent` TEXT COMMENT 'User Agent браузера',
    `device_info` VARCHAR(255) COMMENT 'Информация об устройстве',
    `last_activity` TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP COMMENT 'Последняя активность',
    `created_at` TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP COMMENT 'Время создания сессии',
    `is_current` TINYINT(1) NOT NULL DEFAULT 1 COMMENT 'Текущая сессия',
    INDEX `idx_user_id` (`user_id`),
    INDEX `idx_session_id` (`session_id`),
    INDEX `idx_last_activity` (`last_activity`),
    INDEX `idx_user_current` (`user_id`, `is_current`),
    FOREIGN KEY (`user_id`) REFERENCES `users`(`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- 17. Таблица для обращений в поддержку (с поддержкой второй линии)
-- Из миграций migrations/add_support_tickets_table.sql и add_support_second_line_fields.sql
-- Полная версия из migrations/support_tickets_complete.sql
CREATE TABLE IF NOT EXISTS `support_tickets` (
    `id` INT UNSIGNED NOT NULL AUTO_INCREMENT,
    `user_id` INT UNSIGNED NOT NULL COMMENT 'ID пользователя, создавшего обращение',
    `subject` VARCHAR(255) NOT NULL COMMENT 'Тема обращения',
    `status` ENUM('open', 'in_progress', 'resolved', 'closed') NOT NULL DEFAULT 'open' COMMENT 'Статус обращения',
    `priority` ENUM('low', 'medium', 'high', 'urgent') NOT NULL DEFAULT 'medium' COMMENT 'Приоритет',
    `ticket_type` ENUM('general', 'transaction', 'bill', 'system') NOT NULL DEFAULT 'general' COMMENT 'Тип заявки',
    `related_transaction_id` INT UNSIGNED NULL DEFAULT NULL COMMENT 'ID связанной транзакции (для поддержки второй линии)',
    `related_bill_id` INT UNSIGNED NULL DEFAULT NULL COMMENT 'ID связанного векселя (для поддержки второй линии)',
    `assigned_to` INT UNSIGNED NULL DEFAULT NULL COMMENT 'ID администратора, которому назначено обращение',
    `created_at` TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
    `updated_at` TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    `resolved_at` TIMESTAMP NULL DEFAULT NULL COMMENT 'Дата решения',
    PRIMARY KEY (`id`),
    INDEX `idx_user_id` (`user_id`),
    INDEX `idx_status` (`status`),
    INDEX `idx_priority` (`priority`),
    INDEX `idx_ticket_type` (`ticket_type`),
    INDEX `idx_related_transaction` (`related_transaction_id`),
    INDEX `idx_related_bill` (`related_bill_id`),
    INDEX `idx_assigned_to` (`assigned_to`),
    INDEX `idx_created_at` (`created_at`),
    FOREIGN KEY (`user_id`) REFERENCES `users`(`id`) ON DELETE CASCADE,
    FOREIGN KEY (`assigned_to`) REFERENCES `users`(`id`) ON DELETE SET NULL,
    FOREIGN KEY (`related_transaction_id`) REFERENCES `transactions`(`id`) ON DELETE SET NULL,
    FOREIGN KEY (`related_bill_id`) REFERENCES `bills`(`id`) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci COMMENT='Обращения в поддержку (с поддержкой второй линии)';

-- 18. Таблица для сообщений в обращениях поддержки
-- Из миграции migrations/add_support_tickets_table.sql
CREATE TABLE IF NOT EXISTS `support_messages` (
    `id` INT UNSIGNED NOT NULL AUTO_INCREMENT,
    `ticket_id` INT UNSIGNED NOT NULL COMMENT 'ID обращения',
    `user_id` INT UNSIGNED NOT NULL COMMENT 'ID пользователя, отправившего сообщение',
    `message` TEXT NOT NULL COMMENT 'Текст сообщения',
    `is_admin` TINYINT(1) NOT NULL DEFAULT 0 COMMENT 'Сообщение от администратора',
    `created_at` TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
    `read_at` TIMESTAMP NULL DEFAULT NULL COMMENT 'Дата прочтения',
    PRIMARY KEY (`id`),
    INDEX `idx_ticket_id` (`ticket_id`),
    INDEX `idx_user_id` (`user_id`),
    INDEX `idx_created_at` (`created_at`),
    FOREIGN KEY (`ticket_id`) REFERENCES `support_tickets`(`id`) ON DELETE CASCADE,
    FOREIGN KEY (`user_id`) REFERENCES `users`(`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci COMMENT='Сообщения в обращениях поддержки';

-- ============================================================
-- НАЧАЛЬНЫЕ ДАННЫЕ
-- ============================================================

-- Добавляем стандартные категории
INSERT INTO `categories` (`name`, `description`, `icon`, `sort_order`) VALUES
('Продукты питания', 'Продукты питания и напитки', '🥖', 1),
('Одежда и обувь', 'Одежда, обувь и аксессуары', '👕', 2),
('Услуги', 'Различные виды услуг', '🔧', 3),
('Электроника', 'Электронная техника и устройства', '💻', 4),
('Мебель', 'Мебель и предметы интерьера', '🪑', 5),
('Строительные материалы', 'Материалы для строительства и ремонта', '🏗️', 6),
('Транспорт', 'Транспортные средства и запчасти', '🚗', 7),
('Животные', 'Домашние животные и корм', '🐕', 8),
('Образование', 'Образовательные услуги и курсы', '📚', 9),
('Здоровье', 'Медицинские услуги и товары для здоровья', '🏥', 10),
('Спорт и отдых', 'Спортивные товары и услуги отдыха', '⚽', 11),
('Книги и медиа', 'Книги, фильмы, музыка', '📖', 12),
('Бытовая химия', 'Средства для уборки и гигиены', '🧴', 13),
('Другое', 'Прочее', '📦', 99)
ON DUPLICATE KEY UPDATE `name` = `name`;

-- Создание системного пользователя ОГАС
INSERT INTO `users` (
    `email`, 
    `password_hash`, 
    `user_type`, 
    `full_name`, 
    `is_active`, 
    `is_system`
) VALUES (
    'system@ogas',
    '$2y$10$dummyhashforsystemuserOGASsystemuserOGAS', -- Заглушка (не используется)
    'legal',
    'ОГАС (Системный пользователь)',
    1, -- Всегда активен
    1  -- Системный пользователь
) ON DUPLICATE KEY UPDATE 
    `is_system` = 1,
    `is_active` = 1,
    `full_name` = 'ОГАС (Системный пользователь)';

-- Создание администратора системы
-- Пароль по умолчанию: admin123
-- ВАЖНО: После первого входа обязательно смените пароль!
INSERT INTO `users` (
    `email`, 
    `password_hash`, 
    `user_type`, 
    `full_name`, 
    `is_active`, 
    `is_admin`,
    `is_system`
) VALUES (
    'admin@ogas',
    '$2y$10$XbjCSlJIWejz2adX4EGA7.d3GsOUYbBWyPczgs3Vl25elvo9hMGCy', -- Пароль: admin123
    'legal',
    'Администратор ОГАС',
    1, -- Активен
    1, -- Администратор
    0  -- Не системный пользователь
) ON DUPLICATE KEY UPDATE 
    `is_admin` = 1,
    `is_active` = 1,
    `full_name` = 'Администратор ОГАС';

-- ============================================================




