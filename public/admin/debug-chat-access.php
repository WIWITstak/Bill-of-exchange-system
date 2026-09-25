<?php
/**
 * Диагностический скрипт для проверки доступа к чату
 */

require_once __DIR__ . '/../../src/bootstrap.php';

use OGAS\Services\Auth;
use OGAS\Services\AdminService;
use OGAS\Models\Transaction;
use OGAS\Models\User;
use OGAS\Services\ChatService;

Auth::requireAuth();
AdminService::requireAdmin();

$user = Auth::user();

$transactionId = (int)($_GET['transaction_id'] ?? 0);
$testUserId = (int)($_GET['user_id'] ?? 0);

$title = 'Диагностика доступа к чату';
ob_start();
?>

<div class="dashboard">
    <div class="dashboard-header">
        <h2>Диагностика доступа к чату</h2>
        <a href="/admin/index.php" class="btn btn-secondary">← Назад</a>
    </div>

    <div style="background: white; padding: 20px; border-radius: 8px; margin-top: 20px;">
        <form method="GET" style="margin-bottom: 30px;">
            <div style="display: grid; grid-template-columns: 1fr 1fr; gap: 15px; margin-bottom: 15px;">
                <div>
                    <label>ID транзакции:</label>
                    <input type="number" name="transaction_id" value="<?= htmlspecialchars($transactionId) ?>" required style="width: 100%; padding: 8px;">
                </div>
                <div>
                    <label>ID пользователя для проверки (опционально):</label>
                    <input type="number" name="user_id" value="<?= htmlspecialchars($testUserId) ?>" style="width: 100%; padding: 8px;">
                </div>
            </div>
            <button type="submit" class="btn btn-primary">Проверить</button>
        </form>

        <?php if ($transactionId > 0): ?>
            <?php
            $transaction = Transaction::findById($transactionId);
            if (!$transaction):
            ?>
                <div class="alert alert-error">
                    <strong>Ошибка:</strong> Транзакция #<?= $transactionId ?> не найдена в базе данных.
                </div>
            <?php else: ?>
                <?php
                $seller = User::findById($transaction->getSellerId());
                $buyer = User::findById($transaction->getBuyerId());
                ?>

                <h3>Информация о транзакции #<?= $transactionId ?></h3>
                
                <table style="width: 100%; border-collapse: collapse; margin-bottom: 30px;">
                    <tr>
                        <td style="padding: 8px; border: 1px solid #ddd; background: #f9f9f9; font-weight: bold;">ID транзакции</td>
                        <td style="padding: 8px; border: 1px solid #ddd;"><?= $transaction->getId() ?></td>
                    </tr>
                    <tr>
                        <td style="padding: 8px; border: 1px solid #ddd; background: #f9f9f9; font-weight: bold;">Статус</td>
                        <td style="padding: 8px; border: 1px solid #ddd;"><?= htmlspecialchars($transaction->getStatus()) ?></td>
                    </tr>
                    <tr>
                        <td style="padding: 8px; border: 1px solid #ddd; background: #f9f9f9; font-weight: bold;">Тип транзакции</td>
                        <td style="padding: 8px; border: 1px solid #ddd;"><?= htmlspecialchars($transaction->getTransactionType()) ?></td>
                    </tr>
                    <tr>
                        <td style="padding: 8px; border: 1px solid #ddd; background: #f9f9f9; font-weight: bold;">Продавец (seller_id)</td>
                        <td style="padding: 8px; border: 1px solid #ddd;">
                            ID: <?= $transaction->getSellerId() ?> 
                            <?php if ($seller): ?>
                                | <?= htmlspecialchars($seller->getFullName()) ?> 
                                (<?= htmlspecialchars($seller->getEmail()) ?>)
                                | Тип ID: <?= gettype($seller->getId()) ?>
                            <?php else: ?>
                                | <span style="color: red;">Пользователь не найден!</span>
                            <?php endif; ?>
                        </td>
                    </tr>
                    <tr>
                        <td style="padding: 8px; border: 1px solid #ddd; background: #f9f9f9; font-weight: bold;">Покупатель (buyer_id)</td>
                        <td style="padding: 8px; border: 1px solid #ddd;">
                            ID: <?= $transaction->getBuyerId() ?> 
                            <?php if ($buyer): ?>
                                | <?= htmlspecialchars($buyer->getFullName()) ?> 
                                (<?= htmlspecialchars($buyer->getEmail()) ?>)
                            <?php else: ?>
                                | <span style="color: red;">Пользователь не найден!</span>
                            <?php endif; ?>
                        </td>
                    </tr>
                    <tr>
                        <td style="padding: 8px; border: 1px solid #ddd; background: #f9f9f9; font-weight: bold;">Продавец подтвердил</td>
                        <td style="padding: 8px; border: 1px solid #ddd;"><?= $transaction->isSellerConfirmed() ? '✅ Да' : '❌ Нет' ?></td>
                    </tr>
                    <tr>
                        <td style="padding: 8px; border: 1px solid #ddd; background: #f9f9f9; font-weight: bold;">Покупатель подтвердил</td>
                        <td style="padding: 8px; border: 1px solid #ddd;"><?= $transaction->isBuyerConfirmed() ? '✅ Да' : '❌ Нет' ?></td>
                    </tr>
                    <tr>
                        <td style="padding: 8px; border: 1px solid #ddd; background: #f9f9f9; font-weight: bold;">Оба подтвердили</td>
                        <td style="padding: 8px; border: 1px solid #ddd;"><?= $transaction->isBothConfirmed() ? '✅ Да' : '❌ Нет' ?></td>
                    </tr>
                </table>

                <h3>Проверка доступа пользователей</h3>

                <table style="width: 100%; border-collapse: collapse; margin-bottom: 30px;">
                    <thead>
                        <tr>
                            <th style="padding: 8px; border: 1px solid #ddd; background: #f0f0f0;">Пользователь</th>
                            <th style="padding: 8px; border: 1px solid #ddd; background: #f0f0f0;">ID</th>
                            <th style="padding: 8px; border: 1px solid #ddd; background: #f0f0f0;">Доступ к чату</th>
                            <th style="padding: 8px; border: 1px solid #ddd; background: #f0f0f0;">Причина</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php if ($seller): ?>
                        <tr>
                            <td style="padding: 8px; border: 1px solid #ddd;">
                                <?= htmlspecialchars($seller->getFullName()) ?><br>
                                <small><?= htmlspecialchars($seller->getEmail()) ?></small>
                            </td>
                            <td style="padding: 8px; border: 1px solid #ddd;">
                                <?= $seller->getId() ?><br>
                                <small>Тип: <?= gettype($seller->getId()) ?></small>
                            </td>
                            <td style="padding: 8px; border: 1px solid #ddd;">
                                <?php
                                $hasAccess = ChatService::checkAccess($transactionId, $seller->getId());
                                echo $hasAccess ? '✅ <strong>Есть доступ</strong>' : '❌ <strong>Нет доступа</strong>';
                                ?>
                            </td>
                            <td style="padding: 8px; border: 1px solid #ddd;">
                                <?php if ($hasAccess): ?>
                                    Пользователь является продавцом или покупателем
                                <?php else: ?>
                                    <span style="color: red;">
                                        sellerId (<?= $transaction->getSellerId() ?>, тип: <?= gettype($transaction->getSellerId()) ?>) !== userId (<?= $seller->getId() ?>, тип: <?= gettype($seller->getId()) ?>)<br>
                                        buyerId (<?= $transaction->getBuyerId() ?>, тип: <?= gettype($transaction->getBuyerId()) ?>) !== userId (<?= $seller->getId() ?>, тип: <?= gettype($seller->getId()) ?>)
                                    </span>
                                <?php endif; ?>
                            </td>
                        </tr>
                        <?php endif; ?>

                        <?php if ($buyer): ?>
                        <tr>
                            <td style="padding: 8px; border: 1px solid #ddd;">
                                <?= htmlspecialchars($buyer->getFullName()) ?><br>
                                <small><?= htmlspecialchars($buyer->getEmail()) ?></small>
                            </td>
                            <td style="padding: 8px; border: 1px solid #ddd;">
                                <?= $buyer->getId() ?><br>
                                <small>Тип: <?= gettype($buyer->getId()) ?></small>
                            </td>
                            <td style="padding: 8px; border: 1px solid #ddd;">
                                <?php
                                $hasAccess = ChatService::checkAccess($transactionId, $buyer->getId());
                                echo $hasAccess ? '✅ <strong>Есть доступ</strong>' : '❌ <strong>Нет доступа</strong>';
                                ?>
                            </td>
                            <td style="padding: 8px; border: 1px solid #ddd;">
                                <?php if ($hasAccess): ?>
                                    Пользователь является продавцом или покупателем
                                <?php else: ?>
                                    <span style="color: red;">
                                        sellerId (<?= $transaction->getSellerId() ?>, тип: <?= gettype($transaction->getSellerId()) ?>) !== userId (<?= $buyer->getId() ?>, тип: <?= gettype($buyer->getId()) ?>)<br>
                                        buyerId (<?= $transaction->getBuyerId() ?>, тип: <?= gettype($transaction->getBuyerId()) ?>) !== userId (<?= $buyer->getId() ?>, тип: <?= gettype($buyer->getId()) ?>)
                                    </span>
                                <?php endif; ?>
                            </td>
                        </tr>
                        <?php endif; ?>

                        <?php if ($testUserId > 0 && $testUserId !== $seller->getId() && $testUserId !== $buyer->getId()): ?>
                            <?php
                            $testUser = User::findById($testUserId);
                            if ($testUser):
                            ?>
                            <tr style="background: #fff3cd;">
                                <td style="padding: 8px; border: 1px solid #ddd;">
                                    <?= htmlspecialchars($testUser->getFullName()) ?><br>
                                    <small><?= htmlspecialchars($testUser->getEmail()) ?></small>
                                </td>
                                <td style="padding: 8px; border: 1px solid #ddd;">
                                    <?= $testUser->getId() ?><br>
                                    <small>Тип: <?= gettype($testUser->getId()) ?></small>
                                </td>
                                <td style="padding: 8px; border: 1px solid #ddd;">
                                    <?php
                                    $hasAccess = ChatService::checkAccess($transactionId, $testUser->getId());
                                    echo $hasAccess ? '✅ <strong>Есть доступ</strong>' : '❌ <strong>Нет доступа</strong>';
                                    ?>
                                </td>
                                <td style="padding: 8px; border: 1px solid #ddd;">
                                    <?php if ($hasAccess): ?>
                                        Пользователь является продавцом или покупателем
                                    <?php else: ?>
                                        <span style="color: red;">
                                            Пользователь не является участником транзакции<br>
                                            sellerId: <?= $transaction->getSellerId() ?>, buyerId: <?= $transaction->getBuyerId() ?>, userId: <?= $testUser->getId() ?>
                                        </span>
                                    <?php endif; ?>
                                </td>
                            </tr>
                            <?php endif; ?>
                        <?php endif; ?>
                    </tbody>
                </table>

                <h3>Детальная отладочная информация</h3>
                <pre style="background: #f5f5f5; padding: 15px; border-radius: 4px; overflow-x: auto;"><?php
echo "=== Сравнение типов ===\n";
echo "sellerId: " . var_export($transaction->getSellerId(), true) . " (тип: " . gettype($transaction->getSellerId()) . ")\n";
echo "buyerId: " . var_export($transaction->getBuyerId(), true) . " (тип: " . gettype($transaction->getBuyerId()) . ")\n";
if ($seller) {
    echo "seller->getId(): " . var_export($seller->getId(), true) . " (тип: " . gettype($seller->getId()) . ")\n";
    echo "Сравнение sellerId === seller->getId(): " . var_export($transaction->getSellerId() === $seller->getId(), true) . "\n";
}
if ($buyer) {
    echo "buyer->getId(): " . var_export($buyer->getId(), true) . " (тип: " . gettype($buyer->getId()) . ")\n";
    echo "Сравнение buyerId === buyer->getId(): " . var_export($transaction->getBuyerId() === $buyer->getId(), true) . "\n";
}
?></pre>

                <div style="margin-top: 30px;">
                    <h4>Прямые ссылки на чат:</h4>
                    <ul>
                        <?php if ($seller): ?>
                        <li>
                            <a href="/transactions/chat.php?id=<?= $transactionId ?>&embed=1" target="_blank">
                                Чат для продавца (<?= htmlspecialchars($seller->getFullName()) ?>)
                            </a>
                        </li>
                        <?php endif; ?>
                        <?php if ($buyer): ?>
                        <li>
                            <a href="/transactions/chat.php?id=<?= $transactionId ?>&embed=1" target="_blank">
                                Чат для покупателя (<?= htmlspecialchars($buyer->getFullName()) ?>)
                            </a>
                        </li>
                        <?php endif; ?>
                    </ul>
                </div>
            <?php endif; ?>
        <?php endif; ?>
    </div>
</div>

<?php
$content = ob_get_clean();
include __DIR__ . '/../../templates/base.php';
?>

