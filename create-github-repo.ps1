# Скрипт для автоматического создания репозитория на GitHub и подключения
# Использование: .\create-github-repo.ps1 -RepoName "12na" -GitHubToken "ваш-токен"

param(
    [Parameter(Mandatory=$false)]
    [string]$RepoName = "12na",
    
    [Parameter(Mandatory=$false)]
    [string]$GitHubToken = "",
    
    [Parameter(Mandatory=$false)]
    [string]$Description = "WordPress тема для сайта 12 шагов",
    
    [Parameter(Mandatory=$false)]
    [switch]$Private = $false
)

# Добавляем Git в PATH
$env:PATH += ";C:\Program Files\Git\bin"

Write-Host "`n🚀 Создание репозитория на GitHub..." -ForegroundColor Green
Write-Host "=" * 60 -ForegroundColor Cyan

# Если токен не предоставлен, пытаемся получить из переменной окружения
if ([string]::IsNullOrEmpty($GitHubToken)) {
    $GitHubToken = $env:GITHUB_TOKEN
}

if ([string]::IsNullOrEmpty($GitHubToken)) {
    Write-Host "`n⚠️  GitHub токен не найден!" -ForegroundColor Yellow
    Write-Host "Для автоматического создания репозитория нужен Personal Access Token." -ForegroundColor Yellow
    Write-Host "`nСоздайте токен здесь: https://github.com/settings/tokens" -ForegroundColor Cyan
    Write-Host "Нужны права: repo (все подпункты)" -ForegroundColor Cyan
    Write-Host "`nИли используйте один из вариантов:" -ForegroundColor Yellow
    Write-Host "1. Передайте токен как параметр: -GitHubToken 'ваш-токен'" -ForegroundColor White
    Write-Host "2. Установите переменную окружения: `$env:GITHUB_TOKEN = 'ваш-токен'" -ForegroundColor White
    Write-Host "`nПродолжаем в полуавтоматическом режиме..." -ForegroundColor Yellow
    Write-Host ""
    
    # Полуавтоматический режим
    Write-Host "📝 Инструкция:" -ForegroundColor Green
    Write-Host "1. Откройте: https://github.com/new" -ForegroundColor Cyan
    Write-Host "2. Название репозитория: $RepoName" -ForegroundColor White
    Write-Host "3. Описание: $Description" -ForegroundColor White
    Write-Host "4. Видимость: $(if($Private){'Private'}else{'Public'})" -ForegroundColor White
    Write-Host "5. НЕ добавляйте README, .gitignore или лицензию" -ForegroundColor Yellow
    Write-Host "6. Нажмите 'Create repository'" -ForegroundColor Cyan
    Write-Host ""
    
    $GitHubUrl = Read-Host "Введите URL созданного репозитория (например: https://github.com/username/$RepoName.git)"
    
    if ([string]::IsNullOrEmpty($GitHubUrl)) {
        Write-Host "❌ URL не предоставлен. Выход." -ForegroundColor Red
        exit 1
    }
    
    # Извлекаем username из URL
    if ($GitHubUrl -match 'github\.com/([^/]+)/') {
        $GitHubUser = $matches[1]
        Write-Host "✅ Найден пользователь: $GitHubUser" -ForegroundColor Green
    }
} else {
    Write-Host "✅ Токен найден, создаем репозиторий через API..." -ForegroundColor Green
    
    # Получаем информацию о пользователе
    $headers = @{
        "Authorization" = "token $GitHubToken"
        "Accept" = "application/vnd.github.v3+json"
    }
    
    try {
        $userResponse = Invoke-RestMethod -Uri "https://api.github.com/user" -Headers $headers -Method Get
        $GitHubUser = $userResponse.login
        Write-Host "✅ Авторизован как: $GitHubUser" -ForegroundColor Green
    } catch {
        Write-Host "❌ Ошибка авторизации: $_" -ForegroundColor Red
        exit 1
    }
    
    # Создаем репозиторий
    $body = @{
        name = $RepoName
        description = $Description
        private = $Private
        auto_init = $false
    } | ConvertTo-Json
    
    try {
        Write-Host "`n📦 Создание репозитория '$RepoName'..." -ForegroundColor Yellow
        $repoResponse = Invoke-RestMethod -Uri "https://api.github.com/user/repos" -Headers $headers -Method Post -Body $body -ContentType "application/json"
        $GitHubUrl = $repoResponse.clone_url
        Write-Host "✅ Репозиторий создан: $GitHubUrl" -ForegroundColor Green
    } catch {
        Write-Host "❌ Ошибка создания репозитория: $_" -ForegroundColor Red
        if ($_.Exception.Response.StatusCode -eq 422) {
            Write-Host "💡 Репозиторий с таким именем уже существует!" -ForegroundColor Yellow
            $GitHubUrl = "https://github.com/$GitHubUser/$RepoName.git"
            Write-Host "Используем существующий: $GitHubUrl" -ForegroundColor Yellow
        } else {
            exit 1
        }
    }
}

# Подключаем локальный репозиторий
Write-Host "`n🔗 Подключение локального репозитория..." -ForegroundColor Green

# Удаляем старый remote (если есть)
git remote remove origin 2>$null

# Добавляем новый remote
git remote add origin $GitHubUrl

# Проверяем
Write-Host "`n📋 Проверка подключения..." -ForegroundColor Yellow
git remote -v

# Переименовываем ветку в main (если нужно)
$currentBranch = git branch --show-current
if ($currentBranch -ne "main") {
    Write-Host "`n🔄 Переименование ветки в main..." -ForegroundColor Yellow
    git branch -M main
}

# Отправляем код
Write-Host "`n📤 Отправка кода на GitHub..." -ForegroundColor Green

if (-not [string]::IsNullOrEmpty($GitHubToken)) {
    # Используем токен для аутентификации
    $GitHubUrlWithToken = $GitHubUrl -replace 'https://', "https://$GitHubToken@"
    Write-Host "Используем токен для аутентификации..." -ForegroundColor Yellow
    
    try {
        git push -u origin main
        Write-Host "`n✅ Успешно! Код отправлен на GitHub!" -ForegroundColor Green
    } catch {
        Write-Host "`n⚠️  Попробуйте выполнить вручную:" -ForegroundColor Yellow
        Write-Host "   git push -u origin main" -ForegroundColor Cyan
        Write-Host "При запросе пароля используйте токен." -ForegroundColor Yellow
    }
} else {
    Write-Host "`n📝 Выполните команду для отправки кода:" -ForegroundColor Yellow
    Write-Host "   git push -u origin main" -ForegroundColor Cyan
    Write-Host "`nПри запросе пароля:" -ForegroundColor Yellow
    Write-Host "   Username: $GitHubUser" -ForegroundColor White
    Write-Host "   Password: ваш Personal Access Token" -ForegroundColor White
    Write-Host "   (НЕ пароль от GitHub!)" -ForegroundColor Yellow
    Write-Host "`nСоздать токен: https://github.com/settings/tokens" -ForegroundColor Cyan
}

Write-Host "`n🎉 Готово! Репозиторий: $GitHubUrl" -ForegroundColor Green
Write-Host "=" * 60 -ForegroundColor Cyan

