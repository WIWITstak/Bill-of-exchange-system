<?php
/**
 * Страница регистрации
 */

require_once __DIR__ . '/../src/bootstrap.php';

use OGAS\Models\User;
use OGAS\Core\Session;
use OGAS\Services\Auth;
use OGAS\Services\RegistrationService;
use OGAS\Models\Transaction;
use OGAS\Core\Security;
use OGAS\Core\BotProtection;

$error = '';
$success = '';

// Если уже авторизован, редирект
if (Auth::check()) {
    header('Location: /dashboard.php');
    exit;
}

// Обработка формы
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    // Проверка на ботов
    // Проверка honeypot поля
    if (!BotProtection::checkHoneypot($_POST, 'website')) {
        $error = 'Обнаружена подозрительная активность. Попробуйте позже.';
    }
    
    // Проверка времени заполнения формы (минимум 3 секунды для регистрации)
    if (empty($error) && !BotProtection::checkFormTime('register', 3)) {
        $error = 'Форма заполнена слишком быстро. Попробуйте еще раз.';
    }
    
    // CSRF защита
    if (empty($error) && !Security::checkCsrfToken()) {
        $error = 'Ошибка безопасности. Обновите страницу и попробуйте снова.';
    }
    
    if (empty($error)) {
        $email = trim($_POST['email'] ?? '');
        $password = $_POST['password'] ?? '';
        $passwordConfirm = $_POST['password_confirm'] ?? '';
        $fullName = trim($_POST['full_name'] ?? '');
        $userType = $_POST['user_type'] ?? 'individual';
        
        // Валидация
        if (empty($email) || empty($password) || empty($fullName)) {
            $error = 'Заполните все обязательные поля';
        } elseif (!Security::validateEmail($email)) {
            $error = 'Некорректный email';
        } elseif ($password !== $passwordConfirm) {
            $error = 'Пароли не совпадают';
        } else {
            // Проверка сложности пароля
            $passwordValidation = Security::validatePasswordStrength($password);
            if (!$passwordValidation['valid']) {
                $error = implode('. ', $passwordValidation['errors']);
            }
        }
        
        if (empty($error)) {
            // Проверяем существование пользователя с обработкой ошибок
            try {
                if (User::findByEmail($email)) {
                    $error = 'Пользователь с таким email уже существует';
                }
            } catch (\PDOException $e) {
                $error = 'Ошибка базы данных при проверке email: ' . $e->getMessage();
            } catch (\RuntimeException $e) {
                $error = 'Ошибка подключения: ' . $e->getMessage();
            } catch (\Exception $e) {
                $error = 'Ошибка: ' . $e->getMessage();
            }
            
            // Если ошибки нет, создаём пользователя
            if (empty($error)) {
                try {
                    // Используем RegistrationService для создания пользователя и транзакции активации
                    $user = RegistrationService::register([
                        'email' => $email,
                        'password' => $password,
                        'full_name' => $fullName,
                        'user_type' => $userType
                    ]);
                    
                    // Находим транзакцию активации
                    $systemUser = User::getOrCreateSystemUser();
                    $activationTransactions = Transaction::findByUser($user->getId(), 'pending');
                    $activationTransaction = null;
                    
                    foreach ($activationTransactions as $t) {
                        if (($t->getSellerId() === $user->getId() && $t->getBuyerId() === $systemUser->getId()) ||
                            ($t->getBuyerId() === $user->getId() && $t->getSellerId() === $systemUser->getId())) {
                            $activationTransaction = $t;
                            break;
                        }
                    }
                    
                    // Авторизуем пользователя
                    Auth::login($email, $password);
                    
                    if ($activationTransaction) {
                        Session::flash('success', 'Регистрация успешна! Для активации аккаунта подтвердите сделку в чате.');
                        header('Location: /transactions/chat.php?id=' . $activationTransaction->getId());
                    } else {
                        Session::flash('success', 'Регистрация успешна! Для активации аккаунта перейдите в раздел активации.');
                        header('Location: /activate.php');
                    }
                    exit;
                } catch (\PDOException $e) {
                    $error = 'Ошибка базы данных при регистрации: ' . $e->getMessage();
                } catch (\RuntimeException $e) {
                    $error = 'Ошибка подключения: ' . $e->getMessage();
                } catch (\Exception $e) {
                    $error = 'Ошибка при регистрации: ' . $e->getMessage();
                }
            }
        }
    }
}

$title = 'Регистрация';
ob_start();
?>
<div class="auth-container">
    <div class="auth-box">
        <div class="auth-box-header">
            <div class="auth-logo">
                <i class="fas fa-user-plus"></i>
            </div>
            <h2>Регистрация в ОГАС</h2>
            <p class="auth-subtitle">Создайте новый аккаунт для работы в системе</p>
        </div>
        
        <?php if ($error): ?>
            <div class="alert alert-error">
                <i class="fas fa-exclamation-circle"></i>
                <?= htmlspecialchars($error) ?>
            </div>
        <?php endif; ?>
        
        <form method="POST" action="" class="auth-form" id="registerForm">
            <?= csrf_field() ?>
            <?php
            // Honeypot поле для защиты от ботов
            echo BotProtection::getHoneypotField('website', 'Website');
            // Устанавливаем время начала заполнения формы
            BotProtection::setFormStartTime('register');
            ?>
            <div class="form-group">
                <label for="user_type">
                    <i class="fas fa-user-tag"></i>
                    Тип пользователя
                </label>
                <select id="user_type" name="user_type" required class="form-select">
                    <option value="individual" <?= ($_POST['user_type'] ?? 'individual') === 'individual' ? 'selected' : '' ?>>Физическое лицо</option>
                    <option value="legal" <?= ($_POST['user_type'] ?? '') === 'legal' ? 'selected' : '' ?>>Юридическое лицо</option>
                </select>
            </div>
            
            <div class="form-group">
                <label for="full_name" id="full_name_label">
                    <i class="fas fa-user"></i>
                    <span id="full_name_text">ФИО</span>
                </label>
                <input type="text" id="full_name" name="full_name" required 
                       value="<?= htmlspecialchars($_POST['full_name'] ?? '') ?>"
                       placeholder="Введите ваше ФИО">
            </div>
            
            <div class="form-group">
                <label for="email">
                    <i class="fas fa-envelope"></i>
                    Email
                </label>
                <input type="email" id="email" name="email" required 
                       value="<?= htmlspecialchars($_POST['email'] ?? '') ?>"
                       placeholder="Введите ваш email">
            </div>
            
            <div class="form-group">
                <label for="password">
                    <i class="fas fa-lock"></i>
                    Пароль
                </label>
                <input type="password" id="password" name="password" required 
                       minlength="6"
                       placeholder="Минимум 6 символов">
                <small class="form-hint">Минимум 6 символов</small>
            </div>
            
            <div class="form-group">
                <label for="password_confirm">
                    <i class="fas fa-lock"></i>
                    Подтверждение пароля
                </label>
                <input type="password" id="password_confirm" name="password_confirm" required
                       placeholder="Повторите пароль">
            </div>
            
            <button type="submit" class="btn btn-primary btn-auth">
                <i class="fas fa-user-plus"></i>
                Зарегистрироваться
            </button>
        </form>
        
        <div class="auth-footer">
            <p class="auth-link">
                Уже есть аккаунт? <a href="/login.php">Войти</a>
            </p>
        </div>
    </div>
</div>

<script>
document.addEventListener('DOMContentLoaded', function() {
    const userTypeSelect = document.getElementById('user_type');
    const fullNameLabel = document.getElementById('full_name_label');
    const fullNameText = document.getElementById('full_name_text');
    const fullNameInput = document.getElementById('full_name');
    const fullNameIcon = fullNameLabel.querySelector('i');
    
    function updateLabel() {
        if (userTypeSelect.value === 'legal') {
            fullNameText.textContent = 'Название организации';
            fullNameIcon.className = 'fas fa-building';
            fullNameInput.placeholder = 'Введите название организации';
        } else {
            fullNameText.textContent = 'ФИО';
            fullNameIcon.className = 'fas fa-user';
            fullNameInput.placeholder = 'Введите ваше ФИО';
        }
    }
    
    userTypeSelect.addEventListener('change', updateLabel);
    updateLabel();
});
</script>
<?php
$content = ob_get_clean();
include __DIR__ . '/../templates/base.php';

