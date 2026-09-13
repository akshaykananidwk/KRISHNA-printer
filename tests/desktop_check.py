#!/usr/bin/env python3
"""
Checks for the desktop agent window.

Driven headlessly against a running server: the window is built for real, a
token is connected with, the printer list is populated from a stubbed spooler
(there is no Windows print system here), and a printer is registered through
the live API. What cannot be checked from Linux is how it looks on Windows.

Usage: desktop_check.py <server-url> <agent-token>
Exits 0 when every check passes, 1 otherwise.
"""

from __future__ import annotations

import importlib.util
import json
import os
import re
import sys
import tempfile
import time
from pathlib import Path

ROOT = Path(__file__).resolve().parent.parent
APP = ROOT / "agent" / "desktop" / "kpms_desktop.py"

failures: list[str] = []
passes = 0


def check(label: str, condition: bool, detail: str = "") -> None:
    global passes
    if condition:
        passes += 1
        print(f"  ok   {label}")
    else:
        failures.append(label)
        print(f"  FAIL {label}  {detail}")


class FakeSpooler:
    """Stands in for the Windows print spooler, which does not exist here."""

    @staticmethod
    def local_printers() -> list[str]:
        return ["HP LaserJet M1005", "Microsoft Print to PDF"]

    @staticmethod
    def capabilities(name: str) -> dict:
        if "M1005" in name:
            return {"paper_sizes": ["A4", "A5", "Letter"],
                    "color_modes": ["bw"], "duplex_modes": ["single"]}
        return {"paper_sizes": ["A4"],
                "color_modes": ["bw", "color"], "duplex_modes": ["single", "double"]}


def pump(root, seconds: float) -> None:
    deadline = time.monotonic() + seconds
    while time.monotonic() < deadline:
        root.update()
        time.sleep(0.05)


def wait_for(root, predicate, seconds: float = 20.0) -> bool:
    """
    Pump the event loop until something becomes true.

    Fixed sleeps made this flaky: it passed run on its own and failed inside
    the full suite, where the server is busier, purely because the wait was
    too short. Waiting on the condition rather than on the clock also means a
    genuine failure is reported as a failed check instead of a traceback.
    """
    deadline = time.monotonic() + seconds
    while time.monotonic() < deadline:
        root.update()
        try:
            if predicate():
                return True
        except Exception:                                   # noqa: BLE001
            pass
        time.sleep(0.05)
    return False


def top_level(path: Path) -> set[str]:
    """The modules a file imports at its top level."""
    import ast

    modules: set[str] = set()
    for node in ast.parse(path.read_text()).body:
        if isinstance(node, ast.Import):
            modules |= {alias.name.split(".")[0] for alias in node.names}
        elif isinstance(node, ast.ImportFrom) and node.module and node.level == 0:
            modules.add(node.module.split(".")[0])
    return modules


def agent_imports_are_visible() -> tuple[bool, str]:
    """
    Every module kpms-agent.py imports must also be imported by the window.

    The agent is loaded at runtime by path, so a packager analysing imports
    statically cannot see inside it. The first build started and immediately
    died with "No module named 'platform'". Naming them in kpms_desktop.py puts
    them in the import graph; this check is what stops that list rotting as the
    agent changes.
    """
    needed = top_level(ROOT / "agent" / "kpms-agent.py") - {"__future__"}
    present = top_level(APP)
    missing = sorted(needed - present)
    return not missing, ", ".join(missing)


def main() -> int:
    server, token = sys.argv[1], sys.argv[2]

    spec = importlib.util.spec_from_file_location("kpms_desktop", APP)
    desktop = importlib.util.module_from_spec(spec)
    sys.modules["kpms_desktop"] = desktop
    spec.loader.exec_module(desktop)

    # Point every configuration location at scratch. The app deliberately
    # falls back to the machine-wide and per-user files, so leaving those at
    # their real paths let a previous run's token leak into this one.
    scratch = Path(tempfile.mkdtemp())
    desktop.default_config_path = lambda: scratch / "config.json"
    desktop.machine_config_path = lambda: scratch / "machine.json"
    desktop.user_config_path = lambda: scratch / "user.json"

    import tkinter as tk

    root = tk.Tk()
    app = desktop.DesktopApp(root)

    print("Packaging")
    visible, missing = agent_imports_are_visible()
    check("every module the agent needs is visible to the packager", visible,
          f"not imported by the window: {missing}")

    print("Settings location")
    # The service installer locks its config to Administrators and SYSTEM,
    # which is right for a file holding a token - and made the desktop app,
    # running as an ordinary user, die with PermissionError on connect. It must
    # fall back to this user's own file, not fail.
    import tkinter as _tk

    blocker = Path(tempfile.mkdtemp()) / "not-a-dir"
    blocker.write_text("")
    unwritable = blocker / "agent" / "config.json"
    mine = Path(tempfile.mkdtemp()) / "mine" / "config.json"

    saved_machine, saved_user = desktop.machine_config_path, desktop.user_config_path
    desktop.machine_config_path = lambda: unwritable
    desktop.user_config_path = lambda: mine

    probe_root = _tk.Tk()
    probe = desktop.DesktopApp(probe_root)
    probe.config_path = unwritable
    probe.server_var.set("https://print.example.com")
    probe.token_var.set("kpms_probe_token")
    note = probe._write_settings()

    check("an unwritable shared config falls back to this user's own",
          mine.is_file() and json.loads(mine.read_text())["token"] == "kpms_probe_token",
          f"note: {note}")
    check("and says where the settings went instead",
          "your account" in note, f"note: {note!r}")
    check("the app then uses that file", probe.config_path == mine)
    check("and reads it back", probe._read_settings().get("token") == "kpms_probe_token")
    probe_root.destroy()

    desktop.machine_config_path, desktop.user_config_path = saved_machine, saved_user

    print("Window")
    check("the window builds", root.title().startswith("Krishna Printer"))
    tabs = [app.tabs.tab(i, "text").strip() for i in range(app.tabs.index("end"))]
    check("it has connection, printers and activity tabs",
          tabs == ["Connection", "Printers", "Activity"], f"got {tabs}")
    check("printing cannot be started before connecting",
          str(app.start_btn.cget("state")) == "disabled")

    print("Updating from inside the window")

    check("there is somewhere to start an update from",
          hasattr(app, "update_btn") and hasattr(app, "check_btn"))
    check("neither button works before connecting",
          str(app.update_btn.cget("state")) == "disabled"
          and str(app.check_btn.cget("state")) == "disabled",
          "an update button that works without a server is a button with nothing behind it")

    # Nothing published: say so, offer nothing.
    app._show_release({"published": False})
    check("with nothing published, no update is offered",
          str(app.update_btn.cget("state")) == "disabled")
    check("and the window says which version it has",
          desktop.APP_VERSION in str(app.update_state.cget("text")))

    # An older release must never be offered as an update.
    app._show_release({"published": True, "version": "0.0.1", "url": "https://x/y.exe",
                       "sha256": "a" * 64})
    check("an older release is not offered",
          str(app.update_btn.cget("state")) == "disabled",
          "offering a downgrade and calling it an update")

    # A newer one is, with whatever the operator wrote about it.
    app._show_release({"published": True, "version": "9.9.9", "url": "https://x/y.exe",
                       "sha256": "a" * 64, "notes": "Runs in the notification area."})
    offered = str(app.update_state.cget("text"))
    check("a newer release is offered", str(app.update_btn.cget("state")) == "normal")
    check("with the version and the notes",
          "9.9.9" in offered and "notification area" in offered, f"got {offered!r}")

    app._show_release({"published": False})

    print("Packaging for Windows")

    # None of this can be run here - there is no Windows - so what is checked
    # is everything that decides whether it works when it is.
    bat = ROOT / "agent" / "desktop" / "build.bat"
    ps1 = ROOT / "agent" / "desktop" / "build.ps1"
    iss = ROOT / "agent" / "desktop" / "installer.iss"

    check("there is a build file that can be double-clicked", bat.is_file())
    check("and a setup script for the wizard", iss.is_file())

    bat_bytes = bat.read_bytes()
    # cmd.exe reads a .bat as the machine's ANSI code page, and a stray
    # non-ASCII byte in a label or a quoted string is a parse error rather than
    # a wrong character. install.ps1 was bitten by exactly this once already.
    try:
        bat_bytes.decode("ascii")
        ascii_only = True
    except UnicodeDecodeError:
        ascii_only = False
    check("build.bat is plain ASCII", ascii_only)
    check("build.bat uses CRLF line endings",
          b"\r\n" in bat_bytes and b"\n" not in bat_bytes.replace(b"\r\n", b""),
          "a .bat with bare LF can break goto and labels")

    bat_text = bat_bytes.decode("ascii")

    # Every label the script jumps to must exist. A goto to a missing label
    # does not stop the script - it ends it, silently, mid-build.
    targets = set(re.findall(r"(?:goto|call)\s+:(\w+)", bat_text))
    labels = set(re.findall(r"^:(\w+)", bat_text, re.MULTILINE))
    check("every label build.bat jumps to exists",
          targets <= labels, f"missing: {sorted(targets - labels)}")

    # It must install what is missing rather than only complaining about it.
    check("it installs Python when there is none",
          "Python.Python" in bat_text and "python.org/ftp/python" in bat_text,
          "no winget id and no direct download")
    check("and puts Python on PATH, or the build cannot find it",
          "PrependPath=1" in bat_text)
    check("and installs Inno Setup for the wizard",
          "JRSoftware.InnoSetup" in bat_text)
    check("nothing asks for Administrator",
          "--scope user" in bat_text and "InstallAllUsers=0" in bat_text)
    check("it does not leave a closed window on failure",
          bat_text.count("pause") >= 2, "a failure with no pause vanishes before it can be read")

    # The packager list lives in build.ps1 alone. A second copy in build.bat
    # would drift, and the symptom of drift is an .exe that dies on launch.
    ps1_text = ps1.read_text()
    check("the packager list is in one place only",
          "--hidden-import" not in bat_text and "--hidden-import" in ps1_text,
          "build.bat has its own copy of the hidden imports")
    check("build.bat delegates the build to build.ps1",
          "build.ps1" in bat_text)

    needed = top_level(ROOT / "agent" / "kpms-agent.py") - {"__future__"}
    named = set(re.findall(r"'([A-Za-z_][\w.]*)'", ps1_text))
    missing_hidden = sorted(m for m in needed if m not in named)
    check("every module the agent needs is named to the packager",
          not missing_hidden, f"not in build.ps1: {missing_hidden}")
    for module in ("ctypes.wintypes", "winreg"):
        check(f"{module} is named too, since it is imported inside a function",
              module in named, "a packager can miss an import that is not at the top of a file")

    iss_text = iss.read_text()
    check("the wizard installs the executable the build produces",
          "KrishnaPrinter.exe" in iss_text)
    check("it has a fixed AppId, so a second install is an upgrade",
          re.search(r"^AppId=\{\{[0-9A-Fa-f-]{36}\}", iss_text, re.MULTILINE) is not None,
          "without a stable AppId every version installs alongside the last")
    check("it can install silently, which is what the updater needs",
          "skipifsilent" in iss_text,
          "an unattended update must not pop a window over somebody's work")
    check("it closes the running app first",
          "CloseApplications=yes" in iss_text,
          "Windows cannot replace a running .exe")

    # The installer's startup tick and the app's own checkbox must write the
    # same registry value, or the two disagree about whether it is on.
    run_key = re.search(r'Subkey: "([^"]+)"', iss_text)
    check("the wizard's startup tick writes the key the app reads",
          run_key is not None and run_key.group(1) == desktop.AUTOSTART_KEY,
          f"iss={run_key.group(1) if run_key else None} app={desktop.AUTOSTART_KEY}")
    check("and with the same hidden switch",
          "--hidden" in iss_text and "--hidden" in desktop.autostart_command())

    # One version number for the product. The installer is stamped from it and
    # the updater compares against it.
    check("the installer takes its version from the application",
          "APP_VERSION" in ps1_text and "AppVersion" in iss_text,
          "a second copy of the version is one more thing to forget")

    print("Running in the background")

    # Off Windows there is no notification area, and the app must say so
    # rather than raise. On Windows a failure to create the icon is equally
    # survivable - see the fallback checked two blocks down.
    check("the notification area is known to be Windows-only",
          desktop.TrayIcon.supported() == (os.name == "nt"))

    lonely = desktop.TrayIcon("probe", {}, is_running=lambda: False)
    if os.name != "nt":
        check("starting one here fails without raising", lonely.start() is False)
        check("and gives a reason", "Windows" in lonely.error, f"got {lonely.error!r}")

    # No icon: minimise must not withdraw the window. A withdrawn window with
    # nothing to restore it from is an application the operator cannot reach.
    states: list[str] = []
    app.tray = None
    app.root.iconify = lambda: states.append("iconified")
    app.root.withdraw = lambda: states.append("withdrawn")
    app.hide_window()
    check("without a tray icon, hiding only minimises",
          states == ["iconified"], f"got {states}")

    # With one, it goes away completely, says where it went, and says it once.
    class StubTray:
        def __init__(self) -> None:
            self.balloons: list[tuple[str, str]] = []
            self.stopped = False

        def notify(self, title, message):
            self.balloons.append((title, message))

        def stop(self):
            self.stopped = True

    stub = StubTray()
    app.tray = stub
    states.clear()
    app.hide_window()
    app.hide_window()
    check("with one, the window is withdrawn", states == ["withdrawn", "withdrawn"], f"got {states}")
    check("and the operator is told where it went once",
          len(stub.balloons) == 1 and "clock" in stub.balloons[0][1], f"got {stub.balloons}")
    check("printing is not stopped by hiding", app.agent_instance is None)

    # Closing the window with an icon present means hide, not quit.
    destroyed: list[bool] = []
    app.root.destroy = lambda: destroyed.append(True)
    states.clear()
    app.on_close()
    check("closing the window hides it instead of quitting",
          states == ["withdrawn"] and not destroyed, f"hide={states} destroyed={destroyed}")

    # Quit is the one thing that ends it, and it takes the icon with it.
    app.quit_app()
    check("Quit really quits", destroyed == [True])
    check("and removes the icon", stub.stopped)

    print("Starting with Windows")
    command = desktop.autostart_command()
    check("the logon command starts hidden", command.endswith("--hidden"), f"got {command}")
    check("and quotes the path, which has spaces on a normal install",
          command.startswith('"'), f"got {command}")
    check("it is only read as enabled on Windows",
          desktop.autostart_enabled() is False or os.name == "nt")
    if os.name != "nt":
        check("and setting it elsewhere reports why, rather than raising",
              "Windows" in desktop.set_autostart(True))

    print("Refusals")
    check("https is accepted", desktop.is_safe_server("https://print.example.com"))
    check("loopback is accepted for a local trial",
          all(desktop.is_safe_server(u) for u in
              ("http://localhost:8088", "http://127.0.0.1:8088", "http://[::1]:8088")))
    check("plain http to anywhere else is not",
          not desktop.is_safe_server("http://printer.example.com"))

    # The token and customer documents must never go over plain HTTP.
    app.server_var.set("http://printer.example.com")
    app.token_var.set(token)
    messages: list[str] = []
    desktop.messagebox.showerror = lambda title, text: messages.append(text)
    app.connect()
    check("an http:// server is refused before anything is sent",
          any("https" in m for m in messages), f"got {messages}")

    app.server_var.set(server)
    app.token_var.set("")
    messages.clear()
    app.connect()
    check("an empty token is refused", bool(messages), "no message shown")

    print("Connecting")
    app.agent_module.select_spooler = lambda: FakeSpooler
    app.server_var.set(server)
    app.token_var.set(token)
    app.connect()
    connected = wait_for(root, lambda: "Connected" in app.status_dot.cget("text"))

    check("it connects and names the agent", connected,
          f"{app.status_dot.cget('text')} / {app.connect_message.cget('text')}")
    check("printing can be started once connected",
          str(app.start_btn.cget("state")) == "normal")
    check("the token is saved for next time",
          (scratch / "config.json").is_file()
          and json.loads((scratch / "config.json").read_text())["token"] == token)
    check("the token is not shown in clear by default",
          app.token_entry.cget("show") != "", "the token was displayed")

    profiles = list(app.profile_box.cget("values"))
    check("model profiles come from the server",
          any("M1005" in p for p in profiles) and any("GM4070" in p for p in profiles),
          f"got {profiles}")
    check("the generic profile is listed once",
          sum(1 for p in profiles if p == "Generic network printer") == 1, f"got {profiles}")

    print("Printers")
    listed = wait_for(root, lambda: len(app.printer_tree.get_children()) == 2)
    rows = app.printer_tree.get_children()
    check("local printers are listed", listed,
          f"got {len(rows)}: {app.printer_message.cget('text')}")

    if listed:
        values = app.printer_tree.item("HP LaserJet M1005", "values")
        check("a mono printer is shown as black & white",
              values[1] == "Black & white", f"got {values}")
        check("a simplex printer is shown as single only",
              values[2] == "Single only", f"got {values}")
        check("it is not yet in the estate", values[4] == "Not added", f"got {values}")
    else:
        for skipped in ("a mono printer is shown as black & white",
                        "a simplex printer is shown as single only",
                        "it is not yet in the estate"):
            check(skipped, False, "the printer list never populated")
        root.destroy()
        print()
        print(f"{passes} passed, {len(failures)} failed: {', '.join(failures)}")
        return 1

    print("Registering")
    app.printer_tree.selection_set("HP LaserJet M1005")
    app.profile_var.set("HP LaserJet M1005 MFP")
    app.register_selected()
    registered = wait_for(root, lambda: "is now printer" in app.printer_message.cget("text"))

    check("the printer is registered through the API", registered,
          app.printer_message.cget("text"))
    check("the row shows it as added",
          app.printer_tree.item("HP LaserJet M1005", "values")[4] == "Added")

    # Adding the same queue twice must not create a second printer.
    before = app.printer_message.cget("text")
    app.printer_message.configure(text="")
    app.register_selected()
    # Wait for the finished message, not the "Adding..." one it shows first.
    wait_for(root, lambda: "is now printer" in app.printer_message.cget("text"))
    after = app.printer_message.cget("text")

    check("adding the same printer again does not duplicate it",
          "is now printer" in after
          and after.split("is now printer ")[-1] == before.split("is now printer ")[-1],
          f"first: {before}\n         again: {after}")

    root.destroy()

    print()
    if failures:
        print(f"{passes} passed, {len(failures)} failed: {', '.join(failures)}")
        return 1
    print(f"{passes} passed, 0 failed")
    return 0


if __name__ == "__main__":
    sys.exit(main())
