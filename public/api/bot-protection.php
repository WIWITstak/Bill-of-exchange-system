<?php
/**
 * API для защиты от ботов
 * Устанавливает флаг выполнения JavaScript
 */

require_once __DIR__ . '/../../src/bootstrap.php';

use OGAS\Core\BotProtection;
use OGAS\Core\Session;

header('Content-Type: application/json; charset=utf-8');

$action = $_GET['action'] ?? '';

switch ($action) {
    case 'set_js_enabled':
        BotProtection::setJavaScriptEnabled();
        echo json_encode(['success' => true, 'message' => 'JavaScript enabled flag set']);
        break;
        
    case 'get_token':
        $token = BotProtection::generateJsToken();
        echo json_encode(['success' => true, 'token' => $token]);
        break;
        
    case 'verify_token':
        $token = $_GET['token'] ?? '';
        $isValid = BotProtection::verifyJsToken($token);
        echo json_encode(['success' => $isValid, 'valid' => $isValid]);
        break;
        
    default:
        http_response_code(400);
        echo json_encode(['success' => false, 'message' => 'Invalid action']);
        break;
}



