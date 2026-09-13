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

# Everything kpms-agent.py imports, imported here too.
#
# The agent is loaded at runtime by path, so a packager analysing imports
# statically never sees inside it: PyInstaller built an executable that started
# and then died with "No module named 'platform'", because nothing in the
# import graph it could see mentioned platform. Naming them here puts them in
# the graph. tests/desktop_check.py fails if this list and the agent's real
# imports ever drift apart, so it cannot rot quietly.
import argparse          # noqa: F401
import dataclasses       # noqa: F401
import hashlib           # noqa: F401
import logging           # noqa: F401
import platform          # noqa: F401
import re                # noqa: F401
import shutil            # noqa: F401
import signal            # noqa: F401
import subprocess        # noqa: F401
import tempfile          # noqa: F401
import time              # noqa: F401
import typing            # noqa: F401
import urllib.error      # noqa: F401
import urllib.parse      # noqa: F401
import urllib.request    # noqa: F401

APP_NAME = "Krishna Printer"
APP_VERSION = "1.0.0"
AUTOSTART_KEY = r"Software\Microsoft\Windows\CurrentVersion\Run"

# --- Colours -------------------------------------------------------------
# The same green as the web admin, so the two feel like one product.
INK = "#14261f"
MUTED = "#5a6b64"
LINE = "#d9e2de"
CANVAS = "#f5f7f6"
ACCENT = "#1e6f5c"
DANGER = "#b3261e"
OK = "#1e6f5c"


def machine_config_path() -> Path:
    """Where the service installer puts its configuration, for every user."""
    if os.name == "nt":
        base = Path(os.environ.get("ProgramData", r"C:\ProgramData"))
        return base / "KrishnaPrinter" / "agent" / "config.json"
    return Path("/etc/kpms-agent/config.json")


def user_config_path() -> Path:
    """Where this user's own copy lives."""
    if os.name == "nt":
        base = Path(os.environ.get("LOCALAPPDATA", str(Path.home() / "AppData" / "Local")))
        return base / "KrishnaPrinter" / "agent" / "config.json"
    return Path.home() / ".config" / "kpms-agent" / "config.json"


def default_config_path() -> Path:
    """
    The configuration this window should use.

    The machine-wide file when it is already there and writable - so the app
    and the service share one setup - and this user's own otherwise.

    The service installer locks its config down to Administrators and SYSTEM,
    which is right for a file holding a token. The desktop app runs as an
    ordinary user, and writing there raised PermissionError and took the whole
    connection down with it. An app that asks someone to run a print shop
    should not also ask them for Administrator.
    """
    machine = machine_config_path()
    if machine.is_file() and os.access(machine, os.W_OK):
        return machine

    user = user_config_path()
    if user.is_file():
        return user

    # Nothing yet: prefer the machine-wide location when it can be created,
    # since a service installed later will then find the same settings.
    try:
        machine.parent.mkdir(parents=True, exist_ok=True)
        probe = machine.parent / ".write-test"
        probe.write_text("")
        probe.unlink()
        return machine
    except OSError:
        return user


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


def autostart_supported() -> bool:
    return os.name == "nt"


def autostart_command() -> str:
    """
    The command Windows should run at logon.

    Frozen by PyInstaller this is the executable itself; from a checkout it is
    the interpreter and this script. Either way it starts hidden, straight
    into the notification area, so logging in does not put a window in the
    operator's way.
    """
    if getattr(sys, "frozen", False):
        return f'"{sys.executable}" --hidden'
    return f'"{sys.executable}" "{Path(__file__).resolve()}" --hidden'


def autostart_enabled() -> bool:
    if not autostart_supported():
        return False
    try:
        import winreg

        with winreg.OpenKey(winreg.HKEY_CURRENT_USER, AUTOSTART_KEY) as key:
            value, _ = winreg.QueryValueEx(key, APP_NAME)
            return bool(value)
    except OSError:
        return False


def set_autostart(enabled: bool) -> str:
    """
    Turn starting-with-Windows on or off. Returns "" or why it could not.

    HKEY_CURRENT_USER, not a service and not the machine-wide Run key: this
    needs no Administrator, and it starts in the operator's own session, which
    is the only session where their printers exist.
    """
    if not autostart_supported():
        return "Starting with the system is only wired up for Windows."

    try:
        import winreg

        with winreg.CreateKey(winreg.HKEY_CURRENT_USER, AUTOSTART_KEY) as key:
            if enabled:
                winreg.SetValueEx(key, APP_NAME, 0, winreg.REG_SZ, autostart_command())
            else:
                try:
                    winreg.DeleteValue(key, APP_NAME)
                except FileNotFoundError:
                    pass
        return ""
    except OSError as error:
        return f"Windows would not save the setting: {error}"


class TrayIcon:
    """
    An icon in the Windows notification area, through ctypes alone.

    Why by hand rather than a library: the whole application is standard
    library, which is what lets the same file run as a script on a machine
    with nothing installed and as a packaged executable on one with no Python
    at all. A tray library would have been the only thing to break that.

    The icon owns a thread with its own window and message loop, because that
    is what Win32 requires. Nothing here touches Tk. Menu choices are put on
    the queue the Tk thread already drains, which is the only safe way to
    reach a widget from another thread.

    Every failure path returns False rather than raising. An operator whose
    notification area misbehaves should get an ordinary window, not a dead
    application - see DesktopApp.hide_window, which minimises normally when
    this is not running.
    """

    OPEN, START, STOP, QUIT = 1001, 1002, 1003, 1004

    def __init__(self, title: str, actions: dict[int, Any], is_running) -> None:
        self.title = title[:127]
        self.actions = actions
        self.is_running = is_running
        self.thread: threading.Thread | None = None
        self.ready = threading.Event()
        self.hwnd = None
        self.error = ""
        self._data = None
        self._shell32 = None

    @staticmethod
    def supported() -> bool:
        return os.name == "nt"

    def start(self) -> bool:
        if not self.supported():
            self.error = "The notification area is a Windows feature."
            return False

        self.thread = threading.Thread(target=self._serve, daemon=True, name="tray")
        self.thread.start()
        self.ready.wait(timeout=10)
        return self.hwnd is not None

    def stop(self) -> None:
        if self.hwnd is None or self._user32 is None:
            return
        try:
            # self._user32, not a fresh windll: this one has argtypes set, so
            # the window handle survives the call on 64-bit Windows.
            self._user32.PostMessageW(self.hwnd, 0x0010, 0, 0)      # WM_CLOSE
        except Exception:                                   # noqa: BLE001
            pass

    def notify(self, title: str, message: str) -> None:
        """A balloon, used once to say where the window went."""
        if self.hwnd is None or self._data is None:
            return
        try:
            import ctypes

            self._data.uFlags = 0x00000010                   # NIF_INFO
            self._data.szInfo = message[:255]
            self._data.szInfoTitle = title[:63]
            self._data.dwInfoFlags = 0x00000001              # NIIF_INFO
            self._shell32.Shell_NotifyIconW(0x00000001, ctypes.byref(self._data))  # NIM_MODIFY
        except Exception:                                   # noqa: BLE001
            pass

    # -- everything below runs on the tray thread --------------------------

    def _serve(self) -> None:
        try:
            self._build()
        except Exception as error:                          # noqa: BLE001
            self.error = f"{error.__class__.__name__}: {error}"
            self.hwnd = None
            self.ready.set()
            return

        self.ready.set()
        self._pump()

    def _build(self) -> None:
        import ctypes
        from ctypes import wintypes

        user32 = ctypes.windll.user32
        shell32 = ctypes.windll.shell32
        kernel32 = ctypes.windll.kernel32
        self._shell32 = shell32

        LRESULT = ctypes.c_ssize_t
        WNDPROC = ctypes.WINFUNCTYPE(
            LRESULT, wintypes.HWND, wintypes.UINT, wintypes.WPARAM, wintypes.LPARAM
        )

        class WNDCLASS(ctypes.Structure):
            _fields_ = [
                ("style", wintypes.UINT),
                ("lpfnWndProc", WNDPROC),
                ("cbClsExtra", ctypes.c_int),
                ("cbWndExtra", ctypes.c_int),
                ("hInstance", wintypes.HINSTANCE),
                ("hIcon", wintypes.HICON),
                ("hCursor", wintypes.HANDLE),
                ("hbrBackground", wintypes.HBRUSH),
                ("lpszMenuName", wintypes.LPCWSTR),
                ("lpszClassName", wintypes.LPCWSTR),
            ]

        class NOTIFYICONDATA(ctypes.Structure):
            _fields_ = [
                ("cbSize", wintypes.DWORD),
                ("hWnd", wintypes.HWND),
                ("uID", wintypes.UINT),
                ("uFlags", wintypes.UINT),
                ("uCallbackMessage", wintypes.UINT),
                ("hIcon", wintypes.HICON),
                ("szTip", wintypes.WCHAR * 128),
                ("dwState", wintypes.DWORD),
                ("dwStateMask", wintypes.DWORD),
                ("szInfo", wintypes.WCHAR * 256),
                ("uVersion", wintypes.UINT),
                ("szInfoTitle", wintypes.WCHAR * 64),
                ("dwInfoFlags", wintypes.DWORD),
                ("guidItem", ctypes.c_byte * 16),
                ("hBalloonIcon", wintypes.HICON),
            ]

        # Every one of these returns or takes a handle. ctypes assumes c_int
        # for anything it is not told about, which silently truncates a 64-bit
        # handle to 32 bits - and 64-bit is what every Windows 10 machine is.
        # The failure is not an error: the window is created and then every
        # later call is given half a handle and quietly does nothing.
        user32.DefWindowProcW.restype = LRESULT
        user32.DefWindowProcW.argtypes = [
            wintypes.HWND, wintypes.UINT, wintypes.WPARAM, wintypes.LPARAM
        ]
        kernel32.GetModuleHandleW.restype = wintypes.HMODULE
        kernel32.GetModuleHandleW.argtypes = [wintypes.LPCWSTR]
        user32.CreateWindowExW.restype = wintypes.HWND
        user32.CreateWindowExW.argtypes = [
            wintypes.DWORD, wintypes.LPCWSTR, wintypes.LPCWSTR, wintypes.DWORD,
            ctypes.c_int, ctypes.c_int, ctypes.c_int, ctypes.c_int,
            wintypes.HWND, wintypes.HMENU, wintypes.HINSTANCE, wintypes.LPVOID,
        ]
        user32.DestroyWindow.argtypes = [wintypes.HWND]
        user32.PostMessageW.restype = wintypes.BOOL
        user32.PostMessageW.argtypes = [
            wintypes.HWND, wintypes.UINT, wintypes.WPARAM, wintypes.LPARAM
        ]
        user32.SetForegroundWindow.argtypes = [wintypes.HWND]
        user32.CreatePopupMenu.restype = wintypes.HMENU
        user32.DestroyMenu.argtypes = [wintypes.HMENU]
        user32.AppendMenuW.argtypes = [
            wintypes.HMENU, wintypes.UINT, ctypes.c_size_t, wintypes.LPCWSTR
        ]
        user32.TrackPopupMenu.argtypes = [
            wintypes.HMENU, wintypes.UINT, ctypes.c_int, ctypes.c_int,
            ctypes.c_int, wintypes.HWND, wintypes.LPVOID,
        ]
        user32.GetMessageW.argtypes = [wintypes.LPVOID, wintypes.HWND, wintypes.UINT, wintypes.UINT]
        user32.GetMessageW.restype = ctypes.c_int

        # Held on self: a WNDPROC that Python garbage-collects while Windows
        # still holds the pointer crashes the process, and the crash happens
        # minutes later somewhere unrelated.
        self._proc = WNDPROC(self._dispatch)

        # Set before the window exists, not after. CreateWindowExW delivers
        # WM_NCCREATE and WM_CREATE synchronously, so _dispatch runs during
        # the call below - and reading self._user32 there when it was assigned
        # afterwards raises inside a ctypes callback, where the exception has
        # nowhere to go.
        self._user32 = user32
        self._ctypes = ctypes
        self._wintypes = wintypes

        instance = kernel32.GetModuleHandleW(None)
        # A class name unique to this process, so two copies do not collide.
        self._class_name = f"KrishnaPrinterTray{os.getpid()}"

        window_class = WNDCLASS()
        window_class.lpfnWndProc = self._proc
        window_class.hInstance = instance
        window_class.lpszClassName = self._class_name
        if not user32.RegisterClassW(ctypes.byref(window_class)):
            raise OSError(f"RegisterClassW failed: {ctypes.get_last_error()}")
        self._class = window_class          # also held against collection

        hwnd = user32.CreateWindowExW(
            0, self._class_name, self.title, 0, 0, 0, 0, 0, None, None, instance, None
        )
        if not hwnd:
            raise OSError(f"CreateWindowExW failed: {ctypes.get_last_error()}")

        data = NOTIFYICONDATA()
        data.cbSize = ctypes.sizeof(NOTIFYICONDATA)
        data.hWnd = hwnd
        data.uID = 1
        data.uFlags = 0x00000001 | 0x00000002 | 0x00000004   # MESSAGE | ICON | TIP
        data.uCallbackMessage = 0x8001                        # WM_APP + 1
        data.hIcon = self._icon(user32, shell32, instance)
        data.szTip = self.title

        if not shell32.Shell_NotifyIconW(0x00000000, ctypes.byref(data)):   # NIM_ADD
            user32.DestroyWindow(hwnd)
            raise OSError("Shell_NotifyIconW would not add the icon.")

        self._data = data
        self.hwnd = hwnd

    def _icon(self, user32, shell32, instance):
        """The application's own icon where there is one, else the generic one."""
        try:
            import ctypes

            shell32.ExtractIconW.restype = ctypes.c_void_p
            handle = shell32.ExtractIconW(instance, sys.executable, 0)
            if handle and handle > 1:
                return handle
        except Exception:                                   # noqa: BLE001
            pass
        user32.LoadIconW.restype = ctypes.c_void_p
        return user32.LoadIconW(None, 32512)                # IDI_APPLICATION

    def _dispatch(self, hwnd, message, wparam, lparam):
        user32 = self._user32

        if message == 0x8001:                               # our callback
            event = lparam & 0xFFFF
            if event == 0x0203:                             # WM_LBUTTONDBLCLK
                self._fire(self.OPEN)
            elif event in (0x0205, 0x0207):                 # WM_RBUTTONUP, WM_MBUTTONDOWN
                self._menu(hwnd)
            return 0

        if message == 0x0111:                               # WM_COMMAND
            self._fire(wparam & 0xFFFF)
            return 0

        if message == 0x0002:                               # WM_DESTROY
            try:
                self._shell32.Shell_NotifyIconW(0x00000002, self._ctypes.byref(self._data))
            except Exception:                               # noqa: BLE001
                pass
            user32.PostQuitMessage(0)
            return 0

        return user32.DefWindowProcW(hwnd, message, wparam, lparam)

    def _menu(self, hwnd) -> None:
        ctypes = self._ctypes
        user32 = self._user32

        class POINT(ctypes.Structure):
            _fields_ = [("x", ctypes.c_long), ("y", ctypes.c_long)]

        running = False
        try:
            running = bool(self.is_running())
        except Exception:                                   # noqa: BLE001
            pass

        MF_STRING, MF_GRAYED, MF_SEPARATOR = 0x0000, 0x0001, 0x0800
        menu = user32.CreatePopupMenu()
        user32.AppendMenuW(menu, MF_STRING, self.OPEN, "Open Krishna Printer")
        user32.AppendMenuW(menu, MF_SEPARATOR, 0, None)
        user32.AppendMenuW(menu, MF_STRING | (MF_GRAYED if running else 0),
                           self.START, "Start printing")
        user32.AppendMenuW(menu, MF_STRING | (0 if running else MF_GRAYED),
                           self.STOP, "Stop printing")
        user32.AppendMenuW(menu, MF_SEPARATOR, 0, None)
        user32.AppendMenuW(menu, MF_STRING, self.QUIT, "Quit")

        point = POINT()
        user32.GetCursorPos(ctypes.byref(point))

        # Without this the menu never closes when the mouse goes elsewhere -
        # a documented Win32 quirk, and the reason for the empty message after.
        user32.SetForegroundWindow(hwnd)
        user32.TrackPopupMenu(menu, 0x0002 | 0x0020,        # RIGHTBUTTON | BOTTOMALIGN
                              point.x, point.y, 0, hwnd, None)
        user32.PostMessageW(hwnd, 0x0000, 0, 0)             # WM_NULL
        user32.DestroyMenu(menu)

    def _fire(self, command: int) -> None:
        action = self.actions.get(command)
        if action is not None:
            action()

    def _pump(self) -> None:
        ctypes = self._ctypes
        wintypes = self._wintypes
        user32 = self._user32

        class MSG(ctypes.Structure):
            _fields_ = [
                ("hWnd", wintypes.HWND), ("message", wintypes.UINT),
                ("wParam", wintypes.WPARAM), ("lParam", wintypes.LPARAM),
                ("time", wintypes.DWORD),
                ("pt_x", ctypes.c_long), ("pt_y", ctypes.c_long),
            ]

        message = MSG()
        while user32.GetMessageW(ctypes.byref(message), None, 0, 0) > 0:
            user32.TranslateMessage(ctypes.byref(message))
            user32.DispatchMessageW(ctypes.byref(message))
        self.hwnd = None


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
    def __init__(self, root: tk.Tk, start_hidden: bool = False) -> None:
        self.root = root
        self.start_hidden = start_hidden
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

        self.release: dict[str, Any] = {}
        self.tray: TrayIcon | None = None
        self.hidden = False
        self.explained_tray = False
        self.quitting = False

        self._build()
        self._poll_log()
        self._start_tray()

        # A token already on disk means this machine has been set up before.
        configured = bool(self.settings.get("token") and self.settings.get("server"))
        if configured:
            self.server_var.set(self.settings["server"])
            self.token_var.set(self.settings["token"])
            self.root.after(300, self.connect)

        # Launched by Windows at logon: go straight to the notification area
        # and start printing. Nobody asked for a window, they logged in.
        if self.start_hidden and configured:
            self.autostarting = True
            self.root.after(100, self.hide_window)
        else:
            self.autostarting = False

    # -- notification area -------------------------------------------------

    def _start_tray(self) -> None:
        if not TrayIcon.supported():
            return

        def queued(action):
            return lambda: self.ui_queue.put(action)

        tray = TrayIcon(
            f"{APP_NAME} - print agent",
            {
                TrayIcon.OPEN: queued(self.show_window),
                TrayIcon.START: queued(self.start_agent),
                TrayIcon.STOP: queued(self.stop_agent),
                TrayIcon.QUIT: queued(self.quit_app),
            },
            is_running=lambda: self.agent_thread is not None and self.agent_thread.is_alive(),
        )
        if tray.start():
            self.tray = tray
        else:
            # Not fatal: without it, minimise means minimise.
            self._append_log(f"The notification area is unavailable ({tray.error}). "
                             "The window will minimise to the taskbar instead.")

    def hide_window(self) -> None:
        """
        Out of the way, still printing.

        With a tray icon the window is withdrawn completely, which is what
        "run in the background" means on Windows. Without one it is only
        iconified, because a withdrawn window with nothing to restore it from
        is an application the operator cannot get back.
        """
        if self.tray is None:
            self.root.iconify()
            return

        self.root.withdraw()
        self.hidden = True

        if not self.explained_tray:
            self.explained_tray = True
            self.tray.notify(
                APP_NAME,
                "Still here, near the clock. Printing carries on. "
                "Double-click the icon to open this window again.",
            )

    def show_window(self) -> None:
        self.hidden = False
        self.root.deiconify()
        self.root.state("normal")
        self.root.lift()
        try:
            self.root.focus_force()
        except tk.TclError:
            pass

    def _on_unmap(self, event) -> None:
        """Minimise means hide, when there is somewhere to hide to."""
        if event.widget is not self.root or self.tray is None or self.hidden:
            return
        if self.root.state() == "iconic":
            self.hide_window()

    def quit_app(self) -> None:
        """Leave for real, from the tray menu or from the Quit button."""
        if self.agent_instance is not None:
            self.agent_instance.stop()
        self.quitting = True
        if self.tray is not None:
            self.tray.stop()
        self.root.destroy()

    # -- configuration ----------------------------------------------------

    def _read_settings(self) -> dict[str, Any]:
        """This user's settings, falling back to the machine-wide ones."""
        for path in (self.config_path, machine_config_path(), user_config_path()):
            try:
                # utf-8-sig: PowerShell and Notepad both write a byte-order mark.
                return json.loads(path.read_text(encoding="utf-8-sig"))
            except (OSError, ValueError):
                continue
        return {}

    def _write_settings(self) -> str:
        """
        Save the settings, falling back to this user's own file.

        Returns a note when the location was not the expected one, so the
        window can say where the token went instead of failing silently.
        """
        for path in (self.config_path, user_config_path()):
            try:
                path.parent.mkdir(parents=True, exist_ok=True)
                work = path.parent / "work"
                work.mkdir(parents=True, exist_ok=True)

                self.settings.update({
                    "server": self.server_var.get().strip().rstrip("/"),
                    "token": self.token_var.get().strip(),
                    "work_dir": str(work),
                    "poll_interval": int(self.settings.get("poll_interval", 10)),
                    "verify_tls": bool(self.settings.get("verify_tls", True)),
                })
                path.write_text(json.dumps(self.settings, indent=2), encoding="utf-8")

                if os.name != "nt":
                    try:
                        path.chmod(0o600)
                    except OSError:
                        pass

                if path != self.config_path:
                    self.config_path = path
                    return (
                        f" The shared settings file could not be written, so this is saved "
                        f"for your account instead ({path})."
                    )
                return ""
            except OSError:
                continue

        return " The settings could not be saved, so the token will be asked for again next time."

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
        style.configure("Card.TCheckbutton", background="white", foreground=INK)
        style.map("Card.TCheckbutton", background=[("active", "white")])
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
        ttk.Button(row, text="Hide", command=self.hide_window).pack(side="left", padx=8)
        ttk.Button(row, text="Quit", command=self.quit_app).pack(side="right")

        background = self._card(tab)
        ttk.Label(background, text="Running in the background",
                  font=self.head_font, style="Card.TLabel").pack(anchor="w")
        ttk.Label(background, style="Muted.TLabel", wraplength=760, justify="left",
                  text="Closing or minimising this window puts it beside the clock and keeps "
                       "printing. Right-click that icon to start, stop or quit. Quit is the "
                       "only thing that stops collecting jobs."
                  ).pack(anchor="w", pady=(4, 12))

        self.autostart_var = tk.BooleanVar(value=autostart_enabled())
        self.autostart_box = ttk.Checkbutton(
            background, text="Start automatically when I log in to this computer",
            variable=self.autostart_var, command=self._toggle_autostart, style="Card.TCheckbutton",
        )
        self.autostart_box.pack(anchor="w")
        self.autostart_message = ttk.Label(background, style="Muted.TLabel",
                                           wraplength=760, justify="left", text="")
        self.autostart_message.pack(anchor="w", pady=(6, 0))

        if not autostart_supported():
            self.autostart_box.configure(state="disabled")
            self.autostart_message.configure(
                text="On Linux the installer registers a systemd service instead.")

        updates = self._card(tab)
        ttk.Label(updates, text="Updates", font=self.head_font, style="Card.TLabel").pack(anchor="w")
        self.update_state = ttk.Label(
            updates, style="Muted.TLabel", wraplength=760, justify="left",
            text=f"This computer has version {APP_VERSION}. Connect to see whether a newer one "
                 f"has been published.")
        self.update_state.pack(anchor="w", pady=(4, 12))

        update_row = ttk.Frame(updates, style="Card.TFrame")
        update_row.pack(fill="x")
        self.update_btn = ttk.Button(update_row, text="Install update", style="Accent.TButton",
                                     command=self.install_update, state="disabled")
        self.update_btn.pack(side="left")
        self.check_btn = ttk.Button(update_row, text="Check for updates",
                                    command=self.check_for_update, state="disabled")
        self.check_btn.pack(side="left", padx=8)

    def _toggle_autostart(self) -> None:
        wanted = bool(self.autostart_var.get())
        problem = set_autostart(wanted)
        if problem:
            self.autostart_var.set(autostart_enabled())
            self.autostart_message.configure(text=problem, foreground=DANGER)
            return
        self.autostart_message.configure(
            text=("It will start hidden next time you log in, and begin printing on its own."
                  if wanted else "It will not start on its own."),
            foreground=MUTED,
        )

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
                callback = self.ui_queue.get_nowait()
                try:
                    callback()
                except Exception as error:                  # noqa: BLE001
                    # Whatever went wrong, say it in words. A traceback in a
                    # dialog helps nobody running a shop, and one raised here
                    # used to repeat on every poll.
                    self._append_log(traceback.format_exc())
                    self._set_status("Something went wrong", DANGER)
                    self.connect_message.configure(
                        text=f"{error.__class__.__name__}: {error}\n"
                             "The Activity tab has the detail.",
                        foreground=DANGER,
                    )
        except queue.Empty:
            pass

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

        note = self._write_settings()

        name = self.device.get("name", "this computer")
        count = len(self.registered)
        self.connect_message.configure(
            text=f"Connected as \u201c{name}\u201d with {count} printer(s) assigned." + note,
            foreground=OK,
        )
        self._set_status(f"Connected \u00b7 {name}", OK)
        self.start_btn.configure(state="normal")
        self.check_btn.configure(state="normal")
        # The config call carries the published release, so a connected window
        # already knows whether there is one without asking a second time.
        self._show_release(configuration.get("update") or {})

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

        # Started by Windows at logon, with a printer already assigned: begin
        # printing without waiting to be told. Anything else would mean jobs
        # sitting in the queue until somebody noticed the icon.
        if self.autostarting:
            self.autostarting = False
            if self.registered:
                self.start_agent()
            return

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
            # Ports, where the backend can report them, so a queue that writes
            # a file instead of printing can be marked before somebody picks it
            # and waits for paper that was never coming.
            ports = spooler.printer_ports() if hasattr(spooler, "printer_ports") else {}
            found = []
            for name in names:
                capabilities = spooler.capabilities(name) or {}
                found.append({
                    "queue": name,
                    "capabilities": capabilities,
                    "virtual": module.looks_virtual(name, ports.get(name, "")),
                })
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
            if entry.get("virtual"):
                state = "Not a printer"
            else:
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

        # Asked rather than refused: somebody may genuinely want a file-writing
        # queue for a trial. But they should find out here, not from a customer
        # standing at the counter waiting for a page that was never coming.
        if entry is not None and entry.get("virtual") and not messagebox.askokcancel(
            APP_NAME,
            f"\u201c{queue_name}\u201d writes a file instead of printing onto paper. "
            "It asks where to save, in a window nobody will be there to answer, so jobs "
            "sent to it will fail.\n\nAdd it anyway?",
        ):
            return

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

    # -- updates -----------------------------------------------------------

    def _show_release(self, release: dict[str, Any]) -> None:
        """Say what is available, and enable the button only if it really is."""
        self.release = release if isinstance(release, dict) else {}
        module = self.agent_module

        if not self.release.get("published"):
            self.update_state.configure(
                text=f"This computer has version {APP_VERSION}. Nothing newer has been published.",
                foreground=MUTED)
            self.update_btn.configure(state="disabled")
            return

        version = str(self.release.get("version", ""))
        if not module.is_newer(version, APP_VERSION):
            self.update_state.configure(
                text=f"Version {APP_VERSION} — up to date.", foreground=OK)
            self.update_btn.configure(state="disabled")
            return

        notes = str(self.release.get("notes", "")).strip()
        self.update_state.configure(
            text=f"Version {version} is available. You have {APP_VERSION}."
                 + (f"\n\n{notes}" if notes else ""),
            foreground=INK)
        self.update_btn.configure(state="normal")

    def check_for_update(self) -> None:
        if self.api is None:
            return
        self.check_btn.configure(state="disabled")
        self.update_state.configure(text="Asking the server…", foreground=MUTED)

        api = self.api

        def work():
            return api.release()

        def done(result, error):
            self.check_btn.configure(state="normal")
            if error is not None:
                self.update_state.configure(
                    text=f"Could not check for updates: {error}", foreground=DANGER)
                return
            self._show_release(result)

        self._in_background(work, done)

    def install_update(self) -> None:
        """
        Download the release, check it, and hand it to the installer.

        The download is refused unless it hashes to what the server said, and
        the installer is run silently — an update nobody asked to watch should
        not put a wizard over the counter. The app then closes, because Windows
        cannot replace a running executable, and the installer brings it back.
        """
        if self.api is None or not self.release.get("published"):
            return

        version = str(self.release.get("version", ""))
        if not messagebox.askokcancel(
            APP_NAME,
            f"Update to version {version}?\n\n"
            "Printing stops for about a minute while it installs, and this window closes and "
            "comes back on its own. Do not do this in the middle of a busy counter.",
        ):
            return

        self.update_btn.configure(state="disabled")
        self.check_btn.configure(state="disabled")
        self.update_state.configure(text=f"Downloading version {version}…", foreground=INK)

        api = self.api
        release = dict(self.release)
        target = Path(self.settings.get("work_dir", str(Path.home()))) / "KrishnaPrinterSetup.exe"

        def work():
            api.fetch(str(release["url"]), target, str(release["sha256"]))
            return target

        def done(result, error):
            if error is not None:
                self.update_btn.configure(state="normal")
                self.check_btn.configure(state="normal")
                self.update_state.configure(
                    text=f"The update was not installed: {error}", foreground=DANGER)
                return
            self._launch_installer(result, version)

        self._in_background(work, done)

    def _launch_installer(self, installer: Path, version: str) -> None:
        self.update_state.configure(
            text=f"Installing version {version}. This window will close and come back.",
            foreground=INK)

        if self.agent_instance is not None:
            self.agent_instance.stop()

        try:
            # Detached, so it outlives the process it is about to replace. The
            # Inno switches: silent, no restart prompt, and close the running
            # copy - Windows will not overwrite an executable that is open.
            self.agent_module.spawn_detached(
                [str(installer), "/SILENT", "/NORESTART", "/CLOSEAPPLICATIONS"]
            )
        except Exception as error:                          # noqa: BLE001
            self.update_btn.configure(state="normal")
            self.check_btn.configure(state="normal")
            self.update_state.configure(
                text=f"The installer would not start: {error}\n"
                     f"You can run it yourself: {installer}",
                foreground=DANGER)
            return

        self.root.after(1500, self.quit_app)

    def on_close(self) -> None:
        """
        The X button.

        With a notification-area icon this hides the window and leaves the
        agent printing - which is what a shop wants from the thing that runs
        its printer, and what closing does to every other background
        application on Windows. Quit, in the tray menu, is how you actually
        stop it. Without an icon there is nowhere to hide to, so X still means
        close, and it still asks first when a job could be lost.
        """
        if self.tray is not None:
            self.hide_window()
            return

        if self.agent_thread is not None and self.agent_thread.is_alive():
            if not messagebox.askokcancel(
                APP_NAME,
                "The printing service is running. Close anyway? Jobs will stop being collected.",
            ):
                return
        self.quit_app()


def main(argv: list[str] | None = None) -> int:
    arguments = sys.argv[1:] if argv is None else argv
    start_hidden = "--hidden" in arguments or "--tray" in arguments

    root = tk.Tk()
    try:
        app = DesktopApp(root, start_hidden=start_hidden)
    except Exception as error:                              # noqa: BLE001
        messagebox.showerror(APP_NAME, f"{APP_NAME} could not start:\n\n{error}")
        return 1

    root.protocol("WM_DELETE_WINDOW", app.on_close)
    root.bind("<Unmap>", app._on_unmap)
    root.mainloop()
    return 0


if __name__ == "__main__":
    sys.exit(main())
