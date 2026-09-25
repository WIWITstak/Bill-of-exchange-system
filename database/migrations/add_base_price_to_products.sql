-- Миграция: Добавление поля base_price (начальная цена) в таблицу products
-- Фактическая цена будет рассчитываться динамически на основе рейтинга покупателя
-- Формула: фактическая_цена = начальная_цена / рейтинг_покупателя

ALTER TABLE `products` 
ADD COLUMN `base_price` DECIMAL(10, 2) NULL DEFAULT NULL COMMENT 'Начальная цена товара/услуги (цена продавца)' AFTER `price`;

-- Если поле price уже существует, копируем его значения в base_price для существующих записей
UPDATE `products` SET `base_price` = `price` WHERE `base_price` IS NULL;

-- Делаем base_price обязательным полем
ALTER TABLE `products` 
MODIFY COLUMN `base_price` DECIMAL(10, 2) NOT NULL DEFAULT 0.00 COMMENT 'Начальная цена товара/услуги (цена продавца)';













