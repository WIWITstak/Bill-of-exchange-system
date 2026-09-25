<?php

namespace OGAS\Services;

use OGAS\Models\Message;
use OGAS\Models\Transaction;
use OGAS\Models\CommunityRequest;
use OGAS\Models\User;
use OGAS\Models\Bill;
use OGAS\Services\BillService;
use OGAS\Services\NotificationService;
use OGAS\Services\SubscriptionService;

/**
 * Сервис для работы с чатом транзакций
 */
class ChatService
{
    /**
     * Отправить сообщение в чат транзакции
     */
    public static function sendMessage(int $transactionId, int $userId, string $message): Message
    {
        // Проверяем, что пользователь участвует в транзакции
        $transaction = Transaction::findById($transactionId);
        if (!$transaction) {
            throw new \Exception('Транзакция не найдена');
        }
        
        if ($transaction->getSellerId() !== $userId && $transaction->getBuyerId() !== $userId) {
            throw new \Exception('Вы не являетесь участником этой транзакции');
        }
        
        // Создаём сообщение
        $messageModel = Message::create([
            'transaction_id' => $transactionId,
            'user_id' => $userId,
            'message' => trim($message)
        ]);
        
        // Определяем получателя уведомления
        $recipientId = $transaction->getSellerId() === $userId 
            ? $transaction->getBuyerId() 
            : $transaction->getSellerId();
        
        // Отправляем уведомление получателю (если это не он сам)
        if ($recipientId !== $userId) {
            NotificationService::createNotification(
                $recipientId,
                'chat_message',
                'Новое сообщение в транзакции',
                'Вам пришло новое сообщение в транзакции #' . $transactionId,
                $transactionId,
                'transaction'
            );
            
            // Отправляем обновление счетчиков через WebSocket
            if (class_exists('\OGAS\Services\WebSocketService')) {
                \OGAS\Services\WebSocketService::notifyUserUnreadCounts($recipientId);
            }
        }
        
        return $messageModel;
    }
    
    /**
     * Получить сообщения транзакции
     */
    public static function getMessages(int $transactionId, ?int $limit = null, ?int $offset = null): array
    {
        return Message::findByTransaction($transactionId, $limit, $offset);
    }
    
    /**
     * Получить новые сообщения (после указанной даты)
     */
    public static function getNewMessages(int $transactionId, string $afterDate): array
    {
        return Message::findNewByTransaction($transactionId, $afterDate);
    }
    
    /**
     * Отметить сообщения как прочитанные
     */
    public static function markAsRead(int $transactionId, int $userId): bool
    {
        $result = Message::markAsReadByTransaction($transactionId, $userId);
        
        // Отправляем обновление счетчиков через WebSocket
        if ($result && class_exists('\OGAS\Services\WebSocketService')) {
            \OGAS\Services\WebSocketService::notifyUserUnreadCounts($userId);
        }
        
        return $result;
    }
    
    /**
     * Подтвердить транзакцию (продавец или покупатель)
     */
    public static function confirmTransaction(int $transactionId, int $userId): bool
    {
        $transaction = Transaction::findById($transactionId);
        if (!$transaction) {
            throw new \Exception('Транзакция не найдена');
        }
        
        // Определяем, кто подтверждает
        if ($transaction->getSellerId() === $userId) {
            $transaction->setSellerConfirmed(true);
        } elseif ($transaction->getBuyerId() === $userId) {
            $transaction->setBuyerConfirmed(true);
        } else {
            throw new \Exception('Вы не являетесь участником этой транзакции');
        }
        
        // Сохраняем подтверждение
        if (!$transaction->save()) {
            return false;
        }
        
        // Отправляем сообщение в чат о подтверждении
        $user = User::findById($userId);
        $messageText = sprintf(
            "✅ %s подтвердил(а) сделку",
            $user->getFullName()
        );
        
        Message::create([
            'transaction_id' => $transactionId,
            'user_id' => $userId,
            'message' => $messageText
        ]);
        
        // Если оба подтвердили, автоматически создаём вексель (если ещё не создан)
        if ($transaction->isBothConfirmed() && $transaction->getStatus() === 'pending') {
            self::activateTransactionAfterConfirmation($transactionId);
        }
        
        return true;
    }
    
    /**
     * Активировать транзакцию после подтверждения обоими участниками
     * Вексель создаётся автоматически при наличии параметров
     * Если это транзакция активации с системным пользователем - активируется аккаунт
     */
    private static function activateTransactionAfterConfirmation(int $transactionId): void
    {
        $transaction = Transaction::findById($transactionId);
        if (!$transaction || !$transaction->isBothConfirmed()) {
            return;
        }
        
        // Проверяем, является ли это транзакцией активации (с системным пользователем)
        $systemUser = User::getOrCreateSystemUser();
        $isActivationTransaction = ($transaction->getSellerId() === $systemUser->getId() || 
                                    $transaction->getBuyerId() === $systemUser->getId());
        
        // Определяем пользователя для активации (не системного)
        $userToActivate = null;
        if ($isActivationTransaction) {
            if ($transaction->getSellerId() === $systemUser->getId()) {
                $userToActivate = User::findById($transaction->getBuyerId());
            } else {
                $userToActivate = User::findById($transaction->getSellerId());
            }
        }
        
        // Активируем аккаунт пользователя, если это транзакция активации
        $subscription = null;
        $subscriptionAmount = 0;
        $bill = null;
        if ($isActivationTransaction && $userToActivate && !$userToActivate->isActive()) {
            try {
                $subscriptionAmount = SubscriptionService::calculateAmount($userToActivate);
                
                // ОГАС собирает подписку в виде векселей: пользователь выписывает вексель в пользу ОГАС
                // Пользователь - issuer (эмитент векселя), ОГАС - holder (держатель векселя)
                $bill = BillService::issue(
                    $userToActivate->getId(),  // issuer - пользователь выписывает вексель
                    $systemUser->getId(),      // holder - ОГАС получает вексель
                    $subscriptionAmount,       // сумма подписки (1 000 или 10 000 дублей)
                    30                         // срок погашения - 30 дней
                );
                
                // Создаём подписку, связанную с векселем
                $subscription = SubscriptionService::createSubscription($userToActivate, $bill->getId());
                
                // Активируем аккаунт только после успешного создания векселя и подписки
                $userToActivate->setActive(true);
                $userToActivate->save();
                
                // Создаём уведомление об активации с информацией о векселе
                NotificationService::createNotification(
                    $userToActivate->getId(),
                    'account_activated',
                    'Аккаунт активирован',
                    sprintf(
                        'Ваш аккаунт успешно активирован! Выпущен вексель на подписку на сумму %s дублей (срок погашения: 30 дней).',
                        number_format($subscriptionAmount, 2, '.', ' ')
                    ),
                    $bill->getId(),
                    'bill'
                );
            } catch (\Exception $e) {
                // Если не удалось создать вексель или подписку, логируем ошибку
                error_log('Ошибка при создании векселя подписки при активации: ' . $e->getMessage());
                $subscriptionAmount = SubscriptionService::calculateAmount($userToActivate);
                // Активируем аккаунт в любом случае
                $userToActivate->setActive(true);
                $userToActivate->save();
            }
        }
        
        // Меняем статус на 'active'
        $transaction->setStatus('active');
        $transaction->save();
        
        // Отправляем системное сообщение о подтверждении сделки
        $seller = User::findById($transaction->getSellerId());
        $buyer = User::findById($transaction->getBuyerId());
        
        if ($isActivationTransaction && $userToActivate) {
            // Используем информацию о подписке для сообщения
            if ($subscriptionAmount === 0) {
                $subscriptionAmount = SubscriptionService::calculateAmount($userToActivate);
            }
            
            $billInfo = '';
            if ($bill) {
                $billInfo = sprintf(
                    "\n📋 Выпущен вексель на подписку:\n" .
                    "• Сумма: %s дублей\n" .
                    "• Срок погашения: 30 дней\n" .
                    "• Номер векселя: #%d\n",
                    number_format($subscriptionAmount, 2, '.', ' '),
                    $bill->getId()
                );
            } else {
                $billInfo = sprintf(
                    "\n📋 Подписка: %s дублей на 30 дней\n",
                    number_format($subscriptionAmount, 2, '.', ' ')
                );
            }
            
            $systemMessage = sprintf(
                "✅ Сделка подтверждена обоими участниками!\nПродавец: %s\nПокупатель: %s\nСтатус: Активна\n\n" .
                "🎉 Поздравляем! Ваш аккаунт активирован!%s\n" .
                "Теперь вы можете использовать все функции системы ОГАС:\n" .
                "• Создавать транзакции с другими пользователями\n" .
                "• Выпускать и получать вексели\n" .
                "• Участвовать в системе взаимных гарантий",
                $seller->getFullName(),
                $buyer->getFullName(),
                $billInfo
            );
        } else {
            $systemMessage = sprintf(
                "✅ Сделка подтверждена обоими участниками!\nПродавец: %s\nПокупатель: %s\nСтатус: Активна",
                $seller->getFullName(),
                $buyer->getFullName()
            );
        }
        
        // Отправляем системное сообщение от продавца
        Message::create([
            'transaction_id' => $transactionId,
            'user_id' => $transaction->getSellerId(),
            'message' => $systemMessage
        ]);
        
        // Создаём уведомления
        NotificationService::notifyTransactionCompleted($transaction->getSellerId(), $transactionId);
        NotificationService::notifyTransactionCompleted($transaction->getBuyerId(), $transactionId);
        
        // Если это активация, отправляем специальное уведомление
        if ($isActivationTransaction && $userToActivate) {
            NotificationService::createNotification(
                $userToActivate->getId(),
                'account_activated',
                'Аккаунт активирован',
                'Ваш аккаунт успешно активирован! Теперь вы можете использовать все функции системы ОГАС.',
                $transactionId,
                'transaction'
            );
        }
    }
    
    /**
     * Создать вексель из чата (только после подтверждения обоими участниками)
     */
    public static function createBillFromChat(
        int $transactionId,
        int $issuerId,
        int $holderId,
        float $nominal,
        int $maturityDays,
        int $productId = null
    ): Bill {
        // Проверяем, что пользователи участвуют в транзакции
        $transaction = Transaction::findById($transactionId);
        if (!$transaction) {
            throw new \Exception('Транзакция не найдена');
        }
        
        // Проверяем, что оба участника подтвердили сделку
        if (!$transaction->isBothConfirmed()) {
            throw new \Exception('Для создания векселя оба участника должны подтвердить сделку');
        }
        
        if (($transaction->getSellerId() !== $issuerId && $transaction->getSellerId() !== $holderId) ||
            ($transaction->getBuyerId() !== $issuerId && $transaction->getBuyerId() !== $holderId)) {
            throw new \Exception('Указанные пользователи не являются участниками транзакции');
        }
        
        // Проверяем, что эмитент и держатель не являются одним и тем же пользователем
        if ($issuerId === $holderId) {
            throw new \Exception('Эмитент и держатель векселя не могут быть одним и тем же пользователем');
        }
        
        // Если передан ID товара, используем новую систему расчета цены
        if ($productId) {
            // Получаем товар
            $product = \OGAS\Models\Product::findById($productId);
            if (!$product) {
                throw new \Exception('Товар не найден');
            }

            // Получаем рейтинг пользователя, который будет держателем векселя (покупатель)
            $holderRating = \OGAS\Models\Rating::findByUserId($holderId);
            $holderRatingValue = $holderRating ? $holderRating->getTotalRating() : 1.0;

            // Рассчитываем номинал векселя по новой формуле: P = C/R
            // где P - цена в рублях (номинал векселя), C - цена в дублях, R - рейтинг пользователя
            $doublesPrice = $product->getDoublesPrice();
            $calculatedNominal = $doublesPrice / $holderRatingValue;

            // Используем рассчитанный номинал вместо переданного
            $nominal = $calculatedNominal;
        }

        // Проверяем валидность суммы
        if ($nominal <= 0) {
            throw new \Exception('Номинал векселя должен быть больше нуля');
        }

        // Проверяем валидность срока погашения
        if ($maturityDays < 1 || $maturityDays > 3650) { // Максимум 10 лет
            throw new \Exception('Срок погашения должен быть от 1 до 3650 дней');
        }

        // Проверяем, что транзакция в статусе pending или active
        if ($transaction->getStatus() === 'cancelled' || $transaction->getStatus() === 'completed') {
            throw new \Exception('Нельзя создать вексель для завершённой или отменённой транзакции');
        }

        // Создаём вексель
        $bill = BillService::issue($issuerId, $holderId, $nominal, $maturityDays);
        
        // Обновляем статус транзакции на 'active' если был 'pending'
        if ($transaction->getStatus() === 'pending') {
            $transaction->setStatus('active');
            $transaction->save();
        }
        
        // Отправляем сообщение о создании векселя
        $issuer = User::findById($issuerId);
        $holder = User::findById($holderId);
        
        // Генерируем PDF для векселя (если ещё не создан)
        try {
            \OGAS\Services\BillPdfService::savePdf($bill);
        } catch (\Exception $e) {
            error_log('Error generating PDF for bill #' . $bill->getId() . ': ' . $e->getMessage());
        }
        
        // Формируем ссылку на PDF векселя
        $pdfUrl = '/api/bill_pdf.php?id=' . $bill->getId();
        
        $systemMessage = sprintf(
            "✅ Вексель #%d создан!\n\n" .
            "📋 Информация о векселе:\n" .
            "• Эмитент: %s\n" .
            "• Держатель: %s\n" .
            "• Сумма: %s ₽\n" .
            "• Срок погашения: %s\n\n" .
            "📄 PDF векселя готов к скачиванию:\n" .
            "%s",
            $bill->getId(),
            $issuer->getFullName(),
            $holder->getFullName(),
            number_format($nominal, 2, '.', ' '),
            date('d.m.Y', strtotime($bill->getMaturityDate())),
            $pdfUrl
        );
        
        // Отправляем системное сообщение от эмитента (чтобы показать в чате)
        Message::create([
            'transaction_id' => $transactionId,
            'user_id' => $issuerId,
            'message' => $systemMessage
        ]);
        
        // Создаём уведомления
        NotificationService::notifyBillReceived($holderId, $bill->getId());
        
        // Уведомляем WebSocket сервер о создании векселя через файловый триггер
        self::notifyWebSocketBillCreated($transactionId, $bill->getId(), $issuerId, $holderId, $nominal, $bill->getMaturityDate());
        
        return $bill;
    }
    
    /**
     * Уведомить WebSocket сервер о создании векселя через файловый триггер
     * 
     * @param int $transactionId ID транзакции
     * @param int $billId ID созданного векселя
     * @param int $issuerId ID эмитента векселя
     * @param int $holderId ID держателя векселя
     * @param float $nominal Номинал векселя
     * @param string $maturityDate Дата погашения
     */
    private static function notifyWebSocketBillCreated(int $transactionId, int $billId, int $issuerId, int $holderId, float $nominal, string $maturityDate): void
    {
        try {
            // Создаем директорию для триггеров, если её нет
            $triggersDir = __DIR__ . '/../../storage/websocket-triggers';
            if (!is_dir($triggersDir)) {
                @mkdir($triggersDir, 0755, true);
            }
            
            // Создаем файл-триггер с информацией о созданном векселе
            $triggerFile = $triggersDir . '/bill_created_' . time() . '_' . uniqid() . '.json';
            $triggerData = [
                'type' => 'bill_created',
                'transaction_id' => $transactionId,
                'bill_id' => $billId,
                'issuer_id' => $issuerId,
                'holder_id' => $holderId,
                'nominal' => $nominal,
                'maturity_date' => $maturityDate,
                'created_at' => date('Y-m-d H:i:s')
            ];
            
            file_put_contents($triggerFile, json_encode($triggerData, JSON_UNESCAPED_UNICODE));
            
            // Устанавливаем права на файл
            @chmod($triggerFile, 0644);
            
        } catch (\Exception $e) {
            // Логируем ошибку, но не прерываем процесс
            error_log('Error creating WebSocket trigger for bill #' . $billId . ': ' . $e->getMessage());
        }
    }
    
    /**
     * Проверить доступ пользователя к чату транзакции
     */
    public static function checkAccess(int $transactionId, int $userId): bool
    {
        $transaction = Transaction::findById($transactionId);
        if (!$transaction) {
            error_log(sprintf(
                '[ChatService::checkAccess] Транзакция не найдена: transactionId=%d, userId=%d',
                $transactionId,
                $userId
            ));
            return false;
        }
        
        $sellerId = $transaction->getSellerId();
        $buyerId = $transaction->getBuyerId();
        $hasAccess = ($sellerId === $userId || $buyerId === $userId);
        
        if (!$hasAccess) {
            error_log(sprintf(
                '[ChatService::checkAccess] Доступ запрещён: transactionId=%d, userId=%d, sellerId=%d, buyerId=%d',
                $transactionId,
                $userId,
                $sellerId,
                $buyerId
            ));
        }
        
        return $hasAccess;
    }
    
    /**
     * Получить количество непрочитанных сообщений
     */
    public static function getUnreadCount(int $transactionId, int $userId): int
    {
        return Message::getUnreadCount($transactionId, $userId);
    }
    
    /**
     * Получить количество непрочитанных сообщений в групповом чате общины
     */
    public static function getCommunityUnreadCount(int $communityRequestId, int $userId): int
    {
        return Message::getUnreadCountByCommunityRequest($communityRequestId, $userId);
    }
    
    /**
     * Отправить сообщение в групповой чат заявки общины
     */
    public static function sendCommunityMessage(int $communityRequestId, int $userId, string $message): Message
    {
        $request = CommunityRequest::findById($communityRequestId);
        if (!$request) {
            throw new \Exception('Заявка общины не найдена');
        }
        
        // Проверяем доступ: участниками могут быть заявитель или поручители
        $participants = $request->getChatParticipants();
        if (!in_array($userId, $participants)) {
            throw new \Exception('Вы не являетесь участником этой заявки');
        }
        
        // Создаём сообщение для группового чата (transaction_id = NULL для групповых чатов)
        $messageModel = Message::create([
            'transaction_id' => null, // Для групповых чатов используется NULL
            'community_request_id' => $communityRequestId,
            'user_id' => $userId,
            'message' => trim($message)
        ]);
        
        // Отправляем уведомления всем остальным участникам
        foreach ($participants as $participantId) {
            if ($participantId !== $userId) {
                NotificationService::createNotification(
                    $participantId,
                    'community_chat_message',
                    'Новое сообщение в групповом чате общины',
                    'Вам пришло новое сообщение в заявке общины #' . $communityRequestId,
                    $communityRequestId,
                    'community'
                );
                
                // Отправляем обновление счетчиков через WebSocket
                if (class_exists('\OGAS\Services\WebSocketService')) {
                    \OGAS\Services\WebSocketService::notifyUserUnreadCounts($participantId);
                }
            }
        }
        
        return $messageModel;
    }
    
    /**
     * Получить сообщения группового чата заявки общины
     */
    public static function getCommunityMessages(int $communityRequestId): array
    {
        return Message::findByCommunityRequest($communityRequestId);
    }
    
    /**
     * Получить новые сообщения группового чата общины (после указанной даты)
     */
    public static function getNewCommunityMessages(int $communityRequestId, string $afterDate): array
    {
        return Message::findNewByCommunityRequest($communityRequestId, $afterDate);
    }
    
    /**
     * Отметить сообщения группового чата общины как прочитанные
     */
    public static function markCommunityAsRead(int $communityRequestId, int $userId): bool
    {
        $result = Message::markAsReadByCommunityRequest($communityRequestId, $userId);
        
        // Отправляем обновление счетчиков через WebSocket
        if ($result && class_exists('\OGAS\Services\WebSocketService')) {
            \OGAS\Services\WebSocketService::notifyUserUnreadCounts($userId);
        }
        
        return $result;
    }
    
    /**
     * Проверить доступ пользователя к групповому чату заявки общины
     */
    public static function checkCommunityAccess(int $communityRequestId, int $userId): bool
    {
        $request = CommunityRequest::findById($communityRequestId);
        if (!$request) {
            return false;
        }
        
        $participants = $request->getChatParticipants();
        return in_array($userId, $participants);
    }
}

