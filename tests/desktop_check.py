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


def main() -> int:
    server, token = sys.argv[1], sys.argv[2]

    spec = importlib.util.spec_from_file_location("kpms_desktop", APP)
    desktop = importlib.util.module_from_spec(spec)
    sys.modules["kpms_desktop"] = desktop
    spec.loader.exec_module(desktop)

    scratch = Path(tempfile.mkdtemp())
    desktop.default_config_path = lambda: scratch / "config.json"

    import tkinter as tk

    root = tk.Tk()
    app = desktop.DesktopApp(root)

    print("Window")
    check("the window builds", root.title().startswith("Krishna Printer"))
    tabs = [app.tabs.tab(i, "text").strip() for i in range(app.tabs.index("end"))]
    check("it has connection, printers and activity tabs",
          tabs == ["Connection", "Printers", "Activity"], f"got {tabs}")
    check("printing cannot be started before connecting",
          str(app.start_btn.cget("state")) == "disabled")

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
