<?php
/**
 * Главная страница админ-панели
 */

require_once __DIR__ . '/../../src/bootstrap.php';

use OGAS\Services\Auth;
use OGAS\Services\AdminService;

Auth::requireAuth();
AdminService::requireAdmin();

$user = Auth::user();

// Получаем статистику системы
$stats = AdminService::getSystemStatistics();

$title = 'Админ-панель';
ob_start();
?>
<div class="dashboard">
    <div class="dashboard-header">
        <h2>Админ-панель</h2>
        <div class="header-actions">
            <a href="/dashboard.php" class="btn btn-secondary">← К рабочему кабинету</a>
        </div>
    </div>

    <div class="admin-welcome">
        <p>Добро пожаловать, <strong><?= htmlspecialchars($user->getFullName()) ?></strong>!</p>
        <p>Вы вошли в административную панель системы ОГАС.</p>
    </div>

    <!-- Статистика системы -->
    <div class="admin-stats">
        <h3>Статистика системы</h3>
        
        <div class="stats-grid">
            <!-- Пользователи -->
            <div class="stat-card">
                <h4>Пользователи</h4>
                <p class="stat-number"><?= $stats['users']['total'] ?></p>
                <p class="stat-detail">Всего пользователей</p>
                <p class="stat-detail">Активных: <?= $stats['users']['active'] ?></p>
                <p class="stat-detail">Администраторов: <?= $stats['users']['admins'] ?></p>
                <a href="/admin/users.php" class="btn btn-small">Управление</a>
            </div>

            <!-- Вексели -->
            <div class="stat-card">
                <h4>Вексели</h4>
                <p class="stat-number"><?= $stats['bills']['total'] ?></p>
                <p class="stat-detail">Всего векселей</p>
                <p class="stat-detail">Активных: <?= $stats['bills']['active'] ?></p>
                <p class="stat-detail">Сумма активных: <?= number_format($stats['bills']['active_total'], 2) ?> ₽</p>
                <a href="/admin/bills.php" class="btn btn-small">Просмотр</a>
            </div>

            <!-- Транзакции -->
            <div class="stat-card">
                <h4>Транзакции</h4>
                <p class="stat-number"><?= $stats['transactions']['total'] ?></p>
                <p class="stat-detail">Всего транзакций</p>
                <p class="stat-detail">Активных: <?= $stats['transactions']['active'] ?></p>
                <p class="stat-detail">Завершённых: <?= $stats['transactions']['completed'] ?></p>
                <a href="/admin/transactions.php" class="btn btn-small">Просмотр</a>
            </div>

            <!-- Подписки -->
            <div class="stat-card">
                <h4>Подписки</h4>
                <p class="stat-number"><?= $stats['subscriptions']['total'] ?></p>
                <p class="stat-detail">Всего подписок</p>
                <p class="stat-detail">Активных: <?= $stats['subscriptions']['active'] ?></p>
            </div>

            <!-- Категории -->
            <div class="stat-card">
                <h4>Категории</h4>
                <p class="stat-number"><?= $stats['categories']['active'] ?></p>
                <p class="stat-detail">Активных категорий</p>
                <a href="/admin/categories.php" class="btn btn-small">Управление</a>
            </div>

            <!-- Уведомления -->
            <div class="stat-card">
                <h4>Уведомления</h4>
                <p class="stat-number"><?= $stats['notifications']['total'] ?></p>
                <p class="stat-detail">Всего уведомлений</p>
                <p class="stat-detail">Непрочитанных: <?= $stats['notifications']['unread'] ?></p>
            </div>
        </div>
    </div>

    <!-- Управление WebSocket сервером -->
    <div class="admin-websocket-control">
        <h3>Управление WebSocket сервером (WSS)</h3>
        <div class="websocket-status" id="websocketStatus">
            <div class="status-indicator">
                <span class="status-dot" id="statusDot"></span>
                <span class="status-text" id="statusText">Проверка статуса...</span>
            </div>
            <div class="websocket-info" id="websocketInfo"></div>
        </div>
        <div class="websocket-actions">
            <button type="button" class="btn btn-success" id="startWebSocketBtn" onclick="startWebSocket(false)">
                <i class="fas fa-play"></i> Запустить с консолью
            </button>
            <button type="button" class="btn btn-success" id="startWebSocketBackgroundBtn" onclick="startWebSocket(true)">
                <i class="fas fa-play-circle"></i> Запустить в фоне
            </button>
            <button type="button" class="btn btn-danger" id="stopWebSocketBtn" onclick="stopWebSocket()">
                <i class="fas fa-stop"></i> Остановить
            </button>
            <button type="button" class="btn btn-warning" id="restartWebSocketBtn" onclick="restartWebSocket()">
                <i class="fas fa-redo"></i> Перезапустить
            </button>
            <button type="button" class="btn btn-secondary" id="refreshStatusBtn" onclick="checkWebSocketStatus()">
                <i class="fas fa-sync"></i> Обновить статус
            </button>
        </div>
        
        <!-- Управление логированием -->
        <div class="websocket-logging-control" style="margin-top: 20px; padding: 15px; background: #f9f9f9; border-radius: 8px;">
            <h4 style="margin-top: 0;">Управление логированием</h4>
            <div class="logging-status" id="loggingStatus">
                <span class="status-text" id="loggingStatusText">Проверка статуса...</span>
            </div>
            <div class="logging-actions" style="margin-top: 10px;">
                <button type="button" class="btn btn-success" id="enableLoggingBtn" onclick="enableWebSocketLogging()" style="display: none;">
                    <i class="fas fa-toggle-on"></i> Включить логирование
                </button>
                <button type="button" class="btn btn-warning" id="disableLoggingBtn" onclick="disableWebSocketLogging()" style="display: none;">
                    <i class="fas fa-toggle-off"></i> Отключить логирование
                </button>
            </div>
        </div>
    </div>

    <!-- Быстрые действия -->
    <div class="admin-actions">
        <h3><i class="fas fa-bolt"></i> Быстрые действия</h3>
        <div class="admin-action-cards">
            <a href="/admin/users.php" class="admin-action-card admin-action-card-users">
                <div class="admin-action-icon">
                    <i class="fas fa-users"></i>
                </div>
                <div class="admin-action-content">
                    <h4>Пользователи</h4>
                    <p>Управление пользователями системы</p>
                </div>
                <div class="admin-action-arrow">
                    <i class="fas fa-chevron-right"></i>
                </div>
            </a>

            <a href="/admin/transactions.php" class="admin-action-card admin-action-card-transactions">
                <div class="admin-action-icon">
                    <i class="fas fa-exchange-alt"></i>
                </div>
                <div class="admin-action-content">
                    <h4>Транзакции</h4>
                    <p>Просмотр и управление транзакциями</p>
                </div>
                <div class="admin-action-arrow">
                    <i class="fas fa-chevron-right"></i>
                </div>
            </a>

            <a href="/admin/bills.php" class="admin-action-card admin-action-card-bills">
                <div class="admin-action-icon">
                    <i class="fas fa-file-invoice-dollar"></i>
                </div>
                <div class="admin-action-content">
                    <h4>Вексели</h4>
                    <p>Просмотр и управление векселями</p>
                </div>
                <div class="admin-action-arrow">
                    <i class="fas fa-chevron-right"></i>
                </div>
            </a>

            <a href="/admin/categories.php" class="admin-action-card admin-action-card-categories">
                <div class="admin-action-icon">
                    <i class="fas fa-tags"></i>
                </div>
                <div class="admin-action-content">
                    <h4>Категории</h4>
                    <p>Управление категориями товаров</p>
                </div>
                <div class="admin-action-arrow">
                    <i class="fas fa-chevron-right"></i>
                </div>
            </a>

            <a href="/admin/activation_transactions.php" class="admin-action-card admin-action-card-activation">
                <div class="admin-action-icon">
                    <i class="fas fa-user-check"></i>
                </div>
                <div class="admin-action-content">
                    <h4>Активация</h4>
                    <p>Транзакции активации аккаунтов</p>
                </div>
                <div class="admin-action-arrow">
                    <i class="fas fa-chevron-right"></i>
                </div>
            </a>

            <a href="/admin/statistics.php" class="admin-action-card admin-action-card-statistics">
                <div class="admin-action-icon">
                    <i class="fas fa-chart-line"></i>
                </div>
                <div class="admin-action-content">
                    <h4>Статистика</h4>
                    <p>Детальная статистика системы</p>
                </div>
                <div class="admin-action-arrow">
                    <i class="fas fa-chevron-right"></i>
                </div>
            </a>

            <a href="/admin/logs.php" class="admin-action-card admin-action-card-logs">
                <div class="admin-action-icon">
                    <i class="fas fa-shield-alt"></i>
                </div>
                <div class="admin-action-content">
                    <h4>Логи безопасности</h4>
                    <p>Просмотр логов безопасности</p>
                </div>
                <div class="admin-action-arrow">
                    <i class="fas fa-chevron-right"></i>
                </div>
            </a>

            <a href="/admin/websocket-logs.php" class="admin-action-card admin-action-card-websocket">
                <div class="admin-action-icon">
                    <i class="fas fa-plug"></i>
                </div>
                <div class="admin-action-content">
                    <h4>Логи WebSocket</h4>
                    <p>Просмотр логов WebSocket сервера</p>
                </div>
                <div class="admin-action-arrow">
                    <i class="fas fa-chevron-right"></i>
                </div>
            </a>

            <a href="/admin/performance-dashboard.php" class="admin-action-card admin-action-card-performance">
                <div class="admin-action-icon">
                    <i class="fas fa-tachometer-alt"></i>
                </div>
                <div class="admin-action-content">
                    <h4>⚡ Производительность</h4>
                    <p>Сводная панель: OPcache, Cache, PHP</p>
                </div>
                <div class="admin-action-arrow">
                    <i class="fas fa-chevron-right"></i>
                </div>
            </a>

            <a href="/admin/cache.php" class="admin-action-card admin-action-card-cache">
                <div class="admin-action-icon">
                    <i class="fas fa-memory"></i>
                </div>
                <div class="admin-action-content">
                    <h4>Кэш приложения</h4>
                    <p>Управление Redis/File кэшированием</p>
                </div>
                <div class="admin-action-arrow">
                    <i class="fas fa-chevron-right"></i>
                </div>
            </a>

            <a href="/admin/cache-logs.php" class="admin-action-card admin-action-card-cache-logs">
                <div class="admin-action-icon">
                    <i class="fas fa-file-alt"></i>
                </div>
                <div class="admin-action-content">
                    <h4>Логи кэша</h4>
                    <p>Просмотр логов кэширования</p>
                </div>
                <div class="admin-action-arrow">
                    <i class="fas fa-chevron-right"></i>
                </div>
            </a>

            <a href="/admin/database-logs.php" class="admin-action-card admin-action-card-database-logs">
                <div class="admin-action-icon">
                    <i class="fas fa-database"></i>
                </div>
                <div class="admin-action-content">
                    <h4>Логи базы данных</h4>
                    <p>Просмотр логов MySQL (запросы и ошибки)</p>
                </div>
                <div class="admin-action-arrow">
                    <i class="fas fa-chevron-right"></i>
                </div>
            </a>

            <a href="/admin/test-redis.php" class="admin-action-card admin-action-card-redis">
                <div class="admin-action-icon">
                    <i class="fas fa-stethoscope"></i>
                </div>
                <div class="admin-action-content">
                    <h4>Диагностика Redis</h4>
                    <p>Проверка подключения к Redis</p>
                </div>
                <div class="admin-action-arrow">
                    <i class="fas fa-chevron-right"></i>
                </div>
            </a>

            <a href="/admin/user_sessions.php" class="admin-action-card admin-action-card-sessions">
                <div class="admin-action-icon">
                    <i class="fas fa-user-clock"></i>
                </div>
                <div class="admin-action-content">
                    <h4>Сессии</h4>
                    <p>Управление сессиями пользователей</p>
                </div>
                <div class="admin-action-arrow">
                    <i class="fas fa-chevron-right"></i>
                </div>
            </a>

            <a href="/admin/monitoring.php" class="admin-action-card admin-action-card-monitoring">
                <div class="admin-action-icon">
                    <i class="fas fa-server"></i>
                </div>
                <div class="admin-action-content">
                    <h4>Мониторинг</h4>
                    <p>Мониторинг сервера</p>
                </div>
                <div class="admin-action-arrow">
                    <i class="fas fa-chevron-right"></i>
                </div>
            </a>

            <a href="/admin/support.php" class="admin-action-card admin-action-card-support">
                <div class="admin-action-icon">
                    <i class="fas fa-headset"></i>
                </div>
                <div class="admin-action-content">
                    <h4>Поддержка</h4>
                    <p>Управление обращениями поддержки</p>
                </div>
                <div class="admin-action-arrow">
                    <i class="fas fa-chevron-right"></i>
                </div>
            </a>

            <a href="/admin/debug-chat-access.php" class="admin-action-card admin-action-card-debug">
                <div class="admin-action-icon">
                    <i class="fas fa-bug"></i>
                </div>
                <div class="admin-action-content">
                    <h4>Диагностика чата</h4>
                    <p>Проверка доступа к чатам</p>
                </div>
                <div class="admin-action-arrow">
                    <i class="fas fa-chevron-right"></i>
                </div>
            </a>
        </div>
    </div>
</div>

<style>
.admin-welcome {
    background: #f0f0f0;
    padding: 20px;
    border-radius: 8px;
    margin-bottom: 30px;
}

.admin-stats {
    margin-bottom: 30px;
}

.stats-grid {
    display: grid;
    grid-template-columns: repeat(auto-fit, minmax(250px, 1fr));
    gap: 20px;
    margin-top: 20px;
}

.stat-card {
    background: white;
    border: 1px solid #ddd;
    border-radius: 8px;
    padding: 20px;
    box-shadow: 0 2px 4px rgba(0,0,0,0.1);
}

.stat-card h4 {
    margin: 0 0 15px 0;
    color: #333;
    font-size: 1.2em;
}

.stat-number {
    font-size: 2.5em;
    font-weight: bold;
    color: #667eea;
    margin: 10px 0;
}

.stat-detail {
    margin: 5px 0;
    color: #666;
    font-size: 0.9em;
}

.admin-actions {
    background: white;
    border: 1px solid #ddd;
    border-radius: 8px;
    padding: 20px;
    margin-top: 20px;
}

.admin-actions h3 {
    margin-top: 0;
    margin-bottom: 20px;
    color: var(--text-primary);
    display: flex;
    align-items: center;
    gap: 10px;
}

.admin-action-cards {
    display: grid;
    grid-template-columns: repeat(auto-fill, minmax(280px, 1fr));
    gap: 20px;
    margin-top: 20px;
}

.admin-action-card {
    background: var(--bg-primary);
    border-radius: var(--radius-xl);
    padding: 20px;
    box-shadow: 0 2px 8px rgba(0, 0, 0, 0.08);
    display: flex;
    align-items: center;
    gap: 16px;
    transition: all 0.3s cubic-bezier(0.4, 0, 0.2, 1);
    border: 2px solid transparent;
    position: relative;
    overflow: hidden;
    text-decoration: none;
    color: inherit;
}

.admin-action-card::after {
    content: '';
    position: absolute;
    top: 0;
    left: 0;
    right: 0;
    height: 3px;
    background: linear-gradient(90deg, var(--color-primary) 0%, transparent 100%);
    transform: scaleX(0);
    transform-origin: left;
    transition: transform 0.3s ease;
}

.admin-action-card:hover {
    transform: translateY(-4px);
    box-shadow: 0 8px 24px rgba(0, 0, 0, 0.15);
    border-color: var(--color-primary);
    text-decoration: none;
    color: inherit;
}

.admin-action-card:hover::after {
    transform: scaleX(1);
}

.admin-action-icon {
    font-size: 2.5em;
    flex-shrink: 0;
    width: 70px;
    height: 70px;
    display: flex;
    align-items: center;
    justify-content: center;
    border-radius: var(--radius-lg);
    background: linear-gradient(135deg, rgba(44, 62, 80, 0.2) 0%, rgba(44, 62, 80, 0.1) 100%);
    transition: all var(--transition-base);
    position: relative;
    overflow: hidden;
}

.admin-action-card-users .admin-action-icon {
    background: linear-gradient(135deg, rgba(102, 126, 234, 0.25) 0%, rgba(102, 126, 234, 0.1) 100%);
    color: #667eea;
}

.admin-action-card-transactions .admin-action-icon {
    background: linear-gradient(135deg, rgba(139, 92, 246, 0.25) 0%, rgba(139, 92, 246, 0.1) 100%);
    color: #8b5cf6;
}

.admin-action-card-bills .admin-action-icon {
    background: linear-gradient(135deg, rgba(245, 158, 11, 0.25) 0%, rgba(245, 158, 11, 0.1) 100%);
    color: #f59e0b;
}

.admin-action-card-categories .admin-action-icon {
    background: linear-gradient(135deg, rgba(16, 185, 129, 0.25) 0%, rgba(16, 185, 129, 0.1) 100%);
    color: #10b981;
}

.admin-action-card-activation .admin-action-icon {
    background: linear-gradient(135deg, rgba(72, 187, 120, 0.25) 0%, rgba(72, 187, 120, 0.1) 100%);
    color: #48bb78;
}

.admin-action-card-statistics .admin-action-icon {
    background: linear-gradient(135deg, rgba(249, 115, 22, 0.25) 0%, rgba(249, 115, 22, 0.1) 100%);
    color: #f97316;
}

.admin-action-card-logs .admin-action-icon {
    background: linear-gradient(135deg, rgba(245, 158, 11, 0.25) 0%, rgba(245, 158, 11, 0.1) 100%);
    color: #f59e0b;
}

.admin-action-card-websocket .admin-action-icon {
    background: linear-gradient(135deg, rgba(16, 185, 129, 0.25) 0%, rgba(16, 185, 129, 0.1) 100%);
    color: #10b981;
}

.admin-action-card-cache .admin-action-icon {
    background: linear-gradient(135deg, rgba(6, 182, 212, 0.25) 0%, rgba(6, 182, 212, 0.1) 100%);
    color: #06b6d4;
}

.admin-action-card-cache-logs .admin-action-icon {
    background: linear-gradient(135deg, rgba(6, 182, 212, 0.25) 0%, rgba(6, 182, 212, 0.1) 100%);
    color: #06b6d4;
}

.admin-action-card-database-logs .admin-action-icon {
    background: linear-gradient(135deg, rgba(59, 130, 246, 0.25) 0%, rgba(59, 130, 246, 0.1) 100%);
    color: #3b82f6;
}

.admin-action-card-redis .admin-action-icon {
    background: linear-gradient(135deg, rgba(239, 68, 68, 0.25) 0%, rgba(239, 68, 68, 0.1) 100%);
    color: #ef4444;
}

.admin-action-card-sessions .admin-action-icon {
    background: linear-gradient(135deg, rgba(139, 92, 246, 0.25) 0%, rgba(139, 92, 246, 0.1) 100%);
    color: #8b5cf6;
}

.admin-action-card-monitoring .admin-action-icon {
    background: linear-gradient(135deg, rgba(239, 68, 68, 0.25) 0%, rgba(239, 68, 68, 0.1) 100%);
    color: #ef4444;
}

.admin-action-card-support .admin-action-icon {
    background: linear-gradient(135deg, rgba(16, 185, 129, 0.25) 0%, rgba(16, 185, 129, 0.1) 100%);
    color: #10b981;
}

.admin-action-card-debug .admin-action-icon {
    background: linear-gradient(135deg, rgba(156, 163, 175, 0.25) 0%, rgba(156, 163, 175, 0.1) 100%);
    color: #9ca3af;
}

.admin-action-card-performance .admin-action-icon {
    background: linear-gradient(135deg, rgba(102, 126, 234, 0.25) 0%, rgba(102, 126, 234, 0.1) 100%);
    color: #667eea;
}

.admin-action-content {
    flex: 1;
    min-width: 0;
}

.admin-action-content h4 {
    margin: 0 0 5px 0;
    font-size: 1.1em;
    font-weight: 600;
    color: var(--text-primary);
}

.admin-action-content p {
    margin: 0;
    font-size: 0.9em;
    color: var(--text-secondary);
}

.admin-action-arrow {
    flex-shrink: 0;
    color: var(--text-secondary);
    transition: all var(--transition-base);
    opacity: 0.5;
}

.admin-action-card:hover .admin-action-arrow {
    opacity: 1;
    transform: translateX(4px);
    color: var(--color-primary);
}

.admin-websocket-control {
    background: white;
    border: 1px solid #ddd;
    border-radius: 8px;
    padding: 20px;
    margin-bottom: 30px;
}

.admin-websocket-control h3 {
    margin-top: 0;
    margin-bottom: 15px;
}

.websocket-status {
    margin-bottom: 15px;
    padding: 15px;
    background: #f9f9f9;
    border-radius: 6px;
}

.status-indicator {
    display: flex;
    align-items: center;
    gap: 10px;
    margin-bottom: 10px;
}

.status-dot {
    width: 12px;
    height: 12px;
    border-radius: 50%;
    display: inline-block;
    background: #ccc;
}

.status-dot.running {
    background: #10b981;
    box-shadow: 0 0 8px #10b981;
}

.status-dot.stopped {
    background: #ef4444;
}

.status-text {
    font-weight: 500;
    font-size: 1.1em;
}

.websocket-info {
    color: #666;
    font-size: 0.9em;
}

.websocket-actions {
    display: flex;
    flex-wrap: wrap;
    gap: 10px;
}

.websocket-actions button {
    min-width: 140px;
}

.websocket-actions button:disabled {
    opacity: 0.5;
    cursor: not-allowed;
}

@media (max-width: 768px) {
    .stats-grid {
        grid-template-columns: 1fr;
    }
    
    .admin-action-cards {
        grid-template-columns: 1fr;
        gap: 15px;
    }
    
    .websocket-actions {
        flex-direction: column;
    }
    
    .websocket-actions button {
        width: 100%;
    }
}
</style>

<script>
// Управление WebSocket сервером
let websocketStatusCheckInterval = null;

function checkWebSocketStatus() {
    // Создаем AbortController для таймаута
    const controller = new AbortController();
    const timeoutId = setTimeout(() => controller.abort(), 5000); // 5 секунд таймаут
    
    fetch('/api/websocket-control.php?action=status', {
        signal: controller.signal
    })
        .then(response => {
            clearTimeout(timeoutId);
            if (!response.ok) {
                throw new Error(`HTTP error! status: ${response.status}`);
            }
            return response.text().then(text => {
                try {
                    return JSON.parse(text);
                } catch (e) {
                    console.error('Invalid JSON response:', text);
                    throw new Error('Invalid JSON response from server');
                }
            });
        })
        .then(data => {
            clearTimeout(timeoutId);
            if (data.success && data.data) {
                updateWebSocketStatus(data.data);
            } else {
                updateWebSocketStatus({ running: false, port: 8082, protocol: 'wss', url: 'wss://localhost:8082' });
            }
        })
        .catch(error => {
            clearTimeout(timeoutId);
            if (error.name === 'AbortError') {
                console.warn('Status check timeout - server may be busy');
                return; // Не обновляем статус при таймауте
            }
            console.error('Error checking WebSocket status:', error);
            updateWebSocketStatus({ running: false, port: 8082, protocol: 'wss', url: 'wss://localhost:8082' });
        });
}

function updateWebSocketStatus(status) {
    const statusDot = document.getElementById('statusDot');
    const statusText = document.getElementById('statusText');
    const websocketInfo = document.getElementById('websocketInfo');
    const startBtn = document.getElementById('startWebSocketBtn');
    const stopBtn = document.getElementById('stopWebSocketBtn');
    
    const startBackgroundBtn = document.getElementById('startWebSocketBackgroundBtn');
    
    if (status.running) {
        statusDot.className = 'status-dot running';
        statusText.textContent = 'WebSocket сервер запущен';
        const protocol = status.protocol || 'wss';
        const protocolName = protocol === 'wss' ? 'WSS (Secure)' : 'WS';
        websocketInfo.innerHTML = `Протокол: <strong>${protocolName}</strong> | URL: <code>${status.url}</code> | Порт: ${status.port}`;
        startBtn.disabled = true;
        if (startBackgroundBtn) startBackgroundBtn.disabled = true;
        stopBtn.disabled = false;
    } else {
        statusDot.className = 'status-dot stopped';
        statusText.textContent = 'WebSocket сервер остановлен';
        websocketInfo.innerHTML = `Протокол: WSS (Secure) | Порт: ${status.port} | Сервер не запущен`;
        startBtn.disabled = false;
        if (startBackgroundBtn) startBackgroundBtn.disabled = false;
        stopBtn.disabled = true;
    }
}

function startWebSocket(background = false) {
    const btnId = background ? 'startWebSocketBackgroundBtn' : 'startWebSocketBtn';
    const btn = document.getElementById(btnId);
    const originalText = btn.innerHTML;
    const allStartBtns = [
        document.getElementById('startWebSocketBtn'),
        document.getElementById('startWebSocketBackgroundBtn')
    ];
    
    allStartBtns.forEach(b => {
        if (b) {
            b.disabled = true;
            if (b === btn) {
                b.innerHTML = '<i class="fas fa-spinner fa-spin"></i> Запуск...';
            }
        }
    });
    
    const url = background 
        ? '/api/websocket-control.php?action=start&background=true'
        : '/api/websocket-control.php?action=start';
    
    // Создаем AbortController для таймаута
    const controller = new AbortController();
    const timeoutId = setTimeout(() => controller.abort(), 5000); // 5 секунд таймаут
    
    fetch(url, {
        signal: controller.signal
    })
        .then(response => {
            clearTimeout(timeoutId);
            if (!response.ok) {
                throw new Error(`HTTP error! status: ${response.status}`);
            }
            return response.text().then(text => {
                try {
                    return JSON.parse(text);
                } catch (e) {
                    console.error('Invalid JSON response:', text);
                    throw new Error('Invalid JSON response from server');
                }
            });
        })
        .then(data => {
            clearTimeout(timeoutId);
            if (data.success) {
                if (typeof Toast !== 'undefined') {
                    Toast.success(data.message || 'WebSocket сервер успешно запущен');
                } else {
                    alert(data.message || 'WebSocket сервер успешно запущен');
                }
                
                // Если нужно проверить статус, делаем это через несколько секунд
                if (data.check_status) {
                    // Проверяем статус через 3 секунды и еще раз через 5 секунд
                    setTimeout(checkWebSocketStatus, 3000);
                    setTimeout(checkWebSocketStatus, 5000);
                } else {
                    setTimeout(checkWebSocketStatus, 2000);
                }
            } else {
                let errorMsg = data.message || 'Ошибка запуска WebSocket сервера';
                
                // Добавляем детали, если есть
                if (data.details) {
                    errorMsg += '\nДетали: ' + data.details;
                }
                if (data.php_path) {
                    errorMsg += '\nPHP путь: ' + data.php_path;
                }
                
                if (typeof Toast !== 'undefined') {
                    Toast.error(errorMsg);
                } else {
                    alert(errorMsg);
                }
                console.error('WebSocket start error:', data);
                allStartBtns.forEach(b => {
                    if (b) {
                        b.disabled = false;
                        if (b === btn) {
                            b.innerHTML = originalText;
                        }
                    }
                });
            }
        })
        .catch(error => {
            clearTimeout(timeoutId);
            console.error('Error starting WebSocket:', error);
            let errorMsg = 'Ошибка при запуске WebSocket сервера';
            
            if (error.name === 'AbortError') {
                errorMsg = 'Запрос превысил время ожидания. Сервер может быть запущен, проверьте статус.';
                // Проверяем статус через несколько секунд
                setTimeout(checkWebSocketStatus, 3000);
            } else if (error.message) {
                errorMsg += ': ' + error.message;
            }
            
            if (typeof Toast !== 'undefined') {
                Toast.error(errorMsg);
            } else {
                alert(errorMsg);
            }
            allStartBtns.forEach(b => {
                if (b) {
                    b.disabled = false;
                    if (b === btn) {
                        b.innerHTML = originalText;
                    }
                }
            });
        });
}

function stopWebSocket() {
    if (!confirm('Вы уверены, что хотите остановить WebSocket сервер?')) {
        return;
    }
    
    const btn = document.getElementById('stopWebSocketBtn');
    const originalText = btn.innerHTML;
    btn.disabled = true;
    btn.innerHTML = '<i class="fas fa-spinner fa-spin"></i> Остановка...';
    
    fetch('/api/websocket-control.php?action=stop')
        .then(response => {
            if (!response.ok) {
                throw new Error(`HTTP error! status: ${response.status}`);
            }
            return response.text().then(text => {
                try {
                    return JSON.parse(text);
                } catch (e) {
                    console.error('Invalid JSON response:', text);
                    throw new Error('Invalid JSON response from server');
                }
            });
        })
        .then(data => {
            if (data.success) {
                if (typeof Toast !== 'undefined') {
                    Toast.success(data.message || 'WebSocket сервер успешно остановлен');
                } else {
                    alert(data.message || 'WebSocket сервер успешно остановлен');
                }
                setTimeout(checkWebSocketStatus, 1000);
            } else {
                if (typeof Toast !== 'undefined') {
                    Toast.error(data.message || 'Ошибка остановки WebSocket сервера');
                } else {
                    alert(data.message || 'Ошибка остановки WebSocket сервера');
                }
                btn.disabled = false;
                btn.innerHTML = originalText;
            }
        })
        .catch(error => {
            console.error('Error stopping WebSocket:', error);
            if (typeof Toast !== 'undefined') {
                Toast.error('Ошибка при остановке WebSocket сервера');
            } else {
                alert('Ошибка при остановке WebSocket сервера');
            }
            btn.disabled = false;
            btn.innerHTML = originalText;
        });
}

function restartWebSocket() {
    if (!confirm('Вы уверены, что хотите перезапустить WebSocket сервер?')) {
        return;
    }
    
    const btn = document.getElementById('restartWebSocketBtn');
    const originalText = btn.innerHTML;
    btn.disabled = true;
    btn.innerHTML = '<i class="fas fa-spinner fa-spin"></i> Перезапуск...';
    
    fetch('/api/websocket-control.php?action=restart')
        .then(response => {
            if (!response.ok) {
                throw new Error(`HTTP error! status: ${response.status}`);
            }
            return response.text().then(text => {
                try {
                    return JSON.parse(text);
                } catch (e) {
                    console.error('Invalid JSON response:', text);
                    throw new Error('Invalid JSON response from server');
                }
            });
        })
        .then(data => {
            if (data.success) {
                if (typeof Toast !== 'undefined') {
                    Toast.success(data.message || 'WebSocket сервер успешно перезапущен');
                } else {
                    alert(data.message || 'WebSocket сервер успешно перезапущен');
                }
                setTimeout(checkWebSocketStatus, 3000);
            } else {
                if (typeof Toast !== 'undefined') {
                    Toast.error(data.message || 'Ошибка перезапуска WebSocket сервера');
                } else {
                    alert(data.message || 'Ошибка перезапуска WebSocket сервера');
                }
                btn.disabled = false;
                btn.innerHTML = originalText;
            }
        })
        .catch(error => {
            console.error('Error restarting WebSocket:', error);
            if (typeof Toast !== 'undefined') {
                Toast.error('Ошибка при перезапуске WebSocket сервера');
            } else {
                alert('Ошибка при перезапуске WebSocket сервера');
            }
            btn.disabled = false;
            btn.innerHTML = originalText;
        });
}

// Проверяем статус при загрузке страницы
document.addEventListener('DOMContentLoaded', function() {
    checkWebSocketStatus();
    checkLoggingStatus();
    
    // Автоматическое обновление статуса каждые 10 секунд
    websocketStatusCheckInterval = setInterval(checkWebSocketStatus, 10000);
    // Обновление статуса логирования каждые 30 секунд
    setInterval(checkLoggingStatus, 30000);
});

// Останавливаем проверку при уходе со страницы
window.addEventListener('beforeunload', function() {
    if (websocketStatusCheckInterval) {
        clearInterval(websocketStatusCheckInterval);
    }
});

// Управление логированием WebSocket
function checkLoggingStatus() {
    fetch('/api/websocket-control.php?action=logging_status')
        .then(response => response.json())
        .then(data => {
            if (data.success) {
                const statusText = document.getElementById('loggingStatusText');
                const enableBtn = document.getElementById('enableLoggingBtn');
                const disableBtn = document.getElementById('disableLoggingBtn');
                
                if (data.data.enabled) {
                    statusText.textContent = 'Логирование включено';
                    statusText.style.color = '#10b981';
                    statusText.style.fontWeight = '600';
                    if (enableBtn) enableBtn.style.display = 'none';
                    if (disableBtn) disableBtn.style.display = 'inline-block';
                } else {
                    statusText.textContent = 'Логирование отключено';
                    statusText.style.color = '#f59e0b';
                    statusText.style.fontWeight = '600';
                    if (enableBtn) enableBtn.style.display = 'inline-block';
                    if (disableBtn) disableBtn.style.display = 'none';
                }
            }
        })
        .catch(error => {
            console.error('Error checking logging status:', error);
        });
}

function enableWebSocketLogging() {
    if (!confirm('Включить логирование WebSocket сервера?')) {
        return;
    }
    
    const btn = document.getElementById('enableLoggingBtn');
    if (!btn) return;
    
    const originalText = btn.innerHTML;
    btn.disabled = true;
    btn.innerHTML = '<i class="fas fa-spinner fa-spin"></i> Включение...';
    
    fetch('/api/websocket-control.php?action=enable_logging')
        .then(response => response.json())
        .then(data => {
            if (data.success) {
                if (typeof Toast !== 'undefined') {
                    Toast.success(data.message || 'Логирование включено');
                } else {
                    alert(data.message || 'Логирование включено');
                }
                checkLoggingStatus();
            } else {
                if (typeof Toast !== 'undefined') {
                    Toast.error(data.message || 'Ошибка включения логирования');
                } else {
                    alert(data.message || 'Ошибка включения логирования');
                }
                btn.disabled = false;
                btn.innerHTML = originalText;
            }
        })
        .catch(error => {
            console.error('Error enabling logging:', error);
            if (typeof Toast !== 'undefined') {
                Toast.error('Ошибка при включении логирования');
            } else {
                alert('Ошибка при включении логирования');
            }
            btn.disabled = false;
            btn.innerHTML = originalText;
        });
}

function disableWebSocketLogging() {
    if (!confirm('Отключить логирование WebSocket сервера? Это не остановит сервер, но логи перестанут записываться.')) {
        return;
    }
    
    const btn = document.getElementById('disableLoggingBtn');
    if (!btn) return;
    
    const originalText = btn.innerHTML;
    btn.disabled = true;
    btn.innerHTML = '<i class="fas fa-spinner fa-spin"></i> Отключение...';
    
    fetch('/api/websocket-control.php?action=disable_logging')
        .then(response => response.json())
        .then(data => {
            if (data.success) {
                if (typeof Toast !== 'undefined') {
                    Toast.success(data.message || 'Логирование отключено');
                } else {
                    alert(data.message || 'Логирование отключено');
                }
                checkLoggingStatus();
            } else {
                if (typeof Toast !== 'undefined') {
                    Toast.error(data.message || 'Ошибка отключения логирования');
                } else {
                    alert(data.message || 'Ошибка отключения логирования');
                }
                btn.disabled = false;
                btn.innerHTML = originalText;
            }
        })
        .catch(error => {
            console.error('Error disabling logging:', error);
            if (typeof Toast !== 'undefined') {
                Toast.error('Ошибка при отключении логирования');
            } else {
                alert('Ошибка при отключении логирования');
            }
            btn.disabled = false;
            btn.innerHTML = originalText;
        });
}
</script>

<?php
$content = ob_get_clean();
include __DIR__ . '/../../templates/base.php';
?>

