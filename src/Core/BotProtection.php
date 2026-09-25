<?php

namespace OGAS\Core;

use OGAS\Core\Session;
use OGAS\Core\Security;
use OGAS\Core\SecurityLogger;

/**
 * Класс для защиты от ботов и сканеров
 */
class BotProtection
{
    // Известные User-Agent ботов и сканеров
    private const BOT_USER_AGENTS = [
        // Сканеры безопасности
        'nikto', 'sqlmap', 'havij', 'acunetix', 'nessus', 'masscan', 'nmap',
        'openvas', 'w3af', 'zap', 'burp', 'paros', 'webscarab', 'skipfish',
        'wpscan', 'joomscan', 'drupalscan', 'whatweb', 'dirb', 'dirbuster',
        
        // Поисковые боты (можно разрешить, но логировать)
        'googlebot', 'bingbot', 'yandex', 'baiduspider', 'slurp', 'duckduckbot',
        'facebookexternalhit', 'twitterbot', 'linkedinbot', 'applebot',
        
        // Простые боты и скрипты
        'curl', 'wget', 'libwww-perl', 'python-requests', 'python-urllib',
        'java', 'scrapy', 'grab', 'httpie', 'postman', 'insomnia',
        'apache-httpclient', 'okhttp', 'go-http-client', 'node-fetch',
        
        // Известные вредоносные боты
        'semrushbot', 'ahrefsbot', 'mj12bot', 'dotbot', 'blexbot',
        'sogou', 'exabot', 'facebot', 'ia_archiver', 'archive.org_bot',
        
        // Пустые или подозрительные User-Agent
        '', 'bot', 'crawler', 'spider', 'scraper', 'monitor', 'checker'
    ];
    
    // Известные IP адреса ботов (опционально, можно расширить)
    private const BOT_IP_RANGES = [
        // Можно добавить известные диапазоны IP ботов
    ];
    
    // Максимальная скорость запросов (запросов в секунду)
    private const MAX_REQUESTS_PER_SECOND = 10;
    
    // Время окна для проверки скорости
    private const RATE_WINDOW = 1; // секунда
    
    /**
     * Проверить, является ли запрос ботом
     */
    public static function isBot(?string $userAgent = null): bool
    {
        $userAgent = $userAgent ?? ($_SERVER['HTTP_USER_AGENT'] ?? '');
        $userAgentLower = strtolower($userAgent);
        
        // Проверяем известные User-Agent ботов
        foreach (self::BOT_USER_AGENTS as $botPattern) {
            if (stripos($userAgentLower, $botPattern) !== false) {
                return true;
            }
        }
        
        // Проверяем пустой User-Agent
        if (empty(trim($userAgent))) {
            return true;
        }
        
        // Проверяем подозрительные паттерны в User-Agent
        if (preg_match('/^(bot|crawler|spider|scraper|monitor|checker)/i', $userAgent)) {
            return true;
        }
        
        return false;
    }
    
    /**
     * Проверить скорость запросов (защита от быстрых ботов)
     */
    public static function checkRequestRate(string $identifier, int $maxRequests = null, int $windowSeconds = null): bool
    {
        $maxRequests = $maxRequests ?? self::MAX_REQUESTS_PER_SECOND;
        $windowSeconds = $windowSeconds ?? self::RATE_WINDOW;
        
        Session::start();
        
        $key = '_bot_rate_' . md5($identifier);
        $now = time();
        $windowStart = $now - $windowSeconds;
        
        $data = Session::get($key, [
            'requests' => [],
            'blocked_until' => 0
        ]);
        
        // Проверяем блокировку
        if ($data['blocked_until'] > $now) {
            return false;
        }
        
        // Очищаем старые запросы
        $data['requests'] = array_filter(
            $data['requests'],
            function($timestamp) use ($windowStart) {
                return $timestamp > $windowStart;
            }
        );
        
        // Добавляем текущий запрос
        $data['requests'][] = $now;
        
        // Если превышен лимит, блокируем
        if (count($data['requests']) > $maxRequests) {
            $data['blocked_until'] = $now + 60; // Блокировка на 1 минуту
            
            // Логируем блокировку
            if (class_exists('OGAS\Core\SecurityLogger')) {
                SecurityLogger::log('bot_rate_limit_exceeded', [
                    'identifier' => $identifier,
                    'requests_count' => count($data['requests']),
                    'blocked_until' => date('Y-m-d H:i:s', $data['blocked_until'])
                ], 'warning');
            }
            
            Session::set($key, $data);
            return false;
        }
        
        Session::set($key, $data);
        return true;
    }
    
    /**
     * Проверить наличие JavaScript (боты часто не выполняют JS)
     */
    public static function checkJavaScriptEnabled(): bool
    {
        Session::start();
        
        // Проверяем, установлен ли флаг выполнения JavaScript
        $jsEnabled = Session::get('_js_enabled', false);
        
        return $jsEnabled;
    }
    
    /**
     * Установить флаг выполнения JavaScript
     */
    public static function setJavaScriptEnabled(): void
    {
        Session::start();
        Session::set('_js_enabled', true);
        Session::set('_js_enabled_time', time());
    }
    
    /**
     * Проверить honeypot поле (скрытое поле, которое должны заполнить только боты)
     */
    public static function checkHoneypot(array $postData, string $fieldName = 'website'): bool
    {
        // Если honeypot поле заполнено - это бот
        if (!empty($postData[$fieldName] ?? '')) {
            // Логируем попытку бота
            if (class_exists('OGAS\Core\SecurityLogger')) {
                SecurityLogger::log('bot_honeypot_triggered', [
                    'ip' => Security::getClientIp(),
                    'user_agent' => $_SERVER['HTTP_USER_AGENT'] ?? 'Unknown',
                    'field_name' => $fieldName,
                    'field_value' => $postData[$fieldName] ?? ''
                ], 'warning');
            }
            return false; // Бот обнаружен
        }
        
        return true; // Honeypot не заполнен - нормальный пользователь
    }
    
    /**
     * Проверить время заполнения формы (боты заполняют слишком быстро)
     */
    public static function checkFormTime(string $formId, int $minSeconds = 3): bool
    {
        Session::start();
        
        $key = '_form_start_' . md5($formId);
        $startTime = Session::get($key, 0);
        
        if ($startTime === 0) {
            // Форма только что загружена
            Session::set($key, time());
            return true;
        }
        
        $elapsed = time() - $startTime;
        
        // Если форма заполнена слишком быстро - подозрительно
        if ($elapsed < $minSeconds) {
            // Логируем подозрительную активность
            if (class_exists('OGAS\Core\SecurityLogger')) {
                SecurityLogger::log('bot_fast_form_submit', [
                    'form_id' => $formId,
                    'elapsed_seconds' => $elapsed,
                    'min_seconds' => $minSeconds,
                    'ip' => Security::getClientIp()
                ], 'warning');
            }
            return false;
        }
        
        // Очищаем время начала после проверки
        Session::remove($key);
        return true;
    }
    
    /**
     * Установить время начала заполнения формы
     */
    public static function setFormStartTime(string $formId): void
    {
        Session::start();
        $key = '_form_start_' . md5($formId);
        Session::set($key, time());
    }
    
    /**
     * Комплексная проверка на бота
     */
    public static function detectBot(?string $userAgent = null, ?string $ip = null): array
    {
        $userAgent = $userAgent ?? ($_SERVER['HTTP_USER_AGENT'] ?? '');
        $ip = $ip ?? Security::getClientIp();
        
        $reasons = [];
        $score = 0;
        
        // Проверка User-Agent
        if (self::isBot($userAgent)) {
            $reasons[] = 'suspicious_user_agent';
            $score += 50;
        }
        
        // Проверка скорости запросов
        if (!self::checkRequestRate($ip)) {
            $reasons[] = 'rate_limit_exceeded';
            $score += 30;
        }
        
        // Проверка JavaScript (только для критичных операций)
        // if (!self::checkJavaScriptEnabled()) {
        //     $reasons[] = 'javascript_not_enabled';
        //     $score += 20;
        // }
        
        // Проверка пустого Referer (может быть бот)
        $referer = $_SERVER['HTTP_REFERER'] ?? '';
        if (empty($referer) && !empty($_POST)) {
            $reasons[] = 'missing_referer';
            $score += 10;
        }
        
        // Проверка Accept заголовков (боты часто не отправляют правильные заголовки)
        $accept = $_SERVER['HTTP_ACCEPT'] ?? '';
        if (empty($accept) || !preg_match('/text\/html|application\/xhtml/i', $accept)) {
            $reasons[] = 'suspicious_accept_header';
            $score += 10;
        }
        
        return [
            'is_bot' => $score >= 50, // Порог для определения бота
            'score' => $score,
            'reasons' => $reasons,
            'user_agent' => $userAgent,
            'ip' => $ip
        ];
    }
    
    /**
     * Блокировать бота
     */
    public static function blockBot(string $reason, array $data = []): void
    {
        // Логируем блокировку
        if (class_exists('OGAS\Core\SecurityLogger')) {
            SecurityLogger::log('bot_blocked', array_merge([
                'reason' => $reason,
                'ip' => Security::getClientIp(),
                'user_agent' => $_SERVER['HTTP_USER_AGENT'] ?? 'Unknown',
                'request_uri' => $_SERVER['REQUEST_URI'] ?? 'Unknown'
            ], $data), 'warning');
        }
        
        // Отправляем 403 Forbidden
        http_response_code(403);
        header('Content-Type: text/html; charset=utf-8');
        
        echo '<!DOCTYPE html>
<html lang="ru">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Доступ запрещен</title>
    <style>
        body {
            font-family: Arial, sans-serif;
            display: flex;
            justify-content: center;
            align-items: center;
            min-height: 100vh;
            margin: 0;
            background: #f5f5f5;
        }
        .error-container {
            text-align: center;
            padding: 40px;
            background: white;
            border-radius: 8px;
            box-shadow: 0 2px 10px rgba(0,0,0,0.1);
        }
        h1 { color: #e74c3c; margin: 0 0 20px 0; }
        p { color: #666; margin: 10px 0; }
    </style>
</head>
<body>
    <div class="error-container">
        <h1>403 - Доступ запрещен</h1>
        <p>Ваш запрос был заблокирован системой безопасности.</p>
        <p>Если вы считаете, что это ошибка, обратитесь к администратору.</p>
    </div>
</body>
</html>';
        
        exit;
    }
    
    /**
     * Проверить и заблокировать бота при необходимости
     */
    public static function checkAndBlock(?string $userAgent = null, ?string $ip = null, bool $strict = false): bool
    {
        $detection = self::detectBot($userAgent, $ip);
        
        // Если строгий режим или высокий score - блокируем
        if ($strict || $detection['is_bot'] || $detection['score'] >= 70) {
            self::blockBot('bot_detected', $detection);
            return false;
        }
        
        // Если средний score - логируем, но не блокируем
        if ($detection['score'] >= 30) {
            if (class_exists('OGAS\Core\SecurityLogger')) {
                SecurityLogger::log('bot_suspicious', $detection, 'info');
            }
        }
        
        return true;
    }
    
    /**
     * Получить HTML для honeypot поля
     */
    public static function getHoneypotField(string $fieldName = 'website', string $label = 'Website'): string
    {
        // Создаем скрытое поле, которое должны заполнить только боты
        return sprintf(
            '<div style="position:absolute;left:-9999px;opacity:0;pointer-events:none;" aria-hidden="true">
                <label for="%s">%s</label>
                <input type="text" name="%s" id="%s" tabindex="-1" autocomplete="off" value="">
            </div>',
            htmlspecialchars($fieldName, ENT_QUOTES, 'UTF-8'),
            htmlspecialchars($label, ENT_QUOTES, 'UTF-8'),
            htmlspecialchars($fieldName, ENT_QUOTES, 'UTF-8'),
            htmlspecialchars($fieldName, ENT_QUOTES, 'UTF-8')
        );
    }
    
    /**
     * Генерировать токен для проверки JavaScript
     */
    public static function generateJsToken(): string
    {
        Session::start();
        $token = bin2hex(random_bytes(16));
        Session::set('_js_token', $token);
        Session::set('_js_token_time', time());
        return $token;
    }
    
    /**
     * Проверить JavaScript токен
     */
    public static function verifyJsToken(?string $token): bool
    {
        Session::start();
        $sessionToken = Session::get('_js_token');
        $tokenTime = Session::get('_js_token_time', 0);
        
        // Токен действителен 1 час
        if (time() - $tokenTime > 3600) {
            return false;
        }
        
        return $sessionToken && $token && hash_equals($sessionToken, $token);
    }
}













