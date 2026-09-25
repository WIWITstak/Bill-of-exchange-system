<?php

namespace OGAS\Core;

/**
 * Класс для логирования операций с кэшем
 */
class CacheLogger
{
    private const LOG_FILE = __DIR__ . '/../../storage/logs/cache.log';
    private const MAX_LOG_SIZE = 10 * 1024 * 1024; // 10 MB
    
    /**
     * Логировать операцию с кэшем
     */
    public static function log(string $operation, array $data = [], string $level = 'info'): void
    {
        $logDir = dirname(self::LOG_FILE);
        if (!is_dir($logDir)) {
            @mkdir($logDir, 0755, true);
        }
        
        $logEntry = [
            'timestamp' => date('Y-m-d H:i:s'),
            'level' => $level,
            'operation' => $operation,
            'data' => $data,
        ];
        
        // Добавляем ID пользователя, если есть
        if (class_exists('OGAS\Core\Session') && Session::has('user_id')) {
            $logEntry['user_id'] = Session::get('user_id');
        }
        
        // Добавляем IP адрес
        if (class_exists('OGAS\Core\Security')) {
            $logEntry['ip'] = Security::getClientIp();
        }
        
        $logLine = json_encode($logEntry, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES) . "\n";
        
        // Ротация логов при превышении размера
        if (file_exists(self::LOG_FILE) && filesize(self::LOG_FILE) > self::MAX_LOG_SIZE) {
            self::rotateLog();
        }
        
        @file_put_contents(self::LOG_FILE, $logLine, FILE_APPEND | LOCK_EX);
    }
    
    /**
     * Логировать чтение из кэша
     */
    public static function logGet(string $key, bool $hit): void
    {
        self::log('cache_get', [
            'key' => $key,
            'hit' => $hit
        ], $hit ? 'info' : 'debug');
    }
    
    /**
     * Логировать запись в кэш
     */
    public static function logSet(string $key, int $ttl): void
    {
        self::log('cache_set', [
            'key' => $key,
            'ttl' => $ttl
        ], 'info');
    }
    
    /**
     * Логировать удаление из кэша
     */
    public static function logDelete(string $key): void
    {
        self::log('cache_delete', [
            'key' => $key
        ], 'info');
    }
    
    /**
     * Логировать удаление по паттерну
     */
    public static function logDeleteByPattern(string $pattern, int $count): void
    {
        self::log('cache_delete_pattern', [
            'pattern' => $pattern,
            'deleted_count' => $count
        ], 'info');
    }
    
    /**
     * Логировать очистку кэша
     */
    public static function logFlush(): void
    {
        self::log('cache_flush', [], 'warning');
    }
    
    /**
     * Логировать ошибку
     */
    public static function logError(string $operation, string $message, array $context = []): void
    {
        self::log('cache_error', array_merge([
            'operation' => $operation,
            'message' => $message
        ], $context), 'error');
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
        $files = glob($logDir . '/cache.log.*');
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
            $log = @json_decode($line, true);
            if ($log) {
                $logs[] = $log;
            }
        }
        
        return array_reverse($logs); // Новые сверху
    }
    
    /**
     * Получить статистику по логам
     */
    public static function getStats(int $hours = 24): array
    {
        $logs = self::getRecentLogs(10000); // Большое число, чтобы получить достаточно записей
        $cutoffTime = time() - ($hours * 3600);
        
        $stats = [
            'total' => 0,
            'hits' => 0,
            'misses' => 0,
            'sets' => 0,
            'deletes' => 0,
            'flushes' => 0,
            'errors' => 0,
            'by_operation' => []
        ];
        
        foreach ($logs as $log) {
            $logTime = strtotime($log['timestamp'] ?? '');
            if ($logTime < $cutoffTime) {
                continue;
            }
            
            $stats['total']++;
            $operation = $log['operation'] ?? 'unknown';
            
            if (!isset($stats['by_operation'][$operation])) {
                $stats['by_operation'][$operation] = 0;
            }
            $stats['by_operation'][$operation]++;
            
            switch ($operation) {
                case 'cache_get':
                    if (isset($log['data']['hit']) && $log['data']['hit']) {
                        $stats['hits']++;
                    } else {
                        $stats['misses']++;
                    }
                    break;
                case 'cache_set':
                    $stats['sets']++;
                    break;
                case 'cache_delete':
                case 'cache_delete_pattern':
                    $stats['deletes']++;
                    break;
                case 'cache_flush':
                    $stats['flushes']++;
                    break;
                case 'cache_error':
                    $stats['errors']++;
                    break;
            }
        }
        
        return $stats;
    }
}



