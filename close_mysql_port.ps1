# Скрипт для закрытия порта MySQL 3306 в брандмауэре Windows
# Запустите этот скрипт от имени администратора

Write-Host "Закрытие порта MySQL 3306 для безопасности..." -ForegroundColor Yellow

# Удаляем существующие правила, разрешающие MySQL
Write-Host "`nУдаление существующих правил для mysqld.exe..." -ForegroundColor Cyan
$rules = Get-NetFirewallRule | Where-Object {$_.DisplayName -like "*mysqld*"}
if ($rules) {
    $rules | Remove-NetFirewallRule -Confirm:$false
    Write-Host "Удалено правил: $($rules.Count)" -ForegroundColor Green
} else {
    Write-Host "Правила не найдены" -ForegroundColor Gray
}

# Создаем правило, блокирующее порт 3306 для входящих подключений
Write-Host "`nСоздание правила блокировки порта 3306..." -ForegroundColor Cyan
try {
    # Проверяем, существует ли уже такое правило
    $existingRule = Get-NetFirewallRule -DisplayName "Block MySQL Port 3306" -ErrorAction SilentlyContinue
    if ($existingRule) {
        Write-Host "Правило уже существует, удаляем старое..." -ForegroundColor Yellow
        Remove-NetFirewallRule -DisplayName "Block MySQL Port 3306" -Confirm:$false
    }
    
    # Создаем новое правило блокировки
    New-NetFirewallRule -DisplayName "Block MySQL Port 3306" `
        -Direction Inbound `
        -LocalPort 3306 `
        -Protocol TCP `
        -Action Block `
        -Description "Блокировка внешних подключений к MySQL для безопасности"
    
    Write-Host "Правило успешно создано!" -ForegroundColor Green
} catch {
    Write-Host "Ошибка при создании правила: $_" -ForegroundColor Red
    exit 1
}

# Проверяем результат
Write-Host "`nПроверка правил брандмауэра для MySQL:" -ForegroundColor Cyan
Get-NetFirewallRule | Where-Object {$_.DisplayName -like "*MySQL*"} | Format-Table DisplayName, Enabled, Direction, Action -AutoSize

Write-Host "`nГотово! Порт 3306 теперь заблокирован для входящих подключений." -ForegroundColor Green
Write-Host "MySQL будет доступен только через localhost (127.0.0.1)" -ForegroundColor Green
Write-Host "`nВАЖНО: Перезапустите MySQL через панель Open Server!" -ForegroundColor Yellow













