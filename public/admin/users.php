<?php
/**
 * Страница управления пользователями (админ-панель)
 */

require_once __DIR__ . '/../../src/bootstrap.php';

use OGAS\Services\Auth;
use OGAS\Services\AdminService;
use OGAS\Models\User;
use OGAS\Core\Session;
use OGAS\Core\Security;

Auth::requireAuth();
AdminService::requireAdmin();

$error = Session::getFlash('error');
$success = Session::getFlash('success');

// Обработка действий
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    // CSRF защита
    if (!Security::checkCsrfToken()) {
        Session::flash('error', 'Ошибка безопасности. Обновите страницу и попробуйте снова.');
        header('Location: /admin/users.php');
        exit;
    }
    
    $action = $_POST['action'] ?? '';
    $userId = (int)($_POST['user_id'] ?? 0);
    
    try {
        $targetUser = User::findById($userId);
        if (!$targetUser || $targetUser->isSystem()) {
            throw new \Exception('Пользователь не найден');
        }
        
        switch ($action) {
            case 'toggle_active':
                $targetUser->setActive(!$targetUser->isActive());
                $targetUser->save();
                Session::flash('success', 'Статус пользователя обновлён');
                break;
            case 'toggle_admin':
                $targetUser->setIsAdmin(!$targetUser->isAdmin());
                $targetUser->save();
                Session::flash('success', 'Права администратора обновлены');
                break;
            default:
                throw new \Exception('Неизвестное действие');
        }
        
        header('Location: /admin/users.php');
        exit;
    } catch (\Exception $e) {
        Session::flash('error', 'Ошибка: ' . $e->getMessage());
        header('Location: /admin/users.php');
        exit;
    }
}

// Параметры пагинации и поиска
$page = max(1, (int)($_GET['page'] ?? 1));
$perPage = 20;
$search = trim($_GET['search'] ?? '');

$totalUsers = AdminService::getUsersCount($search);
$totalPages = ceil($totalUsers / $perPage);
$offset = ($page - 1) * $perPage;

$users = AdminService::getAllUsers($perPage, $offset, $search);

$title = 'Управление пользователями';
ob_start();
?>
<div class="dashboard">
    <div class="dashboard-header">
        <h2>Управление пользователями</h2>
        <div class="header-actions">
            <a href="/admin/index.php" class="btn btn-secondary">← Назад</a>
        </div>
    </div>

    <?php if ($error): ?>
        <div class="alert alert-error"><?= htmlspecialchars($error) ?></div>
    <?php endif; ?>

    <?php if ($success): ?>
        <div class="alert alert-success"><?= htmlspecialchars($success) ?></div>
    <?php endif; ?>

    <!-- Поиск -->
    <div class="filters">
        <form method="GET" action="" style="display: flex; gap: 10px; align-items: flex-end;">
            <div class="form-group" style="flex: 1;">
                <label for="search">Поиск по имени или email:</label>
                <input type="text" id="search" name="search"
                       value="<?= htmlspecialchars($search) ?>"
                       placeholder="Введите имя или email...">
            </div>
            <button type="submit" class="btn btn-primary">Найти</button>
            <?php if ($search): ?>
                <a href="/admin/users.php" class="btn btn-secondary">Сбросить</a>
            <?php endif; ?>
        </form>
    </div>

    <!-- Статистика -->
    <div class="info-card">
        <p><strong>Всего пользователей:</strong> <?= $totalUsers ?></p>
    </div>

    <!-- Таблица пользователей -->
    <div class="info-card">
        <h3>Пользователи</h3>
        <?php if (empty($users)): ?>
            <p class="text-muted">Пользователи не найдены</p>
        <?php else: ?>
            <table class="transactions-table">
                <thead>
                    <tr>
                        <th>ID</th>
                        <th>Имя</th>
                        <th>Email</th>
                        <th>Тип</th>
                        <th>Статус</th>
                        <th>Админ</th>
                        <th>Дата регистрации</th>
                        <th>Действия</th>
                    </tr>
                </thead>
                <tbody>
                    <?php foreach ($users as $targetUser): ?>
                        <tr>
                            <td>#<?= $targetUser->getId() ?></td>
                            <td><?= htmlspecialchars($targetUser->getFullName()) ?></td>
                            <td><?= htmlspecialchars($targetUser->getEmail()) ?></td>
                            <td><?= $targetUser->getUserType() === 'legal' ? 'Юр. лицо' : 'Физ. лицо' ?></td>
                            <td>
                                <?php if ($targetUser->isActive()): ?>
                                    <span class="status status-active">Активен</span>
                                <?php else: ?>
                                    <span class="status status-cancelled">Неактивен</span>
                                <?php endif; ?>
                            </td>
                            <td>
                                <?php if ($targetUser->isAdmin()): ?>
                                    <span class="status status-active">Да</span>
                                <?php else: ?>
                                    <span class="status">Нет</span>
                                <?php endif; ?>
                            </td>
                            <td><?= $targetUser->getCreatedAt() ? date('d.m.Y', strtotime($targetUser->getCreatedAt())) : '-' ?></td>
                            <td>
                                <form method="POST" style="display:inline;" onsubmit="return confirm('Изменить статус активности?');">
                                    <?= csrf_field() ?>
                                    <input type="hidden" name="action" value="toggle_active">
                                    <input type="hidden" name="user_id" value="<?= $targetUser->getId() ?>">
                                    <button type="submit" class="btn btn-small">
                                        <?= $targetUser->isActive() ? 'Деактивировать' : 'Активировать' ?>
                                    </button>
                                </form>
                                <form method="POST" style="display:inline; margin-left: 5px;" onsubmit="return confirm('Изменить права администратора?');">
                                    <?= csrf_field() ?>
                                    <input type="hidden" name="action" value="toggle_admin">
                                    <input type="hidden" name="user_id" value="<?= $targetUser->getId() ?>">
                                    <button type="submit" class="btn btn-small">
                                        <?= $targetUser->isAdmin() ? 'Убрать админ' : 'Сделать админом' ?>
                                    </button>
                                </form>
                            </td>
                        </tr>
                    <?php endforeach; ?>
                </tbody>
            </table>

            <!-- Пагинация -->
            <?php if ($totalPages > 1): ?>
                <div class="pagination">
                    <?php if ($page > 1): ?>
                        <a href="?page=<?= $page - 1 ?><?= $search ? '&search=' . urlencode($search) : '' ?>" class="btn btn-secondary">← Назад</a>
                    <?php endif; ?>
                    
                    <span>Страница <?= $page ?> из <?= $totalPages ?></span>
                    
                    <?php if ($page < $totalPages): ?>
                        <a href="?page=<?= $page + 1 ?><?= $search ? '&search=' . urlencode($search) : '' ?>" class="btn btn-secondary">Вперёд →</a>
                    <?php endif; ?>
                </div>
            <?php endif; ?>
        <?php endif; ?>
    </div>
</div>

<style>
.pagination {
    display: flex;
    justify-content: center;
    align-items: center;
    gap: 15px;
    margin-top: 20px;
    padding: 15px;
}
</style>

<?php
$content = ob_get_clean();
include __DIR__ . '/../../templates/base.php';
?>








