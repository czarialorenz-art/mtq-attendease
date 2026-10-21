# MTQ-ATTENDEASE — build an upload-ready copy for InfinityFree.
#
# Run from the project folder:   powershell -ExecutionPolicy Bypass -File .\deploy-build.ps1
#
# It works on a COPY in .\deploy\mtq-attendease, so your own project (vendor with dev tools, .env,
# database) is never changed and nothing needs restoring afterwards.
#  - No "npm run build": the app is one static page (public/index.html); Vite isn't used.
#  - No config:cache / view:cache: those files would store this PC's C:\ paths, which don't exist
#    on the server. route:cache is skipped too; the app is fast enough without caches.

$ErrorActionPreference = 'Stop'
$root  = $PSScriptRoot
$out   = Join-Path $root 'deploy'
$stage = Join-Path $out 'mtq-attendease'
$zip   = Join-Path $out 'mtq-attendease.zip'

Write-Host "1/5 Preparing $stage" -ForegroundColor Cyan
# Only replace the previous build; keep anything else in deploy\ (your server .env, the .sql export)
foreach ($old in $stage, $zip) { if (Test-Path $old) { Remove-Item $old -Recurse -Force } }
New-Item -ItemType Directory -Path $stage -Force | Out-Null

# Folders and files that must NOT go to the server
$excludeDirs = @('.git', 'node_modules', 'vendor', 'tests', 'deploy', '.vscode', '.idea', '.claude',
                 'storage\logs', 'storage\framework', 'bootstrap\cache', 'public\storage') |
               ForEach-Object { Join-Path $root $_ }
$excludeFiles = @('.env', '.env.backup', '.env.production', '*.log', '.phpunit.result.cache', 'deploy-build.ps1',
                  'export-db.ps1', '*.zip', '*.sql', 'CLAUDE.md', 'AGENTS.md', 'package.json', 'package-lock.json',
                  'vite.config.js', 'phpunit.xml', '.editorconfig', '.npmrc', '.gitattributes')

Write-Host "2/5 Copying project files (no .env, .git, tests, logs, node_modules)" -ForegroundColor Cyan
robocopy $root $stage /E /NFL /NDL /NJH /NJS /NP /XD $excludeDirs /XF $excludeFiles | Out-Null
if ($LASTEXITCODE -ge 8) { throw "robocopy failed with code $LASTEXITCODE" }

# Empty folders Laravel needs to write into. A ZIP can't hold empty folders, so each gets a small
# .gitignore placeholder (same as Laravel's own) — otherwise they'd be missing on the server (HTTP 500).
foreach ($d in 'storage\logs', 'storage\framework\cache\data', 'storage\framework\sessions',
               'storage\framework\views', 'storage\framework\testing', 'bootstrap\cache') {
    $dir = Join-Path $stage $d
    New-Item -ItemType Directory -Path $dir -Force | Out-Null
    $keep = Join-Path $dir '.gitignore'
    if (-not (Test-Path $keep)) { [IO.File]::WriteAllText($keep, "*`n!.gitignore`n") }
}

Write-Host "3/5 composer install --no-dev --optimize-autoloader (in the copy)" -ForegroundColor Cyan
composer install --working-dir="$stage" --no-dev --optimize-autoloader --no-interaction --no-progress
if ($LASTEXITCODE -ne 0) { throw "composer install failed" }

Write-Host "4/5 Checking the result" -ForegroundColor Cyan
foreach ($f in '.env') {
    if (Test-Path (Join-Path $stage $f)) { throw "$f must not be in the upload folder" }
}
$big = Get-ChildItem $stage -Recurse -File | Where-Object { $_.Length -gt 1MB }
if ($big) {
    Write-Warning "Files over 1 MB (InfinityFree may reject them):"
    $big | ForEach-Object { Write-Warning ("  {0:N0} KB  {1}" -f ($_.Length / 1KB), $_.FullName.Substring($stage.Length + 1)) }
}
$count = (Get-ChildItem $stage -Recurse -File -Force).Count
$photos = (Get-ChildItem (Join-Path $stage 'storage\app') -Recurse -File | Where-Object { $_.Name -ne '.gitignore' }).Count
Write-Host ("   {0:N0} files total, {1} uploaded student photos included" -f $count, $photos)

Write-Host "5/5 Creating $zip (backup copy; uploading the folder with FileZilla is recommended)" -ForegroundColor Cyan
Add-Type -AssemblyName System.IO.Compression, System.IO.Compression.FileSystem
$archive = [System.IO.Compression.ZipFile]::Open($zip, 'Create')
try {
    Get-ChildItem $stage -Recurse -File -Force | ForEach-Object {
        # forward slashes so the zip unpacks correctly on the Linux server
        $entry = $_.FullName.Substring($stage.Length + 1).Replace('\', '/')
        [void][System.IO.Compression.ZipFileExtensions]::CreateEntryFromFile($archive, $_.FullName, $entry, 'Optimal')
    }
} finally { $archive.Dispose() }

Write-Host ""
Write-Host "Done. Upload the CONTENTS of:" -ForegroundColor Green
Write-Host "   $stage"
Write-Host "into htdocs/ on InfinityFree, then create htdocs/.env from .env.production.example."
