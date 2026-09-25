@echo off
chcp 65001 >nul
echo ========================================
echo Установка Ratchet через Composer
echo ========================================
echo.

cd /d "%~dp0\.."

REM Используем PHP 8.0 из Open Server
set PHP_PATH=F:\OpenServer\modules\php\PHP_8.0\php.exe

REM Пробуем установить Ratchet разными способами

echo Способ 1: Через composer.phar (скачивание)...
echo.
%PHP_PATH% -r "copy('https://getcomposer.org/installer', 'composer-setup.php');" 2>nul
if exist "composer-setup.php" (
    %PHP_PATH% composer-setup.php 2>nul
    if exist "composer.phar" (
        echo Используется composer.phar
        %PHP_PATH% composer.phar require cboden/ratchet --no-interaction
        del composer-setup.php 2>nul
        del composer-installer.php 2>nul
        echo.
        echo Ratchet установлен!
        pause
        exit /b 0
    )
)

echo.
echo Способ 2: Проверка composer в PATH...
where composer >nul 2>&1
if not errorlevel 1 (
    echo Найден composer в PATH
    composer require cboden/ratchet --no-interaction
    echo.
    echo Ratchet установлен!
    pause
    exit /b 0
)

echo.
echo ОШИБКА: Не удалось найти или установить Composer
echo.
echo Установите Composer вручную:
echo 1. Скачайте с https://getcomposer.org/download/
echo 2. Сохраните как composer.phar в корне проекта
echo 3. Запустите: %PHP_PATH% composer.phar require cboden/ratchet
echo.
pause











