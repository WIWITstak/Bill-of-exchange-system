<?php

namespace OGAS\Core;

use OGAS\Core\Session;
use OGAS\Core\Security;

/**
 * Класс для защиты от Brute Force атак
 */
class BruteForceProtection
{
    private const MAX_ATTEMPTS = 5; // Максимальное количество попыток
    private const LOCKOUT_TIME = 900; // Время блокировки в секундах (15 минут)
    private const ATTEMPT_WINDOW = 300; // Окно для подсчета попыток (5 минут)
    
    /**
     * Проверить, заблокирован ли IP или email
     */
    public static function isBlocked(string $identifier, string $type = 'ip'): bool
    {
        Session::start();
        
        $key = self::getKey($identifier, $type);
        $data = Session::get($key, [
            'attempts' => [],
            'blocked_until' => 0
        ]);
        
        // Проверяем блокировку
        if ($data['blocked_until'] > time()) {
            return true;
        }
        
        // Если блокировка истекла, очищаем данные
        if ($data['blocked_until'] > 0 && $data['blocked_until'] <= time()) {
            Session::remove($key);
            return false;
        }
        
        return false;
    }
    
    /**
     * Получить время до разблокировки
     */
    public static function getUnlockTime(string $identifier, string $type = 'ip'): int
    {
        Session::start();
        
        $key = self::getKey($identifier, $type);
        $data = Session::get($key, [
            'blocked_until' => 0
        ]);
        
        $unlockTime = $data['blocked_until'] - time();
        return max(0, $unlockTime);
    }
    
    /**
     * Зарегистрировать неудачную попытку
     */
    public static function recordFailedAttempt(string $identifier, string $type = 'ip'): void
    {
        Session::start();
        
        $key = self::getKey($identifier, $type);
        $now = time();
        $windowStart = $now - self::ATTEMPT_WINDOW;
        
        $data = Session::get($key, [
            'attempts' => [],
            'blocked_until' => 0
        ]);
        
        // Очищаем старые попытки
        $data['attempts'] = array_filter(
            $data['attempts'],
            function($timestamp) use ($windowStart) {
                return $timestamp > $windowStart;
            }
        );
        
        // Добавляем новую попытку
        $data['attempts'][] = $now;
        
        // Если превышен лимит попыток, блокируем
        if (count($data['attempts']) >= self::MAX_ATTEMPTS) {
            $data['blocked_until'] = $now + self::LOCKOUT_TIME;
            
            // Логируем блокировку
            if (class_exists('OGAS\Core\SecurityLogger')) {
                \OGAS\Core\SecurityLogger::logBruteForceBlock($identifier, $type, count($data['attempts']));
            } else {
                error_log(sprintf(
                    'Brute Force Protection: %s %s заблокирован до %s после %d неудачных попыток',
                    $type,
                    $identifier,
                    date('Y-m-d H:i:s', $data['blocked_until']),
                    count($data['attempts'])
                ));
            }
        }
        
        Session::set($key, $data);
    }
    
    /**
     * Очистить попытки после успешного входа
     */
    public static function clearAttempts(string $identifier, string $type = 'ip'): void
    {
        Session::start();
        
        $key = self::getKey($identifier, $type);
        Session::remove($key);
    }
    
    /**
     * Получить количество оставшихся попыток
     */
    public static function getRemainingAttempts(string $identifier, string $type = 'ip'): int
    {
        Session::start();
        
        $key = self::getKey($identifier, $type);
        $now = time();
        $windowStart = $now - self::ATTEMPT_WINDOW;
        
        $data = Session::get($key, [
            'attempts' => []
        ]);
        
        // Очищаем старые попытки
        $data['attempts'] = array_filter(
            $data['attempts'],
            function($timestamp) use ($windowStart) {
                return $timestamp > $windowStart;
            }
        );
        
        $attemptCount = count($data['attempts']);
        return max(0, self::MAX_ATTEMPTS - $attemptCount);
    }
    
    /**
     * Получить ключ для хранения данных
     */
    private static function getKey(string $identifier, string $type): string
    {
        return '_brute_force_' . $type . '_' . md5($identifier);
    }
    
    /**
     * Проверить и заблокировать при необходимости
     */
    public static function checkAndBlock(string $identifier, string $type = 'ip'): bool
    {
        if (self::isBlocked($identifier, $type)) {
            return false;
        }
        
        return true;
    }
    
    /**
     * Требовать разблокировки (выбрасывает исключение, если заблокирован)
     */
    public static function requireUnlocked(string $identifier, string $type = 'ip'): void
    {
        if (self::isBlocked($identifier, $type)) {
            $unlockTime = self::getUnlockTime($identifier, $type);
            $minutes = ceil($unlockTime / 60);
            
            http_response_code(429);
            header('Content-Type: application/json; charset=utf-8');
            echo json_encode([
                'success' => false,
                'error' => 'Too many failed attempts',
                'message' => "Превышено количество попыток. Попробуйте через {$minutes} минут.",
                'unlock_time' => $unlockTime,
                'unlock_at' => date('Y-m-d H:i:s', time() + $unlockTime)
            ], JSON_UNESCAPED_UNICODE);
            exit;
        }
    }
}

