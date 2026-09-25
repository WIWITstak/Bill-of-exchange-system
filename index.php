<?php
/**
 * Точка входа в корне проекта
 * Редирект обрабатывается через .htaccess -> public/index.php
 * Этот файл нужен только для обратной совместимости
 */

// Прямой редирект на login.php или dashboard.php, чтобы избежать циклических редиректов
// Сначала проверяем авторизацию
require_once __DIR__ . '/src/bootstrap.php';

use OGAS\Services\Auth;

if (Auth::check()) {
    header('Location: /dashboard.php', true, 301);
} else {
    header('Location: /login.php', true, 301);
}
exit;

