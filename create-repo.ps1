# Create GitHub repository and connect
# This script will try to create repo via API, or guide you through manual process

$env:PATH += ";C:\Program Files\Git\bin"

$repoName = "12na"
$description = "WordPress theme for 12 steps site"

Write-Host "`n=== GitHub Repository Creation ===" -ForegroundColor Green
Write-Host "Repository name: $repoName" -ForegroundColor Cyan

# Try to get token from environment or ask user
$token = $env:GITHUB_TOKEN

if ([string]::IsNullOrEmpty($token)) {
    Write-Host "`nGitHub Personal Access Token required for automatic creation." -ForegroundColor Yellow
    Write-Host "Create one: https://github.com/settings/tokens (needs 'repo' permissions)" -ForegroundColor Cyan
    Write-Host ""
    $token = Read-Host "Enter token (or press Enter to create repo manually)"
}

if (-not [string]::IsNullOrEmpty($token)) {
    Write-Host "`nCreating repository via GitHub API..." -ForegroundColor Green
    
    $headers = @{
        "Authorization" = "token $token"
        "Accept" = "application/vnd.github.v3+json"
    }
    
    try {
        # Get user info
        $user = Invoke-RestMethod -Uri "https://api.github.com/user" -Headers $headers
        Write-Host "Authenticated as: $($user.login)" -ForegroundColor Green
        
        # Create repository
        $body = @{
            name = $repoName
            description = $description
            private = $false
            auto_init = $false
        } | ConvertTo-Json
        
        try {
            $repo = Invoke-RestMethod -Uri "https://api.github.com/user/repos" -Headers $headers -Method Post -Body $body -ContentType "application/json"
            $repoUrl = $repo.clone_url
            Write-Host "Repository created: $repoUrl" -ForegroundColor Green
        } catch {
            if ($_.Exception.Response.StatusCode -eq 422) {
                Write-Host "Repository '$repoName' already exists!" -ForegroundColor Yellow
                $repoUrl = "https://github.com/$($user.login)/$repoName.git"
            } else {
                throw
            }
        }
        
        # Connect local repo
        Write-Host "`nConnecting local repository..." -ForegroundColor Green
        git remote remove origin 2>$null
        git remote add origin $repoUrl
        
        Write-Host "Pushing code..." -ForegroundColor Green
        $repoUrlWithToken = $repoUrl -replace 'https://', "https://$token@"
        git push -u origin main
        
        Write-Host "`nSuccess! Repository created and connected!" -ForegroundColor Green
        Write-Host "View at: $($repoUrl -replace '\.git$', '')" -ForegroundColor Cyan
        
    } catch {
        Write-Host "`nError: $_" -ForegroundColor Red
        Write-Host "Falling back to manual method..." -ForegroundColor Yellow
        $token = $null
    }
}

if ([string]::IsNullOrEmpty($token)) {
    Write-Host "`n=== Manual Setup ===" -ForegroundColor Green
    Write-Host "1. Open: https://github.com/new?name=$repoName" -ForegroundColor Cyan
    Write-Host "2. Click 'Create repository' (don't add README)" -ForegroundColor White
    Write-Host "3. Copy the repository URL" -ForegroundColor White
    Write-Host ""
    
    $repoUrl = Read-Host "Enter repository URL (e.g., https://github.com/username/$repoName.git)"
    
    if (-not [string]::IsNullOrEmpty($repoUrl)) {
        Write-Host "`nConnecting..." -ForegroundColor Green
        git remote remove origin 2>$null
        git remote add origin $repoUrl
        
        Write-Host "`nRun this to push:" -ForegroundColor Yellow
        Write-Host "   git push -u origin main" -ForegroundColor Cyan
        Write-Host "`nUse Personal Access Token as password (not GitHub password!)" -ForegroundColor Yellow
    }
}

Write-Host "`n=== Done ===" -ForegroundColor Green

