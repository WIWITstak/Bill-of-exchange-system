<?php
/**
 * Главная страница для поддомена админки
 * Показывает логин, если не авторизован, или админку, если авторизован
 */

require_once __DIR__ . '/../src/bootstrap.php';

use OGAS\Services\Auth;
use OGAS\Services\AdminService;

// Если авторизован и является админом - показываем админку
if (Auth::check() && AdminService::check()) {
    // Перенаправляем в админку (абсолютный URL, чтобы избежать циклов)
    $protocol = (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off') ? 'https' : 'http';
    $host = $_SERVER['HTTP_HOST'];
    header('Location: ' . $protocol . '://' . $host . '/admin/index.php');
    exit;
}

// Если не авторизован или не админ - показываем логин
// Перенаправляем на логин (абсолютный URL, чтобы избежать циклов)
$protocol = (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off') ? 'https' : 'http';
$host = $_SERVER['HTTP_HOST'];
header('Location: ' . $protocol . '://' . $host . '/admin-login.php');
exit;

