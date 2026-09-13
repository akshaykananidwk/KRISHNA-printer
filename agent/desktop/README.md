# Krishna Printer — desktop agent

A window instead of a command line. Paste the token, pick a printer, and the
shop is printing.

![The connection screen](docs/connection.png)
![The printers screen](docs/printers.png)

## What it is

The same agent that runs as a service, with a face on it. It loads
`kpms-agent.py` and drives it — the printing path, the backends, the job
handling and the refusals are all the shipping code. A second implementation
would be a second set of bugs.

| Tab | What it does |
|---|---|
| **Connection** | Server address and token. Connect, start or stop printing, and the background settings. |
| **Printers** | Every printer installed on this computer, what its driver reports, and a button to add one to the shop. |
| **Activity** | What the agent is doing, live. |

## Where the token comes from

Either place issues one:

- **The shop itself**, at `/partner` after registering at `/register` and being
  approved — one button, shown on that page load only.
- **An operator**, in the admin panel under **Print agents**.

Issuing a replacement retires the previous token immediately, which is the
point: a token nobody can account for should not keep working.

## Running in the background

Closing or minimising the window puts it in the notification area, beside the
clock, and printing carries on. Right-click that icon to open the window, start
or stop printing, or quit — quitting is the only thing that stops collecting
jobs.

**Start automatically when I log in** on the Connection tab writes the per-user
`Run` key. No Administrator, and it starts in the operator's own session, which
is the only session their printers exist in. It comes up hidden and starts
printing on its own when a printer is already assigned.

Nothing flashes while it works: every program the agent starts — PowerShell to
read the queue, LibreOffice to convert, SumatraPDF to print — is started with
no console window. Without that, a black box appeared over whatever the
operator was doing, several times a minute, all day.

## Running it

**From source**, on any machine with Python 3:

```bash
python3 agent/desktop/kpms_desktop.py
```

**As an executable**, built on Windows — double-click `build.bat`.

It finds or installs Python (no Administrator needed), installs PyInstaller and
Inno Setup, and writes two files into `dist`:

| | |
|---|---|
| `KrishnaPrinter.exe` | One file. No Python needed on the machine that runs it. |
| `KrishnaPrinterSetup.exe` | The Next-Next-Install wizard to hand to a shop. |

`build.ps1` does the actual build and owns the list of modules the packager has
to be told about; `build.bat` only gets the prerequisites in place first. Run
`build.ps1` directly if you already have everything.

## Updating a shop's software

Build a new `KrishnaPrinterSetup.exe`, put it somewhere reachable over HTTPS,
and publish it in the admin panel under **Settings → Desktop software release**:
the version, the address, and the installer's SHA-256 —

```powershell
Get-FileHash .\dist\KrishnaPrinterSetup.exe
```

Every shop's window then shows "Version X is available" the next time it
connects, with whatever you wrote about it. They press **Install update** and
it does the rest: download, verify, install silently, restart.

The checksum is not decoration. The application hands the agent an address a
human typed into a settings box; the agent downloads what is there and
**refuses to run it unless the hash matches**, discarding it otherwise. Without
that, the settings box would be a way to run any executable on every shop
counter in the estate. Publishing a release without a valid checksum, or over
plain HTTP, is refused at save time.

Clearing all three fields withdraws the release — shops keep the version they
have.

## What it still needs on the printing machine

| | |
|---|---|
| **SumatraPDF** | Required. Windows cannot print a PDF from a script without it. `winget install --id SumatraPDF.SumatraPDF` |
| **LibreOffice** | Required for Word, Excel, PowerPoint, images and plain text — everything that is not already a PDF. `winget install --id TheDocumentFoundation.LibreOffice` |

## If the built .exe will not start

A packager reads imports statically, and the agent is loaded at runtime by
path - so nothing in the import graph mentions what the agent needs, and the
first build died on launch with `No module named 'platform'`.

`kpms_desktop.py` therefore imports everything the agent imports, and
`build.ps1` names them again as hidden imports. A check in
`tests/desktop_check.py` fails if that list and the agent's real imports ever
drift apart.

If a build still will not start, build it with a console attached so it can
say why:

```powershell
.\build.ps1 -KeepConsole
.\dist\KrishnaPrinter.exe
```

## Model profiles

The printer's own driver is asked what it can do, and that is what the list
shows. Choosing a **model profile** adds what the driver gets wrong.

This is a curated list, not a web search. What matters for charging a customer
correctly — whether duplex is automatic or manual, whether a mono model takes
an optional colour cartridge — is exactly what a search result will not tell
you reliably, and a wrong answer there is a refund. Profiles are written from
the manufacturer's published specification, and even then they are only
*declared*: nothing is offered to a customer until it has been probed from the
printer or confirmed by a test print.

## Before customers can use a printer

Adding it here is not the last step. Open the printer in the admin panel, send
it a test page, and mark what actually worked. Until something is verified the
printer offers nothing and takes no jobs — deliberately, so nobody pays for
double-sided on a printer that cannot do it.

## Where settings are kept

The token and server address go in `config.json`. The app prefers the
machine-wide file the service installer uses, so a window and a service share
one setup:

```
C:\ProgramData\KrishnaPrinter\agent\config.json
```

That file is locked to Administrators and SYSTEM, which is right for a file
holding a token — and means an ordinary user cannot write it. When that
happens the app saves to your own account instead, and says so:

```
%LOCALAPPDATA%\KrishnaPrinter\agent\config.json
```

No Administrator prompt either way. It reads both, so a setup made by the
installer is picked up by the window and the other way round.

## Security

- The token is a credential. It is stored in `config.json` next to the agent,
  readable by administrators only, and shown as dots in the window.
- The agent only makes **outbound** connections. No port is opened on this
  computer and no printer is exposed.
- Plain HTTP is refused outright, except to a loopback address for a local
  trial.
