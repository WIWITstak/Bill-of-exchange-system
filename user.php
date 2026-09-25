<?php
/**
 * Страница публичного профиля пользователя
 */

require_once __DIR__ . '/../src/bootstrap.php';

use OGAS\Services\Auth;
use OGAS\Models\User;
use OGAS\Models\Company;
use OGAS\Models\Rating;
use OGAS\Models\Transaction;
use OGAS\Services\BillService;
use OGAS\Services\TransactionService;

// Получаем ID пользователя
$userId = isset($_GET['id']) ? (int)$_GET['id'] : 0;

if ($userId <= 0) {
    header('Location: /users.php');
    exit;
}

// Получаем пользователя
$viewUser = User::findById($userId);

if (!$viewUser) {
    header('Location: /users.php?error=user_not_found');
    exit;
}

// Проверяем, является ли это профиль текущего пользователя
$currentUser = Auth::user();
$isOwnProfile = $currentUser && $currentUser->getId() === $viewUser->getId();

// Получаем публичную статистику
$rating = Rating::findByUserId($viewUser->getId());
$billStats = BillService::getStatistics($viewUser->getId());
$transactionStats = TransactionService::getStatistics($viewUser->getId());

// Публичная история транзакций (только завершённые)
$publicTransactions = Transaction::findByUser($viewUser->getId(), 'completed');
// Ограничиваем количество для публичного просмотра
$publicTransactions = array_slice($publicTransactions, 0, 10);

// Получаем информацию о предприятии (для юрлиц)
$company = null;
if ($viewUser->getUserType() === 'legal') {
    $company = Company::findByUserId($viewUser->getId());
}

$title = 'Профиль: ' . htmlspecialchars($viewUser->getFullName());
ob_start();
?>
<div class="dashboard">
    <div class="dashboard-header">
        <h2>Профиль пользователя</h2>
        <div class="header-actions">
            <?php if ($isOwnProfile): ?>
                <a href="/dashboard.php" class="btn btn-secondary">← Мой кабинет</a>
            <?php else: ?>
                <a href="/users.php" class="btn btn-secondary">← Поиск пользователей</a>
            <?php endif; ?>
        </div>
    </div>
    
    <div class="profile-public">
        <!-- Основная информация -->
        <div class="profile-header-card">
            <div class="profile-avatar-section">
                <div class="profile-avatar-large">
                    <?= $viewUser->getAvatarHtml('profile') ?>
                </div>
                <div class="profile-main-info">
                    <h2 class="profile-name"><?= htmlspecialchars($viewUser->getFullName()) ?></h2>
                    <div class="profile-meta">
                        <span class="profile-type-badge <?= $viewUser->getUserType() === 'legal' ? 'profile-type-legal' : 'profile-type-individual' ?>">
                            <?= $viewUser->getUserType() === 'legal' ? 'Юридическое лицо' : 'Физическое лицо' ?>
                        </span>
                        <?php if ($isOwnProfile): ?>
                            <?php if ($viewUser->isActive()): ?>
                                <span class="status-badge status-active">Активирован</span>
                            <?php else: ?>
                                <span class="status-badge status-inactive">Не активирован</span>
                            <?php endif; ?>
                        <?php endif; ?>
                    </div>
                </div>
            </div>
            
            <?php if ($isOwnProfile): ?>
                <div class="profile-actions">
                    <a href="/profile/edit.php" class="btn-profile-action">Редактировать</a>
                    <a href="/transactions/create.php" class="btn-profile-action btn-primary">Создать транзакцию</a>
                </div>
            <?php else: ?>
                <div class="profile-actions">
                    <a href="/transactions/create.php?buyer_id=<?= $viewUser->getId() ?>" class="btn-profile-action btn-primary">Создать транзакцию</a>
                </div>
            <?php endif; ?>
        </div>
        
        <div class="profile-content-grid">
            <!-- Основная информация -->
            <div class="info-card profile-info-card">
                <h3>📋 Информация</h3>
            
                <?php if ($isOwnProfile): ?>
                    <div class="info-row">
                        <span class="info-label">Email:</span>
                        <span class="info-value"><?= htmlspecialchars($viewUser->getEmail()) ?></span>
                    </div>
                <?php else: ?>
                    <div class="info-row">
                        <span class="info-label">Email:</span>
                        <span class="info-value text-muted">Скрыт</span>
                    </div>
                <?php endif; ?>
                
                <div class="info-row">
                    <span class="info-label">Дата регистрации:</span>
                    <span class="info-value">
                        <?= $viewUser->getCreatedAt() ? date('d.m.Y', strtotime($viewUser->getCreatedAt())) : 'Не указана' ?>
                    </span>
                </div>
            
                <?php if ($viewUser->getUserType() === 'legal' && $company): ?>
                    <div class="info-divider"></div>
                    <h4 style="margin: 15px 0 10px 0; color: #667eea;">🏢 Информация о предприятии</h4>
                    <div class="info-row">
                        <span class="info-label">Название организации:</span>
                        <span class="info-value"><?= htmlspecialchars($company->getName()) ?></span>
                    </div>
                    <?php if ($company->getAddress()): ?>
                        <div class="info-row">
                            <span class="info-label">Юридический адрес:</span>
                            <span class="info-value"><?= htmlspecialchars($company->getAddress()) ?></span>
                        </div>
                    <?php else: ?>
                        <div class="info-row">
                            <span class="info-label">Юридический адрес:</span>
                            <span class="info-value text-muted">Не указан</span>
                        </div>
                    <?php endif; ?>
                    <?php if ($company->getOkvedCode()): ?>
                        <div class="info-row">
                            <span class="info-label">Код ОКВЭД:</span>
                            <span class="info-value"><?= htmlspecialchars($company->getOkvedCode()) ?></span>
                        </div>
                    <?php else: ?>
                        <div class="info-row">
                            <span class="info-label">Код ОКВЭД:</span>
                            <span class="info-value text-muted">Не указан</span>
                        </div>
                    <?php endif; ?>
                    <?php if ($company->getEmployeeCount() > 0): ?>
                        <div class="info-row">
                            <span class="info-label">Количество сотрудников:</span>
                            <span class="info-value"><?= $company->getEmployeeCount() ?> чел.</span>
                        </div>
                    <?php else: ?>
                        <div class="info-row">
                            <span class="info-label">Количество сотрудников:</span>
                            <span class="info-value text-muted">Не указано</span>
                        </div>
                    <?php endif; ?>
                <?php elseif ($viewUser->getUserType() === 'individual'): ?>
                    <div class="info-divider"></div>
                    <h4 style="margin: 15px 0 10px 0; color: #667eea;">👤 Персональная информация</h4>
                    <div class="info-row">
                        <span class="info-label">ФИО:</span>
                        <span class="info-value"><?= htmlspecialchars($viewUser->getFullName()) ?></span>
                    </div>
                    <div class="info-row">
                        <span class="info-label">Тип пользователя:</span>
                        <span class="info-value">Физическое лицо</span>
                    </div>
                <?php endif; ?>
            </div>
            
            <!-- Рейтинг "Око" -->
            <div class="info-card profile-rating-card">
                <h3>⭐ Рейтинг "Око"</h3>
                <div class="rating-display">
                    <div class="rating-main-value">
                        <span class="rating-number"><?= number_format($rating->getTotalRating(), 2) ?></span>
                        <span class="rating-unit">баллов</span>
                    </div>
                    <div class="rating-doubles">
                        <span class="doubles-label">Дубли:</span>
                        <span class="doubles-value"><?= number_format($rating->getDoubles(), 2) ?></span>
                    </div>
                </div>
                
                <details class="rating-details-collapsible">
                    <summary>Подробнее о рейтинге</summary>
                    <div class="rating-breakdown">
                        <div class="rating-breakdown-item">
                            <span class="breakdown-label">Балансовый расчёт:</span>
                            <span class="breakdown-value"><?= number_format($rating->getBalanceScore(), 2) ?></span>
                        </div>
                        <div class="rating-breakdown-item">
                            <span class="breakdown-label">Дисциплина погашаемости:</span>
                            <span class="breakdown-value"><?= number_format($rating->getPaymentDiscipline(), 2) ?>%</span>
                        </div>
                        <div class="rating-breakdown-item">
                            <span class="breakdown-label">Досрочное погашение:</span>
                            <span class="breakdown-value"><?= number_format($rating->getEarlyPaymentAvg(), 2) ?></span>
                        </div>
                    </div>
                </details>
                
                <?php if ($isOwnProfile): ?>
                    <div style="margin-top: 15px;">
                        <a href="/rating.php" class="btn btn-primary">Подробный рейтинг</a>
                    </div>
                <?php endif; ?>
            </div>
        
            <!-- Статистика по векселям -->
            <div class="info-card profile-stats-card">
                <h3>💰 Вексели</h3>
                <div class="stats-grid-mini">
                    <div class="stat-item-mini">
                        <span class="stat-label-mini">Выпущено</span>
                        <span class="stat-value-mini"><?= $billStats['issued_count'] ?></span>
                        <?php if ($isOwnProfile): ?>
                            <span class="stat-amount-mini"><?= number_format($billStats['issued_total'], 0, '.', ' ') ?> ₽</span>
                        <?php endif; ?>
                    </div>
                    <div class="stat-item-mini">
                        <span class="stat-label-mini">Получено</span>
                        <span class="stat-value-mini"><?= $billStats['held_count'] ?></span>
                        <?php if ($isOwnProfile): ?>
                            <span class="stat-amount-mini"><?= number_format($billStats['held_total'], 0, '.', ' ') ?> ₽</span>
                        <?php endif; ?>
                    </div>
                </div>
                <?php if ($isOwnProfile): ?>
                    <div style="margin-top: 15px;">
                        <a href="/bills.php" class="btn btn-primary">Управление векселями</a>
                    </div>
                <?php else: ?>
                    <p class="text-muted" style="margin-top: 10px; font-size: 0.85em;">Детальная информация доступна только владельцу</p>
                <?php endif; ?>
            </div>
            
            <!-- Статистика по транзакциям -->
            <div class="info-card profile-stats-card">
                <h3>📊 Транзакции</h3>
                <div class="stats-grid-mini">
                    <div class="stat-item-mini">
                        <span class="stat-label-mini">Всего</span>
                        <span class="stat-value-mini"><?= $transactionStats['total'] ?></span>
                    </div>
                    <div class="stat-item-mini">
                        <span class="stat-label-mini">Завершённых</span>
                        <span class="stat-value-mini"><?= $transactionStats['completed'] ?></span>
                    </div>
                    <div class="stat-item-mini">
                        <span class="stat-label-mini">Активных</span>
                        <span class="stat-value-mini"><?= $transactionStats['active'] ?></span>
                    </div>
                </div>
                <?php if ($isOwnProfile): ?>
                    <div class="stats-grid-mini" style="margin-top: 10px;">
                        <div class="stat-item-mini">
                            <span class="stat-label-mini">Как продавец</span>
                            <span class="stat-value-mini"><?= $transactionStats['as_seller'] ?></span>
                        </div>
                        <div class="stat-item-mini">
                            <span class="stat-label-mini">Как покупатель</span>
                            <span class="stat-value-mini"><?= $transactionStats['as_buyer'] ?></span>
                        </div>
                    </div>
                    <div style="margin-top: 15px;">
                        <a href="/transactions.php" class="btn btn-primary">Все транзакции</a>
                    </div>
                <?php endif; ?>
            </div>
        </div>
        
        <!-- Графики -->
        <div class="profile-charts-section">
            <h3 class="section-title-with-icon">📈 Графики и статистика</h3>
            <div class="charts-carousel">
                <button class="carousel-btn carousel-btn-prev" onclick="profileChartsCarousel.prev()">‹</button>
                <button class="carousel-btn carousel-btn-next" onclick="profileChartsCarousel.next()">›</button>
                
                <div class="charts-carousel-track">
                    <!-- График изменения рейтинга -->
                    <div class="carousel-slide active">
                        <div class="info-card chart-card">
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
                        <div class="info-card chart-card">
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
                        <div class="info-card chart-card">
                            <h3>Динамика погашения векселей</h3>
                            <canvas id="paymentChart" width="400" height="200"></canvas>
                        </div>
                    </div>
                    
                    <!-- Круговая диаграмма своевременности погашения -->
                    <div class="carousel-slide">
                        <div class="info-card chart-card">
                            <h3>Своевременность погашения</h3>
                            <canvas id="paymentPieChart" width="400" height="200"></canvas>
                        </div>
                    </div>
                    
                    <!-- График статистики транзакций -->
                    <div class="carousel-slide">
                        <div class="info-card chart-card">
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
                        <div class="info-card chart-card">
                            <h3>Распределение транзакций по категориям</h3>
                            <canvas id="categoriesChart" width="400" height="200"></canvas>
                        </div>
                    </div>
                </div>
                
                <!-- Индикаторы точек -->
                <div class="carousel-indicators">
                    <span class="carousel-indicator active" onclick="profileChartsCarousel.goTo(0)"></span>
                    <span class="carousel-indicator" onclick="profileChartsCarousel.goTo(1)"></span>
                    <span class="carousel-indicator" onclick="profileChartsCarousel.goTo(2)"></span>
                    <span class="carousel-indicator" onclick="profileChartsCarousel.goTo(3)"></span>
                    <span class="carousel-indicator" onclick="profileChartsCarousel.goTo(4)"></span>
                    <span class="carousel-indicator" onclick="profileChartsCarousel.goTo(5)"></span>
                </div>
            </div>
        </div>
        
        <!-- Публичная история транзакций -->
        <?php if (!empty($publicTransactions)): ?>
        <div class="info-card">
            <h3>Публичная история транзакций</h3>
            <p class="text-muted"><small>Показаны последние 10 завершённых транзакций</small></p>
            
            <table class="transactions-table" style="width: 100%; margin-top: 15px;">
                <thead>
                    <tr>
                        <th>Дата</th>
                        <th>Роль</th>
                        <th>Контрагент</th>
                        <th>Описание</th>
                    </tr>
                </thead>
                <tbody>
                    <?php foreach ($publicTransactions as $transaction): ?>
                        <?php
                        $seller = User::findById($transaction->getSellerId());
                        $buyer = User::findById($transaction->getBuyerId());
                        $isSeller = $transaction->getSellerId() === $viewUser->getId();
                        $counterparty = $isSeller ? $buyer : $seller;
                        ?>
                        <tr>
                            <td><?= $transaction->getCreatedAt() ? date('d.m.Y', strtotime($transaction->getCreatedAt())) : '-' ?></td>
                            <td><?= $isSeller ? 'Продавец' : 'Покупатель' ?></td>
                            <td>
                                <?php if ($isOwnProfile || $transaction->getStatus() === 'completed'): ?>
                                    <a href="/user.php?id=<?= $counterparty->getId() ?>">
                                        <?= htmlspecialchars($counterparty->getFullName()) ?>
                                    </a>
                                <?php else: ?>
                                    <?= htmlspecialchars($counterparty->getFullName()) ?>
                                <?php endif; ?>
                            </td>
                            <td><?= htmlspecialchars(mb_substr($transaction->getDescription() ?? 'Без описания', 0, 50)) ?><?= mb_strlen($transaction->getDescription() ?? '') > 50 ? '...' : '' ?></td>
                        </tr>
                    <?php endforeach; ?>
                </tbody>
            </table>
            
            <?php if ($isOwnProfile && $transactionStats['total'] > 10): ?>
                <p style="margin-top: 15px;">
                    <a href="/transactions.php" class="btn btn-primary">Просмотреть все транзакции</a>
                </p>
            <?php endif; ?>
        </div>
        <?php endif; ?>
        
    </div>
</div>
<!-- Chart.js -->
<script src="https://cdn.jsdelivr.net/npm/chart.js@4.4.0/dist/chart.umd.min.js"></script>

<script>
const userId = <?= $viewUser->getId() ?>;

// График изменения рейтинга
fetch('/api/charts.php?action=rating&user_id=' + userId)
    .then(response => response.json())
    .then(data => {
        if (data.error) {
            console.error('Ошибка:', data.error);
            return;
        }
        const ctx = document.getElementById('ratingChart')?.getContext('2d');
        if (!ctx) return;
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
        const chartEl = document.getElementById('ratingChart');
        if (chartEl) {
            chartEl.parentElement.innerHTML = '<p style="color: red; padding: 20px;">Ошибка загрузки данных</p>';
        }
    });

// График баланса векселей
fetch('/api/charts.php?action=bills_balance&user_id=' + userId)
    .then(response => response.json())
    .then(data => {
        if (data.error) {
            console.error('Ошибка:', data.error);
            return;
        }
        const ctx = document.getElementById('billsChart')?.getContext('2d');
        if (!ctx) return;
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
        const chartEl = document.getElementById('billsChart');
        if (chartEl) {
            chartEl.parentElement.innerHTML = '<p style="color: red; padding: 20px;">Ошибка загрузки данных</p>';
        }
    });

// График динамики погашения
fetch('/api/charts.php?action=payment_dynamics&user_id=' + userId)
    .then(response => response.json())
    .then(data => {
        if (data.error) {
            console.error('Ошибка:', data.error);
            return;
        }
        // Линейный график
        const ctx = document.getElementById('paymentChart')?.getContext('2d');
        if (!ctx) return;
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
            const pieCtx = document.getElementById('paymentPieChart')?.getContext('2d');
            if (pieCtx) {
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
        }
    })
    .catch(error => {
        console.error('Ошибка загрузки данных погашения:', error);
        const chartEl = document.getElementById('paymentChart');
        if (chartEl) {
            chartEl.parentElement.innerHTML = '<p style="color: red; padding: 20px;">Ошибка загрузки данных</p>';
        }
    });

// График статистики транзакций
fetch('/api/charts.php?action=transactions&user_id=' + userId)
    .then(response => response.json())
    .then(data => {
        if (data.error) {
            console.error('Ошибка:', data.error);
            return;
        }
        const ctx = document.getElementById('transactionsChart')?.getContext('2d');
        if (!ctx) return;
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
        const chartEl = document.getElementById('transactionsChart');
        if (chartEl) {
            chartEl.parentElement.innerHTML = '<p style="color: red; padding: 20px;">Ошибка загрузки данных</p>';
        }
    });

// График распределения по категориям
fetch('/api/charts.php?action=categories_distribution&user_id=' + userId)
    .then(response => response.json())
    .then(data => {
        if (data.error) {
            console.error('Ошибка:', data.error);
            return;
        }
        const ctx = document.getElementById('categoriesChart')?.getContext('2d');
        if (!ctx) return;
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
        const chartEl = document.getElementById('categoriesChart');
        if (chartEl) {
            chartEl.parentElement.innerHTML = '<p style="color: red; padding: 20px;">Ошибка загрузки данных</p>';
        }
    });

// Карусель графиков для профиля
const profileChartsCarousel = {
    currentIndex: 0,
    slides: document.querySelectorAll('.profile-charts-section .carousel-slide'),
    indicators: document.querySelectorAll('.profile-charts-section .carousel-indicator'),
    track: document.querySelector('.profile-charts-section .charts-carousel-track'),
    
    init: function() {
        if (this.slides.length > 0) {
            this.update();
        }
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
        this.slides.forEach((slide, index) => {
            slide.classList.toggle('active', index === this.currentIndex);
        });
        
        this.indicators.forEach((indicator, index) => {
            indicator.classList.toggle('active', index === this.currentIndex);
        });
        
        if (this.track) {
            this.track.style.transform = `translateX(-${this.currentIndex * 100}%)`;
        }
    }
};

document.addEventListener('DOMContentLoaded', function() {
    profileChartsCarousel.init();
    
    document.addEventListener('keydown', function(e) {
        if (e.key === 'ArrowLeft') {
            profileChartsCarousel.prev();
        } else if (e.key === 'ArrowRight') {
            profileChartsCarousel.next();
        }
    });
});
</script>
<?php
$content = ob_get_clean();
include __DIR__ . '/../templates/base.php';


