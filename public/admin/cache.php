<?php
/**
 * Страница управления кэшем (админ-панель)
 * Расширенные настройки и статистика
 */

require_once __DIR__ . '/../../src/bootstrap.php';

use OGAS\Services\Auth;
use OGAS\Services\AdminService;
use OGAS\Services\CacheSettingsService;
use OGAS\Core\SecurityLogger;
use OGAS\Core\Cache;
use OGAS\Core\CacheLogger;
use OGAS\Core\Security;

Auth::requireAuth();
AdminService::requireAdmin();

$user = Auth::user();

// Логируем доступ к управлению кэшем
SecurityLogger::logAdminAccess('cache_management', true);

// Загрузка конфигурации
try {
    $config = require __DIR__ . '/../../config/app.php';
    $cacheConfig = $config['cache'] ?? [];
} catch (\Exception $e) {
    $config = [];
    $cacheConfig = [];
}

try {
    // Получаем информацию о кэше
    $cacheInfo = Cache::getInfo();
} catch (\Exception $e) {
    $cacheInfo = [
        'redis_available' => false,
        'redis_connected' => false,
        'cache_dir' => __DIR__ . '/../../storage/cache',
        'driver' => 'file'
    ];
}

try {
    // Получаем статистику кэша
    $cacheStats = CacheLogger::getStats(24); // За последние 24 часа
} catch (\Exception $e) {
    $cacheStats = [
        'total' => 0,
        'hits' => 0,
        'misses' => 0,
        'sets' => 0,
        'deletes' => 0,
        'flushes' => 0,
        'errors' => 0
    ];
}

try {
    // Получаем настройки кэша
    $cacheSettings = CacheSettingsService::getSettings();
} catch (\Exception $e) {
    $cacheSettings = [
        'enabled' => true,
        'logging_enabled' => true,
        'default_ttl' => 3600,
        'ttl' => [],
        'auto_cleanup' => true,
        'cleanup_interval_hours' => 24,
        'max_cache_size_mb' => 100,
    ];
}

try {
    // Получаем размер кэша
    $cacheSize = Cache::getFileCacheStats();
} catch (\Exception $e) {
    $cacheSize = [
        'size_bytes' => 0,
        'size_mb' => 0,
        'file_count' => 0,
        'dir_count' => 0,
    ];
}

// Получаем информацию о Redis, если доступен
$redisInfo = [];
if (!empty($cacheInfo['redis_connected'])) {
    $redisInfo = $cacheInfo['redis_info'] ?? [];
}

// Расчет эффективности кэша
$hitRate = 0;
if (!empty($cacheStats['total']) && $cacheStats['total'] > 0) {
    $hitRate = ($cacheStats['hits'] / $cacheStats['total']) * 100;
}

$title = 'Управление кэшем';

// Получаем CSRF токен один раз
$csrfToken = Security::generateCsrfToken();

ob_start();
?>

<div class="dashboard">
    <div class="dashboard-header">
        <h2><i class="fas fa-database"></i> Управление кэшем</h2>
        <div class="header-actions">
            <button onclick="refreshCacheInfo()" class="btn btn-secondary">
                <i class="fas fa-sync"></i> Обновить
            </button>
            <a href="/admin/index.php" class="btn btn-secondary">
                <i class="fas fa-arrow-left"></i> Назад
            </a>
        </div>
    </div>

    <!-- Статус кэша -->
    <div class="cache-status-section">
        <div class="status-card status-card-<?= ($cacheInfo['enabled'] ?? true) ? ($cacheInfo['redis_connected'] ? 'success' : 'info') : 'warning' ?>">
            <div class="status-icon">
                <?php if (!($cacheInfo['enabled'] ?? true)): ?>
                    <i class="fas fa-power-off"></i>
                <?php elseif ($cacheInfo['redis_connected']): ?>
                    <i class="fas fa-server"></i>
                <?php else: ?>
                    <i class="fas fa-folder"></i>
                <?php endif; ?>
            </div>
            <div class="status-content">
                <h3>
                    <?php if (!($cacheInfo['enabled'] ?? true)): ?>
                        Кэширование: <span style="color: #f59e0b;">ОТКЛЮЧЕНО</span>
                    <?php else: ?>
                        Драйвер: <?= $cacheInfo['driver'] === 'redis' ? 'Redis' : 'Файловый' ?>
                    <?php endif; ?>
                </h3>
                <p>
                    <?php if (!($cacheInfo['enabled'] ?? true)): ?>
                        <span class="status-badge status-warning">Отключено</span>
                        Кэширование отключено. Все запросы выполняются напрямую к базе данных.
                    <?php elseif ($cacheInfo['redis_connected']): ?>
                        <span class="status-badge status-online">Подключен</span>
                        Redis доступен и используется для кэширования (порт: <?= htmlspecialchars($cacheConfig['redis']['port'] ?? 6379) ?>)
                    <?php else: ?>
                        <span class="status-badge status-warning">Файловый режим</span>
                        Redis недоступен, используется файловое хранилище
                    <?php endif; ?>
                </p>
            </div>
        </div>
    </div>

    <!-- Основная статистика -->
    <div class="admin-section">
        <h3><i class="fas fa-chart-bar"></i> Статистика за последние 24 часа</h3>
        <div class="stats-grid-extended">
            <div class="stat-card-large">
                <div class="stat-icon" style="background: linear-gradient(135deg, #667eea 0%, #764ba2 100%);">
                    <i class="fas fa-bullseye"></i>
                </div>
                <div class="stat-content">
                    <div class="stat-label">Эффективность кэша</div>
                    <div class="stat-value-large" style="color: #667eea;">
                        <?= number_format($hitRate, 1) ?>%
                    </div>
                    <div class="stat-detail">
                        <?= number_format($cacheStats['hits']) ?> попаданий из <?= number_format($cacheStats['total']) ?> операций
                    </div>
                </div>
                <div class="stat-progress">
                    <div class="progress-bar-fill" style="width: <?= min(100, $hitRate) ?>%; background: #667eea;"></div>
                </div>
            </div>

            <div class="stat-card">
                <div class="stat-icon-small" style="background: #10b981;">
                    <i class="fas fa-check-circle"></i>
                </div>
                <div class="stat-content-small">
                    <div class="stat-label">Попаданий (hits)</div>
                    <div class="stat-value" style="color: #10b981;">
                        <?= number_format($cacheStats['hits']) ?>
                    </div>
                </div>
            </div>

            <div class="stat-card">
                <div class="stat-icon-small" style="background: #f59e0b;">
                    <i class="fas fa-times-circle"></i>
                </div>
                <div class="stat-content-small">
                    <div class="stat-label">Промахов (misses)</div>
                    <div class="stat-value" style="color: #f59e0b;">
                        <?= number_format($cacheStats['misses']) ?>
                    </div>
                </div>
            </div>

            <div class="stat-card">
                <div class="stat-icon-small" style="background: #3b82f6;">
                    <i class="fas fa-save"></i>
                </div>
                <div class="stat-content-small">
                    <div class="stat-label">Записей</div>
                    <div class="stat-value"><?= number_format($cacheStats['sets']) ?></div>
                </div>
            </div>

            <div class="stat-card">
                <div class="stat-icon-small" style="background: #8b5cf6;">
                    <i class="fas fa-trash"></i>
                </div>
                <div class="stat-content-small">
                    <div class="stat-label">Удалений</div>
                    <div class="stat-value"><?= number_format($cacheStats['deletes']) ?></div>
                </div>
            </div>

            <div class="stat-card">
                <div class="stat-icon-small" style="background: #ef4444;">
                    <i class="fas fa-exclamation-triangle"></i>
                </div>
                <div class="stat-content-small">
                    <div class="stat-label">Ошибок</div>
                    <div class="stat-value" style="color: #ef4444;">
                        <?= number_format($cacheStats['errors']) ?>
                    </div>
                </div>
            </div>
        </div>
    </div>

    <!-- Информация о кэше -->
    <div class="admin-section">
        <h3><i class="fas fa-info-circle"></i> Информация о кэше</h3>
        <div class="info-grid-extended">
            <div class="info-card-enhanced">
                <div class="info-icon"><i class="fas fa-hdd"></i></div>
                <div class="info-content">
                    <div class="info-label">Размер кэша</div>
                    <div class="info-value-large"><?= number_format($cacheSize['size_mb'], 2) ?> МБ</div>
                    <div class="info-detail"><?= number_format($cacheSize['file_count']) ?> файлов</div>
                </div>
            </div>

            <div class="info-card-enhanced">
                <div class="info-icon"><i class="fas fa-folder"></i></div>
                <div class="info-content">
                    <div class="info-label">Директория</div>
                    <div class="info-value-path"><?= htmlspecialchars($cacheInfo['cache_dir']) ?></div>
                </div>
            </div>

            <?php if ($cacheInfo['redis_connected']): ?>
                <div class="info-card-enhanced">
                    <div class="info-icon"><i class="fas fa-database"></i></div>
                    <div class="info-content">
                        <div class="info-label">Ключей в Redis</div>
                        <div class="info-value-large"><?= number_format($cacheInfo['redis_db_size'] ?? 0) ?></div>
                    </div>
                </div>
            <?php endif; ?>

            <div class="info-card-enhanced">
                <div class="info-icon"><i class="fas fa-file-alt"></i></div>
                <div class="info-content">
                    <div class="info-label">Логирование</div>
                    <div class="info-value">
                        <?php if ($cacheSettings['logging_enabled']): ?>
                            <span class="badge badge-success"><i class="fas fa-check"></i> Включено</span>
                        <?php else: ?>
                            <span class="badge badge-secondary"><i class="fas fa-times"></i> Отключено</span>
                        <?php endif; ?>
                    </div>
                </div>
            </div>
        </div>
    </div>

    <!-- Настройки кэша -->
    <div class="admin-section">
        <h3><i class="fas fa-cog"></i> Настройки кэширования</h3>
        
        <div class="settings-tabs">
            <button class="tab-button active" onclick="switchTab('general')">
                <i class="fas fa-sliders-h"></i> Общие
            </button>
            <button class="tab-button" onclick="switchTab('ttl')">
                <i class="fas fa-clock"></i> Время жизни (TTL)
            </button>
            <button class="tab-button" onclick="switchTab('redis')">
                <i class="fas fa-server"></i> Redis
            </button>
            <button class="tab-button" onclick="switchTab('cleanup')">
                <i class="fas fa-broom"></i> Очистка
            </button>
        </div>

        <!-- Общие настройки -->
        <div id="tab-general" class="tab-content active">
            <form id="cache-settings-form" class="settings-form">
                <div class="form-group" style="padding: 20px; background: #f0f9ff; border: 2px solid #3b82f6; border-radius: 8px; margin-bottom: 25px;">
                    <label class="form-label" style="font-size: 1.1em; font-weight: 600; color: #1e40af;">
                        <input type="checkbox" name="enabled" 
                               <?= ($cacheSettings['enabled'] ?? true) ? 'checked' : '' ?> 
                               id="cache-enabled"
                               style="width: 20px; height: 20px; margin-right: 10px;">
                        <span>Включить кэширование</span>
                    </label>
                    <div class="form-help" style="margin-top: 10px; padding-left: 30px;">
                        <strong>Внимание:</strong> При отключении кэширования все запросы будут выполняться напрямую к базе данных. 
                        Это может значительно снизить производительность системы.
                    </div>
                </div>

                <div class="form-group">
                    <label class="form-label">
                        <input type="checkbox" name="logging_enabled" 
                               <?= $cacheSettings['logging_enabled'] ? 'checked' : '' ?> 
                               id="logging-enabled">
                        <span>Включить логирование операций с кэшем</span>
                    </label>
                    <div class="form-help">Все операции с кэшем будут записываться в лог</div>
                </div>

                <div class="form-group">
                    <label class="form-label">Время жизни по умолчанию (секунды)</label>
                    <input type="number" name="default_ttl" value="<?= $cacheSettings['default_ttl'] ?? 3600 ?>" 
                           class="form-input" min="60" step="60">
                    <div class="form-help">Стандартное время жизни кэша (1 час = 3600 сек)</div>
                </div>

                <div class="form-group">
                    <label class="form-label">
                        <input type="checkbox" name="auto_cleanup" 
                               <?= ($cacheSettings['auto_cleanup'] ?? true) ? 'checked' : '' ?>>
                        <span>Автоматическая очистка устаревших записей</span>
                    </label>
                    <div class="form-help">Автоматически удалять записи с истекшим сроком действия</div>
                </div>

                <div class="form-group">
                    <label class="form-label">Интервал автоматической очистки (часы)</label>
                    <input type="number" name="cleanup_interval_hours" 
                           value="<?= $cacheSettings['cleanup_interval_hours'] ?? 24 ?>" 
                           class="form-input" min="1" step="1">
                    <div class="form-help">Как часто выполнять автоматическую очистку</div>
                </div>

                <div class="form-group">
                    <label class="form-label">Максимальный размер кэша (МБ)</label>
                    <input type="number" name="max_cache_size_mb" 
                           value="<?= $cacheSettings['max_cache_size_mb'] ?? 100 ?>" 
                           class="form-input" min="1" step="10">
                    <div class="form-help">При достижении этого размера будет выполнена очистка</div>
                </div>

                <button type="submit" class="btn btn-primary">
                    <i class="fas fa-save"></i> Сохранить настройки
                </button>
            </form>
        </div>

        <!-- Настройки TTL -->
        <div id="tab-ttl" class="tab-content">
            <form id="cache-ttl-form" class="settings-form">
                <div class="ttl-settings-grid">
                    <div class="form-group">
                        <label class="form-label">
                            <i class="fas fa-folder" style="color: #667eea;"></i>
                            Категории (секунды)
                        </label>
                        <input type="number" name="ttl[categories]" 
                               value="<?= $cacheConfig['ttl']['categories'] ?? 3600 ?>" 
                               class="form-input" min="60" step="60">
                        <div class="form-help">Рекомендуется: 3600 (1 час)</div>
                    </div>

                    <div class="form-group">
                        <label class="form-label">
                            <i class="fas fa-star" style="color: #f59e0b;"></i>
                            Рейтинги (секунды)
                        </label>
                        <input type="number" name="ttl[ratings]" 
                               value="<?= $cacheConfig['ttl']['ratings'] ?? 300 ?>" 
                               class="form-input" min="60" step="60">
                        <div class="form-help">Рекомендуется: 300 (5 минут)</div>
                    </div>

                    <div class="form-group">
                        <label class="form-label">
                            <i class="fas fa-user" style="color: #10b981;"></i>
                            Пользователи (секунды)
                        </label>
                        <input type="number" name="ttl[users]" 
                               value="<?= $cacheConfig['ttl']['users'] ?? 1800 ?>" 
                               class="form-input" min="60" step="60">
                        <div class="form-help">Рекомендуется: 1800 (30 минут)</div>
                    </div>

                    <div class="form-group">
                        <label class="form-label">
                            <i class="fas fa-chart-line" style="color: #3b82f6;"></i>
                            Статистика (секунды)
                        </label>
                        <input type="number" name="ttl[statistics]" 
                               value="<?= $cacheConfig['ttl']['statistics'] ?? 600 ?>" 
                               class="form-input" min="60" step="60">
                        <div class="form-help">Рекомендуется: 600 (10 минут)</div>
                    </div>

                    <div class="form-group">
                        <label class="form-label">
                            <i class="fas fa-cog" style="color: #8b5cf6;"></i>
                            Настройки (секунды)
                        </label>
                        <input type="number" name="ttl[settings]" 
                               value="<?= $cacheConfig['ttl']['settings'] ?? 86400 ?>" 
                               class="form-input" min="60" step="60">
                        <div class="form-help">Рекомендуется: 86400 (24 часа)</div>
                    </div>
                </div>

                <button type="submit" class="btn btn-primary">
                    <i class="fas fa-save"></i> Сохранить TTL настройки
                </button>
            </form>
        </div>

        <!-- Настройки Redis -->
        <div id="tab-redis" class="tab-content">
            <?php if ($cacheInfo['redis_connected']): ?>
                <div class="redis-info-section">
                    <div class="info-alert info-success">
                        <i class="fas fa-check-circle"></i>
                        <div>
                            <strong>Redis подключен</strong>
                            <p>Используется для хранения кэша</p>
                        </div>
                    </div>

                    <div class="redis-details">
                        <div class="detail-item">
                            <span class="detail-label">Хост:</span>
                            <span class="detail-value"><?= htmlspecialchars($cacheConfig['redis']['host'] ?? '127.0.0.1') ?></span>
                        </div>
                        <div class="detail-item">
                            <span class="detail-label">Порт:</span>
                            <span class="detail-value"><?= $cacheConfig['redis']['port'] ?? 6379 ?></span>
                        </div>
                        <div class="detail-item">
                            <span class="detail-label">База данных:</span>
                            <span class="detail-value"><?= $cacheConfig['redis']['database'] ?? 0 ?></span>
                        </div>
                        <?php if (!empty($redisInfo)): ?>
                            <div class="detail-item">
                                <span class="detail-label">Версия Redis:</span>
                                <span class="detail-value"><?= $redisInfo['redis_version'] ?? 'unknown' ?></span>
                            </div>
                            <div class="detail-item">
                                <span class="detail-label">Используемая память:</span>
                                <span class="detail-value">
                                    <?php
                                    $usedMemory = $redisInfo['used_memory_human'] ?? 'unknown';
                                    echo htmlspecialchars($usedMemory);
                                    ?>
                                </span>
                            </div>
                        <?php endif; ?>
                    </div>
                </div>
            <?php else: ?>
                <div class="info-alert info-warning">
                    <i class="fas fa-exclamation-triangle"></i>
                    <div>
                        <strong>Redis не подключен</strong>
                        <p>Используется файловое хранилище. Для улучшения производительности рекомендуется установить Redis.</p>
                        <p style="margin-top: 10px; font-size: 0.9em;">
                            Настройки Redis можно изменить в файле <code>config/app.php</code> или через переменные окружения.
                        </p>
                    </div>
                </div>
            <?php endif; ?>
        </div>

        <!-- Настройки очистки -->
        <div id="tab-cleanup" class="tab-content">
            <div class="cleanup-section">
                <h4>Автоматическая очистка</h4>
                <p class="section-description">
                    Очистка устаревших записей выполняется автоматически. 
                    Вы также можете запустить очистку вручную.
                </p>
                
                <button onclick="runCleanup()" class="btn btn-warning">
                    <i class="fas fa-broom"></i> Очистить устаревшие записи
                </button>

                <div id="cleanup-result" class="mt-3"></div>
            </div>
        </div>
    </div>

    <!-- Управление кэшем -->
    <div class="admin-section">
        <h3><i class="fas fa-tasks"></i> Управление кэшем</h3>
        <div class="cache-actions-grid">
            <button onclick="clearCacheByPattern('categories:*')" class="action-button action-warning">
                <i class="fas fa-folder"></i>
                <span>Очистить категории</span>
                <small>Удалит весь кэш категорий</small>
            </button>
            
            <button onclick="clearCacheByPattern('rating:*')" class="action-button action-warning">
                <i class="fas fa-star"></i>
                <span>Очистить рейтинги</span>
                <small>Удалит весь кэш рейтингов</small>
            </button>
            
            <button onclick="clearCacheByPattern('category:*')" class="action-button action-warning">
                <i class="fas fa-tag"></i>
                <span>Очистить категории (одиночные)</span>
                <small>Удалит кэш отдельных категорий</small>
            </button>
            
            <button onclick="clearCacheByPattern('user:*')" class="action-button action-warning">
                <i class="fas fa-user"></i>
                <span>Очистить пользователей</span>
                <small>Удалит кэш пользователей</small>
            </button>
            
            <button onclick="clearAllCache()" class="action-button action-danger">
                <i class="fas fa-trash-alt"></i>
                <span>Очистить весь кэш</span>
                <small>ВНИМАНИЕ: Удалит все данные</small>
            </button>
            
            <a href="/admin/cache-logs.php" class="action-button action-info">
                <i class="fas fa-file-alt"></i>
                <span>Просмотр логов</span>
                <small>Открыть страницу логов</small>
            </a>
            
            <a href="/admin/test-redis.php" class="action-button action-warning">
                <i class="fas fa-stethoscope"></i>
                <span>Диагностика Redis</span>
                <small>Проверить подключение к Redis</small>
            </a>
        </div>
    </div>

    <!-- Операции по ключам -->
    <div class="admin-section">
        <h3><i class="fas fa-key"></i> Работа с ключами</h3>
        <div class="key-operations">
            <div class="form-group-inline">
                <input type="text" id="cache-key" class="form-input-large" 
                       placeholder="Введите ключ кэша (например: category:id:1)">
                <div class="button-group">
                    <button onclick="checkCacheKey()" class="btn btn-secondary">
                        <i class="fas fa-search"></i> Проверить
                    </button>
                    <button onclick="deleteCacheKey()" class="btn btn-warning">
                        <i class="fas fa-trash"></i> Удалить
                    </button>
                </div>
            </div>
            <div id="cache-key-result" class="mt-3"></div>
        </div>
    </div>
</div>

<script>
// Переключение табов
function switchTab(tabName) {
    // Скрываем все табы
    document.querySelectorAll('.tab-content').forEach(tab => {
        tab.classList.remove('active');
    });
    
    // Убираем активность со всех кнопок
    document.querySelectorAll('.tab-button').forEach(btn => {
        btn.classList.remove('active');
    });
    
    // Показываем выбранный таб
    document.getElementById('tab-' + tabName).classList.add('active');
    
    // Активируем кнопку
    if (event && event.target) {
        event.target.classList.add('active');
    }
}

// Все функции, доступные глобально
window.clearCacheByPattern = function(pattern) {
    if (!confirm(`Вы уверены, что хотите очистить кэш по паттерну "${pattern}"?`)) {
        return;
    }
    
    fetch('/api/cache-control.php', {
        method: 'POST',
        headers: {
            'Content-Type': 'application/x-www-form-urlencoded',
        },
        body: new URLSearchParams({
            action: 'delete_by_pattern',
            pattern: pattern,
            _csrf_token: '<?= $csrfToken ?>'
        })
    })
    .then(response => response.json())
    .then(data => {
        if (data.success) {
            if (typeof showNotification === 'function') {
                showNotification(`Кэш очищен. Удалено ключей: ${data.deleted_count || 0}`, 'success');
            } else {
                alert(`Кэш очищен. Удалено ключей: ${data.deleted_count || 0}`);
            }
            setTimeout(() => location.reload(), 1000);
        } else {
            if (typeof showNotification === 'function') {
                showNotification('Ошибка: ' + (data.message || 'Неизвестная ошибка'), 'error');
            } else {
                alert('Ошибка: ' + (data.message || 'Неизвестная ошибка'));
            }
        }
    })
    .catch(error => {
        if (typeof showNotification === 'function') {
            showNotification('Ошибка: ' + error.message, 'error');
        } else {
            alert('Ошибка: ' + error.message);
        }
    });
};

window.clearAllCache = function() {
    if (!confirm('Вы уверены, что хотите очистить весь кэш? Это действие нельзя отменить!')) {
        return;
    }
    
    if (!confirm('Вы ТОЧНО уверены? Это удалит все данные из кэша!')) {
        return;
    }
    
    fetch('/api/cache-control.php', {
        method: 'POST',
        headers: {
            'Content-Type': 'application/x-www-form-urlencoded',
        },
        body: new URLSearchParams({
            action: 'flush',
            _csrf_token: '<?= $csrfToken ?>'
        })
    })
    .then(response => response.json())
    .then(data => {
        if (data.success) {
            if (typeof showNotification === 'function') {
                showNotification('Весь кэш очищен', 'success');
            } else {
                alert('Весь кэш очищен');
            }
            setTimeout(() => location.reload(), 1000);
        } else {
            if (typeof showNotification === 'function') {
                showNotification('Ошибка: ' + (data.message || 'Неизвестная ошибка'), 'error');
            } else {
                alert('Ошибка: ' + (data.message || 'Неизвестная ошибка'));
            }
        }
    })
    .catch(error => {
        if (typeof showNotification === 'function') {
            showNotification('Ошибка: ' + error.message, 'error');
        } else {
            alert('Ошибка: ' + error.message);
        }
    });
};

window.refreshCacheInfo = function() {
    location.reload();
};

window.checkCacheKey = function() {
    const key = document.getElementById('cache-key').value.trim();
    if (!key) {
        if (typeof showNotification === 'function') {
            showNotification('Введите ключ', 'warning');
        } else {
            alert('Введите ключ');
        }
        return;
    }
    
    fetch('/api/cache-control.php?action=check&key=' + encodeURIComponent(key))
    .then(response => response.json())
    .then(data => {
        const resultDiv = document.getElementById('cache-key-result');
        if (data.exists) {
            resultDiv.innerHTML = `
                <div class="alert alert-success">
                    <i class="fas fa-check-circle"></i>
                    <strong>Ключ найден!</strong><br>
                    Тип данных: ${data.data_type || 'unknown'}<br>
                    Размер: ${data.size || 'unknown'}
                </div>
            `;
        } else {
            resultDiv.innerHTML = `
                <div class="alert alert-warning">
                    <i class="fas fa-exclamation-triangle"></i>
                    Ключ не найден в кэше
                </div>
            `;
        }
    })
    .catch(error => {
        if (typeof showNotification === 'function') {
            showNotification('Ошибка: ' + error.message, 'error');
        } else {
            alert('Ошибка: ' + error.message);
        }
    });
};

window.deleteCacheKey = function() {
    const key = document.getElementById('cache-key').value.trim();
    if (!key) {
        if (typeof showNotification === 'function') {
            showNotification('Введите ключ', 'warning');
        } else {
            alert('Введите ключ');
        }
        return;
    }
    
    if (!confirm(`Удалить ключ "${key}" из кэша?`)) {
        return;
    }
    
    fetch('/api/cache-control.php', {
        method: 'POST',
        headers: {
            'Content-Type': 'application/x-www-form-urlencoded',
        },
        body: new URLSearchParams({
            action: 'delete',
            key: key,
            _csrf_token: '<?= $csrfToken ?>'
        })
    })
    .then(response => response.json())
    .then(data => {
        if (data.success) {
            if (typeof showNotification === 'function') {
                showNotification('Ключ удален из кэша', 'success');
            } else {
                alert('Ключ удален из кэша');
            }
            document.getElementById('cache-key-result').innerHTML = '';
        } else {
            if (typeof showNotification === 'function') {
                showNotification('Ошибка: ' + (data.message || 'Неизвестная ошибка'), 'error');
            } else {
                alert('Ошибка: ' + (data.message || 'Неизвестная ошибка'));
            }
        }
    })
    .catch(error => {
        if (typeof showNotification === 'function') {
            showNotification('Ошибка: ' + error.message, 'error');
        } else {
            alert('Ошибка: ' + error.message);
        }
    });
};

window.runCleanup = function() {
    if (!confirm('Запустить очистку устаревших записей кэша?')) {
        return;
    }
    
    fetch('/api/cache-control.php', {
        method: 'POST',
        headers: {
            'Content-Type': 'application/x-www-form-urlencoded',
        },
        body: new URLSearchParams({
            action: 'cleanup',
            _csrf_token: '<?= $csrfToken ?>'
        })
    })
    .then(response => response.json())
    .then(data => {
        const resultDiv = document.getElementById('cleanup-result');
        if (data.success) {
            resultDiv.innerHTML = `
                <div class="alert alert-success">
                    <i class="fas fa-check-circle"></i>
                    <strong>Очистка завершена!</strong><br>
                    Удалено записей: ${data.deleted_count || 0}<br>
                    Освобождено: ${data.deleted_size_mb || 0} МБ
                </div>
            `;
            setTimeout(() => location.reload(), 2000);
        } else {
            resultDiv.innerHTML = `
                <div class="alert alert-error">
                    <i class="fas fa-exclamation-circle"></i>
                    Ошибка: ${data.message || 'Неизвестная ошибка'}
                </div>
            `;
        }
    })
    .catch(error => {
        if (typeof showNotification === 'function') {
            showNotification('Ошибка: ' + error.message, 'error');
        } else {
            alert('Ошибка: ' + error.message);
        }
    });
};

// Сохранение настроек через API
window.saveCacheSettings = function(settings) {
    fetch('/api/cache-settings.php', {
        method: 'POST',
        headers: {
            'Content-Type': 'application/json',
        },
        body: JSON.stringify({
            settings: settings,
            _csrf_token: '<?= $csrfToken ?>'
        })
    })
    .then(response => response.json())
    .then(data => {
        if (data.success) {
            if (typeof window.showNotification === 'function') {
                window.showNotification('Настройки сохранены', 'success');
            } else {
                alert('Настройки сохранены');
            }
            setTimeout(() => location.reload(), 1000);
        } else {
            if (typeof window.showNotification === 'function') {
                window.showNotification('Ошибка: ' + (data.message || 'Неизвестная ошибка'), 'error');
            } else {
                alert('Ошибка: ' + (data.message || 'Неизвестная ошибка'));
            }
        }
    })
    .catch(error => {
        if (typeof window.showNotification === 'function') {
            window.showNotification('Ошибка: ' + error.message, 'error');
        } else {
            alert('Ошибка: ' + error.message);
        }
    });
};

// Сохранение настроек через API
window.saveCacheSettings = function(settings) {
    fetch('/api/cache-settings.php', {
        method: 'POST',
        headers: {
            'Content-Type': 'application/json',
        },
        body: JSON.stringify({
            settings: settings,
            _csrf_token: '<?= $csrfToken ?>'
        })
    })
    .then(response => response.json())
    .then(data => {
        if (data.success) {
            if (typeof window.showNotification === 'function') {
                window.showNotification('Настройки сохранены', 'success');
            } else {
                alert('Настройки сохранены');
            }
            setTimeout(() => location.reload(), 1000);
        } else {
            if (typeof window.showNotification === 'function') {
                window.showNotification('Ошибка: ' + (data.message || 'Неизвестная ошибка'), 'error');
            } else {
                alert('Ошибка: ' + (data.message || 'Неизвестная ошибка'));
            }
        }
    })
    .catch(error => {
        if (typeof window.showNotification === 'function') {
            window.showNotification('Ошибка: ' + error.message, 'error');
        } else {
            alert('Ошибка: ' + error.message);
        }
    });
};

// Сохранение общих настроек
document.addEventListener('DOMContentLoaded', function() {
    const settingsForm = document.getElementById('cache-settings-form');
    if (settingsForm) {
        settingsForm.addEventListener('submit', function(e) {
            e.preventDefault();
            
            const formData = new FormData(this);
            const settings = {};
            
            for (const [key, value] of formData.entries()) {
                if (key === 'enabled' || key === 'logging_enabled' || key === 'auto_cleanup') {
                    settings[key] = true;
                } else {
                    settings[key] = isNaN(value) ? value : parseInt(value);
                }
            }
            
            if (!formData.has('enabled')) {
                settings['enabled'] = false;
            }
            if (!formData.has('logging_enabled')) {
                settings['logging_enabled'] = false;
            }
            if (!formData.has('auto_cleanup')) {
                settings['auto_cleanup'] = false;
            }
            
            window.saveCacheSettings(settings);
        });
    }
    
    const ttlForm = document.getElementById('cache-ttl-form');
    if (ttlForm) {
        ttlForm.addEventListener('submit', function(e) {
            e.preventDefault();
            
            const formData = new FormData(this);
            const ttlSettings = {};
            
            for (const [key, value] of formData.entries()) {
                if (key.startsWith('ttl[')) {
                    const ttlKey = key.match(/ttl\[(.+)\]/)[1];
                    ttlSettings[ttlKey] = parseInt(value);
                }
            }
            
            // Сохраняем только TTL
            window.saveCacheSettings({ ttl: ttlSettings });
        });
    }
});

// Показать уведомление
window.showNotification = function(message, type = 'info') {
    const notification = document.createElement('div');
    notification.className = `notification notification-${type}`;
    notification.innerHTML = `
        <i class="fas fa-${type === 'success' ? 'check-circle' : type === 'error' ? 'exclamation-circle' : 'info-circle'}"></i>
        <span>${message}</span>
        <button onclick="this.parentElement.remove()" class="notification-close">&times;</button>
    `;
    
    document.body.appendChild(notification);
    
    setTimeout(() => {
        notification.classList.add('show');
    }, 10);
    
    setTimeout(() => {
        notification.classList.remove('show');
        setTimeout(() => notification.remove(), 300);
    }, 3000);
}
</script>

<style>
/* Общие стили */
.cache-status-section {
    margin-bottom: 30px;
}

.status-card {
    display: flex;
    align-items: center;
    gap: 20px;
    padding: 25px;
    border-radius: 12px;
    box-shadow: 0 4px 6px rgba(0,0,0,0.1);
    transition: transform 0.2s, box-shadow 0.2s;
}

.status-card:hover {
    transform: translateY(-2px);
    box-shadow: 0 6px 12px rgba(0,0,0,0.15);
}

.status-card-success {
    background: linear-gradient(135deg, #10b981 0%, #059669 100%);
    color: white;
}

.status-card-info {
    background: linear-gradient(135deg, #3b82f6 0%, #2563eb 100%);
    color: white;
}

.status-card-warning {
    background: linear-gradient(135deg, #f59e0b 0%, #d97706 100%);
    color: white;
}

.status-icon {
    font-size: 48px;
    opacity: 0.9;
}

.status-content h3 {
    margin: 0 0 10px 0;
    font-size: 1.5em;
}

.status-content p {
    margin: 0;
    opacity: 0.9;
}

.status-badge {
    display: inline-block;
    padding: 4px 12px;
    border-radius: 12px;
    font-size: 0.85em;
    font-weight: 600;
    margin-right: 10px;
}

.status-online {
    background: rgba(255,255,255,0.2);
    color: white;
}

.status-warning {
    background: rgba(255,255,255,0.2);
    color: white;
}

/* Статистика */
.stats-grid-extended {
    display: grid;
    grid-template-columns: 2fr repeat(5, 1fr);
    gap: 20px;
    margin-top: 20px;
}

.stat-card-large {
    background: white;
    padding: 25px;
    border-radius: 12px;
    box-shadow: 0 2px 8px rgba(0,0,0,0.1);
    display: flex;
    flex-direction: column;
    gap: 15px;
}

.stat-icon {
    width: 60px;
    height: 60px;
    border-radius: 12px;
    display: flex;
    align-items: center;
    justify-content: center;
    color: white;
    font-size: 24px;
}

.stat-value-large {
    font-size: 42px;
    font-weight: bold;
    line-height: 1;
}

.stat-progress {
    height: 8px;
    background: #e5e7eb;
    border-radius: 4px;
    overflow: hidden;
}

.progress-bar-fill {
    height: 100%;
    border-radius: 4px;
    transition: width 0.3s;
}

.stat-card {
    background: white;
    padding: 20px;
    border-radius: 12px;
    box-shadow: 0 2px 8px rgba(0,0,0,0.1);
    display: flex;
    align-items: center;
    gap: 15px;
    transition: transform 0.2s, box-shadow 0.2s;
}

.stat-card:hover {
    transform: translateY(-2px);
    box-shadow: 0 4px 12px rgba(0,0,0,0.15);
}

.stat-icon-small {
    width: 48px;
    height: 48px;
    border-radius: 10px;
    display: flex;
    align-items: center;
    justify-content: center;
    color: white;
    font-size: 20px;
    flex-shrink: 0;
}

.stat-content-small {
    flex: 1;
}

.stat-label {
    font-size: 0.85em;
    color: #666;
    margin-bottom: 8px;
}

.stat-value {
    font-size: 24px;
    font-weight: bold;
    color: #333;
    line-height: 1;
}

/* Информация */
.info-grid-extended {
    display: grid;
    grid-template-columns: repeat(auto-fit, minmax(250px, 1fr));
    gap: 20px;
    margin-top: 20px;
}

.info-card-enhanced {
    background: white;
    padding: 20px;
    border-radius: 12px;
    box-shadow: 0 2px 8px rgba(0,0,0,0.1);
    display: flex;
    align-items: center;
    gap: 15px;
    transition: transform 0.2s, box-shadow 0.2s;
}

.info-card-enhanced:hover {
    transform: translateY(-2px);
    box-shadow: 0 4px 12px rgba(0,0,0,0.15);
}

.info-icon {
    width: 48px;
    height: 48px;
    border-radius: 10px;
    background: linear-gradient(135deg, #667eea 0%, #764ba2 100%);
    display: flex;
    align-items: center;
    justify-content: center;
    color: white;
    font-size: 20px;
    flex-shrink: 0;
}

.info-content {
    flex: 1;
}

.info-label {
    font-size: 0.85em;
    color: #666;
    margin-bottom: 5px;
}

.info-value-large {
    font-size: 20px;
    font-weight: bold;
    color: #333;
}

.info-value-path {
    font-size: 0.9em;
    color: #666;
    word-break: break-all;
    font-family: monospace;
}

.info-detail {
    font-size: 0.8em;
    color: #999;
    margin-top: 3px;
}

/* Табы */
.settings-tabs {
    display: flex;
    gap: 10px;
    margin-bottom: 20px;
    border-bottom: 2px solid #e5e7eb;
}

.tab-button {
    padding: 12px 20px;
    border: none;
    background: none;
    color: #666;
    cursor: pointer;
    font-size: 0.95em;
    font-weight: 500;
    border-bottom: 3px solid transparent;
    transition: all 0.2s;
    display: flex;
    align-items: center;
    gap: 8px;
}

.tab-button:hover {
    color: #333;
    background: #f9fafb;
}

.tab-button.active {
    color: #667eea;
    border-bottom-color: #667eea;
}

.tab-content {
    display: none;
    padding: 25px;
    background: white;
    border-radius: 12px;
    box-shadow: 0 2px 8px rgba(0,0,0,0.1);
}

.tab-content.active {
    display: block;
}

/* Формы */
.settings-form {
    max-width: 800px;
}

.form-group {
    margin-bottom: 25px;
}

.form-label {
    display: flex;
    align-items: center;
    gap: 10px;
    font-weight: 500;
    color: #333;
    margin-bottom: 8px;
}

.form-label input[type="checkbox"] {
    width: 18px;
    height: 18px;
    cursor: pointer;
}

.form-input {
    width: 100%;
    max-width: 300px;
    padding: 10px 15px;
    border: 2px solid #e5e7eb;
    border-radius: 8px;
    font-size: 0.95em;
    transition: border-color 0.2s;
}

.form-input:focus {
    outline: none;
    border-color: #667eea;
    box-shadow: 0 0 0 3px rgba(102, 126, 234, 0.1);
}

.form-input-large {
    flex: 1;
    padding: 12px 15px;
    border: 2px solid #e5e7eb;
    border-radius: 8px;
    font-size: 0.95em;
}

.form-help {
    font-size: 0.85em;
    color: #666;
    margin-top: 5px;
}

.ttl-settings-grid {
    display: grid;
    grid-template-columns: repeat(auto-fit, minmax(250px, 1fr));
    gap: 20px;
    margin-bottom: 25px;
}

.form-group-inline {
    display: flex;
    gap: 10px;
    align-items: flex-start;
}

.button-group {
    display: flex;
    gap: 10px;
}

/* Redis информация */
.redis-info-section {
    padding: 20px;
}

.info-alert {
    display: flex;
    gap: 15px;
    padding: 20px;
    border-radius: 12px;
    margin-bottom: 20px;
}

.info-alert i {
    font-size: 24px;
    flex-shrink: 0;
}

.info-success {
    background: #d1fae5;
    border: 2px solid #10b981;
    color: #065f46;
}

.info-warning {
    background: #fef3c7;
    border: 2px solid #f59e0b;
    color: #92400e;
}

.redis-details {
    display: grid;
    grid-template-columns: repeat(auto-fit, minmax(200px, 1fr));
    gap: 15px;
}

.detail-item {
    padding: 15px;
    background: #f9fafb;
    border-radius: 8px;
    display: flex;
    flex-direction: column;
    gap: 5px;
}

.detail-label {
    font-size: 0.85em;
    color: #666;
}

.detail-value {
    font-size: 1.1em;
    font-weight: 600;
    color: #333;
}

/* Очистка */
.cleanup-section h4 {
    margin-top: 0;
    color: #333;
}

.section-description {
    color: #666;
    margin-bottom: 20px;
}

/* Действия с кэшем */
.cache-actions-grid {
    display: grid;
    grid-template-columns: repeat(auto-fit, minmax(200px, 1fr));
    gap: 15px;
    margin-top: 20px;
}

.action-button {
    padding: 20px;
    border: 2px solid #e5e7eb;
    border-radius: 12px;
    background: white;
    cursor: pointer;
    transition: all 0.2s;
    text-align: center;
    display: flex;
    flex-direction: column;
    align-items: center;
    gap: 10px;
    text-decoration: none;
    color: inherit;
}

.action-button:hover {
    transform: translateY(-2px);
    box-shadow: 0 4px 12px rgba(0,0,0,0.15);
}

.action-button i {
    font-size: 32px;
    color: #667eea;
}

.action-button span {
    font-weight: 600;
    font-size: 1em;
}

.action-button small {
    font-size: 0.8em;
    color: #666;
}

.action-warning {
    border-color: #f59e0b;
}

.action-warning:hover {
    background: #fef3c7;
}

.action-danger {
    border-color: #ef4444;
}

.action-danger:hover {
    background: #fee2e2;
}

.action-info {
    border-color: #3b82f6;
}

.action-info:hover {
    background: #dbeafe;
}

/* Работа с ключами */
.key-operations {
    padding: 20px;
    background: white;
    border-radius: 12px;
    box-shadow: 0 2px 8px rgba(0,0,0,0.1);
}

/* Уведомления */
.notification {
    position: fixed;
    top: 20px;
    right: 20px;
    padding: 15px 20px;
    border-radius: 8px;
    box-shadow: 0 4px 12px rgba(0,0,0,0.2);
    display: flex;
    align-items: center;
    gap: 12px;
    z-index: 10000;
    transform: translateX(400px);
    transition: transform 0.3s;
    max-width: 400px;
}

.notification.show {
    transform: translateX(0);
}

.notification-success {
    background: #10b981;
    color: white;
}

.notification-error {
    background: #ef4444;
    color: white;
}

.notification-warning {
    background: #f59e0b;
    color: white;
}

.notification-close {
    margin-left: auto;
    background: none;
    border: none;
    color: white;
    font-size: 20px;
    cursor: pointer;
    padding: 0;
    width: 24px;
    height: 24px;
    display: flex;
    align-items: center;
    justify-content: center;
}

/* Адаптивность */
@media (max-width: 1200px) {
    .stats-grid-extended {
        grid-template-columns: 1fr;
    }
    
    .stat-card-large {
        grid-column: 1;
    }
}

@media (max-width: 768px) {
    .cache-actions-grid {
        grid-template-columns: 1fr;
    }
    
    .form-group-inline {
        flex-direction: column;
    }
    
    .form-input-large {
        width: 100%;
    }
    
    .button-group {
        width: 100%;
    }
    
    .button-group .btn {
        flex: 1;
    }
    
    .settings-tabs {
        overflow-x: auto;
    }
    
    .tab-button {
        white-space: nowrap;
    }
}
</style>

<?php
$content = ob_get_clean();
include __DIR__ . '/../../templates/base.php';
?>
