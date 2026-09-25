<?php

namespace OGAS\Core;

/**
 * Класс для работы с безопасностью
 */
class Security
{
    /**
     * Генерировать CSRF токен
     */
    public static function generateCsrfToken(): string
    {
        Session::start();
        
        if (!Session::has('_csrf_token')) {
            Session::set('_csrf_token', bin2hex(random_bytes(32)));
        }
        
        return Session::get('_csrf_token');
    }
    
    /**
     * Проверить CSRF токен
     */
    public static function verifyCsrfToken(?string $token): bool
    {
        Session::start();
        
        $sessionToken = Session::get('_csrf_token');
        
        if (!$sessionToken || !$token) {
            return false;
        }
        
        return hash_equals($sessionToken, $token);
    }
    
    /**
     * Кэш для JSON body (php://input можно прочитать только один раз)
     */
    private static ?array $jsonBodyCache = null;
    
    /**
     * Получить JSON body из запроса (с кэшированием)
     * Публичный метод для использования в других местах
     */
    public static function getJsonBody(): ?array
    {
        if (self::$jsonBodyCache !== null) {
            return self::$jsonBodyCache;
        }
        
        $contentType = $_SERVER['CONTENT_TYPE'] ?? '';
        if (strpos($contentType, 'application/json') === false) {
            return null;
        }
        
        $jsonInput = file_get_contents('php://input');
        if (empty($jsonInput)) {
            self::$jsonBodyCache = [];
            return self::$jsonBodyCache;
        }
        
        $data = json_decode($jsonInput, true);
        if (!is_array($data)) {
            self::$jsonBodyCache = [];
            return self::$jsonBodyCache;
        }
        
        self::$jsonBodyCache = $data;
        return self::$jsonBodyCache;
    }
    
    /**
     * Получить CSRF токен из запроса (POST, GET или JSON body)
     */
    public static function getCsrfTokenFromRequest(): ?string
    {
        // Проверяем POST/GET параметры
        if (isset($_POST['_csrf_token'])) {
            return $_POST['_csrf_token'];
        }
        if (isset($_GET['_csrf_token'])) {
            return $_GET['_csrf_token'];
        }
        
        // Проверяем JSON body (для AJAX запросов)
        $jsonBody = self::getJsonBody();
        if ($jsonBody !== null && isset($jsonBody['_csrf_token'])) {
            return $jsonBody['_csrf_token'];
        }
        
        // Проверяем заголовок X-CSRF-Token (для дополнительной поддержки)
        $headers = getallheaders();
        if (is_array($headers)) {
            foreach ($headers as $key => $value) {
                if (strtolower($key) === 'x-csrf-token') {
                    return $value;
                }
            }
        }
        
        return null;
    }
    
    /**
     * Проверить CSRF токен из запроса
     */
    public static function checkCsrfToken(): bool
    {
        $token = self::getCsrfTokenFromRequest();
        $result = self::verifyCsrfToken($token);
        
        // Логируем неудачные попытки CSRF
        if (!$result && class_exists('OGAS\Core\SecurityLogger')) {
            \OGAS\Core\SecurityLogger::logCsrfFailure($token);
        }
        
        return $result;
    }
    
    /**
     * Требовать CSRF токен (выбрасывает исключение, если токен неверен)
     */
    public static function requireCsrfToken(): void
    {
        if (!self::checkCsrfToken()) {
            http_response_code(403);
            header('Content-Type: application/json; charset=utf-8');
            echo json_encode([
                'success' => false,
                'error' => 'CSRF token validation failed',
                'message' => 'Неверный токен безопасности. Обновите страницу и попробуйте снова.'
            ], JSON_UNESCAPED_UNICODE);
            exit;
        }
    }
    
    /**
     * Экранировать HTML для предотвращения XSS
     */
    public static function escapeHtml(?string $string, int $flags = ENT_QUOTES | ENT_HTML5, string $encoding = 'UTF-8'): string
    {
        if ($string === null) {
            return '';
        }
        
        return htmlspecialchars($string, $flags, $encoding);
    }
    
    /**
     * Экранировать HTML для вывода в атрибуты
     */
    public static function escapeAttr(?string $string): string
    {
        return self::escapeHtml($string, ENT_QUOTES | ENT_HTML5);
    }
    
    /**
     * Экранировать JavaScript строку
     */
    public static function escapeJs(?string $string): string
    {
        if ($string === null) {
            return '';
        }
        
        return json_encode($string, JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT | JSON_UNESCAPED_UNICODE);
    }
    
    /**
     * Валидация email
     */
    public static function validateEmail(string $email): bool
    {
        return filter_var($email, FILTER_VALIDATE_EMAIL) !== false;
    }
    
    /**
     * Валидация URL
     */
    public static function validateUrl(string $url): bool
    {
        return filter_var($url, FILTER_VALIDATE_URL) !== false;
    }
    
    /**
     * Санитизация строки (удаление опасных символов)
     */
    public static function sanitizeString(string $string, int $maxLength = 10000): string
    {
        // Удаляем нулевые байты
        $string = str_replace("\0", '', $string);
        
        // Обрезаем до максимальной длины
        if (mb_strlen($string) > $maxLength) {
            $string = mb_substr($string, 0, $maxLength);
        }
        
        return trim($string);
    }
    
    /**
     * Удаление опасных HTML тегов и атрибутов
     */
    public static function stripDangerousHtml(string $input): string
    {
        // Удаляем нулевые байты
        $input = str_replace("\0", '', $input);
        
        // Список опасных тегов
        $dangerousTags = [
            'script', 'iframe', 'object', 'embed', 'form', 'input', 'button',
            'link', 'meta', 'style', 'base', 'applet', 'frame', 'frameset'
        ];
        
        // Удаляем опасные теги и их содержимое
        foreach ($dangerousTags as $tag) {
            $input = preg_replace('/<' . $tag . '[^>]*>.*?<\/' . $tag . '>/is', '', $input);
            $input = preg_replace('/<' . $tag . '[^>]*\/?>/i', '', $input);
        }
        
        // Удаляем опасные атрибуты (onclick, onerror, javascript: и т.д.)
        $dangerousAttributes = [
            'onclick', 'onerror', 'onload', 'onmouseover', 'onfocus', 'onblur',
            'onchange', 'onsubmit', 'onreset', 'onselect', 'onunload'
        ];
        
        foreach ($dangerousAttributes as $attr) {
            $input = preg_replace('/\s*' . preg_quote($attr, '/') . '\s*=\s*["\'][^"\']*["\']/i', '', $input);
            $input = preg_replace('/\s*' . preg_quote($attr, '/') . '\s*=\s*[^\s>]*/i', '', $input);
        }
        
        // Удаляем javascript: протоколы
        $input = preg_replace('/javascript:/i', '', $input);
        $input = preg_replace('/data:text\/html/i', '', $input);
        $input = preg_replace('/vbscript:/i', '', $input);
        
        // Удаляем выражения style с javascript
        $input = preg_replace('/style\s*=\s*["\'][^"\']*expression[^"\']*["\']/i', '', $input);
        
        return trim($input);
    }
    
    /**
     * Валидация целого числа
     */
    public static function validateInt($value, ?int $min = null, ?int $max = null): bool
    {
        if (!is_numeric($value)) {
            return false;
        }
        
        $int = (int)$value;
        
        if ($min !== null && $int < $min) {
            return false;
        }
        
        if ($max !== null && $int > $max) {
            return false;
        }
        
        return true;
    }
    
    /**
     * Валидация типа файла по MIME типу
     */
    public static function validateFileMimeType(string $filePath, array $allowedTypes): bool
    {
        if (!file_exists($filePath)) {
            return false;
        }
        
        $finfo = finfo_open(FILEINFO_MIME_TYPE);
        $mimeType = finfo_file($finfo, $filePath);
        finfo_close($finfo);
        
        return in_array($mimeType, $allowedTypes, true);
    }
    
    /**
     * Проверка размера файла
     */
    public static function validateFileSize(string $filePath, int $maxSizeBytes): bool
    {
        if (!file_exists($filePath)) {
            return false;
        }
        
        return filesize($filePath) <= $maxSizeBytes;
    }
    
    /**
     * Генерация безопасного случайного токена
     */
    public static function generateToken(int $length = 32): string
    {
        return bin2hex(random_bytes($length));
    }
    
    /**
     * Хеширование пароля
     */
    public static function hashPassword(string $password): string
    {
        return password_hash($password, PASSWORD_DEFAULT);
    }
    
    /**
     * Проверка пароля
     */
    public static function verifyPassword(string $password, string $hash): bool
    {
        return password_verify($password, $hash);
    }
    
    /**
     * Регенерация ID сессии (защита от session fixation)
     */
    public static function regenerateSessionId(): void
    {
        Session::start();
        
        // Регенерируем ID сессии
        if (session_status() === PHP_SESSION_ACTIVE) {
            session_regenerate_id(true);
        }
    }
    
    /**
     * Установить безопасные параметры сессии
     */
    public static function setSecureSessionParams(): void
    {
        if (session_status() === PHP_SESSION_NONE) {
            // Устанавливаем безопасные параметры cookie сессии
            ini_set('session.cookie_httponly', '1');
            ini_set('session.cookie_secure', self::isHttps() ? '1' : '0');
            ini_set('session.cookie_samesite', 'Strict');
            ini_set('session.use_strict_mode', '1');
        }
    }
    
    /**
     * Проверить, используется ли HTTPS
     */
    public static function isHttps(): bool
    {
        return (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off') 
            || (!empty($_SERVER['HTTP_X_FORWARDED_PROTO']) && $_SERVER['HTTP_X_FORWARDED_PROTO'] === 'https')
            || (!empty($_SERVER['HTTP_X_FORWARDED_SSL']) && $_SERVER['HTTP_X_FORWARDED_SSL'] === 'on')
            || (isset($_SERVER['SERVER_PORT']) && $_SERVER['SERVER_PORT'] == 443);
    }
    
    /**
     * Получить IP адрес клиента (с учетом прокси)
     */
    public static function getClientIp(): string
    {
        $ipKeys = [
            'HTTP_CF_CONNECTING_IP', // Cloudflare
            'HTTP_X_REAL_IP',
            'HTTP_X_FORWARDED_FOR',
            'REMOTE_ADDR'
        ];
        
        foreach ($ipKeys as $key) {
            if (!empty($_SERVER[$key])) {
                $ip = $_SERVER[$key];
                
                // Если это список IP (X-Forwarded-For), берем первый
                if (strpos($ip, ',') !== false) {
                    $ip = trim(explode(',', $ip)[0]);
                }
                
                // Валидация IP
                if (filter_var($ip, FILTER_VALIDATE_IP, FILTER_FLAG_NO_PRIV_RANGE | FILTER_FLAG_NO_RES_RANGE)) {
                    return $ip;
                }
            }
        }
        
        return $_SERVER['REMOTE_ADDR'] ?? '0.0.0.0';
    }
    
    /**
     * Установить HTTP Security Headers
     */
    public static function setSecurityHeaders(): void
    {
        if (headers_sent()) {
            return;
        }
        
        // X-Frame-Options: защита от clickjacking
        header('X-Frame-Options: SAMEORIGIN');
        
        // X-Content-Type-Options: предотвращение MIME-sniffing
        header('X-Content-Type-Options: nosniff');
        
        // X-XSS-Protection: дополнительная защита от XSS (для старых браузеров)
        header('X-XSS-Protection: 1; mode=block');
        
        // Referrer-Policy: контроль передачи Referer
        header('Referrer-Policy: strict-origin-when-cross-origin');
        
        // Permissions-Policy: ограничение использования API браузера
        header('Permissions-Policy: geolocation=(), microphone=(), camera=()');
        
        // Strict-Transport-Security (HSTS): принудительное использование HTTPS
        if (self::isHttps()) {
            header('Strict-Transport-Security: max-age=31536000; includeSubDomains');
        }
        
        // Content-Security-Policy: базовая защита от XSS
        // Можно настроить более строгую политику в зависимости от страницы
        $csp = "default-src 'self'; " .
               "script-src 'self' 'unsafe-inline' 'unsafe-eval' https://cdn.jsdelivr.net https://cdnjs.cloudflare.com; " .
               "style-src 'self' 'unsafe-inline' https://cdn.jsdelivr.net https://fonts.googleapis.com https://cdnjs.cloudflare.com; " .
               "font-src 'self' https://fonts.gstatic.com https://cdn.jsdelivr.net https://cdnjs.cloudflare.com; " .
               "img-src 'self' data: https:; " .
               "connect-src 'self' wss: ws: https://cdn.jsdelivr.net; " .
               "frame-ancestors 'self';";
        header("Content-Security-Policy: $csp");
    }
    
    /**
     * Проверить сложность пароля
     */
    public static function validatePasswordStrength(string $password): array
    {
        $errors = [];
        $strength = 0;
        
        // Минимальная длина
        if (strlen($password) < 8) {
            $errors[] = 'Пароль должен содержать минимум 8 символов';
        } else {
            $strength++;
        }
        
        // Заглавные буквы
        if (!preg_match('/[A-ZА-ЯЁ]/u', $password)) {
            $errors[] = 'Пароль должен содержать хотя бы одну заглавную букву';
        } else {
            $strength++;
        }
        
        // Строчные буквы
        if (!preg_match('/[a-zа-яё]/u', $password)) {
            $errors[] = 'Пароль должен содержать хотя бы одну строчную букву';
        } else {
            $strength++;
        }
        
        // Цифры
        if (!preg_match('/[0-9]/', $password)) {
            $errors[] = 'Пароль должен содержать хотя бы одну цифру';
        } else {
            $strength++;
        }
        
        // Специальные символы
        if (!preg_match('/[!@#$%^&*()_+\-=\[\]{};\':"\\|,.<>\/?]/', $password)) {
            $errors[] = 'Пароль должен содержать хотя бы один специальный символ (!@#$%^&* и т.д.)';
        } else {
            $strength++;
        }
        
        // Проверка на популярные пароли (базовый список)
        $commonPasswords = ['password', '12345678', 'qwerty', 'admin', 'password123'];
        if (in_array(strtolower($password), $commonPasswords)) {
            $errors[] = 'Использование этого пароля небезопасно';
        }
        
        return [
            'valid' => empty($errors),
            'errors' => $errors,
            'strength' => $strength, // 0-5
            'strength_label' => self::getPasswordStrengthLabel($strength)
        ];
    }
    
    /**
     * Получить метку силы пароля
     */
    private static function getPasswordStrengthLabel(int $strength): string
    {
        $labels = [
            0 => 'Очень слабый',
            1 => 'Слабый',
            2 => 'Средний',
            3 => 'Хороший',
            4 => 'Сильный',
            5 => 'Очень сильный'
        ];
        
        return $labels[$strength] ?? 'Неизвестно';
    }
    
    /**
     * Проверить, является ли URL безопасным для редиректа
     * Разрешаются только относительные пути или HTTPS URL на текущем домене
     */
    public static function isSafeRedirectUrl(string $url): bool
    {
        // Разрешаем только относительные пути или HTTPS URL на текущем домене
        if (empty($url)) {
            return false;
        }

        // Относительный путь
        if (substr($url, 0, 1) === '/') {
            // Проверяем, что нет попыток обхода через ../
            if (strpos($url, '../') !== false || strpos($url, '..\\') !== false) {
                return false;
            }
            return true;
        }

        // Абсолютный URL
        $parsedUrl = parse_url($url);
        $currentHost = $_SERVER['HTTP_HOST'] ?? '';
        $currentScheme = self::isHttps() ? 'https' : 'http';

        // Проверяем схему (только HTTPS или HTTP на том же домене)
        if (!isset($parsedUrl['scheme'])) {
            return false;
        }
        
        // Разрешаем только http/https
        if (!in_array($parsedUrl['scheme'], ['http', 'https'])) {
            return false;
        }

        // Проверяем хост (должен совпадать с текущим)
        if (!isset($parsedUrl['host']) || $parsedUrl['host'] !== $currentHost) {
            return false;
        }

        // Дополнительные проверки для предотвращения обхода
        if (isset($parsedUrl['path']) && (strpos($parsedUrl['path'], '..') !== false || strpos($parsedUrl['path'], '%2e%2e') !== false)) {
            return false;
        }

        return true;
    }
    
    /**
     * Получить безопасный URL для редиректа из параметра запроса
     * 
     * @param string $paramName Имя параметра (например, 'redirect')
     * @param string $defaultUrl URL по умолчанию, если параметр не указан или небезопасен
     * @return string Безопасный URL для редиректа
     */
    public static function getSafeRedirectUrl(string $paramName = 'redirect', string $defaultUrl = '/'): string
    {
        $redirectUrl = $_GET[$paramName] ?? $_POST[$paramName] ?? null;
        
        if ($redirectUrl && self::isSafeRedirectUrl($redirectUrl)) {
            return $redirectUrl;
        }
        
        return $defaultUrl;
    }
    
    /**
     * Валидировать и вернуть безопасный URL для редиректа
     * 
     * @param string $url URL для валидации
     * @param string $defaultUrl URL по умолчанию, если URL небезопасен
     * @return string Безопасный URL
     */
    public static function safeRedirectUrl(string $url, string $defaultUrl = '/'): string
    {
        if (self::isSafeRedirectUrl($url)) {
            return $url;
        }
        
        return $defaultUrl;
    }
    
    /**
     * Выполнить безопасный редирект
     * 
     * @param string $url URL для редиректа
     * @param string $defaultUrl URL по умолчанию, если URL небезопасен
     * @param int $code HTTP код редиректа (302 или 301)
     */
    public static function safeRedirect(string $url, string $defaultUrl = '/', int $code = 302): void
    {
        $safeUrl = self::safeRedirectUrl($url, $defaultUrl);
        
        if (!headers_sent()) {
            header('Location: ' . $safeUrl, true, $code);
            exit;
        }
    }
}

