<?php

namespace OGAS\Services;

use OGAS\Core\Session;

/**
 * Сервис для управления настройками кэша
 */
class CacheSettingsService
{
    private static string $configFile;
    
    /**
     * Получить путь к файлу конфигурации
     */
    private static function getConfigFile(): string
    {
        if (!isset(self::$configFile)) {
            $projectRoot = realpath(__DIR__ . '/../..');
            self::$configFile = $projectRoot . DIRECTORY_SEPARATOR . 'storage' . DIRECTORY_SEPARATOR . 'cache-config.json';
        }
        return self::$configFile;
    }
    
    /**
     * Получить все настройки кэша
     */
    public static function getSettings(): array
    {
        $configFile = self::getConfigFile();
        $defaultSettings = self::getDefaultSettings();
        
        if (!file_exists($configFile)) {
            return $defaultSettings;
        }
        
        $config = @json_decode(file_get_contents($configFile), true);
        if ($config === null || !is_array($config)) {
            return $defaultSettings;
        }
        
        // Объединяем с настройками по умолчанию
        return array_merge($defaultSettings, $config);
    }
    
    /**
     * Сохранить настройки кэша
     */
    public static function saveSettings(array $settings): bool
    {
        $configFile = self::getConfigFile();
        $configDir = dirname($configFile);
        
        // Создаем директорию, если не существует
        if (!is_dir($configDir)) {
            @mkdir($configDir, 0755, true);
        }
        
        // Получаем текущие настройки
        $currentSettings = self::getSettings();
        
        // Объединяем с текущими настройками
        $newSettings = array_merge($currentSettings, $settings);
        $newSettings['updated_at'] = date('Y-m-d H:i:s');
        $newSettings['updated_by'] = Session::get('user_id') ?? null;
        
        $result = @file_put_contents(
            $configFile,
            json_encode($newSettings, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE),
            LOCK_EX
        );
        
        return $result !== false;
    }
    
    /**
     * Получить настройки по умолчанию
     */
    private static function getDefaultSettings(): array
    {
        return [
            'enabled' => true, // Включен ли кэш
            'logging_enabled' => true,
            'default_ttl' => 3600,
            'ttl' => [
                'categories' => 3600,
                'ratings' => 300,
                'users' => 1800,
                'statistics' => 600,
                'settings' => 86400,
            ],
            'auto_cleanup' => true,
            'cleanup_interval_hours' => 24,
            'max_cache_size_mb' => 100,
            'updated_at' => null,
            'updated_by' => null,
        ];
    }
    
    /**
     * Получить статистику размера кэша
     */
    public static function getCacheSize(): array
    {
        $cacheDir = __DIR__ . '/../../storage/cache';
        $size = 0;
        $fileCount = 0;
        
        if (is_dir($cacheDir)) {
            $iterator = new \RecursiveIteratorIterator(
                new \RecursiveDirectoryIterator($cacheDir, \RecursiveDirectoryIterator::SKIP_DOTS)
            );
            
            foreach ($iterator as $file) {
                if ($file->isFile()) {
                    $size += $file->getSize();
                    $fileCount++;
                }
            }
        }
        
        return [
            'size_bytes' => $size,
            'size_mb' => round($size / (1024 * 1024), 2),
            'file_count' => $fileCount,
        ];
    }
    
    /**
     * Очистить устаревшие файлы кэша
     */
    public static function cleanupExpired(): array
    {
        $cacheDir = __DIR__ . '/../../storage/cache';
        $deletedCount = 0;
        $deletedSize = 0;
        $now = time();
        
        if (!is_dir($cacheDir)) {
            return [
                'deleted_count' => 0,
                'deleted_size' => 0,
                'success' => true,
            ];
        }
        
        $iterator = new \RecursiveIteratorIterator(
            new \RecursiveDirectoryIterator($cacheDir, \RecursiveDirectoryIterator::SKIP_DOTS),
            \RecursiveIteratorIterator::CHILD_FIRST
        );
        
        foreach ($iterator as $file) {
            if ($file->isFile() && $file->getExtension() === 'cache') {
                $data = @file_get_contents($file->getPathname());
                if ($data !== false) {
                    $cacheData = @unserialize($data);
                    if (is_array($cacheData) && isset($cacheData['expires'])) {
                        if ($cacheData['expires'] > 0 && $now > $cacheData['expires']) {
                            $fileSize = $file->getSize();
                            if (@unlink($file->getPathname())) {
                                $deletedCount++;
                                $deletedSize += $fileSize;
                            }
                        }
                    }
                }
            }
        }
        
        return [
            'deleted_count' => $deletedCount,
            'deleted_size' => $deletedSize,
            'deleted_size_mb' => round($deletedSize / (1024 * 1024), 2),
            'success' => true,
        ];
    }
    
    /**
     * Получить список всех ключей кэша (для отображения)
     */
    public static function getCacheKeys(string $pattern = '*'): array
    {
        $keys = [];
        $cacheDir = __DIR__ . '/../../storage/cache';
        
        if (!is_dir($cacheDir)) {
            return $keys;
        }
        
        $iterator = new \RecursiveIteratorIterator(
            new \RecursiveDirectoryIterator($cacheDir, \RecursiveDirectoryIterator::SKIP_DOTS)
        );
        
        $patternRegex = '/^' . str_replace(['*', '?'], ['.*', '.'], $pattern) . '$/';
        
        foreach ($iterator as $file) {
            if ($file->isFile() && $file->getExtension() === 'cache') {
                // Извлекаем ключ из пути (упрощенный вариант)
                // В реальности нужно восстановить ключ из файла
                $relativePath = str_replace($cacheDir . DIRECTORY_SEPARATOR, '', $file->getPathname());
                
                // Попытка восстановить ключ из содержимого
                $data = @file_get_contents($file->getPathname());
                if ($data !== false) {
                    $cacheData = @unserialize($data);
                    // Здесь можно добавить логику для восстановления ключа
                }
            }
        }
        
        return $keys;
    }
}

