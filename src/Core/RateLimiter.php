<?php

namespace OGAS\Core;

use OGAS\Database;

/**
 * Класс для ограничения частоты запросов (Rate Limiting)
 */
class RateLimiter
{
    private const DEFAULT_WINDOW = 60; // секунд
    private const DEFAULT_MAX_REQUESTS = 60; // запросов за окно
    
    /**
     * Проверить лимит запросов
     * 
     * @param string $key Ключ для идентификации (например, IP адрес или user_id)
     * @param int $maxRequests Максимальное количество запросов
     * @param int $window Временное окно в секундах
     * @return bool true если лимит не превышен, false если превышен
     */
    public static function check(string $key, int $maxRequests = self::DEFAULT_MAX_REQUESTS, int $window = self::DEFAULT_WINDOW): bool
    {
        $now = time();
        $windowStart = $now - $window;
        
        // Используем сессию для хранения счетчиков (в продакшене лучше использовать Redis)
        Session::start();
        
        $rateLimitKey = '_rate_limit_' . md5($key);
        $rateLimitData = Session::get($rateLimitKey, [
            'requests' => [],
            'last_check' => $now
        ]);
        
        // Очищаем старые запросы вне окна
        $rateLimitData['requests'] = array_filter(
            $rateLimitData['requests'],
            function($timestamp) use ($windowStart) {
                return $timestamp > $windowStart;
            }
        );
        
        // Проверяем лимит
        $requestCount = count($rateLimitData['requests']);
        
        if ($requestCount >= $maxRequests) {
            // Логируем превышение rate limit
            if (class_exists('OGAS\Core\SecurityLogger')) {
                \OGAS\Core\SecurityLogger::logRateLimitExceeded($key, $maxRequests, $window);
            }
            return false;
        }
        
        // Добавляем текущий запрос
        $rateLimitData['requests'][] = $now;
        $rateLimitData['last_check'] = $now;
        
        Session::set($rateLimitKey, $rateLimitData);
        
        return true;
    }
    
    /**
     * Получить оставшееся количество запросов
     */
    public static function getRemaining(string $key, int $maxRequests = self::DEFAULT_MAX_REQUESTS, int $window = self::DEFAULT_WINDOW): int
    {
        $now = time();
        $windowStart = $now - $window;
        
        Session::start();
        
        $rateLimitKey = '_rate_limit_' . md5($key);
        $rateLimitData = Session::get($rateLimitKey, [
            'requests' => [],
            'last_check' => $now
        ]);
        
        // Очищаем старые запросы
        $rateLimitData['requests'] = array_filter(
            $rateLimitData['requests'],
            function($timestamp) use ($windowStart) {
                return $timestamp > $windowStart;
            }
        );
        
        $requestCount = count($rateLimitData['requests']);
        
        return max(0, $maxRequests - $requestCount);
    }
    
    /**
     * Получить время до сброса лимита
     */
    public static function getResetTime(string $key, int $window = self::DEFAULT_WINDOW): int
    {
        Session::start();
        
        $rateLimitKey = '_rate_limit_' . md5($key);
        $rateLimitData = Session::get($rateLimitKey, [
            'requests' => [],
            'last_check' => time()
        ]);
        
        if (empty($rateLimitData['requests'])) {
            return 0;
        }
        
        $oldestRequest = min($rateLimitData['requests']);
        return $oldestRequest + $window - time();
    }
    
    /**
     * Сбросить лимит для ключа
     */
    public static function reset(string $key): void
    {
        Session::start();
        
        $rateLimitKey = '_rate_limit_' . md5($key);
        Session::remove($rateLimitKey);
    }
    
    /**
     * Проверить лимит и вернуть результат с заголовками
     */
    public static function checkWithHeaders(string $key, int $maxRequests = self::DEFAULT_MAX_REQUESTS, int $window = self::DEFAULT_WINDOW): array
    {
        $remaining = self::getRemaining($key, $maxRequests, $window);
        $resetTime = self::getResetTime($key, $window);
        
        $headers = [
            'X-RateLimit-Limit' => $maxRequests,
            'X-RateLimit-Remaining' => $remaining,
            'X-RateLimit-Reset' => time() + $resetTime
        ];
        
        $allowed = self::check($key, $maxRequests, $window);
        
        return [
            'allowed' => $allowed,
            'headers' => $headers,
            'remaining' => $remaining,
            'reset_time' => $resetTime
        ];
    }
    
    /**
     * Требовать лимит (выбрасывает исключение, если лимит превышен)
     * 
     * ВРЕМЕННО ОТКЛЮЧЕНО - для включения раскомментируйте код ниже
     */
    public static function requireLimit(string $key, int $maxRequests = self::DEFAULT_MAX_REQUESTS, int $window = self::DEFAULT_WINDOW): void
    {
        // ВРЕМЕННО ОТКЛЮЧЕНО - rate limiting не работает
        return;
        
        /* Раскомментируйте для включения rate limiting:
        $result = self::checkWithHeaders($key, $maxRequests, $window);
        
        // Устанавливаем заголовки
        foreach ($result['headers'] as $header => $value) {
            header("$header: $value");
        }
        
        if (!$result['allowed']) {
            http_response_code(429);
            header('Content-Type: application/json; charset=utf-8');
            echo json_encode([
                'success' => false,
                'error' => 'Rate limit exceeded',
                'message' => 'Превышен лимит запросов. Попробуйте позже.',
                'retry_after' => $result['reset_time']
            ], JSON_UNESCAPED_UNICODE);
            exit;
        }
        */
    }
}

