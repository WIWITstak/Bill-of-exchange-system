<?php
/**
 * API для редактирования профиля через AJAX
 */

require_once __DIR__ . '/../../src/bootstrap.php';

use OGAS\Services\Auth;
use OGAS\Models\User;
use OGAS\Models\Company;
use OGAS\Models\UserSession;
use OGAS\Core\Session;
use OGAS\Core\Security;
use OGAS\Core\RateLimiter;
use OGAS\Core\SecurityLogger;

header('Content-Type: application/json; charset=UTF-8');

// Rate limiting (60 запросов в минуту с одного IP)
$clientIp = Security::getClientIp();
RateLimiter::requireLimit($clientIp, 60, 60);

// Проверяем авторизацию
Auth::requireAuth();
$user = Auth::user();

if (!$user) {
    echo json_encode(['success' => false, 'message' => 'Необходима авторизация']);
    exit;
}

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    echo json_encode(['success' => false, 'message' => 'Метод не поддерживается']);
    exit;
}

// CSRF защита для POST запросов
Security::requireCsrfToken();

$action = $_POST['action'] ?? $_GET['action'] ?? '';

try {
    switch ($action) {
        case 'update_profile':
            // Изменение ФИО/названия
            $fullName = Security::sanitizeString(trim($_POST['full_name'] ?? ''), 255);
            if (empty($fullName)) {
                echo json_encode(['success' => false, 'message' => 'Поле ФИО/название не может быть пустым']);
                exit;
            }
            
            if (mb_strlen($fullName) < 2) {
                echo json_encode(['success' => false, 'message' => 'ФИО/название должно содержать минимум 2 символа']);
                exit;
            }
            
            $user->setFullName($fullName);
            if ($user->save()) {
                Session::set('user_name', $fullName);
                echo json_encode([
                    'success' => true,
                    'message' => 'Профиль успешно обновлён!',
                    'full_name' => $fullName
                ]);
            } else {
                echo json_encode(['success' => false, 'message' => 'Ошибка при сохранении профиля']);
            }
            break;
            
        case 'change_email':
            // Изменение email
            $newEmail = trim($_POST['new_email'] ?? '');
            $confirmEmail = trim($_POST['confirm_email'] ?? '');
            $currentPassword = $_POST['current_password'] ?? '';
            
            if (empty($newEmail) || empty($confirmEmail)) {
                echo json_encode(['success' => false, 'message' => 'Заполните все поля']);
                exit;
            }
            
            if (!filter_var($newEmail, FILTER_VALIDATE_EMAIL)) {
                echo json_encode(['success' => false, 'message' => 'Некорректный email']);
                exit;
            }
            
            if ($newEmail !== $confirmEmail) {
                echo json_encode(['success' => false, 'message' => 'Email не совпадают']);
                exit;
            }
            
            if ($newEmail === $user->getEmail()) {
                echo json_encode(['success' => false, 'message' => 'Новый email совпадает с текущим']);
                exit;
            }
            
            if (!$user->verifyPassword($currentPassword)) {
                echo json_encode(['success' => false, 'message' => 'Неверный текущий пароль']);
                exit;
            }
            
            // Проверяем, не занят ли email другим пользователем
            $existingUser = User::findByEmail($newEmail);
            if ($existingUser && $existingUser->getId() !== $user->getId()) {
                echo json_encode(['success' => false, 'message' => 'Пользователь с таким email уже существует']);
                exit;
            }
            
            if ($user->changeEmail($newEmail)) {
                Session::set('user_email', $newEmail);
                echo json_encode([
                    'success' => true,
                    'message' => 'Email успешно изменён!',
                    'email' => $newEmail
                ]);
            } else {
                echo json_encode(['success' => false, 'message' => 'Ошибка при изменении email']);
            }
            break;
            
        case 'change_password':
            // Изменение пароля
            $currentPassword = $_POST['current_password'] ?? '';
            $newPassword = $_POST['new_password'] ?? '';
            $confirmPassword = $_POST['confirm_password'] ?? '';
            
            if (empty($currentPassword) || empty($newPassword) || empty($confirmPassword)) {
                echo json_encode(['success' => false, 'message' => 'Заполните все поля']);
                exit;
            }
            
            if (!$user->verifyPassword($currentPassword)) {
                echo json_encode(['success' => false, 'message' => 'Неверный текущий пароль']);
                exit;
            }
            
            if (strlen($newPassword) < 6) {
                echo json_encode(['success' => false, 'message' => 'Новый пароль должен быть не менее 6 символов']);
                exit;
            }
            
            if ($newPassword !== $confirmPassword) {
                echo json_encode(['success' => false, 'message' => 'Пароли не совпадают']);
                exit;
            }
            
            if ($user->changePassword($newPassword)) {
                echo json_encode([
                    'success' => true,
                    'message' => 'Пароль успешно изменён!'
                ]);
            } else {
                echo json_encode(['success' => false, 'message' => 'Ошибка при изменении пароля']);
            }
            break;
            
        case 'update_company':
            // Обновление данных предприятия (для юрлиц)
            if ($user->getUserType() !== 'legal') {
                echo json_encode(['success' => false, 'message' => 'Эта функция доступна только для юридических лиц']);
                exit;
            }
            
            $address = trim($_POST['address'] ?? '');
            $okvedCode = trim($_POST['okved_code'] ?? '');
            $employeeCount = (int)($_POST['employee_count'] ?? 0);
            
            // Валидация ОКВЭД
            if ($okvedCode && !preg_match('/^\d{2}\.\d{2}(\.\d{2})?$/', $okvedCode)) {
                echo json_encode(['success' => false, 'message' => 'Неверный формат ОКВЭД (например: 62.01)']);
                exit;
            }
            
            if ($employeeCount < 0) {
                $employeeCount = 0;
            }
            
            $company = Company::findByUserId($user->getId());
            if (!$company) {
                // Создаём предприятие, если его нет
                $company = Company::create([
                    'user_id' => $user->getId(),
                    'name' => $user->getFullName(),
                    'address' => $address,
                    'okved_code' => $okvedCode ?: null,
                    'employee_count' => $employeeCount
                ]);
            } else {
                // Обновляем существующее предприятие
                $company->setAddress($address ?: null);
                $company->setOkvedCode($okvedCode ?: null);
                $company->setEmployeeCount($employeeCount);
                $company->save();
            }
            
            echo json_encode([
                'success' => true,
                'message' => 'Данные предприятия успешно обновлены!',
                'company' => [
                    'address' => $company->getAddress(),
                    'okved_code' => $company->getOkvedCode(),
                    'employee_count' => $company->getEmployeeCount()
                ]
            ]);
            break;
            
        case 'upload_avatar':
            // Загрузка фото профиля
            if (!isset($_FILES['avatar']) || $_FILES['avatar']['error'] !== UPLOAD_ERR_OK) {
                echo json_encode(['success' => false, 'message' => 'Ошибка загрузки файла']);
                exit;
            }
            
            $file = $_FILES['avatar'];
            
            // Дополнительные проверки безопасности загрузки файлов
            // Проверка на ошибки загрузки
            if ($file['error'] !== UPLOAD_ERR_OK) {
                $errorMessages = [
                    UPLOAD_ERR_INI_SIZE => 'Файл превышает максимальный размер, установленный в php.ini',
                    UPLOAD_ERR_FORM_SIZE => 'Файл превышает максимальный размер формы',
                    UPLOAD_ERR_PARTIAL => 'Файл был загружен частично',
                    UPLOAD_ERR_NO_FILE => 'Файл не был загружен',
                    UPLOAD_ERR_NO_TMP_DIR => 'Отсутствует временная директория',
                    UPLOAD_ERR_CANT_WRITE => 'Ошибка записи файла на диск',
                    UPLOAD_ERR_EXTENSION => 'Загрузка файла остановлена расширением PHP'
                ];
                $errorMsg = $errorMessages[$file['error']] ?? 'Неизвестная ошибка загрузки файла';
                echo json_encode(['success' => false, 'message' => $errorMsg]);
                exit;
            }
            
            // Проверка, что файл действительно был загружен через POST
            if (!is_uploaded_file($file['tmp_name'])) {
                echo json_encode(['success' => false, 'message' => 'Файл не был загружен через HTTP POST']);
                exit;
            }
            
            // Проверка размера файла (максимум 5MB)
            if ($file['size'] > 5 * 1024 * 1024) {
                echo json_encode(['success' => false, 'message' => 'Размер файла не должен превышать 5MB']);
                exit;
            }
            
            // Проверка типа файла по MIME типу
            $allowedTypes = ['image/jpeg', 'image/jpg', 'image/png', 'image/gif', 'image/webp'];
            $finfo = finfo_open(FILEINFO_MIME_TYPE);
            $mimeType = finfo_file($finfo, $file['tmp_name']);
            finfo_close($finfo);
            
            if (!in_array($mimeType, $allowedTypes, true)) {
                echo json_encode(['success' => false, 'message' => 'Разрешены только изображения: JPG, PNG, GIF, WEBP']);
                exit;
            }
            
            // Дополнительная проверка расширения файла
            $extension = strtolower(pathinfo($file['name'], PATHINFO_EXTENSION));
            $allowedExtensions = ['jpg', 'jpeg', 'png', 'gif', 'webp'];
            if (!in_array($extension, $allowedExtensions, true)) {
                echo json_encode(['success' => false, 'message' => 'Недопустимое расширение файла']);
                exit;
            }
            
            // Проверка, что MIME тип соответствует расширению
            $mimeToExtension = [
                'image/jpeg' => ['jpg', 'jpeg'],
                'image/png' => ['png'],
                'image/gif' => ['gif'],
                'image/webp' => ['webp']
            ];
            if (!isset($mimeToExtension[$mimeType]) || !in_array($extension, $mimeToExtension[$mimeType], true)) {
                echo json_encode(['success' => false, 'message' => 'Несоответствие типа файла и расширения']);
                exit;
            }
            
            // Создаем директорию для аватаров
            $avatarsDir = __DIR__ . '/../../storage/avatars';
            if (!is_dir($avatarsDir)) {
                mkdir($avatarsDir, 0755, true);
            }
            
            // Генерируем уникальное имя файла
            $extension = pathinfo($file['name'], PATHINFO_EXTENSION);
            $fileName = 'avatar_' . $user->getId() . '_' . time() . '.' . $extension;
            $filePath = $avatarsDir . '/' . $fileName;
            $relativePath = 'storage/avatars/' . $fileName;
            
            // Удаляем старое фото если есть
            $oldAvatarPath = $user->getAvatarPath();
            if ($oldAvatarPath) {
                $oldPath = ltrim($oldAvatarPath, '/');
                $oldFullPath = __DIR__ . '/../../' . $oldPath;
                if (file_exists($oldFullPath)) {
                    @unlink($oldFullPath);
                }
            }
            
            // Загружаем файл
            if (move_uploaded_file($file['tmp_name'], $filePath)) {
                // Изменяем размер изображения (максимум 400x400)
                if (extension_loaded('gd') && function_exists('imagecreatefromjpeg')) {
                    $image = null;
                    switch ($mimeType) {
                        case 'image/jpeg':
                        case 'image/jpg':
                            $image = @imagecreatefromjpeg($filePath);
                            break;
                        case 'image/png':
                            $image = @imagecreatefrompng($filePath);
                            break;
                        case 'image/gif':
                            $image = @imagecreatefromgif($filePath);
                            break;
                        case 'image/webp':
                            if (function_exists('imagecreatefromwebp')) {
                                $image = @imagecreatefromwebp($filePath);
                            }
                            break;
                    }
                    
                    if ($image) {
                        $width = imagesx($image);
                        $height = imagesy($image);
                        $maxSize = 400;
                        
                        if ($width > $maxSize || $height > $maxSize) {
                            $ratio = min($maxSize / $width, $maxSize / $height);
                            $newWidth = (int)($width * $ratio);
                            $newHeight = (int)($height * $ratio);
                            
                            $resized = imagecreatetruecolor($newWidth, $newHeight);
                            
                            if ($resized) {
                                // Сохраняем прозрачность для PNG и GIF
                                if ($mimeType === 'image/png' || $mimeType === 'image/gif') {
                                    imagealphablending($resized, false);
                                    imagesavealpha($resized, true);
                                    $transparent = imagecolorallocatealpha($resized, 255, 255, 255, 127);
                                    imagefilledrectangle($resized, 0, 0, $newWidth, $newHeight, $transparent);
                                }
                                
                                imagecopyresampled($resized, $image, 0, 0, 0, 0, $newWidth, $newHeight, $width, $height);
                                
                                // Сохраняем измененное изображение
                                switch ($mimeType) {
                                    case 'image/jpeg':
                                    case 'image/jpg':
                                        @imagejpeg($resized, $filePath, 85);
                                        break;
                                    case 'image/png':
                                        @imagepng($resized, $filePath, 6);
                                        break;
                                    case 'image/gif':
                                        @imagegif($resized, $filePath);
                                        break;
                                    case 'image/webp':
                                        if (function_exists('imagewebp')) {
                                            @imagewebp($resized, $filePath, 85);
                                        }
                                        break;
                                }
                                
                                imagedestroy($resized);
                            }
                        }
                        
                        imagedestroy($image);
                    }
                }
                
                // Сохраняем путь в БД
                if ($user->changeAvatar($relativePath)) {
                    // Обновляем данные пользователя из БД
                    $updatedUser = User::findById($user->getId());
                    
                    // Проверяем что файл действительно существует
                    if (!file_exists($filePath)) {
                        @unlink($filePath);
                        echo json_encode(['success' => false, 'message' => 'Файл не был сохранен']);
                        exit;
                    }
                    
                    // Формируем URL - используем прямой путь
                    $avatarUrl = '/' . $relativePath;
                    
                    // Проверяем что файл доступен через веб-сервер
                    $webPath = __DIR__ . '/../../' . ltrim($avatarUrl, '/');
                    if (file_exists($webPath)) {
                        // Используем прямой путь
                        $avatarUrl = '/' . $relativePath;
                    } else {
                        // Пробуем через getAvatarUrl
                        if ($updatedUser) {
                            $checkedUrl = $updatedUser->getAvatarUrl();
                            if (!empty($checkedUrl)) {
                                $avatarUrl = $checkedUrl;
                            }
                        }
                    }
                    
                    echo json_encode([
                        'success' => true,
                        'message' => 'Фото профиля успешно загружено!',
                        'avatar_url' => $avatarUrl,
                        'initials' => $updatedUser ? $updatedUser->getInitials() : $user->getInitials()
                    ]);
                } else {
                    @unlink($filePath);
                    echo json_encode(['success' => false, 'message' => 'Ошибка при сохранении пути к фото']);
                }
            } else {
                echo json_encode(['success' => false, 'message' => 'Ошибка при загрузке файла']);
            }
            break;
            
        case 'remove_avatar':
            // Удаление фото профиля
            if ($user->removeAvatar()) {
                $updatedUser = User::findById($user->getId());
                echo json_encode([
                    'success' => true,
                    'message' => 'Фото профиля удалено',
                    'initials' => $updatedUser ? $updatedUser->getInitials() : $user->getInitials()
                ]);
            } else {
                echo json_encode(['success' => false, 'message' => 'Ошибка при удалении фото']);
            }
            break;
            
        case 'terminate_session':
            // Завершение сессии пользователя
            $sessionIdToTerminate = $_POST['session_id'] ?? '';
            
            if (empty($sessionIdToTerminate)) {
                echo json_encode(['success' => false, 'message' => 'Не указан ID сессии']);
                exit;
            }
            
            // Находим сессию
            $session = UserSession::findBySessionId($sessionIdToTerminate);
            
            if (!$session) {
                echo json_encode(['success' => false, 'message' => 'Сессия не найдена']);
                exit;
            }
            
            // Проверяем, что сессия принадлежит текущему пользователю
            if ($session->getUserId() !== $user->getId()) {
                echo json_encode(['success' => false, 'message' => 'Нет доступа к этой сессии']);
                exit;
            }
            
            // Нельзя завершить текущую сессию
            if ($session->getSessionId() === session_id()) {
                echo json_encode(['success' => false, 'message' => 'Нельзя завершить текущую сессию']);
                exit;
            }
            
            // Удаляем сессию
            if ($session->delete()) {
                // Логируем завершение сессии
                SecurityLogger::log('session_terminated', [
                    'user_id' => $user->getId(),
                    'terminated_session_id' => $sessionIdToTerminate,
                    'ip' => $session->getIpAddress()
                ], 'info');
                
                echo json_encode([
                    'success' => true,
                    'message' => 'Сессия успешно завершена'
                ]);
            } else {
                echo json_encode(['success' => false, 'message' => 'Ошибка при завершении сессии']);
            }
            break;
            
        default:
            echo json_encode(['success' => false, 'message' => 'Неизвестное действие']);
    }
} catch (\Exception $e) {
    echo json_encode(['success' => false, 'message' => 'Ошибка: ' . $e->getMessage()]);
}




