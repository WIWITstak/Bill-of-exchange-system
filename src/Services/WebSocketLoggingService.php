<?php

namespace OGAS\Services;

/**
 * Сервис для управления логированием WebSocket сервера
 */
class WebSocketLoggingService
{
    private static $configFile;
    
    /**
     * Получить путь к файлу конфигурации
     */
    private static function getConfigFile(): string
    {
        if (self::$configFile === null) {
            $projectRoot = realpath(__DIR__ . '/../..');
            self::$configFile = $projectRoot . DIRECTORY_SEPARATOR . 'websocket' . DIRECTORY_SEPARATOR . 'logging-config.json';
        }
        return self::$configFile;
    }
    
    /**
     * Проверить, включено ли логирование
     */
    public static function isEnabled(): bool
    {
        $configFile = self::getConfigFile();
        
        if (!file_exists($configFile)) {
            // Если файла нет, создаем с включенным логированием по умолчанию
            self::setEnabled(true);
            return true;
        }
        
        $config = @json_decode(file_get_contents($configFile), true);
        if ($config === null || !isset($config['logging_enabled'])) {
            return true; // По умолчанию включено
        }
        
        return (bool)$config['logging_enabled'];
    }
    
    /**
     * Включить логирование
     */
    public static function enable(): bool
    {
        return self::setEnabled(true);
    }
    
    /**
     * Отключить логирование
     */
    public static function disable(): bool
    {
        return self::setEnabled(false);
    }
    
    /**
     * Установить состояние логирования
     */
    public static function setEnabled(bool $enabled): bool
    {
        $configFile = self::getConfigFile();
        $configDir = dirname($configFile);
        
        // Создаем директорию, если не существует
        if (!is_dir($configDir)) {
            @mkdir($configDir, 0755, true);
        }
        
        $config = [
            'logging_enabled' => $enabled,
            'updated_at' => date('Y-m-d H:i:s'),
            'updated_by' => 'admin' // Можно добавить user_id из сессии
        ];
        
        $result = @file_put_contents(
            $configFile,
            json_encode($config, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE),
            LOCK_EX
        );
        
        return $result !== false;
    }
    
    /**
     * Получить статус логирования
     */
    public static function getStatus(): array
    {
        $enabled = self::isEnabled();
        $configFile = self::getConfigFile();
        
        $lastUpdate = null;
        if (file_exists($configFile)) {
            $config = @json_decode(file_get_contents($configFile), true);
            $lastUpdate = $config['updated_at'] ?? null;
        }
        
        return [
            'enabled' => $enabled,
            'last_update' => $lastUpdate,
            'config_file' => $configFile
        ];
    }
}








