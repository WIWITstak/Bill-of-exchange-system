<?php
/**
 * API для получения метрик сервера (только для администраторов)
 */

// Включаем буферизацию вывода ПЕРЕД всеми операциями
ob_start();

// Отключаем вывод ошибок, чтобы не испортить JSON
error_reporting(E_ALL);
ini_set('display_errors', 0);
ini_set('log_errors', 1);

// Устанавливаем обработчик фатальных ошибок
register_shutdown_function(function() {
    $error = error_get_last();
    if ($error !== NULL && in_array($error['type'], [E_ERROR, E_CORE_ERROR, E_COMPILE_ERROR, E_PARSE])) {
        ob_clean();
        header('Content-Type: application/json; charset=utf-8');
        http_response_code(500);
        echo json_encode([
            'success' => false,
            'error' => 'Fatal error occurred',
            'message' => 'Произошла критическая ошибка на сервере',
            'details' => $error['message'] . ' in ' . $error['file'] . ' on line ' . $error['line']
        ], JSON_UNESCAPED_UNICODE);
        ob_end_flush();
        exit;
    }
});

try {
    require_once __DIR__ . '/../../src/bootstrap.php';
} catch (\Throwable $e) {
    ob_clean();
    header('Content-Type: application/json; charset=utf-8');
    http_response_code(500);
    echo json_encode([
        'success' => false,
        'error' => 'Bootstrap error',
        'message' => 'Ошибка при инициализации: ' . $e->getMessage()
    ], JSON_UNESCAPED_UNICODE);
    ob_end_flush();
    exit;
}

// Очищаем все возможные выводы от bootstrap
ob_clean();

use OGAS\Services\Auth;
use OGAS\Services\AdminService;
use OGAS\Services\ServerMonitoringService;
use OGAS\Core\Security;
use OGAS\Core\RateLimiter;

// Устанавливаем заголовок JSON до любых выводов
header('Content-Type: application/json; charset=utf-8');

// Rate limiting (60 запросов в минуту)
try {
    $clientIp = Security::getClientIp();
    RateLimiter::requireLimit($clientIp, 60, 60);
} catch (\Throwable $e) {
    error_log('RateLimiter error: ' . $e->getMessage());
    // Продолжаем выполнение, даже если rate limiter не работает
}

// Проверяем авторизацию
if (!Auth::check()) {
    ob_clean();
    http_response_code(401);
    echo json_encode([
        'success' => false,
        'error' => 'Authentication required',
        'message' => 'Требуется авторизация'
    ], JSON_UNESCAPED_UNICODE);
    ob_end_flush();
    exit;
}

// Проверяем права администратора
if (!AdminService::check()) {
    ob_clean();
    http_response_code(403);
    echo json_encode([
        'success' => false,
        'error' => 'Access denied. Admin rights required.'
    ], JSON_UNESCAPED_UNICODE);
    ob_end_flush();
    exit;
}

try {
    $metrics = ServerMonitoringService::getMetrics();
    
    // Логируем ошибки, если метрики пустые
    if (($metrics['cpu']['usage_percent'] ?? 0) == 0 && 
        ($metrics['memory']['total_bytes'] ?? 0) == 0) {
        error_log('ServerMonitoringService: All metrics are zero. Debug info: ' . json_encode($metrics['debug'] ?? []));
    }
    
    ob_clean();
    echo json_encode([
        'success' => true,
        'data' => $metrics
    ], JSON_UNESCAPED_UNICODE);
    ob_end_flush();
    
} catch (\Exception $e) {
    error_log('ServerMonitoringService error: ' . $e->getMessage());
    error_log('Stack trace: ' . $e->getTraceAsString());
    
    ob_clean();
    http_response_code(500);
    echo json_encode([
        'success' => false,
        'error' => $e->getMessage(),
        'message' => 'Ошибка при получении метрик сервера'
    ], JSON_UNESCAPED_UNICODE);
    ob_end_flush();
} catch (\Throwable $e) {
    error_log('ServerMonitoringService fatal error: ' . $e->getMessage());
    
    ob_clean();
    http_response_code(500);
    echo json_encode([
        'success' => false,
        'error' => 'Fatal error occurred',
        'message' => 'Произошла критическая ошибка'
    ], JSON_UNESCAPED_UNICODE);
    ob_end_flush();
}

