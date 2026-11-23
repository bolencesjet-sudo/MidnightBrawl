# Run from PowerShell: .\setup-admin.ps1
# Prompts for a secret; no password is written to disk or printed.
param([switch]$ResetPassword)
$ErrorActionPreference = 'Stop'
$previousAdminEnvironment = @{}
foreach ($key in @('TF_ADMIN_USERNAME','TF_ADMIN_EMAIL','TF_ADMIN_NAME','TF_ADMIN_PASSWORD')) {
    $previousAdminEnvironment[$key] = [Environment]::GetEnvironmentVariable($key, 'Process')
}
$adminPasswordInput = $null
try {
    $chosenUsername = Read-Host 'Admin username [bolencesjet]'
    $env:TF_ADMIN_USERNAME = if ($chosenUsername) { $chosenUsername } else { 'bolencesjet' }
    $chosenEmail = Read-Host 'Admin email [bolencesjet@gmail.com]'
    $env:TF_ADMIN_EMAIL = if ($chosenEmail) { $chosenEmail } else { 'bolencesjet@gmail.com' }
    $env:TF_ADMIN_NAME = 'Administrator'
    $adminPasswordInput = Read-Host 'Choose a unique admin password (15+ characters, at most 72 bytes)' -AsSecureString
    $env:TF_ADMIN_PASSWORD = [System.Net.NetworkCredential]::new('', $adminPasswordInput).Password
    if ($ResetPassword) { & php (Join-Path $PSScriptRoot 'admin.php') --set-password }
    else { & php (Join-Path $PSScriptRoot 'admin.php') }
    if ($LASTEXITCODE -ne 0) { throw 'Admin creation failed. Check the database setup, password rules, and whether this email already exists.' }
} finally {
    foreach ($key in $previousAdminEnvironment.Keys) {
        [Environment]::SetEnvironmentVariable($key, $previousAdminEnvironment[$key], 'Process')
    }
    if ($null -ne $adminPasswordInput) { $adminPasswordInput.Dispose() }
}
