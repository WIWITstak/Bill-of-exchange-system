# Структура CSS файлов ОГАС

## Обзор

CSS код организован в модульную структуру для улучшения поддерживаемости и читаемости.

## Основные файлы

- **`style.css`** - Главный файл стилей, импортирует все модули
- **`strict-theme.css`** - Строгая тема (минималистичный дизайн)

## Структура модулей

### Базовые модули (`modules/`)

- **`variables.css`** - CSS переменные (цвета, отступы, тени, градиенты)
- **`base.css`** - Базовые стили HTML элементов и сброс стилей
- **`layout.css`** - Структура приложения (sidebar, header, main content)
- **`responsive.css`** - Медиа-запросы для адаптивности

### Модули компонентов (`modules/components/`)

- **`buttons.css`** - Стили всех кнопок
- **`cards.css`** - Стили карточек (request-card, user-card, chat-card и т.д.)
- **`forms.css`** - Стили форм и полей ввода
- **`tables.css`** - Стили таблиц
- **`modals.css`** - Стили модальных окон
- **`auth.css`** - Формы авторизации и регистрации
- **`alerts.css`** - Алерты и сообщения (успех, ошибка)
- **`status.css`** - Статусы и значки (badges)
- **`notifications.css`** - Уведомления и dropdown
- **`filters.css`** - Фильтры и формы поиска
- **`profile.css`** - Редактирование профиля
- **`utilities.css`** - Утилиты (text-muted, text-center, статистика)

### Модули страниц (`modules/pages/`)

- **`bills.css`** - Страница векселей
- **`rating.css`** - Страница рейтинга
- **`dashboard.css`** - Рабочий кабинет
- **`community.css`** - Страница общины
- **`chats.css`** - Страница чатов
- **`depository.css`** - Депозитарий
- **`users.css`** - Страница пользователей
- **`transactions.css`** - Страница транзакций

## Порядок импорта

Модули импортируются в следующем порядке:

1. Переменные (variables.css)
2. Базовые стили (base.css)
3. Layout (layout.css)
4. Компоненты (buttons, cards, forms, tables, modals, auth, alerts, status, notifications, filters, profile, utilities)
5. Страницы (bills, rating, dashboard, community, chats, depository, users, transactions)
6. Адаптивность (responsive.css)

## Использование CSS переменных

Все основные значения (цвета, отступы, тени) вынесены в переменные в `variables.css`. Используйте их вместо хардкода:

```css
/* Вместо */
color: #667eea;
padding: 12px;
border-radius: 8px;

/* Используйте */
color: var(--color-primary);
padding: var(--spacing-md);
border-radius: var(--radius-md);
```

## Добавление новых стилей

1. **Новый компонент** → Добавьте в соответствующий файл в `modules/components/`
2. **Новая страница** → Создайте файл в `modules/pages/` и добавьте импорт в `style.css`
3. **Новые переменные** → Добавьте в `modules/variables.css`
4. **Адаптивные стили** → Добавьте в `modules/responsive.css`

## Статистика

- **Основной файл**: ~4140 строк (было ~7164)
- **Сокращение**: ~42% (3024 строки)
- **Модулей**: 24 файла
- **Организация**: Модульная структура

