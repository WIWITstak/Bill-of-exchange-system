<?php
/**
 * API для управления WebSocket сервером (только для администраторов)
 */

// Подключаем bootstrap ПЕРЕД всеми операциями
require_once __DIR__ . '/../../src/bootstrap.php';

// Импортируем классы (use должен быть в начале файла)
use OGAS\Core\Security;
use OGAS\Core\RateLimiter;
use OGAS\Services\Auth;
use OGAS\Services\AdminService;
use OGAS\Services\WebSocketService;
use OGAS\Services\WebSocketLoggingService;

// Включаем буферизацию вывода ПЕРЕД всеми операциями
ob_start();

// Увеличиваем время выполнения для операций запуска/остановки сервера
set_time_limit(30);

// Rate limiting (30 запросов в минуту для админских операций)
$clientIp = Security::getClientIp();
RateLimiter::requireLimit($clientIp, 30, 60);

// Отключаем вывод ошибок, чтобы не испортить JSON
error_reporting(E_ALL);
ini_set('display_errors', 0);
ini_set('log_errors', 1);

// Подавляем все возможные выводы ошибок
$originalErrorHandler = set_error_handler(function($errno, $errstr, $errfile, $errline) use (&$originalErrorHandler) {
    // Логируем ошибки, но не выводим их
    error_log("PHP Error ($errno): $errstr in $errfile on line $errline");
    return true; // Подавляем стандартный обработчик
});

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

// Очищаем все возможные выводы от bootstrap
ob_clean();

// Устанавливаем заголовок JSON до любых выводов
header('Content-Type: application/json; charset=utf-8');

// Проверяем авторизацию (без редиректа для API)
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
    // Получаем действие
    $action = $_GET['action'] ?? $_POST['action'] ?? '';
    
    if (empty($action)) {
        throw new \Exception('Action parameter is required');
    }

    $result = null;
    
    switch ($action) {
        case 'status':
            try {
                $status = WebSocketService::getStatus();
                $result = [
                    'success' => true,
                    'data' => $status
                ];
            } catch (\Throwable $e) {
                throw new \Exception('Error getting status: ' . $e->getMessage(), 0, $e);
            }
            break;
            
        case 'start':
            // Увеличиваем время выполнения для запуска сервера
            set_time_limit(10); // Уменьшаем до 10 секунд, так как запуск теперь асинхронный
            try {
                $background = isset($_GET['background']) && $_GET['background'] === 'true';
                $result = WebSocketService::start($background);
            } catch (\Throwable $e) {
                error_log('WebSocketService::start() error: ' . $e->getMessage());
                error_log('Stack trace: ' . $e->getTraceAsString());
                throw new \Exception('Error starting server: ' . $e->getMessage(), 0, $e);
            }
            break;
            
        case 'stop':
            try {
                $result = WebSocketService::stop();
            } catch (\Throwable $e) {
                throw new \Exception('Error stopping server: ' . $e->getMessage(), 0, $e);
            }
            break;
            
        case 'restart':
            try {
                $result = WebSocketService::restart();
            } catch (\Throwable $e) {
                throw new \Exception('Error restarting server: ' . $e->getMessage(), 0, $e);
            }
            break;
            
        case 'logging_status':
            try {
                $status = WebSocketLoggingService::getStatus();
                $result = [
                    'success' => true,
                    'data' => $status
                ];
            } catch (\Throwable $e) {
                throw new \Exception('Error getting logging status: ' . $e->getMessage(), 0, $e);
            }
            break;
            
        case 'enable_logging':
            try {
                if (WebSocketLoggingService::enable()) {
                    $result = [
                        'success' => true,
                        'message' => 'Логирование включено',
                        'data' => WebSocketLoggingService::getStatus()
                    ];
                } else {
                    throw new \Exception('Не удалось включить логирование');
                }
            } catch (\Throwable $e) {
                throw new \Exception('Error enabling logging: ' . $e->getMessage(), 0, $e);
            }
            break;
            
        case 'disable_logging':
            try {
                if (WebSocketLoggingService::disable()) {
                    $result = [
                        'success' => true,
                        'message' => 'Логирование отключено',
                        'data' => WebSocketLoggingService::getStatus()
                    ];
                } else {
                    throw new \Exception('Не удалось отключить логирование');
                }
            } catch (\Throwable $e) {
                throw new \Exception('Error disabling logging: ' . $e->getMessage(), 0, $e);
            }
            break;
            
        default:
            throw new \Exception('Invalid action: ' . $action);
    }
    
    // Убеждаемся, что результат валиден
    if ($result === null) {
        throw new \Exception('No result returned from service');
    }
    
    // Убеждаемся, что результат содержит success
    if (!isset($result['success'])) {
        $result['success'] = false;
        $result['error'] = 'Unexpected response format';
    }
    
    // Очищаем буфер перед отправкой JSON
    ob_clean();
    echo json_encode($result, JSON_UNESCAPED_UNICODE);
    ob_end_flush();
    exit;
    
} catch (\Exception $e) {
    // Логируем ошибку для отладки
    error_log('WebSocket control API error: ' . $e->getMessage());
    error_log('Stack trace: ' . $e->getTraceAsString());
    
    // Очищаем буфер перед отправкой ошибки
    ob_clean();
    
    http_response_code(500);
    echo json_encode([
        'success' => false,
        'error' => $e->getMessage(),
        'message' => 'Ошибка при выполнении операции'
    ], JSON_UNESCAPED_UNICODE);
    ob_end_flush();
    exit;
    
} catch (\Throwable $e) {
    // Обработка фатальных ошибок
    error_log('WebSocket control API fatal error: ' . $e->getMessage());
    
    ob_clean();
    
    http_response_code(500);
    echo json_encode([
        'success' => false,
        'error' => 'Fatal error occurred',
        'message' => 'Произошла критическая ошибка'
    ], JSON_UNESCAPED_UNICODE);
    ob_end_flush();
    exit;
}

// На всякий случай - если мы дошли сюда, что-то пошло не так
ob_clean();
http_response_code(500);
echo json_encode([
    'success' => false,
    'error' => 'Unexpected end of execution',
    'message' => 'Неожиданное завершение выполнения'
], JSON_UNESCAPED_UNICODE);
ob_end_flush();
exit;

