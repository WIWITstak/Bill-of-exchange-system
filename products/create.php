<?php
/**
 * Страница создания товара/услуги
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
        $doublesPrice = floatval($_POST['doubles_price'] ?? 0);
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
            $product = new Product();
            $product->setUserId($user->getId());
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
                Session::flash('success', 'Товар/услуга успешно добавлен(а)');
                header('Location: /products.php');
                exit;
            } else {
                $error = 'Ошибка при сохранении';
            }
        }
    }
}

$title = 'Добавить товар/услугу';
ob_start();
?>
<div class="dashboard">
    <div class="dashboard-header">
        <h2>Добавить товар/услугу</h2>
        <a href="/products.php" class="btn btn-secondary">
            <i class="fas fa-arrow-left"></i> Назад
        </a>
    </div>

    <?php if ($error): ?>
        <div class="alert alert-error"><?= htmlspecialchars($error) ?></div>
    <?php endif; ?>

    <?php if ($success): ?>
        <div class="alert alert-success"><?= htmlspecialchars($success) ?></div>
    <?php endif; ?>

    <div class="product-form-container">
        <div class="product-form-card">
            <form method="POST" action="" class="product-form-modern" id="product-form">
                <?= csrf_field() ?>
                
                <div class="form-section-modern">
                    <div class="form-section-header">
                        <h3>
                            <span class="section-icon">📦</span>
                            Основная информация
                        </h3>
                        <p class="section-description">Укажите основные данные о товаре или услуге</p>
                    </div>

                    <div class="form-group-modern">
                        <label for="type">
                            <span class="label-icon">🏷️</span>
                            Тип <span class="required-mark">*</span>
                        </label>
                        <select name="type" id="type" class="form-input-modern" required>
                            <option value="product" <?= (isset($_POST['type']) && $_POST['type'] === 'product') || !isset($_POST['type']) ? 'selected' : '' ?>>Товар</option>
                            <option value="service" <?= isset($_POST['type']) && $_POST['type'] === 'service' ? 'selected' : '' ?>>Услуга</option>
                        </select>
                        <small class="form-hint">Выберите тип: товар (физический продукт) или услуга</small>
                    </div>

                    <div class="form-group-modern">
                        <label for="name">
                            <span class="label-icon">📝</span>
                            Название <span class="required-mark">*</span>
                        </label>
                        <input type="text" 
                               name="name" 
                               id="name" 
                               class="form-input-modern" 
                               required 
                               maxlength="255"
                               placeholder="Например: Консультация юриста или Стол письменный"
                               value="<?= htmlspecialchars($_POST['name'] ?? '') ?>">
                        <small class="form-hint">Краткое и понятное название товара или услуги</small>
                    </div>

                    <div class="form-group-modern">
                        <label for="description">
                            <span class="label-icon">📄</span>
                            Описание
                        </label>
                        <textarea name="description" 
                                  id="description" 
                                  class="form-textarea-modern" 
                                  rows="5"
                                  placeholder="Подробное описание товара или услуги, его особенности, характеристики..."><?= htmlspecialchars($_POST['description'] ?? '') ?></textarea>
                        <small class="form-hint">Дополнительная информация о товаре или услуге</small>
                    </div>

                    <div class="form-group-modern">
                        <label for="category">
                            <span class="label-icon">📂</span>
                            Категория
                        </label>
                        <input type="text" 
                               name="category" 
                               id="category" 
                               class="form-input-modern" 
                               maxlength="100"
                               placeholder="Например: Юридические услуги, Мебель"
                               value="<?= htmlspecialchars($_POST['category'] ?? '') ?>">
                        <small class="form-hint">Категория помогает покупателям быстрее найти ваш товар или услугу</small>
                    </div>
                </div>

                <div class="form-section-modern">
                    <div class="form-section-header">
                        <h3>
                            <span class="section-icon">💰</span>
                            Цена и наличие
                        </h3>
                        <p class="section-description">Укажите стоимость и параметры наличия</p>
                    </div>

                    <div class="form-row-modern">
                        <div class="form-group-modern">
                            <label for="base_price">
                                <span class="label-icon">💵</span>
                                Начальная цена в рублях
                            </label>
                            <input type="number"
                                   name="base_price"
                                   id="base_price"
                                   class="form-input-modern"
                                   min="0"
                                   step="0.01"
                                   placeholder="0.00"
                                   value="<?= htmlspecialchars($_POST['base_price'] ?? $_POST['price'] ?? '') ?>">
                            <small class="form-hint">Начальная цена в рублях (для обратной совместимости)</small>
                        </div>

                        <div class="form-group-modern">
                            <label for="doubles_price">
                                <span class="label-icon">💎</span>
                                Цена в дублях <span class="required-mark">*</span>
                            </label>
                            <input type="number"
                                   name="doubles_price"
                                   id="doubles_price"
                                   class="form-input-modern"
                                   required
                                   min="0"
                                   step="0.01"
                                   placeholder="0.00"
                                   value="<?= htmlspecialchars($_POST['doubles_price'] ?? '0') ?>">
                            <small class="form-hint">Цена товара в дублях - фиксированная стоимость, отражающая реальную ценность для всех пользователей</small>
                        </div>
                    </div>

                    <div class="form-row-modern">
                        <div class="form-group-modern">
                            <label for="unit">
                                <span class="label-icon">📏</span>
                                Единица измерения
                            </label>
                            <input type="text"
                                   name="unit"
                                   id="unit"
                                   class="form-input-modern"
                                   maxlength="50"
                                   placeholder="шт, кг, час, м²..."
                                   value="<?= htmlspecialchars($_POST['unit'] ?? 'шт') ?>">
                            <small class="form-hint">Единица измерения (шт, кг, час и т.д.)</small>
                        </div>
                    </div>

                    <div class="form-group-modern" id="quantity-group">
                        <label for="quantity">
                            <span class="label-icon">📊</span>
                            Количество в наличии
                        </label>
                        <input type="number" 
                               name="quantity" 
                               id="quantity" 
                               class="form-input-modern" 
                               min="0"
                               placeholder="Оставьте пустым для неограниченного количества"
                               value="<?= htmlspecialchars($_POST['quantity'] ?? '') ?>">
                        <small class="form-hint">Для услуг обычно не указывается. Для товаров — количество доступных единиц</small>
                    </div>

                    <div class="form-group-modern">
                        <label class="form-checkbox-modern">
                            <input type="checkbox" 
                                   name="is_available" 
                                   value="1" 
                                   <?= isset($_POST['is_available']) || !isset($_POST['name']) ? 'checked' : '' ?>>
                            <span class="checkbox-custom"></span>
                            <span class="checkbox-label">Доступно для заказа</span>
                        </label>
                        <small class="form-hint">Снимите галочку, чтобы временно скрыть товар/услугу от покупателей</small>
                    </div>
                </div>

                <div class="form-actions-modern">
                    <button type="submit" class="btn btn-primary btn-large">
                        <i class="fas fa-save"></i>
                        <span>Сохранить товар/услугу</span>
                    </button>
                    <a href="/products.php" class="btn btn-secondary">
                        <i class="fas fa-times"></i>
                        <span>Отмена</span>
                    </a>
                </div>
            </form>
        </div>
    </div>
</div>

<style>
.product-form-container {
    max-width: 900px;
    margin: 0 auto;
}

.product-form-card {
    background: var(--bg-primary);
    border-radius: var(--radius-xl);
    padding: 32px;
    box-shadow: var(--shadow-md);
    border: 1px solid var(--border-color);
}

.product-form-modern {
    display: flex;
    flex-direction: column;
    gap: 32px;
}

.form-section-modern {
    display: flex;
    flex-direction: column;
    gap: 24px;
    padding: 24px;
    background: var(--bg-secondary);
    border-radius: var(--radius-lg);
    border: 1px solid var(--border-color-light);
}

.form-section-header {
    margin-bottom: 8px;
    padding-bottom: 16px;
    border-bottom: 2px solid var(--border-color-light);
}

.form-section-header h3 {
    margin: 0 0 8px 0;
    font-size: 1.3em;
    font-weight: 700;
    color: var(--text-primary);
    display: flex;
    align-items: center;
    gap: 10px;
}

.section-icon {
    font-size: 1.2em;
}

.section-description {
    margin: 0;
    font-size: 0.9em;
    color: var(--text-secondary);
}

.form-group-modern {
    display: flex;
    flex-direction: column;
    gap: 8px;
}

.form-group-modern label {
    display: flex;
    align-items: center;
    gap: 8px;
    font-weight: 600;
    color: var(--text-primary);
    font-size: 0.95em;
}

.label-icon {
    font-size: 1.1em;
    opacity: 0.8;
}

.required-mark {
    color: var(--color-danger);
    font-weight: 700;
}

.form-input-modern,
.form-textarea-modern,
.form-input-modern select {
    width: 100%;
    padding: 12px 16px;
    border: 2px solid var(--border-color);
    border-radius: var(--radius-md);
    font-size: 0.9375rem;
    transition: all var(--transition-base);
    box-sizing: border-box;
    font-family: inherit;
    background: var(--bg-primary);
    color: var(--text-primary);
}

.form-input-modern:focus,
.form-textarea-modern:focus {
    outline: none;
    border-color: var(--color-primary);
    box-shadow: 0 0 0 4px rgba(44, 62, 80, 0.1);
}

.form-textarea-modern {
    resize: vertical;
    min-height: 120px;
}

.form-hint {
    display: block;
    margin-top: 4px;
    font-size: 0.8125em;
    color: var(--text-secondary);
    line-height: 1.4;
}

.form-row-modern {
    display: grid;
    grid-template-columns: 1fr 1fr;
    gap: 20px;
}

.form-checkbox-modern {
    display: flex;
    align-items: center;
    gap: 12px;
    cursor: pointer;
    padding: 12px;
    background: var(--bg-primary);
    border-radius: var(--radius-md);
    border: 2px solid var(--border-color);
    transition: all var(--transition-base);
}

.form-checkbox-modern:hover {
    border-color: var(--color-primary);
    background: rgba(44, 62, 80, 0.03);
}

.form-checkbox-modern input[type="checkbox"] {
    display: none;
}

.checkbox-custom {
    width: 20px;
    height: 20px;
    border: 2px solid var(--border-color);
    border-radius: var(--radius-sm);
    background: var(--bg-primary);
    display: flex;
    align-items: center;
    justify-content: center;
    transition: all var(--transition-base);
    flex-shrink: 0;
}

.form-checkbox-modern input[type="checkbox"]:checked + .checkbox-custom {
    background: var(--color-primary);
    border-color: var(--color-primary);
}

.form-checkbox-modern input[type="checkbox"]:checked + .checkbox-custom::after {
    content: '✓';
    color: white;
    font-size: 14px;
    font-weight: bold;
}

.checkbox-label {
    font-weight: 500;
    color: var(--text-primary);
    user-select: none;
}

.form-actions-modern {
    display: flex;
    gap: 12px;
    justify-content: flex-start;
    padding-top: 24px;
    border-top: 2px solid var(--border-color-light);
}

.btn-large {
    padding: 14px 28px;
    font-size: 1rem;
}

.btn i {
    margin-right: 8px;
}

/* Адаптивность */
@media (max-width: 768px) {
    .product-form-card {
        padding: 20px;
    }

    .form-section-modern {
        padding: 20px;
    }

    .form-row-modern {
        grid-template-columns: 1fr;
        gap: 24px;
    }

    .form-actions-modern {
        flex-direction: column;
    }

    .form-actions-modern .btn {
        width: 100%;
    }
}
</style>

<script>
document.addEventListener('DOMContentLoaded', function() {
    const typeSelect = document.getElementById('type');
    const quantityGroup = document.getElementById('quantity-group');
    
    // Инициализация скрытия поля количества для услуг
    function toggleQuantityField() {
        if (typeSelect.value === 'service') {
            quantityGroup.style.display = 'none';
            document.getElementById('quantity').value = '';
        } else {
            quantityGroup.style.display = 'block';
        }
    }
    
    // Применяем при загрузке страницы
    toggleQuantityField();
    
    // Обработчик изменения типа
    typeSelect.addEventListener('change', toggleQuantityField);
    
    // Валидация формы
    const form = document.getElementById('product-form');
    form.addEventListener('submit', function(e) {
        const name = document.getElementById('name').value.trim();
        const price = parseFloat(document.getElementById('price').value);
        
        if (!name) {
            e.preventDefault();
            alert('Пожалуйста, укажите название товара/услуги');
            document.getElementById('name').focus();
            return false;
        }
        
        if (isNaN(price) || price < 0) {
            e.preventDefault();
            alert('Пожалуйста, укажите корректную цену (неотрицательное число)');
            document.getElementById('price').focus();
            return false;
        }
    });
    
    // Подсчет символов в описании
    const descriptionTextarea = document.getElementById('description');
    const nameInput = document.getElementById('name');
    
    // Подсчет символов для названия
    if (nameInput) {
        nameInput.addEventListener('input', function() {
            const length = this.value.length;
            const maxLength = this.maxLength || 255;
            if (length > maxLength * 0.9) {
                this.style.borderColor = '#f59e0b';
            } else {
                this.style.borderColor = '';
            }
        });
    }
    
    // Подсчет символов для описания
    if (descriptionTextarea) {
        descriptionTextarea.addEventListener('input', function() {
            const length = this.value.length;
            if (length > 1000) {
                this.style.borderColor = '#f59e0b';
            } else {
                this.style.borderColor = '';
            }
        });
    }
});
</script>

<?php
$content = ob_get_clean();
include __DIR__ . '/../../templates/base.php';
?>
