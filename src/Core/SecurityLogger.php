<?php

namespace OGAS\Core;

/**
 * Класс для структурированного логирования событий безопасности
 */
class SecurityLogger
{
    private const LOG_FILE = __DIR__ . '/../../storage/logs/security.log';
    private const MAX_LOG_SIZE = 10 * 1024 * 1024; // 10 MB
    
    /**
     * Логировать событие безопасности
     */
    public static function log(string $event, array $data = [], string $level = 'info'): void
    {
        $logDir = dirname(self::LOG_FILE);
        if (!is_dir($logDir)) {
            @mkdir($logDir, 0755, true);
        }
        
        $logEntry = [
            'timestamp' => date('Y-m-d H:i:s'),
            'level' => $level,
            'event' => $event,
            'data' => $data,
            'ip' => Security::getClientIp(),
            'user_agent' => $_SERVER['HTTP_USER_AGENT'] ?? 'Unknown',
            'request_uri' => $_SERVER['REQUEST_URI'] ?? 'Unknown',
            'request_method' => $_SERVER['REQUEST_METHOD'] ?? 'Unknown'
        ];
        
        // Добавляем ID пользователя, если есть
        if (Session::has('user_id')) {
            $logEntry['user_id'] = Session::get('user_id');
        }
        
        $logLine = json_encode($logEntry, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES) . "\n";
        
        // Ротация логов при превышении размера
        if (file_exists(self::LOG_FILE) && filesize(self::LOG_FILE) > self::MAX_LOG_SIZE) {
            self::rotateLog();
        }
        
        @file_put_contents(self::LOG_FILE, $logLine, FILE_APPEND | LOCK_EX);
    }
    
    /**
     * Логировать попытку входа
     */
    public static function logLoginAttempt(string $email, bool $success, ?string $reason = null): void
    {
        $data = [
            'email' => $email,
            'success' => $success
        ];
        
        if ($reason) {
            $data['reason'] = $reason;
        }
        
        self::log('login_attempt', $data, $success ? 'info' : 'warning');
    }
    
    /**
     * Логировать блокировку от brute force
     */
    public static function logBruteForceBlock(string $identifier, string $type, int $attempts): void
    {
        self::log('brute_force_block', [
            'identifier' => $identifier,
            'type' => $type,
            'attempts' => $attempts
        ], 'warning');
    }
    
    /**
     * Логировать CSRF ошибку
     */
    public static function logCsrfFailure(?string $token = null): void
    {
        self::log('csrf_failure', [
            'token_provided' => $token !== null
        ], 'warning');
    }
    
    /**
     * Логировать превышение rate limit
     */
    public static function logRateLimitExceeded(string $key, int $limit, int $window): void
    {
        self::log('rate_limit_exceeded', [
            'key' => $key,
            'limit' => $limit,
            'window' => $window
        ], 'warning');
    }
    
    /**
     * Логировать подозрительную активность
     */
    public static function logSuspiciousActivity(string $activity, array $details = []): void
    {
        self::log('suspicious_activity', array_merge([
            'activity' => $activity
        ], $details), 'warning');
    }
    
    /**
     * Логировать критическое событие
     */
    public static function logCritical(string $event, array $data = []): void
    {
        self::log($event, $data, 'critical');
        
        // Можно добавить отправку уведомления администратору
        // self::notifyAdmin($event, $data);
    }
    
    /**
     * Логировать изменение пароля
     */
    public static function logPasswordChange(int $userId, bool $success): void
    {
        self::log('password_change', [
            'user_id' => $userId,
            'success' => $success
        ], $success ? 'info' : 'warning');
    }
    
    /**
     * Логировать изменение email
     */
    public static function logEmailChange(int $userId, string $oldEmail, string $newEmail): void
    {
        self::log('email_change', [
            'user_id' => $userId,
            'old_email' => $oldEmail,
            'new_email' => $newEmail
        ], 'info');
    }
    
    /**
     * Логировать доступ к админ-панели
     */
    public static function logAdminAccess(string $action, bool $success = true): void
    {
        self::log('admin_access', [
            'action' => $action,
            'success' => $success
        ], $success ? 'info' : 'warning');
    }
    
    /**
     * Ротация логов
     */
    private static function rotateLog(): void
    {
        $backupFile = self::LOG_FILE . '.' . date('Y-m-d_His');
        @rename(self::LOG_FILE, $backupFile);
        
        // Удаляем старые бэкапы (старше 30 дней)
        $logDir = dirname(self::LOG_FILE);
        $files = glob($logDir . '/security.log.*');
        $now = time();
        
        foreach ($files as $file) {
            if (is_file($file) && ($now - filemtime($file)) > (30 * 24 * 60 * 60)) {
                @unlink($file);
            }
        }
    }
    
    /**
     * Получить последние записи лога
     */
    public static function getRecentLogs(int $limit = 100): array
    {
        if (!file_exists(self::LOG_FILE)) {
            return [];
        }
        
        $lines = file(self::LOG_FILE, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES);
        $logs = [];
        
        // Берем последние N строк
        $lines = array_slice($lines, -$limit);
        
        foreach ($lines as $line) {
            $log = json_decode($line, true);
            if ($log) {
                $logs[] = $log;
            }
        }
        
        return array_reverse($logs); // Новые сверху
    }
}








