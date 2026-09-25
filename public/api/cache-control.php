<?php
/**
 * API для управления кэшем (только для администраторов)
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
use OGAS\Core\Cache;
use OGAS\Core\CacheLogger;
use OGAS\Core\SecurityLogger;

header('Content-Type: application/json; charset=utf-8');

// Проверка прав администратора
Auth::requireAuth();
AdminService::requireAdmin();

$action = $_GET['action'] ?? $_POST['action'] ?? '';

try {
    switch ($action) {
        case 'check':
            // Проверка наличия ключа в кэше
            $key = $_GET['key'] ?? '';
            if (empty($key)) {
                throw new \Exception('Ключ не указан');
            }
            
            $exists = Cache::has($key);
            $result = ['exists' => $exists];
            
            if ($exists) {
                $value = Cache::get($key);
                $result['data_type'] = gettype($value);
                if (is_string($value)) {
                    $result['size'] = strlen($value) . ' байт';
                } elseif (is_array($value)) {
                    $result['size'] = count($value) . ' элементов';
                }
            }
            
            echo json_encode([
                'success' => true,
                ...$result
            ], JSON_UNESCAPED_UNICODE);
            break;
            
        case 'delete':
            Security::requireCsrfToken();
            $key = $_POST['key'] ?? '';
            if (empty($key)) {
                throw new \Exception('Ключ не указан');
            }
            
            $deleted = Cache::delete($key);
            
            SecurityLogger::logAdminAccess('cache_delete', $deleted);
            
            echo json_encode([
                'success' => $deleted,
                'message' => $deleted ? 'Ключ удален' : 'Ключ не найден'
            ], JSON_UNESCAPED_UNICODE);
            break;
            
        case 'delete_by_pattern':
            Security::requireCsrfToken();
            $pattern = $_POST['pattern'] ?? '';
            if (empty($pattern)) {
                throw new \Exception('Паттерн не указан');
            }
            
            $deletedCount = Cache::deleteByPattern($pattern);
            
            SecurityLogger::logAdminAccess('cache_delete_pattern', true);
            
            echo json_encode([
                'success' => true,
                'deleted_count' => $deletedCount,
                'message' => "Удалено ключей: {$deletedCount}"
            ], JSON_UNESCAPED_UNICODE);
            break;
            
        case 'flush':
            Security::requireCsrfToken();
            
            $flushed = Cache::flush();
            
            SecurityLogger::logAdminAccess('cache_flush', $flushed);
            
            echo json_encode([
                'success' => $flushed,
                'message' => $flushed ? 'Кэш очищен' : 'Ошибка очистки кэша'
            ], JSON_UNESCAPED_UNICODE);
            break;
            
        case 'info':
            // Информация о кэше
            $info = Cache::getInfo();
            echo json_encode([
                'success' => true,
                'info' => $info
            ], JSON_UNESCAPED_UNICODE);
            break;
            
        case 'stats':
            // Статистика кэша
            $hours = max(1, min(168, (int)($_GET['hours'] ?? 24)));
            $stats = CacheLogger::getStats($hours);
            
            echo json_encode([
                'success' => true,
                'stats' => $stats,
                'hours' => $hours
            ], JSON_UNESCAPED_UNICODE);
            break;
            
        case 'cleanup':
            // Очистка устаревших записей
            Security::requireCsrfToken();
            
            try {
                $cleanupResult = CacheSettingsService::cleanupExpired();
                
                SecurityLogger::logAdminAccess('cache_cleanup', true);
                
                echo json_encode([
                    'success' => true,
                    'message' => 'Очистка завершена',
                    'deleted_count' => $cleanupResult['deleted_count'] ?? 0,
                    'deleted_size_mb' => $cleanupResult['deleted_size_mb'] ?? 0
                ], JSON_UNESCAPED_UNICODE);
            } catch (\Exception $e) {
                error_log('Cache cleanup error: ' . $e->getMessage());
                throw new \Exception('Ошибка при очистке кэша: ' . $e->getMessage());
            }
            break;
            
        case 'size':
            // Размер кэша
            $size = Cache::getFileCacheStats();
            
            echo json_encode([
                'success' => true,
                'size' => $size
            ], JSON_UNESCAPED_UNICODE);
            break;
            
        default:
            throw new \Exception('Неизвестное действие');
    }
    
} catch (\Exception $e) {
    http_response_code(400);
    echo json_encode([
        'success' => false,
        'error' => $e->getMessage(),
        'message' => $e->getMessage()
    ], JSON_UNESCAPED_UNICODE);
} catch (\Throwable $e) {
    error_log('Cache control API error: ' . $e->getMessage());
    http_response_code(500);
    echo json_encode([
        'success' => false,
        'error' => 'Internal server error',
        'message' => 'Произошла внутренняя ошибка'
    ], JSON_UNESCAPED_UNICODE);
}

ob_end_flush();
exit;

