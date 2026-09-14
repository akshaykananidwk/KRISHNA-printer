; ---------------------------------------------------------------------------
; Krishna Printer — Windows setup wizard.
;
; Produces KrishnaPrinterSetup.exe: the Next-Next-Install that people expect of
; software, with a Start Menu entry, an optional desktop icon, an optional
; start-with-Windows tick, and an uninstaller in Settings > Apps.
;
; Built by build.bat, which installs Inno Setup if it is not already there.
; To build it by hand:
;
;     iscc installer.iss
;
; The /SILENT and /VERYSILENT switches Inno provides are what the in-app
; updater uses to apply an update without anybody clicking anything.
; ---------------------------------------------------------------------------

#define AppName        "Krishna Printer"
#define AppPublisher   "Krishna Printer"
#define AppExeName     "KrishnaPrinter.exe"

; The version is written by build.bat from APP_VERSION in kpms_desktop.py, so
; one edit there carries into the installer, the Programs list and the updater.
#ifndef AppVersion
  #define AppVersion "1.0.0"
#endif

[Setup]
; A stable AppId is what makes an install an UPGRADE rather than a second copy
; sitting beside the first. Never change it.
AppId={{8A31D1B2-0F4C-4E7A-9C58-2E9B4D6F1A77}
AppName={#AppName}
AppVersion={#AppVersion}
AppVerName={#AppName} {#AppVersion}
AppPublisher={#AppPublisher}
VersionInfoVersion={#AppVersion}

; Per-user by default: a shop counter PC is often used by someone who is not an
; administrator, and asking for elevation to install a program that prints is
; a barrier with nothing behind it. It also puts the install in the same
; account whose printers the agent can actually see.
PrivilegesRequired=lowest
PrivilegesRequiredOverridesAllowed=dialog
DefaultDirName={autopf}\{#AppName}
DefaultGroupName={#AppName}
DisableProgramGroupPage=yes
AllowNoIcons=yes

OutputDir=dist
OutputBaseFilename=KrishnaPrinterSetup
Compression=lzma2/max
SolidCompression=yes
WizardStyle=modern
ArchitecturesInstallIn64BitMode=x64compatible

; Shown on the first page, so somebody who has been handed the file knows what
; it is before they agree to anything.
AppComments=Collects print jobs from your Krishna Printer account and prints them on this computer's printer.
UninstallDisplayName={#AppName}
UninstallDisplayIcon={app}\{#AppExeName}

; Inno cannot replace a running executable. Closing it first turns a confusing
; "file in use" error into nothing at all, which matters most during an
; unattended update, where there is nobody to read the error.
CloseApplications=yes
CloseApplicationsFilter=*.exe
RestartApplications=no

[Languages]
Name: "english"; MessagesFile: "compiler:Default.isl"

[Tasks]
Name: "desktopicon"; Description: "Create a shortcut on the desktop"; GroupDescription: "Shortcuts:"
Name: "startup";     Description: "Start {#AppName} when I log in (recommended)"; GroupDescription: "Printing:"
; SumatraPDF is not offered as a choice: without it nothing prints at all, so a
; tick box would only let somebody install a print agent that cannot print.
; LibreOffice is a few hundred megabytes and only needed for Word, Excel,
; PowerPoint and photographs, so that one is a choice - on by default.
Name: "libreoffice"; Description: "Also install LibreOffice, for Word, Excel, PowerPoint and photos (large download)"; GroupDescription: "Printing:"

[Files]
Source: "dist\{#AppExeName}"; DestDir: "{app}"; Flags: ignoreversion
Source: "README.md";          DestDir: "{app}"; DestName: "README.md"; Flags: ignoreversion isreadme

[Icons]
Name: "{group}\{#AppName}";            Filename: "{app}\{#AppExeName}"
Name: "{group}\Uninstall {#AppName}";  Filename: "{uninstallexe}"
Name: "{autodesktop}\{#AppName}";      Filename: "{app}\{#AppExeName}"; Tasks: desktopicon

[Registry]
; The same per-user Run key the application's own checkbox writes, and the same
; --hidden switch, so the two agree rather than fighting over the value. Ticking
; the task here and the checkbox in the app are two ways to the same setting.
Root: HKCU; Subkey: "Software\Microsoft\Windows\CurrentVersion\Run"; \
    ValueType: string; ValueName: "{#AppName}"; \
    ValueData: """{app}\{#AppExeName}"" --hidden"; \
    Flags: uninsdeletevalue; Tasks: startup

[Run]
; The two programs that do the actual printing. Neither ships with Windows and
; neither is optional in the way it sounds: without SumatraPDF nothing prints
; at all, because Windows cannot print a PDF from a script; without LibreOffice
; a Word file or a photograph is refused.
;
; Installed here so a shop owner never meets a terminal. Per-user, so no
; Administrator prompt on a counter PC. Both are skipped silently where winget
; is absent - the application's own Requirements panel installs them then, and
; says why if it cannot, which is better than an installer failing on a
; machine that has no package manager.
Filename: "{cmd}"; \
    Parameters: "/C winget install --id SumatraPDF.SumatraPDF --silent --scope user --accept-package-agreements --accept-source-agreements"; \
    StatusMsg: "Installing SumatraPDF (needed to print)..."; \
    Flags: runhidden; Check: HasWinget

Filename: "{cmd}"; \
    Parameters: "/C winget install --id TheDocumentFoundation.LibreOffice --silent --scope user --accept-package-agreements --accept-source-agreements"; \
    StatusMsg: "Installing LibreOffice (needed for Word, Excel and photos)..."; \
    Flags: runhidden; Check: HasWinget and InstallLibreOffice

; Not after a silent install: an update applied in the background starts the
; app again through the updater, and a window appearing on its own over
; somebody's work is not what "update" should mean.
Filename: "{app}\{#AppExeName}"; Description: "Start {#AppName} now"; \
    Flags: nowait postinstall skipifsilent

[Code]
function HasWinget: Boolean;
begin
  // Windows 11 and any updated Windows 10 has it; older ones do not, and there
  // is nothing useful an installer can do about that. The app's Requirements
  // panel picks up whatever is still missing and explains itself there.
  Result := FileExists(ExpandConstant('{localappdata}\Microsoft\WindowsApps\winget.exe'));
end;

function InstallLibreOffice: Boolean;
begin
  Result := WizardIsTaskSelected('libreoffice');
end;

[UninstallDelete]
; The token and the work directory. Leaving a credential behind on a machine
; somebody has just uninstalled the software from is not tidy, it is careless.
Type: filesandordirs; Name: "{localappdata}\KrishnaPrinter"
