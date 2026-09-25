@echo off
cd /d "%~dp0\.."
set PHP_PATH=
for %%D in (F C D E) do (
    if exist "%%D:\OpenServer\modules\php\PHP_8.3\php.exe" (
        set PHP_PATH=%%D:\OpenServer\modules\php\PHP_8.3\php.exe
        goto php_found
    )
    if exist "%%D:\OpenServer\modules\php\PHP_8.2\php.exe" (
        set PHP_PATH=%%D:\OpenServer\modules\php\PHP_8.2\php.exe
        goto php_found
    )
    if exist "%%D:\OpenServer\modules\php\PHP_8.1\php.exe" (
        set PHP_PATH=%%D:\OpenServer\modules\php\PHP_8.1\php.exe
        goto php_found
    )
    if exist "%%D:\OpenServer\modules\php\PHP_8.0\php.exe" (
        set PHP_PATH=%%D:\OpenServer\modules\php\PHP_8.0\php.exe
        goto php_found
    )
)
where php >nul 2>&1
if not errorlevel 1 (
    set PHP_PATH=php
    goto php_found
)
echo PHP not found
pause
exit /b 1
:php_found
if not exist "websocket\chat-server.php" (
    echo chat-server.php not found
    pause
    exit /b 1
)
echo Starting WebSocket Server WSS
echo Server: wss://localhost:8082
echo Press Ctrl+C to stop
echo.
"%PHP_PATH%" websocket\chat-server.php
if errorlevel 1 pause
