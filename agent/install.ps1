<#
    Krishna Printer - location agent installer for Windows.

    Installs the agent as a scheduled task that starts with Windows, on a PC
    that has the printer attached (USB or network). Nothing is exposed to the
    internet: the agent only makes outbound HTTPS requests to your server.

    Run in an ADMINISTRATOR PowerShell window:

        .\install.ps1 -Server https://print.example.com -Token kpms_xxxxx

    Requirements it checks for, and installs where it can:
      * Python 3.8 or newer
      * SumatraPDF  - Windows cannot print a PDF from a script without it
      * LibreOffice - only if you want Word/Excel/PowerPoint files to print
#>

[CmdletBinding()]
param(
    [Parameter(Mandatory = $true)][string] $Server,
    [Parameter(Mandatory = $true)][string] $Token,
    [int]    $PollInterval = 10,
    [string] $InstallDir   = "$env:ProgramData\KrishnaPrinter\agent",
    [switch] $NoVerifyTls,
    [switch] $SkipDeps
)

$ErrorActionPreference = 'Stop'

function Info($m) { Write-Host $m -ForegroundColor Cyan }
function Good($m) { Write-Host $m -ForegroundColor Green }
function Warn($m) { Write-Host $m -ForegroundColor Yellow }
function Fail($m) { Write-Host $m -ForegroundColor Red; exit 1 }

# --- Administrator -------------------------------------------------------
$identity  = [Security.Principal.WindowsIdentity]::GetCurrent()
$principal = New-Object Security.Principal.WindowsPrincipal($identity)
if (-not $principal.IsInRole([Security.Principal.WindowsBuiltInRole]::Administrator)) {
    Fail "Run this in an Administrator PowerShell window (right-click -> Run as administrator)."
}

# HTTPS, or loopback for a local trial - 127.0.0.1 is as local as the name is.
if ($Server -notmatch '^https://' -and $Server -notmatch '^http://(localhost|127\.0\.0\.1|\[::1\])') {
    Fail "The server address must start with https:// - the agent carries a token and customer documents."
}
$Server = $Server.TrimEnd('/')

Info "Installing the Krishna Printer agent into $InstallDir"

# --- Python --------------------------------------------------------------
# Written for Windows PowerShell 5.1, which is what Windows 10 ships - so no
# null-coalescing or ternary operators anywhere in this script.
$python = Get-Command python.exe -ErrorAction SilentlyContinue
if (-not $python) { $python = Get-Command python3.exe -ErrorAction SilentlyContinue }

if (-not $python -and -not $SkipDeps) {
    Info "Python was not found. Installing it with winget..."
    if (Get-Command winget -ErrorAction SilentlyContinue) {
        winget install --id Python.Python.3.12 --silent --accept-package-agreements --accept-source-agreements
        $env:Path = [Environment]::GetEnvironmentVariable('Path', 'Machine') + ';' +
                    [Environment]::GetEnvironmentVariable('Path', 'User')
        $python = Get-Command python.exe -ErrorAction SilentlyContinue
    }
}
if (-not $python) {
    Fail "Python 3 is required. Install it from https://www.python.org/downloads/ (tick 'Add python.exe to PATH') and run this again."
}

$version = & $python.Source -c "import sys; print('%d.%d' % sys.version_info[:2])"
Good "Python $version at $($python.Source)"

# --- SumatraPDF ----------------------------------------------------------
# Windows has no way to print a PDF silently from a script. SumatraPDF is a
# single portable executable that does exactly that, and nothing else.
$sumatra = Join-Path $InstallDir 'SumatraPDF.exe'
if (-not (Test-Path $sumatra)) {
    $existing = Get-Command SumatraPDF.exe -ErrorAction SilentlyContinue
    if ($existing) {
        $sumatra = $existing.Source
    }
    elseif (-not $SkipDeps) {
        Info "Installing SumatraPDF..."
        if (Get-Command winget -ErrorAction SilentlyContinue) {
            winget install --id SumatraPDF.SumatraPDF --silent --accept-package-agreements --accept-source-agreements
            $found = @(
                "$env:ProgramFiles\SumatraPDF\SumatraPDF.exe",
                "${env:ProgramFiles(x86)}\SumatraPDF\SumatraPDF.exe",
                "$env:LOCALAPPDATA\SumatraPDF\SumatraPDF.exe"
            ) | Where-Object { Test-Path $_ } | Select-Object -First 1
            if ($found) { $sumatra = $found }
        }
    }
}
if (-not (Test-Path $sumatra)) {
    Warn "SumatraPDF was not installed. Nothing can be printed until it is."
    Warn "Get it from https://www.sumatrapdfreader.org/download-free-pdf-viewer and re-run this script."
}
else {
    Good "SumatraPDF at $sumatra"
}

# --- LibreOffice (optional) ----------------------------------------------
# LibreOffice does not put itself on PATH, so it is looked for where it lands.
# The LOCALAPPDATA entry is a per-user install, which is what winget makes when
# it is not run as an administrator - missing it told people to install what
# they already had.
$soffice = @(
    "$env:ProgramFiles\LibreOffice\program\soffice.exe",
    "${env:ProgramFiles(x86)}\LibreOffice\program\soffice.exe",
    "$env:ProgramW6432\LibreOffice\program\soffice.exe",
    "$env:LOCALAPPDATA\Programs\LibreOffice\program\soffice.exe"
) | Where-Object { Test-Path $_ } | Select-Object -First 1

if ($soffice) {
    Good "LibreOffice at $soffice"
}
else {
    Warn "LibreOffice was not found. PDFs, JPGs and PNGs will print; Word, Excel and"
    Warn "PowerPoint files will be refused with a clear message until you install it:"
    Warn "  winget install --id TheDocumentFoundation.LibreOffice"
}

# --- Files ---------------------------------------------------------------
New-Item -ItemType Directory -Force -Path $InstallDir, "$InstallDir\work" | Out-Null

$source = Join-Path $PSScriptRoot 'kpms-agent.py'
if (-not (Test-Path $source)) { Fail "kpms-agent.py was not found next to this script." }
Copy-Item $source (Join-Path $InstallDir 'kpms-agent.py') -Force

if ((Test-Path $sumatra) -and ((Split-Path $sumatra -Parent) -ne $InstallDir)) {
    Copy-Item $sumatra (Join-Path $InstallDir 'SumatraPDF.exe') -Force -ErrorAction SilentlyContinue
}

# --- Configuration -------------------------------------------------------
# The token is a credential: the file is readable by Administrators and SYSTEM
# only, the same footing as the Linux installer's 0600.
$configPath = Join-Path $InstallDir 'config.json'
@{
    server        = $Server
    token         = $Token
    poll_interval = $PollInterval
    work_dir      = (Join-Path $InstallDir 'work')
    verify_tls    = (-not $NoVerifyTls.IsPresent)
} | ConvertTo-Json | Set-Variable -Name configJson

# Not Set-Content -Encoding UTF8: in Windows PowerShell 5.1 that writes a
# byte-order mark, and a BOM at the start of a JSON file is three characters
# that no JSON parser expects. Write real UTF-8 with no mark.
[IO.File]::WriteAllText($configPath, $configJson, (New-Object System.Text.UTF8Encoding $false))

$acl = Get-Acl $configPath
$acl.SetAccessRuleProtection($true, $false)
$acl.Access | ForEach-Object { $acl.RemoveAccessRule($_) | Out-Null }
foreach ($who in 'BUILTIN\Administrators', 'NT AUTHORITY\SYSTEM') {
    $acl.AddAccessRule((New-Object Security.AccessControl.FileSystemAccessRule(
        $who, 'FullControl', 'Allow'))) | Out-Null
}
Set-Acl -Path $configPath -AclObject $acl
Good "Configuration written to $configPath"

if ($NoVerifyTls) {
    Warn "TLS certificate verification is DISABLED. Only acceptable on a private network"
    Warn "with a self-signed certificate you control."
}

# --- Connection test -----------------------------------------------------
Info "Testing the connection..."
& $python.Source (Join-Path $InstallDir 'kpms-agent.py') --config $configPath --test
if ($LASTEXITCODE -ne 0) {
    Fail "The connection test failed. Fix the problem above, then run this script again."
}

# --- Scheduled task ------------------------------------------------------
# A scheduled task rather than a service: printers are per-session on Windows,
# and a task running as the logged-on user sees exactly the printers that user
# sees in the Printers panel. That is the behaviour people expect.
$taskName = 'KrishnaPrinterAgent'
Get-ScheduledTask -TaskName $taskName -ErrorAction SilentlyContinue |
    Unregister-ScheduledTask -Confirm:$false -ErrorAction SilentlyContinue

$action = New-ScheduledTaskAction -Execute $python.Source `
    -Argument "`"$InstallDir\kpms-agent.py`" --config `"$configPath`"" `
    -WorkingDirectory $InstallDir

$trigger  = New-ScheduledTaskTrigger -AtLogOn
$settings = New-ScheduledTaskSettingsSet -AllowStartIfOnBatteries `
    -DontStopIfGoingOnBatteries -StartWhenAvailable `
    -RestartInterval (New-TimeSpan -Minutes 1) -RestartCount 9999 `
    -ExecutionTimeLimit ([TimeSpan]::Zero)

# The principal matters more than it looks. Without one, the task is registered
# to run whether the user is logged on or not - which means session 0, with no
# desktop. SumatraPDF is a GUI application and cannot print there: it reports
# nothing useful and exits non-zero, so jobs fail with an error that says
# nothing about the real cause. Interactive logon keeps the agent in the user's
# own session, where the desktop and the printers both are.
$taskPrincipal = New-ScheduledTaskPrincipal -UserId "$env:USERDOMAIN\$env:USERNAME" `
    -LogonType Interactive -RunLevel Highest

Register-ScheduledTask -TaskName $taskName -Action $action -Trigger $trigger `
    -Settings $settings -Principal $taskPrincipal -Force | Out-Null

Start-ScheduledTask -TaskName $taskName
Start-Sleep -Seconds 3

$state = (Get-ScheduledTask -TaskName $taskName).State
Good ""
Good "Installed. The agent is $state and will start again whenever you log in."
Good ""
Write-Host "  Status:  Get-ScheduledTask -TaskName $taskName"
Write-Host "  Stop:    Stop-ScheduledTask -TaskName $taskName"
Write-Host "  Start:   Start-ScheduledTask -TaskName $taskName"
Write-Host "  Re-test: $($python.Source) `"$InstallDir\kpms-agent.py`" --config `"$configPath`" --test"
Write-Host "  Remove:  Unregister-ScheduledTask -TaskName $taskName -Confirm:`$false"
Good ""
Write-Host "Next: in the admin panel, add the printer with Transport = agent and the"
Write-Host "queue name exactly as it appears in Windows Settings -> Printers & scanners."
