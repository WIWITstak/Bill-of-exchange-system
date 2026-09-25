<?php

namespace OGAS;

use PDO;
use PDOException;

/**
 * Класс для работы с базой данных
 */
class Database
{
    private static ?PDO $connection = null;
    private static array $config = [];
    
    /**
     * Инициализация подключения к БД
     */
    public static function init(array $config): void
    {
        self::$config = $config;
    }
    
    /**
     * Получить подключение к БД
     */
    public static function getConnection(): PDO
    {
        if (self::$connection === null) {
            self::connect();
        }
        
        return self::$connection;
    }
    
    /**
     * Установить соединение с БД
     */
    private static function connect(): void
    {
        $dsn = sprintf(
            'mysql:host=%s;port=%d;dbname=%s;charset=%s',
            self::$config['host'],
            self::$config['port'],
            self::$config['database'],
            self::$config['charset']
        );
        
        $options = [
            PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
            PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
            PDO::ATTR_EMULATE_PREPARES => false,
        ];
        
        try {
            self::$connection = new PDO(
                $dsn,
                self::$config['username'],
                self::$config['password'],
                $options
            );
        } catch (PDOException $e) {
            $errorCode = $e->getCode();
            $errorMessage = $e->getMessage();
            
            // Более понятные сообщения об ошибках
            $userMessage = 'Ошибка подключения к базе данных: ' . $errorMessage;
            
            // Дополнительные подсказки в зависимости от типа ошибки
            if ($errorCode == 2002 || strpos($errorMessage, 'Подключение не установлено') !== false) {
                $userMessage .= "\n\nВозможные причины:\n";
                $userMessage .= "1. MySQL сервер не запущен (проверьте Open Server)\n";
                $userMessage .= "2. Неправильный хост в настройках (попробуйте 127.0.0.1 вместо localhost)\n";
                $userMessage .= "3. Неправильный порт (по умолчанию 3306)\n";
                $userMessage .= "4. Файрвол блокирует подключение\n";
                $userMessage .= "\nПроверьте настройки в файле .env:\n";
                $userMessage .= "DB_HOST=" . (self::$config['host'] ?? 'не указан') . "\n";
                $userMessage .= "DB_PORT=" . (self::$config['port'] ?? 'не указан') . "\n";
            } elseif ($errorCode == 1045 || strpos($errorMessage, 'Access denied') !== false) {
                $userMessage .= "\n\nВозможные причины:\n";
                $userMessage .= "1. Неправильный логин или пароль\n";
                $userMessage .= "2. Пользователь не имеет прав на доступ к базе данных\n";
                $userMessage .= "\nПроверьте настройки в файле .env:\n";
                $userMessage .= "DB_USERNAME=" . (self::$config['username'] ?? 'не указан') . "\n";
                $userMessage .= "DB_PASSWORD=" . (empty(self::$config['password']) ? '(пусто)' : '***') . "\n";
            } elseif ($errorCode == 1049 || strpos($errorMessage, "Unknown database") !== false) {
                $userMessage .= "\n\nВозможные причины:\n";
                $userMessage .= "1. База данных '" . (self::$config['database'] ?? 'не указана') . "' не существует\n";
                $userMessage .= "2. Необходимо создать базу данных через phpMyAdmin\n";
                $userMessage .= "3. Или импортировать database/schema.sql\n";
            }
            
            throw new \RuntimeException($userMessage);
        }
    }
    
    /**
     * Закрыть соединение
     */
    public static function close(): void
    {
        self::$connection = null;
    }
}

