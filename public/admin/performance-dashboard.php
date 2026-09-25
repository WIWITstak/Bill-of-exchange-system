<?php
/**
 * Сводная панель производительности
 * Объединяет OPcache, Application Cache и общую статистику
 */

require_once __DIR__ . '/../../src/bootstrap.php';

use OGAS\Services\Auth;
use OGAS\Services\AdminService;
use OGAS\Core\Cache;
use OGAS\Core\CacheLogger;
use OGAS\Core\Security;
use OGAS\Core\Session;

Auth::requireAuth();
AdminService::requireAdmin();

$success = Session::getFlash('success');
$error = Session::getFlash('error');

// ============================================================
// 1. OPcache статистика
// ============================================================
$opcacheAvailable = function_exists('opcache_get_status');
$opcacheStatus = $opcacheAvailable ? opcache_get_status() : null;
$opcacheConfig = $opcacheAvailable ? opcache_get_configuration() : null;
$opcacheEnabled = $opcacheStatus && $opcacheStatus['opcache_enabled'];

// ============================================================
// 2. Application Cache статистика
// ============================================================
try {
    $cacheInfo = Cache::getInfo();
    $cacheStats = CacheLogger::getStats(24); // За 24 часа
    $cacheSize = Cache::getFileCacheStats();
} catch (\Exception $e) {
    $cacheInfo = ['enabled' => false, 'driver' => 'none'];
    $cacheStats = ['total' => 0, 'hits' => 0, 'misses' => 0];
    $cacheSize = ['size_mb' => 0, 'file_count' => 0];
}

// Расчет Application Cache Hit Rate
$appCacheHitRate = 0;
if (!empty($cacheStats['total']) && $cacheStats['total'] > 0) {
    $appCacheHitRate = ($cacheStats['hits'] / $cacheStats['total']) * 100;
}

// ============================================================
// 3. PHP конфигурация
// ============================================================
$phpMemoryLimit = ini_get('memory_limit');
$phpMaxExecutionTime = ini_get('max_execution_time');
$phpVersion = phpversion();

$title = 'Панель производительности';
ob_start();
?>

<div class="dashboard">
    <div class="dashboard-header">
        <h2><i class="fas fa-tachometer-alt"></i> Панель производительности</h2>
        <div class="header-actions">
            <a href="/admin/cache.php" class="btn btn-secondary">
                <i class="fas fa-database"></i> Application Cache
            </a>
            <a href="/admin/opcache-status.php" class="btn btn-secondary">
                <i class="fas fa-bolt"></i> OPcache
            </a>
            <a href="/admin/monitoring.php" class="btn btn-secondary">
                <i class="fas fa-server"></i> Мониторинг
            </a>
            <a href="/admin/index.php" class="btn btn-primary">
                <i class="fas fa-arrow-left"></i> Назад
            </a>
        </div>
    </div>

    <?php if ($error): ?>
        <div class="alert alert-error"><?= htmlspecialchars($error) ?></div>
    <?php endif; ?>

    <?php if ($success): ?>
        <div class="alert alert-success"><?= htmlspecialchars($success) ?></div>
    <?php endif; ?>

    <!-- General status -->
    <div class="admin-section">
        <h3><i class="fas fa-info-circle"></i> Общий статус</h3>
        <div class="stats-grid-extended">
            <!-- PHP Version -->
            <div class="stat-card-large">
                <div class="stat-icon" style="background: linear-gradient(135deg, #667eea 0%, #764ba2 100%);">
                    <i class="fas fa-elephant"></i>
                </div>
                <div class="stat-content">
                    <div class="stat-label">PHP Версия</div>
                    <div class="stat-value-large" style="color: #667eea;">
                        <?= $phpVersion ?>
                    </div>
                    <div class="stat-detail">
                        <?php if (version_compare($phpVersion, '7.4.0', '>=')): ?>
                            <span class="badge badge-success"><i class="fas fa-check-circle"></i> Поддерживает Preload</span>
                        <?php else: ?>
                            <span class="badge badge-warning"><i class="fas fa-exclamation-triangle"></i> Обновите до 7.4+</span>
                        <?php endif; ?>
                    </div>
                </div>
            </div>

            <!-- OPcache Status -->
            <div class="stat-card-large">
                <div class="stat-icon" style="background: linear-gradient(135deg, #f093fb 0%, #f5576c 100%);">
                    <i class="fas fa-bolt"></i>
                </div>
                <div class="stat-content">
                    <div class="stat-label">OPcache Hit Rate</div>
                    <div class="stat-value-large" style="color: #f093fb;">
                        <?php if ($opcacheEnabled): ?>
                            <?= round($opcacheStatus['opcache_statistics']['opcache_hit_rate'], 1) ?>%
                        <?php else: ?>
                            OFF
                        <?php endif; ?>
                    </div>
                    <div class="stat-detail">
                        <?php if ($opcacheEnabled): ?>
                            <?= $opcacheStatus['opcache_statistics']['num_cached_scripts'] ?> скриптов в кэше
                        <?php else: ?>
                            <span style="color: #f5576c;">❌ Не включен</span>
                        <?php endif; ?>
                    </div>
                </div>
            </div>

            <!-- Application Cache Status -->
            <div class="stat-card-large">
                <div class="stat-icon" style="background: linear-gradient(135deg, #4facfe 0%, #00f2fe 100%);">
                    <i class="fas fa-database"></i>
                </div>
                <div class="stat-content">
                    <div class="stat-label">App Cache Hit Rate</div>
                    <div class="stat-value-large" style="color: #4facfe;">
                        <?php if ($cacheInfo['enabled']): ?>
                            <?= round($appCacheHitRate, 1) ?>%
                        <?php else: ?>
                            OFF
                        <?php endif; ?>
                    </div>
                    <div class="stat-detail">
                        Драйвер: <?= strtoupper($cacheInfo['driver'] ?? 'none') ?>
                        <?php if ($cacheInfo['redis_connected'] ?? false): ?>
                            <span style="color: #00f2fe;">✓ Redis</span>
                        <?php endif; ?>
                    </div>
                </div>
            </div>

            <!-- Total Cache Size -->
            <div class="stat-card-large">
                <div class="stat-icon" style="background: linear-gradient(135deg, #43e97b 0%, #38f9d7 100%);">
                    <i class="fas fa-hdd"></i>
                </div>
                <div class="stat-content">
                    <div class="stat-label">Общий размер кэша</div>
                    <div class="stat-value-large" style="color: #43e97b;">
                        <?php
                        $totalCacheSize = $cacheSize['size_mb'] ?? 0;
                        if ($opcacheEnabled) {
                            $totalCacheSize += round($opcacheStatus['memory_usage']['used_memory'] / 1024 / 1024, 2);
                        }
                        echo round($totalCacheSize, 1);
                        ?> MB
                    </div>
                    <div class="stat-detail">
                        <?= ($cacheSize['file_count'] ?? 0) ?> файлов
                    </div>
                </div>
            </div>
        </div>
    </div>

    <!-- Comparison table -->
    <div class="admin-section">
        <h3><i class="fas fa-chart-bar"></i> Сравнение систем кэширования</h3>
        <div class="table-responsive">
            <table class="admin-table">
                <thead>
                    <tr>
                        <th>Система</th>
                        <th>Статус</th>
                        <th>Назначение</th>
                        <th>Hit Rate</th>
                        <th>Размер</th>
                        <th>Действия</th>
                    </tr>
                </thead>
                <tbody>
                    <!-- OPcache -->
                    <tr>
                        <td><strong><i class="fas fa-bolt"></i> OPcache</strong></td>
                        <td>
                            <?php if ($opcacheEnabled): ?>
                                <span class="badge badge-success">Включен</span>
                            <?php else: ?>
                                <span class="badge badge-danger">Выключен</span>
                            <?php endif; ?>
                        </td>
                        <td>Кэш PHP байт-кода</td>
                        <td>
                            <?php if ($opcacheEnabled): ?>
                                <?= round($opcacheStatus['opcache_statistics']['opcache_hit_rate'], 2) ?>%
                                <?php if ($opcacheStatus['opcache_statistics']['opcache_hit_rate'] >= 95): ?>
                                    <span class="badge badge-success">Отлично</span>
                                <?php elseif ($opcacheStatus['opcache_statistics']['opcache_hit_rate'] >= 80): ?>
                                    <span class="badge badge-success">Хорошо</span>
                                <?php else: ?>
                                    <span class="badge badge-warning">Низкий</span>
                                <?php endif; ?>
                            <?php else: ?>
                                -
                            <?php endif; ?>
                        </td>
                        <td>
                            <?php if ($opcacheEnabled): ?>
                                <?= round($opcacheStatus['memory_usage']['used_memory'] / 1024 / 1024, 2) ?> MB
                            <?php else: ?>
                                -
                            <?php endif; ?>
                        </td>
                        <td>
                            <a href="/admin/opcache-status.php" class="btn btn-small btn-secondary">
                                <i class="fas fa-external-link-alt"></i> Подробнее
                            </a>
                        </td>
                    </tr>

                    <!-- Application Cache -->
                    <tr>
                        <td><strong><i class="fas fa-database"></i> Application Cache</strong></td>
                        <td>
                            <?php if ($cacheInfo['enabled']): ?>
                                <span class="badge badge-success">Включен</span>
                            <?php else: ?>
                                <span class="badge badge-danger">Выключен</span>
                            <?php endif; ?>
                        </td>
                        <td>Кэш данных приложения (<?= strtoupper($cacheInfo['driver'] ?? 'none') ?>)</td>
                        <td>
                            <?php if ($cacheInfo['enabled'] && $cacheStats['total'] > 0): ?>
                                <?= round($appCacheHitRate, 2) ?>%
                                <?php if ($appCacheHitRate >= 80): ?>
                                    <span class="badge badge-success">Хорошо</span>
                                <?php elseif ($appCacheHitRate >= 50): ?>
                                    <span class="badge badge-success">Средне</span>
                                <?php else: ?>
                                    <span class="badge badge-warning">Низкий</span>
                                <?php endif; ?>
                            <?php else: ?>
                                Нет данных
                            <?php endif; ?>
                        </td>
                        <td>
                            <?= $cacheSize['size_mb'] ?? 0 ?> MB
                            (<?= $cacheSize['file_count'] ?? 0 ?> файлов)
                        </td>
                        <td>
                            <a href="/admin/cache.php" class="btn btn-small btn-secondary">
                                <i class="fas fa-external-link-alt"></i> Подробнее
                            </a>
                        </td>
                    </tr>

                    <!-- PHP Session -->
                    <tr>
                        <td><strong><i class="fas fa-user-lock"></i> PHP Session</strong></td>
                        <td>
                            <span class="badge badge-success">Активен</span>
                        </td>
                        <td>Хранилище сессий</td>
                        <td>-</td>
                        <td>
                            <?= ini_get('session.save_handler') ?>
                        </td>
                        <td>-</td>
                    </tr>
                </tbody>
            </table>
        </div>
    </div>

    <!-- PHP Configuration -->
    <div class="admin-section">
        <h3><i class="fas fa-cog"></i> PHP Конфигурация</h3>
        <div class="table-responsive">
            <table class="admin-table">
                <thead>
                    <tr>
                        <th>Параметр</th>
                        <th>Текущее значение</th>
                        <th>Рекомендация</th>
                    </tr>
                </thead>
                <tbody>
                    <tr>
                        <td>PHP Version</td>
                        <td><?= $phpVersion ?></td>
                        <td>
                            <?php if (version_compare($phpVersion, '8.0.0', '>=')): ?>
                                <span class="badge badge-success">✓ Отлично (PHP 8+)</span>
                            <?php elseif (version_compare($phpVersion, '7.4.0', '>=')): ?>
                                <span class="badge badge-success">✓ Хорошо (поддержка Preload)</span>
                            <?php else: ?>
                                <span class="badge badge-warning">Обновите до 7.4+</span>
                            <?php endif; ?>
                        </td>
                    </tr>
                    <tr>
                        <td>memory_limit</td>
                        <td><?= $phpMemoryLimit ?></td>
                        <td>
                            <?php
                            $memValue = (int)$phpMemoryLimit;
                            if ($memValue >= 256 || $phpMemoryLimit === '-1'): ?>
                                <span class="badge badge-success">✓ OK</span>
                            <?php else: ?>
                                <span class="badge badge-warning">Увеличьте до 256M</span>
                            <?php endif; ?>
                        </td>
                    </tr>
                    <tr>
                        <td>max_execution_time</td>
                        <td><?= $phpMaxExecutionTime ?>s</td>
                        <td>
                            <?php if ($phpMaxExecutionTime >= 60 || $phpMaxExecutionTime == 0): ?>
                                <span class="badge badge-success">✓ OK</span>
                            <?php else: ?>
                                <span class="badge badge-warning">Увеличьте до 60</span>
                            <?php endif; ?>
                        </td>
                    </tr>
                    <tr>
                        <td>OPcache</td>
                        <td><?= $opcacheEnabled ? 'Включен' : 'Выключен' ?></td>
                        <td>
                            <?php if ($opcacheEnabled): ?>
                                <span class="badge badge-success">✓ Включен</span>
                            <?php else: ?>
                                <span class="badge badge-danger">❌ Включите!</span>
                            <?php endif; ?>
                        </td>
                    </tr>
                    <tr>
                        <td>Realpath Cache</td>
                        <td><?= ini_get('realpath_cache_size') ?></td>
                        <td>
                            <?php
                            $realpathSize = ini_get('realpath_cache_size');
                            $realpathValue = (int)$realpathSize;
                            if ($realpathValue >= 4096 * 1024): ?>
                                <span class="badge badge-success">✓ OK</span>
                            <?php else: ?>
                                <span class="badge badge-warning">Увеличьте до 4M</span>
                            <?php endif; ?>
                        </td>
                    </tr>
                </tbody>
            </table>
        </div>
    </div>

    <!-- Recommendations -->
    <div class="admin-section">
        <h3><i class="fas fa-lightbulb"></i> Рекомендации по оптимизации</h3>
        <div class="recommendations-grid">
            <?php if (!$opcacheEnabled): ?>
            <div class="recommendation-item recommendation-critical">
                <i class="fas fa-exclamation-triangle"></i>
                <div class="recommendation-content">
                    <strong>Критично:</strong> Включите OPcache для повышения производительности на 200-300%
                </div>
            </div>
            <?php endif; ?>

            <?php if ($opcacheEnabled && $opcacheStatus['opcache_statistics']['opcache_hit_rate'] < 95): ?>
            <div class="recommendation-item recommendation-warning">
                <i class="fas fa-exclamation-circle"></i>
                <div class="recommendation-content">
                    OPcache Hit Rate низкий. Подождите накопления статистики или проверьте настройки памяти.
                </div>
            </div>
            <?php endif; ?>

            <?php if (!($cacheInfo['redis_connected'] ?? false) && $cacheInfo['driver'] === 'file'): ?>
            <div class="recommendation-item recommendation-info">
                <i class="fas fa-info-circle"></i>
                <div class="recommendation-content">
                    Рассмотрите использование Redis для Application Cache (в 10-100 раз быстрее файлового кэша)
                </div>
            </div>
            <?php endif; ?>

            <?php if ($cacheStats['total'] > 0 && $appCacheHitRate < 50): ?>
            <div class="recommendation-item recommendation-warning">
                <i class="fas fa-chart-line"></i>
                <div class="recommendation-content">
                    Application Cache Hit Rate низкий (<?= round($appCacheHitRate, 1) ?>%). Проверьте TTL и стратегию кэширования.
                </div>
            </div>
            <?php endif; ?>

            <?php if (version_compare($phpVersion, '7.4.0', '<')): ?>
            <div class="recommendation-item recommendation-warning">
                <i class="fas fa-code"></i>
                <div class="recommendation-content">
                    Обновите PHP до версии 7.4+ или 8.0+ для использования Preload и JIT
                </div>
            </div>
            <?php endif; ?>

            <?php if ($opcacheEnabled && empty($opcacheConfig['directives']['opcache.preload'])): ?>
            <div class="recommendation-item recommendation-info">
                <i class="fas fa-rocket"></i>
                <div class="recommendation-content">
                    Настройте Preload для дополнительного ускорения на 30-40%
                </div>
            </div>
            <?php endif; ?>

            <?php if ($opcacheEnabled && $opcacheStatus['opcache_statistics']['opcache_hit_rate'] >= 95 && $appCacheHitRate >= 80): ?>
            <div class="recommendation-item recommendation-success">
                <i class="fas fa-check-circle"></i>
                <div class="recommendation-content">
                    <strong>Отлично!</strong> Все системы кэширования работают оптимально!
                </div>
            </div>
            <?php endif; ?>
        </div>
    </div>

    <!-- Quick links -->
    <div class="admin-section">
        <h3><i class="fas fa-link"></i> Быстрые ссылки</h3>
        <div class="admin-quick-links">
            <a href="/admin/opcache-status.php" class="quick-link-btn quick-link-btn-secondary">
                <i class="fas fa-bolt"></i> OPcache детали
            </a>
            <a href="/admin/cache.php" class="quick-link-btn quick-link-btn-secondary">
                <i class="fas fa-database"></i> Application Cache
            </a>
            <a href="/admin/monitoring.php" class="quick-link-btn quick-link-btn-secondary">
                <i class="fas fa-server"></i> Мониторинг сервера
            </a>
            <a href="/admin/database-logs.php" class="quick-link-btn quick-link-btn-secondary">
                <i class="fas fa-database"></i> Database Logs
            </a>
            <a href="/test-preload.php" class="quick-link-btn quick-link-btn-secondary" target="_blank">
                <i class="fas fa-flask"></i> Тест OPcache
            </a>
            <a href="/docs/performance/OPTIMIZATION_PLAN.md" class="quick-link-btn quick-link-btn-secondary" target="_blank">
                <i class="fas fa-book"></i> Документация
            </a>
        </div>
    </div>
</div>

<style>
/* Вспомогательные стили для адаптации к стилям админки */

/* Стили для кнопок */
.btn-small {
    padding: 4px 8px;
    font-size: 12px;
    min-width: auto;
}

/* Стили для значков (бейджей) */
.badge {
    display: inline-block;
    padding: 4px 8px;
    border-radius: 4px;
    font-size: 12px;
    font-weight: 500;
    text-align: center;
    vertical-align: middle;
    white-space: nowrap;
    border: 0;
}

.badge-success {
    background: var(--color-success);
    color: white;
}

.badge-warning {
    background: var(--color-warning);
    color: black;
}

.badge-danger {
    background: var(--color-danger);
    color: white;
}

.badge-info {
    background: var(--color-info);
    color: white;
}

/* Стили для рекомендаций */
.recommendations-grid {
    display: grid;
    grid-template-columns: repeat(auto-fill, minmax(300px, 1fr));
    gap: 15px;
    margin-top: 15px;
}

.recommendation-item {
    padding: 15px;
    border-radius: var(--radius-md);
    margin-bottom: 10px;
    display: flex;
    align-items: flex-start;
    gap: 10px;
    border-left: 4px solid;
}

.recommendation-item i {
    font-size: 1.2em;
    margin-top: 2px;
}

.recommendation-content {
    flex: 1;
}

.recommendation-critical {
    background: var(--color-gray-lighter);
    border-left-color: var(--color-danger);
    color: var(--text-primary);
}

.recommendation-warning {
    background: #fff3cd;
    border-left-color: var(--color-warning);
    color: #856404;
}

.recommendation-info {
    background: var(--color-gray-table);
    border-left-color: var(--color-info);
    color: var(--text-primary);
}

.recommendation-success {
    background: #d4edda;
    border-left-color: var(--color-success);
    color: #155724;
}

/* Стили для быстрых ссылок */
.admin-quick-links {
    display: grid;
    grid-template-columns: repeat(auto-fill, minmax(200px, 1fr));
    gap: 10px;
    margin-top: 15px;
}

.quick-link-btn {
    display: flex;
    align-items: center;
    gap: 8px;
    padding: 12px 16px;
    text-decoration: none;
    border-radius: var(--radius-md);
    font-weight: 500;
    transition: all 0.2s ease;
    border: 1px solid var(--border-color);
    background: var(--bg-primary);
    color: var(--text-primary);
}

.quick-link-btn:hover {
    transform: translateY(-2px);
    box-shadow: var(--shadow-md);
    text-decoration: none;
    color: var(--text-primary);
}

.quick-link-btn-secondary {
    background: var(--bg-secondary);
    border-color: var(--border-color-light);
}

.quick-link-btn-secondary:hover {
    background: var(--color-secondary);
    border-color: var(--border-color);
}

/* Стили для таблиц */
.admin-table {
    width: 100%;
    border-collapse: collapse;
    background: white;
    border-radius: var(--radius-md);
    overflow: hidden;
    box-shadow: var(--shadow-sm);
}

.admin-table th, .admin-table td {
    padding: 12px;
    text-align: left;
    border-bottom: 1px solid var(--border-color-table);
}

.admin-table th {
    background: var(--color-gray-table);
    font-weight: 600;
    color: var(--text-primary);
}

.table-responsive {
    overflow-x: auto;
    border-radius: var(--radius-md);
}

/* Стили для секций */
.admin-section {
    background: white;
    border: 1px solid var(--border-color);
    border-radius: var(--radius-md);
    padding: 20px;
    margin-bottom: 25px;
}

.admin-section > h3 {
    margin-top: 0;
    margin-bottom: 20px;
    color: var(--text-primary);
    display: flex;
    align-items: center;
    gap: 10px;
}

/* Стили для стат карточек */
.stats-grid-extended {
    display: grid;
    grid-template-columns: repeat(auto-fit, minmax(280px, 1fr));
    gap: 20px;
    margin-top: 15px;
}

.stat-card-large {
    background: var(--bg-primary);
    border-radius: var(--radius-lg);
    padding: 16px;
    box-shadow: var(--shadow-sm);
    display: flex;
    align-items: center;
    gap: 15px;
    border: 1px solid var(--border-color);
}

.stat-icon {
    width: 60px;
    height: 60px;
    display: flex;
    align-items: center;
    justify-content: center;
    border-radius: var(--radius-md);
    font-size: 1.5em;
    color: white;
}

.stat-content {
    flex: 1;
}

.stat-label {
    color: var(--text-tertiary);
    font-size: 0.9em;
    margin-bottom: 4px;
}

.stat-value-large {
    font-size: 1.8em;
    font-weight: bold;
    margin: 5px 0;
}

.stat-detail {
    color: var(--text-secondary);
    font-size: 0.9em;
}
</style>

<?php
$content = ob_get_clean();
require __DIR__ . '/../../templates/base.php';
?>





