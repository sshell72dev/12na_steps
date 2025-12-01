# GitHub Repository Setup Script
# Usage: .\setup-github.ps1

$env:PATH += ";C:\Program Files\Git\bin"

$repoName = "12na"
$description = "WordPress theme for 12 steps site"
$githubToken = $env:GITHUB_TOKEN

Write-Host "`nGitHub Repository Setup" -ForegroundColor Green
Write-Host "======================" -ForegroundColor Cyan

if ([string]::IsNullOrEmpty($githubToken)) {
    Write-Host "`nGitHub token not found in environment variables." -ForegroundColor Yellow
    Write-Host "For automatic repository creation, you need a Personal Access Token." -ForegroundColor Yellow
    Write-Host "Create token: https://github.com/settings/tokens" -ForegroundColor Cyan
    Write-Host "Required permissions: repo (all sub-items)" -ForegroundColor Cyan
    Write-Host ""
    Write-Host "Manual setup:" -ForegroundColor Green
    Write-Host "1. Open: https://github.com/new" -ForegroundColor Cyan
    Write-Host "2. Repository name: $repoName" -ForegroundColor White
    Write-Host "3. Description: $description" -ForegroundColor White
    Write-Host "4. DO NOT add README, .gitignore, or license" -ForegroundColor Yellow
    Write-Host "5. Click 'Create repository'" -ForegroundColor Cyan
    Write-Host ""
    
    $githubUrl = Read-Host "Enter the repository URL (e.g., https://github.com/username/$repoName.git)"
    
    if ([string]::IsNullOrEmpty($githubUrl)) {
        Write-Host "URL not provided. Exiting." -ForegroundColor Red
        exit 1
    }
} else {
    Write-Host "Token found. Creating repository via API..." -ForegroundColor Green
    
    $headers = @{
        "Authorization" = "token $githubToken"
        "Accept" = "application/vnd.github.v3+json"
    }
    
    try {
        $userResponse = Invoke-RestMethod -Uri "https://api.github.com/user" -Headers $headers -Method Get
        $githubUser = $userResponse.login
        Write-Host "Authenticated as: $githubUser" -ForegroundColor Green
    } catch {
        Write-Host "Authentication error: $_" -ForegroundColor Red
        exit 1
    }
    
    $body = @{
        name = $repoName
        description = $description
        private = $false
        auto_init = $false
    } | ConvertTo-Json
    
    try {
        Write-Host "Creating repository '$repoName'..." -ForegroundColor Yellow
        $repoResponse = Invoke-RestMethod -Uri "https://api.github.com/user/repos" -Headers $headers -Method Post -Body $body -ContentType "application/json"
        $githubUrl = $repoResponse.clone_url
        Write-Host "Repository created: $githubUrl" -ForegroundColor Green
    } catch {
        Write-Host "Error creating repository: $_" -ForegroundColor Red
        if ($_.Exception.Response.StatusCode -eq 422) {
            Write-Host "Repository with this name already exists!" -ForegroundColor Yellow
            $githubUrl = "https://github.com/$githubUser/$repoName.git"
            Write-Host "Using existing: $githubUrl" -ForegroundColor Yellow
        } else {
            exit 1
        }
    }
}

Write-Host "`nConnecting local repository..." -ForegroundColor Green

git remote remove origin 2>$null
git remote add origin $githubUrl

Write-Host "`nChecking connection..." -ForegroundColor Yellow
git remote -v

$currentBranch = git branch --show-current
if ($currentBranch -ne "main") {
    Write-Host "`nRenaming branch to main..." -ForegroundColor Yellow
    git branch -M main
}

Write-Host "`nPushing code to GitHub..." -ForegroundColor Green

if (-not [string]::IsNullOrEmpty($githubToken)) {
    $githubUrlWithToken = $githubUrl -replace 'https://', "https://$githubToken@"
    try {
        git push -u origin main
        Write-Host "`nSuccess! Code pushed to GitHub!" -ForegroundColor Green
    } catch {
        Write-Host "`nTry manually: git push -u origin main" -ForegroundColor Yellow
    }
} else {
    Write-Host "`nRun this command to push:" -ForegroundColor Yellow
    Write-Host "   git push -u origin main" -ForegroundColor Cyan
    Write-Host "`nWhen prompted for password, use your Personal Access Token (not GitHub password!)" -ForegroundColor Yellow
}

Write-Host "`nRepository: $githubUrl" -ForegroundColor Green
Write-Host "======================" -ForegroundColor Cyan

