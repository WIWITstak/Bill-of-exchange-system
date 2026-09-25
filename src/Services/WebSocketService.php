<?php

namespace OGAS\Services;

/**
 * Сервис для управления WebSocket сервером
 */
class WebSocketService
{
    /**
     * Получить статус WebSocket сервера
     */
    public static function getStatus(): array
    {
        $isRunning = self::isRunning();
        $port = 8082;
        
        // Определяем хост из конфигурации или используем localhost
        $host = self::getWebSocketHost();
        $protocol = self::isSSLEnabled() ? 'wss' : 'ws';
        
        return [
            'running' => $isRunning,
            'port' => $port,
            'protocol' => $protocol,
            'url' => $protocol . '://' . $host . ':' . $port
        ];
    }
    
    /**
     * Получить хост для WebSocket соединения
     */
    private static function getWebSocketHost(): string
    {
        // 1. Пытаемся получить из переменных окружения
        if (isset($_ENV['APP_HOST']) && !empty($_ENV['APP_HOST'])) {
            return $_ENV['APP_HOST'];
        }
        
        // 2. Пытаемся получить из .env файла
        $envFile = __DIR__ . '/../../.env';
        if (file_exists($envFile)) {
            $envContent = file_get_contents($envFile);
            if (preg_match('/APP_HOST=(.+)/', $envContent, $matches)) {
                return trim($matches[1]);
            }
        }
        
        // 3. Пытаемся получить из HTTP_HOST
        $host = $_SERVER['HTTP_HOST'] ?? null;
        if ($host) {
            // Убираем порт, если он есть (например, localhost:80 -> localhost)
            if (strpos($host, ':') !== false) {
                $host = explode(':', $host)[0];
            }
            return $host;
        }
        
        // 4. Проверяем SERVER_NAME
        if (isset($_SERVER['SERVER_NAME']) && !empty($_SERVER['SERVER_NAME'])) {
            return $_SERVER['SERVER_NAME'];
        }
        
        // 5. По умолчанию
        return 'localhost';
    }
    
    /**
     * Проверить, включен ли SSL
     */
    private static function isSSLEnabled(): bool
    {
        $projectRoot = realpath(__DIR__ . '/../..');
        $openServerPath = self::findOpenServerPath();
        
        if (!$openServerPath) {
            return false;
        }
        
        $hostname = self::getWebSocketHost();
        
        // Проверяем наличие SSL сертификатов (как в chat-server.php)
        $certPath = str_replace('\\', DIRECTORY_SEPARATOR, $openServerPath . '/userdata/config/cert_files/' . $hostname . '.pem');
        $keyPath = str_replace('\\', DIRECTORY_SEPARATOR, $openServerPath . '/userdata/config/cert_files/' . $hostname . '-key.pem');
        
        $certPathAlt = str_replace('\\', DIRECTORY_SEPARATOR, $openServerPath . '/userdata/config/cert_files/' . $hostname . '.crt');
        $keyPathAlt = str_replace('\\', DIRECTORY_SEPARATOR, $openServerPath . '/userdata/config/cert_files/' . $hostname . '.key');
        
        return (file_exists($certPath) && file_exists($keyPath)) || 
               (file_exists($certPathAlt) && file_exists($keyPathAlt));
    }
    
    /**
     * Найти путь к Open Server
     */
    private static function findOpenServerPath(): ?string
    {
        $drives = ['F', 'C', 'D', 'E'];
        
        foreach ($drives as $drive) {
            $path = "$drive:\\OpenServer";
            if (is_dir($path)) {
                return $path;
            }
        }
        
        return null;
    }
    
    /**
     * Проверить, запущен ли WebSocket сервер
     */
    public static function isRunning(): bool
    {
        // Проверяем через netstat, слушает ли порт 8082
        $command = 'netstat -ano | findstr :8082';
        exec($command, $output, $returnCode);
        
        if (!empty($output)) {
            // Если есть вывод, значит порт используется
            foreach ($output as $line) {
                if (stripos($line, 'LISTENING') !== false) {
                    return true;
                }
            }
        }
        
        // fsockopen не работает с WSS (SSL), поэтому не используем его
        // Проверяем только через netstat
        
        return false;
    }
    
    /**
     * Найти путь к PHP
     */
    private static function findPhpPath(): ?string
    {
        // Ищем PHP в Open Server (как в start-server.bat)
        $drives = ['F', 'C', 'D', 'E'];
        $versions = ['8.3', '8.2', '8.1', '8.0'];
        
        foreach ($drives as $drive) {
            foreach ($versions as $version) {
                $phpPath = "$drive:\\OpenServer\\modules\\php\\PHP_$version\\php.exe";
                if (file_exists($phpPath)) {
                    return $phpPath;
                }
            }
        }
        
        // Пробуем найти в PATH
        $phpPath = trim(shell_exec('where php 2>nul'));
        if (!empty($phpPath) && file_exists($phpPath)) {
            return $phpPath;
        }
        
        return null;
    }
    
    /**
     * Запустить WebSocket сервер
     * @param bool $background Запустить в фоне без консоли
     */
    public static function start(bool $background = false): array
    {
        if (self::isRunning()) {
            return [
                'success' => false,
                'message' => 'WebSocket сервер уже запущен'
            ];
        }
        
        $projectRoot = realpath(__DIR__ . '/../..');
        $serverScript = $projectRoot . DIRECTORY_SEPARATOR . 'websocket' . DIRECTORY_SEPARATOR . 'chat-server.php';
        
        if (!file_exists($serverScript)) {
            return [
                'success' => false,
                'message' => 'Файл сервера не найден: websocket/chat-server.php'
            ];
        }
        
        // Находим PHP
        $phpPath = self::findPhpPath();
        if (!$phpPath) {
            return [
                'success' => false,
                'message' => 'PHP не найден. Убедитесь, что Open Server запущен или PHP добавлен в PATH.'
            ];
        }
        
        // Проверяем наличие Ratchet
        $ratchetPath = $projectRoot . DIRECTORY_SEPARATOR . 'vendor' . DIRECTORY_SEPARATOR . 'cboden' . DIRECTORY_SEPARATOR . 'ratchet';
        if (!is_dir($ratchetPath)) {
            return [
                'success' => false,
                'message' => 'Ratchet не установлен. Установите зависимости: composer install'
            ];
        }
        
        // Проверяем наличие react/socket для WSS
        $reactSocketPath = $projectRoot . DIRECTORY_SEPARATOR . 'vendor' . DIRECTORY_SEPARATOR . 'react' . DIRECTORY_SEPARATOR . 'socket';
        if (!is_dir($reactSocketPath)) {
            return [
                'success' => false,
                'message' => 'react/socket не установлен. Установите зависимости: composer install'
            ];
        }
        
        // Запуск только через bat-файл
        $batPath = $projectRoot . DIRECTORY_SEPARATOR . 'websocket' . DIRECTORY_SEPARATOR . 'start-server.bat';
        
        if (!file_exists($batPath)) {
            return [
                'success' => false,
                'message' => 'Файл запуска не найден: websocket/start-server.bat'
            ];
        }
        
        // Нормализуем путь для Windows
        $batPath = realpath($batPath);
        if (!$batPath) {
            return [
                'success' => false,
                'message' => 'Не удалось определить путь к файлу запуска'
            ];
        }
        
        $batPathNormalized = str_replace('/', '\\', $batPath);
        
        // Убеждаемся, что bat-файл существует
        if (!file_exists($batPathNormalized)) {
            return [
                'success' => false,
                'message' => 'Файл запуска не найден: ' . $batPathNormalized
            ];
        }
        
        // Запускаем bat-файл через cmd /c start
        // Синтаксис для Windows: start ["заголовок"] [/MIN] "путь"
        // Важно: первый параметр после start - это заголовок окна, может быть пустым ""
        $batPathQuoted = '"' . $batPathNormalized . '"';
        
        if ($background) {
            // /MIN - минимизированное окно
            $command = 'start /MIN "" ' . $batPathQuoted;
        } else {
            // Обычное окно консоли
            $command = 'start "" ' . $batPathQuoted;
        }
        
        // Выполняем команду через cmd /c для правильной обработки
        $fullCommand = 'cmd /c ' . $command;
        
        // Используем popen для асинхронного запуска (не блокирует выполнение PHP скрипта)
        $handle = @popen($fullCommand, 'r');
        if ($handle) {
            pclose($handle);
        } else {
            // Fallback: используем exec
            $output = [];
            $returnCode = 0;
            
            // Пробуем запустить через exec (перенаправляем вывод в null)
            exec($fullCommand . ' >nul 2>&1', $output, $returnCode);
            
            // Логируем для отладки
            error_log("WebSocket start command: $fullCommand (return code: $returnCode)");
            
            // Если exec вернул ошибку, это не критично - процесс может запуститься асинхронно
            // Проверка статуса будет выполнена отдельным запросом
        }
        
        // Запускаем в фоне и сразу возвращаем успех
        // Проверка статуса будет выполнена отдельным запросом через checkWebSocketStatus
        return [
            'success' => true,
            'message' => 'Команда запуска отправлена. Проверьте статус через несколько секунд.',
            'check_status' => true
        ];
    }
    
    /**
     * Остановить WebSocket сервер
     */
    public static function stop(): array
    {
        if (!self::isRunning()) {
            return [
                'success' => false,
                'message' => 'WebSocket сервер не запущен'
            ];
        }
        
        // Находим PID процесса, который слушает порт 8082
        // Используем netstat для поиска PID процесса на порту 8082
        $command = 'netstat -ano | findstr :8082';
        exec($command, $output, $returnCode);
        
        $pids = [];
        foreach ($output as $line) {
            // Ищем строки вида: TCP    0.0.0.0:8082    0.0.0.0:0    LISTENING       12345
            if (preg_match('/TCP\s+.*:8082\s+.*LISTENING\s+(\d+)/', $line, $matches)) {
                $pid = (int)$matches[1];
                if ($pid > 0 && !in_array($pid, $pids)) {
                    $pids[] = $pid;
                }
            }
        }
        
        // Если не нашли через netstat, пробуем через PowerShell Get-NetTCPConnection
        if (empty($pids)) {
            $psCommand = 'powershell -Command "Get-NetTCPConnection -LocalPort 8082 -ErrorAction SilentlyContinue | Select-Object -ExpandProperty OwningProcess"';
            exec($psCommand, $psOutput, $psReturn);
            
            foreach ($psOutput as $pidStr) {
                $pid = (int)trim($pidStr);
                if ($pid > 0 && !in_array($pid, $pids)) {
                    $pids[] = $pid;
                }
            }
        }
        
        // Если все еще не нашли, ищем все процессы php.exe, которые могут быть нашим сервером
        if (empty($pids)) {
            $command = 'tasklist /FI "IMAGENAME eq php.exe" /FO LIST';
            exec($command, $taskOutput);
            
            $currentPid = null;
            foreach ($taskOutput as $line) {
                if (preg_match('/^PID:\s+(\d+)$/i', $line, $matches)) {
                    $currentPid = (int)$matches[1];
                } elseif ($currentPid && stripos($line, 'chat-server.php') !== false) {
                    if (!in_array($currentPid, $pids)) {
                        $pids[] = $currentPid;
                    }
                }
            }
        }
        
        if (empty($pids)) {
            // Сервер уже остановлен?
            if (!self::isRunning()) {
                return [
                    'success' => true,
                    'message' => 'WebSocket сервер уже остановлен'
                ];
            }
            return [
                'success' => false,
                'message' => 'Не удалось найти процесс WebSocket сервера. Возможно, он уже остановлен.'
            ];
        }
        
        // Останавливаем все найденные процессы
        $stoppedCount = 0;
        foreach ($pids as $pid) {
            // Останавливаем процесс через taskkill (F - принудительно)
            exec("taskkill /F /PID $pid 2>&1", $killOutput, $killReturn);
            if ($killReturn === 0) {
                $stoppedCount++;
            }
        }
        
        // Даем процессу время на остановку (до 3 секунд)
        $maxAttempts = 3;
        $attempt = 0;
        while ($attempt < $maxAttempts) {
            sleep(1);
            if (!self::isRunning()) {
                return [
                    'success' => true,
                    'message' => 'WebSocket сервер успешно остановлен (остановлено процессов: ' . $stoppedCount . ')'
                ];
            }
            $attempt++;
        }
        
        // Если после попыток все еще работает, возможно есть другие процессы
        if (self::isRunning()) {
            return [
                'success' => false,
                'message' => 'Частично остановлен. Возможно, сервер все еще работает. Попробуйте перезапустить.'
            ];
        }
        
        return [
            'success' => true,
            'message' => 'WebSocket сервер остановлен'
        ];
    }
    
    /**
     * Перезапустить WebSocket сервер
     */
    public static function restart(): array
    {
        $stopResult = self::stop();
        if (!$stopResult['success'] && self::isRunning()) {
            // Если сервер запущен, но stop вернул ошибку, все равно пытаемся перезапустить
        }
        
        sleep(1);
        
        $startResult = self::start();
        
        if ($startResult['success']) {
            return [
                'success' => true,
                'message' => 'WebSocket сервер успешно перезапущен'
            ];
        } else {
            return [
                'success' => false,
                'message' => 'Не удалось перезапустить WebSocket сервер: ' . $startResult['message']
            ];
        }
    }
    
    /**
     * Уведомить пользователя о необходимости обновить счетчики непрочитанных
     * 
     * Примечание: Так как WebSocket сервер работает в отдельном процессе,
     * прямой вызов невозможен. Счетчики автоматически обновляются каждые 3 секунды
     * для всех подключенных пользователей. Этот метод добавлен для совместимости.
     * 
     * @param int $userId ID пользователя
     * @return void
     */
    public static function notifyUserUnreadCounts(int $userId): void
    {
        // WebSocket сервер автоматически обновляет счетчики каждые 3 секунды
        // для всех подключенных пользователей через периодический таймер.
        // Этот метод оставлен для совместимости с существующим кодом.
        // В будущем можно добавить файловый триггер или UDP для мгновенных обновлений.
    }
}
