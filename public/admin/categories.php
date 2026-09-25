<?php
/**
 * Страница управления категориями (админ-панель)
 * Эта страница является расширенной версией обычной страницы категорий
 */

require_once __DIR__ . '/../../src/bootstrap.php';

use OGAS\Services\Auth;
use OGAS\Services\AdminService;
use OGAS\Models\Category;
use OGAS\Models\Transaction;
use OGAS\Core\Session;
use OGAS\Core\Security;

Auth::requireAuth();
AdminService::requireAdmin();

$error = Session::getFlash('error');
$success = Session::getFlash('success');

// Обработка POST запросов
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    // CSRF защита
    if (!Security::checkCsrfToken()) {
        Session::flash('error', 'Ошибка безопасности. Обновите страницу и попробуйте снова.');
        header('Location: /admin/categories.php');
        exit;
    }
    
    $action = $_POST['action'] ?? '';
    $id = (int)($_POST['id'] ?? 0);
    $name = trim($_POST['name'] ?? '');
    $description = trim($_POST['description'] ?? '');
    $icon = trim($_POST['icon'] ?? '');
    $parentId = (int)($_POST['parent_id'] ?? 0);
    $sortOrder = (int)($_POST['sort_order'] ?? 0);
    $isActive = isset($_POST['is_active']) ? 1 : 0;

    try {
        switch ($action) {
            case 'create':
                if (empty($name)) {
                    throw new \Exception('Название категории обязательно.');
                }
                Category::create([
                    'name' => $name,
                    'description' => $description ?: null,
                    'icon' => $icon ?: null,
                    'parent_id' => $parentId > 0 ? $parentId : null,
                    'sort_order' => $sortOrder,
                    'is_active' => $isActive
                ]);
                Session::flash('success', 'Категория успешно создана!');
                header('Location: /admin/categories.php');
                exit;
            case 'update':
                if ($id <= 0 || empty($name)) {
                    throw new \Exception('Неверные данные для обновления категории.');
                }
                $category = Category::findById($id);
                if (!$category) {
                    throw new \Exception('Категория не найдена.');
                }
                $category->setName($name);
                $category->setDescription($description ?: null);
                $category->setIcon($icon ?: null);
                $category->setParentId($parentId > 0 ? $parentId : null);
                $category->setSortOrder($sortOrder);
                $category->setIsActive($isActive);
                $category->save();
                Session::flash('success', 'Категория успешно обновлена!');
                header('Location: /admin/categories.php');
                exit;
            case 'delete':
                if ($id <= 0) {
                    throw new \Exception('Неверный ID категории для удаления.');
                }
                $category = Category::findById($id);
                if (!$category) {
                    throw new \Exception('Категория не найдена.');
                }
                if ($category->getUsageCount() > 0) {
                    throw new \Exception('Невозможно удалить категорию, которая используется в транзакциях.');
                }
                $category->delete();
                Session::flash('success', 'Категория успешно удалена!');
                header('Location: /admin/categories.php');
                exit;
        }
    } catch (\Exception $e) {
        $error = 'Ошибка: ' . $e->getMessage();
    }
}

// Получаем все категории
$categories = Category::getAllActive(0); // 0 = все категории

// Получаем статистику использования категорий
$db = \OGAS\Database::getConnection();
$stmt = $db->query("SELECT category, COUNT(*) as count FROM transactions WHERE category IS NOT NULL GROUP BY category");
$categoryUsage = [];
while ($row = $stmt->fetch(\PDO::FETCH_ASSOC)) {
    $categoryUsage[$row['category']] = (int)$row['count'];
}

// Редактирование (если передан ID)
$editCategory = null;
if (isset($_GET['edit'])) {
    $editCategory = Category::findById((int)$_GET['edit']);
}

$title = 'Управление категориями - Админ-панель';
ob_start();
?>
<div class="dashboard">
    <div class="dashboard-header">
        <h2>Управление категориями</h2>
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

    <!-- Форма создания/редактирования -->
    <div class="info-card">
        <h3><?= $editCategory ? 'Редактировать категорию' : 'Создать новую категорию' ?></h3>
        <form method="POST" action="">
            <?= csrf_field() ?>
            <?php if ($editCategory): ?>
                <input type="hidden" name="action" value="update">
                <input type="hidden" name="id" value="<?= $editCategory->getId() ?>">
            <?php else: ?>
                <input type="hidden" name="action" value="create">
            <?php endif; ?>

            <div class="form-group">
                <label for="name">Название категории *:</label>
                <input type="text" id="name" name="name" required
                       value="<?= htmlspecialchars($editCategory ? $editCategory->getName() : '') ?>"
                       placeholder="Например: Продукты питания">
            </div>

            <div class="form-group">
                <label for="description">Описание:</label>
                <textarea id="description" name="description" rows="3"
                          placeholder="Описание категории"><?= htmlspecialchars($editCategory ? ($editCategory->getDescription() ?? '') : '') ?></textarea>
            </div>

            <div class="form-group">
                <label for="icon">Иконка (emoji или CSS класс):</label>
                <input type="text" id="icon" name="icon"
                       value="<?= htmlspecialchars($editCategory ? ($editCategory->getIcon() ?? '') : '') ?>"
                       placeholder="Например: 🍎 или fa-apple">
            </div>

            <div class="form-group">
                <label for="parent_id">Родительская категория:</label>
                <select id="parent_id" name="parent_id">
                    <option value="0">Нет (верхний уровень)</option>
                    <?php foreach (Category::getAllActive(0) as $cat): ?>
                        <?php if (!$editCategory || $cat->getId() !== $editCategory->getId()): ?>
                            <option value="<?= $cat->getId() ?>"
                                    <?= ($editCategory && $editCategory->getParentId() === $cat->getId()) ? 'selected' : '' ?>>
                                <?= htmlspecialchars($cat->getName()) ?>
                            </option>
                        <?php endif; ?>
                    <?php endforeach; ?>
                </select>
            </div>

            <div class="form-group">
                <label for="sort_order">Порядок сортировки:</label>
                <input type="number" id="sort_order" name="sort_order"
                       value="<?= htmlspecialchars($editCategory ? $editCategory->getSortOrder() : '0') ?>">
            </div>

            <div class="form-group form-check">
                <input type="checkbox" id="is_active" name="is_active" value="1"
                       <?= ($editCategory === null || $editCategory->isActive()) ? 'checked' : '' ?>>
                <label for="is_active">Активна</label>
            </div>

            <div class="form-actions">
                <button type="submit" class="btn btn-primary"><?= $editCategory ? 'Сохранить изменения' : 'Создать категорию' ?></button>
                <?php if ($editCategory): ?>
                    <a href="/admin/categories.php" class="btn btn-secondary">Отмена</a>
                <?php endif; ?>
            </div>
        </form>
    </div>

    <!-- Список категорий -->
    <div class="info-card">
        <h3>Все категории (<?= count($categories) ?>)</h3>

        <?php if (empty($categories)): ?>
            <p class="text-muted">Категории не найдены</p>
        <?php else: ?>
            <table class="transactions-table">
                <thead>
                    <tr>
                        <th>Иконка</th>
                        <th>Название</th>
                        <th>Описание</th>
                        <th>Родитель</th>
                        <th>Использований</th>
                        <th>Порядок</th>
                        <th>Статус</th>
                        <th>Действия</th>
                    </tr>
                </thead>
                <tbody>
                    <?php foreach ($categories as $category): ?>
                        <?php
                        $parent = $category->getParentId() ? Category::findById($category->getParentId()) : null;
                        $usageCount = $categoryUsage[$category->getName()] ?? 0;
                        ?>
                        <tr>
                            <td><?= htmlspecialchars($category->getIcon() ?? '📦') ?></td>
                            <td><strong><?= htmlspecialchars($category->getName()) ?></strong></td>
                            <td><?= htmlspecialchars(mb_substr($category->getDescription() ?? '', 0, 50)) ?><?= mb_strlen($category->getDescription() ?? '') > 50 ? '...' : '' ?></td>
                            <td><?= $parent ? htmlspecialchars($parent->getName()) : '-' ?></td>
                            <td class="text-center"><?= $usageCount ?></td>
                            <td class="text-center"><?= $category->getSortOrder() ?></td>
                            <td>
                                <?php if ($category->isActive()): ?>
                                    <span class="status status-active">Активна</span>
                                <?php else: ?>
                                    <span class="status status-cancelled">Неактивна</span>
                                <?php endif; ?>
                            </td>
                            <td>
                                <a href="/admin/categories.php?edit=<?= $category->getId() ?>"
                                   class="btn btn-small">Редактировать</a>
                                <?php if ($usageCount === 0): ?>
                                    <form method="POST" style="display:inline; margin-left: 5px;"
                                          onsubmit="return confirm('Удалить категорию?');">
                                        <?= csrf_field() ?>
                                        <input type="hidden" name="action" value="delete">
                                        <input type="hidden" name="id" value="<?= $category->getId() ?>">
                                        <button type="submit" class="btn btn-small" style="background:#dc3545;color:white;">Удалить</button>
                                    </form>
                                <?php endif; ?>
                            </td>
                        </tr>
                    <?php endforeach; ?>
                </tbody>
            </table>
        <?php endif; ?>
    </div>
</div>

<?php
$content = ob_get_clean();
include __DIR__ . '/../../templates/base.php';
?>








