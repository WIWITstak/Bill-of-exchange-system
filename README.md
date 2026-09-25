# Руководство разработчика

Это руководство поможет разработчикам понять структуру проекта.

##  Содержание

1. [Стандарты кодирования](#стандарты-кодирования)
2. [Структура проекта](#структура-проекта)
3. [Создание новых модулей](#создание-новых-модулей)
4. [Работа с базой данных](#работа-с-базой-данных)
5. [Безопасность](#безопасность)
6. [Тестирование](#тестирование)
7. [Миграции БД](#миграции-бд)

##  Стандарты кодирования

### PHP

- **PSR-12** - стандарт кодирования
- **PSR-4** - автозагрузка классов
- **Именование:**
  - Классы: `PascalCase` (например, `UserService`)
  - Методы: `camelCase` (например, `getUserById()`)
  - Константы: `UPPER_SNAKE_CASE` (например, `MAX_SESSIONS`)
  - Переменные: `camelCase` (например, `$userId`)



##  Структура проекта

### Модели (Models)

Модели находятся в `src/Models/` и представляют сущности базы данных.

**Пример модели:**

```php
<?php

namespace OGAS\Models;

use OGAS\Database;
use PDO;

class Product
{
    private ?int $id = null;
    private int $userId;
    private string $name;
    // ...
    
    public static function findById(int $id): ?self
    {
        $db = Database::getConnection();
        $stmt = $db->prepare("SELECT * FROM products WHERE id = ?");
        $stmt->execute([$id]);
        $data = $stmt->fetch(PDO::FETCH_ASSOC);
        
        if (!$data) {
            return null;
        }
        
        return self::fromArray($data);
    }
    
    public function save(): bool
    {
        $db = Database::getConnection();
        // ...
    }
}
```

### Сервисы (Services)

Сервисы находятся в `src/Services/` и содержат бизнес-логику.

**Пример сервиса:**

```php
<?php

namespace OGAS\Services;

use OGAS\Models\Product;
use OGAS\Core\Security;

class ProductService
{
    public static function createProduct(int $userId, array $data): Product
    {
        // Валидация
        $name = Security::sanitizeString($data['name'] ?? '');
        if (empty($name)) {
            throw new \InvalidArgumentException('Name is required');
        }
        
        // Создание
        $product = Product::create([
            'user_id' => $userId,
            'name' => $name,
            // ...
        ]);
        
        return $product;
    }
}
```

### API Endpoints

API endpoints находятся в `public/api/` и обрабатывают HTTP запросы.

**Пример endpoint:**

```php
<?php

require_once __DIR__ . '/../../src/bootstrap.php';

use OGAS\Services\Auth;
use OGAS\Services\ProductService;
use OGAS\Core\Security;
use OGAS\Core\RateLimiter;

header('Content-Type: application/json; charset=UTF-8');

// Rate limiting
$clientIp = Security::getClientIp();
RateLimiter::requireLimit($clientIp, 60, 60);

// Аутентификация
Auth::requireAuth();
$user = Auth::user();

// CSRF защита для POST
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    Security::requireCsrfToken();
}

$action = $_GET['action'] ?? $_POST['action'] ?? '';

try {
    switch ($action) {
        case 'create':
            $data = [
                'name' => $_POST['name'] ?? '',
                // ...
            ];
            $product = ProductService::createProduct($user->getId(), $data);
            echo json_encode([
                'success' => true,
                'data' => $product->toArray()
            ]);
            break;
            
        default:
            throw new \Exception('Invalid action');
    }
} catch (\Exception $e) {
    http_response_code(400);
    echo json_encode([
        'success' => false,
        'error' => $e->getMessage()
    ]);
}
```

##  Создание новых модулей

### Шаг 1: Создание модели

1. Создайте файл `src/Models/YourModel.php`
2. Реализуйте методы:
   - `fromArray()` - создание из массива
   - `findById()` - поиск по ID
   - `save()` - сохранение
   - `delete()` - удаление

### Шаг 2: Создание сервиса

1. Создайте файл `src/Services/YourService.php`
2. Реализуйте бизнес-логику:
   - Валидация данных
   - Работа с моделями
   - Обработка ошибок

### Шаг 3: Создание API endpoint

1. Создайте файл `public/api/your-endpoint.php`
2. Добавьте:
   - Аутентификацию
   - Rate limiting
   - CSRF защиту
   - Обработку действий

### Шаг 4: Создание страницы (если нужно)

1. Создайте файл `public/your-page.php`
2. Используйте шаблон `templates/base.php`
3. Добавьте стили в `public/css/`
4. Добавьте JavaScript в `public/js/`

##  Работа с базой данных

### Подключение

```php
use OGAS\Database;

$db = Database::getConnection();
```

### Выполнение запросов

```php
// SELECT
$stmt = $db->prepare("SELECT * FROM users WHERE id = ?");
$stmt->execute([$userId]);
$user = $stmt->fetch(PDO::FETCH_ASSOC);

// INSERT
$stmt = $db->prepare("INSERT INTO users (email, password_hash) VALUES (?, ?)");
$stmt->execute([$email, $passwordHash]);
$userId = $db->lastInsertId();

// UPDATE
$stmt = $db->prepare("UPDATE users SET email = ? WHERE id = ?");
$stmt->execute([$newEmail, $userId]);

// DELETE
$stmt = $db->prepare("DELETE FROM users WHERE id = ?");
$stmt->execute([$userId]);
```

### Prepared Statements

**Всегда используйте prepared statements** для защиты от SQL инъекций:

```php
//  Правильно
$stmt = $db->prepare("SELECT * FROM users WHERE email = ?");
$stmt->execute([$email]);


##  Безопасность

### Валидация данных

```php
use OGAS\Core\Security;

// Санитизация строки
$name = Security::sanitizeString($_POST['name'] ?? '');

// Валидация email
if (!Security::validateEmail($email)) {
    throw new \InvalidArgumentException('Invalid email');
}

// Валидация URL
if (!Security::validateUrl($url)) {
    throw new \InvalidArgumentException('Invalid URL');
}
```

### CSRF защита

```php
// В форме
<?= csrf_field() ?>

// В обработчике
Security::requireCsrfToken();
```

### Rate Limiting

```php
use OGAS\Core\RateLimiter;

$clientIp = Security::getClientIp();
RateLimiter::requireLimit($clientIp, 60, 60); // 60 запросов в минуту
```

### Безопасные редиректы

```php
use OGAS\Core\Security;

// Безопасный редирект
Security::safeRedirect('/dashboard.php');

// Получить безопасный URL из параметра
$redirectUrl = Security::getSafeRedirectUrl('/dashboard.php');
```

### Экранирование вывода

```php
// В шаблонах
<?= htmlspecialchars($user->getFullName()) ?>

// Или используйте helper
<?= e($user->getFullName()) ?>
```

##  Тестирование

### Структура тестов

Тесты находятся в папке `tests/`:

```
tests/
├── Unit/           # Unit тесты
├── Integration/    # Интеграционные тесты
└── Functional/     # Функциональные тесты
```

### Пример теста

```php
<?php

namespace OGAS\Tests\Unit;

use PHPUnit\Framework\TestCase;
use OGAS\Models\User;

class UserTest extends TestCase
{
    public function testFindById()
    {
        $user = User::findById(1);
        $this->assertNotNull($user);
        $this->assertEquals(1, $user->getId());
    }
}
```

##  Миграции БД

### Создание миграции

1. Создайте файл в `database/migrations/`:
   ```
   database/migrations/YYYYMMDD_description.sql
   ```

2. Напишите SQL:

```sql
-- Миграция: Добавление таблицы example
-- Дата: 2025-01-15

CREATE TABLE IF NOT EXISTS `example` (
    `id` INT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
    `name` VARCHAR(255) NOT NULL,
    `created_at` TIMESTAMP DEFAULT CURRENT_TIMESTAMP
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
```

3. Выполните миграцию через phpMyAdmin или командную строку

### Откат миграции

Создайте файл отката:

```sql
-- Откат миграции: Удаление таблицы example
-- Дата: 2025-01-15

DROP TABLE IF EXISTS `example`;
```

##  Зависимости

### Установка зависимостей

```bash
composer install
```

### Добавление новой зависимости

```bash
composer require vendor/package
```

### Обновление зависимостей

```bash
composer update
```

##  Развертывание

### Подготовка к продакшену

1. Установите `APP_ENV=production` в `.env`
2. Установите `APP_DEBUG=false`
3. Очистите кэш (если есть)
4. Оптимизируйте автозагрузку:
   ```bash
   composer dump-autoload --optimize
   ```

### Резервное копирование

Перед развертыванием:
1. Создайте резервную копию БД
2. Сохраните файлы `storage/`
3. Сохраните конфигурацию `.env`

##  Полезные ресурсы

- [Архитектура системы](architecture.md)
- [Справочник API](api-reference.md)
- [Безопасность](../SECURITY.md)

##  Часто задаваемые вопросы

**Q: Как добавить новую таблицу в БД?**  
A: Создайте миграцию в `database/migrations/` и выполните её.

**Q: Как создать новый API endpoint?**  
A: Создайте файл в `public/api/`, добавьте аутентификацию, rate limiting и CSRF защиту.

**Q: Как работать с сессиями?**  
A: Используйте класс `OGAS\Core\Session` для работы с сессиями.

**Q: Как логировать события?**  
A: Используйте `OGAS\Core\SecurityLogger` для логирования событий безопасности.

---

_Для вопросов обращайтесь к команде разработки._








