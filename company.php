<?php
/**
 * Страница информации о предприятии
 */

require_once __DIR__ . '/../src/bootstrap.php';

use OGAS\Services\Auth;
use OGAS\Models\User;
use OGAS\Models\Company;
use OGAS\Core\Session;

// Требуем авторизацию
Auth::requireAuth();

$user = Auth::user();

// Проверяем, что пользователь найден
if (!$user) {
    Auth::logout();
    header('Location: /login.php?error=user_not_found');
    exit;
}

// Проверяем, что пользователь - юридическое лицо
if ($user->getUserType() !== 'legal') {
    header('Location: /dashboard.php?error=not_legal_user');
    exit;
}

$error = Session::getFlash('error');
$success = Session::getFlash('success');

// Обработка формы обновления
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action']) && $_POST['action'] === 'update_company') {
    // CSRF защита
    if (!\OGAS\Core\Security::checkCsrfToken()) {
        Session::flash('error', 'Ошибка безопасности. Обновите страницу и попробуйте снова.');
        header('Location: /company.php');
        exit;
    }
    
    try {
        $company = Company::findByUserId($user->getId());
        
        if (!$company) {
            // Создаём предприятие, если его нет
            $company = Company::create([
                'user_id' => $user->getId(),
                'name' => $user->getFullName(),
                'address' => trim($_POST['address'] ?? ''),
                'okved_code' => trim($_POST['okved_code'] ?? ''),
                'employee_count' => (int)($_POST['employee_count'] ?? 0)
            ]);
            $success = 'Данные предприятия успешно созданы!';
        } else {
            // Обновляем существующее предприятие
            $company->setName(trim($_POST['name'] ?? $company->getName()));
            $company->setAddress(trim($_POST['address'] ?? ''));
            $company->setOkvedCode(trim($_POST['okved_code'] ?? ''));
            $company->setEmployeeCount((int)($_POST['employee_count'] ?? 0));
            
            if ($company->save()) {
                $success = 'Данные предприятия успешно обновлены!';
            } else {
                $error = 'Ошибка при сохранении данных';
            }
        }
        
        // Перенаправляем, чтобы избежать повторной отправки формы
        Session::setFlash('success', $success);
        header('Location: /company.php');
        exit;
    } catch (\Exception $e) {
        $error = 'Ошибка: ' . $e->getMessage();
    }
}

// Получаем данные предприятия
$company = Company::findByUserId($user->getId());

$title = 'Информация о предприятии';
ob_start();
?>
<div class="dashboard">
    <div class="dashboard-header">
        <h2>🏢 Информация о предприятии</h2>
        <div class="header-actions">
            <a href="/dashboard.php" class="btn btn-secondary" style="padding: 6px 12px; font-size: 0.85em;">← В кабинет</a>
        </div>
    </div>

    <?php if ($error): ?>
        <div class="alert alert-error"><?= htmlspecialchars($error) ?></div>
    <?php endif; ?>

    <?php if ($success): ?>
        <div class="alert alert-success"><?= htmlspecialchars($success) ?></div>
    <?php endif; ?>

    <div class="dashboard-content">
        <!-- Информация о предприятии -->
        <div class="info-card company-info-card">
            <div class="company-header">
                <div class="company-icon">🏢</div>
                <div class="company-main">
                    <h3><?= htmlspecialchars($company ? $company->getName() : $user->getFullName()) ?></h3>
                    <div class="company-meta">
                        <span class="company-type-badge">Юридическое лицо</span>
                    </div>
                </div>
            </div>

            <?php if ($company): ?>
                <div class="company-details">
                    <div class="info-section">
                        <h4>📋 Основная информация</h4>
                        <div class="info-grid">
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
                        </div>
                    </div>

                    <?php if ($company->getCreatedAt()): ?>
                        <div class="info-divider"></div>
                        <div class="info-note">
                            <small>📅 Дата создания записи: <?= date('d.m.Y', strtotime($company->getCreatedAt())) ?></small>
                        </div>
                    <?php endif; ?>
                </div>
            <?php else: ?>
                <div class="company-empty">
                    <div class="company-empty-icon">📝</div>
                    <h4>Информация о предприятии не заполнена</h4>
                    <p class="text-muted">Заполните данные о вашем предприятии, чтобы другие пользователи могли узнать о вас больше.</p>
                </div>
            <?php endif; ?>

            <!-- Форма редактирования -->
            <div class="company-form-section">
                <h4>✏️ Редактировать информацию</h4>
                <form method="POST" action="/company.php" class="company-form">
                    <?= csrf_field() ?>
                    <input type="hidden" name="action" value="update_company">
                    
                    <div class="form-group">
                        <label for="name">Название организации:</label>
                        <input type="text" 
                               id="name" 
                               name="name" 
                               class="form-control"
                               value="<?= htmlspecialchars($company ? $company->getName() : $user->getFullName()) ?>"
                               required>
                        <small class="form-hint">Полное наименование вашего предприятия</small>
                    </div>
                    
                    <div class="form-group form-group-full-width">
                        <label for="address">Юридический адрес:</label>
                        <textarea id="address" 
                                  name="address" 
                                  class="form-control"
                                  rows="2"
                                  placeholder="Введите полный юридический адрес"><?= htmlspecialchars($company ? $company->getAddress() : '') ?></textarea>
                        <small class="form-hint">Полный адрес регистрации предприятия</small>
                    </div>
                    
                    <div class="form-group">
                        <label for="okved_code">Код ОКВЭД:</label>
                        <input type="text" 
                               id="okved_code" 
                               name="okved_code" 
                               class="form-control"
                               value="<?= htmlspecialchars($company ? $company->getOkvedCode() : '') ?>"
                               placeholder="Например: 62.01">
                        <small class="form-hint">Код по Общероссийскому классификатору видов экономической деятельности</small>
                    </div>
                    
                    <div class="form-group">
                        <label for="employee_count">Количество сотрудников:</label>
                        <input type="number" 
                               id="employee_count" 
                               name="employee_count" 
                               class="form-control"
                               value="<?= $company ? $company->getEmployeeCount() : 0 ?>"
                               min="0"
                               step="1">
                        <small class="form-hint">Общее количество сотрудников на предприятии</small>
                    </div>
                    
                    <div class="form-actions">
                        <button type="submit" class="btn btn-primary">
                            💾 <?= $company ? 'Сохранить изменения' : 'Создать запись' ?>
                        </button>
                        <a href="/dashboard.php" class="btn btn-secondary">Отмена</a>
                    </div>
                </form>
            </div>
        </div>
    </div>
</div>

<?php
$content = ob_get_clean();
include __DIR__ . '/../templates/base.php';
?>







