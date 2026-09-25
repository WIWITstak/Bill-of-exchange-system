<?php

namespace OGAS\Services;

use OGAS\Models\User;
use OGAS\Models\PasswordReset;

/**
 * Сервис для работы с восстановлением пароля
 */
class PasswordResetService
{
    /**
     * Создать токен сброса пароля
     */
    public static function createToken(int $userId, int $hoursValid = 24): string
    {
        // Генерируем безопасный токен
        $token = bin2hex(random_bytes(32)); // 64 символа
        
        PasswordReset::create($userId, $token, $hoursValid);
        
        return $token;
    }
    
    /**
     * Создать запрос на сброс пароля по email
     */
    public static function requestReset(string $email): ?string
    {
        $user = User::findByEmail($email);
        
        if (!$user) {
            // Не говорим, что пользователь не найден (защита от перебора)
            return null;
        }
        
        $token = self::createToken($user->getId(), 24); // 24 часа
        
        return $token;
    }
    
    /**
     * Сбросить пароль по токену
     */
    public static function resetPassword(string $token, string $newPassword): bool
    {
        $passwordReset = PasswordReset::findByToken($token);
        
        if (!$passwordReset || !$passwordReset->isValid()) {
            return false;
        }
        
        $user = User::findById($passwordReset->getUserId());
        
        if (!$user) {
            return false;
        }
        
        // Изменяем пароль
        if ($user->changePassword($newPassword)) {
            // Помечаем токен как использованный
            $passwordReset->markAsUsed();
            return true;
        }
        
        return false;
    }
    
    /**
     * Получить ссылку для сброса пароля
     */
    public static function getResetUrl(string $token): string
    {
        $baseUrl = $_SERVER['HTTP_HOST'] ?? 'localhost';
        $protocol = (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off') ? 'https' : 'http';
        return "{$protocol}://{$baseUrl}/reset-password.php?token=" . urlencode($token);
    }
}








