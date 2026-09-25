<?php
/**
 * Статус OPcache - мониторинг и управление
 * Доступ только для администраторов!
 */

require_once __DIR__ . '/../../src/bootstrap.php';

use OGAS\Services\Auth;
use OGAS\Services\AdminService;
use OGAS\Core\Security;
use OGAS\Core\Session;

Auth::requireAuth();
AdminService::requireAdmin();

$success = Session::getFlash('success');
$error = Session::getFlash('error');

// Обработка действий
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (!Security::checkCsrfToken()) {
        Session::flash('error', 'Ошибка CSRF токена');
        header('Location: /admin/opcache-status.php');
        exit;
    }
    
    $action = $_POST['action'] ?? '';
    
    if ($action === 'reset' && function_exists('opcache_reset')) {
        if (opcache_reset()) {
            Session::flash('success', 'OPcache успешно сброшен!');
        } else {
            Session::flash('error', 'Не удалось сбросить OPcache');
        }
        header('Location: /admin/opcache-status.php');
        exit;
    }
    
    if ($action === 'invalidate' && function_exists('opcache_invalidate')) {
        $file = $_POST['file'] ?? '';
        if ($file && file_exists($file)) {
            if (opcache_invalidate($file, true)) {
                Session::flash('success', 'Файл инвалидирован из кэша');
            } else {
                Session::flash('error', 'Не удалось инвалидировать файл');
            }
        }
        header('Location: /admin/opcache-status.php');
        exit;
    }
}

// Получаем статус и конфигурацию
$opcacheAvailable = function_exists('opcache_get_status');
$status = $opcacheAvailable ? opcache_get_status() : null;
$config = $opcacheAvailable ? opcache_get_configuration() : null;

$title = 'OPcache Status';
ob_start();
?>

<div class="dashboard">
    <div class="dashboard-header">
        <h2>⚡ OPcache Мониторинг</h2>
        <div class="header-actions">
            <a href="/admin/monitoring.php" class="btn btn-secondary">← Мониторинг</a>
            <a href="/admin/index.php" class="btn btn-secondary">Админ-панель</a>
        </div>
    </div>
    
    <?php if ($error): ?>
        <div class="alert alert-error"><?= htmlspecialchars($error) ?></div>
    <?php endif; ?>
    
    <?php if ($success): ?>
        <div class="alert alert-success"><?= htmlspecialchars($success) ?></div>
    <?php endif; ?>
    
    <?php if (!$opcacheAvailable): ?>
        <div class="alert alert-error">
            <h3>❌ OPcache не установлен или не загружен!</h3>
            <p>Для включения OPcache:</p>
            <ol>
                <li>Откройте php.ini</li>
                <li>Раскомментируйте: <code>zend_extension=opcache</code></li>
                <li>Установите: <code>opcache.enable=On</code></li>
                <li>Перезапустите веб-сервер</li>
            </ol>
            <p><a href="/docs/performance/ENABLE_OPCACHE_OPENSERVER.md" class="btn btn-primary">📖 Инструкция</a></p>
        </div>
    <?php elseif (!$status || !$status['opcache_enabled']): ?>
        <div class="alert alert-warning">
            <h3>⚠️ OPcache установлен, но ВЫКЛЮЧЕН!</h3>
            <p>Измените в php.ini: <code>opcache.enable = On</code></p>
        </div>
    <?php else: ?>
        
        <!-- Общий статус -->
        <div class="catalog-info" style="margin-bottom: 30px;">
            <div class="catalog-stats">
                <div class="stat-item" style="background: linear-gradient(135deg, #667eea 0%, #764ba2 100%);">
                    <div class="stat-icon">💾</div>
                    <div class="stat-value">
                        <?= round($status['memory_usage']['used_memory'] / 1024 / 1024, 2) ?> MB
                    </div>
                    <div class="stat-label">Используется памяти</div>
                    <div class="stat-meta">
                        из <?= $config['directives']['opcache.memory_consumption'] ?> MB
                        (<?= round(($status['memory_usage']['used_memory'] / ($config['directives']['opcache.memory_consumption'] * 1024 * 1024)) * 100, 1) ?>%)
                    </div>
                </div>
                
                <div class="stat-item" style="background: linear-gradient(135deg, #f093fb 0%, #f5576c 100%);">
                    <div class="stat-icon">🎯</div>
                    <div class="stat-value">
                        <?= round($status['opcache_statistics']['opcache_hit_rate'], 2) ?>%
                    </div>
                    <div class="stat-label">Hit Rate</div>
                    <div class="stat-meta">
                        <?= number_format($status['opcache_statistics']['hits']) ?> хитов /
                        <?= number_format($status['opcache_statistics']['misses']) ?> промахов
                    </div>
                </div>
                
                <div class="stat-item" style="background: linear-gradient(135deg, #4facfe 0%, #00f2fe 100%);">
                    <div class="stat-icon">📄</div>
                    <div class="stat-value">
                        <?= $status['opcache_statistics']['num_cached_scripts'] ?>
                    </div>
                    <div class="stat-label">Кэшировано скриптов</div>
                    <div class="stat-meta">
                        Макс: <?= $config['directives']['opcache.max_accelerated_files'] ?>
                    </div>
                </div>
                
                <div class="stat-item" style="background: linear-gradient(135deg, #43e97b 0%, #38f9d7 100%);">
                    <div class="stat-icon">🔤</div>
                    <div class="stat-value">
                        <?= number_format($status['interned_strings_usage']['number_of_strings']) ?>
                    </div>
                    <div class="stat-label">Интернированные строки</div>
                    <div class="stat-meta">
                        <?= round($status['interned_strings_usage']['used_memory'] / 1024 / 1024, 2) ?> MB
                    </div>
                </div>
            </div>
        </div>
        
        <!-- Действия -->
        <div class="admin-actions" style="margin-bottom: 30px;">
            <form method="post" style="display: inline;" onsubmit="return confirm('Вы уверены, что хотите сбросить весь OPcache?');">
                <?= Security::getCsrfTokenField() ?>
                <input type="hidden" name="action" value="reset">
                <button type="submit" class="btn btn-warning">
                    🔄 Сбросить OPcache
                </button>
            </form>
            
            <a href="/test-preload.php" class="btn btn-info" target="_blank">
                📊 Детальный статус
            </a>
        </div>
        
        <!-- Детальная статистика -->
        <div class="admin-table-container">
            <h3>📊 Детальная статистика</h3>
            
            <table class="admin-table">
                <thead>
                    <tr>
                        <th>Метрика</th>
                        <th>Значение</th>
                        <th>Статус</th>
                    </tr>
                </thead>
                <tbody>
                    <tr>
                        <td><strong>Hit Rate</strong></td>
                        <td><?= round($status['opcache_statistics']['opcache_hit_rate'], 2) ?>%</td>
                        <td>
                            <?php if ($status['opcache_statistics']['opcache_hit_rate'] >= 95): ?>
                                <span class="badge badge-success">Отлично</span>
                            <?php elseif ($status['opcache_statistics']['opcache_hit_rate'] >= 80): ?>
                                <span class="badge badge-warning">Хорошо</span>
                            <?php else: ?>
                                <span class="badge badge-danger">Требует внимания</span>
                            <?php endif; ?>
                        </td>
                    </tr>
                    <tr>
                        <td>Хиты кэша</td>
                        <td><?= number_format($status['opcache_statistics']['hits']) ?></td>
                        <td><span class="badge badge-success">✓</span></td>
                    </tr>
                    <tr>
                        <td>Промахи кэша</td>
                        <td><?= number_format($status['opcache_statistics']['misses']) ?></td>
                        <td>
                            <?php if ($status['opcache_statistics']['misses'] < 100): ?>
                                <span class="badge badge-success">Отлично</span>
                            <?php else: ?>
                                <span class="badge badge-warning">Норма</span>
                            <?php endif; ?>
                        </td>
                    </tr>
                    <tr>
                        <td>Использовано памяти</td>
                        <td><?= round($status['memory_usage']['used_memory'] / 1024 / 1024, 2) ?> MB</td>
                        <td>
                            <?php 
                            $memPercent = ($status['memory_usage']['used_memory'] / ($config['directives']['opcache.memory_consumption'] * 1024 * 1024)) * 100;
                            ?>
                            <?php if ($memPercent < 80): ?>
                                <span class="badge badge-success">OK</span>
                            <?php else: ?>
                                <span class="badge badge-warning">Почти полная</span>
                            <?php endif; ?>
                        </td>
                    </tr>
                    <tr>
                        <td>Свободно памяти</td>
                        <td><?= round($status['memory_usage']['free_memory'] / 1024 / 1024, 2) ?> MB</td>
                        <td>-</td>
                    </tr>
                    <tr>
                        <td>Потрачено впустую</td>
                        <td><?= round($status['memory_usage']['current_wasted_percentage'], 2) ?>%</td>
                        <td>
                            <?php if ($status['memory_usage']['current_wasted_percentage'] < 5): ?>
                                <span class="badge badge-success">Отлично</span>
                            <?php else: ?>
                                <span class="badge badge-warning">Нужна очистка</span>
                            <?php endif; ?>
                        </td>
                    </tr>
                    <tr>
                        <td>Кэшировано скриптов</td>
                        <td><?= $status['opcache_statistics']['num_cached_scripts'] ?></td>
                        <td>
                            <?php 
                            $scriptPercent = ($status['opcache_statistics']['num_cached_scripts'] / $config['directives']['opcache.max_accelerated_files']) * 100;
                            ?>
                            <?php if ($scriptPercent < 80): ?>
                                <span class="badge badge-success">OK</span>
                            <?php else: ?>
                                <span class="badge badge-warning">Почти полный</span>
                            <?php endif; ?>
                        </td>
                    </tr>
                    <tr>
                        <td>Ключи в кэше</td>
                        <td><?= $status['opcache_statistics']['num_cached_keys'] ?></td>
                        <td>-</td>
                    </tr>
                    <tr>
                        <td>Макс. ключей</td>
                        <td><?= $status['opcache_statistics']['max_cached_keys'] ?></td>
                        <td>-</td>
                    </tr>
                    <tr>
                        <td>Время работы</td>
                        <td>
                            <?php 
                            $uptime = time() - $status['opcache_statistics']['start_time'];
                            $days = floor($uptime / 86400);
                            $hours = floor(($uptime % 86400) / 3600);
                            $minutes = floor(($uptime % 3600) / 60);
                            echo "{$days}д {$hours}ч {$minutes}м";
                            ?>
                        </td>
                        <td><span class="badge badge-info">Uptime</span></td>
                    </tr>
                    <tr>
                        <td>Последний перезапуск</td>
                        <td><?= $status['opcache_statistics']['last_restart_time'] > 0 ? date('Y-m-d H:i:s', $status['opcache_statistics']['last_restart_time']) : 'Никогда' ?></td>
                        <td>-</td>
                    </tr>
                </tbody>
            </table>
        </div>
        
        <!-- Конфигурация -->
        <div class="admin-table-container" style="margin-top: 30px;">
            <h3>⚙️ Конфигурация OPcache</h3>
            
            <table class="admin-table">
                <thead>
                    <tr>
                        <th>Параметр</th>
                        <th>Значение</th>
                        <th>Рекомендация</th>
                    </tr>
                </thead>
                <tbody>
                    <tr>
                        <td>opcache.enable</td>
                        <td><?= $config['directives']['opcache.enable'] ? 'On' : 'Off' ?></td>
                        <td><?= $config['directives']['opcache.enable'] ? '<span class="badge badge-success">✓ OK</span>' : '<span class="badge badge-danger">Включите!</span>' ?></td>
                    </tr>
                    <tr>
                        <td>opcache.memory_consumption</td>
                        <td><?= $config['directives']['opcache.memory_consumption'] ?> MB</td>
                        <td><?= $config['directives']['opcache.memory_consumption'] >= 256 ? '<span class="badge badge-success">✓ OK</span>' : '<span class="badge badge-warning">Увеличьте до 256</span>' ?></td>
                    </tr>
                    <tr>
                        <td>opcache.max_accelerated_files</td>
                        <td><?= $config['directives']['opcache.max_accelerated_files'] ?></td>
                        <td><?= $config['directives']['opcache.max_accelerated_files'] >= 10000 ? '<span class="badge badge-success">✓ OK</span>' : '<span class="badge badge-warning">Увеличьте</span>' ?></td>
                    </tr>
                    <tr>
                        <td>opcache.revalidate_freq</td>
                        <td><?= $config['directives']['opcache.revalidate_freq'] ?> сек</td>
                        <td>
                            <?php if ($config['directives']['opcache.revalidate_freq'] == 0): ?>
                                <span class="badge badge-info">Разработка</span>
                            <?php elseif ($config['directives']['opcache.revalidate_freq'] >= 60): ?>
                                <span class="badge badge-success">Продакшн</span>
                            <?php else: ?>
                                <span class="badge badge-warning">Средне</span>
                            <?php endif; ?>
                        </td>
                    </tr>
                    <tr>
                        <td>opcache.validate_timestamps</td>
                        <td><?= $config['directives']['opcache.validate_timestamps'] ? 'On' : 'Off' ?></td>
                        <td>
                            <?php if ($config['directives']['opcache.validate_timestamps']): ?>
                                <span class="badge badge-info">Разработка</span>
                            <?php else: ?>
                                <span class="badge badge-success">Продакшн</span>
                            <?php endif; ?>
                        </td>
                    </tr>
                    <tr>
                        <td>opcache.interned_strings_buffer</td>
                        <td><?= $config['directives']['opcache.interned_strings_buffer'] ?? 'N/A' ?> MB</td>
                        <td><?= ($config['directives']['opcache.interned_strings_buffer'] ?? 0) >= 8 ? '<span class="badge badge-success">✓ OK</span>' : '<span class="badge badge-warning">Увеличьте до 16</span>' ?></td>
                    </tr>
                    <?php if (isset($config['directives']['opcache.jit'])): ?>
                    <tr>
                        <td>opcache.jit</td>
                        <td><?= $config['directives']['opcache.jit'] ?></td>
                        <td>
                            <?php if (in_array($config['directives']['opcache.jit'], ['1255', 'tracing'])): ?>
                                <span class="badge badge-success">✓ Включен</span>
                            <?php else: ?>
                                <span class="badge badge-info">Выключен</span>
                            <?php endif; ?>
                        </td>
                    </tr>
                    <?php endif; ?>
                    <?php if (!empty($config['directives']['opcache.preload'])): ?>
                    <tr>
                        <td>opcache.preload</td>
                        <td style="word-break: break-all; font-size: 11px;"><?= htmlspecialchars($config['directives']['opcache.preload']) ?></td>
                        <td>
                            <?php if (file_exists($config['directives']['opcache.preload'])): ?>
                                <span class="badge badge-success">✓ Файл найден</span>
                            <?php else: ?>
                                <span class="badge badge-danger">Файл не найден!</span>
                            <?php endif; ?>
                        </td>
                    </tr>
                    <?php endif; ?>
                </tbody>
            </table>
        </div>
        
        <!-- Рекомендации -->
        <div class="alert alert-info" style="margin-top: 30px;">
            <h3>💡 Рекомендации по оптимизации</h3>
            <ul>
                <?php if ($status['opcache_statistics']['opcache_hit_rate'] < 95): ?>
                <li>⚠️ Hit Rate низкий (<?= round($status['opcache_statistics']['opcache_hit_rate'], 2) ?>%). Подождите больше запросов или увеличьте память.</li>
                <?php endif; ?>
                
                <?php if ($config['directives']['opcache.memory_consumption'] < 256): ?>
                <li>⚠️ Увеличьте <code>opcache.memory_consumption</code> до 256 MB</li>
                <?php endif; ?>
                
                <?php if (($status['memory_usage']['used_memory'] / ($config['directives']['opcache.memory_consumption'] * 1024 * 1024)) > 0.8): ?>
                <li>⚠️ Память OPcache почти заполнена (<?= round(($status['memory_usage']['used_memory'] / ($config['directives']['opcache.memory_consumption'] * 1024 * 1024)) * 100, 1) ?>%). Увеличьте <code>opcache.memory_consumption</code></li>
                <?php endif; ?>
                
                <?php if ($config['directives']['opcache.revalidate_freq'] == 0 && $config['directives']['opcache.validate_timestamps']): ?>
                <li>ℹ️ Режим разработки: файлы проверяются при каждом запросе. Для продакшн установите <code>opcache.revalidate_freq = 60</code></li>
                <?php endif; ?>
                
                <?php if (empty($config['directives']['opcache.preload'])): ?>
                <li>💡 Включите Preload для дополнительного ускорения на 30-40%</li>
                <?php endif; ?>
                
                <?php if ($status['opcache_statistics']['opcache_hit_rate'] >= 95 && $config['directives']['opcache.memory_consumption'] >= 256): ?>
                <li style="color: green;">✅ Конфигурация OPcache оптимальна!</li>
                <?php endif; ?>
            </ul>
        </div>
        
    <?php endif; ?>
</div>

<style>
.badge {
    padding: 4px 8px;
    border-radius: 4px;
    font-size: 12px;
    font-weight: bold;
}
.badge-success { background: #28a745; color: white; }
.badge-warning { background: #ffc107; color: black; }
.badge-danger { background: #dc3545; color: white; }
.badge-info { background: #17a2b8; color: white; }
</style>

<?php
$content = ob_get_clean();
require __DIR__ . '/../../templates/base.php';
?>

