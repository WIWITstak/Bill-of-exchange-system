<?php

namespace OGAS\Core;

/**
 * Класс для управления сессиями
 */
class Session
{
    // Константы для таймаутов сессии
    private const INACTIVITY_TIMEOUT = 1800; // 30 минут неактивности
    private const MAX_SESSIONS_PER_USER = 5; // Максимум 5 одновременных сессий
    
    /**
     * Инициализация сессии с безопасными параметрами
     */
    public static function start(): void
    {
        if (session_status() === PHP_SESSION_NONE) {
            // Устанавливаем безопасные параметры cookie сессии
            ini_set('session.cookie_httponly', '1');
            ini_set('session.cookie_secure', self::isHttps() ? '1' : '0');
            ini_set('session.cookie_samesite', 'Strict');
            ini_set('session.use_strict_mode', '1');
            
            // Пытаемся запустить сессию только если заголовки еще не были отправлены
            if (!headers_sent()) {
                @session_start();
            }
            
            // Проверяем таймаут неактивности для авторизованных пользователей
            self::checkInactivityTimeout();
        }
    }
    
    /**
     * Проверить таймаут неактивности сессии
     */
    private static function checkInactivityTimeout(): void
    {
        if (!self::has('user_id')) {
            return; // Не авторизован - не проверяем
        }
        
        $lastActivity = self::get('_last_activity', time());
        $now = time();
        
        // Если прошло больше времени таймаута - завершаем сессию
        if (($now - $lastActivity) > self::INACTIVITY_TIMEOUT) {
            self::destroy();
            
            // Логируем автоматический logout
            if (class_exists('OGAS\Core\SecurityLogger')) {
                \OGAS\Core\SecurityLogger::log('session_timeout', [
                    'user_id' => self::get('user_id'),
                    'timeout' => self::INACTIVITY_TIMEOUT
                ], 'info');
            }
            
            return;
        }
        
        // Обновляем время последней активности
        self::set('_last_activity', $now);
        
        // Обновляем время активности в БД, если есть запись о сессии
        if (self::has('_session_record_id')) {
            $sessionId = session_id();
            $sessionRecord = \OGAS\Models\UserSession::findBySessionId($sessionId);
            if ($sessionRecord) {
                $sessionRecord->updateActivity();
            }
        }
    }
    
    /**
     * Обновить время последней активности
     */
    public static function touch(): void
    {
        self::start();
        self::set('_last_activity', time());
        
        // Обновляем в БД
        if (self::has('_session_record_id')) {
            $sessionId = session_id();
            $sessionRecord = \OGAS\Models\UserSession::findBySessionId($sessionId);
            if ($sessionRecord) {
                $sessionRecord->updateActivity();
            }
        }
    }
    
    /**
     * Получить таймаут неактивности в секундах
     */
    public static function getInactivityTimeout(): int
    {
        return self::INACTIVITY_TIMEOUT;
    }
    
    /**
     * Получить максимальное количество сессий на пользователя
     */
    public static function getMaxSessionsPerUser(): int
    {
        return self::MAX_SESSIONS_PER_USER;
    }
    
    /**
     * Проверить, используется ли HTTPS
     */
    private static function isHttps(): bool
    {
        return (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off') 
            || (!empty($_SERVER['HTTP_X_FORWARDED_PROTO']) && $_SERVER['HTTP_X_FORWARDED_PROTO'] === 'https')
            || (!empty($_SERVER['HTTP_X_FORWARDED_SSL']) && $_SERVER['HTTP_X_FORWARDED_SSL'] === 'on')
            || (isset($_SERVER['SERVER_PORT']) && $_SERVER['SERVER_PORT'] == 443);
    }
    
    /**
     * Регенерировать ID сессии (защита от session fixation)
     */
    public static function regenerateId(): void
    {
        self::start();
        
        if (session_status() === PHP_SESSION_ACTIVE) {
            session_regenerate_id(true);
        }
    }
    
    /**
     * Установить значение в сессию
     */
    public static function set(string $key, $value): void
    {
        self::start();
        $_SESSION[$key] = $value;
    }
    
    /**
     * Получить значение из сессии
     */
    public static function get(string $key, $default = null)
    {
        self::start();
        return $_SESSION[$key] ?? $default;
    }
    
    /**
     * Проверить наличие ключа в сессии
     */
    public static function has(string $key): bool
    {
        self::start();
        return isset($_SESSION[$key]);
    }
    
    /**
     * Удалить значение из сессии
     */
    public static function remove(string $key): void
    {
        self::start();
        unset($_SESSION[$key]);
    }
    
    /**
     * Очистить всю сессию
     */
    public static function destroy(): void
    {
        self::start();
        session_destroy();
        $_SESSION = [];
    }
    
    /**
     * Установить flash-сообщение (показывается один раз)
     */
    public static function flash(string $key, $value): void
    {
        self::set('_flash_' . $key, $value);
    }
    
    /**
     * Получить flash-сообщение
     */
    public static function getFlash(string $key, $default = null)
    {
        $value = self::get('_flash_' . $key, $default);
        self::remove('_flash_' . $key);
        return $value;
    }
}

