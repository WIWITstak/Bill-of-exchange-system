<?php
/**
 * API endpoint для данных графиков
 */

require_once __DIR__ . '/../../src/bootstrap.php';

use OGAS\Services\Auth;
use OGAS\Models\User;
use OGAS\Models\Rating;
use OGAS\Models\Bill;
use OGAS\Models\Transaction;
use OGAS\Services\BillService;
use OGAS\Services\TransactionService;
use OGAS\Core\Security;
use OGAS\Core\RateLimiter;

header('Content-Type: application/json; charset=UTF-8');

// Rate limiting (60 запросов в минуту с одного IP)
$clientIp = Security::getClientIp();
RateLimiter::requireLimit($clientIp, 60, 60);

// Требуем авторизацию
Auth::requireAuth();
$currentUser = Auth::user();

if (!$currentUser) {
    http_response_code(401);
    echo json_encode(['error' => 'Unauthorized']);
    exit;
}

$action = $_GET['action'] ?? '';
// Поддержка просмотра графиков других пользователей
$userId = isset($_GET['user_id']) ? (int)$_GET['user_id'] : $currentUser->getId();

// Проверяем, что пользователь существует
$targetUser = User::findById($userId);
if (!$targetUser) {
    http_response_code(404);
    echo json_encode(['error' => 'User not found']);
    exit;
}

try {
    switch ($action) {
        case 'rating':
            // Данные для графика изменения рейтинга
            $rating = Rating::findByUserId($userId);
            $history = $rating->getHistory(90); // За последние 90 дней
            
            $labels = [];
            $ratings = [];
            $doubles = [];
            
            foreach ($history as $entry) {
                $labels[] = date('d.m.Y', strtotime($entry->getCreatedAt()));
                $ratings[] = round($entry->getTotalRating(), 2);
                $doubles[] = round($entry->getDoubles(), 2);
            }
            
            // Если нет истории, добавляем текущий рейтинг
            if (empty($history)) {
                $labels[] = date('d.m.Y');
                $ratings[] = round($rating->getTotalRating(), 2);
                $doubles[] = round($rating->getDoubles(), 2);
            }
            
            echo json_encode([
                'labels' => $labels,
                'datasets' => [
                    [
                        'label' => 'Рейтинг "Око"',
                        'data' => $ratings,
                        'borderColor' => 'rgb(75, 192, 192)',
                        'backgroundColor' => 'rgba(75, 192, 192, 0.2)',
                        'tension' => 0.1
                    ],
                    [
                        'label' => 'Дубли',
                        'data' => $doubles,
                        'borderColor' => 'rgb(255, 99, 132)',
                        'backgroundColor' => 'rgba(255, 99, 132, 0.2)',
                        'tension' => 0.1,
                        'yAxisID' => 'y1'
                    ]
                ]
            ]);
            break;
            
        case 'bills_balance':
            // Данные для графика баланса векселей
            $bills = Bill::findByIssuer($userId);
            $heldBills = Bill::findByHolder($userId);
            
            // Группируем по датам (последние 90 дней)
            $dateRanges = [];
            for ($i = 89; $i >= 0; $i--) {
                $date = date('Y-m-d', strtotime("-{$i} days"));
                $dateRanges[$date] = [
                    'issued' => 0,
                    'held' => 0
                ];
            }
            
            // Обрабатываем выпущенные вексели
            foreach ($bills as $bill) {
                $date = date('Y-m-d', strtotime($bill->getIssueDate()));
                if (isset($dateRanges[$date])) {
                    $dateRanges[$date]['issued'] += $bill->getNominal();
                }
            }
            
            // Обрабатываем полученные вексели
            foreach ($heldBills as $bill) {
                $date = date('Y-m-d', strtotime($bill->getIssueDate()));
                if (isset($dateRanges[$date])) {
                    $dateRanges[$date]['held'] += $bill->getNominal();
                }
            }
            
            // Накапливаем значения
            $cumulativeIssued = 0;
            $cumulativeHeld = 0;
            $labels = [];
            $issuedData = [];
            $heldData = [];
            
            foreach ($dateRanges as $date => $values) {
                $cumulativeIssued += $values['issued'];
                $cumulativeHeld += $values['held'];
                
                // Показываем только даты с шагом (каждые 7 дней или при изменениях)
                if (date('w', strtotime($date)) === '0' || $values['issued'] > 0 || $values['held'] > 0) {
                    $labels[] = date('d.m.Y', strtotime($date));
                    $issuedData[] = round($cumulativeIssued, 2);
                    $heldData[] = round($cumulativeHeld, 2);
                }
            }
            
            // Если нет данных, добавляем текущие значения
            if (empty($labels)) {
                $stats = BillService::getStatistics($userId);
                $labels[] = date('d.m.Y');
                $issuedData[] = $stats['issued_total'];
                $heldData[] = $stats['held_total'];
            }
            
            echo json_encode([
                'labels' => $labels,
                'datasets' => [
                    [
                        'label' => 'Выпущенные вексели',
                        'data' => $issuedData,
                        'borderColor' => 'rgb(255, 99, 132)',
                        'backgroundColor' => 'rgba(255, 99, 132, 0.2)',
                        'tension' => 0.1
                    ],
                    [
                        'label' => 'Полученные вексели',
                        'data' => $heldData,
                        'borderColor' => 'rgb(54, 162, 235)',
                        'backgroundColor' => 'rgba(54, 162, 235, 0.2)',
                        'tension' => 0.1
                    ]
                ]
            ]);
            break;
            
        case 'payment_dynamics':
            // Данные для графика динамики погашения векселей
            $bills = Bill::findByIssuer($userId, 'paid');
            
            // Группируем по месяцам
            $monthlyData = [];
            foreach ($bills as $bill) {
                if ($bill->getPaymentDate()) {
                    $month = date('Y-m', strtotime($bill->getPaymentDate()));
                    if (!isset($monthlyData[$month])) {
                        $monthlyData[$month] = [
                            'count' => 0,
                            'total' => 0,
                            'on_time' => 0,
                            'early' => 0,
                            'late' => 0
                        ];
                    }
                    
                    $monthlyData[$month]['count']++;
                    $monthlyData[$month]['total'] += $bill->getNominal();
                    
                    // Определяем своевременность погашения
                    $maturityDate = strtotime($bill->getMaturityDate());
                    $paymentDate = strtotime($bill->getPaymentDate());
                    
                    if ($paymentDate < $maturityDate) {
                        $monthlyData[$month]['early']++;
                    } elseif ($paymentDate <= $maturityDate) {
                        $monthlyData[$month]['on_time']++;
                    } else {
                        $monthlyData[$month]['late']++;
                    }
                }
            }
            
            ksort($monthlyData);
            
            $labels = [];
            $counts = [];
            $totals = [];
            $early = [];
            $onTime = [];
            $late = [];
            
            foreach ($monthlyData as $month => $data) {
                $labels[] = date('M Y', strtotime($month . '-01'));
                $counts[] = $data['count'];
                $totals[] = round($data['total'], 2);
                $early[] = $data['early'];
                $onTime[] = $data['on_time'];
                $late[] = $data['late'];
            }
            
            echo json_encode([
                'labels' => $labels,
                'datasets' => [
                    [
                        'label' => 'Количество погашенных векселей',
                        'data' => $counts,
                        'borderColor' => 'rgb(153, 102, 255)',
                        'backgroundColor' => 'rgba(153, 102, 255, 0.2)',
                        'tension' => 0.1
                    ],
                    [
                        'label' => 'Сумма погашенных векселей',
                        'data' => $totals,
                        'borderColor' => 'rgb(255, 159, 64)',
                        'backgroundColor' => 'rgba(255, 159, 64, 0.2)',
                        'tension' => 0.1,
                        'yAxisID' => 'y1'
                    ]
                ],
                'pie' => [
                    'labels' => ['Досрочно', 'В срок', 'Просрочено'],
                    'data' => [
                        array_sum($early),
                        array_sum($onTime),
                        array_sum($late)
                    ]
                ]
            ]);
            break;
            
        case 'transactions':
            // Данные для графика статистики транзакций
            $transactions = Transaction::findByUser($userId);
            
            // Группируем по месяцам
            $monthlyData = [];
            foreach ($transactions as $transaction) {
                $date = $transaction->getCreatedAt() ?: date('Y-m-d H:i:s');
                $month = date('Y-m', strtotime($date));
                
                if (!isset($monthlyData[$month])) {
                    $monthlyData[$month] = [
                        'total' => 0,
                        'completed' => 0,
                        'pending' => 0,
                        'active' => 0,
                        'cancelled' => 0
                    ];
                }
                
                $monthlyData[$month]['total']++;
                $status = $transaction->getStatus();
                if (isset($monthlyData[$month][$status])) {
                    $monthlyData[$month][$status]++;
                }
            }
            
            ksort($monthlyData);
            
            $labels = [];
            $totals = [];
            $completed = [];
            $pending = [];
            $active = [];
            $cancelled = [];
            
            foreach ($monthlyData as $month => $data) {
                $labels[] = date('M Y', strtotime($month . '-01'));
                $totals[] = $data['total'];
                $completed[] = $data['completed'];
                $pending[] = $data['pending'];
                $active[] = $data['active'];
                $cancelled[] = $data['cancelled'];
            }
            
            // Если нет данных, добавляем текущий месяц
            if (empty($labels)) {
                $labels[] = date('M Y');
                $stats = TransactionService::getStatistics($userId);
                $totals[] = $stats['total'];
                $completed[] = $stats['completed'];
                $pending[] = $stats['pending'] ?? 0;
                $active[] = $stats['active'];
                $cancelled[] = 0;
            }
            
            echo json_encode([
                'labels' => $labels,
                'datasets' => [
                    [
                        'label' => 'Всего транзакций',
                        'data' => $totals,
                        'borderColor' => 'rgb(102, 102, 255)',
                        'backgroundColor' => 'rgba(102, 102, 255, 0.2)',
                        'tension' => 0.1
                    ],
                    [
                        'label' => 'Завершённые',
                        'data' => $completed,
                        'borderColor' => 'rgb(75, 192, 192)',
                        'backgroundColor' => 'rgba(75, 192, 192, 0.2)',
                        'tension' => 0.1
                    ],
                    [
                        'label' => 'Активные',
                        'data' => $active,
                        'borderColor' => 'rgb(255, 206, 86)',
                        'backgroundColor' => 'rgba(255, 206, 86, 0.2)',
                        'tension' => 0.1
                    ],
                    [
                        'label' => 'Ожидают',
                        'data' => $pending,
                        'borderColor' => 'rgb(153, 102, 255)',
                        'backgroundColor' => 'rgba(153, 102, 255, 0.2)',
                        'tension' => 0.1
                    ]
                ]
            ]);
            break;
            
        case 'categories_distribution':
            // Данные для графика распределения транзакций по категориям
            $transactions = Transaction::findByUser($userId);
            
            $categoryCounts = [];
            foreach ($transactions as $transaction) {
                $catName = $transaction->getCategory();
                if (!$catName) {
                    $catName = 'Без категории';
                }
                
                if (!isset($categoryCounts[$catName])) {
                    $categoryCounts[$catName] = 0;
                }
                $categoryCounts[$catName]++;
            }
            
            // Сортируем по количеству
            arsort($categoryCounts);
            
            // Берем топ-10 категорий
            $topCategories = array_slice($categoryCounts, 0, 10, true);
            
            $labels = [];
            $data = [];
            $colors = [
                'rgba(102, 126, 234, 0.8)',
                'rgba(75, 192, 192, 0.8)',
                'rgba(255, 99, 132, 0.8)',
                'rgba(255, 206, 86, 0.8)',
                'rgba(153, 102, 255, 0.8)',
                'rgba(255, 159, 64, 0.8)',
                'rgba(54, 162, 235, 0.8)',
                'rgba(201, 203, 207, 0.8)',
                'rgba(255, 99, 71, 0.8)',
                'rgba(50, 205, 50, 0.8)'
            ];
            
            $i = 0;
            foreach ($topCategories as $catName => $count) {
                $labels[] = $catName;
                $data[] = $count;
                $i++;
            }
            
            echo json_encode([
                'labels' => $labels,
                'datasets' => [[
                    'label' => 'Количество транзакций',
                    'data' => $data,
                    'backgroundColor' => array_slice($colors, 0, count($data)),
                    'borderColor' => array_map(function($c) {
                        return str_replace('0.8', '1', $c);
                    }, array_slice($colors, 0, count($data))),
                    'borderWidth' => 1
                ]]
            ], JSON_UNESCAPED_UNICODE);
            break;
            
        case 'economic_calculations':
            // Автоматические экономические расчёты
            $bills = Bill::findByIssuer($userId);
            $heldBills = Bill::findByHolder($userId);
            
            // Получаем все активные вексели
            $activeIssued = array_filter($bills, fn($b) => $b->getStatus() === 'active');
            $activeHeld = array_filter($heldBills, fn($b) => $b->getStatus() === 'active');
            
            // Расчёт ликвидности (текущие активы / обязательства)
            $totalHeld = array_sum(array_map(fn($b) => $b->getNominal(), $activeHeld));
            $totalIssued = array_sum(array_map(fn($b) => $b->getNominal(), $activeIssued));
            $liquidityRatio = $totalIssued > 0 ? $totalHeld / $totalIssued : ($totalHeld > 0 ? 999 : 0);
            
            if ($liquidityRatio >= 2.0) {
                $liquidityRating = 'good';
                $liquidityDesc = 'Отличная ликвидность. Достаточно активов для покрытия обязательств.';
            } elseif ($liquidityRatio >= 1.0) {
                $liquidityRating = 'warning';
                $liquidityDesc = 'Нормальная ликвидность. Активы покрывают обязательства.';
            } else {
                $liquidityRating = 'danger';
                $liquidityDesc = 'Низкая ликвидность. Обязательства превышают активы.';
            }
            
            // Чистый баланс
            $netBalance = $totalHeld - $totalIssued;
            $netBalanceRating = $netBalance >= 0 ? 'good' : ($netBalance >= -$totalHeld * 0.5 ? 'warning' : 'danger');
            $netBalanceDesc = $netBalance >= 0 
                ? 'Положительный баланс. Вы имеете больше активов, чем обязательств.'
                : 'Отрицательный баланс. Необходимо увеличить доходы или уменьшить обязательства.';
            
            // Эффективность оборачиваемости векселей
            // Объединяем вексели, исключая дубликаты по ID
            $allBillsMap = [];
            foreach ($bills as $bill) {
                $allBillsMap[$bill->getId()] = $bill;
            }
            foreach ($heldBills as $bill) {
                if (!isset($allBillsMap[$bill->getId()])) {
                    $allBillsMap[$bill->getId()] = $bill;
                }
            }
            $paidBills = array_filter($allBillsMap, fn($b) => $b->getStatus() === 'paid');
            $avgPaymentDays = 0;
            if (count($paidBills) > 0) {
                $totalDays = 0;
                foreach ($paidBills as $bill) {
                    if ($bill->getPaymentDate()) {
                        $days = (strtotime($bill->getPaymentDate()) - strtotime($bill->getIssueDate())) / 86400;
                        $totalDays += max(0, $days);
                    }
                }
                $avgPaymentDays = $totalDays / count($paidBills);
            }
            $turnoverRatio = $avgPaymentDays > 0 ? 365 / $avgPaymentDays : 0;
            
            if ($turnoverRatio >= 12) {
                $turnoverRating = 'good';
                $turnoverDesc = 'Высокая оборачиваемость. Вексели погашаются быстро.';
            } elseif ($turnoverRatio >= 6) {
                $turnoverRating = 'warning';
                $turnoverDesc = 'Средняя оборачиваемость. Нормальный цикл оборота.';
            } else {
                $turnoverRating = 'danger';
                $turnoverDesc = 'Низкая оборачиваемость. Длительный цикл погашения.';
            }
            
            // Доходность (отношение полученных к выпущенным)
            $totalReceived = array_sum(array_map(fn($b) => $b->getNominal(), $heldBills));
            $totalIssuedAll = array_sum(array_map(fn($b) => $b->getNominal(), $bills));
            $profitabilityRatio = $totalIssuedAll > 0 ? $totalReceived / $totalIssuedAll : 0;
            
            if ($profitabilityRatio >= 1.5) {
                $profitRating = 'good';
                $profitDesc = 'Высокая доходность. Получаете больше, чем выпускаете.';
            } elseif ($profitabilityRatio >= 1.0) {
                $profitRating = 'warning';
                $profitDesc = 'Сбалансированная доходность. Активы примерно равны обязательствам.';
            } else {
                $profitRating = 'danger';
                $profitDesc = 'Низкая доходность. Выпускаете больше, чем получаете.';
            }
            
            // Риск просрочки
            $overdueBills = array_filter($bills, fn($b) => $b->getStatus() === 'overdue');
            $activeAndOverdue = array_merge($activeIssued, $overdueBills);
            $overdueCount = count($overdueBills);
            $totalActive = count($activeAndOverdue);
            $overdueRiskRatio = $totalActive > 0 ? $overdueCount / $totalActive : 0;
            
            if ($overdueRiskRatio <= 0.05) {
                $riskRating = 'good';
                $riskDesc = 'Низкий риск. Все вексели погашаются вовремя.';
            } elseif ($overdueRiskRatio <= 0.15) {
                $riskRating = 'warning';
                $riskDesc = 'Умеренный риск. Есть некоторые просроченные вексели.';
            } else {
                $riskRating = 'danger';
                $riskDesc = 'Высокий риск. Много просроченных векселей.';
            }
            
            // Платёжеспособность (комплексный показатель 0-100)
            $paidBillsCount = count($paidBills);
            // Объединяем вексели, исключая дубликаты по ID
            $allBillsIds = [];
            $allBillsUnique = [];
            foreach (array_merge($bills, $heldBills) as $bill) {
                if (!in_array($bill->getId(), $allBillsIds)) {
                    $allBillsIds[] = $bill->getId();
                    $allBillsUnique[] = $bill;
                }
            }
            $allBillsCount = count($allBillsUnique);
            $paymentRate = $allBillsCount > 0 ? $paidBillsCount / $allBillsCount : 0;
            
            // Расчёт общего скора платёжеспособности
            $solvencyScore = 0;
            $solvencyScore += min(30, $liquidityRatio * 15); // До 30 баллов за ликвидность
            $solvencyScore += min(25, $paymentRate * 25); // До 25 баллов за процент погашенных
            $solvencyScore += min(20, (1 - $overdueRiskRatio) * 20); // До 20 баллов за отсутствие просрочек
            $solvencyScore += min(15, $turnoverRatio / 12 * 15); // До 15 баллов за оборачиваемость
            $solvencyScore += min(10, max(0, $netBalance / ($totalHeld ?: 1) * 10)); // До 10 баллов за баланс
            
            $solvencyScore = round($solvencyScore);
            
            if ($solvencyScore >= 80) {
                $solvencyRating = 'good';
                $solvencyDesc = 'Отличная платёжеспособность. Высокая финансовая надёжность.';
            } elseif ($solvencyScore >= 60) {
                $solvencyRating = 'warning';
                $solvencyDesc = 'Хорошая платёжеспособность. Нормальная финансовая стабильность.';
            } else {
                $solvencyRating = 'danger';
                $solvencyDesc = 'Низкая платёжеспособность. Требуется улучшение финансового состояния.';
            }
            
            echo json_encode([
                'liquidity' => [
                    'ratio' => $liquidityRatio,
                    'rating' => $liquidityRating,
                    'description' => $liquidityDesc
                ],
                'netBalance' => [
                    'value' => $netBalance,
                    'rating' => $netBalanceRating,
                    'description' => $netBalanceDesc
                ],
                'turnover' => [
                    'ratio' => $turnoverRatio,
                    'rating' => $turnoverRating,
                    'description' => $turnoverDesc
                ],
                'profitability' => [
                    'ratio' => $profitabilityRatio,
                    'rating' => $profitRating,
                    'description' => $profitDesc
                ],
                'overdueRisk' => [
                    'ratio' => $overdueRiskRatio,
                    'rating' => $riskRating,
                    'description' => $riskDesc
                ],
                'solvency' => [
                    'score' => $solvencyScore,
                    'rating' => $solvencyRating,
                    'description' => $solvencyDesc
                ]
            ], JSON_UNESCAPED_UNICODE);
            break;
            
        case 'cashflow_forecast':
            // Прогноз денежных потоков на ближайшие 3 месяца
            $issuedBills = Bill::findByIssuer($userId);
            $heldBills = Bill::findByHolder($userId);
            
            // Объединяем, исключая дубликаты
            $billsMap = [];
            foreach ($issuedBills as $bill) {
                $billsMap[$bill->getId()] = $bill;
            }
            foreach ($heldBills as $bill) {
                if (!isset($billsMap[$bill->getId()])) {
                    $billsMap[$bill->getId()] = $bill;
                }
            }
            $activeBills = array_filter($billsMap, fn($b) => $b->getStatus() === 'active');
            
            $forecast = [];
            for ($i = 0; $i < 90; $i++) {
                $date = date('Y-m-d', strtotime("+{$i} days"));
                $forecast[$date] = [
                    'incoming' => 0,
                    'outgoing' => 0
                ];
            }
            
            foreach ($activeBills as $bill) {
                $maturityDate = date('Y-m-d', strtotime($bill->getMaturityDate()));
                if (isset($forecast[$maturityDate])) {
                    // Если вексель выпущен (обязательство) - исходящий поток
                    if ($bill->getIssuerId() == $userId) {
                        $forecast[$maturityDate]['outgoing'] += $bill->getNominal();
                    }
                    // Если вексель получен (актив) - входящий поток
                    if ($bill->getHolderId() == $userId) {
                        $forecast[$maturityDate]['incoming'] += $bill->getNominal();
                    }
                }
            }
            
            // Группируем по неделям для графика
            $weeklyData = [];
            $currentWeek = date('W', strtotime('today'));
            $currentYear = date('Y');
            
            foreach ($forecast as $date => $flows) {
                $week = date('W', strtotime($date));
                $year = date('Y', strtotime($date));
                $weekKey = "{$year}-W{$week}";
                
                if (!isset($weeklyData[$weekKey])) {
                    $weeklyData[$weekKey] = [
                        'incoming' => 0,
                        'outgoing' => 0,
                        'label' => 'Неделя ' . $week
                    ];
                }
                
                $weeklyData[$weekKey]['incoming'] += $flows['incoming'];
                $weeklyData[$weekKey]['outgoing'] += $flows['outgoing'];
            }
            
            ksort($weeklyData);
            $labels = array_column($weeklyData, 'label');
            $incoming = array_column($weeklyData, 'incoming');
            $outgoing = array_column($weeklyData, 'outgoing');
            
            // Рассчитываем чистый поток
            $netFlow = array_map(function($in, $out) {
                return $in - $out;
            }, $incoming, $outgoing);
            
            echo json_encode([
                'labels' => $labels,
                'datasets' => [
                    [
                        'label' => 'Входящий поток',
                        'data' => $incoming,
                        'borderColor' => 'rgb(75, 192, 192)',
                        'backgroundColor' => 'rgba(75, 192, 192, 0.2)',
                        'tension' => 0.1
                    ],
                    [
                        'label' => 'Исходящий поток',
                        'data' => $outgoing,
                        'borderColor' => 'rgb(255, 99, 132)',
                        'backgroundColor' => 'rgba(255, 99, 132, 0.2)',
                        'tension' => 0.1
                    ],
                    [
                        'label' => 'Чистый поток',
                        'data' => $netFlow,
                        'borderColor' => 'rgb(54, 162, 235)',
                        'backgroundColor' => 'rgba(54, 162, 235, 0.2)',
                        'tension' => 0.1,
                        'borderDash' => [5, 5]
                    ]
                ]
            ], JSON_UNESCAPED_UNICODE);
            break;
            
        case 'efficiency_metrics':
            // Показатели эффективности за последние 6 месяцев
            $issuedBills = Bill::findByIssuer($userId);
            $heldBills = Bill::findByHolder($userId);
            
            // Объединяем, исключая дубликаты
            $billsMap = [];
            foreach ($issuedBills as $bill) {
                $billsMap[$bill->getId()] = $bill;
            }
            foreach ($heldBills as $bill) {
                if (!isset($billsMap[$bill->getId()])) {
                    $billsMap[$bill->getId()] = $bill;
                }
            }
            $bills = array_values($billsMap);
            
            $monthlyMetrics = [];
            for ($i = 5; $i >= 0; $i--) {
                $month = date('Y-m', strtotime("-{$i} months"));
                $monthlyMetrics[$month] = [
                    'payment_rate' => 0,
                    'on_time_rate' => 0,
                    'avg_amount' => 0,
                    'count' => 0
                ];
            }
            
            foreach ($bills as $bill) {
                $month = date('Y-m', strtotime($bill->getIssueDate()));
                if (isset($monthlyMetrics[$month])) {
                    $monthlyMetrics[$month]['count']++;
                    
                    if ($bill->getStatus() === 'paid') {
                        $monthlyMetrics[$month]['payment_rate']++;
                        
                        if ($bill->getPaymentDate() && $bill->getMaturityDate()) {
                            $paymentDate = strtotime($bill->getPaymentDate());
                            $maturityDate = strtotime($bill->getMaturityDate());
                            if ($paymentDate <= $maturityDate) {
                                $monthlyMetrics[$month]['on_time_rate']++;
                            }
                        }
                    }
                    
                    $monthlyMetrics[$month]['avg_amount'] += $bill->getNominal();
                }
            }
            
            $labels = [];
            $paymentRates = [];
            $onTimeRates = [];
            
            foreach ($monthlyMetrics as $month => $metrics) {
                $labels[] = date('M Y', strtotime($month . '-01'));
                $paymentRates[] = $metrics['count'] > 0 ? round($metrics['payment_rate'] / $metrics['count'] * 100, 1) : 0;
                $onTimeRates[] = $metrics['payment_rate'] > 0 ? round($metrics['on_time_rate'] / $metrics['payment_rate'] * 100, 1) : 0;
            }
            
            echo json_encode([
                'labels' => $labels,
                'datasets' => [
                    [
                        'label' => 'Процент погашенных векселей',
                        'data' => $paymentRates,
                        'backgroundColor' => 'rgba(75, 192, 192, 0.8)',
                        'borderColor' => 'rgb(75, 192, 192)'
                    ],
                    [
                        'label' => 'Процент своевременных погашений',
                        'data' => $onTimeRates,
                        'backgroundColor' => 'rgba(54, 162, 235, 0.8)',
                        'borderColor' => 'rgb(54, 162, 235)'
                    ]
                ]
            ], JSON_UNESCAPED_UNICODE);
            break;
            
        case 'metric_details':
            // Детализация конкретного показателя
            $type = $_GET['type'] ?? '';
            
            if (!$type) {
                http_response_code(400);
                echo json_encode(['error' => 'Type parameter required']);
                break;
            }
            
            $bills = Bill::findByIssuer($userId);
            $heldBills = Bill::findByHolder($userId);
            $activeIssued = array_filter($bills, fn($b) => $b->getStatus() === 'active');
            $activeHeld = array_filter($heldBills, fn($b) => $b->getStatus() === 'active');
            
            $totalHeld = array_sum(array_map(fn($b) => $b->getNominal(), $activeHeld));
            $totalIssued = array_sum(array_map(fn($b) => $b->getNominal(), $activeIssued));
            
            $detailsHtml = '';
            
            switch ($type) {
                case 'liquidity':
                    $detailsHtml = '
                        <div class="metric-detail-section">
                            <h4>Расчёт ликвидности</h4>
                            <p>Коэффициент ликвидности = Активы / Обязательства</p>
                            <table class="metric-detail-table">
                                <tr>
                                    <th>Показатель</th>
                                    <th>Значение</th>
                                </tr>
                                <tr>
                                    <td>Активы (полученные вексели)</td>
                                    <td>' . number_format($totalHeld, 2, '.', ' ') . ' ₽</td>
                                </tr>
                                <tr>
                                    <td>Обязательства (выпущенные вексели)</td>
                                    <td>' . number_format($totalIssued, 2, '.', ' ') . ' ₽</td>
                                </tr>
                                <tr>
                                    <td><strong>Коэффициент ликвидности</strong></td>
                                    <td><strong>' . ($totalIssued > 0 ? number_format($totalHeld / $totalIssued, 2, '.', ' ') : '∞') . '</strong></td>
                                </tr>
                            </table>
                        </div>
                        <div class="metric-detail-section">
                            <h4>Интерпретация</h4>
                            <ul style="margin: 0; padding-left: 20px; line-height: 1.8;">
                                <li><strong>≥ 2.0</strong> - Отличная ликвидность. Достаточно активов для покрытия обязательств.</li>
                                <li><strong>1.0 - 2.0</strong> - Нормальная ликвидность. Активы покрывают обязательства.</li>
                                <li><strong>&lt; 1.0</strong> - Низкая ликвидность. Обязательства превышают активы.</li>
                            </ul>
                        </div>
                    ';
                    break;
                    
                case 'balance':
                    $netBalance = $totalHeld - $totalIssued;
                    $detailsHtml = '
                        <div class="metric-detail-section">
                            <h4>Расчёт чистого баланса</h4>
                            <p>Чистый баланс = Активы - Обязательства</p>
                            <table class="metric-detail-table">
                                <tr>
                                    <th>Показатель</th>
                                    <th>Значение</th>
                                </tr>
                                <tr>
                                    <td>Активы (полученные вексели)</td>
                                    <td style="color: #10b981;">+' . number_format($totalHeld, 2, '.', ' ') . ' ₽</td>
                                </tr>
                                <tr>
                                    <td>Обязательства (выпущенные вексели)</td>
                                    <td style="color: #ef4444;">-' . number_format($totalIssued, 2, '.', ' ') . ' ₽</td>
                                </tr>
                                <tr>
                                    <td><strong>Чистый баланс</strong></td>
                                    <td><strong style="color: ' . ($netBalance >= 0 ? '#10b981' : '#ef4444') . ';">' . number_format($netBalance, 2, '.', ' ') . ' ₽</strong></td>
                                </tr>
                            </table>
                        </div>
                        <div class="metric-detail-section">
                            <h4>Интерпретация</h4>
                            <ul style="margin: 0; padding-left: 20px; line-height: 1.8;">
                                <li><strong>Положительный баланс</strong> - Вы имеете больше активов, чем обязательств.</li>
                                <li><strong>Отрицательный баланс</strong> - Необходимо увеличить доходы или уменьшить обязательства.</li>
                            </ul>
                        </div>
                    ';
                    break;
                    
                case 'turnover':
                    // Объединяем вексели, исключая дубликаты
                    $allBillsMap = [];
                    foreach ($bills as $bill) {
                        $allBillsMap[$bill->getId()] = $bill;
                    }
                    foreach ($heldBills as $bill) {
                        if (!isset($allBillsMap[$bill->getId()])) {
                            $allBillsMap[$bill->getId()] = $bill;
                        }
                    }
                    $paidBills = array_filter($allBillsMap, fn($b) => $b->getStatus() === 'paid');
                    
                    $avgPaymentDays = 0;
                    $totalDays = 0;
                    if (count($paidBills) > 0) {
                        foreach ($paidBills as $bill) {
                            if ($bill->getPaymentDate()) {
                                $days = (strtotime($bill->getPaymentDate()) - strtotime($bill->getIssueDate())) / 86400;
                                $totalDays += max(0, $days);
                            }
                        }
                        $avgPaymentDays = $totalDays / count($paidBills);
                    }
                    $turnoverRatio = $avgPaymentDays > 0 ? 365 / $avgPaymentDays : 0;
                    
                    $detailsHtml = '
                        <div class="metric-detail-section">
                            <h4>Расчёт оборачиваемости</h4>
                            <p>Оборачиваемость = 365 дней / Средний срок погашения</p>
                            <table class="metric-detail-table">
                                <tr>
                                    <th>Показатель</th>
                                    <th>Значение</th>
                                </tr>
                                <tr>
                                    <td>Погашенных векселей</td>
                                    <td>' . count($paidBills) . '</td>
                                </tr>
                                <tr>
                                    <td>Средний срок погашения</td>
                                    <td>' . number_format($avgPaymentDays, 1) . ' дней</td>
                                </tr>
                                <tr>
                                    <td><strong>Оборачиваемость (раз в год)</strong></td>
                                    <td><strong>' . number_format($turnoverRatio, 2) . '</strong></td>
                                </tr>
                            </table>
                        </div>
                        <div class="metric-detail-section">
                            <h4>Интерпретация</h4>
                            <ul style="margin: 0; padding-left: 20px; line-height: 1.8;">
                                <li><strong>≥ 12</strong> - Высокая оборачиваемость. Вексели погашаются быстро (менее месяца).</li>
                                <li><strong>6 - 12</strong> - Средняя оборачиваемость. Нормальный цикл оборота (1-2 месяца).</li>
                                <li><strong>&lt; 6</strong> - Низкая оборачиваемость. Длительный цикл погашения (более 2 месяцев).</li>
                            </ul>
                        </div>
                    ';
                    break;
                    
                case 'profitability':
                    $totalReceived = array_sum(array_map(fn($b) => $b->getNominal(), $heldBills));
                    $totalIssuedAll = array_sum(array_map(fn($b) => $b->getNominal(), $bills));
                    $profitabilityRatio = $totalIssuedAll > 0 ? $totalReceived / $totalIssuedAll : 0;
                    
                    $detailsHtml = '
                        <div class="metric-detail-section">
                            <h4>Расчёт доходности</h4>
                            <p>Доходность = Полученные вексели / Выпущенные вексели</p>
                            <table class="metric-detail-table">
                                <tr>
                                    <th>Показатель</th>
                                    <th>Значение</th>
                                </tr>
                                <tr>
                                    <td>Всего получено векселей</td>
                                    <td>' . number_format($totalReceived, 2, '.', ' ') . ' ₽</td>
                                </tr>
                                <tr>
                                    <td>Всего выпущено векселей</td>
                                    <td>' . number_format($totalIssuedAll, 2, '.', ' ') . ' ₽</td>
                                </tr>
                                <tr>
                                    <td><strong>Коэффициент доходности</strong></td>
                                    <td><strong>' . number_format($profitabilityRatio * 100, 2) . '%</strong></td>
                                </tr>
                            </table>
                        </div>
                        <div class="metric-detail-section">
                            <h4>Интерпретация</h4>
                            <ul style="margin: 0; padding-left: 20px; line-height: 1.8;">
                                <li><strong>≥ 150%</strong> - Высокая доходность. Получаете значительно больше, чем выпускаете.</li>
                                <li><strong>100% - 150%</strong> - Сбалансированная доходность. Активы примерно равны обязательствам.</li>
                                <li><strong>&lt; 100%</strong> - Низкая доходность. Выпускаете больше, чем получаете.</li>
                            </ul>
                        </div>
                    ';
                    break;
                    
                case 'risk':
                    $overdueBills = array_filter($bills, fn($b) => $b->getStatus() === 'overdue');
                    $activeAndOverdue = array_merge($activeIssued, $overdueBills);
                    $overdueCount = count($overdueBills);
                    $totalActive = count($activeAndOverdue);
                    $overdueRiskRatio = $totalActive > 0 ? $overdueCount / $totalActive : 0;
                    
                    $detailsHtml = '
                        <div class="metric-detail-section">
                            <h4>Расчёт риска просрочки</h4>
                            <p>Риск просрочки = Просроченные вексели / Всего активных векселей</p>
                            <table class="metric-detail-table">
                                <tr>
                                    <th>Показатель</th>
                                    <th>Значение</th>
                                </tr>
                                <tr>
                                    <td>Активных векселей</td>
                                    <td>' . count($activeIssued) . '</td>
                                </tr>
                                <tr>
                                    <td>Просроченных векселей</td>
                                    <td style="color: #ef4444;">' . $overdueCount . '</td>
                                </tr>
                                <tr>
                                    <td><strong>Процент риска</strong></td>
                                    <td><strong style="color: ' . ($overdueRiskRatio <= 0.05 ? '#10b981' : ($overdueRiskRatio <= 0.15 ? '#f59e0b' : '#ef4444')) . ';">' . number_format($overdueRiskRatio * 100, 1) . '%</strong></td>
                                </tr>
                            </table>
                        </div>
                        <div class="metric-detail-section">
                            <h4>Интерпретация</h4>
                            <ul style="margin: 0; padding-left: 20px; line-height: 1.8;">
                                <li><strong>≤ 5%</strong> - Низкий риск. Все вексели погашаются вовремя.</li>
                                <li><strong>5% - 15%</strong> - Умеренный риск. Есть некоторые просроченные вексели.</li>
                                <li><strong>&gt; 15%</strong> - Высокий риск. Много просроченных векселей.</li>
                            </ul>
                        </div>
                    ';
                    break;
                    
                case 'solvency':
                    // Объединяем вексели для расчёта
                    $allBillsMap = [];
                    foreach ($bills as $bill) {
                        $allBillsMap[$bill->getId()] = $bill;
                    }
                    foreach ($heldBills as $bill) {
                        if (!isset($allBillsMap[$bill->getId()])) {
                            $allBillsMap[$bill->getId()] = $bill;
                        }
                    }
                    $paidBills = array_filter($allBillsMap, fn($b) => $b->getStatus() === 'paid');
                    $allBillsCount = count($allBillsMap);
                    $paymentRate = $allBillsCount > 0 ? count($paidBills) / $allBillsCount : 0;
                    
                    $liquidityRatio = $totalIssued > 0 ? $totalHeld / $totalIssued : ($totalHeld > 0 ? 999 : 0);
                    $overdueRiskRatio = count($activeIssued) > 0 ? count(array_filter($bills, fn($b) => $b->getStatus() === 'overdue')) / count($activeIssued) : 0;
                    
                    $avgPaymentDays = 0;
                    if (count($paidBills) > 0) {
                        $totalDays = 0;
                        foreach ($paidBills as $bill) {
                            if ($bill->getPaymentDate()) {
                                $days = (strtotime($bill->getPaymentDate()) - strtotime($bill->getIssueDate())) / 86400;
                                $totalDays += max(0, $days);
                            }
                        }
                        $avgPaymentDays = $totalDays / count($paidBills);
                    }
                    $turnoverRatio = $avgPaymentDays > 0 ? 365 / $avgPaymentDays : 0;
                    $netBalance = $totalHeld - $totalIssued;
                    
                    // Расчёт общего скора
                    $solvencyScore = 0;
                    $solvencyScore += min(30, $liquidityRatio * 15);
                    $solvencyScore += min(25, $paymentRate * 25);
                    $solvencyScore += min(20, (1 - $overdueRiskRatio) * 20);
                    $solvencyScore += min(15, $turnoverRatio / 12 * 15);
                    $solvencyScore += min(10, max(0, $netBalance / ($totalHeld ?: 1) * 10));
                    $solvencyScore = round($solvencyScore);
                    
                    $detailsHtml = '
                        <div class="metric-detail-section">
                            <h4>Расчёт платёжеспособности</h4>
                            <p>Комплексный показатель на основе нескольких факторов (0-100 баллов)</p>
                            <table class="metric-detail-table">
                                <tr>
                                    <th>Фактор</th>
                                    <th>Вклад</th>
                                    <th>Баллы</th>
                                </tr>
                                <tr>
                                    <td>Ликвидность</td>
                                    <td>Коэффициент: ' . number_format($liquidityRatio, 2) . '</td>
                                    <td>' . round(min(30, $liquidityRatio * 15)) . '/30</td>
                                </tr>
                                <tr>
                                    <td>Процент погашенных</td>
                                    <td>' . number_format($paymentRate * 100, 1) . '%</td>
                                    <td>' . round(min(25, $paymentRate * 25)) . '/25</td>
                                </tr>
                                <tr>
                                    <td>Отсутствие просрочек</td>
                                    <td>Риск: ' . number_format($overdueRiskRatio * 100, 1) . '%</td>
                                    <td>' . round(min(20, (1 - $overdueRiskRatio) * 20)) . '/20</td>
                                </tr>
                                <tr>
                                    <td>Оборачиваемость</td>
                                    <td>Коэффициент: ' . number_format($turnoverRatio, 2) . '</td>
                                    <td>' . round(min(15, $turnoverRatio / 12 * 15)) . '/15</td>
                                </tr>
                                <tr>
                                    <td>Чистый баланс</td>
                                    <td>' . number_format($netBalance, 2, '.', ' ') . ' ₽</td>
                                    <td>' . round(min(10, max(0, $netBalance / ($totalHeld ?: 1) * 10))) . '/10</td>
                                </tr>
                                <tr style="background: var(--bg-secondary);">
                                    <td><strong>Итого</strong></td>
                                    <td></td>
                                    <td><strong>' . $solvencyScore . '/100</strong></td>
                                </tr>
                            </table>
                        </div>
                        <div class="metric-detail-section">
                            <h4>Интерпретация</h4>
                            <ul style="margin: 0; padding-left: 20px; line-height: 1.8;">
                                <li><strong>80-100</strong> - Отличная платёжеспособность. Высокая финансовая надёжность.</li>
                                <li><strong>60-79</strong> - Хорошая платёжеспособность. Нормальная финансовая стабильность.</li>
                                <li><strong>&lt; 60</strong> - Низкая платёжеспособность. Требуется улучшение финансового состояния.</li>
                            </ul>
                        </div>
                    ';
                    break;
                    
                default:
                    $detailsHtml = '<p>Детализация для данного показателя недоступна.</p>';
                    break;
            }
            
            echo json_encode([
                'details' => $detailsHtml
            ], JSON_UNESCAPED_UNICODE);
            break;
            
        case 'chart_details':
            // Детализация графиков
            $type = $_GET['type'] ?? '';
            
            if (!$type) {
                http_response_code(400);
                echo json_encode(['error' => 'Type parameter required']);
                break;
            }
            
            $bills = Bill::findByIssuer($userId);
            $heldBills = Bill::findByHolder($userId);
            
            $detailsHtml = '';
            
            switch ($type) {
                case 'cashflow':
                    // Получаем данные для прогноза
                    $issuedBills = Bill::findByIssuer($userId);
                    $heldBills = Bill::findByHolder($userId);
                    
                    $billsMap = [];
                    foreach ($issuedBills as $bill) {
                        $billsMap[$bill->getId()] = $bill;
                    }
                    foreach ($heldBills as $bill) {
                        if (!isset($billsMap[$bill->getId()])) {
                            $billsMap[$bill->getId()] = $bill;
                        }
                    }
                    $activeBills = array_filter($billsMap, fn($b) => $b->getStatus() === 'active');
                    
                    $totalIncoming = 0;
                    $totalOutgoing = 0;
                    $billsCount = 0;
                    
                    foreach ($activeBills as $bill) {
                        if ($bill->getHolderId() == $userId) {
                            $totalIncoming += $bill->getNominal();
                        }
                        if ($bill->getIssuerId() == $userId) {
                            $totalOutgoing += $bill->getNominal();
                        }
                        $billsCount++;
                    }
                    
                    $detailsHtml = '
                        <div class="metric-detail-section">
                            <h4>Методология расчёта</h4>
                            <p>Прогноз денежных потоков рассчитывается на основе активных векселей на ближайшие 90 дней (3 месяца).</p>
                        </div>
                        <div class="metric-detail-section">
                            <h4>Формула расчёта</h4>
                            <ul style="margin: 0; padding-left: 20px; line-height: 1.8;">
                                <li><strong>Входящий поток</strong> = Сумма номиналов векселей, где вы являетесь держателем (holder_id), со сроком погашения в прогнозируемом периоде</li>
                                <li><strong>Исходящий поток</strong> = Сумма номиналов векселей, где вы являетесь эмитентом (issuer_id), со сроком погашения в прогнозируемом периоде</li>
                                <li><strong>Чистый поток</strong> = Входящий поток - Исходящий поток</li>
                            </ul>
                        </div>
                        <div class="metric-detail-section">
                            <h4>Текущие данные</h4>
                            <table class="metric-detail-table">
                                <tr>
                                    <th>Показатель</th>
                                    <th>Значение</th>
                                </tr>
                                <tr>
                                    <td>Активных векселей</td>
                                    <td>' . $billsCount . '</td>
                                </tr>
                                <tr>
                                    <td>Ожидаемый входящий поток</td>
                                    <td style="color: #10b981;">+' . number_format($totalIncoming, 2, '.', ' ') . ' ₽</td>
                                </tr>
                                <tr>
                                    <td>Ожидаемый исходящий поток</td>
                                    <td style="color: #ef4444;">-' . number_format($totalOutgoing, 2, '.', ' ') . ' ₽</td>
                                </tr>
                                <tr>
                                    <td><strong>Чистый поток</strong></td>
                                    <td><strong style="color: ' . (($totalIncoming - $totalOutgoing) >= 0 ? '#10b981' : '#ef4444') . ';">' . number_format($totalIncoming - $totalOutgoing, 2, '.', ' ') . ' ₽</strong></td>
                                </tr>
                            </table>
                        </div>
                        <div class="metric-detail-section">
                            <h4>Интерпретация</h4>
                            <ul style="margin: 0; padding-left: 20px; line-height: 1.8;">
                                <li><strong>Положительный чистый поток</strong> - Ожидается приток денежных средств. Вы получите больше, чем должны выплатить.</li>
                                <li><strong>Отрицательный чистый поток</strong> - Ожидается отток денежных средств. Необходимо подготовиться к выплатам.</li>
                                <li>Прогноз основан на текущих активных векселях и их сроках погашения.</li>
                            </ul>
                        </div>
                    ';
                    break;
                    
                case 'efficiency':
                    // Получаем данные для показателей эффективности
                    $issuedBills = Bill::findByIssuer($userId);
                    $heldBills = Bill::findByHolder($userId);
                    
                    $billsMap = [];
                    foreach ($issuedBills as $bill) {
                        $billsMap[$bill->getId()] = $bill;
                    }
                    foreach ($heldBills as $bill) {
                        if (!isset($billsMap[$bill->getId()])) {
                            $billsMap[$bill->getId()] = $bill;
                        }
                    }
                    $bills = array_values($billsMap);
                    
                    // Группируем по месяцам
                    $monthlyData = [];
                    for ($i = 5; $i >= 0; $i--) {
                        $month = date('Y-m', strtotime("-{$i} months"));
                        $monthlyData[$month] = [
                            'total' => 0,
                            'paid' => 0,
                            'on_time' => 0
                        ];
                    }
                    
                    foreach ($bills as $bill) {
                        $month = date('Y-m', strtotime($bill->getIssueDate()));
                        if (isset($monthlyData[$month])) {
                            $monthlyData[$month]['total']++;
                            
                            if ($bill->getStatus() === 'paid') {
                                $monthlyData[$month]['paid']++;
                                
                                if ($bill->getPaymentDate() && $bill->getMaturityDate()) {
                                    $paymentDate = strtotime($bill->getPaymentDate());
                                    $maturityDate = strtotime($bill->getMaturityDate());
                                    if ($paymentDate <= $maturityDate) {
                                        $monthlyData[$month]['on_time']++;
                                    }
                                }
                            }
                        }
                    }
                    
                    $totalBills = array_sum(array_column($monthlyData, 'total'));
                    $totalPaid = array_sum(array_column($monthlyData, 'paid'));
                    $totalOnTime = array_sum(array_column($monthlyData, 'on_time'));
                    $paymentRate = $totalBills > 0 ? ($totalPaid / $totalBills) * 100 : 0;
                    $onTimeRate = $totalPaid > 0 ? ($totalOnTime / $totalPaid) * 100 : 0;
                    
                    $detailsHtml = '
                        <div class="metric-detail-section">
                            <h4>Методология расчёта</h4>
                            <p>Показатели эффективности рассчитываются на основе данных за последние 6 месяцев.</p>
                        </div>
                        <div class="metric-detail-section">
                            <h4>Формулы расчёта</h4>
                            <ul style="margin: 0; padding-left: 20px; line-height: 1.8;">
                                <li><strong>Процент погашенных векселей</strong> = (Количество погашенных векселей / Общее количество векселей) × 100%</li>
                                <li><strong>Процент своевременных погашений</strong> = (Количество погашенных в срок / Количество погашенных векселей) × 100%</li>
                                <li>Вексель считается погашенным в срок, если дата погашения ≤ дате срока погашения</li>
                            </ul>
                        </div>
                        <div class="metric-detail-section">
                            <h4>Текущие показатели</h4>
                            <table class="metric-detail-table">
                                <tr>
                                    <th>Показатель</th>
                                    <th>Значение</th>
                                </tr>
                                <tr>
                                    <td>Всего векселей за 6 месяцев</td>
                                    <td>' . $totalBills . '</td>
                                </tr>
                                <tr>
                                    <td>Погашенных векселей</td>
                                    <td>' . $totalPaid . '</td>
                                </tr>
                                <tr>
                                    <td>Погашенных в срок</td>
                                    <td>' . $totalOnTime . '</td>
                                </tr>
                                <tr>
                                    <td><strong>Процент погашенных</strong></td>
                                    <td><strong>' . number_format($paymentRate, 1) . '%</strong></td>
                                </tr>
                                <tr>
                                    <td><strong>Процент своевременных</strong></td>
                                    <td><strong>' . number_format($onTimeRate, 1) . '%</strong></td>
                                </tr>
                            </table>
                        </div>
                        <div class="metric-detail-section">
                            <h4>Интерпретация</h4>
                            <ul style="margin: 0; padding-left: 20px; line-height: 1.8;">
                                <li><strong>Высокий процент погашенных</strong> (≥80%) - Хорошая дисциплина погашения векселей.</li>
                                <li><strong>Высокий процент своевременных</strong> (≥90%) - Отличная платёжная дисциплина, вексели погашаются вовремя.</li>
                                <li>Эти показатели отражают надёжность и эффективность работы с векселями.</li>
                            </ul>
                        </div>
                    ';
                    break;
                    
                case 'rating':
                    // Детализация графика изменения рейтинга
                    $rating = Rating::findByUserId($userId);
                    $history = $rating->getHistory(90);
                    
                    $detailsHtml = '
                        <div class="metric-detail-section">
                            <h4>Методология расчёта рейтинга "Око"</h4>
                            <p>Рейтинг рассчитывается на основе трёх основных факторов и обновляется при каждом изменении векселей.</p>
                        </div>
                        <div class="metric-detail-section">
                            <h4>Формула расчёта</h4>
                            <p><strong>Общий рейтинг = (Балансовый расчёт / 100) × Дисциплина погашаемости × Усреднённое досрочное погашение</strong></p>
                            <ul style="margin: 12px 0 0 20px; padding: 0; line-height: 1.8;">
                                <li><strong>Балансовый расчёт</strong> = (Активы / Обязательства) × 100</li>
                                <li><strong>Дисциплина погашаемости</strong> = (Погашенные в срок / Все погашенные) × 100%</li>
                                <li><strong>Усреднённое досрочное погашение</strong> = Среднее значение коэффициента досрочности</li>
                                <li><strong>Дубли</strong> = 1000 × Общий рейтинг (репутационный капитал)</li>
                            </ul>
                        </div>
                        <div class="metric-detail-section">
                            <h4>Текущие значения</h4>
                            <table class="metric-detail-table">
                                <tr>
                                    <th>Показатель</th>
                                    <th>Значение</th>
                                </tr>
                                <tr>
                                    <td>Балансовый расчёт</td>
                                    <td>' . number_format($rating->getBalanceScore(), 2) . '</td>
                                </tr>
                                <tr>
                                    <td>Дисциплина погашаемости</td>
                                    <td>' . number_format($rating->getPaymentDiscipline(), 2) . '%</td>
                                </tr>
                                <tr>
                                    <td>Усреднённое досрочное погашение</td>
                                    <td>' . number_format($rating->getEarlyPaymentAvg(), 2) . '</td>
                                </tr>
                                <tr>
                                    <td><strong>Общий рейтинг</strong></td>
                                    <td><strong>' . number_format($rating->getTotalRating(), 2) . '</strong></td>
                                </tr>
                                <tr>
                                    <td><strong>Дубли (репутационный капитал)</strong></td>
                                    <td><strong>' . number_format($rating->getDoubles(), 2) . '</strong></td>
                                </tr>
                            </table>
                        </div>
                        <div class="metric-detail-section">
                            <h4>Интерпретация</h4>
                            <ul style="margin: 0; padding-left: 20px; line-height: 1.8;">
                                <li>График показывает изменение рейтинга за последние 90 дней</li>
                                <li>Высокий рейтинг указывает на хорошую финансовую дисциплину и надёжность</li>
                                <li>Дубли представляют репутационный капитал пользователя</li>
                            </ul>
                        </div>
                    ';
                    break;
                    
                case 'bills_balance':
                    // Детализация графика баланса векселей
                    $issuedBills = Bill::findByIssuer($userId);
                    $heldBills = Bill::findByHolder($userId);
                    
                    $totalIssued = array_sum(array_map(fn($b) => $b->getNominal(), $issuedBills));
                    $totalHeld = array_sum(array_map(fn($b) => $b->getNominal(), $heldBills));
                    $activeIssued = array_filter($issuedBills, fn($b) => $b->getStatus() === 'active');
                    $activeHeld = array_filter($heldBills, fn($b) => $b->getStatus() === 'active');
                    
                    $activeIssuedTotal = array_sum(array_map(fn($b) => $b->getNominal(), $activeIssued));
                    $activeHeldTotal = array_sum(array_map(fn($b) => $b->getNominal(), $activeHeld));
                    
                    $detailsHtml = '
                        <div class="metric-detail-section">
                            <h4>Методология расчёта</h4>
                            <p>График показывает накопленный баланс выпущенных и полученных векселей за последние 90 дней.</p>
                        </div>
                        <div class="metric-detail-section">
                            <h4>Метод расчёта</h4>
                            <ul style="margin: 0; padding-left: 20px; line-height: 1.8;">
                                <li><strong>Выпущенные вексели</strong> - накопленная сумма всех векселей, выпущенных вами (обязательства)</li>
                                <li><strong>Полученные вексели</strong> - накопленная сумма всех векселей, полученных вами (активы)</li>
                                <li>Значения накапливаются по датам выпуска векселей</li>
                            </ul>
                        </div>
                        <div class="metric-detail-section">
                            <h4>Текущие данные</h4>
                            <table class="metric-detail-table">
                                <tr>
                                    <th>Показатель</th>
                                    <th>Всего</th>
                                    <th>Активных</th>
                                </tr>
                                <tr>
                                    <td>Выпущено векселей</td>
                                    <td>' . count($issuedBills) . ' (' . number_format($totalIssued, 2, '.', ' ') . ' ₽)</td>
                                    <td>' . count($activeIssued) . ' (' . number_format($activeIssuedTotal, 2, '.', ' ') . ' ₽)</td>
                                </tr>
                                <tr>
                                    <td>Получено векселей</td>
                                    <td>' . count($heldBills) . ' (' . number_format($totalHeld, 2, '.', ' ') . ' ₽)</td>
                                    <td>' . count($activeHeld) . ' (' . number_format($activeHeldTotal, 2, '.', ' ') . ' ₽)</td>
                                </tr>
                            </table>
                        </div>
                        <div class="metric-detail-section">
                            <h4>Интерпретация</h4>
                            <ul style="margin: 0; padding-left: 20px; line-height: 1.8;">
                                <li>Положительная разница между полученными и выпущенными векселями указывает на активный баланс</li>
                                <li>График помогает отслеживать динамику изменения баланса во времени</li>
                            </ul>
                        </div>
                    ';
                    break;
                    
                case 'payment_dynamics':
                case 'payment_pie':
                    // Детализация графиков погашения
                    $bills = Bill::findByIssuer($userId, 'paid');
                    
                    $totalPaid = count($bills);
                    $onTimeCount = 0;
                    $earlyCount = 0;
                    $lateCount = 0;
                    
                    foreach ($bills as $bill) {
                        if ($bill->getPaymentDate() && $bill->getMaturityDate()) {
                            $paymentDate = strtotime($bill->getPaymentDate());
                            $maturityDate = strtotime($bill->getMaturityDate());
                            
                            if ($paymentDate < $maturityDate) {
                                $earlyCount++;
                            } elseif ($paymentDate <= $maturityDate) {
                                $onTimeCount++;
                            } else {
                                $lateCount++;
                            }
                        }
                    }
                    
                    $detailsHtml = '
                        <div class="metric-detail-section">
                            <h4>Методология расчёта</h4>
                            <p>Графики показывают динамику и своевременность погашения векселей, сгруппированные по месяцам.</p>
                        </div>
                        <div class="metric-detail-section">
                            <h4>Метод расчёта</h4>
                            <ul style="margin: 0; padding-left: 20px; line-height: 1.8;">
                                <li><strong>Динамика погашения</strong> - количество и сумма погашенных векселей по месяцам</li>
                                <li><strong>Своевременность</strong> - разделение на досрочные, в срок и просроченные погашения</li>
                                <li>Вексель считается погашенным <strong>в срок</strong>, если дата погашения ≤ дате срока погашения</li>
                                <li>Вексель считается погашенным <strong>досрочно</strong>, если дата погашения &lt; даты срока погашения</li>
                                <li>Вексель считается <strong>просроченным</strong>, если дата погашения &gt; даты срока погашения</li>
                            </ul>
                        </div>
                        <div class="metric-detail-section">
                            <h4>Текущие данные</h4>
                            <table class="metric-detail-table">
                                <tr>
                                    <th>Категория</th>
                                    <th>Количество</th>
                                    <th>Процент</th>
                                </tr>
                                <tr>
                                    <td>Досрочно погашенных</td>
                                    <td>' . $earlyCount . '</td>
                                    <td>' . ($totalPaid > 0 ? number_format(($earlyCount / $totalPaid) * 100, 1) : 0) . '%</td>
                                </tr>
                                <tr>
                                    <td>Погашенных в срок</td>
                                    <td>' . $onTimeCount . '</td>
                                    <td>' . ($totalPaid > 0 ? number_format(($onTimeCount / $totalPaid) * 100, 1) : 0) . '%</td>
                                </tr>
                                <tr>
                                    <td>Просроченных</td>
                                    <td>' . $lateCount . '</td>
                                    <td>' . ($totalPaid > 0 ? number_format(($lateCount / $totalPaid) * 100, 1) : 0) . '%</td>
                                </tr>
                                <tr style="background: var(--bg-secondary);">
                                    <td><strong>Всего погашенных</strong></td>
                                    <td><strong>' . $totalPaid . '</strong></td>
                                    <td><strong>100%</strong></td>
                                </tr>
                            </table>
                        </div>
                        <div class="metric-detail-section">
                            <h4>Интерпретация</h4>
                            <ul style="margin: 0; padding-left: 20px; line-height: 1.8;">
                                <li>Высокий процент своевременных погашений повышает рейтинг и доверие</li>
                                <li>Досрочное погашение улучшает репутацию</li>
                                <li>Графики помогают отслеживать платёжную дисциплину</li>
                            </ul>
                        </div>
                    ';
                    break;
                    
                case 'transactions':
                    // Детализация графика статистики транзакций
                    $transactions = Transaction::findByUser($userId);
                    
                    $totalCount = count($transactions);
                    $completedCount = count(array_filter($transactions, fn($t) => $t->getStatus() === 'completed'));
                    $activeCount = count(array_filter($transactions, fn($t) => $t->getStatus() === 'active'));
                    $pendingCount = count(array_filter($transactions, fn($t) => $t->getStatus() === 'pending'));
                    $cancelledCount = count(array_filter($transactions, fn($t) => $t->getStatus() === 'cancelled'));
                    
                    $detailsHtml = '
                        <div class="metric-detail-section">
                            <h4>Методология расчёта</h4>
                            <p>График показывает динамику транзакций по месяцам, разделённых по статусам.</p>
                        </div>
                        <div class="metric-detail-section">
                            <h4>Метод расчёта</h4>
                            <ul style="margin: 0; padding-left: 20px; line-height: 1.8;">
                                <li>Транзакции группируются по месяцам на основе даты создания</li>
                                <li>Подсчитывается количество транзакций каждого статуса в каждом месяце</li>
                                <li><strong>Завершённые</strong> - успешно завершённые транзакции</li>
                                <li><strong>Активные</strong> - транзакции в процессе выполнения</li>
                                <li><strong>Ожидают</strong> - транзакции, ожидающие подтверждения</li>
                                <li><strong>Отменённые</strong> - отменённые транзакции</li>
                            </ul>
                        </div>
                        <div class="metric-detail-section">
                            <h4>Текущие данные</h4>
                            <table class="metric-detail-table">
                                <tr>
                                    <th>Статус</th>
                                    <th>Количество</th>
                                    <th>Процент</th>
                                </tr>
                                <tr>
                                    <td>Завершённые</td>
                                    <td>' . $completedCount . '</td>
                                    <td>' . ($totalCount > 0 ? number_format(($completedCount / $totalCount) * 100, 1) : 0) . '%</td>
                                </tr>
                                <tr>
                                    <td>Активные</td>
                                    <td>' . $activeCount . '</td>
                                    <td>' . ($totalCount > 0 ? number_format(($activeCount / $totalCount) * 100, 1) : 0) . '%</td>
                                </tr>
                                <tr>
                                    <td>Ожидают</td>
                                    <td>' . $pendingCount . '</td>
                                    <td>' . ($totalCount > 0 ? number_format(($pendingCount / $totalCount) * 100, 1) : 0) . '%</td>
                                </tr>
                                <tr>
                                    <td>Отменённые</td>
                                    <td>' . $cancelledCount . '</td>
                                    <td>' . ($totalCount > 0 ? number_format(($cancelledCount / $totalCount) * 100, 1) : 0) . '%</td>
                                </tr>
                                <tr style="background: var(--bg-secondary);">
                                    <td><strong>Всего</strong></td>
                                    <td><strong>' . $totalCount . '</strong></td>
                                    <td><strong>100%</strong></td>
                                </tr>
                            </table>
                        </div>
                        <div class="metric-detail-section">
                            <h4>Интерпретация</h4>
                            <ul style="margin: 0; padding-left: 20px; line-height: 1.8;">
                                <li>Высокий процент завершённых транзакций указывает на успешную торговую активность</li>
                                <li>График помогает отслеживать динамику торговых операций</li>
                            </ul>
                        </div>
                    ';
                    break;
                    
                case 'categories':
                    // Детализация графика распределения по категориям
                    $transactions = Transaction::findByUser($userId);
                    
                    $categoryCounts = [];
                    foreach ($transactions as $transaction) {
                        $catName = $transaction->getCategory() ?: 'Без категории';
                        if (!isset($categoryCounts[$catName])) {
                            $categoryCounts[$catName] = 0;
                        }
                        $categoryCounts[$catName]++;
                    }
                    
                    arsort($categoryCounts);
                    $topCategories = array_slice($categoryCounts, 0, 10, true);
                    $totalCount = count($transactions);
                    
                    $categoriesTable = '';
                    foreach ($topCategories as $catName => $count) {
                        $percentage = $totalCount > 0 ? number_format(($count / $totalCount) * 100, 1) : 0;
                        $categoriesTable .= '<tr><td>' . htmlspecialchars($catName) . '</td><td>' . $count . '</td><td>' . $percentage . '%</td></tr>';
                    }
                    
                    $detailsHtml = '
                        <div class="metric-detail-section">
                            <h4>Методология расчёта</h4>
                            <p>График показывает распределение транзакций по категориям товаров/услуг.</p>
                        </div>
                        <div class="metric-detail-section">
                            <h4>Метод расчёта</h4>
                            <ul style="margin: 0; padding-left: 20px; line-height: 1.8;">
                                <li>Все транзакции группируются по категориям</li>
                                <li>Подсчитывается количество транзакций в каждой категории</li>
                                <li>Отображаются топ-10 категорий по количеству транзакций</li>
                                <li>Транзакции без категории отображаются как "Без категории"</li>
                            </ul>
                        </div>
                        <div class="metric-detail-section">
                            <h4>Топ категорий</h4>
                            <table class="metric-detail-table">
                                <tr>
                                    <th>Категория</th>
                                    <th>Количество</th>
                                    <th>Процент</th>
                                </tr>
                                ' . $categoriesTable . '
                            </table>
                        </div>
                        <div class="metric-detail-section">
                            <h4>Интерпретация</h4>
                            <ul style="margin: 0; padding-left: 20px; line-height: 1.8;">
                                <li>График показывает предпочтения в торговых операциях</li>
                                <li>Помогает понять структуру бизнеса и основные направления деятельности</li>
                            </ul>
                        </div>
                    ';
                    break;
                    
                default:
                    $detailsHtml = '<p>Детализация для данного графика недоступна.</p>';
                    break;
            }
            
            echo json_encode([
                'details' => $detailsHtml
            ], JSON_UNESCAPED_UNICODE);
            break;
            
        case 'rating_component_details':
            // Детализация компонентов рейтинга
            $type = $_GET['type'] ?? '';
            $rating = Rating::findByUserId($userId);
            $detailsHtml = '';
            
            switch ($type) {
                case 'balance_score':
                    $db = \OGAS\Database::getConnection();
                    
                    // Получаем данные для балансового расчёта
                    $stmt = $db->prepare("
                        SELECT COALESCE(SUM(nominal), 0) as total 
                        FROM bills 
                        WHERE holder_id = ? AND status IN ('active', 'paid')
                    ");
                    $stmt->execute([$userId]);
                    $balance = (float)$stmt->fetch(\PDO::FETCH_ASSOC)['total'];
                    
                    $stmt = $db->prepare("
                        SELECT COALESCE(SUM(nominal), 0) as total 
                        FROM bills 
                        WHERE issuer_id = ? AND status = 'active'
                    ");
                    $stmt->execute([$userId]);
                    $obligations = (float)$stmt->fetch(\PDO::FETCH_ASSOC)['total'];
                    
                    $balanceScore = $rating->getBalanceScore();
                    
                    $detailsHtml = '
                        <div class="metric-detail-section">
                            <h4>Формула расчёта</h4>
                            <div style="background: var(--bg-secondary); padding: 16px; border-radius: var(--radius-md); margin: 12px 0;">
                                <p style="margin: 0; font-family: monospace; font-size: 1.1em;">
                                    Балансовый расчёт = (Сумма векселей на счету / Сумма обязательств) × 100
                                </p>
                            </div>
                        </div>
                        <div class="metric-detail-section">
                            <h4>Исходные данные</h4>
                            <ul style="margin: 0; padding-left: 20px; line-height: 1.8;">
                                <li><strong>Вексели на счету:</strong> ' . number_format($balance, 2, '.', ' ') . ' ₽</li>
                                <li><strong>Сумма обязательств:</strong> ' . number_format($obligations, 2, '.', ' ') . ' ₽</li>
                                <li><strong>Расчёт:</strong> (' . number_format($balance, 2, '.', ' ') . ' / ' . number_format($obligations > 0 ? $obligations : 1, 2, '.', ' ') . ') × 100</li>
                                <li><strong>Результат:</strong> <strong style="color: var(--color-primary);">' . number_format($balanceScore, 2) . ' баллов</strong></li>
                            </ul>
                        </div>
                        <div class="metric-detail-section">
                            <h4>Интерпретация</h4>
                            <ul style="margin: 0; padding-left: 20px; line-height: 1.8;">
                                <li>Показывает соотношение между активами (полученными векселями) и обязательствами (выпущенными векселями)</li>
                                <li>Значение выше 100 означает, что у вас больше активов, чем обязательств</li>
                                <li>Значение ниже 100 означает, что обязательства превышают активы</li>
                                <li>Максимальное значение ограничено 200 баллами</li>
                            </ul>
                        </div>
                    ';
                    break;
                    
                case 'payment_discipline':
                    $db = \OGAS\Database::getConnection();
                    
                    // Получаем данные для дисциплины погашаемости
                    $stmt = $db->prepare("
                        SELECT COUNT(*) as count, COALESCE(SUM(nominal), 0) as total 
                        FROM bills 
                        WHERE issuer_id = ? AND status = 'paid'
                    ");
                    $stmt->execute([$userId]);
                    $paid = $stmt->fetch(\PDO::FETCH_ASSOC);
                    $paidCount = (int)$paid['count'];
                    $paidTotal = (float)$paid['total'];
                    
                    $stmt = $db->prepare("
                        SELECT COUNT(*) as count, COALESCE(SUM(nominal), 0) as total 
                        FROM bills 
                        WHERE issuer_id = ? AND status = 'overdue'
                    ");
                    $stmt->execute([$userId]);
                    $overdue = $stmt->fetch(\PDO::FETCH_ASSOC);
                    $overdueCount = (int)$overdue['count'];
                    $overdueTotal = (float)$overdue['total'];
                    
                    $total = $paidTotal + $overdueTotal;
                    $paymentDiscipline = $rating->getPaymentDiscipline();
                    
                    $detailsHtml = '
                        <div class="metric-detail-section">
                            <h4>Формула расчёта</h4>
                            <div style="background: var(--bg-secondary); padding: 16px; border-radius: var(--radius-md); margin: 12px 0;">
                                <p style="margin: 0; font-family: monospace; font-size: 1.1em;">
                                    Дисциплина = (Погашенные вексели / (Погашенные + Просроченные)) × 100%
                                </p>
                            </div>
                        </div>
                        <div class="metric-detail-section">
                            <h4>Исходные данные</h4>
                            <ul style="margin: 0; padding-left: 20px; line-height: 1.8;">
                                <li><strong>Погашенных векселей:</strong> ' . $paidCount . ' шт. (' . number_format($paidTotal, 2, '.', ' ') . ' ₽)</li>
                                <li><strong>Просроченных векселей:</strong> ' . $overdueCount . ' шт. (' . number_format($overdueTotal, 2, '.', ' ') . ' ₽)</li>
                                <li><strong>Всего обязательств:</strong> ' . ($paidCount + $overdueCount) . ' шт. (' . number_format($total, 2, '.', ' ') . ' ₽)</li>
                                <li><strong>Расчёт:</strong> (' . number_format($paidTotal, 2, '.', ' ') . ' / ' . number_format($total > 0 ? $total : 1, 2, '.', ' ') . ') × 100%</li>
                                <li><strong>Результат:</strong> <strong style="color: var(--color-primary);">' . number_format($paymentDiscipline, 2) . '%</strong></li>
                            </ul>
                        </div>
                        <div class="metric-detail-section">
                            <h4>Интерпретация</h4>
                            <ul style="margin: 0; padding-left: 20px; line-height: 1.8;">
                                <li>Показывает процент погашенных векселей от общего количества обязательств</li>
                                <li>100% означает, что все обязательства были выполнены в срок</li>
                                <li>Чем ниже процент, тем больше просроченных обязательств</li>
                                <li>Если нет обязательств, значение автоматически устанавливается в 100%</li>
                            </ul>
                        </div>
                    ';
                    break;
                    
                case 'early_payment':
                    $db = \OGAS\Database::getConnection();
                    
                    // Получаем данные для досрочного погашения
                    $stmt = $db->prepare("
                        SELECT issue_date, maturity_date, payment_date, nominal,
                               DATEDIFF(maturity_date, issue_date) as planned_days,
                               DATEDIFF(payment_date, issue_date) as actual_days
                        FROM bills 
                        WHERE issuer_id = ? AND status = 'paid' AND payment_date IS NOT NULL
                        ORDER BY payment_date DESC
                        LIMIT 10
                    ");
                    $stmt->execute([$userId]);
                    $bills = $stmt->fetchAll(\PDO::FETCH_ASSOC);
                    
                    $earlyPaymentAvg = $rating->getEarlyPaymentAvg();
                    
                    $billsList = '';
                    if (count($bills) > 0) {
                        $billsList = '<table style="width: 100%; border-collapse: collapse; margin-top: 12px;">
                            <thead>
                                <tr style="background: var(--bg-secondary);">
                                    <th style="padding: 8px; text-align: left; border-bottom: 2px solid var(--border-color);">Номинал</th>
                                    <th style="padding: 8px; text-align: left; border-bottom: 2px solid var(--border-color);">Запланировано дней</th>
                                    <th style="padding: 8px; text-align: left; border-bottom: 2px solid var(--border-color);">Фактически дней</th>
                                    <th style="padding: 8px; text-align: left; border-bottom: 2px solid var(--border-color);">Коэффициент</th>
                                </tr>
                            </thead>
                            <tbody>';
                        foreach ($bills as $bill) {
                            $plannedDays = (int)$bill['planned_days'];
                            $actualDays = (int)$bill['actual_days'];
                            $coefficient = $plannedDays > 0 && $actualDays <= $plannedDays ? ($plannedDays / max($actualDays, 1)) : ($actualDays > $plannedDays ? -($actualDays / $plannedDays) : 1.0);
                            $billsList .= '<tr>
                                <td style="padding: 8px; border-bottom: 1px solid var(--border-color-light);">' . number_format($bill['nominal'], 2, '.', ' ') . ' ₽</td>
                                <td style="padding: 8px; border-bottom: 1px solid var(--border-color-light);">' . $plannedDays . '</td>
                                <td style="padding: 8px; border-bottom: 1px solid var(--border-color-light);">' . $actualDays . '</td>
                                <td style="padding: 8px; border-bottom: 1px solid var(--border-color-light);">' . number_format($coefficient, 2) . '</td>
                            </tr>';
                        }
                        $billsList .= '</tbody></table>';
                    } else {
                        $billsList = '<p style="color: var(--text-secondary);">Нет данных о погашенных векселях.</p>';
                    }
                    
                    $detailsHtml = '
                        <div class="metric-detail-section">
                            <h4>Формула расчёта</h4>
                            <div style="background: var(--bg-secondary); padding: 16px; border-radius: var(--radius-md); margin: 12px 0;">
                                <p style="margin: 0; font-family: monospace; font-size: 1.1em;">
                                    Коэффициент = Запланированные дни / Фактические дни<br>
                                    Средний коэффициент = Сумма всех коэффициентов / Количество векселей
                                </p>
                            </div>
                        </div>
                        <div class="metric-detail-section">
                            <h4>Последние погашенные вексели</h4>
                            ' . $billsList . '
                        </div>
                        <div class="metric-detail-section">
                            <h4>Итоговый результат</h4>
                            <ul style="margin: 0; padding-left: 20px; line-height: 1.8;">
                                <li><strong>Средний коэффициент досрочного погашения:</strong> <strong style="color: var(--color-primary);">' . number_format($earlyPaymentAvg, 2) . '</strong></li>
                            </ul>
                        </div>
                        <div class="metric-detail-section">
                            <h4>Интерпретация</h4>
                            <ul style="margin: 0; padding-left: 20px; line-height: 1.8;">
                                <li>Коэффициент > 1 означает, что вексель был погашен раньше срока (чем больше, тем лучше)</li>
                                <li>Коэффициент = 1 означает погашение точно в срок</li>
                                <li>Коэффициент < 1 означает просрочку (приводит к снижению рейтинга)</li>
                                <li>Если нет погашенных векселей, значение устанавливается в 1.0</li>
                                <li>Минимальное значение ограничено 0.1</li>
                            </ul>
                        </div>
                    ';
                    break;
                    
                default:
                    $detailsHtml = '<p>Детализация для данного компонента недоступна.</p>';
                    break;
            }
            
            echo json_encode([
                'details' => $detailsHtml
            ], JSON_UNESCAPED_UNICODE);
            break;
            
        case 'rating_formula_details':
            // Детализация формул рейтинга
            $type = $_GET['type'] ?? '';
            $rating = Rating::findByUserId($userId);
            $detailsHtml = '';
            
            switch ($type) {
                case 'rating_formula':
                    $balanceScore = $rating->getBalanceScore();
                    $paymentDiscipline = $rating->getPaymentDiscipline();
                    $earlyPaymentAvg = $rating->getEarlyPaymentAvg();
                    $totalRating = $rating->getTotalRating();
                    
                    $detailsHtml = '
                        <div class="metric-detail-section">
                            <h4>Общая формула</h4>
                            <div style="background: var(--bg-secondary); padding: 16px; border-radius: var(--radius-md); margin: 12px 0;">
                                <p style="margin: 0; font-family: monospace; font-size: 1.2em; text-align: center;">
                                    Рейтинг = (Балансовый расчёт / 100) × Дисциплина погашаемости × Досрочное погашение
                                </p>
                            </div>
                        </div>
                        <div class="metric-detail-section">
                            <h4>Расчёт на текущий момент</h4>
                            <div style="background: var(--bg-secondary); padding: 16px; border-radius: var(--radius-md); margin: 12px 0;">
                                <p style="margin: 0; font-family: monospace; font-size: 1.1em; line-height: 1.8;">
                                    = (' . number_format($balanceScore, 2) . ' / 100) × ' . number_format($paymentDiscipline, 2) . ' × ' . number_format($earlyPaymentAvg, 2) . '<br>
                                    = ' . number_format($balanceScore / 100, 4) . ' × ' . number_format($paymentDiscipline, 2) . ' × ' . number_format($earlyPaymentAvg, 2) . '<br>
                                    = <strong style="color: var(--color-primary); font-size: 1.2em;">' . number_format($totalRating, 2) . ' баллов</strong>
                                </p>
                            </div>
                        </div>
                        <div class="metric-detail-section">
                            <h4>Компоненты формулы</h4>
                            <ul style="margin: 0; padding-left: 20px; line-height: 1.8;">
                                <li><strong>Балансовый расчёт:</strong> ' . number_format($balanceScore, 2) . ' баллов (нормализуется делением на 100)</li>
                                <li><strong>Дисциплина погашаемости:</strong> ' . number_format($paymentDiscipline, 2) . '% (показывает процент выполненных обязательств)</li>
                                <li><strong>Досрочное погашение:</strong> ' . number_format($earlyPaymentAvg, 2) . ' (коэффициент скорости выполнения)</li>
                            </ul>
                        </div>
                        <div class="metric-detail-section">
                            <h4>Как улучшить рейтинг</h4>
                            <ul style="margin: 0; padding-left: 20px; line-height: 1.8;">
                                <li>Увеличьте баланс векселей (получайте больше векселей, чем выпускаете)</li>
                                <li>Погашайте все обязательства в срок или досрочно</li>
                                <li>Избегайте просрочек по векселям</li>
                                <li>Стремитесь погашать вексели раньше установленного срока</li>
                            </ul>
                        </div>
                    ';
                    break;
                    
                case 'doubles_formula':
                    $totalRating = $rating->getTotalRating();
                    $doubles = $rating->getDoubles();
                    $totalBillsNominal = $rating->getTotalBillsNominal();
                    
                    $detailsHtml = '
                        <div class="metric-detail-section">
                            <h4>Общая формула</h4>
                            <div style="background: var(--bg-secondary); padding: 16px; border-radius: var(--radius-md); margin: 12px 0;">
                                <p style="margin: 0; font-family: monospace; font-size: 1.2em; text-align: center;">
                                    Дубли = Сумма векселей на счету × Рейтинг "Око"
                                </p>
                                <p style="margin: 8px 0 0 0; font-size: 0.9em; color: var(--text-muted); text-align: center;">
                                    Номинал векселя может быть любым
                                </p>
                            </div>
                        </div>
                        <div class="metric-detail-section">
                            <h4>Расчёт на текущий момент</h4>
                            <div style="background: var(--bg-secondary); padding: 16px; border-radius: var(--radius-md); margin: 12px 0;">
                                <p style="margin: 0; font-family: monospace; font-size: 1.1em; line-height: 1.8;">
                                    Сумма векселей на счету: <strong>' . number_format($totalBillsNominal, 2) . ' ₽</strong><br>
                                    Рейтинг "Око": <strong>' . number_format($totalRating, 2) . '</strong><br><br>
                                    = ' . number_format($totalBillsNominal, 2) . ' × ' . number_format($totalRating, 2) . '<br>
                                    = <strong style="color: var(--color-primary); font-size: 1.2em;">' . number_format($doubles, 2) . ' дублей</strong>
                                </p>
                            </div>
                        </div>
                        <div class="metric-detail-section">
                            <h4>Что такое дубли?</h4>
                            <ul style="margin: 0; padding-left: 20px; line-height: 1.8;">
                                <li><strong>Дубли</strong> — это репутационный капитал пользователя в системе "Око"</li>
                                <li>Показывает реальную стоимость вашей репутации в рублях</li>
                                <li>Базовый номинал векселя в системе составляет 1000 ₽</li>
                                <li>Чем выше ваш рейтинг, тем больше ваш репутационный капитал</li>
                            </ul>
                        </div>
                        <div class="metric-detail-section">
                            <h4>Примеры</h4>
                            <ul style="margin: 0; padding-left: 20px; line-height: 1.8;">
                                <li>Рейтинг 1.0 = 1,000 ₽ репутационного капитала</li>
                                <li>Рейтинг 2.0 = 2,000 ₽ репутационного капитала</li>
                                <li>Рейтинг 0.5 = 500 ₽ репутационного капитала</li>
                                <li>Ваш рейтинг ' . number_format($totalRating, 2) . ' = ' . number_format($doubles, 2) . ' ₽ репутационного капитала</li>
                            </ul>
                        </div>
                    ';
                    break;
                    
                default:
                    $detailsHtml = '<p>Детализация для данной формулы недоступна.</p>';
                    break;
            }
            
            echo json_encode([
                'details' => $detailsHtml
            ], JSON_UNESCAPED_UNICODE);
            break;
            
        default:
            http_response_code(400);
            echo json_encode(['error' => 'Invalid action']);
            break;
    }
} catch (\Exception $e) {
    http_response_code(500);
    echo json_encode(['error' => $e->getMessage()]);
}

