<?php
/**
 * Страница с графиками и визуализацией данных
 */

require_once __DIR__ . '/../src/bootstrap.php';

use OGAS\Services\Auth;
use OGAS\Models\User;
use OGAS\Models\Rating;
use OGAS\Services\BillService;
use OGAS\Services\TransactionService;

Auth::requireAuth();
$user = Auth::user();

if (!$user) {
    Auth::logout();
    header('Location: /login.php');
    exit;
}

// Получаем текущую статистику
$rating = Rating::findByUserId($user->getId());
$billStats = BillService::getStatistics($user->getId());
$transactionStats = TransactionService::getStatistics($user->getId());

$title = 'Графики и статистика';
ob_start();
?>
<div class="dashboard">
    <div class="dashboard-header">
        <h2>Графики и статистика</h2>
        <a href="/dashboard.php" class="btn btn-secondary">← Назад</a>
    </div>
    
    <!-- Табы для переключения -->
    <div class="charts-tabs">
        <button class="chart-tab-btn active" data-tab="charts" onclick="switchTab('charts')">
            <i class="fas fa-chart-line"></i>
            <span>Графики</span>
        </button>
        <button class="chart-tab-btn" data-tab="calculations" onclick="switchTab('calculations')">
            <i class="fas fa-calculator"></i>
            <span>Экономические расчёты</span>
        </button>
    </div>
    
    <!-- Вкладка: Графики -->
    <div id="charts-tab" class="tab-content active">
    <div class="charts-container">
        <!-- График изменения рейтинга -->
        <div class="info-card chart-card">
            <div class="chart-header">
                <h3>Изменение рейтинга "Око"</h3>
                <button class="chart-details-btn" onclick="showChartDetails('rating')">
                    <i class="fas fa-info-circle"></i> Как считается
                </button>
            </div>
            <canvas id="ratingChart" width="400" height="200"></canvas>
            <p class="chart-info">
                <strong>Текущий рейтинг:</strong> <?= number_format($rating->getTotalRating(), 2) ?> баллов
                | <strong>Дубли:</strong> <?= number_format($rating->getDoubles(), 2) ?>
            </p>
        </div>
        
        <!-- График баланса векселей -->
        <div class="info-card chart-card">
            <div class="chart-header">
                <h3>Баланс векселей</h3>
                <button class="chart-details-btn" onclick="showChartDetails('bills_balance')">
                    <i class="fas fa-info-circle"></i> Как считается
                </button>
            </div>
            <canvas id="billsChart" width="400" height="200"></canvas>
            <p class="chart-info">
                <strong>Выпущено:</strong> <?= $billStats['issued_count'] ?> (<?= number_format($billStats['issued_total'], 2, '.', ' ') ?> ₽)
                | <strong>Получено:</strong> <?= $billStats['held_count'] ?> (<?= number_format($billStats['held_total'], 2, '.', ' ') ?> ₽)
            </p>
        </div>
        
        <!-- График динамики погашения векселей -->
        <div class="info-card chart-card">
            <div class="chart-header">
                <h3>Динамика погашения векселей</h3>
                <button class="chart-details-btn" onclick="showChartDetails('payment_dynamics')">
                    <i class="fas fa-info-circle"></i> Как считается
                </button>
            </div>
            <canvas id="paymentChart" width="400" height="200"></canvas>
        </div>
        
        <!-- Круговая диаграмма своевременности погашения -->
        <div class="info-card chart-card">
            <div class="chart-header">
                <h3>Своевременность погашения</h3>
                <button class="chart-details-btn" onclick="showChartDetails('payment_pie')">
                    <i class="fas fa-info-circle"></i> Как считается
                </button>
            </div>
            <canvas id="paymentPieChart" width="400" height="200"></canvas>
        </div>
        
        <!-- График статистики транзакций -->
        <div class="info-card chart-card">
            <div class="chart-header">
                <h3>Статистика транзакций</h3>
                <button class="chart-details-btn" onclick="showChartDetails('transactions')">
                    <i class="fas fa-info-circle"></i> Как считается
                </button>
            </div>
            <canvas id="transactionsChart" width="400" height="200"></canvas>
            <p class="chart-info">
                <strong>Всего:</strong> <?= $transactionStats['total'] ?>
                | <strong>Завершённых:</strong> <?= $transactionStats['completed'] ?>
                | <strong>Активных:</strong> <?= $transactionStats['active'] ?>
            </p>
        </div>
        
        <!-- График распределения транзакций по категориям -->
        <div class="info-card chart-card">
            <div class="chart-header">
                <h3>Распределение транзакций по категориям</h3>
                <button class="chart-details-btn" onclick="showChartDetails('categories')">
                    <i class="fas fa-info-circle"></i> Как считается
                </button>
            </div>
            <canvas id="categoriesChart" width="400" height="200"></canvas>
        </div>
    </div>
    </div>
    
    <!-- Вкладка: Экономические расчёты -->
    <div id="calculations-tab" class="tab-content">
        <!-- Блок автоматических экономических расчётов -->
        <div class="info-card economic-calculations">
            <h3><i class="fas fa-calculator"></i> Автоматические экономические расчёты</h3>
            <div class="economic-metrics" id="economicMetrics">
                <div class="metrics-loading">
                    <i class="fas fa-spinner fa-spin"></i> Загрузка расчётов...
                </div>
            </div>
        </div>
        
        <!-- Графики -->
        <div class="calculations-charts-container">
            <!-- График финансовых потоков -->
            <div class="info-card chart-card">
                <div class="chart-header">
                    <h3>Прогноз денежных потоков</h3>
                    <button class="chart-details-btn" onclick="showChartDetails('cashflow')">
                        <i class="fas fa-info-circle"></i> Как считается
                    </button>
                </div>
                <canvas id="cashflowChart" width="400" height="200"></canvas>
            </div>
            
            <!-- График показателей эффективности -->
            <div class="info-card chart-card">
                <div class="chart-header">
                    <h3>Показатели эффективности</h3>
                    <button class="chart-details-btn" onclick="showChartDetails('efficiency')">
                        <i class="fas fa-info-circle"></i> Как считается
                    </button>
                </div>
                <canvas id="efficiencyChart" width="400" height="200"></canvas>
            </div>
        </div>
    </div>
</div>

<!-- Модальное окно детализации расчёта -->
<div id="metricDetailsModal" class="modal" style="display: none;">
    <div class="modal-content modal-medium">
        <div class="modal-header">
            <h3 id="metricDetailsTitle">Детализация расчёта</h3>
            <button type="button" class="modal-close" onclick="closeMetricDetails()">&times;</button>
        </div>
        <div class="modal-body" id="metricDetailsBody">
            <!-- Детали будут загружены динамически -->
        </div>
    </div>
</div>

<!-- Chart.js -->
<script src="https://cdn.jsdelivr.net/npm/chart.js@4.4.0/dist/chart.umd.min.js"></script>

<script>
// График изменения рейтинга
fetch('/api/charts.php?action=rating')
    .then(response => response.json())
    .then(data => {
        const ctx = document.getElementById('ratingChart').getContext('2d');
        new Chart(ctx, {
            type: 'line',
            data: data,
            options: {
                responsive: true,
                maintainAspectRatio: true,
                scales: {
                    y: {
                        beginAtZero: true,
                        title: {
                            display: true,
                            text: 'Рейтинг'
                        }
                    },
                    y1: {
                        type: 'linear',
                        display: true,
                        position: 'right',
                        title: {
                            display: true,
                            text: 'Дубли'
                        },
                        grid: {
                            drawOnChartArea: false
                        }
                    }
                },
                plugins: {
                    legend: {
                        display: true
                    }
                }
            }
        });
    })
    .catch(error => {
        console.error('Ошибка загрузки данных рейтинга:', error);
        document.getElementById('ratingChart').parentElement.innerHTML = '<p style="color: red;">Ошибка загрузки данных</p>';
    });

// График баланса векселей
fetch('/api/charts.php?action=bills_balance')
    .then(response => response.json())
    .then(data => {
        const ctx = document.getElementById('billsChart').getContext('2d');
        new Chart(ctx, {
            type: 'line',
            data: data,
            options: {
                responsive: true,
                maintainAspectRatio: true,
                scales: {
                    y: {
                        beginAtZero: true,
                        title: {
                            display: true,
                            text: 'Сумма (₽)'
                        }
                    }
                },
                plugins: {
                    legend: {
                        display: true
                    }
                }
            }
        });
    })
    .catch(error => {
        console.error('Ошибка загрузки данных баланса:', error);
        document.getElementById('billsChart').parentElement.innerHTML = '<p style="color: red;">Ошибка загрузки данных</p>';
    });

// График динамики погашения
fetch('/api/charts.php?action=payment_dynamics')
    .then(response => response.json())
    .then(data => {
        // Линейный график
        const ctx = document.getElementById('paymentChart').getContext('2d');
        new Chart(ctx, {
            type: 'bar',
            data: {
                labels: data.labels,
                datasets: data.datasets
            },
            options: {
                responsive: true,
                maintainAspectRatio: true,
                scales: {
                    y: {
                        beginAtZero: true,
                        title: {
                            display: true,
                            text: 'Количество'
                        }
                    },
                    y1: {
                        type: 'linear',
                        display: true,
                        position: 'right',
                        title: {
                            display: true,
                            text: 'Сумма (₽)'
                        },
                        grid: {
                            drawOnChartArea: false
                        }
                    }
                },
                plugins: {
                    legend: {
                        display: true
                    }
                }
            }
        });
        
        // Круговая диаграмма
        if (data.pie) {
            const pieCtx = document.getElementById('paymentPieChart').getContext('2d');
            new Chart(pieCtx, {
                type: 'doughnut',
                data: {
                    labels: data.pie.labels,
                    datasets: [{
                        data: data.pie.data,
                        backgroundColor: [
                            'rgba(75, 192, 192, 0.8)',
                            'rgba(54, 162, 235, 0.8)',
                            'rgba(255, 99, 132, 0.8)'
                        ],
                        borderColor: [
                            'rgb(75, 192, 192)',
                            'rgb(54, 162, 235)',
                            'rgb(255, 99, 132)'
                        ],
                        borderWidth: 1
                    }]
                },
                options: {
                    responsive: true,
                    maintainAspectRatio: true,
                    plugins: {
                        legend: {
                            display: true,
                            position: 'bottom'
                        }
                    }
                }
            });
        }
    })
    .catch(error => {
        console.error('Ошибка загрузки данных погашения:', error);
        document.getElementById('paymentChart').parentElement.innerHTML = '<p style="color: red;">Ошибка загрузки данных</p>';
    });

// График статистики транзакций
fetch('/api/charts.php?action=transactions')
    .then(response => response.json())
    .then(data => {
        const ctx = document.getElementById('transactionsChart').getContext('2d');
        new Chart(ctx, {
            type: 'line',
            data: data,
            options: {
                responsive: true,
                maintainAspectRatio: true,
                scales: {
                    y: {
                        beginAtZero: true,
                        ticks: {
                            stepSize: 1
                        },
                        title: {
                            display: true,
                            text: 'Количество транзакций'
                        }
                    }
                },
                plugins: {
                    legend: {
                        display: true
                    }
                }
            }
        });
    })
    .catch(error => {
        console.error('Ошибка загрузки данных транзакций:', error);
        document.getElementById('transactionsChart').parentElement.innerHTML = '<p style="color: red;">Ошибка загрузки данных</p>';
    });

// График распределения по категориям
fetch('/api/charts.php?action=categories_distribution')
    .then(response => response.json())
    .then(data => {
        const ctx = document.getElementById('categoriesChart').getContext('2d');
        new Chart(ctx, {
            type: 'doughnut',
            data: data,
            options: {
                responsive: true,
                maintainAspectRatio: true,
                plugins: {
                    legend: {
                        display: true,
                        position: 'bottom'
                    },
                    tooltip: {
                        callbacks: {
                            label: function(context) {
                                let label = context.label || '';
                                if (label) {
                                    label += ': ';
                                }
                                label += context.parsed + ' транзакций';
                                return label;
                            }
                        }
                    }
                }
            }
        });
    })
    .catch(error => {
        console.error('Ошибка загрузки данных по категориям:', error);
        document.getElementById('categoriesChart').parentElement.innerHTML = '<p style="color: red;">Ошибка загрузки данных</p>';
    });

// Функция переключения табов
function switchTab(tabName) {
    // Переключаем кнопки табов
    document.querySelectorAll('.chart-tab-btn').forEach(btn => {
        btn.classList.remove('active');
    });
    document.querySelector(`[data-tab="${tabName}"]`).classList.add('active');
    
    // Переключаем содержимое табов
    document.querySelectorAll('.tab-content').forEach(content => {
        content.classList.remove('active');
    });
    document.getElementById(`${tabName}-tab`).classList.add('active');
    
    // Если переключаемся на расчёты, загружаем данные
    if (tabName === 'calculations' && !window.calculationsLoaded) {
        loadEconomicCalculations();
        loadCashflowChart();
        loadEfficiencyChart();
        window.calculationsLoaded = true;
    }
}

// Загрузка автоматических экономических расчётов
function loadEconomicCalculations() {
    fetch('/api/charts.php?action=economic_calculations')
        .then(response => response.json())
        .then(data => {
            const container = document.getElementById('economicMetrics');
            
            if (data.error) {
                container.innerHTML = `<div class="metric-error">Ошибка: ${data.error}</div>`;
                return;
            }
            
            let html = '<div class="metrics-grid">';
            
            // Показатели ликвидности
            html += `
                <div class="metric-card metric-liquidity">
                    <div class="metric-icon"><i class="fas fa-coins"></i></div>
                    <div class="metric-content">
                        <div class="metric-label">Ликвидность</div>
                        <div class="metric-value ${data.liquidity.rating}">${data.liquidity.ratio.toFixed(2)}</div>
                        <div class="metric-description">${data.liquidity.description}</div>
                        <button class="metric-details-btn" onclick="showMetricDetails('liquidity', ${JSON.stringify(data.liquidity).replace(/"/g, '&quot;')})">
                            <i class="fas fa-info-circle"></i> Детали
                        </button>
                    </div>
                </div>
            `;
            
            // Чистый баланс
            html += `
                <div class="metric-card metric-balance">
                    <div class="metric-icon"><i class="fas fa-balance-scale"></i></div>
                    <div class="metric-content">
                        <div class="metric-label">Чистый баланс</div>
                        <div class="metric-value ${data.netBalance.rating}">${data.netBalance.value.toLocaleString('ru-RU', {style: 'currency', currency: 'RUB', minimumFractionDigits: 0})}</div>
                        <div class="metric-description">${data.netBalance.description}</div>
                        <button class="metric-details-btn" onclick="showMetricDetails('balance', ${JSON.stringify(data.netBalance).replace(/"/g, '&quot;')})">
                            <i class="fas fa-info-circle"></i> Детали
                        </button>
                    </div>
                </div>
            `;
            
            // Эффективность оборачиваемости
            html += `
                <div class="metric-card metric-turnover">
                    <div class="metric-icon"><i class="fas fa-sync-alt"></i></div>
                    <div class="metric-content">
                        <div class="metric-label">Оборачиваемость векселей</div>
                        <div class="metric-value ${data.turnover.rating}">${data.turnover.ratio.toFixed(2)}</div>
                        <div class="metric-description">${data.turnover.description}</div>
                        <button class="metric-details-btn" onclick="showMetricDetails('turnover', ${JSON.stringify(data.turnover).replace(/"/g, '&quot;')})">
                            <i class="fas fa-info-circle"></i> Детали
                        </button>
                    </div>
                </div>
            `;
            
            // Доходность
            html += `
                <div class="metric-card metric-profitability">
                    <div class="metric-icon"><i class="fas fa-chart-line"></i></div>
                    <div class="metric-content">
                        <div class="metric-label">Доходность</div>
                        <div class="metric-value ${data.profitability.rating}">${(data.profitability.ratio * 100).toFixed(2)}%</div>
                        <div class="metric-description">${data.profitability.description}</div>
                        <button class="metric-details-btn" onclick="showMetricDetails('profitability', ${JSON.stringify(data.profitability).replace(/"/g, '&quot;')})">
                            <i class="fas fa-info-circle"></i> Детали
                        </button>
                    </div>
                </div>
            `;
            
            // Риск просрочки
            html += `
                <div class="metric-card metric-risk">
                    <div class="metric-icon"><i class="fas fa-exclamation-triangle"></i></div>
                    <div class="metric-content">
                        <div class="metric-label">Риск просрочки</div>
                        <div class="metric-value ${data.overdueRisk.rating}">${(data.overdueRisk.ratio * 100).toFixed(1)}%</div>
                        <div class="metric-description">${data.overdueRisk.description}</div>
                        <button class="metric-details-btn" onclick="showMetricDetails('risk', ${JSON.stringify(data.overdueRisk).replace(/"/g, '&quot;')})">
                            <i class="fas fa-info-circle"></i> Детали
                        </button>
                    </div>
                </div>
            `;
            
            // Платёжеспособность
            html += `
                <div class="metric-card metric-solvency">
                    <div class="metric-icon"><i class="fas fa-shield-alt"></i></div>
                    <div class="metric-content">
                        <div class="metric-label">Платёжеспособность</div>
                        <div class="metric-value ${data.solvency.rating}">${data.solvency.score}/100</div>
                        <div class="metric-description">${data.solvency.description}</div>
                        <button class="metric-details-btn" onclick="showMetricDetails('solvency', ${JSON.stringify(data.solvency).replace(/"/g, '&quot;')})">
                            <i class="fas fa-info-circle"></i> Детали
                        </button>
                    </div>
                </div>
            `;
            
            html += '</div>';
            container.innerHTML = html;
            
            // Сохраняем данные для детализации
            window.economicData = data;
        })
        .catch(error => {
            console.error('Ошибка загрузки экономических расчётов:', error);
            const container = document.getElementById('economicMetrics');
            if (container) {
                container.innerHTML = '<div class="metric-error">Ошибка загрузки расчётов</div>';
            }
        });
}

// Загрузка графика денежных потоков
function loadCashflowChart() {
    fetch('/api/charts.php?action=cashflow_forecast')
        .then(response => response.json())
        .then(data => {
            const ctx = document.getElementById('cashflowChart').getContext('2d');
            new Chart(ctx, {
                type: 'line',
                data: {
                    labels: data.labels,
                    datasets: data.datasets
                },
                options: {
                    responsive: true,
                    maintainAspectRatio: true,
                    scales: {
                        y: {
                            beginAtZero: false,
                            title: {
                                display: true,
                                text: 'Сумма (₽)'
                            }
                        }
                    },
                    plugins: {
                        legend: {
                            display: true
                        },
                        tooltip: {
                            callbacks: {
                                label: function(context) {
                                    return context.dataset.label + ': ' + context.parsed.y.toLocaleString('ru-RU', {style: 'currency', currency: 'RUB', minimumFractionDigits: 0});
                                }
                            }
                        }
                    }
                }
            });
        })
        .catch(error => {
            console.error('Ошибка загрузки прогноза денежных потоков:', error);
            const chartEl = document.getElementById('cashflowChart');
            if (chartEl && chartEl.parentElement) {
                chartEl.parentElement.innerHTML = '<p style="color: red;">Ошибка загрузки данных</p>';
            }
        });
}

// Загрузка графика показателей эффективности
function loadEfficiencyChart() {
    fetch('/api/charts.php?action=efficiency_metrics')
        .then(response => response.json())
        .then(data => {
            const ctx = document.getElementById('efficiencyChart').getContext('2d');
            new Chart(ctx, {
                type: 'bar',
                data: {
                    labels: data.labels,
                    datasets: data.datasets
                },
                options: {
                    responsive: true,
                    maintainAspectRatio: true,
                    scales: {
                        y: {
                            beginAtZero: true,
                            title: {
                                display: true,
                                text: 'Показатель'
                            }
                        }
                    },
                    plugins: {
                        legend: {
                            display: true
                        }
                    }
                }
            });
        })
        .catch(error => {
            console.error('Ошибка загрузки показателей эффективности:', error);
            const chartEl = document.getElementById('efficiencyChart');
            if (chartEl && chartEl.parentElement) {
                chartEl.parentElement.innerHTML = '<p style="color: red;">Ошибка загрузки данных</p>';
            }
        });
}

// Показ детализации расчёта
function showMetricDetails(metricType, metricData) {
    // Загружаем детальные данные
    fetch(`/api/charts.php?action=metric_details&type=${metricType}`)
        .then(response => response.json())
        .then(details => {
            showDetailsModal(metricType, metricData, details);
        })
        .catch(error => {
            console.error('Ошибка загрузки деталей:', error);
            // Показываем модальное окно с базовыми данными
            showDetailsModal(metricType, metricData, null);
        });
}

// Показ детализации графика
function showChartDetails(chartType) {
    // Загружаем детальные данные
    fetch(`/api/charts.php?action=chart_details&type=${chartType}`)
        .then(response => response.json())
        .then(details => {
            showDetailsModal(chartType, null, details);
        })
        .catch(error => {
            console.error('Ошибка загрузки деталей графика:', error);
            showDetailsModal(chartType, null, null);
        });
}

// Показ модального окна с деталями
function showDetailsModal(metricType, metricData, details) {
    const modal = document.getElementById('metricDetailsModal');
    const modalTitle = document.getElementById('metricDetailsTitle');
    const modalBody = document.getElementById('metricDetailsBody');
    
    if (!modal || !modalTitle || !modalBody) {
        console.error('Модальное окно не найдено');
        return;
    }
    
    const titles = {
        'liquidity': 'Детализация: Ликвидность',
        'balance': 'Детализация: Чистый баланс',
        'turnover': 'Детализация: Оборачиваемость векселей',
        'profitability': 'Детализация: Доходность',
        'risk': 'Детализация: Риск просрочки',
        'solvency': 'Детализация: Платёжеспособность',
        'cashflow': 'Как считается: Прогноз денежных потоков',
        'efficiency': 'Как считается: Показатели эффективности',
        'rating': 'Как считается: Изменение рейтинга "Око"',
        'bills_balance': 'Как считается: Баланс векселей',
        'payment_dynamics': 'Как считается: Динамика погашения векселей',
        'payment_pie': 'Как считается: Своевременность погашения',
        'transactions': 'Как считается: Статистика транзакций',
        'categories': 'Как считается: Распределение транзакций по категориям'
    };
    
    modalTitle.textContent = titles[metricType] || 'Детализация расчёта';
    
    let html = '';
    
    if (details && details.details) {
        html = details.details;
    } else if (metricType === 'cashflow' || metricType === 'efficiency') {
        // Для графиков показываем базовую информацию
        html = `
            <div class="metric-detail-section">
                <h4>Описание расчёта</h4>
                <p>Детальная информация о расчёте будет загружена...</p>
            </div>
        `;
    } else if (metricData) {
        // Базовая информация для метрик
        html = `
            <div class="metric-detail-section">
                <h4>Текущее значение</h4>
                <div class="detail-value ${metricData.rating}">
                    ${metricType === 'balance' 
                        ? metricData.value.toLocaleString('ru-RU', {style: 'currency', currency: 'RUB', minimumFractionDigits: 0})
                        : metricType === 'profitability' || metricType === 'risk'
                        ? (metricData.ratio * 100).toFixed(2) + '%'
                        : metricType === 'solvency'
                        ? metricData.score + '/100'
                        : metricData.ratio.toFixed(2)}
                </div>
            </div>
            <div class="metric-detail-section">
                <h4>Описание</h4>
                <p>${metricData.description}</p>
            </div>
        `;
    }
    
    modalBody.innerHTML = html;
    modal.style.display = 'flex';
    document.body.style.overflow = 'hidden';
}

// Закрытие модального окна
function closeMetricDetails() {
    const modal = document.getElementById('metricDetailsModal');
    if (modal) {
        modal.style.display = 'none';
        document.body.style.overflow = '';
    }
}

// Инициализация загрузки при открытии страницы (только графики)
window.calculationsLoaded = false;

// Закрытие модального окна по клику вне области
document.addEventListener('DOMContentLoaded', function() {
    const modal = document.getElementById('metricDetailsModal');
    if (modal) {
        modal.addEventListener('click', function(e) {
            if (e.target === modal) {
                closeMetricDetails();
            }
        });
        
        // Закрытие по ESC
        document.addEventListener('keydown', function(e) {
            if (e.key === 'Escape' && modal.style.display === 'flex') {
                closeMetricDetails();
            }
        });
    }
});

</script>

<style>
.economic-calculations {
    padding: 24px;
}

.economic-calculations h3 {
    margin-bottom: 20px;
    display: flex;
    align-items: center;
    gap: 10px;
    color: var(--text-primary);
}

.metrics-loading {
    text-align: center;
    padding: 40px;
    color: var(--text-secondary);
}

.metrics-loading i {
    font-size: 24px;
    margin-bottom: 10px;
}

.metrics-grid {
    display: grid;
    grid-template-columns: repeat(6, 1fr);
    gap: 16px;
}

.metric-card {
    background: var(--bg-secondary);
    border-radius: var(--radius-lg);
    padding: 16px;
    border: 1px solid var(--border-color);
    display: flex;
    flex-direction: column;
    align-items: center;
    text-align: center;
    gap: 12px;
    transition: all var(--transition-base);
    min-width: 0;
}

.metric-card:hover {
    box-shadow: var(--shadow-md);
    transform: translateY(-2px);
}

.metric-icon {
    width: 40px;
    height: 40px;
    border-radius: var(--radius-md);
    background: var(--gradient-primary);
    color: white;
    display: flex;
    align-items: center;
    justify-content: center;
    font-size: 20px;
    flex-shrink: 0;
}

.metric-content {
    flex: 1;
    min-width: 0;
    width: 100%;
    display: flex;
    flex-direction: column;
    align-items: center;
}

.metric-label {
    font-size: 0.75em;
    color: var(--text-secondary);
    margin-bottom: 6px;
    font-weight: 500;
}

.metric-value {
    font-size: 1.35em;
    font-weight: 700;
    margin-bottom: 6px;
    line-height: 1.2;
}

.metric-value.good {
    color: #10b981;
}

.metric-value.warning {
    color: #f59e0b;
}

.metric-value.danger {
    color: #ef4444;
}

.metric-description {
    font-size: 0.7em;
    color: var(--text-secondary);
    line-height: 1.3;
    margin-bottom: 8px;
}

.metric-error {
    text-align: center;
    padding: 20px;
    color: #ef4444;
    background: #fee2e2;
    border-radius: var(--radius-md);
}

/* Стили для табов */
.charts-tabs {
    display: flex;
    gap: 12px;
    margin-bottom: 24px;
    border-bottom: 2px solid var(--border-color);
    padding-bottom: 0;
}

.chart-tab-btn {
    display: flex;
    align-items: center;
    gap: 8px;
    padding: 12px 24px;
    background: transparent;
    border: none;
    border-bottom: 3px solid transparent;
    color: var(--text-secondary);
    font-size: 1em;
    font-weight: 500;
    cursor: pointer;
    transition: all var(--transition-base);
    margin-bottom: -2px;
}

.chart-tab-btn:hover {
    color: var(--text-primary);
    background: var(--bg-secondary);
}

.chart-tab-btn.active {
    color: var(--color-primary);
    border-bottom-color: var(--color-primary);
    background: transparent;
}

.chart-tab-btn i {
    font-size: 1.1em;
}

/* Стили для содержимого табов */
.tab-content {
    display: none;
}

.tab-content.active {
    display: block;
}

/* Блок экономических расчётов - горизонтальный */
.economic-calculations {
    width: 100%;
    margin-bottom: 24px;
}

/* Контейнер для графиков */
.calculations-charts-container {
    display: grid;
    grid-template-columns: repeat(2, 1fr);
    gap: 20px;
    margin-top: 24px;
}

/* Заголовок графика с кнопкой */
.chart-header {
    display: flex;
    justify-content: space-between;
    align-items: center;
    margin-bottom: 16px;
}

.chart-header h3 {
    margin: 0;
}

/* Кнопка детализации графика */
.chart-details-btn {
    padding: 6px 12px;
    background: var(--bg-secondary);
    border: 1px solid var(--border-color);
    border-radius: var(--radius-md);
    color: var(--text-primary);
    font-size: 0.8em;
    cursor: pointer;
    transition: all var(--transition-base);
    display: inline-flex;
    align-items: center;
    gap: 6px;
}

.chart-details-btn:hover {
    background: var(--color-primary);
    color: white;
    border-color: var(--color-primary);
    transform: translateY(-1px);
    box-shadow: var(--shadow-sm);
}

/* Стили для кнопки детализации */
.metric-details-btn {
    margin-top: 12px;
    padding: 8px 16px;
    background: var(--bg-primary);
    border: 1px solid var(--border-color);
    border-radius: var(--radius-md);
    color: var(--text-primary);
    font-size: 0.875em;
    cursor: pointer;
    transition: all var(--transition-base);
    display: inline-flex;
    align-items: center;
    gap: 6px;
}

.metric-details-btn:hover {
    background: var(--color-primary);
    color: white;
    border-color: var(--color-primary);
    transform: translateY(-1px);
    box-shadow: var(--shadow-sm);
}

/* Стили для модального окна детализации */
.modal-medium {
    max-width: 700px;
}

.metric-detail-section {
    margin-bottom: 24px;
    padding-bottom: 20px;
    border-bottom: 1px solid var(--border-color-light);
}

.metric-detail-section:last-child {
    border-bottom: none;
    margin-bottom: 0;
}

.metric-detail-section h4 {
    margin: 0 0 12px 0;
    font-size: 1.1em;
    color: var(--text-primary);
    font-weight: 600;
}

.detail-value {
    font-size: 2em;
    font-weight: 700;
    margin: 12px 0;
}

.detail-value.good {
    color: #10b981;
}

.detail-value.warning {
    color: #f59e0b;
}

.detail-value.danger {
    color: #ef4444;
}

.metric-detail-section p {
    margin: 0;
    line-height: 1.6;
    color: var(--text-secondary);
}

.metric-detail-section ul {
    margin: 0;
    padding-left: 20px;
    line-height: 1.8;
}

.metric-detail-table {
    width: 100%;
    border-collapse: collapse;
    margin-top: 12px;
}

.metric-detail-table th,
.metric-detail-table td {
    padding: 10px;
    text-align: left;
    border-bottom: 1px solid var(--border-color-light);
}

.metric-detail-table th {
    font-weight: 600;
    color: var(--text-primary);
    background: var(--bg-secondary);
}

.metric-detail-table td {
    color: var(--text-secondary);
}

.metric-detail-table tr:last-child td {
    border-bottom: none;
}

@media (max-width: 768px) {
    .metrics-grid {
        grid-template-columns: 1fr;
    }
    
    .charts-tabs {
        flex-direction: column;
        gap: 0;
    }
    
    .chart-tab-btn {
        width: 100%;
        justify-content: center;
    }
    
    .calculations-charts-container {
        grid-template-columns: 1fr;
    }
}

@media (max-width: 1400px) {
    .metrics-grid {
        grid-template-columns: repeat(3, 1fr);
    }
}

@media (max-width: 1024px) {
    .metrics-grid {
        grid-template-columns: repeat(2, 1fr);
    }
    
    .calculations-charts-container {
        grid-template-columns: 1fr;
    }
}
</style>
<?php
$content = ob_get_clean();
include __DIR__ . '/../templates/base.php';

