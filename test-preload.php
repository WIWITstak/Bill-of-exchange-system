<?php
/**
 * Тест проверки OPcache Preload
 * Откройте: http://ogas/test-preload.php
 */

header('Content-Type: text/html; charset=UTF-8');
?>
<!DOCTYPE html>
<html lang="ru">
<head>
    <meta charset="UTF-8">
    <title>OPcache Preload Test</title>
    <style>
        body { font-family: Arial, sans-serif; margin: 40px; background: #f5f5f5; }
        .container { background: white; padding: 30px; border-radius: 8px; box-shadow: 0 2px 10px rgba(0,0,0,0.1); max-width: 800px; margin: 0 auto; }
        h1 { color: #333; margin-top: 0; }
        .status { padding: 15px; margin: 20px 0; border-radius: 5px; font-size: 18px; font-weight: bold; }
        .success { background: #d4edda; color: #155724; border: 1px solid #c3e6cb; }
        .warning { background: #fff3cd; color: #856404; border: 1px solid #ffeaa7; }
        .error { background: #f8d7da; color: #721c24; border: 1px solid #f5c6cb; }
        .info { background: #d1ecf1; color: #0c5460; border: 1px solid #bee5eb; }
        table { width: 100%; border-collapse: collapse; margin: 20px 0; }
        th, td { padding: 12px; text-align: left; border-bottom: 1px solid #ddd; }
        th { background: #f8f9fa; font-weight: bold; }
        .code { background: #f8f9fa; padding: 10px; border-radius: 4px; font-family: monospace; margin: 10px 0; }
        .section { margin: 30px 0; }
        .badge { padding: 4px 8px; border-radius: 3px; font-size: 12px; font-weight: bold; }
        .badge-success { background: #28a745; color: white; }
        .badge-warning { background: #ffc107; color: black; }
        .badge-danger { background: #dc3545; color: white; }
    </style>
</head>
<body>
    <div class="container">
        <h1>🚀 OPcache & Preload Status</h1>
        
        <?php
        // Проверка версии PHP
        $phpVersion = phpversion();
        $supportsPreload = version_compare($phpVersion, '7.4.0', '>=');
        ?>
        
        <div class="section">
            <h2>📌 Версия PHP</h2>
            <div class="status <?= $supportsPreload ? 'success' : 'warning' ?>">
                PHP <?= $phpVersion ?>
                <?php if ($supportsPreload): ?>
                    ✅ Preload поддерживается!
                <?php else: ?>
                    ⚠️ Требуется PHP 7.4+ для Preload
                <?php endif; ?>
            </div>
        </div>
        
        <?php if (function_exists('opcache_get_status')): ?>
            <?php
            $status = opcache_get_status();
            $config = opcache_get_configuration();
            ?>
            
            <div class="section">
                <h2>💾 OPcache Status</h2>
                
                <?php if ($status && $status['opcache_enabled']): ?>
                    <div class="status success">
                        ✅ OPcache ВКЛЮЧЕН и работает!
                    </div>
                    
                    <table>
                        <tr>
                            <th>Параметр</th>
                            <th>Значение</th>
                        </tr>
                        <tr>
                            <td>Используется памяти</td>
                            <td><?= round($status['memory_usage']['used_memory'] / 1024 / 1024, 2) ?> MB</td>
                        </tr>
                        <tr>
                            <td>Свободно памяти</td>
                            <td><?= round($status['memory_usage']['free_memory'] / 1024 / 1024, 2) ?> MB</td>
                        </tr>
                        <tr>
                            <td>Процент использования</td>
                            <td><?= round($status['memory_usage']['current_wasted_percentage'], 2) ?>%</td>
                        </tr>
                        <tr>
                            <td>Кэшировано скриптов</td>
                            <td><strong><?= $status['opcache_statistics']['num_cached_scripts'] ?></strong></td>
                        </tr>
                        <tr>
                            <td>Хиты кэша</td>
                            <td><?= number_format($status['opcache_statistics']['hits']) ?></td>
                        </tr>
                        <tr>
                            <td>Промахи кэша</td>
                            <td><?= number_format($status['opcache_statistics']['misses']) ?></td>
                        </tr>
                        <tr>
                            <td>Hit Rate</td>
                            <td>
                                <strong><?= round($status['opcache_statistics']['opcache_hit_rate'], 2) ?>%</strong>
                                <?php if ($status['opcache_statistics']['opcache_hit_rate'] > 95): ?>
                                    <span class="badge badge-success">Отлично!</span>
                                <?php elseif ($status['opcache_statistics']['opcache_hit_rate'] > 80): ?>
                                    <span class="badge badge-warning">Хорошо</span>
                                <?php else: ?>
                                    <span class="badge badge-danger">Требует внимания</span>
                                <?php endif; ?>
                            </td>
                        </tr>
                    </table>
                <?php else: ?>
                    <div class="status error">
                        ❌ OPcache ВЫКЛЮЧЕН!
                    </div>
                    <div class="info" style="margin: 20px 0;">
                        <p><strong>Как включить:</strong></p>
                        <ol>
                            <li>Откройте php.ini</li>
                            <li>Найдите <code>opcache.enable</code></li>
                            <li>Установите <code>opcache.enable = On</code></li>
                            <li>Перезапустите веб-сервер</li>
                        </ol>
                    </div>
                <?php endif; ?>
            </div>
            
            <div class="section">
                <h2>🔧 Preload Configuration</h2>
                
                <?php 
                $preloadPath = $config['directives']['opcache.preload'] ?? '';
                $preloadEnabled = !empty($preloadPath);
                ?>
                
                <?php if ($preloadEnabled): ?>
                    <div class="status success">
                        ✅ Preload НАСТРОЕН!
                    </div>
                    <div class="code">
                        <strong>Путь к preload.php:</strong><br>
                        <?= htmlspecialchars($preloadPath) ?>
                    </div>
                    
                    <?php if (file_exists($preloadPath)): ?>
                        <p style="color: green;">✅ Файл preload.php существует</p>
                    <?php else: ?>
                        <p style="color: red;">❌ Файл preload.php не найден!</p>
                    <?php endif; ?>
                    
                    <?php if (isset($status['preload_statistics'])): ?>
                        <table>
                            <tr>
                                <th>Статистика Preload</th>
                                <th>Значение</th>
                            </tr>
                            <tr>
                                <td>Память preload</td>
                                <td><?= round($status['preload_statistics']['memory_consumption'] / 1024 / 1024, 2) ?> MB</td>
                            </tr>
                            <tr>
                                <td>Предзагружено скриптов</td>
                                <td><strong><?= $status['preload_statistics']['scripts'] ?? 0 ?></strong></td>
                            </tr>
                        </table>
                    <?php endif; ?>
                <?php else: ?>
                    <div class="status warning">
                        ⚠️ Preload НЕ НАСТРОЕН
                    </div>
                    <div class="info" style="margin: 20px 0;">
                        <p><strong>Как настроить Preload:</strong></p>
                        <ol>
                            <li>Откройте php.ini</li>
                            <li>Добавьте в раздел [Zend OPcache]:</li>
                        </ol>
                        <div class="code">
                            opcache.preload=<?= str_replace('\\', '/', __DIR__ . '/../preload.php') ?>
                        </div>
                        <ol start="3">
                            <li>Перезапустите веб-сервер</li>
                            <li>Обновите эту страницу</li>
                        </ol>
                    </div>
                <?php endif; ?>
            </div>
            
            <div class="section">
                <h2>📊 Основные настройки OPcache</h2>
                <table>
                    <tr>
                        <th>Параметр</th>
                        <th>Значение</th>
                    </tr>
                    <tr>
                        <td>opcache.enable</td>
                        <td><?= $config['directives']['opcache.enable'] ? 'On' : 'Off' ?></td>
                    </tr>
                    <tr>
                        <td>opcache.memory_consumption</td>
                        <td><?= $config['directives']['opcache.memory_consumption'] ?> MB</td>
                    </tr>
                    <tr>
                        <td>opcache.max_accelerated_files</td>
                        <td><?= $config['directives']['opcache.max_accelerated_files'] ?></td>
                    </tr>
                    <tr>
                        <td>opcache.revalidate_freq</td>
                        <td><?= $config['directives']['opcache.revalidate_freq'] ?> сек</td>
                    </tr>
                    <tr>
                        <td>opcache.validate_timestamps</td>
                        <td><?= $config['directives']['opcache.validate_timestamps'] ? 'On' : 'Off' ?></td>
                    </tr>
                    <?php if (isset($config['directives']['opcache.jit'])): ?>
                    <tr>
                        <td>opcache.jit</td>
                        <td><?= $config['directives']['opcache.jit'] ?></td>
                    </tr>
                    <?php endif; ?>
                </table>
            </div>
            
        <?php else: ?>
            <div class="status error">
                ❌ OPcache не установлен или не загружен!
            </div>
        <?php endif; ?>
        
        <div class="section">
            <h2>💡 Рекомендации</h2>
            <ul>
                <li>✅ Включите OPcache для повышения производительности на 200-300%</li>
                <li>✅ Используйте Preload (PHP 7.4+) для еще большего ускорения</li>
                <li>✅ Установите <code>opcache.memory_consumption = 256</code></li>
                <li>✅ Установите <code>opcache.revalidate_freq = 60</code> для продакшн</li>
                <li>✅ Мониторьте Hit Rate (должен быть > 95%)</li>
            </ul>
        </div>
        
        <div style="text-align: center; margin-top: 30px; padding-top: 20px; border-top: 1px solid #ddd;">
            <a href="/dashboard.php" style="color: #007bff; text-decoration: none;">← Вернуться на главную</a>
        </div>
    </div>
</body>
</html>





