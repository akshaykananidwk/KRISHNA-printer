@echo off
setlocal enabledelayedexpansion
title Krishna Printer - build

REM ---------------------------------------------------------------------------
REM  Double-click this. It works out what is missing, installs it, and leaves
REM  you with two files in the dist folder:
REM
REM      KrishnaPrinter.exe        the application
REM      KrishnaPrinterSetup.exe   the Next-Next-Install wizard to hand out
REM
REM  Nothing here needs Administrator. Python and Inno Setup are both installed
REM  for the current user only, which is also the account whose printers the
REM  agent can see.
REM
REM  This file gets the prerequisites in place and then hands the actual build
REM  to build.ps1, which owns the list of modules the packager has to be told
REM  about. Two copies of that list would drift apart, and the symptom of it
REM  drifting is an executable that starts and immediately dies.
REM
REM  It is a .bat and not a .ps1 because a .ps1 does not run when it is
REM  double-clicked: Windows opens it in Notepad, and the default execution
REM  policy blocks it even when it does run. That cost a round trip the first
REM  time somebody tried to build this.
REM ---------------------------------------------------------------------------

cd /d "%~dp0"

echo.
echo  ================================================
echo   Krishna Printer - building the Windows software
echo  ================================================
echo.

if not exist "kpms_desktop.py"  goto :no_source
if not exist "build.ps1"        goto :no_source
if not exist "..\kpms-agent.py" goto :no_source

REM --- Python ----------------------------------------------------------------
call :find_python

REM "python" on a machine without it is a stub that opens the Microsoft Store
REM and exits 9009. Being on PATH is not the same as working, so ask it for its
REM version and believe the answer rather than the file.
if defined PY (
    "!PY!" -c "import sys; sys.exit(0 if sys.version_info >= (3, 8) else 1)" >nul 2>&1
    if errorlevel 1 (
        echo  [..] The Python on this PC is too old, or is the Microsoft Store stub.
        set "PY="
    )
)

if not defined PY (
    echo  [..] Python is not installed. Installing it - this takes a few minutes.
    echo.
    call :install_python
    call :find_python
)
if not defined PY goto :no_python

for /f "delims=" %%V in ('"!PY!" -c "import sys;print(\"%%d.%%d\" %% sys.version_info[:2])" 2^>nul') do set "PYVER=%%V"
echo  [ok] Python !PYVER! at !PY!

REM --- Inno Setup, so build.ps1 can make the wizard as well ------------------
call :find_iscc
if not defined ISCC (
    echo  [..] Inno Setup is not installed. Installing it to build the wizard...
    where winget >nul 2>&1
    if not errorlevel 1 (
        winget install --id JRSoftware.InnoSetup --silent --scope user ^
            --accept-package-agreements --accept-source-agreements >nul 2>&1
        call :refresh_path
        call :find_iscc
    )
)
if defined ISCC (
    echo  [ok] Inno Setup at !ISCC!
) else (
    echo  [--] Inno Setup is not available. Only the plain .exe will be built.
    echo       Get it from https://jrsoftware.org/isdl.php and run this again
    echo       if you want the Next-Next-Install wizard too.
)

REM --- Build -----------------------------------------------------------------
echo.
powershell -NoProfile -ExecutionPolicy Bypass -File "%~dp0build.ps1"
if errorlevel 1 goto :failed

echo.
if exist "dist" explorer "dist"
pause
exit /b 0

REM ---------------------------------------------------------------------------
:find_python
set "PY="
for %%P in (python.exe python3.exe) do (
    if not defined PY (
        for /f "delims=" %%I in ('where %%P 2^>nul') do if not defined PY set "PY=%%I"
    )
)
exit /b 0

REM ---------------------------------------------------------------------------
:find_iscc
set "ISCC="
for /f "delims=" %%I in ('where iscc.exe 2^>nul') do if not defined ISCC set "ISCC=%%I"
if not defined ISCC if exist "%ProgramFiles(x86)%\Inno Setup 6\ISCC.exe" set "ISCC=%ProgramFiles(x86)%\Inno Setup 6\ISCC.exe"
if not defined ISCC if exist "%ProgramFiles%\Inno Setup 6\ISCC.exe"      set "ISCC=%ProgramFiles%\Inno Setup 6\ISCC.exe"
if not defined ISCC if exist "%LOCALAPPDATA%\Programs\Inno Setup 6\ISCC.exe" set "ISCC=%LOCALAPPDATA%\Programs\Inno Setup 6\ISCC.exe"
exit /b 0

REM ---------------------------------------------------------------------------
:install_python
REM winget where it exists - every Windows 11 and most updated Windows 10 - and
REM the official installer where it does not, per-user so a counter PC does not
REM need an Administrator to get past it.
where winget >nul 2>&1
if not errorlevel 1 (
    winget install --id Python.Python.3.12 --silent --scope user ^
        --accept-package-agreements --accept-source-agreements >nul 2>&1
    call :refresh_path
    where python.exe >nul 2>&1
    if not errorlevel 1 exit /b 0
)

echo  [..] Downloading Python from python.org...
set "PYSETUP=%TEMP%\python-3.12.8-amd64.exe"
powershell -NoProfile -ExecutionPolicy Bypass -Command ^
    "$ErrorActionPreference='Stop'; [Net.ServicePointManager]::SecurityProtocol=[Net.SecurityProtocolType]::Tls12; Invoke-WebRequest -Uri 'https://www.python.org/ftp/python/3.12.8/python-3.12.8-amd64.exe' -OutFile '%PYSETUP%'" >nul 2>&1
if not exist "%PYSETUP%" exit /b 1

echo  [..] Installing Python...
REM PrependPath is the one that matters: without it the build cannot find the
REM interpreter it has just installed. Include_tcltk is the window itself.
"%PYSETUP%" /quiet InstallAllUsers=0 PrependPath=1 Include_pip=1 Include_tcltk=1
del /q "%PYSETUP%" >nul 2>&1
call :refresh_path
exit /b 0

REM ---------------------------------------------------------------------------
:refresh_path
REM winget and the Python installer both extend PATH for NEW processes. This one
REM already has its environment, so rebuild PATH from the registry instead of
REM telling somebody to close the window and start again.
for /f "usebackq tokens=2,*" %%A in (`reg query "HKLM\SYSTEM\CurrentControlSet\Control\Session Manager\Environment" /v Path 2^>nul`) do set "MACHINEPATH=%%B"
for /f "usebackq tokens=2,*" %%A in (`reg query "HKCU\Environment" /v Path 2^>nul`) do set "USERPATH=%%B"
set "PATH=%MACHINEPATH%;%USERPATH%"
exit /b 0

REM ---------------------------------------------------------------------------
:no_source
echo  [!!] kpms_desktop.py, build.ps1 or ..\kpms-agent.py is missing.
echo.
echo       Run this from the agent\desktop folder of the project, with the
echo       whole project around it. Copied somewhere on its own, it has
echo       nothing to build.
goto :failed

:no_python
echo.
echo  [!!] Python could not be installed automatically.
echo.
echo       Install it by hand from https://www.python.org/downloads/
echo       and TICK "Add python.exe to PATH" on the first screen.
echo       Then run this file again.
goto :failed

:failed
echo.
echo  The build did not finish. The messages above say why.
echo.
pause
exit /b 1
