# Sync the plugin (and the security fixture theme) into the Local by WP Engine test site.
#
# Usage:  powershell -File tests\sync-to-local.ps1 [-SitePath "C:\Users\...\Local Sites\<site>\app\public"]
#
# The plugin folder in the Local site is a copy, not a junction, so run this after every change.

param(
    [string]$SitePath = "C:\Users\AbelCastillo\Local Sites\themeforestcheck\app\public"
)

$repo   = Split-Path -Parent $PSScriptRoot
$plugin = Join-Path $SitePath "wp-content\plugins\envato-theme-check"
$theme  = Join-Path $SitePath "wp-content\themes\tc-security-fixture"

if (-not (Test-Path $SitePath)) {
    Write-Error "Site path not found: $SitePath"
    exit 1
}

Write-Host "Plugin  -> $plugin"
robocopy $repo $plugin /MIR /NFL /NDL /NJH /NJS /XD ".git" ".claude" "openspec" "tests" "node_modules" "docs" | Out-Null
if ($LASTEXITCODE -ge 8) { Write-Error "robocopy (plugin) failed with code $LASTEXITCODE"; exit 1 }

Write-Host "Fixture -> $theme"
robocopy (Join-Path $repo "tests\fixtures\tc-security-fixture") $theme /MIR /NFL /NDL /NJH /NJS | Out-Null
if ($LASTEXITCODE -ge 8) { Write-Error "robocopy (fixture) failed with code $LASTEXITCODE"; exit 1 }

Write-Host "Done."
exit 0
