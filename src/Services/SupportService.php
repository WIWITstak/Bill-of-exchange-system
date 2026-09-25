<?php

namespace OGAS\Services;

use OGAS\Models\Ticket;
use OGAS\Models\SupportMessage;
use OGAS\Core\Security;

/**
 * Сервис для работы с поддержкой
 */
class SupportService
{
    /**
     * Создать новое обращение
     */
    public static function createTicket(int $userId, string $subject, string $message, string $priority = 'medium'): array
    {
        // Валидация
        $subject = Security::sanitizeString($subject);
        $message = Security::sanitizeString($message);
        
        if (empty($subject) || mb_strlen($subject) < 3) {
            return [
                'success' => false,
                'message' => 'Тема обращения должна содержать минимум 3 символа'
            ];
        }
        
        if (empty($message) || mb_strlen($message) < 10) {
            return [
                'success' => false,
                'message' => 'Сообщение должно содержать минимум 10 символов'
            ];
        }
        
        if (!in_array($priority, ['low', 'medium', 'high', 'urgent'])) {
            $priority = 'medium';
        }
        
        try {
            $ticketModel = new Ticket();
            $ticketId = $ticketModel->create($userId, $subject, $priority, null, null, 'general');
            
            // Создаем первое сообщение
            $messageModel = new SupportMessage();
            $messageModel->create($ticketId, $userId, $message, false);
            
            return [
                'success' => true,
                'message' => 'Обращение успешно создано',
                'ticket_id' => $ticketId
            ];
        } catch (\Exception $e) {
            error_log('SupportService::createTicket error: ' . $e->getMessage());
            return [
                'success' => false,
                'message' => 'Ошибка при создании обращения'
            ];
        }
    }
    
    /**
     * Добавить сообщение в обращение
     */
    public static function addMessage(int $ticketId, int $userId, string $message, bool $isAdmin = false): array
    {
        // Валидация
        $message = Security::sanitizeString($message);
        $message = Security::stripDangerousHtml($message);
        
        if (empty($message) || mb_strlen($message) < 1) {
            return [
                'success' => false,
                'message' => 'Сообщение не может быть пустым'
            ];
        }
        
        if (mb_strlen($message) > 10000) {
            return [
                'success' => false,
                'message' => 'Сообщение слишком длинное (макс. 10000 символов)'
            ];
        }
        
        try {
            // Проверяем существование обращения
            $ticketModel = new Ticket();
            $ticket = $ticketModel->findById($ticketId);
            
            if (!$ticket) {
                return [
                    'success' => false,
                    'message' => 'Обращение не найдено'
                ];
            }
            
            // Проверяем права доступа
            if (!$isAdmin && $ticket['user_id'] != $userId) {
                return [
                    'success' => false,
                    'message' => 'Нет доступа к этому обращению'
                ];
            }
            
            // Если обращение закрыто, только админы могут добавлять сообщения
            if ($ticket['status'] === 'closed' && !$isAdmin) {
                return [
                    'success' => false,
                    'message' => 'Обращение закрыто. Новые сообщения недоступны.'
                ];
            }
            
            // Если это первое сообщение от админа, меняем статус на in_progress
            if ($isAdmin && $ticket['status'] === 'open') {
                $ticketModel->updateStatus($ticketId, 'in_progress');
            }
            
            $messageModel = new SupportMessage();
            $messageId = $messageModel->create($ticketId, $userId, $message, $isAdmin);
            
            // Отмечаем сообщения как прочитанные для текущего пользователя
            $messageModel->markAsRead($ticketId, $userId);
            
            return [
                'success' => true,
                'message' => 'Сообщение отправлено',
                'message_id' => $messageId
            ];
        } catch (\Exception $e) {
            error_log('SupportService::addMessage error: ' . $e->getMessage());
            return [
                'success' => false,
                'message' => 'Ошибка при отправке сообщения'
            ];
        }
    }
    
    /**
     * Получить обращения пользователя
     */
    public static function getUserTickets(int $userId, ?string $status = null): array
    {
        try {
            $ticketModel = new Ticket();
            return $ticketModel->findByUserId($userId, $status);
        } catch (\Exception $e) {
            error_log('SupportService::getUserTickets error: ' . $e->getMessage());
            return [];
        }
    }
    
    /**
     * Получить все обращения (для администраторов)
     */
    public static function getAllTickets(?string $status = null, ?string $priority = null, ?int $assignedTo = null): array
    {
        try {
            $ticketModel = new Ticket();
            return $ticketModel->findAll($status, $priority, $assignedTo);
        } catch (\Exception $e) {
            error_log('SupportService::getAllTickets error: ' . $e->getMessage());
            return [];
        }
    }
    
    /**
     * Получить обращение с сообщениями
     */
    public static function getTicketWithMessages(int $ticketId, int $userId, bool $isAdmin = false): ?array
    {
        try {
            $ticketModel = new Ticket();
            $ticket = $ticketModel->findById($ticketId);
            
            if (!$ticket) {
                return null;
            }
            
            // Проверяем права доступа
            if (!$isAdmin && $ticket['user_id'] != $userId) {
                return null;
            }
            
            // Получаем сообщения
            $messageModel = new SupportMessage();
            $messages = $messageModel->findByTicketId($ticketId);
            
            // Отмечаем сообщения как прочитанные
            $messageModel->markAsRead($ticketId, $userId);
            
            $ticket['messages'] = $messages;
            
            return $ticket;
        } catch (\Exception $e) {
            error_log('SupportService::getTicketWithMessages error: ' . $e->getMessage());
            return null;
        }
    }
    
    /**
     * Обновить статус обращения
     */
    public static function updateTicketStatus(int $ticketId, string $status, ?int $assignedTo = null): array
    {
        if (!in_array($status, ['open', 'in_progress', 'resolved', 'closed'])) {
            return [
                'success' => false,
                'message' => 'Недопустимый статус'
            ];
        }
        
        try {
            $ticketModel = new Ticket();
            $success = $ticketModel->updateStatus($ticketId, $status, $assignedTo);
            
            if ($success) {
                return [
                    'success' => true,
                    'message' => 'Статус обновлен'
                ];
            } else {
                return [
                    'success' => false,
                    'message' => 'Ошибка при обновлении статуса'
                ];
            }
        } catch (\Exception $e) {
            error_log('SupportService::updateTicketStatus error: ' . $e->getMessage());
            return [
                'success' => false,
                'message' => 'Ошибка при обновлении статуса'
            ];
        }
    }
    
    /**
     * Назначить обращение администратору (или снять назначение, если $adminId = null)
     */
    public static function assignTicket(int $ticketId, ?int $adminId): array
    {
        try {
            $ticketModel = new Ticket();
            $success = $ticketModel->assignTo($ticketId, $adminId);
            
            if ($success) {
                return [
                    'success' => true,
                    'message' => $adminId ? 'Обращение назначено' : 'Назначение снято'
                ];
            } else {
                return [
                    'success' => false,
                    'message' => 'Ошибка при назначении обращения'
                ];
            }
        } catch (\Exception $e) {
            error_log('SupportService::assignTicket error: ' . $e->getMessage());
            return [
                'success' => false,
                'message' => 'Ошибка при назначении обращения'
            ];
        }
    }
    
    /**
     * Получить статистику обращений
     */
    public static function getStats(?int $userId = null): array
    {
        try {
            $ticketModel = new Ticket();
            return $ticketModel->getStats($userId);
        } catch (\Exception $e) {
            error_log('SupportService::getStats error: ' . $e->getMessage());
            return [
                'total' => 0,
                'open' => 0,
                'in_progress' => 0,
                'resolved' => 0,
                'closed' => 0
            ];
        }
    }
    
    /**
     * Получить список администраторов для назначения обращений
     */
    public static function getAdmins(): array
    {
        try {
            $db = \OGAS\Database::getConnection();
            $stmt = $db->prepare("
                SELECT id, full_name, email 
                FROM users 
                WHERE is_admin = 1 AND is_active = 1 AND is_system = 0
                ORDER BY full_name ASC
            ");
            $stmt->execute();
            return $stmt->fetchAll(\PDO::FETCH_ASSOC);
        } catch (\Exception $e) {
            error_log('SupportService::getAdmins error: ' . $e->getMessage());
            return [];
        }
    }
    
    /**
     * Закрыть обращение
     */
    public static function closeTicket(int $ticketId): array
    {
        return self::updateTicketStatus($ticketId, 'closed');
    }
    
    /**
     * Создать обращение, связанное с транзакцией или векселем
     */
    public static function createTicketWithRelation(
        int $userId, 
        string $subject, 
        string $message, 
        ?int $relatedTransactionId = null,
        ?int $relatedBillId = null,
        string $priority = 'medium'
    ): array {
        // Определяем тип заявки
        $ticketType = 'general';
        if ($relatedTransactionId) {
            $ticketType = 'transaction';
        } elseif ($relatedBillId) {
            $ticketType = 'bill';
        }
        
        // Валидация
        $subject = Security::sanitizeString($subject);
        $message = Security::sanitizeString($message);
        
        if (empty($subject) || mb_strlen($subject) < 3) {
            return [
                'success' => false,
                'message' => 'Тема обращения должна содержать минимум 3 символа'
            ];
        }
        
        if (empty($message) || mb_strlen($message) < 10) {
            return [
                'success' => false,
                'message' => 'Сообщение должно содержать минимум 10 символов'
            ];
        }
        
        if (!in_array($priority, ['low', 'medium', 'high', 'urgent'])) {
            $priority = 'medium';
        }
        
        try {
            $ticketModel = new Ticket();
            $ticketId = $ticketModel->create($userId, $subject, $priority, $relatedTransactionId, $relatedBillId, $ticketType);
            
            // Создаем первое сообщение
            $messageModel = new SupportMessage();
            $messageModel->create($ticketId, $userId, $message, false);
            
            return [
                'success' => true,
                'message' => 'Обращение успешно создано',
                'ticket_id' => $ticketId
            ];
        } catch (\Exception $e) {
            error_log('SupportService::createTicketWithRelation error: ' . $e->getMessage());
            return [
                'success' => false,
                'message' => 'Ошибка при создании обращения'
            ];
        }
    }
    
    /**
     * Принудительно подтвердить транзакцию администратором (для исправления проблем)
     */
    public static function adminForceConfirmTransaction(int $ticketId, int $adminId, int $transactionId, bool $confirmSeller = true, bool $confirmBuyer = true): array
    {
        try {
            // Проверяем, что заявка связана с этой транзакцией
            $ticketModel = new Ticket();
            $ticket = $ticketModel->findById($ticketId);
            
            if (!$ticket) {
                return [
                    'success' => false,
                    'message' => 'Обращение не найдено'
                ];
            }
            
            if ($ticket['related_transaction_id'] != $transactionId) {
                return [
                    'success' => false,
                    'message' => 'Обращение не связано с указанной транзакцией'
                ];
            }
            
            // Получаем транзакцию
            $transaction = \OGAS\Models\Transaction::findById($transactionId);
            if (!$transaction) {
                return [
                    'success' => false,
                    'message' => 'Транзакция не найдена'
                ];
            }
            
            // Принудительно подтверждаем
            if ($confirmSeller) {
                $transaction->setSellerConfirmed(true);
            }
            if ($confirmBuyer) {
                $transaction->setBuyerConfirmed(true);
            }
            
            // Если оба подтверждены, меняем статус на active
            if ($transaction->isBothConfirmed() && $transaction->getStatus() === 'pending') {
                $transaction->setStatus('active');
            }
            
            if (!$transaction->save()) {
                return [
                    'success' => false,
                    'message' => 'Ошибка при обновлении транзакции'
                ];
            }
            
            // Добавляем сообщение в заявку от администратора
            $adminUser = \OGAS\Models\User::findById($adminId);
            $messageText = sprintf(
                "🔧 Администратор %s принудительно подтвердил сделку (Продавец: %s, Покупатель: %s).",
                $adminUser ? $adminUser->getFullName() : 'Администратор',
                $confirmSeller ? 'да' : 'нет',
                $confirmBuyer ? 'да' : 'нет'
            );
            
            $messageModel = new SupportMessage();
            $messageModel->create($ticketId, $adminId, $messageText, true);
            
            return [
                'success' => true,
                'message' => 'Транзакция успешно подтверждена',
                'transaction' => [
                    'id' => $transaction->getId(),
                    'seller_confirmed' => $transaction->isSellerConfirmed(),
                    'buyer_confirmed' => $transaction->isBuyerConfirmed(),
                    'status' => $transaction->getStatus()
                ]
            ];
        } catch (\Exception $e) {
            error_log('SupportService::adminForceConfirmTransaction error: ' . $e->getMessage());
            return [
                'success' => false,
                'message' => 'Ошибка при подтверждении транзакции: ' . $e->getMessage()
            ];
        }
    }
    
    /**
     * Принудительно создать вексель администратором (для исправления проблем)
     */
    public static function adminForceCreateBill(
        int $ticketId, 
        int $adminId, 
        int $transactionId, 
        int $issuerId, 
        int $holderId, 
        float $nominal, 
        int $maturityDays
    ): array {
        try {
            // Проверяем, что заявка связана с этой транзакцией
            $ticketModel = new Ticket();
            $ticket = $ticketModel->findById($ticketId);
            
            if (!$ticket) {
                return [
                    'success' => false,
                    'message' => 'Обращение не найдено'
                ];
            }
            
            if ($ticket['related_transaction_id'] != $transactionId) {
                return [
                    'success' => false,
                    'message' => 'Обращение не связано с указанной транзакцией'
                ];
            }
            
            // Получаем транзакцию
            $transaction = \OGAS\Models\Transaction::findById($transactionId);
            if (!$transaction) {
                return [
                    'success' => false,
                    'message' => 'Транзакция не найдена'
                ];
            }
            
            // Проверяем, что пользователи участвуют в транзакции
            if (($transaction->getSellerId() !== $issuerId && $transaction->getSellerId() !== $holderId) ||
                ($transaction->getBuyerId() !== $issuerId && $transaction->getBuyerId() !== $holderId)) {
                return [
                    'success' => false,
                    'message' => 'Указанные пользователи не являются участниками транзакции'
                ];
            }
            
            // Создаем вексель
            $bill = \OGAS\Services\BillService::issue($issuerId, $holderId, $nominal, $maturityDays);
            
            // Обновляем статус транзакции на 'active' если был 'pending'
            if ($transaction->getStatus() === 'pending') {
                $transaction->setStatus('active');
                $transaction->save();
            }
            
            // Добавляем сообщение в заявку от администратора
            $adminUser = \OGAS\Models\User::findById($adminId);
            $issuerUser = \OGAS\Models\User::findById($issuerId);
            $holderUser = \OGAS\Models\User::findById($holderId);
            
            $messageText = sprintf(
                "🔧 Администратор %s принудительно создал вексель #%d.\nВыпустил: %s\nПолучил: %s\nНоминал: %.2f ₽\nСрок погашения: %d дней",
                $adminUser ? $adminUser->getFullName() : 'Администратор',
                $bill->getId(),
                $issuerUser ? $issuerUser->getFullName() : "Пользователь #$issuerId",
                $holderUser ? $holderUser->getFullName() : "Пользователь #$holderId",
                $nominal,
                $maturityDays
            );
            
            $messageModel = new SupportMessage();
            $messageModel->create($ticketId, $adminId, $messageText, true);
            
            return [
                'success' => true,
                'message' => 'Вексель успешно создан',
                'bill_id' => $bill->getId()
            ];
        } catch (\Exception $e) {
            error_log('SupportService::adminForceCreateBill error: ' . $e->getMessage());
            return [
                'success' => false,
                'message' => 'Ошибка при создании векселя: ' . $e->getMessage()
            ];
        }
    }
}

