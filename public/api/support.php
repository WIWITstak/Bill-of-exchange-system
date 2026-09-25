<?php
/**
 * API для работы с поддержкой
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
use OGAS\Services\SupportService;
use OGAS\Core\Security;
use OGAS\Core\RateLimiter;

// Устанавливаем заголовок JSON до любых выводов
header('Content-Type: application/json; charset=utf-8');

// Rate limiting
try {
    $clientIp = Security::getClientIp();
    RateLimiter::requireLimit($clientIp, 60, 60);
} catch (\Throwable $e) {
    error_log('RateLimiter error: ' . $e->getMessage());
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

$user = Auth::user();
$isAdmin = AdminService::check();

try {
    $action = $_GET['action'] ?? $_POST['action'] ?? '';
    
    if (empty($action)) {
        throw new \Exception('Action parameter is required');
    }
    
    $result = null;
    
    switch ($action) {
        case 'create_ticket':
            Security::requireCsrfToken();
            $subject = $_POST['subject'] ?? '';
            $message = $_POST['message'] ?? '';
            $priority = $_POST['priority'] ?? 'medium';
            
            // Поддерживаем создание заявки с привязкой к транзакции или векселю
            $relatedTransactionId = isset($_POST['related_transaction_id']) && !empty($_POST['related_transaction_id']) ? (int)$_POST['related_transaction_id'] : null;
            $relatedBillId = isset($_POST['related_bill_id']) && !empty($_POST['related_bill_id']) ? (int)$_POST['related_bill_id'] : null;
            
            if ($relatedTransactionId || $relatedBillId) {
                $result = SupportService::createTicketWithRelation(
                    $user->getId(), 
                    $subject, 
                    $message, 
                    $relatedTransactionId, 
                    $relatedBillId, 
                    $priority
                );
            } else {
                $result = SupportService::createTicket($user->getId(), $subject, $message, $priority);
            }
            break;
            
        case 'get_tickets':
            if ($isAdmin) {
                $status = $_GET['status'] ?? null;
                $priority = $_GET['priority'] ?? null;
                $assignedTo = isset($_GET['assigned_to']) ? (int)$_GET['assigned_to'] : null;
                $tickets = SupportService::getAllTickets($status, $priority, $assignedTo);
            } else {
                $status = $_GET['status'] ?? null;
                $tickets = SupportService::getUserTickets($user->getId(), $status);
            }
            
            $result = [
                'success' => true,
                'data' => $tickets
            ];
            break;
            
        case 'get_ticket':
            $ticketId = (int)($_GET['ticket_id'] ?? $_POST['ticket_id'] ?? 0);
            if (!$ticketId) {
                throw new \Exception('Ticket ID is required');
            }
            
            $ticket = SupportService::getTicketWithMessages($ticketId, $user->getId(), $isAdmin);
            
            if ($ticket) {
                $result = [
                    'success' => true,
                    'data' => $ticket
                ];
            } else {
                $result = [
                    'success' => false,
                    'message' => 'Обращение не найдено или нет доступа'
                ];
            }
            break;
            
        case 'add_message':
            try {
                Security::requireCsrfToken();
            } catch (\Exception $e) {
                throw new \Exception('CSRF token validation failed: ' . $e->getMessage());
            }
            $ticketId = (int)($_POST['ticket_id'] ?? 0);
            $message = $_POST['message'] ?? '';
            
            if (!$ticketId) {
                throw new \Exception('Ticket ID is required');
            }
            
            $result = SupportService::addMessage($ticketId, $user->getId(), $message, $isAdmin);
            break;
            
        case 'update_status':
            if (!$isAdmin) {
                throw new \Exception('Admin rights required');
            }
            
            Security::requireCsrfToken();
            $ticketId = (int)($_POST['ticket_id'] ?? 0);
            $status = $_POST['status'] ?? '';
            $assignedTo = isset($_POST['assigned_to']) ? (int)$_POST['assigned_to'] : null;
            
            if (!$ticketId) {
                throw new \Exception('Ticket ID is required');
            }
            
            $result = SupportService::updateTicketStatus($ticketId, $status, $assignedTo);
            break;
            
        case 'assign_ticket':
            if (!$isAdmin) {
                throw new \Exception('Admin rights required');
            }
            
            Security::requireCsrfToken();
            $ticketId = (int)($_POST['ticket_id'] ?? 0);
            $adminId = !empty($_POST['admin_id']) ? (int)$_POST['admin_id'] : null;
            
            if (!$ticketId) {
                throw new \Exception('Ticket ID is required');
            }
            
            $result = SupportService::assignTicket($ticketId, $adminId);
            break;
            
        case 'get_stats':
            $userId = $isAdmin ? null : $user->getId();
            $stats = SupportService::getStats($userId);
            
            $result = [
                'success' => true,
                'data' => $stats
            ];
            break;
            
        case 'get_admins':
            if (!$isAdmin) {
                throw new \Exception('Admin rights required');
            }
            $admins = SupportService::getAdmins();
            $result = [
                'success' => true,
                'data' => $admins
            ];
            break;
            
        case 'close_ticket':
            if (!$isAdmin) {
                throw new \Exception('Admin rights required');
            }
            Security::requireCsrfToken();
            $ticketId = (int)($_POST['ticket_id'] ?? 0);
            if (!$ticketId) {
                throw new \Exception('Ticket ID is required');
            }
            $result = SupportService::closeTicket($ticketId);
            break;
            
        case 'admin_force_confirm_transaction':
            if (!$isAdmin) {
                throw new \Exception('Admin rights required');
            }
            Security::requireCsrfToken();
            $ticketId = (int)($_POST['ticket_id'] ?? 0);
            $transactionId = (int)($_POST['transaction_id'] ?? 0);
            $confirmSeller = isset($_POST['confirm_seller']) ? (bool)$_POST['confirm_seller'] : true;
            $confirmBuyer = isset($_POST['confirm_buyer']) ? (bool)$_POST['confirm_buyer'] : true;
            
            if (!$ticketId || !$transactionId) {
                throw new \Exception('Ticket ID and Transaction ID are required');
            }
            
            $result = SupportService::adminForceConfirmTransaction(
                $ticketId, 
                $user->getId(), 
                $transactionId, 
                $confirmSeller, 
                $confirmBuyer
            );
            break;
            
        case 'admin_force_create_bill':
            if (!$isAdmin) {
                throw new \Exception('Admin rights required');
            }
            Security::requireCsrfToken();
            $ticketId = (int)($_POST['ticket_id'] ?? 0);
            $transactionId = (int)($_POST['transaction_id'] ?? 0);
            $issuerId = (int)($_POST['issuer_id'] ?? 0);
            $holderId = (int)($_POST['holder_id'] ?? 0);
            $nominal = (float)($_POST['nominal'] ?? 0);
            $maturityDays = (int)($_POST['maturity_days'] ?? 30);
            
            if (!$ticketId || !$transactionId || !$issuerId || !$holderId || $nominal <= 0) {
                throw new \Exception('All required fields must be provided');
            }
            
            $result = SupportService::adminForceCreateBill(
                $ticketId,
                $user->getId(),
                $transactionId,
                $issuerId,
                $holderId,
                $nominal,
                $maturityDays
            );
            break;
            
        default:
            throw new \Exception('Invalid action: ' . $action);
    }
    
    ob_clean();
    echo json_encode($result, JSON_UNESCAPED_UNICODE);
    ob_end_flush();
    exit;
    
} catch (\Exception $e) {
    error_log('Support API error: ' . $e->getMessage());
    error_log('Stack trace: ' . $e->getTraceAsString());
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
    error_log('Support API fatal error: ' . $e->getMessage());
    error_log('Stack trace: ' . $e->getTraceAsString());
    ob_clean();
    http_response_code(500);
    echo json_encode([
        'success' => false,
        'error' => 'Fatal error occurred',
        'message' => 'Произошла критическая ошибка',
        'details' => $e->getMessage()
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

