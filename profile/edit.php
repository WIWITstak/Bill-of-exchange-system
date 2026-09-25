<?php
/**
 * Страница редактирования профиля
 */

require_once __DIR__ . '/../../src/bootstrap.php';

use OGAS\Services\Auth;
use OGAS\Models\User;
use OGAS\Models\Company;
use OGAS\Core\Session;

Auth::requireAuth();
$user = Auth::user();

if (!$user) {
    Auth::logout();
    header('Location: /login.php');
    exit;
}

$error = '';
$success = '';

// Обработка изменения профиля
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $action = $_POST['action'] ?? '';
    
    try {
        switch ($action) {
            case 'update_profile':
                // Изменение ФИО/названия
                $fullName = trim($_POST['full_name'] ?? '');
                if (empty($fullName)) {
                    $error = 'Поле ФИО/название не может быть пустым';
                } else {
                    $user->setFullName($fullName);
                    if ($user->save()) {
                        // Обновляем данные в сессии
                        Session::set('user_name', $fullName);
                        $success = 'Профиль успешно обновлён!';
                    } else {
                        $error = 'Ошибка при сохранении профиля';
                    }
                }
                break;
                
            case 'change_email':
                // Изменение email
                $newEmail = trim($_POST['new_email'] ?? '');
                $confirmEmail = trim($_POST['confirm_email'] ?? '');
                $currentPassword = $_POST['current_password'] ?? '';
                
                if (empty($newEmail) || empty($confirmEmail)) {
                    $error = 'Заполните все поля';
                } elseif (!filter_var($newEmail, FILTER_VALIDATE_EMAIL)) {
                    $error = 'Некорректный email';
                } elseif ($newEmail !== $confirmEmail) {
                    $error = 'Email не совпадают';
                } elseif ($newEmail === $user->getEmail()) {
                    $error = 'Новый email совпадает с текущим';
                } elseif (!$user->verifyPassword($currentPassword)) {
                    $error = 'Неверный текущий пароль';
                } else {
                    // Проверяем, не занят ли email другим пользователем
                    $existingUser = User::findByEmail($newEmail);
                    if ($existingUser && $existingUser->getId() !== $user->getId()) {
                        $error = 'Пользователь с таким email уже существует';
                    } else {
                        if ($user->changeEmail($newEmail)) {
                            Session::set('user_email', $newEmail);
                            $success = 'Email успешно изменён!';
                        } else {
                            $error = 'Ошибка при изменении email';
                        }
                    }
                }
                break;
                
            case 'change_password':
                // Изменение пароля
                $currentPassword = $_POST['current_password'] ?? '';
                $newPassword = $_POST['new_password'] ?? '';
                $confirmPassword = $_POST['confirm_password'] ?? '';
                
                if (empty($currentPassword) || empty($newPassword) || empty($confirmPassword)) {
                    $error = 'Заполните все поля';
                } elseif (!$user->verifyPassword($currentPassword)) {
                    $error = 'Неверный текущий пароль';
                } elseif (strlen($newPassword) < 6) {
                    $error = 'Новый пароль должен быть не менее 6 символов';
                } elseif ($newPassword !== $confirmPassword) {
                    $error = 'Пароли не совпадают';
                } else {
                    if ($user->changePassword($newPassword)) {
                        $success = 'Пароль успешно изменён!';
                    } else {
                        $error = 'Ошибка при изменении пароля';
                    }
                }
                break;
                
            case 'update_company':
                // Обновление данных предприятия (для юрлиц)
                if ($user->getUserType() !== 'legal') {
                    $error = 'Эта функция доступна только для юридических лиц';
                } else {
                    $company = Company::findByUserId($user->getId());
                    if (!$company) {
                        // Создаём предприятие, если его нет
                        $company = Company::create([
                            'user_id' => $user->getId(),
                            'name' => $user->getFullName(),
                            'address' => trim($_POST['address'] ?? ''),
                            'okved_code' => trim($_POST['okved_code'] ?? ''),
                            'employee_count' => (int)($_POST['employee_count'] ?? 0)
                        ]);
                    } else {
                        // Обновляем существующее предприятие
                        $company->setAddress(trim($_POST['address'] ?? ''));
                        $company->setOkvedCode(trim($_POST['okved_code'] ?? ''));
                        $company->setEmployeeCount((int)($_POST['employee_count'] ?? 0));
                        $company->save();
                    }
                    $success = 'Данные предприятия успешно обновлены!';
                }
                break;
        }
    } catch (\Exception $e) {
        $error = 'Ошибка: ' . $e->getMessage();
    }
}

// Получаем данные предприятия для юрлиц
$company = null;
if ($user->getUserType() === 'legal') {
    $company = Company::findByUserId($user->getId());
}

$title = 'Редактирование профиля';
ob_start();
?>
<div class="dashboard">
    <div class="dashboard-header">
        <h2>Редактирование профиля</h2>
        <a href="/dashboard.php" class="btn btn-secondary">← Назад</a>
    </div>
    
    <?php if ($error): ?>
        <div class="alert alert-error"><?= htmlspecialchars($error) ?></div>
    <?php endif; ?>
    
    <?php if ($success): ?>
        <div class="alert alert-success"><?= htmlspecialchars($success) ?></div>
    <?php endif; ?>
    
    <div class="profile-edit-modern">
        <!-- Табы -->
        <div class="profile-tabs">
            <button class="profile-tab-btn active" data-tab="profile">
                <span class="tab-icon">👤</span>
                <span>Основная информация</span>
            </button>
            <button class="profile-tab-btn" data-tab="security">
                <span class="tab-icon">🔒</span>
                <span>Безопасность</span>
            </button>
            <?php if ($user->getUserType() === 'legal'): ?>
            <button class="profile-tab-btn" data-tab="company">
                <span class="tab-icon">🏢</span>
                <span>Данные предприятия</span>
            </button>
            <?php endif; ?>
        </div>
        
        <!-- Таб: Основная информация -->
        <div id="tab-profile" class="profile-tab-content active">
            <div class="profile-edit-card">
                <div class="profile-edit-header">
                    <h3>Основная информация</h3>
                    <p class="text-muted">Измените ваши основные данные</p>
                </div>
                
                <!-- Загрузка фото профиля -->
                <div class="profile-avatar-upload-section">
                    <div class="profile-avatar-preview">
                        <?php 
                        $avatarUrl = $user->getAvatarUrl();
                        $initials = $user->getInitials();
                        if ($avatarUrl): ?>
                            <img src="<?= htmlspecialchars($avatarUrl) ?>" alt="Фото профиля" id="avatar-preview" class="avatar-preview-image" title="<?= htmlspecialchars($user->getFullName()) ?>">
                        <?php else: ?>
                            <div class="avatar-preview-placeholder" id="avatar-preview" title="<?= htmlspecialchars($user->getFullName()) ?>"><?= htmlspecialchars($initials) ?></div>
                        <?php endif; ?>
                    </div>
                    <div class="profile-avatar-upload-controls">
                        <label for="avatar-upload" class="btn btn-secondary btn-upload-avatar">
                            <span>📷</span>
                            <span>Загрузить фото</span>
                        </label>
                        <input type="file" id="avatar-upload" name="avatar" accept="image/jpeg,image/jpg,image/png,image/gif,image/webp" style="display: none;">
                        <button type="button" class="btn btn-danger btn-remove-avatar" id="remove-avatar-btn" style="<?= $avatarUrl ? '' : 'display: none;' ?>">
                            <span>🗑️</span>
                            <span>Удалить фото</span>
                        </button>
                        <small class="form-hint">JPG, PNG, GIF или WEBP, максимум 5MB</small>
                    </div>
                </div>
                
                <form class="profile-edit-form" method="POST" action="/api/profile_edit.php">
                    <?= csrf_field() ?>
                    <input type="hidden" name="action" value="update_profile">
                    
                    <div class="form-group-modern">
                        <label for="full_name">
                            <span class="label-icon">📝</span>
                            <?= $user->getUserType() === 'legal' ? 'Название организации:' : 'ФИО:' ?>
                        </label>
                        <input type="text" id="full_name" name="full_name" required
                               value="<?= htmlspecialchars($user->getFullName()) ?>"
                               placeholder="<?= $user->getUserType() === 'legal' ? 'Введите название организации' : 'Введите ФИО' ?>"
                               class="form-input-modern">
                        <small class="form-hint">Минимум 2 символа</small>
                    </div>
                    
                    <div class="form-group-modern">
                        <label>
                            <span class="label-icon">📧</span>
                            Email:
                        </label>
                        <input type="text" value="<?= htmlspecialchars($user->getEmail()) ?>" 
                               disabled class="form-input-modern form-input-disabled">
                        <small class="form-hint">Для изменения email используйте вкладку "Безопасность"</small>
                    </div>
                    
                    <div class="form-group-modern">
                        <label>
                            <span class="label-icon"><?= $user->getUserType() === 'legal' ? '🏢' : '👤' ?></span>
                            Тип пользователя:
                        </label>
                        <input type="text" 
                               value="<?= $user->getUserType() === 'legal' ? 'Юридическое лицо' : 'Физическое лицо' ?>" 
                               disabled class="form-input-modern form-input-disabled">
                        <small class="form-hint">Тип пользователя нельзя изменить</small>
                    </div>
                    
                    <div class="form-actions-modern">
                        <button type="submit" class="btn btn-primary">
                            <span>💾</span>
                            <span>Сохранить изменения</span>
                        </button>
                    </div>
                </form>
            </div>
        </div>
        
        <!-- Таб: Безопасность -->
        <div id="tab-security" class="profile-tab-content">
            <!-- Изменение email -->
            <div class="profile-edit-card">
                <div class="profile-edit-header">
                    <h3>Изменение email</h3>
                    <p class="text-muted">Измените адрес электронной почты</p>
                </div>
                
                <form class="profile-edit-form" method="POST" action="/api/profile_edit.php">
                    <?= csrf_field() ?>
                    <input type="hidden" name="action" value="change_email">
                    
                    <div class="form-group-modern">
                        <label for="current_email_display">Текущий email:</label>
                        <input type="text" id="current_email_display" 
                               value="<?= htmlspecialchars($user->getEmail()) ?>" 
                               disabled class="form-input-modern form-input-disabled">
                    </div>
                    
                    <div class="form-group-modern">
                        <label for="new_email">
                            <span class="label-icon">📧</span>
                            Новый email:
                        </label>
                        <input type="email" id="new_email" name="new_email" required
                               value="<?= htmlspecialchars($_POST['new_email'] ?? '') ?>"
                               placeholder="example@domain.com"
                               class="form-input-modern">
                        <small class="form-hint">Введите новый адрес электронной почты</small>
                    </div>
                    
                    <div class="form-group-modern">
                        <label for="confirm_email">
                            <span class="label-icon">✓</span>
                            Подтвердите email:
                        </label>
                        <input type="email" id="confirm_email" name="confirm_email" required
                               placeholder="Повторите новый email"
                               class="form-input-modern">
                    </div>
                    
                    <div class="form-group-modern">
                        <label for="current_password_email">
                            <span class="label-icon">🔑</span>
                            Текущий пароль:
                        </label>
                        <input type="password" id="current_password_email" name="current_password" required
                               placeholder="Введите текущий пароль"
                               class="form-input-modern">
                        <small class="form-hint">Требуется для подтверждения изменения email</small>
                    </div>
                    
                    <div class="form-actions-modern">
                        <button type="submit" class="btn btn-primary">
                            <span>✏️</span>
                            <span>Изменить email</span>
                        </button>
                    </div>
                </form>
            </div>
            
            <!-- Изменение пароля -->
            <div class="profile-edit-card">
                <div class="profile-edit-header">
                    <h3>Изменение пароля</h3>
                    <p class="text-muted">Измените пароль для защиты вашего аккаунта</p>
                </div>
                
                <form class="profile-edit-form" method="POST" action="/api/profile_edit.php">
                    <?= csrf_field() ?>
                    <input type="hidden" name="action" value="change_password">
                    
                    <div class="form-group-modern">
                        <label for="current_password">
                            <span class="label-icon">🔑</span>
                            Текущий пароль:
                        </label>
                        <input type="password" id="current_password" name="current_password" required
                               placeholder="Введите текущий пароль"
                               class="form-input-modern">
                    </div>
                    
                    <div class="form-group-modern">
                        <label for="new_password">
                            <span class="label-icon">🔒</span>
                            Новый пароль:
                        </label>
                        <input type="password" id="new_password" name="new_password" required minlength="6"
                               placeholder="Минимум 6 символов"
                               class="form-input-modern">
                        <small class="form-hint">Минимум 6 символов</small>
                    </div>
                    
                    <div class="form-group-modern">
                        <label for="confirm_password">
                            <span class="label-icon">✓</span>
                            Подтвердите новый пароль:
                        </label>
                        <input type="password" id="confirm_password" name="confirm_password" required minlength="6"
                               placeholder="Повторите новый пароль"
                               class="form-input-modern">
                    </div>
                    
                    <div class="form-actions-modern">
                        <button type="submit" class="btn btn-primary">
                            <span>🔐</span>
                            <span>Изменить пароль</span>
                        </button>
                    </div>
                </form>
            </div>
            
            <!-- Активные сессии -->
            <div class="profile-edit-card">
                <div class="profile-edit-header">
                    <h3>Активные сессии</h3>
                    <p class="text-muted">Управление активными сессиями вашего аккаунта</p>
                </div>
                
                <?php
                use OGAS\Models\UserSession;
                use OGAS\Core\Session as SessionCore;
                
                $userSessions = UserSession::findByUserId($user->getId(), false);
                $currentSessionId = session_id();
                ?>
                
                <div class="sessions-info">
                    <p><strong>Активных сессий:</strong> <?= count(array_filter($userSessions, fn($s) => $s->isCurrent())) ?></p>
                    <p><strong>Максимум сессий:</strong> <?= SessionCore::getMaxSessionsPerUser() ?></p>
                    <p><strong>Таймаут неактивности:</strong> <?= SessionCore::getInactivityTimeout() / 60 ?> минут</p>
                </div>
                
                <?php if (empty($userSessions)): ?>
                    <p class="text-muted">Активных сессий не найдено</p>
                <?php else: ?>
                    <div class="sessions-list">
                        <table class="sessions-table-profile">
                            <thead>
                                <tr>
                                    <th>IP адрес</th>
                                    <th>Устройство</th>
                                    <th>Последняя активность</th>
                                    <th>Текущая</th>
                                    <th>Действия</th>
                                </tr>
                            </thead>
                            <tbody>
                                <?php foreach ($userSessions as $session): ?>
                                    <tr class="<?= $session->getSessionId() === $currentSessionId ? 'current-session-row' : '' ?>">
                                        <td><?= htmlspecialchars($session->getIpAddress()) ?></td>
                                        <td><?= htmlspecialchars($session->getDeviceInfo() ?? 'Unknown') ?></td>
                                        <td><?= htmlspecialchars($session->getLastActivity()) ?></td>
                                        <td>
                                            <?php if ($session->getSessionId() === $currentSessionId): ?>
                                                <span class="badge badge-success">Текущая</span>
                                            <?php else: ?>
                                                <span class="badge badge-secondary">—</span>
                                            <?php endif; ?>
                                        </td>
                                        <td>
                                            <?php if ($session->getSessionId() !== $currentSessionId): ?>
                                                <form method="POST" action="/api/profile_edit.php" style="display: inline;" onsubmit="return confirm('Завершить эту сессию?')">
                                                    <?= csrf_field() ?>
                                                    <input type="hidden" name="action" value="terminate_session">
                                                    <input type="hidden" name="session_id" value="<?= htmlspecialchars($session->getSessionId()) ?>">
                                                    <button type="submit" class="btn btn-danger btn-small">Завершить</button>
                                                </form>
                                            <?php else: ?>
                                                <span class="text-muted">—</span>
                                            <?php endif; ?>
                                        </td>
                                    </tr>
                                <?php endforeach; ?>
                            </tbody>
                        </table>
                    </div>
                <?php endif; ?>
            </div>
        </div>
        
        <!-- Таб: Данные предприятия (для юрлиц) -->
        <?php if ($user->getUserType() === 'legal'): ?>
        <div id="tab-company" class="profile-tab-content">
            <div class="profile-edit-card">
                <div class="profile-edit-header">
                    <h3>Данные предприятия</h3>
                    <p class="text-muted">Обновите информацию о вашем предприятии</p>
                </div>
                
                <form class="profile-edit-form" method="POST" action="/api/profile_edit.php">
                    <?= csrf_field() ?>
                    <input type="hidden" name="action" value="update_company">
                    
                    <div class="form-group-modern">
                        <label>
                            <span class="label-icon">🏢</span>
                            Название организации:
                        </label>
                        <input type="text" value="<?= htmlspecialchars($user->getFullName()) ?>" 
                               disabled class="form-input-modern form-input-disabled">
                        <small class="form-hint">Измените название в разделе "Основная информация"</small>
                    </div>
                    
                    <div class="form-group-modern">
                        <label for="address">
                            <span class="label-icon">📍</span>
                            Адрес:
                        </label>
                        <textarea id="address" name="address" rows="3"
                                  placeholder="Введите полный адрес предприятия"
                                  class="form-textarea-modern"><?= htmlspecialchars($company ? $company->getAddress() : '') ?></textarea>
                        <small class="form-hint">Юридический или фактический адрес</small>
                    </div>
                    
                    <div class="form-row">
                        <div class="form-group-modern">
                            <label for="okved_code">
                                <span class="label-icon">📋</span>
                                Код ОКВЭД:
                            </label>
                            <input type="text" id="okved_code" name="okved_code"
                                   value="<?= htmlspecialchars($company ? $company->getOkvedCode() : '') ?>"
                                   placeholder="Например: 62.01"
                                   class="form-input-modern"
                                   pattern="\d{2}\.\d{2}(\.\d{2})?">
                            <small class="form-hint">Формат: XX.XX или XX.XX.XX</small>
                        </div>
                        
                        <div class="form-group-modern">
                            <label for="employee_count">
                                <span class="label-icon">👥</span>
                                Количество сотрудников:
                            </label>
                            <input type="number" id="employee_count" name="employee_count" min="0"
                                   value="<?= $company ? $company->getEmployeeCount() : 0 ?>"
                                   placeholder="0"
                                   class="form-input-modern">
                            <small class="form-hint">Общее количество сотрудников</small>
                        </div>
                    </div>
                    
                    <div class="form-actions-modern">
                        <button type="submit" class="btn btn-primary">
                            <span>💾</span>
                            <span>Сохранить данные предприятия</span>
                        </button>
                    </div>
                </form>
            </div>
        </div>
        <?php endif; ?>
    </div>
    
    <style>
    .sessions-info {
        background: #f9f9f9;
        padding: 15px;
        border-radius: 8px;
        margin-bottom: 20px;
    }
    
    .sessions-info p {
        margin: 5px 0;
        color: #666;
    }
    
    .sessions-list {
        margin-top: 20px;
    }
    
    .sessions-table-profile {
        width: 100%;
        border-collapse: collapse;
        font-size: 0.9em;
    }
    
    .sessions-table-profile thead {
        background: #f9f9f9;
    }
    
    .sessions-table-profile th {
        padding: 10px;
        text-align: left;
        font-weight: 600;
        border-bottom: 2px solid #ddd;
    }
    
    .sessions-table-profile td {
        padding: 10px;
        border-bottom: 1px solid #eee;
    }
    
    .current-session-row {
        background: #f0f9ff;
    }
    
    .sessions-table-profile .badge {
        display: inline-block;
        padding: 4px 8px;
        border-radius: 4px;
        font-size: 0.8em;
        font-weight: 600;
    }
    
    .badge-success {
        background: #d1fae5;
        color: #065f46;
    }
    
    .badge-secondary {
        background: #e5e7eb;
        color: #374151;
    }
    </style>
    
    <script src="/js/profile-edit.js"></script>
</div>
<?php
$content = ob_get_clean();
include __DIR__ . '/../../templates/base.php';





