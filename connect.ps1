# Simple GitHub connection script
param([string]$Url = "")

$env:PATH += ";C:\Program Files\Git\bin"

if ([string]::IsNullOrEmpty($Url)) {
    Write-Host "Usage: .\connect.ps1 -Url 'https://github.com/username/12na.git'" -ForegroundColor Yellow
    Write-Host "`nOr provide URL now:" -ForegroundColor Cyan
    $Url = Read-Host "GitHub repository URL"
}

if ([string]::IsNullOrEmpty($Url)) {
    Write-Host "URL required. Exiting." -ForegroundColor Red
    exit 1
}

Write-Host "`nConnecting to: $Url" -ForegroundColor Green

git remote remove origin 2>$null
git remote add origin $Url

Write-Host "`nRemote configured:" -ForegroundColor Yellow
git remote -v

$branch = git branch --show-current
if ($branch -ne "main") {
    git branch -M main
    Write-Host "Branch renamed to: main" -ForegroundColor Green
}

Write-Host "`nPushing to GitHub..." -ForegroundColor Green
git push -u origin main

if ($LASTEXITCODE -eq 0) {
    Write-Host "`nSuccess! Repository connected and code pushed!" -ForegroundColor Green
} else {
    Write-Host "`nPush failed. You may need to:" -ForegroundColor Yellow
    Write-Host "1. Create repository on GitHub first: https://github.com/new" -ForegroundColor Cyan
    Write-Host "2. Use Personal Access Token as password (not GitHub password)" -ForegroundColor Yellow
    Write-Host "3. Try again: git push -u origin main" -ForegroundColor Cyan
}

