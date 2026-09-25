<?php
/**
 * Выход из системы
 */

require_once __DIR__ . '/../src/bootstrap.php';

use OGAS\Services\Auth;
Auth::logout();

header('Location: /login.php');
exit;

