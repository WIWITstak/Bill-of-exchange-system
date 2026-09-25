<?php
/**
 * WebSocket сервер для обновления непрочитанных сообщений в реальном времени
 * 
 * Запуск: php websocket/chat-server.php
 * 
 * Требования:
 * - Composer: composer require cboden/ratchet
 * - PHP 8.0+
 * - Расширение sockets (обычно включено по умолчанию)
 */

use Ratchet\MessageComponentInterface;
use Ratchet\ConnectionInterface;
use Ratchet\Server\IoServer;
use Ratchet\Http\HttpServer;
use Ratchet\WebSocket\WsServer;
use React\Socket\Server as Reactor;
use React\Socket\SecureServer;
use React\EventLoop\Loop;

// Подключаем автозагрузчик Composer
require_once __DIR__ . '/../vendor/autoload.php';

// Проверяем наличие Ratchet
if (!class_exists('Ratchet\Server\IoServer')) {
    die("ERROR: Ratchet не установлен. Выполните: composer require cboden/ratchet\n");
}

// Подключаем bootstrap для доступа к БД и сервисам
require_once __DIR__ . '/../src/bootstrap.php';

// Классы уже загружены через bootstrap.php, просто импортируем для использования
use OGAS\Models\Transaction;
use OGAS\Models\CommunityRequest;
use OGAS\Services\ChatService;

/**
 * Класс для обработки WebSocket соединений
 */
class ChatUnreadCountsHandler implements MessageComponentInterface {
    public $clients; // Public для доступа из замыканий
    protected $users; // userId => [connections]
    protected $chatSubscriptions; // 'transaction_123' => [connections], 'community_456' => [connections]
    protected $connectionSubscriptions; // connection resourceId => [chatKeys]
    
    private $logFile;
    private $loggingConfigFile;
    
    // Rate Limiting (ослаблено)
    private $rateLimits = []; // connectionId => [count, timestamp]
    private $ipConnections = []; // ip => [connections]
    private $connectionIps = []; // connectionId => ip
    private const MAX_MESSAGES_PER_SECOND = 100; // Увеличено
    private const MAX_CONNECTIONS_PER_IP = 50; // Увеличено
    private const MAX_CONNECTIONS_PER_LOCALHOST = 100; // Увеличено
    private const MAX_SUBSCRIPTIONS_PER_CONNECTION = 200; // Увеличено
    
    // Разрешенные типы чатов
    private const ALLOWED_CHAT_TYPES = ['transaction', 'community', 'support'];
    
    // Разрешенные типы сообщений
    private const ALLOWED_MESSAGE_TYPES = [
        'auth', 'get_unread_counts', 'subscribe_chat', 'unsubscribe_chat',
        'send_message', 'mark_read', 'confirm_transaction', 'pong'
    ];
    
    public function __construct() {
        $this->clients = new \SplObjectStorage;
        $this->users = [];
        $this->chatSubscriptions = [];
        $this->connectionSubscriptions = [];
        
        // Путь к файлу логов
        $this->logFile = __DIR__ . '/server.log';
        
        // Путь к файлу конфигурации логирования
        $this->loggingConfigFile = __DIR__ . '/logging-config.json';
        
        // Логируем запуск сервера (если логирование включено)
        if ($this->isLoggingEnabled()) {
            $this->log('INFO', 'WebSocket сервер инициализирован', [
                'logging_enabled' => true
            ]);
        }
    }
    
    /**
     * Проверить, включено ли логирование
     * Проверяет конфигурационный файл при каждом вызове для динамического управления
     */
    private function isLoggingEnabled(): bool {
        // Проверяем переменную окружения (приоритет)
        $envLogging = getenv('WEBSOCKET_LOGGING');
        if ($envLogging !== false) {
            return filter_var($envLogging, FILTER_VALIDATE_BOOLEAN);
        }
        
        // Проверяем конфигурационный файл
        if (file_exists($this->loggingConfigFile)) {
            $config = @json_decode(file_get_contents($this->loggingConfigFile), true);
            if ($config !== null && isset($config['logging_enabled'])) {
                return (bool)$config['logging_enabled'];
            }
        }
        
        // По умолчанию включено
        return true;
    }
    
    /**
     * Логирование событий WebSocket сервера
     * 
     * @param string $level Уровень (INFO, WARNING, ERROR, DEBUG)
     * @param string $message Сообщение
     * @param array $context Дополнительный контекст
     */
    public function log(string $level, string $message, array $context = []): void {
        // Если логирование отключено, не записываем ничего (кроме критических ошибок)
        if (!$this->isLoggingEnabled() && $level !== 'ERROR') {
            return;
        }
        
        $timestamp = date('Y-m-d H:i:s');
        $contextStr = '';
        
        if (!empty($context)) {
            $contextStr = ' | Context: ' . json_encode($context, JSON_UNESCAPED_UNICODE);
        }
        
        $logMessage = "[{$timestamp}] {$level}: {$message}{$contextStr}\n";
        
        // Записываем в файл
        @file_put_contents($this->logFile, $logMessage, FILE_APPEND | LOCK_EX);
        
        // Также выводим в консоль для отладки
        echo $logMessage;
    }
    
    /**
     * Получить всех подключенных пользователей
     */
    public function getConnectedUsers(): array {
        $users = [];
        foreach ($this->clients as $client) {
            if (isset($client->userId)) {
                $userId = $client->userId;
                if (!isset($users[$userId])) {
                    $users[$userId] = true;
                }
            }
        }
        return array_keys($users);
    }
    
    /**
     * Получить IP адрес клиента
     */
    private function getClientIp(ConnectionInterface $conn): string {
        $ip = $conn->remoteAddress ?? 'unknown';
        // Извлекаем IP из адреса (может быть в формате "ip:port")
        if (strpos($ip, ':') !== false) {
            $parts = explode(':', $ip);
            $ip = $parts[0];
        }
        return $ip;
    }
    
    /**
     * Проверить количество подключений с одного IP
     */
    private function countConnectionsByIp(string $ip): int {
        if (!isset($this->ipConnections[$ip])) {
            return 0;
        }
        
        // Подсчитываем только активные соединения
        $activeConnections = array_filter($this->ipConnections[$ip], function($conn) {
            return $conn instanceof ConnectionInterface;
        });
        
        return count($activeConnections);
    }
    
    /**
     * Очистка неактивных подключений с IP
     */
    private function cleanupInactiveConnections(string $ip): void {
        if (!isset($this->ipConnections[$ip])) {
            return;
        }
        
        // Удаляем закрытые соединения
        $this->ipConnections[$ip] = array_filter($this->ipConnections[$ip], function($conn) {
            // Проверяем, что соединение еще активно
            return $conn instanceof ConnectionInterface;
        });
        
        // Переиндексируем массив
        $this->ipConnections[$ip] = array_values($this->ipConnections[$ip]);
        
        // Если соединений не осталось, удаляем запись
        if (empty($this->ipConnections[$ip])) {
            unset($this->ipConnections[$ip]);
        }
    }
    
    /**
     * Проверка, является ли IP localhost
     */
    private function isLocalhost(string $ip): bool {
        return in_array($ip, ['127.0.0.1', '::1', 'localhost'], true) || 
               strpos($ip, '127.') === 0;
    }
    
    /**
     * Новое подключение
     */
    public function onOpen(ConnectionInterface $conn) {
        $ip = $this->getClientIp($conn);
        
        // Проверка лимита подключений (ослаблена)
        $maxConnections = $this->isLocalhost($ip) 
            ? self::MAX_CONNECTIONS_PER_LOCALHOST 
            : self::MAX_CONNECTIONS_PER_IP;
        
        // Очищаем неактивные соединения перед добавлением нового
        $this->cleanupInactiveConnections($ip);
        
        // Проверяем количество подключений с одного IP (только предупреждение, не блокируем)
        $ipConnections = $this->countConnectionsByIp($ip);
        if ($ipConnections >= $maxConnections) {
            $this->log('INFO', 'Много подключений с одного IP (не блокируем)', [
                'ip' => $ip,
                'connections' => $ipConnections,
                'max_connections' => $maxConnections,
                'connection_id' => $conn->resourceId ?? 'unknown'
            ]);
            // Не блокируем, только логируем
        }
        
        // Сохраняем IP для соединения
        $this->connectionIps[$conn->resourceId] = $ip;
        if (!isset($this->ipConnections[$ip])) {
            $this->ipConnections[$ip] = [];
        }
        $this->ipConnections[$ip][] = $conn;
        
        $this->clients->attach($conn);
        $this->log('INFO', 'Новое подключение', [
            'connection_id' => $conn->resourceId,
            'remote_address' => $ip
        ]);
    }
    
    /**
     * Проверка Rate Limit
     */
    private function checkRateLimit(ConnectionInterface $conn): bool {
        // Упрощенная проверка: всегда разрешаем (rate limiting отключен)
        // Можно включить обратно, если нужно
        return true;
    }
    
    /**
     * Валидация входных данных
     */
    private function validateInput(array $data, string $type): array {
        $errors = [];
        
        // Проверка типа сообщения
        if (!in_array($type, self::ALLOWED_MESSAGE_TYPES, true)) {
            $errors[] = 'Неизвестный тип сообщения';
            return $errors;
        }
        
        // Валидация в зависимости от типа
        switch ($type) {
            case 'auth':
                if (!isset($data['userId']) || !is_numeric($data['userId']) || (int)$data['userId'] <= 0) {
                    $errors[] = 'Неверный userId';
                }
                // Токен опционален (упрощенная аутентификация)
                break;
                
            case 'subscribe_chat':
            case 'unsubscribe_chat':
                if (!isset($data['chat_type']) || !in_array($data['chat_type'], self::ALLOWED_CHAT_TYPES, true)) {
                    $errors[] = 'Не указан тип чата или он недопустим';
                }
                if (!isset($data['chat_id']) || !is_numeric($data['chat_id'])) {
                    $errors[] = 'Не указан chat_id или он не является числом';
                } elseif ((int)$data['chat_id'] <= 0) {
                    $errors[] = 'chat_id должен быть положительным числом';
                }
                break;
                
            case 'send_message':
                if (!isset($data['chat_type']) || !in_array($data['chat_type'], self::ALLOWED_CHAT_TYPES, true)) {
                    $errors[] = 'Не указан тип чата или он недопустим';
                }
                if (!isset($data['chat_id']) || !is_numeric($data['chat_id'])) {
                    $errors[] = 'Не указан chat_id или он не является числом';
                } elseif ((int)$data['chat_id'] <= 0) {
                    $errors[] = 'chat_id должен быть положительным числом';
                }
                if (!isset($data['message']) || !is_string($data['message'])) {
                    $errors[] = 'Не указано сообщение или оно не является строкой';
                } elseif (mb_strlen($data['message']) > 10000) {
                    $errors[] = 'Сообщение слишком длинное (максимум 10000 символов)';
                }
                break;
                
            case 'mark_read':
                if (!isset($data['chat_id']) || !is_numeric($data['chat_id'])) {
                    $errors[] = 'Не указан chat_id или он не является числом';
                } elseif ((int)$data['chat_id'] <= 0) {
                    $errors[] = 'chat_id должен быть положительным числом';
                }
                break;
                
            case 'confirm_transaction':
                // Для confirm_transaction принимаем либо transaction_id, либо chat_id
                // Проверяем transaction_id в первую очередь (как отправляет клиент)
                if (isset($data['transaction_id'])) {
                    if (!is_numeric($data['transaction_id']) || (int)$data['transaction_id'] <= 0) {
                        $errors[] = 'transaction_id должен быть положительным числом';
                    }
                } elseif (isset($data['chat_id'])) {
                    // Fallback на chat_id для обратной совместимости
                    if (!is_numeric($data['chat_id']) || (int)$data['chat_id'] <= 0) {
                        $errors[] = 'chat_id должен быть положительным числом';
                    }
                } else {
                    $errors[] = 'Не указан transaction_id или chat_id';
                }
                break;
        }
        
        return $errors;
    }
    
    /**
     * Улучшенная защита от XSS
     */
    private function sanitizeMessage(string $message): string {
        // Удаляем нулевые байты
        $message = str_replace("\0", '', $message);
        
        // Ограничиваем длину
        if (mb_strlen($message) > 10000) {
            $message = mb_substr($message, 0, 10000);
        }
        
        // Удаляем опасные HTML теги и атрибуты
        $message = strip_tags($message, '<p><br><strong><em><u><a>');
        
        // Экранируем оставшиеся HTML символы
        $message = htmlspecialchars($message, ENT_QUOTES | ENT_HTML5, 'UTF-8');
        
        // Дополнительная проверка на опасные паттерны
        $dangerousPatterns = [
            '/javascript:/i',
            '/on\w+\s*=/i',
            '/data:text\/html/i',
            '/vbscript:/i',
            '/expression\s*\(/i',
            '/@import/i'
        ];
        
        foreach ($dangerousPatterns as $pattern) {
            if (preg_match($pattern, $message)) {
                $this->log('WARNING', 'Обнаружен опасный паттерн в сообщении', [
                    'pattern' => $pattern,
                    'message_preview' => mb_substr($message, 0, 100)
                ]);
                // Удаляем опасный паттерн
                $message = preg_replace($pattern, '', $message);
            }
        }
        
        return trim($message);
    }
    
    /**
     * Получено сообщение от клиента
     */
    public function onMessage(ConnectionInterface $from, $msg) {
        try {
            // Проверка Rate Limit
            if (!$this->checkRateLimit($from)) {
                $from->send(json_encode([
                    'type' => 'error',
                    'message' => 'Превышен лимит сообщений. Попробуйте позже.'
                ], JSON_UNESCAPED_UNICODE));
                return;
            }
            
            // Ограничение размера JSON
            if (strlen($msg) > 65536) { // 64KB
                $this->log('WARNING', 'Сообщение слишком большое', [
                    'connection_id' => $from->resourceId ?? 'unknown',
                    'size' => strlen($msg)
                ]);
                $from->send(json_encode([
                    'type' => 'error',
                    'message' => 'Сообщение слишком большое'
                ], JSON_UNESCAPED_UNICODE));
                return;
            }
            
            $data = json_decode($msg, true);
            
            if (!$data || !is_array($data)) {
                $from->send(json_encode([
                    'type' => 'error',
                    'message' => 'Неверный формат JSON'
                ], JSON_UNESCAPED_UNICODE));
                return;
            }
            
            if (!isset($data['type']) || !is_string($data['type'])) {
                $from->send(json_encode([
                    'type' => 'error',
                    'message' => 'Не указан тип сообщения'
                ], JSON_UNESCAPED_UNICODE));
                return;
            }
            
            // Валидация входных данных
            $validationErrors = $this->validateInput($data, $data['type']);
            if (!empty($validationErrors)) {
                $this->log('WARNING', 'Ошибки валидации входных данных', [
                    'connection_id' => $from->resourceId ?? 'unknown',
                    'user_id' => $from->userId ?? null,
                    'type' => $data['type'],
                    'errors' => $validationErrors,
                    'received_data' => $data // Добавляем данные для отладки
                ]);
                $from->send(json_encode([
                    'type' => 'error',
                    'message' => 'Ошибки валидации: ' . implode(', ', $validationErrors)
                ], JSON_UNESCAPED_UNICODE));
                return;
            }
            
            // Логируем получение сообщения
            $this->log('DEBUG', 'Получено сообщение', [
                'connection_id' => $from->resourceId ?? 'unknown',
                'user_id' => $from->userId ?? null,
                'type' => $data['type']
            ]);
            
            switch ($data['type']) {
                case 'auth':
                    $this->handleAuth($from, $data);
                    break;
                    
                case 'get_unread_counts':
                    $this->handleGetUnreadCounts($from);
                    break;
                    
                case 'subscribe_chat':
                    $this->handleSubscribeChat($from, $data);
                    break;
                    
                case 'unsubscribe_chat':
                    $this->handleUnsubscribeChat($from, $data);
                    break;
                    
                case 'send_message':
                    $this->handleSendMessage($from, $data);
                    break;
                    
                case 'mark_read':
                    $this->handleMarkAsRead($from, $data);
                    break;
                    
                case 'confirm_transaction':
                    $this->handleConfirmTransaction($from, $data);
                    break;
                    
                case 'pong':
                    // Ответ на ping, ничего не делаем
                    break;
                    
                default:
                    $this->log('WARNING', 'Неизвестный тип сообщения', [
                        'connection_id' => $from->resourceId ?? 'unknown',
                        'user_id' => $from->userId ?? null,
                        'type' => $data['type']
                    ]);
                    
                    $from->send(json_encode([
                        'type' => 'error',
                        'message' => 'Неизвестный тип сообщения: ' . $data['type']
                    ]));
            }
            
        } catch (\Exception $e) {
            $this->log('ERROR', 'Ошибка обработки сообщения', [
                'connection_id' => $from->resourceId ?? 'unknown',
                'user_id' => $from->userId ?? null,
                'message' => $msg,
                'error' => $e->getMessage(),
                'trace' => $e->getTraceAsString()
            ]);
            
            $from->send(json_encode([
                'type' => 'error',
                'message' => 'Ошибка обработки запроса'
            ]));
        }
    }
    
    /**
     * Проверка токена аутентификации (упрощенная версия)
     */
    private function validateAuthToken(int $userId, string $token): bool {
        try {
            // Простая проверка: если пользователь существует, разрешаем подключение
            $user = \OGAS\Models\User::findById($userId);
            if ($user) {
                return true;
            }
            return false;
        } catch (\Exception $e) {
            $this->log('ERROR', 'Ошибка проверки токена аутентификации', [
                'user_id' => $userId,
                'error' => $e->getMessage()
            ]);
            return false;
        }
    }
    
    /**
     * Обработка аутентификации
     */
    private function handleAuth(ConnectionInterface $conn, array $data) {
        $userId = (int)$data['userId'];
        $token = trim($data['token'] ?? '');
        
        // Упрощенная проверка: просто проверяем, существует ли пользователь
        $user = \OGAS\Models\User::findById($userId);
        if (!$user) {
            $this->log('WARNING', 'Попытка аутентификации несуществующего пользователя', [
                'connection_id' => $conn->resourceId ?? 'unknown',
                'user_id' => $userId
            ]);
            $conn->send(json_encode([
                'type' => 'error',
                'message' => 'Пользователь не найден'
            ], JSON_UNESCAPED_UNICODE));
            return;
        }
        
        // Сохраняем userId для соединения
        $conn->userId = $userId;
        $conn->authenticated = true;
        
        // Добавляем соединение к списку соединений пользователя
        if (!isset($this->users[$userId])) {
            $this->users[$userId] = [];
        }
        $this->users[$userId][] = $conn;
        
        $this->log('INFO', 'Пользователь аутентифицирован', [
            'connection_id' => $conn->resourceId ?? 'unknown',
            'user_id' => $userId,
            'user_email' => $user->getEmail(),
            'total_connections' => count($this->users[$userId]),
            'ip' => $this->connectionIps[$conn->resourceId] ?? 'unknown'
        ]);
        
        // Отправляем подтверждение аутентификации
        $authSuccessMessage = json_encode([
            'type' => 'auth_success',
            'userId' => $userId
        ], JSON_UNESCAPED_UNICODE);
        
        $this->log('INFO', 'Отправка подтверждения аутентификации', [
            'connection_id' => $conn->resourceId ?? 'unknown',
            'user_id' => $userId
        ]);
        
        $conn->send($authSuccessMessage);
        
        // Отправляем текущие счетчики
        $this->sendUnreadCountsToUser($userId);
    }
    
    /**
     * Обработка запроса счетчиков непрочитанных
     */
    private function handleGetUnreadCounts(ConnectionInterface $conn) {
        if (!isset($conn->userId) || !isset($conn->authenticated) || !$conn->authenticated) {
            $this->log('WARNING', 'Попытка получить счетчики без аутентификации', [
                'connection_id' => $conn->resourceId ?? 'unknown'
            ]);
            $conn->send(json_encode([
                'type' => 'error',
                'message' => 'Требуется аутентификация'
            ], JSON_UNESCAPED_UNICODE));
            return;
        }
        
        $this->log('DEBUG', 'Запрос счетчиков непрочитанных', [
            'connection_id' => $conn->resourceId ?? 'unknown',
            'user_id' => $conn->userId
        ]);
        
        $this->sendUnreadCountsToUser($conn->userId);
    }
    
    /**
     * Отправить счетчики непрочитанных пользователю
     */
    private function sendUnreadCountsToUser(int $userId) {
        if (!isset($this->users[$userId]) || empty($this->users[$userId])) {
            return;
        }
        
        try {
            $unreadCounts = $this->getUnreadCounts($userId);
            
            $message = json_encode([
                'type' => 'unread_counts_update',
                'unread_counts' => $unreadCounts
            ], JSON_UNESCAPED_UNICODE);
            
            // Отправляем всем соединениям пользователя
            foreach ($this->users[$userId] as $index => $conn) {
                try {
                    if ($conn instanceof ConnectionInterface) {
                        $conn->send($message);
                    } else {
                        // Удаляем невалидное соединение
                        unset($this->users[$userId][$index]);
                    }
                } catch (\Exception $e) {
                    // Удаляем проблемное соединение
                    unset($this->users[$userId][$index]);
                }
            }
            
            // Очищаем массив от null значений
            $this->users[$userId] = array_values(array_filter($this->users[$userId]));
            
        } catch (\Exception $e) {
        }
    }
    
    /**
     * Получить все счетчики непрочитанных для пользователя
     */
    private function getUnreadCounts(int $userId): array {
        $unreadCounts = [
            'transactions' => [],
            'community' => []
        ];
        
        try {
            // Получаем транзакции пользователя
            $transactions = Transaction::findByUser($userId);
            
            foreach ($transactions as $transaction) {
                if (ChatService::checkAccess($transaction->getId(), $userId)) {
                    $count = ChatService::getUnreadCount($transaction->getId(), $userId);
                    if ($count > 0) {
                        $unreadCounts['transactions'][] = [
                            'id' => $transaction->getId(),
                            'count' => $count
                        ];
                    }
                }
            }
            
            // Получаем групповые чаты общины
            $communityRequests = CommunityRequest::findUserCommunityChats($userId);
            
            foreach ($communityRequests as $request) {
                if (ChatService::checkCommunityAccess($request->getId(), $userId)) {
                    $count = ChatService::getCommunityUnreadCount($request->getId(), $userId);
                    if ($count > 0) {
                        $unreadCounts['community'][] = [
                            'id' => $request->getId(),
                            'count' => $count
                        ];
                    }
                }
            }
            
        } catch (\Exception $e) {
        }
        
        return $unreadCounts;
    }
    
    /**
     * Отправка обновления счетчиков конкретному пользователю
     * Вызывается извне при отправке сообщения
     */
    public function notifyUserUnreadCounts(int $userId) {
        $this->log('DEBUG', 'Обновление счетчиков непрочитанных', [
            'user_id' => $userId
        ]);
        $this->sendUnreadCountsToUser($userId);
    }
    
    /**
     * Проверка количества подписок
     */
    private function checkSubscriptionLimit(ConnectionInterface $conn): bool {
        $id = $conn->resourceId;
        $currentSubscriptions = isset($this->connectionSubscriptions[$id]) 
            ? count($this->connectionSubscriptions[$id]) 
            : 0;
        
        if ($currentSubscriptions >= self::MAX_SUBSCRIPTIONS_PER_CONNECTION) {
            $this->log('WARNING', 'Превышен лимит подписок', [
                'connection_id' => $id,
                'user_id' => $conn->userId ?? null,
                'subscriptions' => $currentSubscriptions
            ]);
            return false;
        }
        
        return true;
    }
    
    /**
     * Подписка на чат
     */
    private function handleSubscribeChat(ConnectionInterface $conn, array $data) {
        if (!isset($conn->userId) || !isset($conn->authenticated) || !$conn->authenticated) {
            $conn->send(json_encode([
                'type' => 'error',
                'message' => 'Требуется аутентификация'
            ], JSON_UNESCAPED_UNICODE));
            return;
        }
        
        // Проверка лимита подписок
        if (!$this->checkSubscriptionLimit($conn)) {
            $conn->send(json_encode([
                'type' => 'error',
                'message' => 'Превышен лимит подписок на чаты'
            ], JSON_UNESCAPED_UNICODE));
            return;
        }
        
        $chatType = $data['chat_type'] ?? null;
        $chatId = isset($data['chat_id']) ? (int)$data['chat_id'] : null;
        
        if (!$chatType || !$chatId) {
            $conn->send(json_encode([
                'type' => 'error',
                'message' => 'Не указан тип чата или ID'
            ]));
            return;
        }
        
        // Проверяем доступ к чату
        if ($chatType === 'transaction') {
            // Получаем информацию о транзакции для детального логирования
            $transaction = \OGAS\Models\Transaction::findById($chatId);
            if (!$transaction) {
                $this->log('WARNING', 'Попытка подписки на несуществующую транзакцию', [
                    'connection_id' => $conn->resourceId ?? 'unknown',
                    'user_id' => $conn->userId,
                    'chat_id' => $chatId
                ]);
                $conn->send(json_encode([
                    'type' => 'error',
                    'message' => 'Транзакция не найдена'
                ]));
                return;
            }
            
            if (!ChatService::checkAccess($chatId, $conn->userId)) {
                $this->log('WARNING', 'Попытка подписки на чат без доступа (транзакция)', [
                    'connection_id' => $conn->resourceId ?? 'unknown',
                    'user_id' => $conn->userId,
                    'chat_id' => $chatId,
                    'transaction_seller_id' => $transaction->getSellerId(),
                    'transaction_buyer_id' => $transaction->getBuyerId(),
                    'user_is_seller' => ($transaction->getSellerId() === $conn->userId),
                    'user_is_buyer' => ($transaction->getBuyerId() === $conn->userId)
                ]);
                $conn->send(json_encode([
                    'type' => 'error',
                    'message' => 'Нет доступа к этому чату. Вы не являетесь участником этой транзакции.',
                    'debug' => [
                        'transaction_id' => $chatId,
                        'your_user_id' => $conn->userId,
                        'transaction_seller_id' => $transaction->getSellerId(),
                        'transaction_buyer_id' => $transaction->getBuyerId()
                    ]
                ]));
                return;
            }
        } elseif ($chatType === 'community') {
            if (!ChatService::checkCommunityAccess($chatId, $conn->userId)) {
                $this->log('WARNING', 'Попытка подписки на чат без доступа (община)', [
                    'connection_id' => $conn->resourceId ?? 'unknown',
                    'user_id' => $conn->userId,
                    'chat_id' => $chatId
                ]);
                $conn->send(json_encode([
                    'type' => 'error',
                    'message' => 'Нет доступа к этому чату'
                ]));
                return;
            }
        } elseif ($chatType === 'support') {
            // Проверяем доступ к обращению в поддержку
            $ticketModel = new \OGAS\Models\Ticket();
            $ticket = $ticketModel->findById($chatId);
            if (!$ticket) {
                $this->log('WARNING', 'Попытка подписки на несуществующее обращение', [
                    'connection_id' => $conn->resourceId ?? 'unknown',
                    'user_id' => $conn->userId,
                    'ticket_id' => $chatId
                ]);
                $conn->send(json_encode([
                    'type' => 'error',
                    'message' => 'Обращение не найдено'
                ]));
                return;
            }
            
            // Проверяем права доступа: пользователь должен быть создателем или администратором
            $user = \OGAS\Models\User::findById($conn->userId);
            $isAdmin = $user && $user->isAdmin();
            
            if ($ticket['user_id'] != $conn->userId && !$isAdmin) {
                $this->log('WARNING', 'Попытка подписки на обращение без доступа', [
                    'connection_id' => $conn->resourceId ?? 'unknown',
                    'user_id' => $conn->userId,
                    'ticket_id' => $chatId,
                    'ticket_user_id' => $ticket['user_id']
                ]);
                $conn->send(json_encode([
                    'type' => 'error',
                    'message' => 'Нет доступа к этому обращению'
                ]));
                return;
            }
        } else {
            $this->log('WARNING', 'Попытка подписки на неизвестный тип чата', [
                'connection_id' => $conn->resourceId ?? 'unknown',
                'user_id' => $conn->userId,
                'chat_type' => $chatType,
                'chat_id' => $chatId
            ]);
            $conn->send(json_encode([
                'type' => 'error',
                'message' => 'Неизвестный тип чата'
            ]));
            return;
        }
        
        $chatKey = "{$chatType}_{$chatId}";
        
        // Инициализируем массив подписок для этого чата
        if (!isset($this->chatSubscriptions[$chatKey])) {
            $this->chatSubscriptions[$chatKey] = [];
        }
        
        // Добавляем соединение к подпискам чата
        if (!in_array($conn, $this->chatSubscriptions[$chatKey], true)) {
            $this->chatSubscriptions[$chatKey][] = $conn;
        }
        
        // Сохраняем список подписок для соединения в отдельной структуре
        $connId = $conn->resourceId;
        if (!isset($this->connectionSubscriptions[$connId])) {
            $this->connectionSubscriptions[$connId] = [];
        }
        if (!in_array($chatKey, $this->connectionSubscriptions[$connId])) {
            $this->connectionSubscriptions[$connId][] = $chatKey;
        }
        
        $this->log('INFO', 'Подписка на чат', [
            'connection_id' => $conn->resourceId ?? 'unknown',
            'user_id' => $conn->userId,
            'chat_type' => $chatType,
            'chat_id' => $chatId,
            'chat_key' => $chatKey,
            'total_subscribers' => count($this->chatSubscriptions[$chatKey])
        ]);
        
        // Отправляем подтверждение подписки
        $conn->send(json_encode([
            'type' => 'chat_subscribed',
            'chat_type' => $chatType,
            'chat_id' => $chatId
        ]));
        
        $conn->send(json_encode([
            'type' => 'chat_subscribed',
            'chat_type' => $chatType,
            'chat_id' => $chatId
        ]));
    }
    
    /**
     * Отписка от чата
     */
    private function handleUnsubscribeChat(ConnectionInterface $conn, array $data) {
        if (!isset($conn->userId) || !isset($conn->authenticated) || !$conn->authenticated) {
            $conn->send(json_encode([
                'type' => 'error',
                'message' => 'Требуется аутентификация'
            ], JSON_UNESCAPED_UNICODE));
            return;
        }
        
        $chatType = $data['chat_type'] ?? null;
        $chatId = isset($data['chat_id']) ? (int)$data['chat_id'] : null;
        
        if (!$chatType || !$chatId) {
            $conn->send(json_encode([
                'type' => 'error',
                'message' => 'Не указан тип чата или ID'
            ]));
            return;
        }
        
        $chatKey = "{$chatType}_{$chatId}";
        
        // Удаляем соединение из подписок чата
        if (isset($this->chatSubscriptions[$chatKey])) {
            $this->chatSubscriptions[$chatKey] = array_filter(
                $this->chatSubscriptions[$chatKey],
                function($c) use ($conn) {
                    return $c !== $conn;
                }
            );
            $this->chatSubscriptions[$chatKey] = array_values($this->chatSubscriptions[$chatKey]);
            
            // Если подписок не осталось, удаляем чат
            if (empty($this->chatSubscriptions[$chatKey])) {
                unset($this->chatSubscriptions[$chatKey]);
            }
        }
        
        // Удаляем чат из списка подписок соединения
        $connId = $conn->resourceId;
        if (isset($this->connectionSubscriptions[$connId])) {
            $this->connectionSubscriptions[$connId] = array_filter(
                $this->connectionSubscriptions[$connId],
                function($key) use ($chatKey) {
                    return $key !== $chatKey;
                }
            );
            $this->connectionSubscriptions[$connId] = array_values($this->connectionSubscriptions[$connId]);
            
            // Если подписок не осталось, удаляем запись
            if (empty($this->connectionSubscriptions[$connId])) {
                unset($this->connectionSubscriptions[$connId]);
            }
        }
        
        if (isset($conn->userId)) {
            $this->log('INFO', 'Отписка от чата', [
                'connection_id' => $conn->resourceId ?? 'unknown',
                'user_id' => $conn->userId,
                'chat_type' => $chatType,
                'chat_id' => $chatId,
                'chat_key' => $chatKey
            ]);
        }
        
        $conn->send(json_encode([
            'type' => 'chat_unsubscribed',
            'chat_type' => $chatType,
            'chat_id' => $chatId
        ]));
    }
    
    /**
     * Обработка отправки сообщения через WebSocket
     */
    private function handleSendMessage(ConnectionInterface $conn, array $data) {
        if (!isset($conn->userId) || !isset($conn->authenticated) || !$conn->authenticated) {
            $conn->send(json_encode([
                'type' => 'error',
                'message' => 'Требуется аутентификация'
            ], JSON_UNESCAPED_UNICODE));
            return;
        }
        
        $chatType = $data['chat_type'] ?? null;
        $chatId = isset($data['chat_id']) ? (int)$data['chat_id'] : null;
        $message = trim($data['message'] ?? '');
        
        if (!$chatType || !$chatId || empty($message)) {
            $conn->send(json_encode([
                'type' => 'error',
                'message' => 'Не указан тип чата, ID или сообщение'
            ]));
            return;
        }
        
        // Улучшенная защита от XSS
        $message = $this->sanitizeMessage($message);
        
        if (empty($message)) {
            $conn->send(json_encode([
                'type' => 'error',
                'message' => 'Сообщение не может быть пустым'
            ], JSON_UNESCAPED_UNICODE));
            return;
        }
        
        try {
            $messageModel = null;
            $transactionStatus = null;
            
            // Отправляем сообщение через соответствующий сервис
            if ($chatType === 'transaction') {
                $messageModel = ChatService::sendMessage($chatId, $conn->userId, $message);
                
                // Получаем информацию о транзакции для статуса
                $transaction = Transaction::findById($chatId);
                if ($transaction) {
                    $transactionStatus = [
                        'seller_confirmed' => $transaction->isSellerConfirmed(),
                        'buyer_confirmed' => $transaction->isBuyerConfirmed(),
                        'both_confirmed' => $transaction->isBothConfirmed(),
                        'status' => $transaction->getStatus()
                    ];
                }
            } elseif ($chatType === 'community') {
                $messageModel = ChatService::sendCommunityMessage($chatId, $conn->userId, $message);
            } elseif ($chatType === 'support') {
                // Отправляем сообщение в обращение поддержки
                $user = \OGAS\Models\User::findById($conn->userId);
                $isAdmin = $user && $user->isAdmin();
                
                $result = \OGAS\Services\SupportService::addMessage($chatId, $conn->userId, $message, $isAdmin);
                
                if (!$result['success']) {
                    $conn->send(json_encode([
                        'type' => 'error',
                        'message' => $result['message'] ?? 'Ошибка при отправке сообщения'
                    ]));
                    return;
                }
                
                // Получаем созданное сообщение
                $messageModelObj = new \OGAS\Models\SupportMessage();
                $messages = $messageModelObj->findByTicketId($chatId);
                $lastMessage = end($messages);
                
                if ($lastMessage) {
                    // Создаем объект-обертку для совместимости
                    $messageModel = new class($lastMessage) {
                        private $data;
                        public function __construct($data) { $this->data = $data; }
                        public function getId() { return $this->data['id']; }
                        public function getUserId() { return $this->data['user_id']; }
                        public function getMessage() { return $this->data['message']; }
                        public function getCreatedAt() { return $this->data['created_at']; }
                        public function getIsAdmin() { return (bool)$this->data['is_admin']; }
                    };
                } else {
                    $conn->send(json_encode([
                        'type' => 'error',
                        'message' => 'Не удалось получить созданное сообщение'
                    ]));
                    return;
                }
            } else {
                $this->log('WARNING', 'Попытка отправить сообщение в неизвестный тип чата', [
                    'connection_id' => $conn->resourceId ?? 'unknown',
                    'user_id' => $conn->userId,
                    'chat_type' => $chatType,
                    'chat_id' => $chatId
                ]);
                $conn->send(json_encode([
                    'type' => 'error',
                    'message' => 'Неизвестный тип чата'
                ]));
                return;
            }
            
            if (!$messageModel) {
                $conn->send(json_encode([
                    'type' => 'error',
                    'message' => 'Не удалось создать сообщение'
                ]));
                return;
            }
            
            // Формируем данные сообщения
            $userId = $messageModel->getUserId();
            $user = \OGAS\Models\User::findById($userId);
            $isAdmin = $chatType === 'support' ? $messageModel->getIsAdmin() : false;
            
            $messageData = [
                'type' => 'new_message',
                'chat_type' => $chatType,
                'chat_id' => $chatId,
                'message' => [
                    'id' => $messageModel->getId(),
                    'user_id' => $userId,
                    'user_name' => $user ? $user->getFullName() : 'Неизвестный',
                    'user_avatar_html' => $user ? $user->getAvatarHtml('small') : '<div class="message-avatar-placeholder">?</div>',
                    'message' => $messageModel->getMessage(),
                    'created_at' => $messageModel->getCreatedAt(),
                    'is_own' => false, // Для получателей всегда false
                    'is_admin' => $isAdmin
                ]
            ];
            
            // Добавляем статус транзакции, если есть
            if ($transactionStatus) {
                $messageData['transaction_status'] = $transactionStatus;
            }
            
            // Отправляем сообщение всем подписанным на чат (тем, кто открыл чат)
            $chatKey = "{$chatType}_{$chatId}";
            
            $this->log('INFO', 'Отправка сообщения через WebSocket', [
                'connection_id' => $conn->resourceId ?? 'unknown',
                'user_id' => $conn->userId,
                'chat_type' => $chatType,
                'chat_id' => $chatId,
                'message_id' => $messageModel->getId(),
                'message_length' => mb_strlen($message),
                'chat_key' => $chatKey
            ]);
            
            // Для support чатов отправляем ВСЕМ подписанным, включая отправителя
            if ($chatType === 'support') {
                // Отправляем всем подписанным на этот чат (включая отправителя)
                $this->broadcastToChat($chatKey, $messageData, null);
            } else {
                // Для других типов чатов исключаем отправителя
                $this->broadcastToChat($chatKey, $messageData, $conn->userId);
            }
            
            // Получаем всех участников чата
            $participants = [];
            if ($chatType === 'transaction' && isset($transaction)) {
                $participants = [$transaction->getSellerId(), $transaction->getBuyerId()];
            } elseif ($chatType === 'community') {
                $request = CommunityRequest::findById($chatId);
                if ($request) {
                    $participants = $request->getChatParticipants();
                }
            } elseif ($chatType === 'support') {
                // Для support получаем участников из обращения
                $ticketModel = new \OGAS\Models\Ticket();
                $ticket = $ticketModel->findById($chatId);
                if ($ticket) {
                    $participants = [$ticket['user_id']];
                    // Добавляем администратора, если обращение назначено
                    if ($ticket['assigned_to']) {
                        $participants[] = $ticket['assigned_to'];
                    }
                }
            }
            
            // Отправляем сообщение ВСЕМ участникам чата (независимо от подписки)
            // Это гарантирует, что все участники получат сообщения в реальном времени
            // и обновят последнее сообщение в списке чатов
            foreach ($participants as $participantId) {
                
                if (isset($this->users[$participantId]) && !empty($this->users[$participantId])) {
                    // Создаем сообщение для отправки
                    $messageForParticipant = [
                        'type' => 'new_message',
                        'chat_type' => $chatType,
                        'chat_id' => $chatId,
                        'message' => $messageData['message']
                    ];
                    // Определяем, свое ли сообщение для получателя
                    $messageForParticipant['message']['is_own'] = ($participantId == $conn->userId);
                    
                    $messageJson = json_encode($messageForParticipant, JSON_UNESCAPED_UNICODE);
                    
                    // Отправляем всем соединениям пользователя
                    foreach ($this->users[$participantId] as $userConn) {
                        try {
                            if ($userConn instanceof ConnectionInterface) {
                                $userConn->send($messageJson);
                            }
                        } catch (\Exception $e) {
                        }
                    }
                } else {
                }
            }
            
            // Обновляем счетчики непрочитанных для всех участников чата
            if ($chatType === 'transaction' && isset($transaction)) {
                $this->notifyUserUnreadCounts($transaction->getSellerId());
                $this->notifyUserUnreadCounts($transaction->getBuyerId());
            } elseif ($chatType === 'community') {
                $request = CommunityRequest::findById($chatId);
                if ($request) {
                    // Получаем всех участников общины и обновляем счетчики
                    $participants = $request->getChatParticipants();
                    foreach ($participants as $participantId) {
                        if ($participantId != $conn->userId) {
                            $this->notifyUserUnreadCounts($participantId);
                        }
                    }
                }
            }
            
        } catch (\Exception $e) {
            $this->log('ERROR', 'Ошибка при отправке сообщения', [
                'connection_id' => $conn->resourceId ?? 'unknown',
                'user_id' => $conn->userId,
                'chat_type' => $chatType ?? null,
                'chat_id' => $chatId ?? null,
                'error' => $e->getMessage(),
                'trace' => $e->getTraceAsString()
            ]);
            
            $conn->send(json_encode([
                'type' => 'error',
                'message' => 'Ошибка отправки сообщения: ' . $e->getMessage()
            ]));
        }
    }
    
    /**
     * Обработка отметки сообщений как прочитанных
     */
    private function handleMarkAsRead(ConnectionInterface $conn, array $data) {
        if (!isset($conn->userId) || !isset($conn->authenticated) || !$conn->authenticated) {
            $conn->send(json_encode([
                'type' => 'error',
                'message' => 'Требуется аутентификация'
            ], JSON_UNESCAPED_UNICODE));
            return;
        }
        
        $chatType = $data['chat_type'] ?? null;
        $chatId = isset($data['chat_id']) ? (int)$data['chat_id'] : null;
        
        if (!$chatType || !$chatId) {
            $conn->send(json_encode([
                'type' => 'error',
                'message' => 'Не указан тип чата или ID'
            ]));
            return;
        }
        
        try {
            // Отмечаем как прочитанное
            if ($chatType === 'transaction') {
                if (!ChatService::checkAccess($chatId, $conn->userId)) {
                    throw new \Exception('Access denied');
                }
                ChatService::markAsRead($chatId, $conn->userId);
            } elseif ($chatType === 'community') {
                if (!ChatService::checkCommunityAccess($chatId, $conn->userId)) {
                    throw new \Exception('Access denied');
                }
                ChatService::markCommunityAsRead($chatId, $conn->userId);
            } else {
                $conn->send(json_encode([
                    'type' => 'error',
                    'message' => 'Неизвестный тип чата'
                ]));
                return;
            }
            
            $this->log('INFO', 'Сообщения отмечены как прочитанные', [
                'connection_id' => $conn->resourceId ?? 'unknown',
                'user_id' => $conn->userId,
                'chat_type' => $chatType,
                'chat_id' => $chatId
            ]);
            
            // Отправляем подтверждение
            $conn->send(json_encode([
                'type' => 'mark_read_success',
                'chat_type' => $chatType,
                'chat_id' => $chatId
            ]));
            
            // Обновляем счетчики непрочитанных для пользователя
            $this->notifyUserUnreadCounts($conn->userId);
            
            
        } catch (\Exception $e) {
            $this->log('ERROR', 'Ошибка при отметке как прочитанное', [
                'connection_id' => $conn->resourceId ?? 'unknown',
                'user_id' => $conn->userId,
                'chat_type' => $chatType,
                'chat_id' => $chatId,
                'error' => $e->getMessage()
            ]);
            $conn->send(json_encode([
                'type' => 'error',
                'message' => 'Ошибка отметки как прочитанного: ' . $e->getMessage()
            ]));
        }
    }
    
    /**
     * Обработка подтверждения транзакции
     */
    private function handleConfirmTransaction(ConnectionInterface $conn, array $data) {
        if (!isset($conn->userId) || !isset($conn->authenticated) || !$conn->authenticated) {
            $conn->send(json_encode([
                'type' => 'error',
                'message' => 'Требуется аутентификация'
            ], JSON_UNESCAPED_UNICODE));
            return;
        }
        
        // Поддерживаем оба варианта: transaction_id (от клиента) и chat_id (для обратной совместимости)
        $transactionId = isset($data['transaction_id']) ? (int)$data['transaction_id'] : (isset($data['chat_id']) ? (int)$data['chat_id'] : null);
        
        if (!$transactionId) {
            $conn->send(json_encode([
                'type' => 'error',
                'message' => 'Не указан ID транзакции (transaction_id или chat_id)'
            ]));
            return;
        }
        
        try {
            // Проверяем доступ
            $transaction = Transaction::findById($transactionId);
            if (!$transaction) {
                $this->log('WARNING', 'Транзакция не найдена при подтверждении', [
                    'connection_id' => $conn->resourceId ?? 'unknown',
                    'user_id' => $conn->userId,
                    'transaction_id' => $transactionId
                ]);
                throw new \Exception('Транзакция не найдена');
            }
            
            if (!ChatService::checkAccess($transactionId, $conn->userId)) {
                $this->log('WARNING', 'Попытка подтверждения транзакции без доступа', [
                    'connection_id' => $conn->resourceId ?? 'unknown',
                    'user_id' => $conn->userId,
                    'transaction_id' => $transactionId,
                    'transaction_seller_id' => $transaction->getSellerId(),
                    'transaction_buyer_id' => $transaction->getBuyerId(),
                    'user_is_seller' => ($transaction->getSellerId() === $conn->userId),
                    'user_is_buyer' => ($transaction->getBuyerId() === $conn->userId)
                ]);
                throw new \Exception('Access denied. Вы не являетесь участником этой транзакции.');
            }
            
            // Подтверждаем транзакцию
            if (!ChatService::confirmTransaction($transactionId, $conn->userId)) {
                throw new \Exception('Не удалось подтвердить транзакцию');
            }
            
            // Получаем обновленную информацию о транзакции
            $transaction = Transaction::findById($transactionId);
            if (!$transaction) {
                throw new \Exception('Транзакция не найдена');
            }
            
            $transactionStatus = [
                'seller_confirmed' => $transaction->isSellerConfirmed(),
                'buyer_confirmed' => $transaction->isBuyerConfirmed(),
                'both_confirmed' => $transaction->isBothConfirmed(),
                'status' => $transaction->getStatus()
            ];
            
            $this->log('INFO', 'Транзакция подтверждена через WebSocket', [
                'connection_id' => $conn->resourceId ?? 'unknown',
                'user_id' => $conn->userId,
                'transaction_id' => $transactionId,
                'seller_confirmed' => $transactionStatus['seller_confirmed'],
                'buyer_confirmed' => $transactionStatus['buyer_confirmed'],
                'both_confirmed' => $transactionStatus['both_confirmed']
            ]);
            
            // Отправляем подтверждение отправителю
            $conn->send(json_encode([
                'type' => 'transaction_confirmed',
                'transaction_id' => $transactionId,
                'transaction_status' => $transactionStatus,
                'community_request_id' => $transaction->getCommunityRequestId(), // Добавляем ID общины для клиента
                'message' => 'Сделка подтверждена!'
            ], JSON_UNESCAPED_UNICODE));
            
            // Получаем участников транзакции
            $participants = [$transaction->getSellerId(), $transaction->getBuyerId()];
            
            // Если транзакция связана с общиной, добавляем всех участников общины
            if ($transaction->getCommunityRequestId()) {
                $communityRequest = \OGAS\Models\CommunityRequest::findById($transaction->getCommunityRequestId());
                if ($communityRequest) {
                    $communityParticipants = $communityRequest->getChatParticipants();
                    $participants = array_unique(array_merge($participants, $communityParticipants));
                }
            }
            
            // Отправляем обновление статуса всем участникам транзакции (и общины, если применимо)
            foreach ($participants as $participantId) {
                if (isset($this->users[$participantId]) && !empty($this->users[$participantId])) {
                    $statusMessage = json_encode([
                        'type' => 'transaction_status_updated',
                        'transaction_id' => $transactionId,
                        'transaction_status' => $transactionStatus,
                        'community_request_id' => $transaction->getCommunityRequestId() // Добавляем ID общины для клиента
                    ], JSON_UNESCAPED_UNICODE);
                    
                    foreach ($this->users[$participantId] as $userConn) {
                        try {
                            if ($userConn instanceof ConnectionInterface && $userConn !== $conn) {
                                $userConn->send($statusMessage);
                            }
                        } catch (\Exception $e) {
                        }
                    }
                }
            }
            
            // Отправляем сообщение о подтверждении через WebSocket (оно уже было добавлено в БД)
            // Нужно отправить его всем участникам
            $messages = ChatService::getMessages($transactionId, 1); // Получаем последнее сообщение
            if (!empty($messages)) {
                $lastMessage = end($messages);
                $messageUser = \OGAS\Models\User::findById($lastMessage->getUserId());
                
                $messageData = [
                    'type' => 'new_message',
                    'chat_type' => 'transaction',
                    'chat_id' => $transactionId,
                    'message' => [
                        'id' => $lastMessage->getId(),
                        'user_id' => $lastMessage->getUserId(),
                        'user_name' => $messageUser ? $messageUser->getFullName() : 'Неизвестный',
                        'user_avatar_html' => $messageUser ? $messageUser->getAvatarHtml('small') : '<div class="message-avatar-placeholder">?</div>',
                        'message' => $lastMessage->getMessage(),
                        'created_at' => $lastMessage->getCreatedAt(),
                        'is_own' => false
                    ],
                    'transaction_status' => $transactionStatus
                ];
                
                foreach ($participants as $participantId) {
                    if (isset($this->users[$participantId]) && !empty($this->users[$participantId])) {
                        $messageData['message']['is_own'] = ($participantId == $conn->userId);
                        $messageJson = json_encode($messageData, JSON_UNESCAPED_UNICODE);
                        
                        foreach ($this->users[$participantId] as $userConn) {
                            try {
                                if ($userConn instanceof ConnectionInterface) {
                                    $userConn->send($messageJson);
                                }
                            } catch (\Exception $e) {
                            }
                        }
                    }
                }
            }
            
            // Обновляем счетчики непрочитанных для всех участников
            foreach ($participants as $participantId) {
                $this->notifyUserUnreadCounts($participantId);
            }
            
            
        } catch (\Exception $e) {
            $this->log('ERROR', 'Ошибка при подтверждении транзакции', [
                'connection_id' => $conn->resourceId ?? 'unknown',
                'user_id' => $conn->userId,
                'transaction_id' => $transactionId ?? null,
                'error' => $e->getMessage(),
                'trace' => $e->getTraceAsString()
            ]);
            
            $conn->send(json_encode([
                'type' => 'error',
                'message' => 'Ошибка подтверждения транзакции: ' . $e->getMessage()
            ]));
        }
    }
    
    /**
     * Отправить сообщение всем подписанным на чат (кроме отправителя)
     */
    private function broadcastToChat(string $chatKey, array $messageData, int $excludeUserId = null) {
        if (!isset($this->chatSubscriptions[$chatKey])) {
            $this->log('WARNING', 'Нет подписок на чат', [
                'chat_key' => $chatKey,
                'total_subscriptions' => count($this->chatSubscriptions)
            ]);
            return;
        }
        
        $message = json_encode($messageData, JSON_UNESCAPED_UNICODE);
        $sentCount = 0;
        $errorCount = 0;
        
        $this->log('DEBUG', 'Начало рассылки сообщения', [
            'chat_key' => $chatKey,
            'subscribers_count' => count($this->chatSubscriptions[$chatKey]),
            'exclude_user_id' => $excludeUserId
        ]);
        
        foreach ($this->chatSubscriptions[$chatKey] as $index => $conn) {
            try {
                // Пропускаем отправителя только если указан excludeUserId
                if ($excludeUserId !== null && isset($conn->userId) && $conn->userId === $excludeUserId) {
                    $this->log('DEBUG', 'Пропуск отправителя', [
                        'chat_key' => $chatKey,
                        'user_id' => $conn->userId
                    ]);
                    continue;
                }
                
                if ($conn instanceof ConnectionInterface) {
                    $conn->send($message);
                    $sentCount++;
                    $this->log('DEBUG', 'Сообщение отправлено подписчику', [
                        'chat_key' => $chatKey,
                        'connection_id' => $conn->resourceId ?? 'unknown',
                        'user_id' => $conn->userId ?? 'unknown'
                    ]);
                } else {
                    // Удаляем невалидное соединение
                    unset($this->chatSubscriptions[$chatKey][$index]);
                    $errorCount++;
                }
            } catch (\Exception $e) {
                // Удаляем проблемное соединение
                unset($this->chatSubscriptions[$chatKey][$index]);
                $errorCount++;
                
                $this->log('WARNING', 'Ошибка при отправке сообщения в чат', [
                    'chat_key' => $chatKey,
                    'error' => $e->getMessage()
                ]);
            }
        }
        
        // Логируем статистику рассылки
        $this->log('INFO', 'Рассылка сообщения в чат завершена', [
            'chat_key' => $chatKey,
            'sent' => $sentCount,
            'errors' => $errorCount,
            'exclude_user_id' => $excludeUserId
        ]);
        
        // Очищаем массив от null значений
        if (isset($this->chatSubscriptions[$chatKey])) {
            $this->chatSubscriptions[$chatKey] = array_values(
                array_filter($this->chatSubscriptions[$chatKey])
            );
        }
    }
    
    /**
     * Уведомить участников транзакции о создании векселя
     * 
     * @param int $transactionId ID транзакции
     * @param int $billId ID созданного векселя
     * @param int $issuerId ID эмитента векселя
     * @param int $holderId ID держателя векселя
     * @param float $nominal Номинал векселя
     * @param string $maturityDate Дата погашения
     */
    public function notifyBillCreated(int $transactionId, int $billId, int $issuerId, int $holderId, float $nominal, string $maturityDate): void
    {
        try {
            // Получаем информацию о транзакции
            $transaction = Transaction::findById($transactionId);
            if (!$transaction) {
                $this->log('WARNING', 'Транзакция не найдена для уведомления о векселе', [
                    'transaction_id' => $transactionId
                ]);
                return;
            }
            
            // Получаем участников транзакции
            $participants = [$transaction->getSellerId(), $transaction->getBuyerId()];
            
            // Получаем информацию о пользователях
            $issuer = \OGAS\Models\User::findById($issuerId);
            $holder = \OGAS\Models\User::findById($holderId);
            
            // Формируем сообщение о создании векселя
            $billMessage = [
                'type' => 'bill_created',
                'transaction_id' => $transactionId,
                'bill' => [
                    'id' => $billId,
                    'issuer_id' => $issuerId,
                    'issuer_name' => $issuer ? $issuer->getFullName() : 'Неизвестный',
                    'holder_id' => $holderId,
                    'holder_name' => $holder ? $holder->getFullName() : 'Неизвестный',
                    'nominal' => $nominal,
                    'maturity_date' => $maturityDate,
                    'pdf_url' => '/api/bill_pdf.php?id=' . $billId
                ]
            ];
            
            $messageJson = json_encode($billMessage, JSON_UNESCAPED_UNICODE);
            
            // Отправляем уведомление всем участникам транзакции
            foreach ($participants as $participantId) {
                if (isset($this->users[$participantId]) && !empty($this->users[$participantId])) {
                    foreach ($this->users[$participantId] as $userConn) {
                        try {
                            if ($userConn instanceof ConnectionInterface) {
                                $userConn->send($messageJson);
                                
                                $this->log('DEBUG', 'Уведомление о создании векселя отправлено', [
                                    'connection_id' => $userConn->resourceId ?? 'unknown',
                                    'user_id' => $participantId,
                                    'transaction_id' => $transactionId,
                                    'bill_id' => $billId
                                ]);
                            }
                        } catch (\Exception $e) {
                            $this->log('WARNING', 'Ошибка отправки уведомления о векселе', [
                                'user_id' => $participantId,
                                'error' => $e->getMessage()
                            ]);
                        }
                    }
                }
            }
            
            // Также отправляем в чат транзакции (для тех, кто подписан на чат)
            $chatKey = "transaction_{$transactionId}";
            if (isset($this->chatSubscriptions[$chatKey])) {
                $this->broadcastToChat($chatKey, $billMessage, null);
            }
            
            $this->log('INFO', 'Уведомление о создании векселя отправлено участникам', [
                'transaction_id' => $transactionId,
                'bill_id' => $billId,
                'participants_count' => count($participants)
            ]);
            
        } catch (\Exception $e) {
            $this->log('ERROR', 'Ошибка при отправке уведомления о создании векселя', [
                'transaction_id' => $transactionId,
                'bill_id' => $billId,
                'error' => $e->getMessage(),
                'trace' => $e->getTraceAsString()
            ]);
        }
    }
    
    /**
     * Отключение клиента
     */
    public function onClose(ConnectionInterface $conn) {
        $userId = $conn->userId ?? null;
        $connId = $conn->resourceId;
        
        $this->log('INFO', 'Клиент отключился', [
            'connection_id' => $connId ?? 'unknown',
            'user_id' => $userId,
            'remote_address' => $conn->remoteAddress ?? 'unknown'
        ]);
        
        // Удаляем из всех подписок на чаты
        if (isset($this->connectionSubscriptions[$connId])) {
            foreach ($this->connectionSubscriptions[$connId] as $chatKey) {
                if (isset($this->chatSubscriptions[$chatKey])) {
                    $this->chatSubscriptions[$chatKey] = array_filter(
                        $this->chatSubscriptions[$chatKey],
                        function($c) use ($conn) {
                            return $c !== $conn;
                        }
                    );
                    $this->chatSubscriptions[$chatKey] = array_values($this->chatSubscriptions[$chatKey]);
                    
                    // Если подписок не осталось, удаляем чат
                    if (empty($this->chatSubscriptions[$chatKey])) {
                        unset($this->chatSubscriptions[$chatKey]);
                    }
                }
            }
            // Удаляем запись о подписках соединения
            unset($this->connectionSubscriptions[$connId]);
        }
        
        $this->clients->detach($conn);
        
        if (isset($conn->userId)) {
            $userId = $conn->userId;
            
            // Удаляем соединение из списка пользователя
            if (isset($this->users[$userId])) {
                $this->users[$userId] = array_filter($this->users[$userId], function($c) use ($conn) {
                    return $c !== $conn;
                });
                
                // Очищаем массив
                $this->users[$userId] = array_values($this->users[$userId]);
                
                // Если у пользователя не осталось соединений, удаляем его
                if (empty($this->users[$userId])) {
                    unset($this->users[$userId]);
                }
            }
            
        } else {
        }
    }
    
    /**
     * Ошибка соединения
     */
    public function onError(ConnectionInterface $conn, \Exception $e) {
        $this->log('ERROR', 'Ошибка WebSocket соединения', [
            'connection_id' => $conn->resourceId ?? 'unknown',
            'user_id' => $conn->userId ?? null,
            'remote_address' => $conn->remoteAddress ?? 'unknown',
            'error' => $e->getMessage(),
            'file' => $e->getFile(),
            'line' => $e->getLine(),
            'trace' => $e->getTraceAsString()
        ]);
        
        $conn->close();
    }
}

// Создаем обработчик
$handler = new ChatUnreadCountsHandler();

// Настройка SSL (опционально)
$useSSL = false;
$sslCertPath = null;
$sslKeyPath = null;

// Пытаемся определить пути к SSL сертификатам Open Server
// Пути: %sprogdir%/userdata/config/cert_files/%host%.pem
// Где %sprogdir% - путь к Open Server, %host% - имя хоста

// Определяем путь к Open Server
$possiblePaths = ['F:\\OpenServer', 'C:\\OpenServer', 'D:\\OpenServer'];
$openServerPath = null;

foreach ($possiblePaths as $path) {
    if (is_dir($path)) {
        $openServerPath = $path;
        break;
    }
}

// Определяем имя хоста из параметров командной строки, переменных окружения или конфигурации
$hostname = null;

// 1. Проверяем параметр командной строки: php chat-server.php --host=mysuite.mercusysddns.com
if (isset($argv)) {
    foreach ($argv as $arg) {
        if (strpos($arg, '--host=') === 0) {
            $hostname = substr($arg, 7);
            break;
        }
    }
}

// 2. Если не задан через параметр, проверяем переменные окружения
if (empty($hostname)) {
    if (isset($_SERVER['SERVER_NAME']) && !empty($_SERVER['SERVER_NAME'])) {
        $hostname = $_SERVER['SERVER_NAME'];
    } elseif (isset($_ENV['APP_HOST']) && !empty($_ENV['APP_HOST'])) {
        $hostname = $_ENV['APP_HOST'];
    } else {
        // 3. Читаем из .env файла
        $envFile = __DIR__ . '/../.env';
        if (file_exists($envFile)) {
            $envContent = file_get_contents($envFile);
            if (preg_match('/APP_HOST=(.+)/', $envContent, $matches)) {
                $hostname = trim($matches[1]);
            }
        }
    }
}

// 4. Если все еще не определен, используем домен по умолчанию
if (empty($hostname)) {
    $hostname = 'mysuite.mercusysddns.com';
}


// Если путь к Open Server найден, проверяем наличие сертификатов
if ($openServerPath) {
    $certPath = str_replace('\\', DIRECTORY_SEPARATOR, $openServerPath . '/userdata/config/cert_files/' . $hostname . '.pem');
    $keyPath = str_replace('\\', DIRECTORY_SEPARATOR, $openServerPath . '/userdata/config/cert_files/' . $hostname . '-key.pem');
    
    // Также проверяем альтернативные пути (без расширения .pem)
    $certPathAlt = str_replace('\\', DIRECTORY_SEPARATOR, $openServerPath . '/userdata/config/cert_files/' . $hostname . '.crt');
    $keyPathAlt = str_replace('\\', DIRECTORY_SEPARATOR, $openServerPath . '/userdata/config/cert_files/' . $hostname . '.key');
    
    if (file_exists($certPath) && file_exists($keyPath)) {
        $useSSL = true;
        $sslCertPath = $certPath;
        $sslKeyPath = $keyPath;
    } elseif (file_exists($certPathAlt) && file_exists($keyPathAlt)) {
        $useSSL = true;
        $sslCertPath = $certPathAlt;
        $sslKeyPath = $keyPathAlt;
    }
}

// Получаем или создаем event loop (нужен для периодических задач)
$loop = Loop::get();

// Создаем WebSocket сервер
if ($useSSL && $sslCertPath && $sslKeyPath) {
    // Проверяем наличие react/socket
    if (!class_exists('React\Socket\SecureServer')) {
        die("ERROR: Для SSL необходимо установить react/socket. Выполните: composer require react/socket\n");
    }
    
    try {
        // Создаем SSL контекст в виде массива (для SecureServer)
        $sslContext = [
            'local_cert' => $sslCertPath,
            'local_pk' => $sslKeyPath,
            'verify_peer' => false, // Для самоподписанных сертификатов
            'allow_self_signed' => true,
            'verify_peer_name' => false,
        ];
        
        // Создаем обычный socket сервер (без SSL) с явным event loop
        $socket = new Reactor('0.0.0.0:8082', $loop);
        
        // Оборачиваем в SSL, передавая массив контекста как третий параметр
        $secureSocket = new SecureServer($socket, $loop, $sslContext);
        
        // Создаем WebSocket сервер с SSL
        $server = new IoServer(
            new HttpServer(
                new WsServer($handler)
            ),
            $secureSocket,
            $loop
        );
        
    } catch (\Exception $e) {
        $handler->log('ERROR', 'Ошибка при создании SSL соединения', [
            'error' => $e->getMessage(),
            'trace' => $e->getTraceAsString()
        ]);
        $useSSL = false;
    }
}

// Если SSL не используется или произошла ошибка, создаем обычный сервер
if (!$useSSL) {
    $server = IoServer::factory(
        new HttpServer(
            new WsServer($handler)
        ),
        8082, // Порт для WebSocket
        '0.0.0.0' // Слушаем на всех интерфейсах
    );
    // Получаем loop из созданного сервера
    $loop = $server->loop;
}

// Периодическая проверка изменений счетчиков (каждые 3 секунды)
// Это обеспечивает обновление даже если сообщения были отправлены напрямую в БД
$loop->addPeriodicTimer(3, function() use ($handler) {
    try {
        // Получаем всех подключенных пользователей через метод
        $users = $handler->getConnectedUsers();
        
        if (!empty($users)) {
            $handler->log('DEBUG', 'Периодическое обновление счетчиков', [
                'users_count' => count($users)
            ]);
            
            // Обновляем счетчики для всех подключенных пользователей
            foreach ($users as $userId) {
                $handler->notifyUserUnreadCounts($userId);
            }
        }
    } catch (\Exception $e) {
        $handler->log('ERROR', 'Ошибка в периодическом обновлении счетчиков', [
            'error' => $e->getMessage(),
            'trace' => $e->getTraceAsString()
        ]);
    }
});

// Периодическая проверка файловых триггеров для уведомлений о создании векселей (каждые 2 секунды)
$triggersDir = __DIR__ . '/../storage/websocket-triggers';
$loop->addPeriodicTimer(2, function() use ($handler, $triggersDir) {
    try {
        if (!is_dir($triggersDir)) {
            return;
        }
        
        // Получаем все файлы-триггеры
        $triggerFiles = glob($triggersDir . '/bill_created_*.json');
        
        if (empty($triggerFiles)) {
            return;
        }
        
        foreach ($triggerFiles as $triggerFile) {
            try {
                // Читаем данные триггера
                $triggerData = @json_decode(file_get_contents($triggerFile), true);
                
                if (!$triggerData || !isset($triggerData['type']) || $triggerData['type'] !== 'bill_created') {
                    // Удаляем невалидный файл
                    @unlink($triggerFile);
                    continue;
                }
                
                // Отправляем уведомление о создании векселя
                $handler->notifyBillCreated(
                    (int)$triggerData['transaction_id'],
                    (int)$triggerData['bill_id'],
                    (int)$triggerData['issuer_id'],
                    (int)$triggerData['holder_id'],
                    (float)$triggerData['nominal'],
                    $triggerData['maturity_date']
                );
                
                // Удаляем обработанный файл-триггер
                @unlink($triggerFile);
                
                $handler->log('INFO', 'Обработан файловый триггер о создании векселя', [
                    'transaction_id' => $triggerData['transaction_id'],
                    'bill_id' => $triggerData['bill_id']
                ]);
                
            } catch (\Exception $e) {
                // Логируем ошибку и удаляем проблемный файл
                $handler->log('ERROR', 'Ошибка обработки файлового триггера', [
                    'file' => basename($triggerFile),
                    'error' => $e->getMessage()
                ]);
                @unlink($triggerFile);
            }
        }
    } catch (\Exception $e) {
        $handler->log('ERROR', 'Ошибка в периодической проверке триггеров', [
            'error' => $e->getMessage()
        ]);
    }
});

// Примечание: UDP сервер отключен, так как React Event Loop не поддерживает
// объекты Socket из расширения sockets в PHP 8.0+. 
// Вместо этого используется периодическая проверка изменений (каждые 3 секунды).
// Это обеспечивает обновление счетчиков даже без UDP уведомлений.
// 
// Для мгновенных обновлений можно использовать:
// - Файловую систему (watch файл)
// - Redis pub/sub
// - Или увеличить частоту проверки

// Логируем запуск сервера
$handler->log('INFO', 'WebSocket сервер запущен', [
    'ssl_enabled' => $useSSL,
    'port' => 8082,
    'host' => $hostname ?? 'localhost',
    'url' => $useSSL ? "wss://{$hostname}:8082" : "ws://{$hostname}:8082"
]);

echo "\n";
echo "========================================\n";
echo "WebSocket сервер запущен!\n";
echo "========================================\n";
if ($useSSL) {
    echo "WebSocket: wss://localhost:8082 (SSL включен)\n";
} else {
    echo "WebSocket: ws://localhost:8082 (без SSL)\n";
}
echo "Нажмите Ctrl+C для остановки\n";
echo "========================================\n\n";

// Запускаем сервер
$server->run();
