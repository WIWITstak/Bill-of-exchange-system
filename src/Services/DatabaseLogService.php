<?php

namespace OGAS\Services;

/**
 * Сервис для работы с логами базы данных MySQL
 */
class DatabaseLogService
{
    // Пути к логам MySQL (из конфигурации Open Server)
    private const QUERIES_LOG = 'f:/openserver/userdata/logs/MySQL-8.0-Win10_queries.log';
    private const ERROR_LOG = 'f:/openserver/userdata/logs/MySQL-8.0-Win10_error.log';
    
    // Альтернативные пути (если Open Server в другом месте)
    private const ALT_QUERIES_LOG = 'c:/openserver/userdata/logs/MySQL-8.0-Win10_queries.log';
    private const ALT_ERROR_LOG = 'c:/openserver/userdata/logs/MySQL-8.0-Win10_error.log';
    
    /**
     * Получить путь к логу запросов
     */
    private static function getQueriesLogPath(): ?string
    {
        if (file_exists(self::QUERIES_LOG)) {
            return self::QUERIES_LOG;
        }
        if (file_exists(self::ALT_QUERIES_LOG)) {
            return self::ALT_QUERIES_LOG;
        }
        return null;
    }
    
    /**
     * Получить путь к логу ошибок
     */
    private static function getErrorLogPath(): ?string
    {
        if (file_exists(self::ERROR_LOG)) {
            return self::ERROR_LOG;
        }
        if (file_exists(self::ALT_ERROR_LOG)) {
            return self::ALT_ERROR_LOG;
        }
        return null;
    }
    
    /**
     * Читать последние строки из файла (эффективно для больших файлов)
     */
    private static function readLastLines(string $filePath, int $lines = 1000): array
    {
        if (!file_exists($filePath) || !is_readable($filePath)) {
            return [];
        }
        
        $file = fopen($filePath, 'r');
        if (!$file) {
            return [];
        }
        
        // Перемещаемся в конец файла
        fseek($file, -1, SEEK_END);
        
        $result = [];
        $currentLine = '';
        $lineCount = 0;
        
        // Читаем файл с конца
        while ($lineCount < $lines && ftell($file) > 0) {
            $char = fgetc($file);
            
            if ($char === "\n") {
                if (!empty(trim($currentLine))) {
                    array_unshift($result, trim($currentLine));
                    $lineCount++;
                }
                $currentLine = '';
            } else {
                $currentLine = $char . $currentLine;
            }
            
            fseek($file, -2, SEEK_CUR);
        }
        
        // Добавляем последнюю строку, если есть
        if (!empty(trim($currentLine))) {
            array_unshift($result, trim($currentLine));
        }
        
        fclose($file);
        
        return $result;
    }
    
    /**
     * Парсить строку лога запросов MySQL
     */
    private static function parseQueryLogLine(string $line): ?array
    {
        // Формат: YYYY-MM-DD HH:MM:SS.###### [ID] Query/Init DB/Connect/Quit
        // Пример: 2024-01-15 10:30:45.123456     15 Query    SELECT * FROM users
        
        if (empty(trim($line))) {
            return null;
        }
        
        // Пытаемся распарсить дату и время
        if (preg_match('/^(\d{4}-\d{2}-\d{2}\s+\d{2}:\d{2}:\d{2}(?:\.\d+)?)\s+(\d+)\s+(\w+)\s+(.*)$/', $line, $matches)) {
            return [
                'timestamp' => $matches[1],
                'thread_id' => (int)$matches[2],
                'type' => $matches[3], // Query, Init DB, Connect, Quit
                'query' => trim($matches[4] ?? ''),
                'raw' => $line
            ];
        }
        
        // Если не удалось распарсить, возвращаем как есть
        return [
            'timestamp' => date('Y-m-d H:i:s'),
            'thread_id' => 0,
            'type' => 'Unknown',
            'query' => $line,
            'raw' => $line
        ];
    }
    
    /**
     * Парсить строку лога ошибок MySQL
     */
    private static function parseErrorLogLine(string $line): ?array
    {
        if (empty(trim($line))) {
            return null;
        }
        
        // Формат: YYYY-MM-DD HH:MM:SS [LEVEL] Message
        // Пример: 2024-01-15 10:30:45 [ERROR] Access denied for user
        
        if (preg_match('/^(\d{4}-\d{2}-\d{2}\s+\d{2}:\d{2}:\d{2})\s+\[(\w+)\]\s+(.*)$/', $line, $matches)) {
            return [
                'timestamp' => $matches[1],
                'level' => $matches[2],
                'message' => trim($matches[3] ?? ''),
                'raw' => $line
            ];
        }
        
        // Если не удалось распарсить, возвращаем как есть
        return [
            'timestamp' => date('Y-m-d H:i:s'),
            'level' => 'INFO',
            'message' => $line,
            'raw' => $line
        ];
    }
    
    /**
     * Получить логи запросов
     */
    public static function getQueryLogs(int $limit = 1000, ?string $search = null, ?string $type = null): array
    {
        $logPath = self::getQueriesLogPath();
        if (!$logPath) {
            return [
                'success' => false,
                'message' => 'Файл лога запросов не найден',
                'logs' => []
            ];
        }
        
        $lines = self::readLastLines($logPath, $limit * 2); // Читаем больше для фильтрации
        
        $logs = [];
        foreach ($lines as $line) {
            $parsed = self::parseQueryLogLine($line);
            if (!$parsed) {
                continue;
            }
            
            // Фильтрация по типу
            if ($type && $parsed['type'] !== $type) {
                continue;
            }
            
            // Фильтрация по поисковому запросу
            if ($search && stripos($parsed['query'], $search) === false) {
                continue;
            }
            
            $logs[] = $parsed;
            
            if (count($logs) >= $limit) {
                break;
            }
        }
        
        return [
            'success' => true,
            'logs' => $logs,
            'total' => count($lines),
            'file_path' => $logPath,
            'file_size' => filesize($logPath)
        ];
    }
    
    /**
     * Получить логи ошибок
     */
    public static function getErrorLogs(int $limit = 1000, ?string $search = null, ?string $level = null): array
    {
        $logPath = self::getErrorLogPath();
        if (!$logPath) {
            return [
                'success' => false,
                'message' => 'Файл лога ошибок не найден',
                'logs' => []
            ];
        }
        
        $lines = self::readLastLines($logPath, $limit * 2); // Читаем больше для фильтрации
        
        $logs = [];
        foreach ($lines as $line) {
            $parsed = self::parseErrorLogLine($line);
            if (!$parsed) {
                continue;
            }
            
            // Фильтрация по уровню
            if ($level && strtoupper($parsed['level']) !== strtoupper($level)) {
                continue;
            }
            
            // Фильтрация по поисковому запросу
            if ($search && stripos($parsed['message'], $search) === false) {
                continue;
            }
            
            $logs[] = $parsed;
            
            if (count($logs) >= $limit) {
                break;
            }
        }
        
        return [
            'success' => true,
            'logs' => $logs,
            'total' => count($lines),
            'file_path' => $logPath,
            'file_size' => filesize($logPath)
        ];
    }
    
    /**
     * Получить статистику по логам
     */
    public static function getLogStatistics(): array
    {
        $queryLogPath = self::getQueriesLogPath();
        $errorLogPath = self::getErrorLogPath();
        
        $stats = [
            'queries_log' => [
                'exists' => $queryLogPath !== null,
                'path' => $queryLogPath,
                'size' => $queryLogPath && file_exists($queryLogPath) ? filesize($queryLogPath) : 0,
                'last_modified' => $queryLogPath && file_exists($queryLogPath) ? filemtime($queryLogPath) : null
            ],
            'error_log' => [
                'exists' => $errorLogPath !== null,
                'path' => $errorLogPath,
                'size' => $errorLogPath && file_exists($errorLogPath) ? filesize($errorLogPath) : 0,
                'last_modified' => $errorLogPath && file_exists($errorLogPath) ? filemtime($errorLogPath) : null
            ]
        ];
        
        // Получаем статистику по типам запросов
        if ($queryLogPath) {
            $queryLogs = self::getQueryLogs(5000);
            if ($queryLogs['success']) {
                $typeStats = [];
                foreach ($queryLogs['logs'] as $log) {
                    $type = $log['type'] ?? 'Unknown';
                    $typeStats[$type] = ($typeStats[$type] ?? 0) + 1;
                }
                $stats['queries_log']['type_stats'] = $typeStats;
            }
        }
        
        // Получаем статистику по уровням ошибок
        if ($errorLogPath) {
            $errorLogs = self::getErrorLogs(5000);
            if ($errorLogs['success']) {
                $levelStats = [];
                foreach ($errorLogs['logs'] as $log) {
                    $level = $log['level'] ?? 'INFO';
                    $levelStats[$level] = ($levelStats[$level] ?? 0) + 1;
                }
                $stats['error_log']['level_stats'] = $levelStats;
            }
        }
        
        return $stats;
    }
    
    /**
     * Форматировать размер файла
     */
    public static function formatFileSize(int $bytes): string
    {
        $units = ['B', 'KB', 'MB', 'GB'];
        $bytes = max($bytes, 0);
        $pow = floor(($bytes ? log($bytes) : 0) / log(1024));
        $pow = min($pow, count($units) - 1);
        $bytes /= pow(1024, $pow);
        return round($bytes, 2) . ' ' . $units[$pow];
    }
}













