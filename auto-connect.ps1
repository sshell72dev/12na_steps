# Auto-connect to GitHub repository
# Usage: .\auto-connect.ps1 -GitHubUrl "https://github.com/username/12na.git"

param(
    [Parameter(Mandatory=$true)]
    [string]$GitHubUrl
)

$env:PATH += ";C:\Program Files\Git\bin"

Write-Host "Connecting to GitHub..." -ForegroundColor Green

git remote remove origin 2>$null
git remote add origin $GitHubUrl

Write-Host "Remote added:" -ForegroundColor Yellow
git remote -v

$currentBranch = git branch --show-current
if ($currentBranch -ne "main") {
    git branch -M main
}

Write-Host "`nReady to push! Run:" -ForegroundColor Green
Write-Host "   git push -u origin main" -ForegroundColor Cyan
Write-Host "`nWhen prompted, use your Personal Access Token as password." -ForegroundColor Yellow

