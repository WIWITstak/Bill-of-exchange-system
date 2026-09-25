<?php
/**
 * Страница управления категориями
 */

require_once __DIR__ . '/../src/bootstrap.php';

use OGAS\Services\Auth;
use OGAS\Models\Category;
use OGAS\Core\Session;

Auth::requireAuth();
$user = Auth::user();

if (!$user) {
    Auth::logout();
    header('Location: /login.php');
    exit;
}

$error = Session::getFlash('error');
$success = Session::getFlash('success');

// Обработка действий
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    // CSRF защита
    if (!\OGAS\Core\Security::checkCsrfToken()) {
        Session::flash('error', 'Ошибка безопасности. Обновите страницу и попробуйте снова.');
        header('Location: /categories.php');
        exit;
    }
    
    $action = $_POST['action'] ?? '';
    
    try {
        switch ($action) {
            case 'create':
                $name = trim($_POST['name'] ?? '');
                $description = trim($_POST['description'] ?? '');
                $icon = trim($_POST['icon'] ?? '');
                $parentId = !empty($_POST['parent_id']) ? (int)$_POST['parent_id'] : null;
                $sortOrder = (int)($_POST['sort_order'] ?? 0);
                
                if (empty($name)) {
                    $error = 'Название категории обязательно';
                } elseif (Category::findByName($name)) {
                    $error = 'Категория с таким названием уже существует';
                } else {
                    Category::create([
                        'name' => $name,
                        'description' => $description ?: null,
                        'icon' => $icon ?: null,
                        'parent_id' => $parentId,
                        'sort_order' => $sortOrder
                    ]);
                    Session::flash('success', 'Категория успешно создана!');
                    header('Location: /categories.php');
                    exit;
                }
                break;
                
            case 'update':
                $id = (int)($_POST['id'] ?? 0);
                $category = Category::findById($id);
                
                if (!$category) {
                    $error = 'Категория не найдена';
                } else {
                    $name = trim($_POST['name'] ?? '');
                    $description = trim($_POST['description'] ?? '');
                    $icon = trim($_POST['icon'] ?? '');
                    $parentId = !empty($_POST['parent_id']) ? (int)$_POST['parent_id'] : null;
                    $sortOrder = (int)($_POST['sort_order'] ?? 0);
                    $isActive = isset($_POST['is_active']);
                    
                    if (empty($name)) {
                        $error = 'Название категории обязательно';
                    } else {
                        // Проверяем уникальность имени (кроме текущей категории)
                        $existing = Category::findByName($name);
                        if ($existing && $existing->getId() !== $id) {
                            $error = 'Категория с таким названием уже существует';
                        } else {
                            $category->setName($name);
                            $category->setDescription($description ?: null);
                            $category->setIcon($icon ?: null);
                            $category->setParentId($parentId);
                            $category->setSortOrder($sortOrder);
                            $category->setIsActive($isActive);
                            
                            if ($category->save()) {
                                Session::flash('success', 'Категория успешно обновлена!');
                                header('Location: /categories.php');
                                exit;
                            } else {
                                $error = 'Ошибка при обновлении категории';
                            }
                        }
                    }
                }
                break;
                
            case 'delete':
                $id = (int)($_POST['id'] ?? 0);
                $category = Category::findById($id);
                
                if (!$category) {
                    $error = 'Категория не найдена';
                } else {
                    if ($category->delete()) {
                        Session::flash('success', 'Категория успешно удалена (или деактивирована, если использовалась)!');
                        header('Location: /categories.php');
                        exit;
                    } else {
                        $error = 'Ошибка при удалении категории';
                    }
                }
                break;
        }
    } catch (\Exception $e) {
        $error = 'Ошибка: ' . $e->getMessage();
    }
}

// Получаем все категории
$categories = Category::getAllActive(0); // 0 = все категории

// Получаем статистику по категориям
use OGAS\Models\Transaction;
$categoryStats = [];
$allUserTransactions = Transaction::findByUser($user->getId());

foreach ($allUserTransactions as $transaction) {
    $catName = $transaction->getCategory();
    if ($catName) {
        if (!isset($categoryStats[$catName])) {
            $categoryStats[$catName] = [
                'total' => 0,
                'completed' => 0,
                'active' => 0,
                'pending' => 0,
                'cancelled' => 0
            ];
        }
        $categoryStats[$catName]['total']++;
        $status = $transaction->getStatus();
        if (isset($categoryStats[$catName][$status])) {
            $categoryStats[$catName][$status]++;
        }
    }
}

// Сортируем категории по количеству использований
usort($categories, function($a, $b) use ($categoryStats) {
    $countA = $categoryStats[$a->getName()]['total'] ?? 0;
    $countB = $categoryStats[$b->getName()]['total'] ?? 0;
    return $countB - $countA;
});

// Редактирование (если передан ID)
$editCategory = null;
if (isset($_GET['edit'])) {
    $editCategory = Category::findById((int)$_GET['edit']);
}

$title = 'Управление категориями';
ob_start();
?>
<div class="dashboard">
    <div class="dashboard-header">
        <h2>Управление категориями</h2>
        <div class="header-actions">
            <a href="/dashboard.php" class="btn btn-secondary">← Назад</a>
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
                       placeholder="Например: 🥖 или fa-food">
                <small>Emoji или класс иконки</small>
            </div>
            
            <div class="form-group">
                <label for="parent_id">Родительская категория:</label>
                <select id="parent_id" name="parent_id">
                    <option value="">Нет (основная категория)</option>
                    <?php foreach ($categories as $cat): ?>
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
                <input type="number" id="sort_order" name="sort_order" min="0" 
                       value="<?= $editCategory ? $editCategory->getSortOrder() : 0 ?>">
                <small>Меньше число = выше в списке</small>
            </div>
            
            <?php if ($editCategory): ?>
                <div class="form-group">
                    <label>
                        <input type="checkbox" name="is_active" value="1" 
                               <?= $editCategory->isActive() ? 'checked' : '' ?>>
                        Активна
                    </label>
                </div>
            <?php endif; ?>
            
            <div class="form-actions">
                <button type="submit" class="btn btn-primary">
                    <?= $editCategory ? 'Сохранить изменения' : 'Создать категорию' ?>
                </button>
                <?php if ($editCategory): ?>
                    <a href="/categories.php" class="btn btn-secondary">Отмена</a>
                <?php endif; ?>
            </div>
        </form>
    </div>
    
    <!-- Статистика по категориям -->
    <?php if (!empty($categoryStats)): ?>
        <div class="info-card">
            <h3>Статистика по категориям</h3>
            <div class="category-stats">
                <?php 
                $topCategories = array_slice(array_filter($categories, function($cat) use ($categoryStats) {
                    return isset($categoryStats[$cat->getName()]) && $categoryStats[$cat->getName()]['total'] > 0;
                }), 0, 5);
                ?>
                <?php foreach ($topCategories as $cat): ?>
                    <?php $stats = $categoryStats[$cat->getName()] ?? ['total' => 0]; ?>
                    <div class="category-stat-item">
                        <div class="category-stat-header">
                            <span class="category-icon-large"><?= htmlspecialchars($cat->getIcon() ?? '📦') ?></span>
                            <span class="category-name-large"><?= htmlspecialchars($cat->getName()) ?></span>
                        </div>
                        <div class="category-stat-numbers">
                            <span class="stat-number"><?= $stats['total'] ?></span>
                            <small>транзакций</small>
                        </div>
                        <div class="category-stat-details">
                            <span class="stat-completed">✓ <?= $stats['completed'] ?? 0 ?> завершённых</span>
                            <span class="stat-active">○ <?= $stats['active'] ?? 0 ?> активных</span>
                            <span class="stat-pending">⏳ <?= $stats['pending'] ?? 0 ?> ожидают</span>
                        </div>
                    </div>
                <?php endforeach; ?>
            </div>
        </div>
    <?php endif; ?>
    
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
                        $usageCount = $category->getUsageCount();
                        ?>
                        <tr>
                            <td><?= htmlspecialchars($category->getIcon() ?? '📦') ?></td>
                            <td><strong><?= htmlspecialchars($category->getName()) ?></strong></td>
                            <td><?= htmlspecialchars(mb_substr($category->getDescription() ?? '', 0, 50)) ?><?= mb_strlen($category->getDescription() ?? '') > 50 ? '...' : '' ?></td>
                            <td><?= $parent ? htmlspecialchars($parent->getName()) : '-' ?></td>
                            <td class="text-center">
                                <?= $usageCount ?>
                                <?php if (isset($categoryStats[$category->getName()])): ?>
                                    <?php $stat = $categoryStats[$category->getName()]; ?>
                                    <br><small class="text-muted">
                                        ✓<?= $stat['completed'] ?? 0 ?> 
                                        ○<?= $stat['active'] ?? 0 ?> 
                                        ⏳<?= $stat['pending'] ?? 0 ?>
                                    </small>
                                <?php endif; ?>
                            </td>
                            <td class="text-center"><?= $category->getSortOrder() ?></td>
                            <td>
                                <?php if ($category->isActive()): ?>
                                    <span class="status status-active">Активна</span>
                                <?php else: ?>
                                    <span class="status status-cancelled">Неактивна</span>
                                <?php endif; ?>
                            </td>
                            <td>
                                <a href="/categories.php?edit=<?= $category->getId() ?>" 
                                   class="btn btn-small">Редактировать</a>
                                <?php if ($usageCount === 0): ?>
                                    <form method="POST" style="display:inline;" 
                                          onsubmit="return confirm('Удалить категорию?');">
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

<style>
.category-stats {
    display: grid;
    grid-template-columns: repeat(auto-fit, minmax(250px, 1fr));
    gap: 15px;
    margin-top: 15px;
}

.category-stat-item {
    background: #f9f9f9;
    padding: 15px;
    border-radius: 8px;
    border-left: 4px solid #667eea;
}

.category-stat-header {
    display: flex;
    align-items: center;
    gap: 10px;
    margin-bottom: 10px;
}

.category-icon-large {
    font-size: 1.5em;
}

.category-name-large {
    font-weight: 600;
    color: #333;
}

.category-stat-numbers {
    margin: 10px 0;
}

.category-stat-numbers .stat-number {
    font-size: 2em;
    font-weight: bold;
    color: #667eea;
}

.category-stat-details {
    display: flex;
    flex-wrap: wrap;
    gap: 10px;
    font-size: 0.9em;
    margin-top: 10px;
}

.stat-completed {
    color: #28a745;
}

.stat-active {
    color: #17a2b8;
}

.stat-pending {
    color: #ffc107;
}

@media (max-width: 768px) {
    .category-stats {
        grid-template-columns: 1fr;
    }
}
</style>
<?php
$content = ob_get_clean();
include __DIR__ . '/../templates/base.php';

