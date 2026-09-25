<?php
/**
 * Страница мониторинга сервера (админ-панель)
 */

require_once __DIR__ . '/../../src/bootstrap.php';

use OGAS\Services\Auth;
use OGAS\Services\AdminService;
use OGAS\Core\SecurityLogger;

Auth::requireAuth();
AdminService::requireAdmin();

$user = Auth::user();

// Логируем доступ к мониторингу
SecurityLogger::logAdminAccess('view_server_monitoring', true);

$title = 'Мониторинг сервера';

ob_start();
?>

<div class="admin-container">
    <div class="admin-header">
        <h1><i class="fas fa-server"></i> Мониторинг сервера</h1>
        <div class="admin-actions">
            <button type="button" class="btn btn-secondary" onclick="refreshMetrics()">
                <i class="fas fa-sync"></i> Обновить
            </button>
            <button type="button" class="btn btn-secondary" id="autoRefreshBtn" onclick="toggleAutoRefresh()">
                <i class="fas fa-play"></i> Автообновление: ВЫКЛ
            </button>
        </div>
    </div>

    <div class="monitoring-grid" style="display: grid; grid-template-columns: repeat(auto-fit, minmax(300px, 1fr)); gap: 20px; margin-top: 20px;">
        
        <!-- CPU -->
        <div class="metric-card" style="background: white; padding: 20px; border-radius: 8px; box-shadow: 0 2px 4px rgba(0,0,0,0.1);">
            <h3 style="margin-top: 0; color: #333;">
                <i class="fas fa-microchip" style="color: #3b82f6;"></i> CPU
            </h3>
            <div class="metric-value" id="cpuUsage" style="font-size: 32px; font-weight: bold; color: #3b82f6;">
                <span id="cpuUsagePercent">-</span>%
            </div>
            <div class="metric-details" id="cpuDetails" style="margin-top: 10px; color: #666; font-size: 14px;">
                <div>Ядер: <span id="cpuCores">-</span></div>
                <div>Логических процессоров: <span id="cpuLogicalProcessors">-</span></div>
                <div id="cpuThreads" style="display: none;">Потоков: <span id="cpuThreadsCount">-</span></div>
                <div id="cpuLoadAverage" style="display: none;">
                    Load Average: <span id="load1min">-</span> / <span id="load5min">-</span> / <span id="load15min">-</span>
                </div>
            </div>
            <div class="progress-bar" style="margin-top: 15px; height: 8px; background: #e5e7eb; border-radius: 4px; overflow: hidden;">
                <div id="cpuProgress" style="height: 100%; background: #3b82f6; width: 0%; transition: width 0.3s;"></div>
            </div>
        </div>

        <!-- Память -->
        <div class="metric-card" style="background: white; padding: 20px; border-radius: 8px; box-shadow: 0 2px 4px rgba(0,0,0,0.1);">
            <h3 style="margin-top: 0; color: #333;">
                <i class="fas fa-memory" style="color: #10b981;"></i> Память
            </h3>
            <div class="metric-value" id="memoryUsage" style="font-size: 32px; font-weight: bold; color: #10b981;">
                <span id="memoryUsagePercent">-</span>%
            </div>
            <div class="metric-details" id="memoryDetails" style="margin-top: 10px; color: #666; font-size: 14px;">
                <div>Использовано: <span id="memoryUsed">-</span> / <span id="memoryTotal">-</span></div>
                <div>Свободно: <span id="memoryFree">-</span></div>
            </div>
            <div class="progress-bar" style="margin-top: 15px; height: 8px; background: #e5e7eb; border-radius: 4px; overflow: hidden;">
                <div id="memoryProgress" style="height: 100%; background: #10b981; width: 0%; transition: width 0.3s;"></div>
            </div>
        </div>

        <!-- Диск -->
        <div class="metric-card" style="background: white; padding: 20px; border-radius: 8px; box-shadow: 0 2px 4px rgba(0,0,0,0.1);">
            <h3 style="margin-top: 0; color: #333;">
                <i class="fas fa-hdd" style="color: #f59e0b;"></i> Диск
            </h3>
            <div class="metric-value" id="diskUsage" style="font-size: 32px; font-weight: bold; color: #f59e0b;">
                <span id="diskUsagePercent">-</span>%
            </div>
            <div class="metric-details" id="diskDetails" style="margin-top: 10px; color: #666; font-size: 14px;">
                <div>Использовано: <span id="diskUsed">-</span> / <span id="diskTotal">-</span></div>
                <div>Свободно: <span id="diskFree">-</span></div>
                <div id="diskPath" style="font-size: 12px; color: #999; margin-top: 5px;"></div>
            </div>
            <div class="progress-bar" style="margin-top: 15px; height: 8px; background: #e5e7eb; border-radius: 4px; overflow: hidden;">
                <div id="diskProgress" style="height: 100%; background: #f59e0b; width: 0%; transition: width 0.3s;"></div>
            </div>
        </div>

        <!-- Диск I/O -->
        <div class="metric-card" style="background: white; padding: 20px; border-radius: 8px; box-shadow: 0 2px 4px rgba(0,0,0,0.1);">
            <h3 style="margin-top: 0; color: #333;">
                <i class="fas fa-database" style="color: #ec4899;"></i> Диск I/O
            </h3>
            <div class="metric-value" id="diskIO" style="font-size: 24px; font-weight: bold; color: #ec4899;">
                <span id="diskIOTotal">-</span>
            </div>
            <div class="metric-details" id="diskIODetails" style="margin-top: 10px; color: #666; font-size: 14px;">
                <div>Чтение: <span id="diskIORead">-</span></div>
                <div>Запись: <span id="diskIOWrite">-</span></div>
                <div>Операций/сек: <span id="diskIOOps">-</span></div>
            </div>
        </div>

        <!-- Сеть -->
        <div class="metric-card" style="background: white; padding: 20px; border-radius: 8px; box-shadow: 0 2px 4px rgba(0,0,0,0.1);">
            <h3 style="margin-top: 0; color: #333;">
                <i class="fas fa-network-wired" style="color: #8b5cf6;"></i> Сеть
            </h3>
            <div class="metric-value" id="networkTotal" style="font-size: 24px; font-weight: bold; color: #8b5cf6;">
                <span id="networkTotalBytes">-</span>
            </div>
            <div class="metric-details" id="networkDetails" style="margin-top: 10px; color: #666; font-size: 14px;">
                <div>Получено: <span id="networkReceived">-</span></div>
                <div>Отправлено: <span id="networkSent">-</span></div>
            </div>
        </div>

        <!-- Время работы -->
        <div class="metric-card" style="background: white; padding: 20px; border-radius: 8px; box-shadow: 0 2px 4px rgba(0,0,0,0.1);">
            <h3 style="margin-top: 0; color: #333;">
                <i class="fas fa-clock" style="color: #ef4444;"></i> Время работы
            </h3>
            <div class="metric-value" id="uptime" style="font-size: 24px; font-weight: bold; color: #ef4444;">
                <span id="uptimeFormatted">-</span>
            </div>
            <div class="metric-details" style="margin-top: 10px; color: #666; font-size: 14px;">
                <div>Последнее обновление: <span id="lastUpdate">-</span></div>
            </div>
        </div>

    </div>

    <!-- Графики -->
    <div class="charts-container" style="margin-top: 30px;">
        <div class="chart-card" style="background: white; padding: 20px; border-radius: 8px; box-shadow: 0 2px 4px rgba(0,0,0,0.1); margin-bottom: 20px;">
            <h3 style="margin-top: 0;">Использование CPU (%)</h3>
            <canvas id="cpuChart" style="max-height: 300px;"></canvas>
        </div>
        <div class="chart-card" style="background: white; padding: 20px; border-radius: 8px; box-shadow: 0 2px 4px rgba(0,0,0,0.1); margin-bottom: 20px;">
            <h3 style="margin-top: 0;">Использование памяти (%)</h3>
            <canvas id="memoryChart" style="max-height: 300px;"></canvas>
        </div>
        <div class="chart-card" style="background: white; padding: 20px; border-radius: 8px; box-shadow: 0 2px 4px rgba(0,0,0,0.1);">
            <h3 style="margin-top: 0;">Использование диска (%)</h3>
            <canvas id="diskChart" style="max-height: 300px;"></canvas>
        </div>
    </div>
</div>

<script src="https://cdn.jsdelivr.net/npm/chart.js@4.4.0/dist/chart.umd.js"></script>
<script>
let autoRefreshInterval = null;
let autoRefreshEnabled = false;
let cpuChart = null;
let memoryChart = null;
let diskChart = null;

// Данные для графиков (храним последние 20 точек)
const chartData = {
    cpu: [],
    memory: [],
    disk: [],
    labels: []
};

// Инициализация графиков
function initCharts() {
    const chartOptions = {
        responsive: true,
        maintainAspectRatio: false,
        scales: {
            y: {
                beginAtZero: true,
                max: 100,
                ticks: {
                    callback: function(value) {
                        return value + '%';
                    }
                }
            }
        },
        plugins: {
            legend: {
                display: false
            },
            tooltip: {
                callbacks: {
                    label: function(context) {
                        return context.parsed.y.toFixed(2) + '%';
                    }
                }
            }
        }
    };

    // CPU Chart
    const cpuCtx = document.getElementById('cpuChart').getContext('2d');
    cpuChart = new Chart(cpuCtx, {
        type: 'line',
        data: {
            labels: [],
            datasets: [{
                label: 'CPU Usage',
                data: [],
                borderColor: '#3b82f6',
                backgroundColor: 'rgba(59, 130, 246, 0.1)',
                tension: 0.4,
                fill: true
            }]
        },
        options: chartOptions
    });

    // Memory Chart
    const memoryCtx = document.getElementById('memoryChart').getContext('2d');
    memoryChart = new Chart(memoryCtx, {
        type: 'line',
        data: {
            labels: [],
            datasets: [{
                label: 'Memory Usage',
                data: [],
                borderColor: '#10b981',
                backgroundColor: 'rgba(16, 185, 129, 0.1)',
                tension: 0.4,
                fill: true
            }]
        },
        options: chartOptions
    });

    // Disk Chart
    const diskCtx = document.getElementById('diskChart').getContext('2d');
    diskChart = new Chart(diskCtx, {
        type: 'line',
        data: {
            labels: [],
            datasets: [{
                label: 'Disk Usage',
                data: [],
                borderColor: '#f59e0b',
                backgroundColor: 'rgba(245, 158, 11, 0.1)',
                tension: 0.4,
                fill: true
            }]
        },
        options: chartOptions
    });
}

// Обновление метрик
function refreshMetrics() {
    fetch('/api/server-metrics.php')
        .then(response => response.json())
        .then(data => {
            if (data.success && data.data) {
                updateMetrics(data.data);
            } else {
                console.error('Error fetching metrics:', data);
            }
        })
        .catch(error => {
            console.error('Error fetching metrics:', error);
        });
}

    // Обновление UI с метриками
function updateMetrics(metrics) {
    // CPU
    const cpuUsage = metrics.cpu?.usage_percent || 0;
    document.getElementById('cpuUsagePercent').textContent = cpuUsage.toFixed(1);
    document.getElementById('cpuCores').textContent = metrics.cpu?.cores || '-';
    document.getElementById('cpuLogicalProcessors').textContent = metrics.cpu?.logical_processors || metrics.cpu?.cores || '-';
    
    if (metrics.cpu?.threads && metrics.cpu.threads > 0) {
        document.getElementById('cpuThreads').style.display = 'block';
        document.getElementById('cpuThreadsCount').textContent = metrics.cpu.threads;
    } else {
        document.getElementById('cpuThreads').style.display = 'none';
    }
    
    document.getElementById('cpuProgress').style.width = cpuUsage + '%';
    
    if (metrics.cpu?.load_average) {
        document.getElementById('cpuLoadAverage').style.display = 'block';
        document.getElementById('load1min').textContent = metrics.cpu.load_average['1min'] || '-';
        document.getElementById('load5min').textContent = metrics.cpu.load_average['5min'] || '-';
        document.getElementById('load15min').textContent = metrics.cpu.load_average['15min'] || '-';
    }

    // Memory
    const memoryUsage = metrics.memory?.usage_percent || 0;
    document.getElementById('memoryUsagePercent').textContent = memoryUsage.toFixed(1);
    document.getElementById('memoryUsed').textContent = metrics.memory?.used_formatted || '-';
    document.getElementById('memoryTotal').textContent = metrics.memory?.total_formatted || '-';
    document.getElementById('memoryFree').textContent = metrics.memory?.free_formatted || '-';
    document.getElementById('memoryProgress').style.width = memoryUsage + '%';

    // Disk
    const diskUsage = metrics.disk?.usage_percent || 0;
    document.getElementById('diskUsagePercent').textContent = diskUsage.toFixed(1);
    document.getElementById('diskUsed').textContent = metrics.disk?.used_formatted || '-';
    document.getElementById('diskTotal').textContent = metrics.disk?.total_formatted || '-';
    document.getElementById('diskFree').textContent = metrics.disk?.free_formatted || '-';
    if (metrics.disk?.drive) {
        document.getElementById('diskPath').textContent = 'Диск: ' + metrics.disk.drive;
    } else if (metrics.disk?.path) {
        document.getElementById('diskPath').textContent = 'Путь: ' + metrics.disk.path;
    }
    document.getElementById('diskProgress').style.width = diskUsage + '%';

    // Disk I/O
    if (metrics.disk_io) {
        document.getElementById('diskIOTotal').textContent = metrics.disk_io?.total_bytes_per_sec_formatted || '-';
        document.getElementById('diskIORead').textContent = metrics.disk_io?.read_bytes_per_sec_formatted || '-';
        document.getElementById('diskIOWrite').textContent = metrics.disk_io?.write_bytes_per_sec_formatted || '-';
        document.getElementById('diskIOOps').textContent = metrics.disk_io?.total_ops_per_sec?.toFixed(0) || '-';
    } else {
        document.getElementById('diskIOTotal').textContent = '-';
        document.getElementById('diskIORead').textContent = '-';
        document.getElementById('diskIOWrite').textContent = '-';
        document.getElementById('diskIOOps').textContent = '-';
    }

    // Network
    document.getElementById('networkTotalBytes').textContent = metrics.network?.total_formatted || '-';
    document.getElementById('networkReceived').textContent = metrics.network?.bytes_received_formatted || '-';
    document.getElementById('networkSent').textContent = metrics.network?.bytes_sent_formatted || '-';

    // Uptime
    document.getElementById('uptimeFormatted').textContent = metrics.uptime?.formatted || '-';
    document.getElementById('lastUpdate').textContent = metrics.datetime || '-';

    // Обновляем графики
    updateCharts(cpuUsage, memoryUsage, diskUsage);
}

// Обновление графиков
function updateCharts(cpuUsage, memoryUsage, diskUsage) {
    const now = new Date().toLocaleTimeString();
    
    // Добавляем новые данные
    chartData.cpu.push(cpuUsage);
    chartData.memory.push(memoryUsage);
    chartData.disk.push(diskUsage);
    chartData.labels.push(now);
    
    // Ограничиваем до 20 точек
    const maxPoints = 20;
    if (chartData.cpu.length > maxPoints) {
        chartData.cpu.shift();
        chartData.memory.shift();
        chartData.disk.shift();
        chartData.labels.shift();
    }
    
    // Обновляем графики
    if (cpuChart) {
        cpuChart.data.labels = chartData.labels;
        cpuChart.data.datasets[0].data = chartData.cpu;
        cpuChart.update('none');
    }
    
    if (memoryChart) {
        memoryChart.data.labels = chartData.labels;
        memoryChart.data.datasets[0].data = chartData.memory;
        memoryChart.update('none');
    }
    
    if (diskChart) {
        diskChart.data.labels = chartData.labels;
        diskChart.data.datasets[0].data = chartData.disk;
        diskChart.update('none');
    }
}

// Переключение автообновления
function toggleAutoRefresh() {
    autoRefreshEnabled = !autoRefreshEnabled;
    const btn = document.getElementById('autoRefreshBtn');
    
    if (autoRefreshEnabled) {
        btn.innerHTML = '<i class="fas fa-pause"></i> Автообновление: ВКЛ';
        btn.classList.remove('btn-secondary');
        btn.classList.add('btn-success');
        autoRefreshInterval = setInterval(refreshMetrics, 5000); // Обновление каждые 5 секунд
    } else {
        btn.innerHTML = '<i class="fas fa-play"></i> Автообновление: ВЫКЛ';
        btn.classList.remove('btn-success');
        btn.classList.add('btn-secondary');
        if (autoRefreshInterval) {
            clearInterval(autoRefreshInterval);
            autoRefreshInterval = null;
        }
    }
}

// Инициализация при загрузке страницы
document.addEventListener('DOMContentLoaded', function() {
    initCharts();
    refreshMetrics();
    
    // Обновляем каждые 10 секунд по умолчанию
    setInterval(refreshMetrics, 10000);
});
</script>

<style>
.metric-card {
    transition: transform 0.2s, box-shadow 0.2s;
}

.metric-card:hover {
    transform: translateY(-2px);
    box-shadow: 0 4px 8px rgba(0,0,0,0.15) !important;
}

.progress-bar {
    position: relative;
}

.progress-bar::after {
    content: '';
    position: absolute;
    top: 0;
    left: 0;
    right: 0;
    bottom: 0;
    background: linear-gradient(90deg, transparent, rgba(255,255,255,0.3), transparent);
    animation: shimmer 2s infinite;
}

@keyframes shimmer {
    0% { transform: translateX(-100%); }
    100% { transform: translateX(100%); }
}
</style>

<?php
$content = ob_get_clean();
require_once __DIR__ . '/../../templates/base.php';
?>

