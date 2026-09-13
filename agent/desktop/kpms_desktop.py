#!/usr/bin/env python3
"""
Krishna Printer - desktop agent.

A window that does what the PowerShell installer and the command line did, for
someone who should not have to open a terminal to run a print shop:

    paste the token  ->  Connect  ->  pick a printer  ->  it starts printing

It wraps kpms-agent.py rather than reimplementing it. The agent that prints is
the same agent, with the same backends, the same job handling and the same
refusals - this only gives it a face. A second implementation of the printing
path would be a second set of bugs.

tkinter only, which ships with Python, so the packaged executable carries no
third-party runtime.
"""

from __future__ import annotations

import importlib.util
import json
import os
import queue
import sys
import threading
import traceback
import webbrowser
from pathlib import Path
from typing import Any

import tkinter as tk
from tkinter import font as tkfont
from tkinter import messagebox, ttk

APP_NAME = "Krishna Printer"
APP_VERSION = "1.0.0"

# --- Colours -------------------------------------------------------------
# The same green as the web admin, so the two feel like one product.
INK = "#14261f"
MUTED = "#5a6b64"
LINE = "#d9e2de"
CANVAS = "#f5f7f6"
ACCENT = "#1e6f5c"
DANGER = "#b3261e"
OK = "#1e6f5c"


def default_config_path() -> Path:
    if os.name == "nt":
        base = Path(os.environ.get("ProgramData", r"C:\ProgramData"))
        return base / "KrishnaPrinter" / "agent" / "config.json"
    return Path.home() / ".config" / "kpms-agent" / "config.json"


def is_safe_server(url: str) -> bool:
    """
    HTTPS, or a loopback address for a local trial.

    The agent carries a bearer token and customer documents, so plain HTTP to
    anywhere else is refused outright rather than warned about. 127.0.0.1 and
    ::1 count as loopback exactly as "localhost" does - treating only the name
    as local was an arbitrary distinction that refused a perfectly local
    server.
    """
    if url.startswith("https://"):
        return True
    for prefix in ("http://localhost", "http://127.0.0.1", "http://[::1]"):
        if url.startswith(prefix):
            return True
    return False


def load_agent_module():
    """
    Import kpms-agent.py, whose name is not a valid module name.

    Looked for in the bundle first (PyInstaller unpacks --add-data there and
    __file__ does not point at it on every version), then next to this file -
    how the installer lays it out - then one directory up, how the repository
    does.
    """
    here = Path(__file__).resolve().parent
    bundle = getattr(sys, "_MEIPASS", None)

    candidates = [here / "kpms-agent.py", here.parent / "kpms-agent.py"]
    if bundle:
        candidates.insert(0, Path(bundle) / "kpms-agent.py")

    for candidate in candidates:
        if candidate.is_file():
            spec = importlib.util.spec_from_file_location("kpms_agent", candidate)
            module = importlib.util.module_from_spec(spec)
            sys.modules["kpms_agent"] = module
            spec.loader.exec_module(module)
            return module
    raise FileNotFoundError(
        "kpms-agent.py was not found next to this application. Reinstall, or copy it alongside."
    )


class LogPipe:
    """Collect the agent's log records for display, without blocking it."""

    def __init__(self) -> None:
        self.records: queue.Queue[str] = queue.Queue()

    def attach(self, agent_module) -> None:
        import logging

        class Handler(logging.Handler):
            def __init__(self, sink: queue.Queue[str]) -> None:
                super().__init__()
                self.sink = sink

            def emit(self, record: logging.LogRecord) -> None:
                try:
                    self.sink.put(self.format(record))
                except Exception:      # never let logging break printing
                    pass

        handler = Handler(self.records)
        handler.setFormatter(
            logging.Formatter("%(asctime)s  %(levelname)-7s %(message)s", "%H:%M:%S")
        )
        logging.getLogger().addHandler(handler)
        logging.getLogger().setLevel(logging.INFO)


class DesktopApp:
    def __init__(self, root: tk.Tk) -> None:
        self.root = root
        self.agent_module = load_agent_module()
        self.log_pipe = LogPipe()
        self.ui_queue: queue.Queue = queue.Queue()
        self.log_pipe.attach(self.agent_module)

        self.config_path = default_config_path()
        self.settings: dict[str, Any] = self._read_settings()

        self.api = None
        self.device: dict[str, Any] = {}
        self.profiles: list[dict[str, Any]] = []
        self.local_printers: list[dict[str, Any]] = []
        self.registered: dict[str, dict[str, Any]] = {}

        self.agent_thread: threading.Thread | None = None
        self.agent_instance = None

        self._build()
        self._poll_log()

        # A token already on disk means this machine has been set up before.
        if self.settings.get("token") and self.settings.get("server"):
            self.server_var.set(self.settings["server"])
            self.token_var.set(self.settings["token"])
            self.root.after(300, self.connect)

    # -- configuration ----------------------------------------------------

    def _read_settings(self) -> dict[str, Any]:
        try:
            # utf-8-sig: PowerShell and Notepad both write a byte-order mark.
            return json.loads(self.config_path.read_text(encoding="utf-8-sig"))
        except (OSError, ValueError):
            return {}

    def _write_settings(self) -> None:
        self.config_path.parent.mkdir(parents=True, exist_ok=True)
        work = self.config_path.parent / "work"
        work.mkdir(parents=True, exist_ok=True)

        self.settings.update({
            "server": self.server_var.get().strip().rstrip("/"),
            "token": self.token_var.get().strip(),
            "work_dir": str(work),
            "poll_interval": int(self.settings.get("poll_interval", 10)),
            "verify_tls": bool(self.settings.get("verify_tls", True)),
        })
        self.config_path.write_text(json.dumps(self.settings, indent=2), encoding="utf-8")

        if os.name != "nt":
            try:
                self.config_path.chmod(0o600)
            except OSError:
                pass

    # -- layout -----------------------------------------------------------

    def _build(self) -> None:
        self.root.title(f"{APP_NAME} - print agent")
        self.root.geometry("880x660")
        self.root.minsize(760, 560)
        self.root.configure(bg=CANVAS)

        base = tkfont.nametofont("TkDefaultFont")
        base.configure(size=10)
        self.mono = tkfont.Font(family="Consolas" if os.name == "nt" else "Monospace", size=9)
        self.title_font = tkfont.Font(family=base.cget("family"), size=15, weight="bold")
        self.head_font = tkfont.Font(family=base.cget("family"), size=11, weight="bold")

        style = ttk.Style()
        try:
            style.theme_use("clam")
        except tk.TclError:
            pass
        style.configure("TFrame", background=CANVAS)
        style.configure("Card.TFrame", background="white", relief="flat")
        style.configure("TLabel", background=CANVAS, foreground=INK)
        style.configure("Card.TLabel", background="white", foreground=INK)
        style.configure("Muted.TLabel", background="white", foreground=MUTED)
        style.configure("Accent.TButton", background=ACCENT, foreground="white",
                        borderwidth=0, focusthickness=0, padding=(14, 7))
        style.map("Accent.TButton", background=[("active", "#17594a"), ("disabled", "#9bb5ac")])
        style.configure("TButton", padding=(12, 6))
        style.configure("Treeview", rowheight=26, fieldbackground="white", background="white")
        style.configure("Treeview.Heading", font=(base.cget("family"), 9, "bold"))

        header = ttk.Frame(self.root)
        header.pack(fill="x", padx=16, pady=(14, 8))
        ttk.Label(header, text=APP_NAME, font=self.title_font).pack(side="left")
        self.status_dot = ttk.Label(header, text="Not connected", foreground=MUTED)
        self.status_dot.pack(side="right")

        self.tabs = ttk.Notebook(self.root)
        self.tabs.pack(fill="both", expand=True, padx=16, pady=(0, 14))

        self._build_connect_tab()
        self._build_printers_tab()
        self._build_log_tab()

    def _card(self, parent: tk.Widget) -> ttk.Frame:
        outer = tk.Frame(parent, bg=LINE, bd=0)
        outer.pack(fill="x", pady=(0, 12))
        inner = ttk.Frame(outer, style="Card.TFrame", padding=16)
        inner.pack(fill="both", expand=True, padx=1, pady=1)
        return inner

    def _build_connect_tab(self) -> None:
        tab = ttk.Frame(self.tabs, padding=16)
        self.tabs.add(tab, text="  Connection  ")

        card = self._card(tab)
        ttk.Label(card, text="Connect this computer to your shop",
                  font=self.head_font, style="Card.TLabel").pack(anchor="w")
        ttk.Label(card, style="Muted.TLabel", wraplength=760, justify="left",
                  text="Register a print agent in the admin panel under Print agents, copy the "
                       "token it shows once, and paste it here. Nothing is opened on this "
                       "computer: the agent only makes outbound connections to your server."
                  ).pack(anchor="w", pady=(4, 14))

        form = ttk.Frame(card, style="Card.TFrame")
        form.pack(fill="x")
        form.columnconfigure(1, weight=1)

        ttk.Label(form, text="Server address", style="Card.TLabel").grid(row=0, column=0, sticky="w", pady=6)
        self.server_var = tk.StringVar(value=self.settings.get("server", "https://"))
        ttk.Entry(form, textvariable=self.server_var).grid(row=0, column=1, sticky="ew", padx=(12, 0), pady=6)

        ttk.Label(form, text="Agent token", style="Card.TLabel").grid(row=1, column=0, sticky="w", pady=6)
        self.token_var = tk.StringVar(value=self.settings.get("token", ""))
        self.token_entry = ttk.Entry(form, textvariable=self.token_var, show="\u2022")
        self.token_entry.grid(row=1, column=1, sticky="ew", padx=(12, 0), pady=6)

        self.show_token = tk.BooleanVar(value=False)
        ttk.Checkbutton(form, text="Show token", variable=self.show_token,
                        command=self._toggle_token).grid(row=2, column=1, sticky="w", padx=(12, 0))

        buttons = ttk.Frame(card, style="Card.TFrame")
        buttons.pack(fill="x", pady=(16, 0))
        self.connect_btn = ttk.Button(buttons, text="Connect", style="Accent.TButton", command=self.connect)
        self.connect_btn.pack(side="left")
        ttk.Button(buttons, text="Open admin panel", command=self._open_admin).pack(side="left", padx=8)

        self.connect_message = ttk.Label(card, style="Muted.TLabel", wraplength=760, justify="left", text="")
        self.connect_message.pack(anchor="w", pady=(12, 0))

        status = self._card(tab)
        ttk.Label(status, text="Agent", font=self.head_font, style="Card.TLabel").pack(anchor="w")
        self.agent_state = ttk.Label(status, style="Muted.TLabel",
                                     text="Stopped. Connect first, then add a printer.")
        self.agent_state.pack(anchor="w", pady=(4, 12))

        row = ttk.Frame(status, style="Card.TFrame")
        row.pack(fill="x")
        self.start_btn = ttk.Button(row, text="Start printing", style="Accent.TButton",
                                    command=self.start_agent, state="disabled")
        self.start_btn.pack(side="left")
        self.stop_btn = ttk.Button(row, text="Stop", command=self.stop_agent, state="disabled")
        self.stop_btn.pack(side="left", padx=8)

    def _build_printers_tab(self) -> None:
        tab = ttk.Frame(self.tabs, padding=16)
        self.tabs.add(tab, text="  Printers  ")

        card = self._card(tab)
        ttk.Label(card, text="Printers on this computer", font=self.head_font,
                  style="Card.TLabel").pack(anchor="w")
        ttk.Label(card, style="Muted.TLabel", wraplength=760, justify="left",
                  text="What the printer's own driver reports. Choose a model profile if yours "
                       "is listed - it records the things a driver gets wrong, such as duplex "
                       "that is manual rather than automatic."
                  ).pack(anchor="w", pady=(4, 12))

        columns = ("queue", "colour", "sides", "sizes", "state")
        self.printer_tree = ttk.Treeview(card, columns=columns, show="headings", height=8)
        for column, heading, width in (
            ("queue", "Printer", 215), ("colour", "Colour", 115),
            ("sides", "Sides", 125), ("sizes", "Paper sizes", 215), ("state", "Added", 95),
        ):
            self.printer_tree.heading(column, text=heading)
            self.printer_tree.column(column, width=width, anchor="w")
        self.printer_tree.pack(fill="both", expand=True, pady=(0, 12))

        controls = ttk.Frame(card, style="Card.TFrame")
        controls.pack(fill="x")
        ttk.Label(controls, text="Model profile", style="Card.TLabel").pack(side="left")
        self.profile_var = tk.StringVar(value="Generic network printer")
        self.profile_box = ttk.Combobox(controls, textvariable=self.profile_var, state="readonly", width=34)
        self.profile_box.pack(side="left", padx=(10, 16))

        self.add_btn = ttk.Button(controls, text="Add this printer", style="Accent.TButton",
                                  command=self.register_selected, state="disabled")
        self.add_btn.pack(side="left")
        ttk.Button(controls, text="Refresh", command=self.refresh_printers).pack(side="left", padx=8)

        self.printer_message = ttk.Label(card, style="Muted.TLabel", wraplength=760,
                                         justify="left", text="")
        self.printer_message.pack(anchor="w", pady=(12, 0))

        note = self._card(tab)
        ttk.Label(note, text="Before customers can use it", font=self.head_font,
                  style="Card.TLabel").pack(anchor="w")
        ttk.Label(note, style="Muted.TLabel", wraplength=760, justify="left",
                  text="A printer offers nothing to customers until a capability has been "
                       "verified. Send a test page from the printer's page in the admin panel, "
                       "then mark what actually worked. That deliberate step is what stops "
                       "someone paying for double-sided on a printer that cannot do it."
                  ).pack(anchor="w", pady=(4, 10))
        ttk.Button(note, text="Open this shop's printers", command=self._open_printers).pack(anchor="w")

    def _build_log_tab(self) -> None:
        tab = ttk.Frame(self.tabs, padding=16)
        self.tabs.add(tab, text="  Activity  ")

        wrapper = tk.Frame(tab, bg=LINE)
        wrapper.pack(fill="both", expand=True)
        self.log_view = tk.Text(wrapper, font=self.mono, bg="white", fg=INK, wrap="word",
                                relief="flat", padx=12, pady=10, state="disabled")
        scroll = ttk.Scrollbar(wrapper, command=self.log_view.yview)
        self.log_view.configure(yscrollcommand=scroll.set)
        scroll.pack(side="right", fill="y")
        self.log_view.pack(side="left", fill="both", expand=True, padx=1, pady=1)

        self.log_view.tag_configure("ERROR", foreground=DANGER)
        self.log_view.tag_configure("WARNING", foreground="#8a6d1f")

    # -- helpers ----------------------------------------------------------

    def _toggle_token(self) -> None:
        self.token_entry.configure(show="" if self.show_token.get() else "\u2022")

    def _open_admin(self) -> None:
        server = self.server_var.get().strip().rstrip("/")
        if server.startswith("http"):
            webbrowser.open(server + "/admin/devices")

    def _open_printers(self) -> None:
        server = self.server_var.get().strip().rstrip("/")
        if server.startswith("http"):
            webbrowser.open(server + "/admin/printers")

    def _set_status(self, text: str, colour: str = MUTED) -> None:
        self.status_dot.configure(text=text, foreground=colour)

    def _append_log(self, line: str) -> None:
        tag = "ERROR" if " ERROR " in line else ("WARNING" if " WARNING" in line else "")
        self.log_view.configure(state="normal")
        self.log_view.insert("end", line + "\n", tag)
        # Keep the view bounded; a shop machine runs this for months.
        if int(self.log_view.index("end-1c").split(".")[0]) > 2000:
            self.log_view.delete("1.0", "500.0")
        self.log_view.see("end")
        self.log_view.configure(state="disabled")

    def _poll_log(self) -> None:
        """Drain both queues on the UI thread: log lines, then UI callbacks."""
        try:
            while True:
                self._append_log(self.log_pipe.records.get_nowait())
        except queue.Empty:
            pass

        try:
            while True:
                self.ui_queue.get_nowait()()
        except queue.Empty:
            pass
        except Exception:                                   # noqa: BLE001
            self._append_log(traceback.format_exc())

        self.root.after(200, self._poll_log)

    def _in_background(self, work, done) -> None:
        """
        Run a call off the UI thread, then hand the result back on it.

        The result travels through a queue the UI thread drains, not through
        root.after() called from the worker. Tk is not thread-safe, and calling
        into it from another thread raises "main thread is not in main loop"
        outright in some situations and corrupts state quietly in others.
        """
        def runner() -> None:
            try:
                result = work()
                self.ui_queue.put(lambda: done(result, None))
            except Exception as error:                      # noqa: BLE001 - reported to the user
                detail = error
                self.ui_queue.put(lambda: done(None, detail))

        threading.Thread(target=runner, daemon=True).start()

    # -- actions ----------------------------------------------------------

    def connect(self) -> None:
        server = self.server_var.get().strip().rstrip("/")
        token = self.token_var.get().strip()

        if not is_safe_server(server):
            messagebox.showerror(
                APP_NAME,
                "The server address must start with https://. The agent carries a token and "
                "customer documents, so it will not send them over an unencrypted connection.",
            )
            return
        if not token:
            messagebox.showerror(APP_NAME, "Paste the agent token from the admin panel.")
            return

        self.connect_btn.configure(state="disabled")
        self.connect_message.configure(text="Connecting...", foreground=MUTED)
        self._set_status("Connecting", MUTED)

        module = self.agent_module
        config = module.Config(server=server, token=token,
                               work_dir=self.config_path.parent / "work")

        def work():
            api = module.Api(config)
            configuration = api.config_call()
            profiles: list[dict[str, Any]] = []
            try:
                profiles = api._request("GET", "/api/agent/profiles").get("profiles", [])
            except Exception:
                # An older server will not have this endpoint; the app still works.
                profiles = []
            return api, configuration, profiles

        self._in_background(work, self._connected)

    def _connected(self, result, error) -> None:
        self.connect_btn.configure(state="normal")

        if error is not None:
            self.connect_message.configure(text=f"Could not connect: {error}", foreground=DANGER)
            self._set_status("Not connected", DANGER)
            return

        self.api, configuration, self.profiles = result
        self.device = configuration.get("device", {})
        self.registered = {
            (p.get("queue_name") or p.get("code")): p
            for p in configuration.get("printers", [])
        }

        self._write_settings()

        name = self.device.get("name", "this computer")
        count = len(self.registered)
        self.connect_message.configure(
            text=f"Connected as \u201c{name}\u201d with {count} printer(s) assigned.",
            foreground=OK,
        )
        self._set_status(f"Connected \u00b7 {name}", OK)
        self.start_btn.configure(state="normal")

        # The server's own list, which already contains the generic fallback -
        # prepending one here listed it twice.
        self.profile_by_label = {p["label"]: p["key"] for p in self.profiles}
        labels = list(self.profile_by_label)
        if not labels:
            labels = ["Generic network printer"]
            self.profile_by_label = {"Generic network printer": "generic"}
        self.profile_box.configure(values=labels)
        if self.profile_var.get() not in self.profile_by_label:
            self.profile_var.set(labels[-1] if "Generic" in labels[-1] else labels[0])

        self.refresh_printers()
        self.tabs.select(1)

    def refresh_printers(self) -> None:
        module = self.agent_module
        spooler = module.select_spooler()

        if spooler is None:
            self.printer_message.configure(
                text="No printing system was found on this computer.", foreground=DANGER)
            return

        self.printer_message.configure(text="Reading printers...", foreground=MUTED)

        def work():
            names = spooler.local_printers()
            found = []
            for name in names:
                capabilities = spooler.capabilities(name) or {}
                found.append({"queue": name, "capabilities": capabilities})
            return found

        self._in_background(work, self._printers_read)

    def _printers_read(self, result, error) -> None:
        if error is not None:
            self.printer_message.configure(text=f"Could not read printers: {error}", foreground=DANGER)
            return

        self.local_printers = result
        self.printer_tree.delete(*self.printer_tree.get_children())

        for entry in result:
            capabilities = entry["capabilities"]
            colour = "Colour" if "color" in capabilities.get("color_modes", []) else "Black & white"
            sides = "Single & double" if "double" in capabilities.get("duplex_modes", []) else "Single only"
            sizes = ", ".join(capabilities.get("paper_sizes", [])) or "not reported"
            state = "Added" if entry["queue"] in self.registered else "Not added"
            self.printer_tree.insert("", "end", iid=entry["queue"],
                                     values=(entry["queue"], colour, sides, sizes, state))

        self.add_btn.configure(state="normal" if result else "disabled")
        self.printer_message.configure(
            text=f"{len(result)} printer(s) found on this computer." if result
                 else "No printers are installed on this computer.",
            foreground=MUTED,
        )

    def register_selected(self) -> None:
        selection = self.printer_tree.selection()
        if not selection:
            messagebox.showinfo(APP_NAME, "Choose a printer from the list first.")
            return

        queue_name = selection[0]
        profile_key = getattr(self, "profile_by_label", {}).get(self.profile_var.get(), "generic")
        entry = next((p for p in self.local_printers if p["queue"] == queue_name), None)
        capabilities = entry["capabilities"] if entry else {}

        api = self.api
        self.add_btn.configure(state="disabled")
        self.printer_message.configure(text=f"Adding {queue_name}...", foreground=MUTED)

        def work():
            response = api._request("POST", "/api/agent/printers", {
                "queue_name": queue_name,
                "name": queue_name,
                "capability_profile": profile_key,
            })

            # Report what the driver says straight away, so the printer's page
            # shows something real rather than an empty capability list.
            code = (response.get("printer") or {}).get("code")
            if code and capabilities:
                try:
                    api._request("POST", "/api/agent/capabilities", {
                        "printer_code": code,
                        "paper_sizes": capabilities.get("paper_sizes", []),
                        "color_modes": capabilities.get("color_modes", []),
                        "duplex_modes": capabilities.get("duplex_modes", []),
                    })
                except Exception:
                    pass        # the printer is registered; capabilities can be probed later
            return response

        def done(response, error):
            self.add_btn.configure(state="normal")
            if error is not None:
                self.printer_message.configure(text=f"Could not add it: {error}", foreground=DANGER)
                return

            printer = response.get("printer", {})
            self.registered[queue_name] = printer
            self.printer_tree.set(queue_name, "state", "Added")
            self.printer_message.configure(
                text=f"\u201c{queue_name}\u201d is now printer {printer.get('code')}. "
                     "Send it a test page from the admin panel before customers use it.",
                foreground=OK,
            )

        self._in_background(work, done)

    def start_agent(self) -> None:
        if self.agent_thread is not None and self.agent_thread.is_alive():
            return

        self._write_settings()
        module = self.agent_module
        config = module.Config(
            server=self.settings["server"],
            token=self.settings["token"],
            poll_interval=int(self.settings.get("poll_interval", 10)),
            work_dir=Path(self.settings["work_dir"]),
            verify_tls=bool(self.settings.get("verify_tls", True)),
        )

        self.agent_instance = module.Agent(config)

        def run() -> None:
            try:
                self.agent_instance.run()
            except Exception:
                self._append_log(traceback.format_exc())

        self.agent_thread = threading.Thread(target=run, daemon=True)
        self.agent_thread.start()

        self.agent_state.configure(text="Running. This window can stay open in the background.")
        self.start_btn.configure(state="disabled")
        self.stop_btn.configure(state="normal")
        self._set_status("Printing service running", OK)
        self.tabs.select(2)

    def stop_agent(self) -> None:
        if self.agent_instance is not None:
            self.agent_instance.stop()
        self.agent_state.configure(text="Stopping after the current job...")
        self.stop_btn.configure(state="disabled")
        self.start_btn.configure(state="normal")
        self._set_status("Stopped", MUTED)

    def on_close(self) -> None:
        if self.agent_thread is not None and self.agent_thread.is_alive():
            if not messagebox.askokcancel(
                APP_NAME,
                "The printing service is running. Close anyway? Jobs will stop being collected.",
            ):
                return
            if self.agent_instance is not None:
                self.agent_instance.stop()
        self.root.destroy()


def main() -> int:
    root = tk.Tk()
    try:
        app = DesktopApp(root)
    except Exception as error:                              # noqa: BLE001
        messagebox.showerror(APP_NAME, f"{APP_NAME} could not start:\n\n{error}")
        return 1

    root.protocol("WM_DELETE_WINDOW", app.on_close)
    root.mainloop()
    return 0


if __name__ == "__main__":
    sys.exit(main())
