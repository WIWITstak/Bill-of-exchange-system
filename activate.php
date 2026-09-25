<?php
/**
 * Страница активации аккаунта через сделку с ОГАС
 */

require_once __DIR__ . '/../src/bootstrap.php';

use OGAS\Services\Auth;
use OGAS\Models\User;
use OGAS\Models\Transaction;
use OGAS\Services\TransactionService;
use OGAS\Services\ChatService;

Auth::requireAuth();
$user = Auth::user();

$error = '';
$success = '';

// Если уже активирован, редирект
if ($user->isActive()) {
    header('Location: /dashboard.php');
    exit;
}

// Ищем транзакцию активации с системным пользователем
$systemUser = User::getOrCreateSystemUser();
$activationTransactions = Transaction::findByUser($user->getId(), 'pending');
$activationTransaction = null;

foreach ($activationTransactions as $t) {
    if (($t->getSellerId() === $user->getId() && $t->getBuyerId() === $systemUser->getId()) ||
        ($t->getBuyerId() === $user->getId() && $t->getSellerId() === $systemUser->getId())) {
        $activationTransaction = $t;
        break;
    }
}

// Если транзакции активации нет, создаём её
if (!$activationTransaction) {
    try {
               // Рассчитываем сумму подписки для описания транзакции
               $subscriptionAmount = \OGAS\Services\SubscriptionService::calculateAmount($user);
               
               // Создаём транзакцию активации
               $activationTransaction = TransactionService::create([
                   'seller_id' => $user->getId(),
                   'buyer_id' => $systemUser->getId(),
                   'description' => sprintf(
                       'Активация аккаунта пользователя %s в системе ОГАС. Подписка на сумму %s ₽ на 30 дней.',
                       $user->getFullName(),
                       number_format($subscriptionAmount, 2, '.', ' ')
                   ),
                   'category' => 'Активация аккаунта',
                   'transaction_type' => 'barter'
               ]);
        
        // Системный пользователь подтверждает сделку вручную через админ-панель
        // Администратор должен проверить пользователя и подтвердить сделку
        
        // Создаём приветственное сообщение
        try {
                   ChatService::sendMessage(
                       $activationTransaction->getId(),
                       $systemUser->getId(),
                       sprintf(
                           "Добро пожаловать в систему ОГАС, %s!\n\n" .
                           "В этой сделке заключается подписка на сумму %s ₽ на 30 дней.\n\n" .
                           "Для активации вашего аккаунта и получения доступа ко всем функциям системы, " .
                           "необходимо подтверждение этой сделки с обеих сторон:\n" .
                           "1. Вы должны подтвердить сделку, нажав кнопку 'Подтвердить сделку'\n" .
                           "2. Администратор ОГАС проверит вашу заявку и подтвердит сделку со своей стороны\n\n" .
                           "После подтверждения сделки обоими участниками ваш аккаунт будет активирован, " .
                           "подписка будет оформлена, и вы сможете:\n" .
                           "• Создавать транзакции с другими пользователями\n" .
                           "• Выпускать и получать вексели\n" .
                           "• Участвовать в системе взаимных гарантий\n" .
                           "• Использовать все возможности платформы ОГАС",
                           $user->getFullName(),
                           number_format($subscriptionAmount, 2, '.', ' ')
                       )
                   );
        } catch (\Exception $e) {
            // Если не удалось отправить сообщение, это не критично
        }
    } catch (\Exception $e) {
        $error = 'Ошибка при создании транзакции активации: ' . $e->getMessage();
    }
}

// Если есть транзакция активации, перенаправляем в чат
if ($activationTransaction) {
    header('Location: /transactions/chat.php?id=' . $activationTransaction->getId());
    exit;
}

$title = 'Активация аккаунта';
ob_start();
?>
<div class="dashboard">
    <div class="dashboard-header">
        <h2>Активация аккаунта</h2>
    </div>
    
    <?php if ($error): ?>
        <div class="alert alert-error"><?= htmlspecialchars($error) ?></div>
        <a href="/dashboard.php" class="btn btn-secondary">← В кабинет</a>
    <?php else: ?>
        <div class="activate-info">
            <h3>Активация аккаунта</h3>
            <p>Перенаправление в чат для активации аккаунта...</p>
            <p>Если перенаправление не произошло автоматически, <a href="/transactions.php">перейдите к транзакциям</a>.</p>
        </div>
    <?php endif; ?>
</div>
<?php
$content = ob_get_clean();
include __DIR__ . '/../templates/base.php';

