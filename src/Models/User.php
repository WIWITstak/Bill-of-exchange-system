<?php

namespace OGAS\Models;

use OGAS\Database;
use PDO;

/**
 * Модель пользователя
 */
class User
{
    private ?int $id = null;
    private string $email;
    private string $passwordHash;
    private string $userType; // 'individual' или 'legal'
    private string $fullName;
    private bool $isActive = false;
    private bool $isSystem = false;
    private bool $isAdmin = false;
    private ?string $createdAt = null;
    private ?string $updatedAt = null;
    private ?string $avatarPath = null;
    
    /**
     * Найти пользователя по ID (с кэшированием)
     */
    public static function findById(int $id): ?self
    {
        // Используем кэш для частых запросов пользователей
        $cacheKey = "user:id:{$id}";
        
        if (class_exists('OGAS\Core\Cache')) {
            return \OGAS\Core\Cache::remember($cacheKey, function() use ($id) {
                return self::fetchUserById($id);
            }, 1800); // 30 минут
        }
        
        return self::fetchUserById($id);
    }
    
    /**
     * Получить пользователя из БД (без кэша)
     */
    private static function fetchUserById(int $id): ?self
    {
        try {
            $db = Database::getConnection();
            $stmt = $db->prepare("SELECT * FROM users WHERE id = ?");
            $stmt->execute([$id]);
            $data = $stmt->fetch(PDO::FETCH_ASSOC);
            
            if (!$data) {
                return null;
            }
            
            return self::fromArray($data);
        } catch (\PDOException $e) {
            // Логируем ошибку и возвращаем null
            error_log("Ошибка при поиске пользователя по ID {$id}: " . $e->getMessage());
            return null;
        }
    }
    
    /**
     * Найти нескольких пользователей по ID (batch loading для решения N+1)
     * @param array $ids Массив ID пользователей
     * @return array Ассоциативный массив [id => User]
     */
    public static function findByIds(array $ids): array
    {
        if (empty($ids)) {
            return [];
        }
        
        // Убираем дубликаты
        $ids = array_unique($ids);
        
        // Проверяем кэш для каждого ID
        $users = [];
        $missingIds = [];
        
        if (class_exists('OGAS\Core\Cache')) {
            foreach ($ids as $id) {
                $cacheKey = "user:id:{$id}";
                $cached = \OGAS\Core\Cache::get($cacheKey);
                if ($cached !== null) {
                    $users[$id] = $cached;
                } else {
                    $missingIds[] = $id;
                }
            }
        } else {
            $missingIds = $ids;
        }
        
        // Если все найдены в кэше, возвращаем
        if (empty($missingIds)) {
            return $users;
        }
        
        try {
            $db = Database::getConnection();
            
            // Создаем плейсхолдеры для IN запроса
            $placeholders = implode(',', array_fill(0, count($missingIds), '?'));
            $stmt = $db->prepare("SELECT * FROM users WHERE id IN ({$placeholders})");
            $stmt->execute($missingIds);
            
            while ($data = $stmt->fetch(PDO::FETCH_ASSOC)) {
                $user = self::fromArray($data);
                $users[$user->getId()] = $user;
                
                // Кэшируем каждого пользователя
                if (class_exists('OGAS\Core\Cache')) {
                    $cacheKey = "user:id:{$user->getId()}";
                    \OGAS\Core\Cache::set($cacheKey, $user, 1800); // 30 минут
                }
            }
            
            return $users;
        } catch (\PDOException $e) {
            error_log("Ошибка при batch loading пользователей: " . $e->getMessage());
            return $users;
        }
    }
    
    /**
     * Найти пользователя по email
     */
    public static function findByEmail(string $email): ?self
    {
        $db = Database::getConnection();
        $stmt = $db->prepare("SELECT * FROM users WHERE email = ?");
        $stmt->execute([$email]);
        $data = $stmt->fetch(PDO::FETCH_ASSOC);
        
        if (!$data) {
            return null;
        }
        
        return self::fromArray($data);
    }
    
    /**
     * Найти пользователей по имени или email (поиск)
     */
    public static function search(string $query, int $limit = 20): array
    {
        $db = Database::getConnection();
        $searchTerm = '%' . $query . '%';
        $stmt = $db->prepare("
            SELECT * FROM users 
            WHERE (full_name LIKE ? OR email LIKE ?) AND is_system = 0
            ORDER BY full_name ASC
            LIMIT ?
        ");
        $stmt->execute([$searchTerm, $searchTerm, $limit]);
        
        $users = [];
        while ($data = $stmt->fetch(PDO::FETCH_ASSOC)) {
            $users[] = self::fromArray($data);
        }
        
        return $users;
    }
    
    /**
     * Получить всех активных пользователей
     */
    public static function getAllActive(int $limit = 100): array
    {
        $db = Database::getConnection();
        $stmt = $db->prepare("
            SELECT * FROM users 
            WHERE is_active = 1 AND is_system = 0
            ORDER BY full_name ASC
            LIMIT ?
        ");
        $stmt->execute([$limit]);
        
        $users = [];
        while ($data = $stmt->fetch(PDO::FETCH_ASSOC)) {
            $users[] = self::fromArray($data);
        }
        
        return $users;
    }
    
    /**
     * Получить системного пользователя ОГАС
     */
    public static function getSystemUser(): ?self
    {
        $db = Database::getConnection();
        $stmt = $db->prepare("SELECT * FROM users WHERE is_system = 1 LIMIT 1");
        $stmt->execute();
        $data = $stmt->fetch(PDO::FETCH_ASSOC);
        
        if (!$data) {
            return null;
        }
        
        return self::fromArray($data);
    }
    
    /**
     * Создать или получить системного пользователя ОГАС
     */
    public static function getOrCreateSystemUser(): self
    {
        $systemUser = self::getSystemUser();
        
        if ($systemUser) {
            return $systemUser;
        }
        
        // Создаём системного пользователя
        $db = Database::getConnection();
        $stmt = $db->prepare("
            INSERT INTO users (email, password_hash, user_type, full_name, is_active, is_system)
            VALUES (?, ?, ?, ?, 1, 1)
        ");
        
        $dummyHash = password_hash('system_user_dummy_password', PASSWORD_DEFAULT);
        $stmt->execute([
            'system@ogas',
            $dummyHash,
            'legal',
            'ОГАС (Системный пользователь)'
        ]);
        
        $userId = (int)$db->lastInsertId();
        return self::findById($userId);
    }
    
    /**
     * Создать пользователя из массива данных
     */
    public static function fromArray(array $data): self
    {
        $user = new self();
        $user->id = (int)$data['id'];
        $user->email = $data['email'];
        $user->passwordHash = $data['password_hash'];
        $user->userType = $data['user_type'];
        $user->fullName = $data['full_name'];
        $user->isActive = (bool)($data['is_active'] ?? false);
        $user->isSystem = (bool)($data['is_system'] ?? false);
        $user->isAdmin = (bool)($data['is_admin'] ?? false);
        $user->createdAt = $data['created_at'];
        $user->updatedAt = $data['updated_at'];
        $user->avatarPath = $data['avatar_path'] ?? null;
        return $user;
    }
    
    /**
     * Создать нового пользователя
     * Примечание: Нельзя создать системного пользователя через этот метод
     */
    public static function create(array $data): self
    {
        $db = Database::getConnection();
        
        $passwordHash = password_hash($data['password'], PASSWORD_DEFAULT);
        
        // Гарантируем, что создаваемый пользователь не системный
        $isSystem = isset($data['is_system']) ? (int)$data['is_system'] : 0;
        if ($isSystem) {
            throw new \Exception('Нельзя создать системного пользователя через метод create(). Используйте getOrCreateSystemUser().');
        }
        
        $stmt = $db->prepare("
            INSERT INTO users (email, password_hash, user_type, full_name, is_active, is_system)
            VALUES (?, ?, ?, ?, 0, 0)
        ");
        
        $stmt->execute([
            $data['email'],
            $passwordHash,
            $data['user_type'] ?? 'individual',
            $data['full_name']
        ]);
        
        $userId = (int)$db->lastInsertId();
        return self::findById($userId);
    }
    
    /**
     * Сохранить изменения
     */
    public function save(): bool
    {
        if (!$this->id) {
            return false;
        }
        
        // Защита от изменения системного пользователя
        if ($this->isSystem) {
            // Системный пользователь нельзя редактировать (кроме некоторых полей)
            return false;
        }
        
        $db = Database::getConnection();
        $stmt = $db->prepare("
            UPDATE users 
            SET email = ?, user_type = ?, full_name = ?, is_active = ?, is_admin = ?, avatar_path = ?
            WHERE id = ? AND is_system = 0
        ");
        
        $result = $stmt->execute([
            $this->email,
            $this->userType,
            $this->fullName,
            $this->isActive ? 1 : 0,
            $this->isAdmin ? 1 : 0,
            $this->avatarPath,
            $this->id
        ]);
        
        // Инвалидируем кэш пользователя
        if ($result && class_exists('OGAS\Core\Cache')) {
            \OGAS\Core\Cache::delete("user:id:{$this->id}");
        }
        
        return $result;
    }
    
    /**
     * Проверить пароль
     */
    public function verifyPassword(string $password): bool
    {
        return password_verify($password, $this->passwordHash);
    }
    
    /**
     * Изменить пароль
     */
    public function changePassword(string $newPassword): bool
    {
        $this->passwordHash = password_hash($newPassword, PASSWORD_DEFAULT);
        $db = Database::getConnection();
        $stmt = $db->prepare("UPDATE users SET password_hash = ? WHERE id = ?");
        return $stmt->execute([$this->passwordHash, $this->id]);
    }
    
    /**
     * Изменить email (проверка на уникальность будет в сервисе)
     */
    public function changeEmail(string $newEmail): bool
    {
        $this->email = $newEmail;
        return $this->save();
    }
    
    /**
     * Удалить пользователя (с защитой системного пользователя)
     */
    public function delete(): bool
    {
        // Системный пользователь нельзя удалить
        if ($this->isSystem) {
            return false;
        }
        
        if (!$this->id) {
            return false;
        }
        
        $db = Database::getConnection();
        $stmt = $db->prepare("DELETE FROM users WHERE id = ? AND is_system = 0");
        return $stmt->execute([$this->id]);
    }
    
    // Getters
    public function getId(): ?int { return $this->id; }
    public function getEmail(): string { return $this->email; }
    public function getUserType(): string { return $this->userType; }
    public function getFullName(): string { return $this->fullName; }
    public function isActive(): bool { return $this->isActive; }
    public function isSystem(): bool { return $this->isSystem; }
    public function isAdmin(): bool { return $this->isAdmin; }
    public function getCreatedAt(): ?string { return $this->createdAt; }
    public function getUpdatedAt(): ?string { return $this->updatedAt; }
    public function getAvatarPath(): ?string { return $this->avatarPath; }
    
    // Setters
    public function setEmail(string $email): void { $this->email = $email; }
    public function setFullName(string $fullName): void { $this->fullName = $fullName; }
    public function setUserType(string $userType): void { $this->userType = $userType; }
    public function setActive(bool $active): void { $this->isActive = $active; }
    public function setIsSystem(bool $isSystem): void { $this->isSystem = $isSystem; }
    public function setIsAdmin(bool $isAdmin): void { $this->isAdmin = $isAdmin; }
    public function setAvatarPath(?string $avatarPath): void { $this->avatarPath = $avatarPath; }
    
    /**
     * Получить первую букву имени для инициалов
     */
    public function getInitials(): string
    {
        $fullName = trim($this->fullName);
        if (empty($fullName)) {
            return '?';
        }
        return mb_strtoupper(mb_substr($fullName, 0, 1, 'UTF-8'), 'UTF-8');
    }
    
    /**
     * Получить URL аватара
     */
    public function getAvatarUrl(): string
    {
        if (empty($this->avatarPath)) {
            return '';
        }
        
        $path = trim($this->avatarPath);
        $path = ltrim($path, '/');
        
        if (empty($path)) {
            return '';
        }
        
        // Формируем полный путь к файлу
        $fullPath = __DIR__ . '/../../' . $path;
        
        // Проверяем существование файла
        if (file_exists($fullPath) && is_file($fullPath) && is_readable($fullPath)) {
            // Возвращаем путь с ведущим слэшем
            return '/' . $path;
        }
        
        return '';
    }
    
    /**
     * Получить HTML аватара
     */
    public function getAvatarHtml(string $size = 'medium', string $additionalClass = ''): string
    {
        $avatarUrl = $this->getAvatarUrl();
        $initials = $this->getInitials();
        $fullName = htmlspecialchars($this->fullName);
        
        $sizeClasses = [
            'small' => ['div' => 'sidebar-user-avatar', 'img' => 'sidebar-user-avatar-image'],
            'medium' => ['div' => 'user-avatar-large', 'img' => 'user-avatar-large'],
            'large' => ['div' => 'avatar-preview-placeholder', 'img' => 'avatar-preview-image'],
            'dashboard' => ['div' => 'dashboard-user-avatar', 'img' => 'dashboard-user-avatar'],
            'table' => ['div' => 'table-user-avatar', 'img' => 'table-user-avatar'],
            'profile' => ['div' => 'profile-avatar-large', 'img' => 'profile-avatar-large']
        ];
        
        $classes = $sizeClasses[$size] ?? $sizeClasses['medium'];
        $divClass = trim($classes['div'] . ' ' . $additionalClass);
        $imgClass = trim($classes['img'] . ' ' . $additionalClass);
        
        if (!empty($avatarUrl)) {
            return sprintf(
                '<img src="%s" alt="%s" class="%s" title="%s" loading="lazy">',
                htmlspecialchars($avatarUrl),
                $fullName,
                $imgClass,
                $fullName
            );
        } else {
            return sprintf(
                '<div class="%s" title="%s">%s</div>',
                $divClass,
                $fullName,
                htmlspecialchars($initials)
            );
        }
    }
    
    /**
     * Изменить аватар
     */
    public function changeAvatar(?string $avatarPath): bool
    {
        if (!$this->id || $this->isSystem) {
            return false;
        }
        
        $this->avatarPath = $avatarPath;
        $db = Database::getConnection();
        $stmt = $db->prepare("UPDATE users SET avatar_path = ?, updated_at = NOW() WHERE id = ? AND is_system = 0");
        return $stmt->execute([$this->avatarPath, $this->id]) && $stmt->rowCount() > 0;
    }
    
    /**
     * Удалить аватар
     */
    public function removeAvatar(): bool
    {
        if (!$this->id || !$this->avatarPath) {
            return false;
        }
        
        $path = ltrim($this->avatarPath, '/');
        $fullPath = __DIR__ . '/../../' . $path;
        if (file_exists($fullPath)) {
            @unlink($fullPath);
        }
        
        return $this->changeAvatar(null);
    }
}

