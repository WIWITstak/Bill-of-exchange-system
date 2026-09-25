<?php

namespace OGAS\Services;

/**
 * Сервис для мониторинга сервера (CPU, память, диск, сеть)
 */
class ServerMonitoringService
{
    /**
     * Получить все метрики сервера
     */
    public static function getMetrics(): array
    {
        $metrics = [
            'cpu' => [],
            'memory' => [],
            'disk' => [],
            'disk_io' => [],
            'network' => [],
            'uptime' => [],
            'timestamp' => time(),
            'datetime' => date('Y-m-d H:i:s'),
            'os' => PHP_OS,
            'debug' => []
        ];
        
        // Получаем метрики с обработкой ошибок
        try {
            $metrics['cpu'] = self::getCpuUsage();
        } catch (\Throwable $e) {
            error_log('Error getting CPU metrics: ' . $e->getMessage());
            $metrics['cpu'] = ['error' => $e->getMessage()];
        }
        
        try {
            $metrics['memory'] = self::getMemoryUsage();
        } catch (\Throwable $e) {
            error_log('Error getting memory metrics: ' . $e->getMessage());
            $metrics['memory'] = ['error' => $e->getMessage()];
        }
        
        try {
            $metrics['disk'] = self::getDiskUsage();
        } catch (\Throwable $e) {
            error_log('Error getting disk metrics: ' . $e->getMessage());
            $metrics['disk'] = ['error' => $e->getMessage()];
        }
        
        try {
            $metrics['disk_io'] = self::getDiskIO();
        } catch (\Throwable $e) {
            error_log('Error getting disk IO metrics: ' . $e->getMessage());
            $metrics['disk_io'] = ['error' => $e->getMessage()];
        }
        
        try {
            $metrics['network'] = self::getNetworkStats();
        } catch (\Throwable $e) {
            error_log('Error getting network metrics: ' . $e->getMessage());
            $metrics['network'] = ['error' => $e->getMessage()];
        }
        
        try {
            $metrics['uptime'] = self::getUptime();
        } catch (\Throwable $e) {
            error_log('Error getting uptime metrics: ' . $e->getMessage());
            $metrics['uptime'] = ['error' => $e->getMessage()];
        }
        
        // Добавляем отладочную информацию, если метрики пустые
        if (($metrics['cpu']['usage_percent'] ?? 0) == 0 && 
            ($metrics['memory']['total_bytes'] ?? 0) == 0) {
            try {
                $metrics['debug']['test_command'] = self::testCommands();
            } catch (\Throwable $e) {
                error_log('Error testing commands: ' . $e->getMessage());
            }
        }
        
        return $metrics;
    }
    
    /**
     * Тестирование команд для отладки
     */
    private static function testCommands(): array
    {
        $results = [];
        
        if (strtoupper(substr(PHP_OS, 0, 3)) === 'WIN') {
            // Тест PowerShell
            exec('powershell -NoProfile -Command "Write-Host \'PowerShell works\'"', $psOutput, $psReturn);
            $results['powershell'] = [
                'available' => $psReturn === 0,
                'output' => implode(' ', $psOutput)
            ];
            
            // Тест WMI
            exec('wmic cpu get NumberOfCores /value 2>nul', $wmiOutput, $wmiReturn);
            $results['wmi'] = [
                'available' => $wmiReturn === 0,
                'output' => implode(' ', array_slice($wmiOutput, 0, 3))
            ];
        }
        
        return $results;
    }
    
    /**
     * Получить использование CPU
     */
    public static function getCpuUsage(): array
    {
        if (strtoupper(substr(PHP_OS, 0, 3)) === 'WIN') {
            return self::getCpuUsageWindows();
        } else {
            return self::getCpuUsageUnix();
        }
    }
    
    /**
     * Получить использование CPU (Windows)
     */
    private static function getCpuUsageWindows(): array
    {
        $cpuUsage = 0;
        $cores = 1;
        $logicalProcessors = 1;
        
        try {
            // Используем PowerShell для получения метрик CPU
            // Исправляем команду - используем правильный синтаксис
            $command = 'powershell -NoProfile -Command "Get-WmiObject Win32_Processor | Measure-Object -Property LoadPercentage -Average | Select-Object -ExpandProperty Average" 2>&1';
            @exec($command, $output, $returnCode);
        
            // Обрабатываем вывод - может быть несколько строк
            foreach ($output as $line) {
                $line = trim($line);
                // Пропускаем строки с ошибками PowerShell
                if (stripos($line, 'error') !== false || stripos($line, 'exception') !== false) {
                    continue;
                }
                if (is_numeric($line)) {
                    $cpuUsage = (float)$line;
                    break;
                }
            }
        } catch (\Throwable $e) {
            error_log('PowerShell CPU command failed: ' . $e->getMessage());
        }
        
        // Если не получили через WMI, пробуем альтернативный способ
        if ($cpuUsage == 0) {
            try {
                // Используем typeperf для получения загрузки CPU
                $typeperfCmd = 'typeperf "\\Processor(_Total)\\% Processor Time" -sc 1 2>nul';
                @exec($typeperfCmd, $typeperfOutput, $typeperfReturn);
                
                if (!empty($typeperfOutput)) {
                    foreach ($typeperfOutput as $line) {
                        if (preg_match('/"([\d.]+)"/', $line, $matches)) {
                            $cpuUsage = (float)$matches[1];
                            break;
                        }
                    }
                }
            } catch (\Throwable $e) {
                error_log('Typeperf CPU command failed: ' . $e->getMessage());
            }
        }
        
        // Получаем количество физических ядер
        try {
            $coresCommand = 'powershell -NoProfile -Command "(Get-WmiObject Win32_Processor | Measure-Object -Property NumberOfCores -Sum).Sum" 2>&1';
            @exec($coresCommand, $coresOutput, $coresReturn);
            
            foreach ($coresOutput as $line) {
                $line = trim($line);
                if (stripos($line, 'error') === false && is_numeric($line)) {
                    $cores = (int)$line;
                    break;
                }
            }
        } catch (\Throwable $e) {
            error_log('PowerShell cores command failed: ' . $e->getMessage());
        }
        
        // Если не получили, пробуем другой способ
        if ($cores == 1) {
            try {
                $coresCommand2 = 'wmic cpu get NumberOfCores /value 2>nul';
                @exec($coresCommand2, $coresOutput2, $coresReturn2);
                foreach ($coresOutput2 as $line) {
                    if (preg_match('/NumberOfCores=(\d+)/', $line, $matches)) {
                        $cores = (int)$matches[1];
                        break;
                    }
                }
            } catch (\Throwable $e) {
                error_log('WMI cores command failed: ' . $e->getMessage());
            }
        }
        
        // Получаем количество логических процессоров (ядра + потоки/гипертрединг)
        try {
            $logicalCommand = 'powershell -NoProfile -Command "(Get-WmiObject Win32_ComputerSystem).NumberOfLogicalProcessors" 2>&1';
            @exec($logicalCommand, $logicalOutput, $logicalReturn);
            
            foreach ($logicalOutput as $line) {
                $line = trim($line);
                if (stripos($line, 'error') === false && is_numeric($line)) {
                    $logicalProcessors = (int)$line;
                    break;
                }
            }
        } catch (\Throwable $e) {
            error_log('PowerShell logical processors command failed: ' . $e->getMessage());
        }
        
        // Альтернативный способ через wmic
        if ($logicalProcessors == 1) {
            try {
                $logicalCommand2 = 'wmic cpu get NumberOfLogicalProcessors /value 2>nul';
                @exec($logicalCommand2, $logicalOutput2, $logicalReturn2);
                foreach ($logicalOutput2 as $line) {
                    if (preg_match('/NumberOfLogicalProcessors=(\d+)/', $line, $matches)) {
                        $logicalProcessors = (int)$matches[1];
                        break;
                    }
                }
            } catch (\Throwable $e) {
                error_log('WMI logical processors command failed: ' . $e->getMessage());
            }
        }
        
        // Если все еще не получили, используем PHP функцию
        if ($logicalProcessors == 1) {
            try {
                $envProcessors = @shell_exec('echo %NUMBER_OF_PROCESSORS%');
                if ($envProcessors && is_numeric(trim($envProcessors))) {
                    $logicalProcessors = (int)trim($envProcessors);
                }
                if ($logicalProcessors < 1) {
                    $logicalProcessors = $cores; // Fallback на количество ядер
                }
            } catch (\Throwable $e) {
                $logicalProcessors = $cores; // Fallback на количество ядер
            }
        }
        
        return [
            'usage_percent' => round($cpuUsage, 2),
            'cores' => $cores,
            'logical_processors' => $logicalProcessors,
            'threads' => $logicalProcessors - $cores, // Количество потоков (гипертрединг)
            'load_average' => null // Windows не имеет load average
        ];
    }
    
    /**
     * Получить использование CPU (Unix/Linux)
     */
    private static function getCpuUsageUnix(): array
    {
        $cpuUsage = 0;
        $loadAverage = [0, 0, 0];
        $cores = 1;
        
        // Получаем load average
        if (function_exists('sys_getloadavg')) {
            $loadAverage = sys_getloadavg();
        } else {
            $loadAvgFile = '/proc/loadavg';
            if (file_exists($loadAvgFile)) {
                $loadAvgData = file_get_contents($loadAvgFile);
                if ($loadAvgData) {
                    $parts = explode(' ', trim($loadAvgData));
                    $loadAverage = [
                        (float)($parts[0] ?? 0),
                        (float)($parts[1] ?? 0),
                        (float)($parts[2] ?? 0)
                    ];
                }
            }
        }
        
        // Получаем количество физических ядер
        $coresCommand = 'nproc --all 2>/dev/null || nproc 2>/dev/null || sysctl -n hw.ncpu 2>/dev/null || echo 1';
        exec($coresCommand, $coresOutput, $coresReturn);
        if (!empty($coresOutput) && is_numeric($coresOutput[0])) {
            $cores = (int)$coresOutput[0];
        }
        
        // Получаем количество логических процессоров (ядра + потоки)
        $logicalCommand = 'nproc 2>/dev/null || sysctl -n hw.ncpu 2>/dev/null || echo 1';
        exec($logicalCommand, $logicalOutput, $logicalReturn);
        $logicalProcessors = $cores;
        if (!empty($logicalOutput) && is_numeric($logicalOutput[0])) {
            $logicalProcessors = (int)$logicalOutput[0];
        }
        
        // Альтернативный способ через /proc/cpuinfo
        if ($logicalProcessors == $cores) {
            $cpuinfoFile = '/proc/cpuinfo';
            if (file_exists($cpuinfoFile)) {
                $cpuinfo = file_get_contents($cpuinfoFile);
                // Считаем количество уникальных процессоров
                preg_match_all('/^processor\s*:/m', $cpuinfo, $matches);
                $logicalProcessors = count($matches[0]);
                
                // Считаем физические ядра
                preg_match_all('/^physical id\s*:\s*(\d+)/m', $cpuinfo, $physicalMatches);
                $uniquePhysicalIds = count(array_unique($physicalMatches[1]));
                if ($uniquePhysicalIds > 0) {
                    preg_match_all('/^cpu cores\s*:\s*(\d+)/m', $cpuinfo, $coresMatches);
                    if (!empty($coresMatches[1])) {
                        $cores = (int)$coresMatches[1][0] * $uniquePhysicalIds;
                    }
                }
            }
        }
        
        // Вычисляем использование CPU из load average
        if ($logicalProcessors > 0) {
            $cpuUsage = min(100, ($loadAverage[0] / $logicalProcessors) * 100);
        }
        
        return [
            'usage_percent' => round($cpuUsage, 2),
            'cores' => $cores,
            'logical_processors' => $logicalProcessors,
            'threads' => $logicalProcessors - $cores, // Количество потоков
            'load_average' => [
                '1min' => round($loadAverage[0], 2),
                '5min' => round($loadAverage[1], 2),
                '15min' => round($loadAverage[2], 2)
            ]
        ];
    }
    
    /**
     * Получить использование памяти
     */
    public static function getMemoryUsage(): array
    {
        if (strtoupper(substr(PHP_OS, 0, 3)) === 'WIN') {
            return self::getMemoryUsageWindows();
        } else {
            return self::getMemoryUsageUnix();
        }
    }
    
    /**
     * Получить использование памяти (Windows)
     */
    private static function getMemoryUsageWindows(): array
    {
        $total = 0;
        $free = 0;
        $used = 0;
        
        try {
            // Используем PowerShell для получения метрик памяти
            $command = 'powershell -NoProfile -Command "$mem = Get-WmiObject Win32_OperatingSystem; Write-Host ([math]::Round($mem.TotalVisibleMemorySize * 1024, 0)); Write-Host ([math]::Round($mem.FreePhysicalMemory * 1024, 0))" 2>&1';
            @exec($command, $output, $returnCode);
            
            // Обрабатываем вывод построчно
            $values = [];
            foreach ($output as $line) {
                $line = trim($line);
                // Пропускаем строки с ошибками PowerShell
                if (stripos($line, 'error') !== false || stripos($line, 'exception') !== false) {
                    continue;
                }
                if (is_numeric($line)) {
                    $values[] = (float)$line;
                }
            }
            
            if (count($values) >= 2) {
                $total = $values[0];
                $free = $values[1];
                $used = $total - $free;
            }
        } catch (\Throwable $e) {
            error_log('PowerShell memory command failed: ' . $e->getMessage());
        }
        
        // Если не получили через PowerShell, пробуем wmic
        if ($total == 0) {
            try {
                $wmicCommand = 'wmic OS get TotalVisibleMemorySize,FreePhysicalMemory /value 2>nul';
                @exec($wmicCommand, $wmicOutput, $wmicReturn);
                
                foreach ($wmicOutput as $line) {
                    if (preg_match('/TotalVisibleMemorySize=(\d+)/', $line, $matches)) {
                        $total = (float)$matches[1] * 1024; // Конвертируем из KB в байты
                    }
                    if (preg_match('/FreePhysicalMemory=(\d+)/', $line, $matches)) {
                        $free = (float)$matches[1] * 1024; // Конвертируем из KB в байты
                    }
                }
                $used = $total - $free;
            } catch (\Throwable $e) {
                error_log('WMI memory command failed: ' . $e->getMessage());
            }
        }
        
        $usagePercent = $total > 0 ? ($used / $total) * 100 : 0;
        
        return [
            'total_bytes' => $total,
            'used_bytes' => $used,
            'free_bytes' => $free,
            'usage_percent' => round($usagePercent, 2),
            'total_formatted' => self::formatBytes($total),
            'used_formatted' => self::formatBytes($used),
            'free_formatted' => self::formatBytes($free)
        ];
    }
    
    /**
     * Получить использование памяти (Unix/Linux)
     */
    private static function getMemoryUsageUnix(): array
    {
        $total = 0;
        $free = 0;
        $used = 0;
        
        // Читаем /proc/meminfo
        $memInfoFile = '/proc/meminfo';
        if (file_exists($memInfoFile)) {
            $memInfo = file_get_contents($memInfoFile);
            preg_match('/MemTotal:\s+(\d+)\s+kB/i', $memInfo, $totalMatch);
            preg_match('/MemFree:\s+(\d+)\s+kB/i', $memInfo, $freeMatch);
            preg_match('/MemAvailable:\s+(\d+)\s+kB/i', $memInfo, $availableMatch);
            
            if (!empty($totalMatch[1])) {
                $total = (float)$totalMatch[1] * 1024; // Конвертируем в байты
            }
            
            if (!empty($availableMatch[1])) {
                $free = (float)$availableMatch[1] * 1024;
            } elseif (!empty($freeMatch[1])) {
                $free = (float)$freeMatch[1] * 1024;
            }
            
            $used = $total - $free;
        }
        
        $usagePercent = $total > 0 ? ($used / $total) * 100 : 0;
        
        return [
            'total_bytes' => $total,
            'used_bytes' => $used,
            'free_bytes' => $free,
            'usage_percent' => round($usagePercent, 2),
            'total_formatted' => self::formatBytes($total),
            'used_formatted' => self::formatBytes($used),
            'free_formatted' => self::formatBytes($free)
        ];
    }
    
    /**
     * Получить использование диска
     */
    public static function getDiskUsage(): array
    {
        $projectRoot = realpath(__DIR__ . '/../..');
        $diskPath = $projectRoot ? dirname($projectRoot) : '/';
        
        if (strtoupper(substr(PHP_OS, 0, 3)) === 'WIN') {
            // Windows: получаем букву диска
            $diskPath = substr($projectRoot, 0, 2) . '\\';
            return self::getDiskUsageWindows($diskPath);
        } else {
            return self::getDiskUsageUnix($diskPath);
        }
    }
    
    /**
     * Получить использование диска (Windows)
     */
    private static function getDiskUsageWindows(string $drive): array
    {
        $total = 0;
        $free = 0;
        $used = 0;
        
        $driveLetter = rtrim($drive, '\\');
        
        // Используем PowerShell для получения метрик диска
        $command = sprintf(
            'powershell -NoProfile -Command "$disk = Get-WmiObject Win32_LogicalDisk -Filter \"DeviceID=\'%s\'\"; Write-Host ([math]::Round($disk.Size, 0)); Write-Host ([math]::Round($disk.FreeSpace, 0))"',
            $driveLetter
        );
        exec($command, $output, $returnCode);
        
        // Обрабатываем вывод построчно
        $values = [];
        foreach ($output as $line) {
            $line = trim($line);
            if (is_numeric($line)) {
                $values[] = (float)$line;
            }
        }
        
        if (count($values) >= 2) {
            $total = $values[0];
            $free = $values[1];
            $used = $total - $free;
        } else {
            // Альтернативный способ через wmic
            $wmicCommand = sprintf('wmic LogicalDisk where "DeviceID=\'%s\'" get Size,FreeSpace /value 2>nul', $driveLetter);
            exec($wmicCommand, $wmicOutput, $wmicReturn);
            
            foreach ($wmicOutput as $line) {
                if (preg_match('/Size=(\d+)/', $line, $matches)) {
                    $total = (float)$matches[1];
                }
                if (preg_match('/FreeSpace=(\d+)/', $line, $matches)) {
                    $free = (float)$matches[1];
                }
            }
            $used = $total - $free;
        }
        
        $usagePercent = $total > 0 ? ($used / $total) * 100 : 0;
        
        return [
            'total_bytes' => $total,
            'used_bytes' => $used,
            'free_bytes' => $free,
            'usage_percent' => round($usagePercent, 2),
            'total_formatted' => self::formatBytes($total),
            'used_formatted' => self::formatBytes($used),
            'free_formatted' => self::formatBytes($free),
            'drive' => $drive
        ];
    }
    
    /**
     * Получить использование диска (Unix/Linux)
     */
    private static function getDiskUsageUnix(string $path): array
    {
        $total = disk_total_space($path);
        $free = disk_free_space($path);
        $used = $total - $free;
        
        $usagePercent = $total > 0 ? ($used / $total) * 100 : 0;
        
        return [
            'total_bytes' => $total,
            'used_bytes' => $used,
            'free_bytes' => $free,
            'usage_percent' => round($usagePercent, 2),
            'total_formatted' => self::formatBytes($total),
            'used_formatted' => self::formatBytes($used),
            'free_formatted' => self::formatBytes($free),
            'path' => $path
        ];
    }
    
    /**
     * Получить статистику I/O диска
     */
    public static function getDiskIO(): array
    {
        if (strtoupper(substr(PHP_OS, 0, 3)) === 'WIN') {
            return self::getDiskIOWindows();
        } else {
            return self::getDiskIOUnix();
        }
    }
    
    /**
     * Получить статистику I/O диска (Windows)
     */
    private static function getDiskIOWindows(): array
    {
        $readBytes = 0;
        $writeBytes = 0;
        $readOps = 0;
        $writeOps = 0;
        
        try {
            // Используем PowerShell для получения статистики диска
            $command = 'powershell -NoProfile -Command "$disks = Get-WmiObject Win32_PerfRawData_PerfDisk_LogicalDisk | Where-Object {$_.Name -ne \"_Total\"}; $readBytes = ($disks | Measure-Object -Property DiskReadBytesPerSec -Sum).Sum; $writeBytes = ($disks | Measure-Object -Property DiskWriteBytesPerSec -Sum).Sum; $readOps = ($disks | Measure-Object -Property DiskReadsPerSec -Sum).Sum; $writeOps = ($disks | Measure-Object -Property DiskWritesPerSec -Sum).Sum; Write-Host $readBytes; Write-Host $writeBytes; Write-Host $readOps; Write-Host $writeOps" 2>&1';
            @exec($command, $output, $returnCode);
            
            // Обрабатываем вывод построчно
            $values = [];
            foreach ($output as $line) {
                $line = trim($line);
                if (stripos($line, 'error') === false && is_numeric($line)) {
                    $values[] = (float)$line;
                }
            }
            
            if (count($values) >= 4) {
                $readBytes = $values[0];
                $writeBytes = $values[1];
                $readOps = $values[2];
                $writeOps = $values[3];
            }
        } catch (\Throwable $e) {
            error_log('PowerShell disk IO command failed: ' . $e->getMessage());
        }
        
        return [
            'read_bytes_per_sec' => round($readBytes, 0),
            'write_bytes_per_sec' => round($writeBytes, 0),
            'read_ops_per_sec' => round($readOps, 0),
            'write_ops_per_sec' => round($writeOps, 0),
            'read_bytes_per_sec_formatted' => self::formatBytes($readBytes) . '/s',
            'write_bytes_per_sec_formatted' => self::formatBytes($writeBytes) . '/s',
            'total_bytes_per_sec' => round($readBytes + $writeBytes, 0),
            'total_bytes_per_sec_formatted' => self::formatBytes($readBytes + $writeBytes) . '/s',
            'total_ops_per_sec' => round($readOps + $writeOps, 0)
        ];
    }
    
    /**
     * Получить статистику I/O диска (Unix/Linux)
     */
    private static function getDiskIOUnix(): array
    {
        $readBytes = 0;
        $writeBytes = 0;
        $readOps = 0;
        $writeOps = 0;
        
        try {
            // Читаем /proc/diskstats
            $diskstatsFile = '/proc/diskstats';
            if (file_exists($diskstatsFile)) {
                $diskstats = file_get_contents($diskstatsFile);
                $lines = explode("\n", $diskstats);
                
                foreach ($lines as $line) {
                    // Формат: major minor name reads reads_merged reads_sectors reads_ms writes writes_merged writes_sectors writes_ms ...
                    // Пропускаем loopback устройства и разделы
                    if (preg_match('/^\s*\d+\s+\d+\s+(\w+)\s+(\d+)\s+\d+\s+(\d+)\s+\d+\s+(\d+)\s+\d+\s+(\d+)/', $line, $matches)) {
                        $device = $matches[1];
                        // Пропускаем loopback и разделы (sda1, sda2 и т.д.)
                        if (strpos($device, 'loop') === false && !preg_match('/\d+$/', $device)) {
                            $readOps += (int)$matches[2];
                            $readBytes += (int)$matches[3] * 512; // Секторы в байты
                            $writeOps += (int)$matches[4];
                            $writeBytes += (int)$matches[5] * 512; // Секторы в байты
                        }
                    }
                }
            }
        } catch (\Throwable $e) {
            error_log('Unix disk IO command failed: ' . $e->getMessage());
        }
        
        // Это кумулятивные значения, нужно вычислять разницу между измерениями
        // Для простоты возвращаем текущие значения
        // В реальном приложении нужно хранить предыдущие значения и вычислять разницу
        
        return [
            'read_bytes_per_sec' => round($readBytes, 0),
            'write_bytes_per_sec' => round($writeBytes, 0),
            'read_ops_per_sec' => round($readOps, 0),
            'write_ops_per_sec' => round($writeOps, 0),
            'read_bytes_per_sec_formatted' => self::formatBytes($readBytes) . '/s',
            'write_bytes_per_sec_formatted' => self::formatBytes($writeBytes) . '/s',
            'total_bytes_per_sec' => round($readBytes + $writeBytes, 0),
            'total_bytes_per_sec_formatted' => self::formatBytes($readBytes + $writeBytes) . '/s',
            'total_ops_per_sec' => round($readOps + $writeOps, 0),
            'note' => 'Значения кумулятивные. Для точных значений в секунду нужны два измерения.'
        ];
    }
    
    /**
     * Получить статистику сети
     */
    public static function getNetworkStats(): array
    {
        if (strtoupper(substr(PHP_OS, 0, 3)) === 'WIN') {
            return self::getNetworkStatsWindows();
        } else {
            return self::getNetworkStatsUnix();
        }
    }
    
    /**
     * Получить статистику сети (Windows)
     */
    private static function getNetworkStatsWindows(): array
    {
        $bytesReceived = 0;
        $bytesSent = 0;
        
        // Используем PowerShell для получения статистики сети
        $command = 'powershell -NoProfile -Command "$adapters = Get-NetAdapterStatistics | Where-Object {$_.InterfaceDescription -notlike \"*Loopback*\"}; $received = ($adapters | Measure-Object -Property ReceivedBytes -Sum).Sum; $sent = ($adapters | Measure-Object -Property SentBytes -Sum).Sum; Write-Host $received; Write-Host $sent"';
        exec($command, $output, $returnCode);
        
        // Обрабатываем вывод построчно
        $values = [];
        foreach ($output as $line) {
            $line = trim($line);
            if (is_numeric($line)) {
                $values[] = (float)$line;
            }
        }
        
        if (count($values) >= 2) {
            $bytesReceived = $values[0];
            $bytesSent = $values[1];
        } else {
            // Альтернативный способ через typeperf (менее точный, но работает)
            // Для Windows сложно получить точную статистику сети без специальных инструментов
            // Возвращаем 0, если не удалось получить данные
        }
        
        return [
            'bytes_received' => $bytesReceived,
            'bytes_sent' => $bytesSent,
            'bytes_received_formatted' => self::formatBytes($bytesReceived),
            'bytes_sent_formatted' => self::formatBytes($bytesSent),
            'total_bytes' => $bytesReceived + $bytesSent,
            'total_formatted' => self::formatBytes($bytesReceived + $bytesSent)
        ];
    }
    
    /**
     * Получить статистику сети (Unix/Linux)
     */
    private static function getNetworkStatsUnix(): array
    {
        $bytesReceived = 0;
        $bytesSent = 0;
        
        // Читаем /proc/net/dev
        $netDevFile = '/proc/net/dev';
        if (file_exists($netDevFile)) {
            $netDev = file_get_contents($netDevFile);
            $lines = explode("\n", $netDev);
            
            foreach ($lines as $line) {
                if (preg_match('/^\s*(\w+):\s+(\d+)\s+\d+\s+\d+\s+\d+\s+\d+\s+\d+\s+\d+\s+\d+\s+(\d+)/', $line, $matches)) {
                    $interface = $matches[1];
                    // Пропускаем loopback интерфейсы
                    if ($interface !== 'lo') {
                        $bytesReceived += (float)$matches[2];
                        $bytesSent += (float)$matches[3];
                    }
                }
            }
        }
        
        return [
            'bytes_received' => $bytesReceived,
            'bytes_sent' => $bytesSent,
            'bytes_received_formatted' => self::formatBytes($bytesReceived),
            'bytes_sent_formatted' => self::formatBytes($bytesSent),
            'total_bytes' => $bytesReceived + $bytesSent,
            'total_formatted' => self::formatBytes($bytesReceived + $bytesSent)
        ];
    }
    
    /**
     * Получить время работы сервера
     */
    public static function getUptime(): array
    {
        if (strtoupper(substr(PHP_OS, 0, 3)) === 'WIN') {
            return self::getUptimeWindows();
        } else {
            return self::getUptimeUnix();
        }
    }
    
    /**
     * Получить время работы сервера (Windows)
     */
    private static function getUptimeWindows(): array
    {
        $uptimeSeconds = 0;
        
        // Используем PowerShell для получения времени работы
        $command = 'powershell -NoProfile -Command "$bootTime = (Get-CimInstance Win32_OperatingSystem).LastBootUpTime; $uptime = (Get-Date) - $bootTime; Write-Host ([math]::Round($uptime.TotalSeconds, 0))"';
        exec($command, $output, $returnCode);
        
        // Обрабатываем вывод
        foreach ($output as $line) {
            $line = trim($line);
            if (is_numeric($line)) {
                $uptimeSeconds = (int)$line;
                break;
            }
        }
        
        // Альтернативный способ через wmic
        if ($uptimeSeconds == 0) {
            $wmicCommand = 'wmic OS get LastBootUpTime /value 2>nul';
            exec($wmicCommand, $wmicOutput, $wmicReturn);
            
            $bootTimeStr = '';
            foreach ($wmicOutput as $line) {
                if (preg_match('/LastBootUpTime=(\d+)/', $line, $matches)) {
                    $bootTimeStr = $matches[1];
                    break;
                }
            }
            
            if ($bootTimeStr) {
                // Формат WMI: YYYYMMDDHHmmss.ffffff+UUU
                $year = substr($bootTimeStr, 0, 4);
                $month = substr($bootTimeStr, 4, 2);
                $day = substr($bootTimeStr, 6, 2);
                $hour = substr($bootTimeStr, 8, 2);
                $minute = substr($bootTimeStr, 10, 2);
                $second = substr($bootTimeStr, 12, 2);
                
                $bootTime = mktime($hour, $minute, $second, $month, $day, $year);
                $uptimeSeconds = time() - $bootTime;
            }
        }
        
        return [
            'seconds' => $uptimeSeconds,
            'formatted' => self::formatUptime($uptimeSeconds)
        ];
    }
    
    /**
     * Получить время работы сервера (Unix/Linux)
     */
    private static function getUptimeUnix(): array
    {
        $uptimeSeconds = 0;
        
        // Читаем /proc/uptime
        $uptimeFile = '/proc/uptime';
        if (file_exists($uptimeFile)) {
            $uptimeData = file_get_contents($uptimeFile);
            $parts = explode(' ', trim($uptimeData));
            if (!empty($parts[0])) {
                $uptimeSeconds = (int)$parts[0];
            }
        }
        
        return [
            'seconds' => $uptimeSeconds,
            'formatted' => self::formatUptime($uptimeSeconds)
        ];
    }
    
    /**
     * Форматировать байты в читаемый формат
     */
    private static function formatBytes(float $bytes, int $precision = 2): string
    {
        $units = ['B', 'KB', 'MB', 'GB', 'TB', 'PB'];
        
        $bytes = max($bytes, 0);
        $pow = floor(($bytes ? log($bytes) : 0) / log(1024));
        $pow = min($pow, count($units) - 1);
        
        $bytes /= pow(1024, $pow);
        
        return round($bytes, $precision) . ' ' . $units[$pow];
    }
    
    /**
     * Форматировать время работы
     */
    private static function formatUptime(int $seconds): string
    {
        $days = floor($seconds / 86400);
        $hours = floor(($seconds % 86400) / 3600);
        $minutes = floor(($seconds % 3600) / 60);
        $secs = $seconds % 60;
        
        $parts = [];
        if ($days > 0) {
            $parts[] = $days . ' дн.';
        }
        if ($hours > 0) {
            $parts[] = $hours . ' ч.';
        }
        if ($minutes > 0) {
            $parts[] = $minutes . ' мин.';
        }
        if ($secs > 0 || empty($parts)) {
            $parts[] = $secs . ' сек.';
        }
        
        return implode(' ', $parts);
    }
}

