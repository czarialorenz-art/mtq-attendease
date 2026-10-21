# MTQ-ATTENDEASE — export the local database as a .sql file for InfinityFree's phpMyAdmin.
#
# Run:   powershell -ExecutionPolicy Bypass -File .\export-db.ps1
#        (asks for your MySQL root password; optional: -Database laravel -User root)
#
# The file contains only tables (no CREATE DATABASE / USE), so it imports into the database name
# InfinityFree gives you. Login tokens, cache, sessions and job tables are exported EMPTY so old
# logins from this PC don't carry over. MySQL 8+/9+ lines that MariaDB/older MySQL reject are removed.

param(
    [string]$Database = 'laravel',
    [string]$User = 'root',
    [string]$OutFile = (Join-Path $PSScriptRoot 'deploy\mtq-attendease.sql')
)
$ErrorActionPreference = 'Stop'

$dump = (Get-Command mysqldump -ErrorAction SilentlyContinue).Source
if (-not $dump) {
    $dump = Get-ChildItem 'C:\Program Files\MySQL\*\bin\mysqldump.exe' -ErrorAction SilentlyContinue |
            Select-Object -Last 1 -ExpandProperty FullName
}
if (-not $dump) { throw 'mysqldump.exe not found. Add MySQL''s bin folder to PATH.' }

if (-not $env:MYSQL_PWD) {
    $sec = Read-Host "MySQL password for '$User'" -AsSecureString
    $env:MYSQL_PWD = [Runtime.InteropServices.Marshal]::PtrToStringAuto([Runtime.InteropServices.Marshal]::SecureStringToBSTR($sec))
    $clearAfter = $true
}

$emptyTables = 'cache', 'cache_locks', 'sessions', 'jobs', 'job_batches', 'failed_jobs', 'personal_access_tokens'
$common = @("--user=$User", '--default-character-set=utf8mb4', '--single-transaction', '--skip-lock-tables',
            '--set-gtid-purged=OFF', '--no-tablespaces', '--skip-triggers', '--hex-blob', '--skip-comments', '--compact')

try {
    Write-Host "Exporting structure of all tables from '$Database'..." -ForegroundColor Cyan
    $structure = & $dump @common --no-data $Database
    if ($LASTEXITCODE -ne 0) { throw 'mysqldump (structure) failed' }

    Write-Host "Exporting data (except: $($emptyTables -join ', '))..." -ForegroundColor Cyan
    $ignore = $emptyTables | ForEach-Object { "--ignore-table=$Database.$_" }
    $data = & $dump @common --no-create-info --complete-insert $Database @ignore
    if ($LASTEXITCODE -ne 0) { throw 'mysqldump (data) failed' }
} finally {
    if ($clearAfter) { Remove-Item Env:\MYSQL_PWD -ErrorAction SilentlyContinue }
}

$sql = @(
    '-- MTQ-ATTENDEASE database export for InfinityFree phpMyAdmin'
    "-- Created $(Get-Date -Format 'yyyy-MM-dd HH:mm') from '$Database'"
    'SET NAMES utf8mb4;'
    'SET FOREIGN_KEY_CHECKS=0;'
    "SET SQL_MODE='NO_AUTO_VALUE_ON_ZERO';"
    ''
) + $structure + '' + $data + @('', 'SET FOREIGN_KEY_CHECKS=1;') -join "`n"

# Make it import on MariaDB / older MySQL
$sql = $sql -replace 'utf8mb4_0900_ai_ci', 'utf8mb4_unicode_ci'          # MySQL-8-only collation
$sql = $sql -replace '(?m)^.*(GTID_PURGED|SQL_LOG_BIN).*\r?\n?', ''        # needs SUPER privilege
$sql = $sql -replace 'DEFINER=`[^`]+`@`[^`]+`\s*', ''                     # needs SUPER privilege
$sql = $sql -replace '/\*!80016 DEFAULT ENCRYPTION=''N'' \*/', ''

New-Item -ItemType Directory -Path (Split-Path $OutFile) -Force | Out-Null
[IO.File]::WriteAllText($OutFile, $sql, (New-Object Text.UTF8Encoding($false)))   # UTF-8 without BOM
$kb = [math]::Round((Get-Item $OutFile).Length / 1KB, 1)
Write-Host "Saved $OutFile ($kb KB). Import it in InfinityFree phpMyAdmin > your database > Import." -ForegroundColor Green
