<?php
/**
 * Точка входа в приложение ОГАС
 * Редирект на страницу входа или на рабочий кабинет, если пользователь авторизован
 */

require_once __DIR__ . '/../src/bootstrap.php';

use OGAS\Services\Auth;

// Если пользователь авторизован, перенаправляем на рабочий кабинет
if (Auth::check()) {
    header('Location: /dashboard.php');
    exit;
}

// Если не авторизован, перенаправляем на страницу входа
header('Location: /login.php');
exit;
