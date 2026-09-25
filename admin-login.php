<?php
/**
 * Страница авторизации для админ-панели
 */

require_once __DIR__ . '/../src/bootstrap.php';

use OGAS\Services\Auth;
use OGAS\Services\AdminService;
use OGAS\Core\Session;
use OGAS\Core\Security;
use OGAS\Core\RateLimiter;
use OGAS\Core\BruteForceProtection;
use OGAS\Core\SecurityLogger;
use OGAS\Core\BotProtection;

$error = '';
$success = Session::getFlash('success');

// Если уже авторизован и является админом, редирект в админку
if (Auth::check() && AdminService::check()) {
    $redirectUrl = Security::getSafeRedirectUrl('redirect', '/admin/index.php');
    header('Location: ' . $redirectUrl);
    exit;
}

// Если авторизован, но не админ - показываем ошибку
if (Auth::check() && !AdminService::check()) {
    $error = 'У вас нет прав доступа к админ-панели';
    Auth::logout(); // Выходим, чтобы можно было войти другим аккаунтом
}

// Rate limiting для страницы логина (строже чем обычно)
$clientIp = Security::getClientIp();
RateLimiter::requireLimit($clientIp, 5, 60); // 5 попыток в минуту

// Обработка формы
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    // Проверка на ботов
    // Проверка honeypot поля
    if (!BotProtection::checkHoneypot($_POST, 'website')) {
        $error = 'Обнаружена подозрительная активность. Попробуйте позже.';
        SecurityLogger::log('bot_detected_admin_login', [
            'ip' => Security::getClientIp(),
            'user_agent' => $_SERVER['HTTP_USER_AGENT'] ?? 'Unknown'
        ], 'warning');
    }
    
    // Проверка времени заполнения формы (минимум 2 секунды)
    if (empty($error) && !BotProtection::checkFormTime('admin_login', 2)) {
        $error = 'Форма заполнена слишком быстро. Попробуйте еще раз.';
    }
    
    // CSRF защита
    if (empty($error) && !Security::checkCsrfToken()) {
        $error = 'Ошибка безопасности. Обновите страницу и попробуйте снова.';
    }
    
    if (empty($error)) {
        $email = trim($_POST['email'] ?? '');
        $password = $_POST['password'] ?? '';
        
        // Проверка блокировки по IP
        if (!BruteForceProtection::checkAndBlock($clientIp, 'ip')) {
            $unlockTime = BruteForceProtection::getUnlockTime($clientIp, 'ip');
            $minutes = ceil($unlockTime / 60);
            $error = "Превышено количество попыток входа. Попробуйте через {$minutes} минут.";
        }
        // Проверка блокировки по email (если указан)
        elseif (!empty($email) && !BruteForceProtection::checkAndBlock($email, 'email')) {
            $unlockTime = BruteForceProtection::getUnlockTime($email, 'email');
            $minutes = ceil($unlockTime / 60);
            $error = "Превышено количество попыток входа для этого email. Попробуйте через {$minutes} минут.";
        }
        elseif (empty($email) || empty($password)) {
            $error = 'Заполните все поля';
        } else {
            try {
                if (Auth::login($email, $password)) {
                    // Проверяем, является ли пользователь администратором
                    if (!AdminService::check()) {
                        Auth::logout();
                        $error = 'У вас нет прав доступа к админ-панели';
                        SecurityLogger::log('admin_access_denied', [
                            'email' => $email,
                            'ip' => Security::getClientIp()
                        ], 'warning');
                    } else {
                        // Очищаем попытки после успешного входа
                        BruteForceProtection::clearAttempts($clientIp, 'ip');
                        BruteForceProtection::clearAttempts($email, 'email');
                        
                        // Логируем успешный вход в админку
                        SecurityLogger::logLoginAttempt($email, true, 'Admin login');
                        SecurityLogger::logAdminAccess('admin_login', true);
                        
                        // Безопасный редирект после входа в админку
                        $redirectUrl = Security::getSafeRedirectUrl('redirect', '/admin/index.php');
                        header('Location: ' . $redirectUrl);
                        exit;
                    }
                } else {
                    // Регистрируем неудачную попытку
                    BruteForceProtection::recordFailedAttempt($clientIp, 'ip');
                    BruteForceProtection::recordFailedAttempt($email, 'email');
                    
                    // Логируем неудачную попытку
                    SecurityLogger::logLoginAttempt($email, false, 'Invalid credentials (admin login)');
                    
                    $remaining = BruteForceProtection::getRemainingAttempts($clientIp, 'ip');
                    if ($remaining > 0) {
                        $error = "Неверный email или пароль. Осталось попыток: {$remaining}";
                    } else {
                        $unlockTime = BruteForceProtection::getUnlockTime($clientIp, 'ip');
                        $minutes = ceil($unlockTime / 60);
                        $error = "Превышено количество попыток. Попробуйте через {$minutes} минут.";
                    }
                }
            } catch (\PDOException $e) {
                $error = 'Ошибка базы данных: ' . $e->getMessage();
            } catch (\RuntimeException $e) {
                $error = 'Ошибка подключения: ' . $e->getMessage();
            } catch (\Exception $e) {
                $error = 'Ошибка при входе: ' . $e->getMessage();
            }
        }
    }
}

$title = 'Вход в админ-панель';
ob_start();
?>
<div class="auth-container">
    <div class="auth-box">
        <div class="auth-box-header">
            <div class="auth-logo">
                <i class="fas fa-user-shield"></i>
            </div>
            <h2>Вход в админ-панель</h2>
            <p class="auth-subtitle">Только для администраторов</p>
        </div>
        
        <?php if ($error): ?>
            <div class="alert alert-error">
                <i class="fas fa-exclamation-circle"></i>
                <?= htmlspecialchars($error) ?>
            </div>
        <?php endif; ?>
        
        <?php if ($success): ?>
            <div class="alert alert-success">
                <i class="fas fa-check-circle"></i>
                <?= htmlspecialchars($success) ?>
            </div>
        <?php endif; ?>
        
        <form method="POST" action="" class="auth-form" id="adminLoginForm">
            <?= csrf_field() ?>
            <?php
            // Honeypot поле для защиты от ботов
            echo BotProtection::getHoneypotField('website', 'Website');
            // Устанавливаем время начала заполнения формы
            BotProtection::setFormStartTime('admin_login');
            ?>
            <div class="form-group">
                <label for="email">
                    <i class="fas fa-envelope"></i>
                    Email администратора
                </label>
                <input type="email" id="email" name="email" required 
                       value="<?= htmlspecialchars($_POST['email'] ?? '') ?>"
                       placeholder="Введите email администратора">
            </div>
            
            <div class="form-group">
                <label for="password">
                    <i class="fas fa-lock"></i>
                    Пароль
                </label>
                <input type="password" id="password" name="password" required
                       placeholder="Введите пароль">
            </div>
            
            <button type="submit" class="btn btn-primary btn-auth">
                <i class="fas fa-sign-in-alt"></i>
                Войти в админ-панель
            </button>
        </form>
        
        <div class="auth-footer">
            <p class="auth-link">
                <a href="/login.php">
                    <i class="fas fa-arrow-left"></i>
                    Обычный вход
                </a>
            </p>
        </div>
    </div>
</div>
<?php
$content = ob_get_clean();
include __DIR__ . '/../templates/base.php';


