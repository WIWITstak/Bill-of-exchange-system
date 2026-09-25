<?php
/**
 * Helper функции для приложения ОГАС
 */

/**
 * Генерация URL без префикса /public/
 * 
 * @param string $path Путь относительно public/
 * @return string Полный URL
 */
function url(string $path = ''): string
{
    // Убираем начальный слэш если есть
    $path = ltrim($path, '/');
    
    // Убираем /public/ если есть в начале
    $path = preg_replace('#^public/#', '', $path);
    
    // Возвращаем путь с начальным слэшем
    return '/' . $path;
}

/**
 * Генерация URL для статических ресурсов (CSS, JS, images)
 * 
 * @param string $path Путь к ресурсу относительно public/
 * @return string Полный URL
 */
function asset(string $path): string
{
    return url($path);
}

/**
 * Редирект на указанный URL (с защитой от Open Redirect)
 * 
 * @param string $path Путь для редиректа
 * @param int $code HTTP код редиректа
 */
function redirect(string $path, int $code = 302): void
{
    // Используем безопасный редирект из Security класса
    \OGAS\Core\Security::safeRedirect($path, '/', $code);
}

/**
 * Генерация CSRF токена
 * 
 * @return string CSRF токен
 */
function csrf_token(): string
{
    return \OGAS\Core\Security::generateCsrfToken();
}

/**
 * Генерация скрытого поля с CSRF токеном для формы
 * 
 * @return string HTML код скрытого поля
 */
function csrf_field(): string
{
    return '<input type="hidden" name="_csrf_token" value="' . htmlspecialchars(csrf_token(), ENT_QUOTES, 'UTF-8') . '">';
}

/**
 * Экранирование HTML для предотвращения XSS
 * 
 * @param string|null $string Строка для экранирования
 * @param int $flags Флаги для htmlspecialchars
 * @return string Экранированная строка
 */
function e(?string $string, int $flags = ENT_QUOTES | ENT_HTML5): string
{
    return \OGAS\Core\Security::escapeHtml($string, $flags);
}

/**
 * Экранирование для JavaScript
 * 
 * @param string|null $string Строка для экранирования
 * @return string Экранированная строка
 */
function e_js(?string $string): string
{
    return \OGAS\Core\Security::escapeJs($string);
}
