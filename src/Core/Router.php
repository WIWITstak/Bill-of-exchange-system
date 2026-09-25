<?php

namespace OGAS\Core;

/**
 * Простой роутер для маршрутизации запросов
 */
class Router
{
    private static array $routes = [];
    
    /**
     * Добавить GET маршрут
     */
    public static function get(string $path, callable $handler): void
    {
        self::$routes['GET'][$path] = $handler;
    }
    
    /**
     * Добавить POST маршрут
     */
    public static function post(string $path, callable $handler): void
    {
        self::$routes['POST'][$path] = $handler;
    }
    
    /**
     * Обработать запрос
     */
    public static function dispatch(): void
    {
        $method = $_SERVER['REQUEST_METHOD'] ?? 'GET';
        $path = parse_url($_SERVER['REQUEST_URI'] ?? '/', PHP_URL_PATH);
        
        // Убираем /public из пути, если есть
        $path = str_replace('/public', '', $path);
        $path = $path ?: '/';
        
        if (isset(self::$routes[$method][$path])) {
            $handler = self::$routes[$method][$path];
            call_user_func($handler);
        } else {
            // 404
            http_response_code(404);
            echo "Страница не найдена";
        }
    }
}

