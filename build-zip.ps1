<#
.SYNOPSIS
    Build a clean production WordPress plugin ZIP into ./dist/
#>
$ErrorActionPreference = 'Stop'

$root = Split-Path -Parent $MyInvocation.MyCommand.Path
$mainFile = Join-Path $root 'hi-quick-buy.php'
$content = Get-Content -Path $mainFile -Raw -Encoding UTF8

$version = '1.0.0'
if ($content -match '(?m)^\s*\*\s*Version:\s*([0-9]+\.[0-9]+(?:\.[0-9]+)?)') {
    $version = $Matches[1]
}

$distDir = Join-Path $root 'dist'
$stageRoot = Join-Path ([System.IO.Path]::GetTempPath()) ('hiqb-build-' + [guid]::NewGuid().ToString('N'))
$pluginStage = Join-Path $stageRoot 'hi-quick-buy'

New-Item -ItemType Directory -Path $distDir -Force | Out-Null
New-Item -ItemType Directory -Path $pluginStage -Force | Out-Null

$includeItems = @(
    'hi-quick-buy.php',
    'uninstall.php',
    'README.md',
    'includes',
    'assets',
    'languages'
)

foreach ($item in $includeItems) {
    $src = Join-Path $root $item
    if (Test-Path $src) {
        Copy-Item -Path $src -Destination $pluginStage -Recurse -Force
    }
}

Add-Type -AssemblyName System.IO.Compression
Add-Type -AssemblyName System.IO.Compression.FileSystem

function New-PosixZip {
    param(
        [string]$SourceDir,
        [string]$ZipPath
    )
    if (Test-Path $ZipPath) {
        Remove-Item -Path $ZipPath -Force
    }
    $zip = [System.IO.Compression.ZipFile]::Open($ZipPath, [System.IO.Compression.ZipArchiveMode]::Create)
    try {
        $baseLen = $SourceDir.TrimEnd('\', '/').Length + 1
        Get-ChildItem -Path $SourceDir -Recurse -File | ForEach-Object {
            $relPath = $_.FullName.Substring($baseLen).Replace('\', '/')
            [System.IO.Compression.ZipFileExtensions]::CreateEntryFromFile(
                $zip,
                $_.FullName,
                $relPath,
                [System.IO.Compression.CompressionLevel]::Optimal
            ) | Out-Null
        }
    } finally {
        $zip.Dispose()
    }
}

$versionedZip = Join-Path $distDir ("hi-quick-buy-$version.zip")

New-PosixZip -SourceDir $stageRoot -ZipPath $versionedZip

Remove-Item -Path $stageRoot -Recurse -Force

$sizeKb = [Math]::Round((Get-Item $versionedZip).Length / 1KB, 1)
Write-Output "Built clean plugin ZIP ($sizeKb KB): $versionedZip"

