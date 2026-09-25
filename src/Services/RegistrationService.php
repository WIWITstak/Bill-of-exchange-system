<?php

namespace OGAS\Services;

use OGAS\Models\User;
use OGAS\Models\Transaction;
use OGAS\Services\TransactionService;
use OGAS\Services\ChatService;

/**
 * Сервис для обработки регистрации пользователей
 */
class RegistrationService
{
    /**
     * Зарегистрировать нового пользователя и создать транзакцию активации
     */
    public static function register(array $data): User
    {
        // Создаём пользователя (неактивным)
        $user = User::create([
            'email' => $data['email'],
            'password' => $data['password'],
            'full_name' => $data['full_name'],
            'user_type' => $data['user_type'] ?? 'individual'
        ]);
        
        // Получаем системного пользователя ОГАС
        $systemUser = User::getOrCreateSystemUser();
        
        // Рассчитываем сумму подписки для описания транзакции
        $subscriptionAmount = \OGAS\Services\SubscriptionService::calculateAmount($user);
        
        // Создаём транзакцию активации с системным пользователем
        // Пользователь - продавец, ОГАС - покупатель (пользователь "продаёт" подписку ОГАС)
        $transaction = TransactionService::create([
            'seller_id' => $user->getId(),
            'buyer_id' => $systemUser->getId(),
            'description' => sprintf(
                'Активация аккаунта пользователя %s в системе ОГАС. Подписка на сумму %s ₽ на 30 дней.',
                $user->getFullName(),
                number_format($subscriptionAmount, 2, '.', ' ')
            ),
            'category' => 'Активация аккаунта',
            'transaction_type' => 'barter'
        ]);
        
        // Системный пользователь подтверждает сделку вручную через админ-панель
        // Администратор должен проверить пользователя и подтвердить сделку
        
        // Создаём приветственное сообщение от системного пользователя
        try {
            ChatService::sendMessage(
                $transaction->getId(),
                $systemUser->getId(),
                sprintf(
                    "Добро пожаловать в систему ОГАС, %s!\n\n" .
                    "В этой сделке заключается подписка на сумму %s ₽ на 30 дней.\n\n" .
                    "Для активации вашего аккаунта и получения доступа ко всем функциям системы, " .
                    "необходимо подтверждение этой сделки с обеих сторон:\n" .
                    "1. Вы должны подтвердить сделку, нажав кнопку 'Подтвердить сделку'\n" .
                    "2. Администратор ОГАС проверит вашу заявку и подтвердит сделку со своей стороны\n\n" .
                    "После подтверждения сделки обоими участниками ваш аккаунт будет активирован, " .
                    "подписка будет оформлена, и вы сможете:\n" .
                    "• Создавать транзакции с другими пользователями\n" .
                    "• Выпускать и получать вексели\n" .
                    "• Участвовать в системе взаимных гарантий\n" .
                    "• Использовать все возможности платформы ОГАС",
                    $user->getFullName(),
                    number_format($subscriptionAmount, 2, '.', ' ')
                )
            );
        } catch (\Exception $e) {
            // Если не удалось отправить сообщение, это не критично
        }
        
        return $user;
    }
}

