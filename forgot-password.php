<?php
/**
 * Страница "Забыли пароль?"
 */

require_once __DIR__ . '/../src/bootstrap.php';

use OGAS\Services\Auth;
use OGAS\Services\PasswordResetService;
use OGAS\Core\Session;

// Если уже авторизован, редирект
if (Auth::check()) {
    header('Location: /dashboard.php');
    exit;
}

$error = '';
$success = '';
$token = null;
$resetUrl = null;

// Обработка запроса на сброс пароля
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $email = trim($_POST['email'] ?? '');
    
    if (empty($email)) {
        $error = 'Введите email';
    } elseif (!filter_var($email, FILTER_VALIDATE_EMAIL)) {
        $error = 'Некорректный email';
    } else {
        try {
            // Создаём токен сброса пароля
            $token = PasswordResetService::requestReset($email);
            
            if ($token) {
                // Генерируем URL для сброса пароля
                $resetUrl = PasswordResetService::getResetUrl($token);
                $success = 'Запрос на сброс пароля успешно создан!';
            } else {
                // Не говорим, что пользователь не найден (безопасность)
                $success = 'Если пользователь с таким email существует, инструкция по восстановлению пароля будет отправлена на указанный адрес.';
            }
        } catch (\Exception $e) {
            $error = 'Ошибка: ' . $e->getMessage();
        }
    }
}

$title = 'Восстановление пароля';
ob_start();
?>
<div class="auth-container">
    <div class="auth-box">
        <h2>Восстановление пароля</h2>
        
        <?php if ($error): ?>
            <div class="alert alert-error"><?= htmlspecialchars($error) ?></div>
        <?php endif; ?>
        
        <?php if ($success): ?>
            <div class="alert alert-success">
                <?= htmlspecialchars($success) ?>
                
                <?php if ($resetUrl): ?>
                    <div style="margin-top: 15px; padding: 15px; background: #f0f0f0; border-radius: 5px;">
                        <p><strong>Ссылка для сброса пароля:</strong></p>
                        <p style="word-break: break-all; font-size: 0.9em; background: white; padding: 10px; border-radius: 3px;">
                            <a href="<?= htmlspecialchars($resetUrl) ?>"><?= htmlspecialchars($resetUrl) ?></a>
                        </p>
                        <p style="font-size: 0.85em; color: #666; margin-top: 10px;">
                            <strong>Примечание:</strong> В демо-версии ссылка отображается здесь. 
                            В рабочей версии она будет отправлена на email. Ссылка действительна 24 часа.
                        </p>
                    </div>
                <?php endif; ?>
            </div>
        <?php endif; ?>
        
        <?php if (!$success): ?>
            <p>Введите email, который вы использовали при регистрации. Мы отправим вам ссылку для сброса пароля.</p>
            
            <form method="POST" action="">
                <?= csrf_field() ?>
                <div class="form-group">
                    <label for="email">Email:</label>
                    <input type="email" id="email" name="email" required 
                           value="<?= htmlspecialchars($_POST['email'] ?? '') ?>"
                           placeholder="your@email.com">
                </div>
                
                <button type="submit" class="btn btn-primary">Отправить ссылку для сброса</button>
            </form>
        <?php endif; ?>
        
        <p class="auth-link" style="margin-top: 20px;">
            <a href="/login.php">← Вернуться к входу</a>
        </p>
    </div>
</div>
<?php
$content = ob_get_clean();
include __DIR__ . '/../templates/base.php';

