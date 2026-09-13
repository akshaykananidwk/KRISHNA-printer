<#
    Build Krishna Printer.exe from the desktop agent.

    Run on a Windows machine with Python installed:

        .\build.ps1

    Produces dist\KrishnaPrinter.exe - a single file that needs no Python on
    the machine it runs on. Copy it anywhere and double-click it.

    PyInstaller is a build-time tool only. Nothing it pulls in ends up as a
    runtime dependency of the application: the agent itself is standard
    library, and the window is tkinter, which ships with Python.
#>

[CmdletBinding()]
param(
    [string] $OutputName = "KrishnaPrinter",
    [switch] $KeepConsole
)

$ErrorActionPreference = 'Stop'

function Info($m) { Write-Host $m -ForegroundColor Cyan }
function Good($m) { Write-Host $m -ForegroundColor Green }
function Fail($m) { Write-Host $m -ForegroundColor Red; exit 1 }

$here = $PSScriptRoot
$agent = Join-Path (Split-Path $here -Parent) 'kpms-agent.py'
$app = Join-Path $here 'kpms_desktop.py'

if (-not (Test-Path $agent)) { Fail "kpms-agent.py was not found at $agent" }
if (-not (Test-Path $app))   { Fail "kpms_desktop.py was not found at $app" }

$python = Get-Command python.exe -ErrorAction SilentlyContinue
if (-not $python) { $python = Get-Command python3.exe -ErrorAction SilentlyContinue }
if (-not $python) { Fail "Python 3 is required to build. Install it from python.org." }

Info "Python at $($python.Source)"

Info "Installing PyInstaller (build-time only)..."
& $python.Source -m pip install --upgrade --quiet pyinstaller
if ($LASTEXITCODE -ne 0) { Fail "Could not install PyInstaller." }

# The agent is loaded at runtime by path, not imported as a module, so
# PyInstaller cannot see it. Carry it as data, next to the executable's
# temporary root, which is where load_agent_module() looks first.
$addData = "$agent;."

$windowFlag = '--windowed'
if ($KeepConsole) { $windowFlag = '--console' }

# Belt and braces for the runtime-loaded agent. kpms_desktop.py imports
# everything the agent needs so the analyser can see it, but naming them here
# as well costs nothing and survives someone editing that list out.
$hidden = @(
    'argparse', 'dataclasses', 'hashlib', 'json', 'logging', 'os', 'pathlib',
    'platform', 're', 'shutil', 'signal', 'subprocess', 'tempfile', 'time',
    'typing', 'urllib.error', 'urllib.parse', 'urllib.request'
)
$hiddenArgs = @()
foreach ($module in $hidden) { $hiddenArgs += @('--hidden-import', $module) }

Info "Building $OutputName.exe..."
& $python.Source -m PyInstaller `
    --noconfirm --clean --onefile $windowFlag `
    --name $OutputName `
    --add-data $addData `
    @hiddenArgs `
    --distpath (Join-Path $here 'dist') `
    --workpath (Join-Path $here 'build') `
    --specpath $here `
    $app

if ($LASTEXITCODE -ne 0) { Fail "The build failed. The output above says why." }

$exe = Join-Path $here "dist\$OutputName.exe"
if (-not (Test-Path $exe)) { Fail "The build reported success but produced no executable." }

$size = [math]::Round((Get-Item $exe).Length / 1MB, 1)
Good ""
Good "Built $exe ($size MB)"
Good ""
Write-Host "This executable still needs, on the machine that prints:"
Write-Host "  * SumatraPDF  - winget install --id SumatraPDF.SumatraPDF"
Write-Host "  * LibreOffice - winget install --id TheDocumentFoundation.LibreOffice"
Write-Host "                  (only for Word, Excel, PowerPoint, images and text)"
