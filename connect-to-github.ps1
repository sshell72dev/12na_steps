# Скрипт для подключения к GitHub
# Использование: .\connect-to-github.ps1 -GitHubUrl "https://github.com/username/repo.git"

param(
    [Parameter(Mandatory=$true)]
    [string]$GitHubUrl
)

Write-Host "Подключение к GitHub..." -ForegroundColor Green

# Добавляем Git в PATH (если нужно)
$env:PATH += ";C:\Program Files\Git\bin"

# Проверяем наличие Git
try {
    $gitVersion = git --version
    Write-Host "Git найден: $gitVersion" -ForegroundColor Green
} catch {
    Write-Host "ОШИБКА: Git не найден!" -ForegroundColor Red
    exit 1
}

# Переименовываем ветку в main (если нужно)
Write-Host "`nПереименование ветки в main..." -ForegroundColor Yellow
git branch -M main

# Удаляем старый remote (если есть)
Write-Host "`nУдаление старого remote (если есть)..." -ForegroundColor Yellow
git remote remove origin 2>$null

# Добавляем новый remote
Write-Host "`nДобавление remote: $GitHubUrl" -ForegroundColor Yellow
git remote add origin $GitHubUrl

# Проверяем remote
Write-Host "`nПроверка remote..." -ForegroundColor Yellow
git remote -v

Write-Host "`n✅ Готово! Теперь выполните:" -ForegroundColor Green
Write-Host "   git push -u origin main" -ForegroundColor Cyan
Write-Host "`nПри запросе пароля используйте Personal Access Token (не пароль GitHub!)" -ForegroundColor Yellow
Write-Host "Создать токен: https://github.com/settings/tokens" -ForegroundColor Cyan

