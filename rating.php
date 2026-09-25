<?php
/**
 * Страница рейтинговой системы "Око"
 */

require_once __DIR__ . '/../src/bootstrap.php';

use OGAS\Services\Auth;
use OGAS\Models\Rating;
use OGAS\Models\User;
use OGAS\Services\BillService;
use OGAS\Services\TransactionService;

Auth::requireAuth();
$user = Auth::user();

// Получаем или создаём рейтинг
$rating = Rating::findByUserId($user->getId());
$rating->recalculate(); // Пересчитываем при каждом открытии

// Получаем статистику для графиков
$billStats = BillService::getStatistics($user->getId());
$transactionStats = TransactionService::getStatistics($user->getId());

$title = 'Рейтинговая система "Око"';
ob_start();
?>
<div class="dashboard">
    <div class="dashboard-header">
        <h2>Рейтинговая система "Око"</h2>
        <a href="/dashboard.php" class="btn btn-secondary">← Назад</a>
    </div>
    
    <div class="rating-container">
        <!-- Главные карточки рейтинга -->
        <div class="rating-main-grid">
            <div class="rating-card-modern rating-card-primary">
                <div class="rating-card-icon">⭐</div>
                <div class="rating-card-content">
                    <div class="rating-card-label">Ваш рейтинг "Око"</div>
                    <div class="rating-value-large"><?= number_format($rating->getTotalRating(), 2) ?></div>
                    <div class="rating-card-sublabel">баллов</div>
                </div>
                <div class="rating-card-trend">
                    <span class="trend-indicator">📈</span>
                    <span class="trend-text">Репутация</span>
                </div>
            </div>
            
            <div class="rating-card-modern rating-card-secondary">
                <div class="rating-card-icon">💰</div>
                <div class="rating-card-content">
                    <div class="rating-card-label">Дубли</div>
                    <div class="rating-value-large"><?= number_format($rating->getDoubles(), 2) ?></div>
                    <div class="rating-card-sublabel">репутационный капитал</div>
                </div>
                <div class="rating-card-trend">
                    <span class="trend-indicator">💎</span>
                    <span class="trend-text">Стоимость</span>
                </div>
            </div>
        </div>
        
        <!-- Компоненты рейтинга -->
        <div class="rating-components-section">
            <h3 class="section-title">📊 Компоненты рейтинга</h3>
            
            <div class="components-grid">
                <div class="component-card">
                    <div class="component-header">
                        <div class="component-icon">⚖️</div>
                        <div class="component-info">
                            <div class="component-name">Балансовый расчёт</div>
                            <div class="component-value"><?= number_format($rating->getBalanceScore(), 2) ?> баллов</div>
                        </div>
                    </div>
                    <div class="progress-bar-wrapper">
                        <div class="progress-bar">
                            <div class="progress-fill" style="width: <?= min(100, ($rating->getBalanceScore() / 200) * 100) ?>%"></div>
                        </div>
                        <div class="progress-label"><?= number_format(($rating->getBalanceScore() / 200) * 100, 1) ?>%</div>
                    </div>
                    <p class="component-description">
                        Отношение суммы векселей на счету к сумме обязательств
                    </p>
                    <button class="component-details-btn" onclick="showComponentDetails('balance_score')">
                        <i class="fas fa-info-circle"></i> Как считается
                    </button>
                </div>
                
                <div class="component-card">
                    <div class="component-header">
                        <div class="component-icon">✅</div>
                        <div class="component-info">
                            <div class="component-name">Дисциплина погашаемости</div>
                            <div class="component-value"><?= number_format($rating->getPaymentDiscipline(), 2) ?>%</div>
                        </div>
                    </div>
                    <div class="progress-bar-wrapper">
                        <div class="progress-bar">
                            <div class="progress-fill" style="width: <?= min(100, $rating->getPaymentDiscipline()) ?>%"></div>
                        </div>
                        <div class="progress-label"><?= number_format($rating->getPaymentDiscipline(), 1) ?>%</div>
                    </div>
                    <p class="component-description">
                        Процент погашенных векселей от общего количества обязательств
                    </p>
                    <button class="component-details-btn" onclick="showComponentDetails('payment_discipline')">
                        <i class="fas fa-info-circle"></i> Как считается
                    </button>
                </div>
                
                <div class="component-card">
                    <div class="component-header">
                        <div class="component-icon">⚡</div>
                        <div class="component-info">
                            <div class="component-name">Досрочное погашение</div>
                            <div class="component-value"><?= number_format($rating->getEarlyPaymentAvg(), 2) ?></div>
                        </div>
                    </div>
                    <div class="progress-bar-wrapper">
                        <div class="progress-bar">
                            <div class="progress-fill" style="width: <?= min(100, ($rating->getEarlyPaymentAvg() / 2) * 100) ?>%"></div>
                        </div>
                        <div class="progress-label">Коэффициент: <?= number_format($rating->getEarlyPaymentAvg(), 2) ?></div>
                    </div>
                    <p class="component-description">
                        Коэффициент скорости погашения обязательств (чем больше, тем лучше)
                    </p>
                    <button class="component-details-btn" onclick="showComponentDetails('early_payment')">
                        <i class="fas fa-info-circle"></i> Как считается
                    </button>
                </div>
            </div>
        </div>
        
        <!-- Формулы расчёта -->
        <div class="rating-formulas-section">
            <h3 class="section-title">🧮 Формулы расчёта</h3>
            
            <div class="formulas-grid">
                <div class="formula-card">
                    <div class="formula-header">
                        <span class="formula-icon">🔢</span>
                        <span class="formula-title">Формула рейтинга</span>
                    </div>
                    <div class="formula-body">
                        <div class="formula-text">
                            Рейтинг = (Балансовый расчёт / 100) × Дисциплина погашаемости × Досрочное погашение
                        </div>
                        <div class="formula-calculation">
                            <div class="calculation-step">
                                = (<?= number_format($rating->getBalanceScore(), 2) ?> / 100) × <?= number_format($rating->getPaymentDiscipline(), 2) ?> × <?= number_format($rating->getEarlyPaymentAvg(), 2) ?>
                            </div>
                            <div class="calculation-result">
                                = <?= number_format($rating->getTotalRating(), 2) ?> баллов
                            </div>
                        </div>
                    </div>
                    <button class="formula-details-btn" onclick="showFormulaDetails('rating_formula')">
                        <i class="fas fa-info-circle"></i> Подробнее
                    </button>
                </div>
                
                <div class="formula-card">
                    <div class="formula-header">
                        <span class="formula-icon">💎</span>
                        <span class="formula-title">Расчёт дублей</span>
                    </div>
                    <div class="formula-body">
                        <div class="formula-text">
                            Дубли = Сумма векселей на счету × Рейтинг
                        </div>
                        <div class="formula-calculation">
                            <?php 
                            $totalBillsNominal = $rating->getTotalBillsNominal();
                            ?>
                            <div class="calculation-step">
                                = <?= number_format($totalBillsNominal, 2) ?> ₽ × <?= number_format($rating->getTotalRating(), 2) ?>
                            </div>
                            <div class="calculation-result">
                                = <?= number_format($rating->getDoubles(), 2) ?> дублей
                            </div>
                        </div>
                    </div>
                    <button class="formula-details-btn" onclick="showFormulaDetails('doubles_formula')">
                        <i class="fas fa-info-circle"></i> Подробнее
                    </button>
                </div>
            </div>
        </div>
    </div>
    
    <!-- Графики -->
    <div class="charts-carousel-section">
        <h3>📊 Графики и статистика</h3>
        
        <div class="charts-carousel">
            <button class="carousel-btn carousel-btn-prev" onclick="chartsCarousel.prev()">‹</button>
            <button class="carousel-btn carousel-btn-next" onclick="chartsCarousel.next()">›</button>
            
            <div class="charts-carousel-track">
                <!-- График изменения рейтинга -->
                <div class="carousel-slide active">
                    <div class="chart-card">
                        <h3>Изменение рейтинга "Око"</h3>
                        <canvas id="ratingChart" width="400" height="200"></canvas>
                        <p class="chart-info">
                            <strong>Текущий рейтинг:</strong> <?= number_format($rating->getTotalRating(), 2) ?> баллов
                            | <strong>Дубли:</strong> <?= number_format($rating->getDoubles(), 2) ?>
                        </p>
                    </div>
                </div>
                
                <!-- График баланса векселей -->
                <div class="carousel-slide">
                    <div class="chart-card">
                        <h3>Баланс векселей</h3>
                        <canvas id="billsChart" width="400" height="200"></canvas>
                        <p class="chart-info">
                            <strong>Выпущено:</strong> <?= $billStats['issued_count'] ?> (<?= number_format($billStats['issued_total'], 2, '.', ' ') ?> ₽)
                            | <strong>Получено:</strong> <?= $billStats['held_count'] ?> (<?= number_format($billStats['held_total'], 2, '.', ' ') ?> ₽)
                        </p>
                    </div>
                </div>
                
                <!-- График динамики погашения векселей -->
                <div class="carousel-slide">
                    <div class="chart-card">
                        <h3>Динамика погашения векселей</h3>
                        <canvas id="paymentChart" width="400" height="200"></canvas>
                    </div>
                </div>
                
                <!-- Круговая диаграмма своевременности погашения -->
                <div class="carousel-slide">
                    <div class="chart-card">
                        <h3>Своевременность погашения</h3>
                        <canvas id="paymentPieChart" width="400" height="200"></canvas>
                    </div>
                </div>
                
                <!-- График статистики транзакций -->
                <div class="carousel-slide">
                    <div class="chart-card">
                        <h3>Статистика транзакций</h3>
                        <canvas id="transactionsChart" width="400" height="200"></canvas>
                        <p class="chart-info">
                            <strong>Всего:</strong> <?= $transactionStats['total'] ?>
                            | <strong>Завершённых:</strong> <?= $transactionStats['completed'] ?>
                            | <strong>Активных:</strong> <?= $transactionStats['active'] ?>
                        </p>
                    </div>
                </div>
                
                <!-- График распределения транзакций по категориям -->
                <div class="carousel-slide">
                    <div class="chart-card">
                        <h3>Распределение транзакций по категориям</h3>
                        <canvas id="categoriesChart" width="400" height="200"></canvas>
                    </div>
                </div>
            </div>
            
            <!-- Индикаторы точек -->
            <div class="carousel-indicators">
                <span class="carousel-indicator active" onclick="chartsCarousel.goTo(0)"></span>
                <span class="carousel-indicator" onclick="chartsCarousel.goTo(1)"></span>
                <span class="carousel-indicator" onclick="chartsCarousel.goTo(2)"></span>
                <span class="carousel-indicator" onclick="chartsCarousel.goTo(3)"></span>
                <span class="carousel-indicator" onclick="chartsCarousel.goTo(4)"></span>
                <span class="carousel-indicator" onclick="chartsCarousel.goTo(5)"></span>
            </div>
        </div>
    </div>
</div>

<!-- Модальное окно детализации компонента/формулы -->
<div id="ratingDetailsModal" class="modal" style="display: none;">
    <div class="modal-content modal-medium">
        <div class="modal-header">
            <h3 id="ratingDetailsTitle">Детализация расчёта</h3>
            <button type="button" class="modal-close" onclick="closeRatingDetails()">&times;</button>
        </div>
        <div class="modal-body" id="ratingDetailsBody">
            <!-- Детали будут загружены динамически -->
        </div>
    </div>
</div>

<!-- Chart.js -->
<script src="https://cdn.jsdelivr.net/npm/chart.js@4.4.0/dist/chart.umd.min.js"></script>

<script>
const userId = <?= $user->getId() ?>;

// График изменения рейтинга
fetch('/api/charts.php?action=rating&user_id=' + userId)
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
fetch('/api/charts.php?action=bills_balance&user_id=' + userId)
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
fetch('/api/charts.php?action=payment_dynamics&user_id=' + userId)
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
fetch('/api/charts.php?action=transactions&user_id=' + userId)
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
fetch('/api/charts.php?action=categories_distribution&user_id=' + userId)
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

// Карусель графиков
const chartsCarousel = {
    currentIndex: 0,
    slides: document.querySelectorAll('.carousel-slide'),
    indicators: document.querySelectorAll('.carousel-indicator'),
    track: document.querySelector('.charts-carousel-track'),
    
    init: function() {
        this.update();
        
        // Автопрокрутка (опционально)
        // setInterval(() => this.next(), 5000);
    },
    
    goTo: function(index) {
        if (index >= 0 && index < this.slides.length) {
            this.currentIndex = index;
            this.update();
        }
    },
    
    next: function() {
        this.currentIndex = (this.currentIndex + 1) % this.slides.length;
        this.update();
    },
    
    prev: function() {
        this.currentIndex = (this.currentIndex - 1 + this.slides.length) % this.slides.length;
        this.update();
    },
    
    update: function() {
        // Обновляем слайды
        this.slides.forEach((slide, index) => {
            slide.classList.toggle('active', index === this.currentIndex);
        });
        
        // Обновляем индикаторы
        this.indicators.forEach((indicator, index) => {
            indicator.classList.toggle('active', index === this.currentIndex);
        });
        
        // Прокручиваем трек
        if (this.track) {
            this.track.style.transform = `translateX(-${this.currentIndex * 100}%)`;
        }
    }
};

// Показ детализации компонента
function showComponentDetails(componentType) {
    fetch(`/api/charts.php?action=rating_component_details&type=${componentType}`)
        .then(response => response.json())
        .then(details => {
            showRatingDetailsModal(componentType, details);
        })
        .catch(error => {
            console.error('Ошибка загрузки деталей компонента:', error);
            showRatingDetailsModal(componentType, null);
        });
}

// Показ детализации формулы
function showFormulaDetails(formulaType) {
    fetch(`/api/charts.php?action=rating_formula_details&type=${formulaType}`)
        .then(response => response.json())
        .then(details => {
            showRatingDetailsModal(formulaType, details);
        })
        .catch(error => {
            console.error('Ошибка загрузки деталей формулы:', error);
            showRatingDetailsModal(formulaType, null);
        });
}

// Показ модального окна с деталями
function showRatingDetailsModal(type, details) {
    const modal = document.getElementById('ratingDetailsModal');
    const modalTitle = document.getElementById('ratingDetailsTitle');
    const modalBody = document.getElementById('ratingDetailsBody');
    
    if (!modal || !modalTitle || !modalBody) {
        console.error('Модальное окно не найдено');
        return;
    }
    
    const titles = {
        'balance_score': 'Как считается: Балансовый расчёт',
        'payment_discipline': 'Как считается: Дисциплина погашаемости',
        'early_payment': 'Как считается: Досрочное погашение',
        'rating_formula': 'Пояснение: Формула рейтинга',
        'doubles_formula': 'Пояснение: Расчёт дублей'
    };
    
    modalTitle.textContent = titles[type] || 'Детализация расчёта';
    
    let html = '';
    
    if (details && details.details) {
        html = details.details;
    } else {
        html = `
            <div class="metric-detail-section">
                <h4>Описание</h4>
                <p>Детальная информация о расчёте будет загружена...</p>
            </div>
        `;
    }
    
    modalBody.innerHTML = html;
    modal.style.display = 'flex';
    document.body.style.overflow = 'hidden';
}

// Закрытие модального окна
function closeRatingDetails() {
    const modal = document.getElementById('ratingDetailsModal');
    if (modal) {
        modal.style.display = 'none';
        document.body.style.overflow = '';
    }
}

// Инициализация карусели после загрузки DOM
document.addEventListener('DOMContentLoaded', function() {
    chartsCarousel.init();
    
    // Поддержка клавиатуры
    document.addEventListener('keydown', function(e) {
        if (e.key === 'ArrowLeft') {
            chartsCarousel.prev();
        } else if (e.key === 'ArrowRight') {
            chartsCarousel.next();
        } else if (e.key === 'Escape') {
            closeRatingDetails();
        }
    });
    
    // Закрытие модального окна по клику вне области
    const modal = document.getElementById('ratingDetailsModal');
    if (modal) {
        modal.addEventListener('click', function(e) {
            if (e.target === modal) {
                closeRatingDetails();
            }
        });
    }
});
</script>
<?php
$content = ob_get_clean();
include __DIR__ . '/../templates/base.php';

