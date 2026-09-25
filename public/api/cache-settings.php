<?php
/**
 * API для управления настройками кэша (только для администраторов)
 */

ob_start();
error_reporting(E_ALL);
ini_set('display_errors', 0);
ini_set('log_errors', 1);

register_shutdown_function(function() {
    $error = error_get_last();
    if ($error !== NULL && in_array($error['type'], [E_ERROR, E_CORE_ERROR, E_COMPILE_ERROR, E_PARSE])) {
        ob_clean();
        header('Content-Type: application/json; charset=utf-8');
        http_response_code(500);
        echo json_encode([
            'success' => false,
            'error' => 'Fatal error occurred',
            'message' => 'Произошла критическая ошибка на сервере'
        ], JSON_UNESCAPED_UNICODE);
        ob_end_flush();
        exit;
    }
});

try {
    require_once __DIR__ . '/../../src/bootstrap.php';
} catch (\Throwable $e) {
    ob_clean();
    header('Content-Type: application/json; charset=utf-8');
    http_response_code(500);
    echo json_encode([
        'success' => false,
        'error' => 'Bootstrap error',
        'message' => 'Ошибка при инициализации: ' . $e->getMessage()
    ], JSON_UNESCAPED_UNICODE);
    ob_end_flush();
    exit;
}

ob_clean();

use OGAS\Services\Auth;
use OGAS\Services\AdminService;
use OGAS\Services\CacheSettingsService;
use OGAS\Core\Security;
use OGAS\Core\SecurityLogger;

header('Content-Type: application/json; charset=utf-8');

// Проверка прав администратора
Auth::requireAuth();
AdminService::requireAdmin();

$method = $_SERVER['REQUEST_METHOD'] ?? 'GET';

try {
    if ($method === 'GET') {
        // Получить настройки
        $settings = CacheSettingsService::getSettings();
        
        echo json_encode([
            'success' => true,
            'settings' => $settings
        ], JSON_UNESCAPED_UNICODE);
        
    } elseif ($method === 'POST') {
        // Сохранить настройки
        Security::requireCsrfToken();
        
        // Получаем JSON body (уже кэширован при проверке CSRF)
        $input = Security::getJsonBody();
        if ($input === null || empty($input)) {
            $input = $_POST;
        }
        
        if (!isset($input['settings']) || !is_array($input['settings'])) {
            throw new \Exception('Настройки не указаны');
        }
        
        $settings = $input['settings'];
        
        // Валидация настроек
        if (isset($settings['default_ttl'])) {
            $settings['default_ttl'] = max(60, (int)$settings['default_ttl']);
        }
        
        if (isset($settings['cleanup_interval_hours'])) {
            $settings['cleanup_interval_hours'] = max(1, (int)$settings['cleanup_interval_hours']);
        }
        
        if (isset($settings['max_cache_size_mb'])) {
            $settings['max_cache_size_mb'] = max(1, (int)$settings['max_cache_size_mb']);
        }
        
        // Сохраняем настройки
        $result = CacheSettingsService::saveSettings($settings);
        
        if ($result) {
            // Обновляем состояние кэша в runtime, если изменилось
            if (class_exists('OGAS\Core\Cache')) {
                if (isset($settings['enabled'])) {
                    \OGAS\Core\Cache::setEnabled((bool)$settings['enabled']);
                }
            }
            
            SecurityLogger::logAdminAccess('cache_settings_save', true);
            
            echo json_encode([
                'success' => true,
                'message' => 'Настройки сохранены',
                'settings' => CacheSettingsService::getSettings()
            ], JSON_UNESCAPED_UNICODE);
        } else {
            throw new \Exception('Ошибка сохранения настроек');
        }
        
    } else {
        throw new \Exception('Метод не поддерживается');
    }
    
} catch (\Exception $e) {
    http_response_code(400);
    echo json_encode([
        'success' => false,
        'error' => $e->getMessage(),
        'message' => $e->getMessage()
    ], JSON_UNESCAPED_UNICODE);
} catch (\Throwable $e) {
    error_log('Cache settings API error: ' . $e->getMessage());
    http_response_code(500);
    echo json_encode([
        'success' => false,
        'error' => 'Internal server error',
        'message' => 'Произошла внутренняя ошибка'
    ], JSON_UNESCAPED_UNICODE);
}

ob_end_flush();
exit;

