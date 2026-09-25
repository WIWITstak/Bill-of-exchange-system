<?php
/**
 * Страница сброса пароля по токену
 */

require_once __DIR__ . '/../src/bootstrap.php';

use OGAS\Services\Auth;
use OGAS\Models\PasswordReset;
use OGAS\Services\PasswordResetService;

// Если уже авторизован, редирект
if (Auth::check()) {
    header('Location: /dashboard.php');
    exit;
}

$error = '';
$success = '';
$token = $_GET['token'] ?? '';

// Проверяем токен
$passwordReset = null;
$isValidToken = false;

if (!empty($token)) {
    $passwordReset = PasswordReset::findByToken($token);
    $isValidToken = $passwordReset && $passwordReset->isValid();
    
    if (!$isValidToken) {
        if ($passwordReset && !$passwordReset->isValid()) {
            $error = 'Токен истёк или уже был использован';
        } else {
            $error = 'Неверный токен сброса пароля';
        }
    }
}

// Обработка сброса пароля
if ($_SERVER['REQUEST_METHOD'] === 'POST' && $isValidToken) {
    $token = $_POST['token'] ?? $_GET['token'] ?? '';
    $newPassword = $_POST['new_password'] ?? '';
    $confirmPassword = $_POST['confirm_password'] ?? '';
    
    if (empty($newPassword) || empty($confirmPassword)) {
        $error = 'Заполните все поля';
    } elseif (strlen($newPassword) < 6) {
        $error = 'Пароль должен быть не менее 6 символов';
    } elseif ($newPassword !== $confirmPassword) {
        $error = 'Пароли не совпадают';
    } else {
        try {
            if (PasswordResetService::resetPassword($token, $newPassword)) {
                $success = 'Пароль успешно изменён! Теперь вы можете войти с новым паролем.';
                $isValidToken = false; // Скрываем форму после успешного сброса
            } else {
                $error = 'Ошибка при сбросе пароля. Возможно, токен истёк.';
            }
        } catch (\Exception $e) {
            $error = 'Ошибка: ' . $e->getMessage();
        }
    }
}

$title = 'Сброс пароля';
ob_start();
?>
<div class="auth-container">
    <div class="auth-box">
        <h2>Сброс пароля</h2>
        
        <?php if ($error): ?>
            <div class="alert alert-error"><?= htmlspecialchars($error) ?></div>
        <?php endif; ?>
        
        <?php if ($success): ?>
            <div class="alert alert-success">
                <?= htmlspecialchars($success) ?>
                <p style="margin-top: 15px;">
                    <a href="/login.php" class="btn btn-primary">Войти в систему</a>
                </p>
            </div>
        <?php endif; ?>
        
        <?php if (empty($token)): ?>
            <div class="alert alert-error">
                Токен сброса пароля не указан. Пожалуйста, используйте ссылку из письма.
            </div>
            <p class="auth-link">
                <a href="/forgot-password.php">Запросить новый токен</a> | 
                <a href="/login.php">Войти</a>
            </p>
        <?php elseif ($isValidToken): ?>
            <p>Введите новый пароль для вашего аккаунта.</p>
            
            <?php if ($passwordReset): ?>
                <p style="font-size: 0.9em; color: #666;">
                    Токен действителен до: <?= date('d.m.Y H:i', strtotime($passwordReset->getExpiresAt())) ?>
                </p>
            <?php endif; ?>
            
            <form method="POST" action="">
                <?= csrf_field() ?>
                <input type="hidden" name="token" value="<?= htmlspecialchars($token) ?>">
                
                <div class="form-group">
                    <label for="new_password">Новый пароль:</label>
                    <input type="password" id="new_password" name="new_password" required 
                           minlength="6" placeholder="Минимум 6 символов">
                </div>
                
                <div class="form-group">
                    <label for="confirm_password">Подтвердите новый пароль:</label>
                    <input type="password" id="confirm_password" name="confirm_password" required 
                           minlength="6" placeholder="Повторите пароль">
                </div>
                
                <button type="submit" class="btn btn-primary">Сбросить пароль</button>
            </form>
            
            <p class="auth-link" style="margin-top: 20px;">
                <a href="/login.php">← Вернуться к входу</a>
            </p>
        <?php else: ?>
            <p class="auth-link">
                <a href="/forgot-password.php">Запросить новый токен сброса пароля</a> | 
                <a href="/login.php">Войти</a>
            </p>
        <?php endif; ?>
    </div>
</div>
<?php
$content = ob_get_clean();
include __DIR__ . '/../templates/base.php';

