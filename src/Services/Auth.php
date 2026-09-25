<?php

namespace OGAS\Services;

use OGAS\Models\User;
use OGAS\Models\UserSession;
use OGAS\Core\Session;
use OGAS\Core\Security;
use OGAS\Core\SecurityLogger;

/**
 * Сервис авторизации
 */
class Auth
{
    /**
     * Авторизовать пользователя
     */
    public static function login(string $email, string $password): bool
    {
        $user = User::findByEmail($email);
        
        if (!$user) {
            return false;
        }
        
        // Системный пользователь не может войти в систему
        if ($user->isSystem()) {
            return false;
        }
        
        if (!$user->verifyPassword($password)) {
            return false;
        }
        
        // Регенерируем ID сессии для защиты от session fixation
        Session::regenerateId();
        
        $sessionId = session_id();
        $ipAddress = Security::getClientIp();
        $userAgent = $_SERVER['HTTP_USER_AGENT'] ?? 'Unknown';
        
        // Определяем информацию об устройстве
        $deviceInfo = self::detectDevice($userAgent);
        
        // Проверяем количество активных сессий
        $activeSessionsCount = UserSession::countActiveByUserId($user->getId());
        $maxSessions = Session::getMaxSessionsPerUser();
        
        // Если превышен лимит сессий, удаляем самые старые
        if ($activeSessionsCount >= $maxSessions) {
            $sessions = UserSession::findByUserId($user->getId(), true);
            // Сортируем по времени последней активности
            usort($sessions, function($a, $b) {
                return strtotime($a->getLastActivity()) - strtotime($b->getLastActivity());
            });
            
            // Удаляем самые старые сессии
            $toRemove = $activeSessionsCount - $maxSessions + 1;
            for ($i = 0; $i < $toRemove; $i++) {
                $sessions[$i]->delete();
            }
        }
        
        // Проверяем, есть ли уже сессия с таким IP/User Agent (новое устройство?)
        $existingSessions = UserSession::findByUserId($user->getId(), true);
        $isNewDevice = true;
        foreach ($existingSessions as $existingSession) {
            if ($existingSession->getIpAddress() === $ipAddress && 
                $existingSession->getUserAgent() === $userAgent) {
                $isNewDevice = false;
                break;
            }
        }
        
        // Создаем запись о новой сессии
        $userSession = UserSession::create($user->getId(), $sessionId, $ipAddress, $userAgent, $deviceInfo);
        
        // Логируем вход с нового устройства/IP
        if ($isNewDevice && class_exists('OGAS\Core\SecurityLogger')) {
            SecurityLogger::logSuspiciousActivity('login_from_new_device', [
                'user_id' => $user->getId(),
                'ip' => $ipAddress,
                'user_agent' => $userAgent,
                'device_info' => $deviceInfo
            ]);
        }
        
        // Сохраняем ID пользователя в сессии
        Session::set('user_id', $user->getId());
        Session::set('user_email', $user->getEmail());
        Session::set('user_type', $user->getUserType());
        Session::set('user_name', $user->getFullName());
        Session::set('is_active', $user->isActive());
        Session::set('is_admin', $user->isAdmin());
        Session::set('_last_activity', time());
        Session::set('_session_record_id', $userSession->getId());
        
        return true;
    }
    
    /**
     * Выйти из системы
     */
    public static function logout(): void
    {
        // Удаляем запись о сессии из БД
        if (Session::has('_session_record_id')) {
            $sessionId = session_id();
            $sessionRecord = UserSession::findBySessionId($sessionId);
            if ($sessionRecord) {
                $sessionRecord->delete();
            }
        }
        
        Session::destroy();
    }
    
    /**
     * Определить информацию об устройстве из User Agent
     */
    private static function detectDevice(string $userAgent): string
    {
        $device = 'Unknown';
        
        // Определяем тип устройства
        if (preg_match('/Mobile|Android|iPhone|iPad/i', $userAgent)) {
            $device = 'Mobile';
            if (preg_match('/iPhone/i', $userAgent)) {
                $device = 'iPhone';
            } elseif (preg_match('/iPad/i', $userAgent)) {
                $device = 'iPad';
            } elseif (preg_match('/Android/i', $userAgent)) {
                $device = 'Android';
            }
        } elseif (preg_match('/Windows/i', $userAgent)) {
            $device = 'Windows';
        } elseif (preg_match('/Mac/i', $userAgent)) {
            $device = 'Mac';
        } elseif (preg_match('/Linux/i', $userAgent)) {
            $device = 'Linux';
        }
        
        // Определяем браузер
        $browser = 'Unknown';
        if (preg_match('/Chrome/i', $userAgent) && !preg_match('/Edg/i', $userAgent)) {
            $browser = 'Chrome';
        } elseif (preg_match('/Firefox/i', $userAgent)) {
            $browser = 'Firefox';
        } elseif (preg_match('/Safari/i', $userAgent) && !preg_match('/Chrome/i', $userAgent)) {
            $browser = 'Safari';
        } elseif (preg_match('/Edg/i', $userAgent)) {
            $browser = 'Edge';
        } elseif (preg_match('/Opera/i', $userAgent)) {
            $browser = 'Opera';
        }
        
        return $device . ' / ' . $browser;
    }
    
    /**
     * Проверить, авторизован ли пользователь
     */
    public static function check(): bool
    {
        if (!Session::has('user_id')) {
            return false;
        }
        
        // Проверяем таймаут неактивности
        $lastActivity = Session::get('_last_activity', 0);
        $now = time();
        $timeout = Session::getInactivityTimeout();
        
        if (($now - $lastActivity) > $timeout) {
            // Сессия истекла
            self::logout();
            return false;
        }
        
        // Обновляем время последней активности
        Session::touch();
        
        // Проверяем, что сессия существует в БД
        $sessionId = session_id();
        $sessionRecord = UserSession::findBySessionId($sessionId);
        if (!$sessionRecord) {
            // Сессия не найдена в БД - возможно, была удалена
            self::logout();
            return false;
        }
        
        return true;
    }
    
    /**
     * Получить текущего пользователя
     */
    public static function user(): ?User
    {
        if (!self::check()) {
            return null;
        }
        
        $userId = Session::get('user_id');
        if (!$userId) {
            return null;
        }
        
        try {
            $user = User::findById($userId);
            // Если пользователь не найден, но сессия установлена - очищаем сессию
            if (!$user) {
                self::logout();
                return null;
            }
            return $user;
        } catch (\Exception $e) {
            // При ошибке тоже очищаем сессию
            self::logout();
            return null;
        }
    }
    
    /**
     * Проверить, активен ли аккаунт пользователя
     */
    public static function isActive(): bool
    {
        if (!self::check()) {
            return false;
        }
        
        return Session::get('is_active', false);
    }
    
    /**
     * Требовать авторизацию (редирект на логин, если не авторизован)
     * 
     * @param string|null $redirectUrl URL для редиректа после входа (опционально)
     */
    public static function requireAuth(?string $redirectUrl = null): void
    {
        if (!self::check()) {
            if (!headers_sent()) {
                $loginUrl = '/login.php';
                if ($redirectUrl !== null) {
                    $safeRedirect = \OGAS\Core\Security::safeRedirectUrl($redirectUrl, '/');
                    $loginUrl .= '?redirect=' . urlencode($safeRedirect);
                } else {
                    // Добавляем текущий URL как redirect, если не указан
                    $currentUrl = $_SERVER['REQUEST_URI'] ?? '/dashboard.php';
                    if ($currentUrl !== '/login.php' && $currentUrl !== '/register.php') {
                        $safeRedirect = \OGAS\Core\Security::safeRedirectUrl($currentUrl, '/');
                        $loginUrl .= '?redirect=' . urlencode($safeRedirect);
                    }
                }
                header('Location: ' . $loginUrl, true, 302);
                exit;
            } else {
                // Если заголовки уже отправлены, делаем JavaScript редирект
                echo '<script>window.location.href = "' . htmlspecialchars($loginUrl ?? '/login.php', ENT_QUOTES, 'UTF-8') . '";</script>';
                exit;
            }
        }
    }
    
    /**
     * Требовать активный аккаунт
     */
    public static function requireActive(): void
    {
        self::requireAuth();
        
        if (!self::isActive()) {
            header('Location: /activate.php');
            exit;
        }
    }
}

