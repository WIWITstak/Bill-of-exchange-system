<?php

namespace OGAS\Services;

use OGAS\Models\CommunityRequest;
use OGAS\Models\Bill;
use OGAS\Models\Transaction;
use OGAS\Models\Rating;
use OGAS\Services\NotificationService;

/**
 * Сервис для работы с системой круговой поруки "Община"
 */
class CommunityService
{
    /**
     * Создать заявку на круговую поруку
     */
    public static function createRequest(array $data): CommunityRequest
    {
        return CommunityRequest::create($data);
    }
    
    /**
     * Стать поручителем (ТОЛЬКО добавление в список, без создания векселей)
     * Вексели будут созданы после согласия всех поручителей в групповом чате
     */
    public static function becomeGuarantor(int $requestId, int $guarantorId, int $billsCount = 1): bool
    {
        $request = CommunityRequest::findById($requestId);
        
        if (!$request || $request->getStatus() !== 'open') {
            return false;
        }
        
        // Проверяем, не превышает ли это количество необходимых векселей
        $collected = $request->getCollectedAmount();
        if ($collected + $billsCount > $request->getAmount()) {
            return false;
        }
        
        // Проверяем, не является ли пользователь уже поручителем
        $db = \OGAS\Database::getConnection();
        $checkStmt = $db->prepare("
            SELECT id FROM community_guarantors 
            WHERE request_id = ? AND guarantor_id = ? AND status = 'active'
        ");
        $checkStmt->execute([$requestId, $guarantorId]);
        if ($checkStmt->fetch()) {
            return false; // Уже является поручителем
        }
        
        // Добавляем поручителя (БЕЗ создания векселей)
        $stmt = $db->prepare("
            INSERT INTO community_guarantors (request_id, guarantor_id, bills_count, status, confirmed)
            VALUES (?, ?, ?, 'active', 0)
        ");
        $stmt->execute([$requestId, $guarantorId, $billsCount]);
        
        // Обновляем данные заявки после добавления поручителя
        $request = CommunityRequest::findById($requestId);
        
        // Проверяем, собралось ли нужное количество векселей для открытия группового чата
        if ($request->isFulfilled()) {
            // Автоматически создаём групповой чат с приветственным сообщением
            self::initializeCommunityChat($requestId);
        }
        
        // Создаем уведомление для автора заявки о новом поручителе
        NotificationService::notifyCommunityGuarantor(
            $request->getUserId(),
            $requestId,
            $guarantorId
        );
        
        return true;
    }
    
    /**
     * Подтвердить согласие поручителя в групповом чате
     */
    public static function confirmGuarantor(int $requestId, int $guarantorId): bool
    {
        $request = CommunityRequest::findById($requestId);
        if (!$request || $request->getStatus() !== 'open') {
            return false;
        }
        
        $db = \OGAS\Database::getConnection();
        
        // Обновляем статус подтверждения поручителя
        $stmt = $db->prepare("
            UPDATE community_guarantors 
            SET confirmed = 1, confirmed_at = NOW()
            WHERE request_id = ? AND guarantor_id = ? AND status = 'active' AND confirmed = 0
        ");
        $stmt->execute([$requestId, $guarantorId]);
        
        if ($stmt->rowCount() > 0) {
            // Проверяем, все ли подтвердили и выполнена ли заявка
            if ($request->allGuarantorsConfirmed()) {
                // Создаём вексели и транзакции
                self::finalizeCommunityRequest($requestId);
            }
            return true;
        }
        
        return false;
    }
    
    /**
     * Инициализировать групповой чат для заявки общины
     * Создаёт приветственное сообщение и отправляет уведомления участникам
     */
    public static function initializeCommunityChat(int $requestId): void
    {
        $request = CommunityRequest::findById($requestId);
        if (!$request || !$request->isFulfilled()) {
            return;
        }
        
        // Проверяем, не было ли уже создано сообщение для этого чата
        $db = \OGAS\Database::getConnection();
        $checkStmt = $db->prepare("
            SELECT COUNT(*) FROM messages 
            WHERE community_request_id = ? AND transaction_id IS NULL
        ");
        $checkStmt->execute([$requestId]);
        if ($checkStmt->fetchColumn() > 0) {
            return; // Чат уже инициализирован
        }
        
        $requester = \OGAS\Models\User::findById($request->getUserId());
        $guarantors = $request->getGuarantors();
        $guarantorsCount = count($guarantors);
        
        // Создаём приветственное сообщение в групповом чате
        $welcomeMessage = sprintf(
            "👋 Добро пожаловать в групповой чат заявки общины #%d!\n\n" .
            "📋 Информация о заявке:\n" .
            "• Заявитель: %s\n" .
            "• Количество векселей: %d шт\n" .
            "• Номинал векселя: %s ₽\n" .
            "• Срок погашения: %d дней\n" .
            "• Общая сумма: %s ₽\n" .
            "• Количество поручителей: %d\n\n" .
            "✅ Необходимое количество векселей собрано!\n\n" .
            "📝 Теперь всем поручителям необходимо подтвердить своё согласие в этом чате.\n" .
            "После подтверждения всех поручителей будут созданы вексели и транзакции.",
            $requestId,
            $requester->getFullName(),
            $request->getAmount(),
            number_format($request->getNominal(), 2, '.', ' '),
            $request->getMaturityDays(),
            number_format($request->getTotalAmount(), 2, '.', ' '),
            $guarantorsCount
        );
        
        if ($request->getProductDescription()) {
            $welcomeMessage .= "\n\n🎯 Цель заявки: " . $request->getProductDescription();
            if ($request->getProductPrice()) {
                $welcomeMessage .= " (Стоимость: " . number_format($request->getProductPrice(), 2, '.', ' ') . " ₽)";
            }
        }
        
        // Создаём системное сообщение от заявителя
        \OGAS\Models\Message::create([
            'transaction_id' => null,
            'community_request_id' => $requestId,
            'user_id' => $request->getUserId(),
            'message' => $welcomeMessage
        ]);
        
        // Отправляем уведомления всем участникам о открытии группового чата
        $participants = $request->getChatParticipants();
        foreach ($participants as $participantId) {
            NotificationService::createNotification(
                $participantId,
                'community_chat_opened',
                'Групповой чат открыт',
                sprintf(
                    'Заявка общины #%d выполнена! Собрано нужное количество векселей. Групповой чат открыт для подтверждения согласия всех поручителей.',
                    $requestId
                ),
                $requestId,
                'community'
            );
        }
    }
    
    /**
     * Финальное создание векселей и транзакций после согласия всех поручителей
     */
    public static function finalizeCommunityRequest(int $requestId): bool
    {
        $request = CommunityRequest::findById($requestId);
        if (!$request || !$request->allGuarantorsConfirmed()) {
            return false;
        }
        
        $db = \OGAS\Database::getConnection();
        $db->beginTransaction();
        
        try {
            $guarantors = $request->getGuarantors();
            $maturityDate = date('Y-m-d H:i:s', strtotime("+{$request->getMaturityDays()} days"));
            
            foreach ($guarantors as $guarantorData) {
                $guarantorId = (int)$guarantorData['guarantor_id'];
                $billsCount = (int)$guarantorData['bills_count'];
                $guarantorRecordId = (int)$guarantorData['id'];
                
                // Создаём вексели: поручитель выпускает вексели для заявителя
                for ($i = 0; $i < $billsCount; $i++) {
                    Bill::create([
                        'issuer_id' => $guarantorId,
                        'holder_id' => $request->getUserId(),
                        'nominal' => $request->getNominal(),
                        'maturity_date' => $maturityDate,
                        'community_request_id' => $requestId,
                        'community_guarantor_id' => $guarantorRecordId
                    ]);
                }
                
                // Создаём вексели: заявитель выпускает вексели для поручителя
                for ($i = 0; $i < $billsCount; $i++) {
                    Bill::create([
                        'issuer_id' => $request->getUserId(),
                        'holder_id' => $guarantorId,
                        'nominal' => $request->getNominal(),
                        'maturity_date' => $maturityDate,
                        'community_request_id' => $requestId,
                        'community_guarantor_id' => $guarantorRecordId
                    ]);
                }
                
                // Создаём транзакцию типа "community" между заявителем и поручителем
                $description = "Круговая порука: обмен векселями через общину" . 
                               ($request->getDescription() ? ". " . $request->getDescription() : "");
                
                $transaction = Transaction::create([
                    'seller_id' => $request->getUserId(),
                    'buyer_id' => $guarantorId,
                    'description' => $description,
                    'category' => null,
                    'transaction_type' => 'community',
                    'community_request_id' => $requestId
                ]);
                
                // Автоматически подтверждаем с обеих сторон
                $transaction->setSellerConfirmed(true);
                $transaction->setBuyerConfirmed(true);
                $transaction->setStatus('active');
                $transaction->save();
            }
            
            // Обновляем статус заявки после финализации
            $request->setStatus('fulfilled');
            $request->save();
            
            // Отправляем сообщение в групповой чат о финализации
            $finalizationMessage = sprintf(
                "🎉 Поздравляем! Все поручители подтвердили согласие!\n\n" .
                "✅ Заявка общины #%d выполнена.\n" .
                "✅ Вексели и транзакции успешно созданы.\n\n" .
                "📋 Итоги:\n" .
                "• Создано векселей: %d шт\n" .
                "• Общая сумма: %s ₽\n" .
                "• Количество транзакций: %d\n\n" .
                "Теперь все участники могут управлять своими векселями в разделе 'Мои вексели'.",
                $requestId,
                $request->getAmount() * 2, // Взаимный обмен = 2 векселя на каждую единицу
                number_format($request->getTotalAmount() * 2, 2, '.', ' '),
                count($guarantors)
            );
            
            \OGAS\Models\Message::create([
                'transaction_id' => null,
                'community_request_id' => $requestId,
                'user_id' => $request->getUserId(),
                'message' => $finalizationMessage
            ]);
            
            // Отправляем уведомления всем участникам о финализации
            $participants = $request->getChatParticipants();
            foreach ($participants as $participantId) {
                NotificationService::createNotification(
                    $participantId,
                    'community_request_finalized',
                    'Заявка общины выполнена',
                    sprintf(
                        'Заявка общины #%d выполнена! Все поручители подтвердили согласие. Вексели и транзакции созданы.',
                        $requestId
                    ),
                    $requestId,
                    'community'
                );
            }
            
            // Обновляем рейтинги для всех участников
            $rating = Rating::findByUserId($request->getUserId());
            $rating->recalculate();
            
            foreach ($guarantors as $guarantorData) {
                $guarantorId = (int)$guarantorData['guarantor_id'];
                $rating = Rating::findByUserId($guarantorId);
                if ($rating) {
                    $rating->recalculate();
                }
            }
            
            $db->commit();
            return true;
            
        } catch (\Exception $e) {
            $db->rollBack();
            error_log("Ошибка при финализации заявки общины #{$requestId}: " . $e->getMessage());
            return false;
        }
    }
    
    /**
     * Получить все открытые заявки
     */
    public static function getOpenRequests(): array
    {
        return CommunityRequest::findOpen();
    }
}

