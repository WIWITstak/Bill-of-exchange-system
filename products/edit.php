<?php
/**
 * Страница редактирования товара/услуги
 */

require_once __DIR__ . '/../../src/bootstrap.php';

use OGAS\Services\Auth;
use OGAS\Models\Product;
use OGAS\Core\Session;
use OGAS\Core\Security;

Auth::requireAuth();
$user = Auth::user();

if (!$user) {
    Auth::logout();
    header('Location: /login.php');
    exit;
}

$productId = (int)($_GET['id'] ?? 0);
$product = Product::findById($productId);

if (!$product || $product->getUserId() !== $user->getId()) {
    Session::flash('error', 'Товар/услуга не найден(а) или у вас нет доступа');
    header('Location: /products.php');
    exit;
}

$error = '';
$success = '';

// Обработка формы
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    // CSRF защита
    if (!Security::checkCsrfToken()) {
        $error = 'Ошибка безопасности. Обновите страницу и попробуйте снова.';
    } else {
        $name = trim($_POST['name'] ?? '');
        $description = trim($_POST['description'] ?? '');
        $type = $_POST['type'] ?? 'product';
        $category = trim($_POST['category'] ?? '');
        $basePrice = floatval($_POST['base_price'] ?? $_POST['price'] ?? 0);
        $doublesPrice = floatval($_POST['doubles_price'] ?? $product->getDoublesPrice());
        $unit = trim($_POST['unit'] ?? 'шт');
        $quantity = !empty($_POST['quantity']) ? intval($_POST['quantity']) : null;
        $isAvailable = isset($_POST['is_available']);

        // Валидация
        if (empty($name)) {
            $error = 'Укажите название';
        } elseif ($basePrice < 0) {
            $error = 'Начальная цена не может быть отрицательной';
        } elseif ($doublesPrice < 0) {
            $error = 'Цена в дублях не может быть отрицательной';
        } else {
            $product->setName($name);
            $product->setDescription($description);
            $product->setType($type);
            $product->setCategory($category);
            $product->setBasePrice($basePrice);
            $product->setDoublesPrice($doublesPrice);
            $product->setUnit($unit);
            $product->setQuantity($quantity);
            $product->setIsAvailable($isAvailable);

            if ($product->save()) {
                Session::flash('success', 'Товар/услуга успешно обновлен(а)');
                header('Location: /products.php');
                exit;
            } else {
                $error = 'Ошибка при сохранении';
            }
        }
    }
}

$title = 'Редактировать товар/услугу';
ob_start();
?>
<div class="dashboard">
    <div class="dashboard-header">
        <h2>Редактировать товар/услугу</h2>
        <div class="header-actions">
            <a href="/products.php" class="btn btn-secondary">
                <i class="fas fa-arrow-left"></i> Назад
            </a>
        </div>
    </div>

    <?php if ($error): ?>
        <div class="alert alert-error"><?= htmlspecialchars($error) ?></div>
    <?php endif; ?>

    <?php if ($success): ?>
        <div class="alert alert-success"><?= htmlspecialchars($success) ?></div>
    <?php endif; ?>

    <div class="form-card">
        <form method="POST" action="" class="form-modern">
            <?= csrf_field() ?>
            <div class="form-section">
                <h3>Основная информация</h3>

                <div class="form-group">
                    <label for="type" class="form-label required">Тип</label>
                    <select name="type" id="type" class="form-input" required>
                        <option value="product" <?= $product->getType() === 'product' ? 'selected' : '' ?>>Товар</option>
                        <option value="service" <?= $product->getType() === 'service' ? 'selected' : '' ?>>Услуга</option>
                    </select>
                </div>

                <div class="form-group">
                    <label for="name" class="form-label required">Название</label>
                    <input type="text" 
                           name="name" 
                           id="name" 
                           class="form-input" 
                           required 
                           maxlength="255"
                           value="<?= htmlspecialchars($product->getName()) ?>">
                </div>

                <div class="form-group">
                    <label for="description" class="form-label">Описание</label>
                    <textarea name="description" 
                              id="description" 
                              class="form-input" 
                              rows="4"><?= htmlspecialchars($product->getDescription()) ?></textarea>
                </div>

                <div class="form-group">
                    <label for="category" class="form-label">Категория</label>
                    <input type="text" 
                           name="category" 
                           id="category" 
                           class="form-input" 
                           maxlength="100"
                           value="<?= htmlspecialchars($product->getCategory()) ?>">
                </div>
            </div>

            <div class="form-section">
                <h3>Цена и наличие</h3>

                <div class="form-row">
                    <div class="form-group">
                        <label for="base_price" class="form-label">Начальная цена в рублях</label>
                        <input type="number"
                               name="base_price"
                               id="base_price"
                               class="form-input"
                               min="0"
                               step="0.01"
                               value="<?= htmlspecialchars($product->getBasePrice()) ?>">
                        <small class="form-hint">Начальная цена в рублях (для обратной совместимости)</small>
                    </div>

                    <div class="form-group">
                        <label for="doubles_price" class="form-label required">Цена в дублях</label>
                        <input type="number"
                               name="doubles_price"
                               id="doubles_price"
                               class="form-input"
                               required
                               min="0"
                               step="0.01"
                               value="<?= htmlspecialchars($product->getDoublesPrice()) ?>">
                        <small class="form-hint">Цена товара в дублях - фиксированная стоимость, отражающая реальную ценность для всех пользователей</small>
                    </div>
                </div>

                <div class="form-row">
                    <div class="form-group">
                        <label for="unit" class="form-label">Единица измерения</label>
                        <input type="text"
                               name="unit"
                               id="unit"
                               class="form-input"
                               maxlength="50"
                               value="<?= htmlspecialchars($product->getUnit()) ?>">
                    </div>
                </div>

                <div class="form-group" id="quantity-group" style="<?= $product->getType() === 'service' ? 'display: none;' : '' ?>">
                    <label for="quantity" class="form-label">Количество в наличии</label>
                    <input type="number" 
                           name="quantity" 
                           id="quantity" 
                           class="form-input" 
                           min="0"
                           value="<?= $product->getQuantity() !== null ? htmlspecialchars($product->getQuantity()) : '' ?>">
                </div>

                <div class="form-group">
                    <label class="form-checkbox">
                        <input type="checkbox" 
                               name="is_available" 
                               value="1" 
                               <?= $product->isAvailable() ? 'checked' : '' ?>>
                        <span>Доступно для заказа</span>
                    </label>
                </div>
            </div>

            <div class="form-actions">
                <button type="submit" class="btn btn-primary">
                    <i class="fas fa-save"></i> Сохранить
                </button>
                <a href="/products.php" class="btn btn-secondary">Отмена</a>
            </div>
        </form>
    </div>
</div>

<script>
// Скрываем поле количества для услуг
document.getElementById('type').addEventListener('change', function() {
    const quantityGroup = document.getElementById('quantity-group');
    if (this.value === 'service') {
        quantityGroup.style.display = 'none';
        document.getElementById('quantity').value = '';
    } else {
        quantityGroup.style.display = 'block';
    }
});
</script>

<?php
$content = ob_get_clean();
include __DIR__ . '/../../templates/base.php';
?>

