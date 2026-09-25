<?php
/**
 * API endpoint для работы с чатом
 */

require_once __DIR__ . '/../../src/bootstrap.php';

use OGAS\Services\Auth;
use OGAS\Models\Transaction;
use OGAS\Models\CommunityRequest;
use OGAS\Services\ChatService;
use OGAS\Models\User;
use OGAS\Core\Security;
use OGAS\Core\RateLimiter;

header('Content-Type: application/json; charset=UTF-8');

// Rate limiting (100 запросов в минуту с одного IP)
$clientIp = Security::getClientIp();
RateLimiter::requireLimit($clientIp, 100, 60);

Auth::requireAuth();
$user = Auth::user();

if (!$user) {
    http_response_code(401);
    echo json_encode(['error' => 'Unauthorized']);
    exit;
}

$action = $_GET['action'] ?? $_POST['action'] ?? '';

try {
    switch ($action) {
        case 'get_messages':
            // Получить сообщения транзакции
            $transactionId = (int)($_GET['transaction_id'] ?? 0);
            $afterDate = $_GET['after_date'] ?? null;
            
            if ($transactionId <= 0) {
                throw new \Exception('Transaction ID required');
            }
            
            if (!ChatService::checkAccess($transactionId, $user->getId())) {
                error_log(sprintf(
                    '[api/chat.php::get_messages] Доступ запрещён: transactionId=%d, userId=%d, userEmail=%s',
                    $transactionId,
                    $user->getId(),
                    $user->getEmail()
                ));
                http_response_code(403);
                echo json_encode([
                    'success' => false,
                    'error' => 'Access denied. Вы не являетесь участником этой транзакции.',
                    'debug' => [
                        'transaction_id' => $transactionId,
                        'user_id' => $user->getId()
                    ]
                ], JSON_UNESCAPED_UNICODE);
                exit;
            }
            
            // Если указана дата, получаем только новые сообщения
            if ($afterDate) {
                try {
                    $messages = ChatService::getNewMessages($transactionId, $afterDate);
                } catch (\Exception $e) {
                    // Если метод не найден или ошибка, получаем все сообщения
                    $messages = ChatService::getMessages($transactionId);
                }
            } else {
                $messages = ChatService::getMessages($transactionId);
            }
            
            // Форматируем сообщения
            $formattedMessages = [];
            foreach ($messages as $msg) {
                $messageUser = User::findById($msg->getUserId());
                $formattedMessages[] = [
                    'id' => $msg->getId(),
                    'user_id' => $msg->getUserId(),
                    'user_name' => $messageUser ? $messageUser->getFullName() : 'Неизвестный',
                    'user_avatar_html' => $messageUser ? $messageUser->getAvatarHtml('small') : '<div class="message-avatar-placeholder">?</div>',
                    'message' => $msg->getMessage(),
                    'created_at' => $msg->getCreatedAt(),
                    'is_own' => $msg->getUserId() === $user->getId()
                ];
            }
            
            echo json_encode([
                'success' => true,
                'messages' => $formattedMessages
            ], JSON_UNESCAPED_UNICODE);
            break;
            
        case 'send_message':
            // Отправить сообщение
            $transactionId = (int)($_POST['transaction_id'] ?? 0);
            $message = trim($_POST['message'] ?? '');
            
            if ($transactionId <= 0) {
                throw new \Exception('Transaction ID required');
            }
            
            if (empty($message)) {
                throw new \Exception('Message is required');
            }
            
            if (!ChatService::checkAccess($transactionId, $user->getId())) {
                error_log(sprintf(
                    '[api/chat.php] Доступ запрещён при отправке сообщения: transactionId=%d, userId=%d, userEmail=%s',
                    $transactionId,
                    $user->getId(),
                    $user->getEmail()
                ));
                http_response_code(403);
                echo json_encode([
                    'success' => false,
                    'error' => 'Access denied. Вы не являетесь участником этой транзакции.'
                ], JSON_UNESCAPED_UNICODE);
                exit;
            }
            
            $messageModel = ChatService::sendMessage($transactionId, $user->getId(), $message);
            $messageUser = User::findById($messageModel->getUserId());
            
            echo json_encode([
                'success' => true,
                'message' => [
                    'id' => $messageModel->getId(),
                    'user_id' => $messageModel->getUserId(),
                    'user_name' => $messageUser->getFullName(),
                    'user_avatar_html' => $messageUser->getAvatarHtml('small'),
                    'message' => $messageModel->getMessage(),
                    'created_at' => $messageModel->getCreatedAt(),
                    'is_own' => true
                ]
            ], JSON_UNESCAPED_UNICODE);
            break;
            
        case 'mark_read':
            // Отметить сообщения как прочитанные
            $transactionId = (int)($_POST['transaction_id'] ?? 0);
            
            if ($transactionId <= 0) {
                throw new \Exception('Transaction ID required');
            }
            
            if (!ChatService::checkAccess($transactionId, $user->getId())) {
                error_log(sprintf(
                    '[api/chat.php::mark_read] Доступ запрещён: transactionId=%d, userId=%d, userEmail=%s',
                    $transactionId,
                    $user->getId(),
                    $user->getEmail()
                ));
                http_response_code(403);
                echo json_encode([
                    'success' => false,
                    'error' => 'Access denied'
                ], JSON_UNESCAPED_UNICODE);
                exit;
            }
            
            ChatService::markAsRead($transactionId, $user->getId());
            
            echo json_encode(['success' => true]);
            break;
            
        case 'get_unread_count':
            // Получить количество непрочитанных сообщений
            $transactionId = (int)($_GET['transaction_id'] ?? 0);
            
            if ($transactionId <= 0) {
                throw new \Exception('Transaction ID required');
            }
            
            if (!ChatService::checkAccess($transactionId, $user->getId())) {
                error_log(sprintf(
                    '[api/chat.php::get_unread_count] Доступ запрещён: transactionId=%d, userId=%d, userEmail=%s',
                    $transactionId,
                    $user->getId(),
                    $user->getEmail()
                ));
                http_response_code(403);
                echo json_encode([
                    'success' => false,
                    'error' => 'Access denied'
                ], JSON_UNESCAPED_UNICODE);
                exit;
            }
            
            $count = ChatService::getUnreadCount($transactionId, $user->getId());
            
            echo json_encode([
                'success' => true,
                'count' => $count
            ]);
            break;
            
        case 'get_community_messages':
            // Получить сообщения группового чата заявки общины
            $communityRequestId = (int)($_GET['community_request_id'] ?? 0);
            $afterDate = $_GET['after_date'] ?? null;
            
            if ($communityRequestId <= 0) {
                throw new \Exception('Community Request ID required');
            }
            
            if (!ChatService::checkCommunityAccess($communityRequestId, $user->getId())) {
                throw new \Exception('Access denied');
            }
            
            // Если указана дата, получаем только новые сообщения
            if ($afterDate) {
                $messages = ChatService::getNewCommunityMessages($communityRequestId, $afterDate);
            } else {
                $messages = ChatService::getCommunityMessages($communityRequestId);
            }
            
            // Форматируем сообщения
            $formattedMessages = [];
            foreach ($messages as $msg) {
                $messageUser = User::findById($msg->getUserId());
                $formattedMessages[] = [
                    'id' => $msg->getId(),
                    'user_id' => $msg->getUserId(),
                    'user_name' => $messageUser ? $messageUser->getFullName() : 'Неизвестный',
                    'user_avatar_html' => $messageUser ? $messageUser->getAvatarHtml('small') : '<div class="message-avatar-placeholder">?</div>',
                    'message' => $msg->getMessage(),
                    'created_at' => $msg->getCreatedAt(),
                    'is_own' => $msg->getUserId() === $user->getId()
                ];
            }
            
            echo json_encode([
                'success' => true,
                'messages' => $formattedMessages
            ], JSON_UNESCAPED_UNICODE);
            break;
            
        case 'send_community_message':
            // Отправить сообщение в групповой чат заявки общины
            $communityRequestId = (int)($_POST['community_request_id'] ?? 0);
            $message = trim($_POST['message'] ?? '');
            
            if ($communityRequestId <= 0) {
                throw new \Exception('Community Request ID required');
            }
            
            if (empty($message)) {
                throw new \Exception('Message is required');
            }
            
            if (!ChatService::checkCommunityAccess($communityRequestId, $user->getId())) {
                throw new \Exception('Access denied');
            }
            
            $messageModel = ChatService::sendCommunityMessage($communityRequestId, $user->getId(), $message);
            $messageUser = User::findById($messageModel->getUserId());
            
            echo json_encode([
                'success' => true,
                'message' => [
                    'id' => $messageModel->getId(),
                    'user_id' => $messageModel->getUserId(),
                    'user_name' => $messageUser->getFullName(),
                    'user_avatar_html' => $messageUser->getAvatarHtml('small'),
                    'message' => $messageModel->getMessage(),
                    'created_at' => $messageModel->getCreatedAt(),
                    'is_own' => true
                ]
            ], JSON_UNESCAPED_UNICODE);
            break;
            
        case 'mark_community_read':
            // Отметить сообщения группового чата общины как прочитанные
            $communityRequestId = (int)($_POST['community_request_id'] ?? 0);
            
            if ($communityRequestId <= 0) {
                throw new \Exception('Community Request ID required');
            }
            
            if (!ChatService::checkCommunityAccess($communityRequestId, $user->getId())) {
                throw new \Exception('Access denied');
            }
            
            ChatService::markCommunityAsRead($communityRequestId, $user->getId());
            
            echo json_encode(['success' => true]);
            break;
            
        case 'get_community_unread_count':
            // Получить количество непрочитанных сообщений в групповом чате общины
            $communityRequestId = (int)($_GET['community_request_id'] ?? 0);
            
            if ($communityRequestId <= 0) {
                throw new \Exception('Community Request ID required');
            }
            
            if (!ChatService::checkCommunityAccess($communityRequestId, $user->getId())) {
                throw new \Exception('Access denied');
            }
            
            $count = ChatService::getCommunityUnreadCount($communityRequestId, $user->getId());
            
            echo json_encode([
                'success' => true,
                'count' => $count
            ]);
            break;
            
        case 'get_transaction_status':
            // Получить статус подтверждения транзакции
            $transactionId = (int)($_GET['transaction_id'] ?? 0);
            
            if ($transactionId <= 0) {
                throw new \Exception('Transaction ID required');
            }
            
            if (!ChatService::checkAccess($transactionId, $user->getId())) {
                error_log(sprintf(
                    '[api/chat.php::get_transaction_status] Доступ запрещён: transactionId=%d, userId=%d, userEmail=%s',
                    $transactionId,
                    $user->getId(),
                    $user->getEmail()
                ));
                http_response_code(403);
                echo json_encode([
                    'success' => false,
                    'error' => 'Access denied'
                ], JSON_UNESCAPED_UNICODE);
                exit;
            }
            
            $transaction = Transaction::findById($transactionId);
            if (!$transaction) {
                throw new \Exception('Transaction not found');
            }
            
            echo json_encode([
                'success' => true,
                'seller_confirmed' => $transaction->isSellerConfirmed(),
                'buyer_confirmed' => $transaction->isBuyerConfirmed(),
                'both_confirmed' => $transaction->isBothConfirmed(),
                'status' => $transaction->getStatus()
            ]);
            break;
            
        case 'confirm_transaction':
            // @deprecated Подтверждение транзакции рекомендуется делать через WebSocket
            // Этот endpoint оставлен для обратной совместимости, но лучше использовать:
            // window.chatWebSocket.confirmTransaction(transactionId)
            
            // CSRF защита для POST запросов
            if ($_SERVER['REQUEST_METHOD'] === 'POST' && !Security::checkCsrfToken()) {
                throw new \Exception('CSRF token validation failed');
            }
            
            $transactionId = (int)($_POST['transaction_id'] ?? 0);
            
            if ($transactionId <= 0) {
                throw new \Exception('Transaction ID required');
            }
            
            if (!ChatService::checkAccess($transactionId, $user->getId())) {
                error_log(sprintf(
                    '[api/chat.php::confirm_transaction] Доступ запрещён: transactionId=%d, userId=%d, userEmail=%s',
                    $transactionId,
                    $user->getId(),
                    $user->getEmail()
                ));
                http_response_code(403);
                echo json_encode([
                    'success' => false,
                    'error' => 'Access denied'
                ], JSON_UNESCAPED_UNICODE);
                exit;
            }
            
            if (ChatService::confirmTransaction($transactionId, $user->getId())) {
                $transaction = Transaction::findById($transactionId);
                echo json_encode([
                    'success' => true,
                    'seller_confirmed' => $transaction->isSellerConfirmed(),
                    'buyer_confirmed' => $transaction->isBuyerConfirmed(),
                    'both_confirmed' => $transaction->isBothConfirmed(),
                    'status' => $transaction->getStatus(),
                    'message' => 'Сделка подтверждена!'
                ], JSON_UNESCAPED_UNICODE);
            } else {
                throw new \Exception('Не удалось подтвердить сделку');
            }
            break;
            
        case 'get_community_status':
            // Получить статус подтверждения поручителей общины
            $communityRequestId = (int)($_GET['community_request_id'] ?? 0);
            
            if ($communityRequestId <= 0) {
                throw new \Exception('Community Request ID required');
            }
            
            if (!ChatService::checkCommunityAccess($communityRequestId, $user->getId())) {
                throw new \Exception('Access denied');
            }
            
            $request = CommunityRequest::findById($communityRequestId);
            if (!$request) {
                throw new \Exception('Community request not found');
            }
            
            $guarantors = $request->getGuarantors();
            $guarantorsList = [];
            foreach ($guarantors as $g) {
                $guarantorUser = User::findById($g['guarantor_id']);
                $guarantorsList[] = [
                    'id' => (int)$g['guarantor_id'],
                    'name' => $guarantorUser ? $guarantorUser->getFullName() : 'Пользователь #' . $g['guarantor_id'],
                    'confirmed' => (bool)$g['confirmed'],
                    'bills_count' => (int)$g['bills_count']
                ];
            }
            
            echo json_encode([
                'success' => true,
                'is_fulfilled' => $request->isFulfilled(),
                'all_confirmed' => $request->allGuarantorsConfirmed(),
                'confirmed_count' => $request->getConfirmedGuarantorsCount(),
                'guarantors_count' => count($guarantors),
                'guarantors' => $guarantorsList,
                'collected' => $request->getCollectedAmount(),
                'progress_percent' => $request->getProgressPercent()
            ], JSON_UNESCAPED_UNICODE);
            break;
            
        case 'long_poll':
            // Long Polling для получения новых сообщений и изменений статуса
            $transactionId = (int)($_GET['transaction_id'] ?? 0);
            $communityRequestId = (int)($_GET['community_request_id'] ?? 0);
            $afterDate = $_GET['after_date'] ?? null;
            $lastStatusCheck = $_GET['last_status_check'] ?? null;
            $timeout = (int)($_GET['timeout'] ?? 25); // Таймаут в секундах (максимум 30)
            $timeout = min($timeout, 30);
            
            // Проверяем доступ
            if ($transactionId > 0) {
                if (!ChatService::checkAccess($transactionId, $user->getId())) {
                    throw new \Exception('Access denied');
                }
            } elseif ($communityRequestId > 0) {
                if (!ChatService::checkCommunityAccess($communityRequestId, $user->getId())) {
                    throw new \Exception('Access denied');
                }
            } else {
                throw new \Exception('Transaction ID or Community Request ID required');
            }
            
            // Отключаем ограничение времени выполнения
            set_time_limit($timeout + 10);
            
            // Начало Long Polling
            $startTime = time();
            $checkInterval = 0.5; // Проверяем каждые 0.5 секунды
            $maxChecks = (int)($timeout / $checkInterval);
            
            $newMessages = [];
            $statusChanged = false;
            $transaction = null;
            $request = null;
            
            for ($i = 0; $i < $maxChecks; $i++) {
                // Проверяем новые сообщения
                if ($afterDate) {
                    try {
                        if ($transactionId > 0) {
                            $newMessages = ChatService::getNewMessages($transactionId, $afterDate);
                        } elseif ($communityRequestId > 0) {
                            $newMessages = ChatService::getNewCommunityMessages($communityRequestId, $afterDate);
                        }
                        
                        // Если есть новые сообщения, возвращаем их немедленно
                        if (!empty($newMessages)) {
                            break;
                        }
                    } catch (\Exception $e) {
                        // Если метод не найден, получаем все сообщения
                        if ($transactionId > 0) {
                            $allMessages = ChatService::getMessages($transactionId);
                            // Фильтруем по дате вручную
                            foreach ($allMessages as $msg) {
                                if (strtotime($msg->getCreatedAt()) > strtotime($afterDate)) {
                                    $newMessages[] = $msg;
                                }
                            }
                            if (!empty($newMessages)) {
                                break;
                            }
                        }
                    }
                }
                
                // Проверяем изменения статуса транзакции
                if ($transactionId > 0) {
                    $transaction = Transaction::findById($transactionId);
                    if ($transaction) {
                        $currentStatus = [
                            'seller_confirmed' => $transaction->isSellerConfirmed(),
                            'buyer_confirmed' => $transaction->isBuyerConfirmed(),
                            'status' => $transaction->getStatus()
                        ];
                        
                        if ($lastStatusCheck) {
                            // Сравниваем текущий статус с последним известным
                            $lastStatus = json_decode($lastStatusCheck, true);
                            if ($lastStatus && (
                                $currentStatus['seller_confirmed'] !== ($lastStatus['seller_confirmed'] ?? false) ||
                                $currentStatus['buyer_confirmed'] !== ($lastStatus['buyer_confirmed'] ?? false) ||
                                $currentStatus['status'] !== ($lastStatus['status'] ?? '')
                            )) {
                                $statusChanged = true;
                                break;
                            }
                        } else {
                            // Если это первая проверка, просто возвращаем текущий статус
                            $statusChanged = true;
                            break;
                        }
                    }
                }
                
                // Ждем перед следующей проверкой (0.5 секунды)
                usleep(500000); // 0.5 секунды в микросекундах
            }
            
            // Форматируем новые сообщения
            $formattedMessages = [];
            foreach ($newMessages as $msg) {
                $messageUser = User::findById($msg->getUserId());
                $formattedMessages[] = [
                    'id' => $msg->getId(),
                    'user_id' => $msg->getUserId(),
                    'user_name' => $messageUser ? $messageUser->getFullName() : 'Неизвестный',
                    'user_avatar_html' => $messageUser ? $messageUser->getAvatarHtml('small') : '<div class="message-avatar-placeholder">?</div>',
                    'message' => $msg->getMessage(),
                    'created_at' => $msg->getCreatedAt(),
                    'is_own' => $msg->getUserId() === $user->getId()
                ];
            }
            
            // Формируем ответ
            $response = [
                'success' => true,
                'messages' => $formattedMessages,
                'has_new_messages' => !empty($formattedMessages),
                'status_changed' => $statusChanged
            ];
            
            // Добавляем информацию о статусе транзакции
            if ($transactionId > 0 && $transaction) {
                $response['transaction_status'] = [
                    'seller_confirmed' => $transaction->isSellerConfirmed(),
                    'buyer_confirmed' => $transaction->isBuyerConfirmed(),
                    'both_confirmed' => $transaction->isBothConfirmed(),
                    'status' => $transaction->getStatus()
                ];
            }
            
            echo json_encode($response, JSON_UNESCAPED_UNICODE);
            break;
            
        case 'get_all_unread_counts':
            // Получить количество непрочитанных сообщений для всех чатов пользователя
            $transactions = Transaction::findByUser($user->getId());
            $communityRequests = CommunityRequest::findUserCommunityChats($user->getId());
            
            $unreadCounts = [
                'transactions' => [],
                'community' => []
            ];
            
            // Получаем счетчики для транзакций
            foreach ($transactions as $transaction) {
                if (!ChatService::checkAccess($transaction->getId(), $user->getId())) {
                    continue;
                }
                $count = ChatService::getUnreadCount($transaction->getId(), $user->getId());
                if ($count > 0) {
                    $unreadCounts['transactions'][] = [
                        'id' => $transaction->getId(),
                        'count' => $count
                    ];
                }
            }
            
            // Получаем счетчики для групповых чатов общины
            foreach ($communityRequests as $request) {
                if (!ChatService::checkCommunityAccess($request->getId(), $user->getId())) {
                    continue;
                }
                $count = ChatService::getCommunityUnreadCount($request->getId(), $user->getId());
                if ($count > 0) {
                    $unreadCounts['community'][] = [
                        'id' => $request->getId(),
                        'count' => $count
                    ];
                }
            }
            
            echo json_encode([
                'success' => true,
                'unread_counts' => $unreadCounts
            ], JSON_UNESCAPED_UNICODE);
            break;
            
        case 'long_poll_unread_counts':
            // Long Polling для обновления непрочитанных счетчиков
            $timeout = (int)($_GET['timeout'] ?? 25);
            $startTime = time();
            $checkInterval = 1; // проверяем каждую секунду
            
            // Получаем текущие счетчики
            $lastKnownCounts = [];
            $transactions = Transaction::findByUser($user->getId());
            $communityRequests = CommunityRequest::findUserCommunityChats($user->getId());
            
            foreach ($transactions as $transaction) {
                if (ChatService::checkAccess($transaction->getId(), $user->getId())) {
                    $count = ChatService::getUnreadCount($transaction->getId(), $user->getId());
                    $lastKnownCounts['transaction_' . $transaction->getId()] = $count;
                }
            }
            
            foreach ($communityRequests as $request) {
                if (ChatService::checkCommunityAccess($request->getId(), $user->getId())) {
                    $count = ChatService::getCommunityUnreadCount($request->getId(), $user->getId());
                    $lastKnownCounts['community_' . $request->getId()] = $count;
                }
            }
            
            // Проверяем изменения каждую секунду
            while ((time() - $startTime) < $timeout) {
                $hasChanges = false;
                $updatedCounts = [
                    'transactions' => [],
                    'community' => []
                ];
                
                // Проверяем транзакции
                foreach ($transactions as $transaction) {
                    if (!ChatService::checkAccess($transaction->getId(), $user->getId())) {
                        continue;
                    }
                    $currentCount = ChatService::getUnreadCount($transaction->getId(), $user->getId());
                    $key = 'transaction_' . $transaction->getId();
                    
                    if (!isset($lastKnownCounts[$key]) || $lastKnownCounts[$key] !== $currentCount) {
                        $hasChanges = true;
                        $lastKnownCounts[$key] = $currentCount;
                        $updatedCounts['transactions'][] = [
                            'id' => $transaction->getId(),
                            'count' => $currentCount
                        ];
                    }
                }
                
                // Проверяем групповые чаты
                foreach ($communityRequests as $request) {
                    if (!ChatService::checkCommunityAccess($request->getId(), $user->getId())) {
                        continue;
                    }
                    $currentCount = ChatService::getCommunityUnreadCount($request->getId(), $user->getId());
                    $key = 'community_' . $request->getId();
                    
                    if (!isset($lastKnownCounts[$key]) || $lastKnownCounts[$key] !== $currentCount) {
                        $hasChanges = true;
                        $lastKnownCounts[$key] = $currentCount;
                        $updatedCounts['community'][] = [
                            'id' => $request->getId(),
                            'count' => $currentCount
                        ];
                    }
                }
                
                if ($hasChanges) {
                    echo json_encode([
                        'success' => true,
                        'has_changes' => true,
                        'unread_counts' => $updatedCounts
                    ], JSON_UNESCAPED_UNICODE);
                    exit;
                }
                
                usleep($checkInterval * 1000000); // 1 секунда
            }
            
            // Таймаут без изменений
            echo json_encode([
                'success' => true,
                'has_changes' => false
            ], JSON_UNESCAPED_UNICODE);
            break;
            
    }
} catch (\Exception $e) {
    http_response_code(500);
    echo json_encode(['error' => $e->getMessage()]);
}

