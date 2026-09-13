#!/usr/bin/env python3
"""
Krishna Printer — location print agent.

WHAT THIS IS
------------
A small daemon that runs on an always-on Linux machine (a Raspberry Pi is
plenty) on the shop's own network. It makes ONLY outbound HTTPS requests to the
cloud application:

    heartbeat  → "I am alive; here is each printer's state from CUPS"
    claim      → "give me a job"
    document   → download the file
    (render + print locally through CUPS)
    status     → "printing" / "completed" / "failed"

WHY IT EXISTS
-------------
Two constraints make the direct approach unworkable:

  1. Port 9100 is unauthenticated by design. Anyone who can reach it can print,
     read the queue, or exhaust the consumables. Port-forwarding a printer to
     the internet is not a configuration choice, it is a vulnerability. Because
     this agent only dials out, no inbound port is opened and no VPN is needed.

  2. Consumer inkjets — the Canon PIXMA GM series included — have no PCL or
     PostScript interpreter. A PDF sent to port 9100 prints as pages of garbage.
     They need a host running the real driver to rasterise first. That host is
     this machine, via CUPS.

Together those mean no Windows PC is needed at any location, and the printer is
never exposed.

DEPENDENCIES
------------
Standard library only, plus the CUPS command-line tools:

    sudo apt install cups cups-client libreoffice-core libreoffice-writer \
                     libreoffice-calc libreoffice-impress poppler-utils

Add the printer to CUPS first (`lpadmin` or the CUPS web UI at
http://localhost:631), then run this agent with its token.
"""

from __future__ import annotations

import argparse
import hashlib
import json
import logging
import os
import platform
import re
import shutil
import signal
import subprocess
import sys
import threading
import tempfile
import time
import urllib.error
import urllib.parse
import urllib.request
from dataclasses import dataclass
from pathlib import Path
from typing import Any

AGENT_VERSION = "1.0.0"

# Formats CUPS can print directly. Everything else is converted to PDF first.
# What each backend can print without help, and what has to become a PDF
# first. CUPS has filters for images and plain text; Windows has none - there
# SumatraPDF is the only thing that prints unattended, and it prints PDFs.
# Sending it a PNG produced no paper and no error, which is worse than a
# refusal, so images and text go through LibreOffice on Windows too.
DIRECT_FORMATS = {".pdf", ".jpg", ".jpeg", ".png", ".txt"}
CONVERT_FORMATS = {".doc", ".docx", ".xls", ".xlsx", ".ppt", ".pptx"}

WINDOWS_DIRECT_FORMATS = {".pdf"}
WINDOWS_CONVERT_FORMATS = CONVERT_FORMATS | {".jpg", ".jpeg", ".png", ".txt"}

# PWG media names → the sizes this application knows about.
PWG_TO_SIZE = {
    "iso_a4_210x297mm": "A4",
    "iso_a5_148x210mm": "A5",
    "iso_a3_297x420mm": "A3",
    "jis_b5_182x257mm": "B5",
    "na_letter_8.5x11in": "Letter",
    "na_legal_8.5x14in": "Legal",
}

log = logging.getLogger("kpms-agent")


# Windows gives every console program it starts a console window of its own,
# even when the parent has none. The agent runs PowerShell several times a
# minute to read the queue, so on a counter PC a black box appeared and
# vanished over whatever the operator was doing, all day. CREATE_NO_WINDOW
# stops the window being made at all; the hidden STARTUPINFO covers the
# handful of programs that ask for a window themselves. Neither exists on
# Linux, where run_quiet is a plain subprocess.run.
CREATE_NO_WINDOW = getattr(subprocess, "CREATE_NO_WINDOW", 0)


def hidden_startupinfo():
    """A STARTUPINFO that asks for no window, or None off Windows."""
    if os.name != "nt":
        return None
    info = subprocess.STARTUPINFO()
    info.dwFlags |= subprocess.STARTF_USESHOWWINDOW
    info.wShowWindow = subprocess.SW_HIDE
    return info


def run_quiet(args, **kwargs) -> subprocess.CompletedProcess:
    """
    subprocess.run that never flashes a window.

    Every external program the agent starts goes through here. Capturing the
    output is the default because the agent reads it, and because output left
    to inherit the parent's handles is what makes Windows want a console in
    the first place.
    """
    kwargs.setdefault("capture_output", True)
    kwargs.setdefault("text", True)
    kwargs.setdefault("check", False)
    if os.name == "nt":
        kwargs["creationflags"] = kwargs.get("creationflags", 0) | CREATE_NO_WINDOW
        kwargs.setdefault("startupinfo", hidden_startupinfo())
    return subprocess.run(args, **kwargs)


@dataclass
class Config:
    server: str
    token: str
    poll_interval: int = 10
    heartbeat_interval: int = 30
    work_dir: Path = Path("/var/lib/kpms-agent")
    verify_tls: bool = True
    timeout: int = 60


class ApiError(RuntimeError):
    """A call to the cloud application failed."""

    def __init__(self, message: str, status: int | None = None, retryable: bool = True):
        super().__init__(message)
        self.status = status
        self.retryable = retryable


class Api:
    """Thin HTTPS client for the agent API."""

    def __init__(self, config: Config):
        self.config = config
        self.base = config.server.rstrip("/")

    def _request(
        self,
        method: str,
        path: str,
        payload: dict[str, Any] | None = None,
        headers: dict[str, str] | None = None,
        raw_response: bool = False,
    ) -> Any:
        url = self.base + path
        body = None
        request_headers = {
            "Authorization": f"Bearer {self.config.token}",
            "Accept": "application/json",
            "User-Agent": f"KrishnaPrinterAgent/{AGENT_VERSION}",
        }

        if payload is not None:
            body = json.dumps(payload).encode("utf-8")
            request_headers["Content-Type"] = "application/json"
        if headers:
            request_headers.update(headers)

        request = urllib.request.Request(url, data=body, headers=request_headers, method=method)

        # TLS verification is on by default. It can be turned off only for a
        # deliberate on-premise deployment with a private CA, and the agent
        # warns loudly at startup when it is.
        context = None
        if url.startswith("https://") and not self.config.verify_tls:
            import ssl

            context = ssl.create_default_context()
            context.check_hostname = False
            context.verify_mode = ssl.CERT_NONE

        try:
            with urllib.request.urlopen(request, timeout=self.config.timeout, context=context) as response:
                data = response.read()
                if raw_response:
                    return data, dict(response.headers)
                return json.loads(data.decode("utf-8"))
        except urllib.error.HTTPError as error:
            detail = error.read().decode("utf-8", "replace")[:400]
            # 4xx (other than 429) will not succeed on a retry.
            retryable = error.code >= 500 or error.code == 429
            raise ApiError(f"HTTP {error.code}: {detail}", error.code, retryable) from error
        except urllib.error.URLError as error:
            raise ApiError(f"Cannot reach the server: {error.reason}") from error
        except json.JSONDecodeError as error:
            raise ApiError(f"The server returned an unreadable response: {error}") from error

    def config_call(self) -> dict[str, Any]:
        return self._request("GET", "/api/agent/config")

    def heartbeat(self, printers: list[dict[str, Any]]) -> dict[str, Any]:
        return self._request("POST", "/api/agent/heartbeat", {
            "agent_version": AGENT_VERSION,
            "platform": f"{platform.system()} {platform.release()}",
            "printers": printers,
        })

    def claim(self) -> dict[str, Any]:
        return self._request("POST", "/api/agent/claim", {})

    def download(self, job_number: str, lease_token: str, target: Path) -> str:
        data, headers = self._request(
            "GET",
            f"/api/agent/jobs/{urllib.parse.quote(job_number)}/document",
            headers={"X-Lease-Token": lease_token},
            raw_response=True,
        )
        target.write_bytes(data)

        # The server sends the hash it stored at upload time; verifying it here
        # catches a truncated download before it reaches the printer.
        expected = headers.get("X-Document-Sha256") or headers.get("X-Document-SHA256")
        actual = hashlib.sha256(data).hexdigest()
        if expected and expected != actual:
            raise ApiError("The downloaded document failed its checksum.", retryable=True)
        return actual

    def report(self, job_number: str, lease_token: str, status: str, **fields: Any) -> dict[str, Any]:
        payload = {"status": status, "lease_token": lease_token}
        payload.update(fields)
        return self._request(
            "POST",
            f"/api/agent/jobs/{urllib.parse.quote(job_number)}/status",
            payload,
            headers={"X-Lease-Token": lease_token},
        )

    def report_capabilities(self, printer_code: str, capabilities: dict[str, Any]) -> dict[str, Any]:
        payload = {"printer_code": printer_code}
        payload.update(capabilities)
        return self._request("POST", "/api/agent/capabilities", payload)


class Cups:
    """Wrapper over the CUPS command-line tools."""

    direct_formats = DIRECT_FORMATS
    convert_formats = CONVERT_FORMATS

    @staticmethod
    def available() -> bool:
        return shutil.which("lpstat") is not None and shutil.which("lp") is not None

    @staticmethod
    def _run(args: list[str], timeout: int = 30) -> subprocess.CompletedProcess:
        return run_quiet(args, timeout=timeout)

    @classmethod
    def local_printers(cls) -> list[str]:
        """Every queue CUPS knows about, for the desktop app's picker."""
        result = cls._run(["lpstat", "-e"])
        if result.returncode != 0:
            return []
        return [line.strip() for line in result.stdout.splitlines() if line.strip()]

    @classmethod
    def printer_state(cls, queue: str) -> dict[str, Any]:
        """
        Read a queue's real state. This is what makes the customer-facing
        "is the printer online?" answer true rather than assumed.
        """
        started = time.monotonic()
        result = cls._run(["lpstat", "-p", queue, "-l"])
        latency = int((time.monotonic() - started) * 1000)

        if result.returncode != 0:
            return {
                "status": "offline",
                "message": (result.stderr or "The queue is not known to CUPS.").strip()[:400],
                "state_reasons": [],
                "latency_ms": latency,
            }

        output = result.stdout
        reasons: list[str] = []
        status = "unknown"
        message = ""

        first_line = output.splitlines()[0] if output.splitlines() else ""

        if "is idle" in first_line:
            status = "online"
            message = "Ready."
        elif "now printing" in first_line or "is printing" in first_line:
            status = "online"
            message = "Printing."
        elif "disabled" in first_line:
            status = "error"
            message = first_line.strip()[:400]

        # CUPS lists the IPP printer-state-reasons under "Alerts:" and in the
        # status line; both are worth capturing.
        for line in output.splitlines():
            stripped = line.strip()
            if stripped.startswith("Alerts:"):
                for reason in stripped[len("Alerts:"):].split(","):
                    reason = reason.strip()
                    if reason and reason != "none":
                        reasons.append(reason)

        lowered = output.lower()
        for needle, reason in (
            ("out of paper", "media-empty"),
            ("media-empty", "media-empty"),
            ("paper jam", "media-jam"),
            ("media-jam", "media-jam"),
            ("cover open", "cover-open"),
            ("door open", "door-open"),
            ("toner empty", "toner-empty"),
            ("marker-supply-empty", "marker-supply-empty"),
            ("offline", "offline"),
            ("unable to connect", "offline"),
        ):
            if needle in lowered and reason not in reasons:
                reasons.append(reason)

        # A blocking reason means the printer will not produce the document,
        # whatever the state line says.
        if reasons and status == "online":
            blocking = {"media-empty", "media-jam", "cover-open", "door-open",
                        "toner-empty", "marker-supply-empty", "offline"}
            if any(r in blocking for r in reasons):
                status = "error"
                message = "Attention needed: " + ", ".join(reasons)

        if not message:
            message = first_line.strip()[:400]

        return {
            "status": status,
            "message": message,
            "state_reasons": reasons,
            "latency_ms": latency,
        }

    @classmethod
    def capabilities(cls, queue: str) -> dict[str, Any] | None:
        """
        Read what the queue actually supports, from CUPS's own view of the
        printer. These become 'probed' capabilities on the server — the only
        kind a customer is ever offered.
        """
        result = cls._run(["lpoptions", "-p", queue, "-l"], timeout=20)
        if result.returncode != 0:
            return None

        paper_sizes: list[str] = []
        color_modes: list[str] = []
        duplex_modes: list[str] = ["single"]     # every printer can print one side
        defaults: dict[str, str] = {}

        for line in result.stdout.splitlines():
            if "/" not in line or ":" not in line:
                continue

            keyword = line.split("/", 1)[0].strip()
            values_part = line.split(":", 1)[1].strip()
            values = values_part.split()

            # CUPS marks the default with a leading '*'.
            cleaned = [v.lstrip("*") for v in values]
            default_value = next((v.lstrip("*") for v in values if v.startswith("*")), None)

            if keyword in ("PageSize", "media"):
                for value in cleaned:
                    size = cls._normalise_size(value)
                    if size and size not in paper_sizes:
                        paper_sizes.append(size)
                if default_value:
                    size = cls._normalise_size(default_value)
                    if size:
                        defaults["paper_size"] = size

            elif keyword in ("ColorModel", "print-color-mode"):
                for value in cleaned:
                    lowered = value.lower()
                    if lowered in ("rgb", "cmyk", "color", "colour") and "color" not in color_modes:
                        color_modes.append("color")
                    elif lowered in ("gray", "grey", "monochrome", "black", "bi-level") and "bw" not in color_modes:
                        color_modes.append("bw")
                if default_value:
                    lowered = default_value.lower()
                    defaults["color_mode"] = "color" if lowered in ("rgb", "cmyk", "color") else "bw"

            elif keyword in ("Duplex", "sides"):
                for value in cleaned:
                    lowered = value.lower()
                    if lowered.startswith("two-sided") or lowered in ("duplexnotumble", "duplextumble"):
                        if "double" not in duplex_modes:
                            duplex_modes.append("double")

        if not color_modes:
            color_modes = ["bw"]

        make_and_model = ""
        state = cls._run(["lpstat", "-l", "-p", queue])
        if state.returncode == 0:
            match = re.search(r"Description:\s*(.+)", state.stdout)
            if match:
                make_and_model = match.group(1).strip()

        if not paper_sizes:
            return None

        return {
            "paper_sizes": paper_sizes,
            "color_modes": color_modes,
            "duplex_modes": duplex_modes,
            "orientations": ["portrait", "landscape"],
            "defaults": defaults,
            "make_and_model": make_and_model,
        }

    @staticmethod
    def _normalise_size(value: str) -> str | None:
        lowered = value.lower()
        if lowered in PWG_TO_SIZE:
            return PWG_TO_SIZE[lowered]
        aliases = {
            "a4": "A4", "iso_a4": "A4",
            "a5": "A5", "iso_a5": "A5",
            "a3": "A3", "iso_a3": "A3",
            "b5": "B5", "jis_b5": "B5", "isob5": "B5",
            "letter": "Letter", "na_letter": "Letter", "us letter": "Letter",
            "legal": "Legal", "na_legal": "Legal", "uslegal": "Legal",
        }
        return aliases.get(lowered)

    @classmethod
    def submit(cls, queue: str, path: Path, title: str, options: list[str]) -> tuple[bool, str, str | None]:
        """
        Print a file. Returns (accepted, message, cups_job_id).
        """
        args = ["lp", "-d", queue, "-t", title[:255]]
        for option in options:
            args.extend(["-o", option])
        args.append(str(path))

        result = cls._run(args, timeout=180)

        if result.returncode != 0:
            return False, (result.stderr or result.stdout or "lp failed").strip()[:500], None

        # lp prints: request id is <queue>-<id> (1 file(s))
        match = re.search(r"request id is (\S+)", result.stdout)
        job_id = match.group(1) if match else None
        return True, result.stdout.strip()[:500], job_id

    @classmethod
    def job_finished(cls, job_id: str) -> tuple[bool, bool, str]:
        """
        Check whether a CUPS job has left the queue.
        Returns (finished, successful, message).
        """
        result = cls._run(["lpstat", "-W", "not-completed", "-o", job_id])
        if result.returncode == 0 and job_id in result.stdout:
            return False, False, "Still in the queue."

        completed = cls._run(["lpstat", "-W", "completed", "-o", job_id])
        if completed.returncode == 0 and job_id in completed.stdout:
            return True, True, "CUPS reports the job completed."

        # Not in either list: CUPS purges finished jobs after a while, and the
        # honest reading is that it left the queue without an error.
        return True, True, "The job is no longer in the CUPS queue."


class Converter:
    """Converts office documents to PDF so CUPS can render them."""

    @staticmethod
    def libreoffice() -> str | None:
        for binary in ("soffice", "libreoffice"):
            path = shutil.which(binary)
            if path:
                return path
        return None

    @classmethod
    def to_pdf(cls, source: Path, work_dir: Path) -> tuple[Path | None, str]:
        binary = cls.libreoffice()
        if binary is None:
            return None, (
                "LibreOffice is not installed on this agent, so Office documents cannot be "
                "converted for printing. Install libreoffice-core and the matching filters."
            )

        output_dir = work_dir / "converted"
        output_dir.mkdir(parents=True, exist_ok=True)

        # A private profile keeps concurrent conversions from fighting over
        # LibreOffice's shared user directory, and macros stay disabled.
        profile = work_dir / "lo-profile"
        profile.mkdir(parents=True, exist_ok=True)

        try:
            result = run_quiet(
                [
                    binary,
                    "--headless",
                    "--norestore",
                    "--nolockcheck",
                    f"-env:UserInstallation=file://{profile}",
                    "--convert-to", "pdf",
                    "--outdir", str(output_dir),
                    str(source),
                ],
                timeout=300,
            )
        except subprocess.TimeoutExpired:
            return None, "Converting the document to PDF timed out."

        candidate = output_dir / (source.stem + ".pdf")
        if candidate.is_file() and candidate.stat().st_size > 0:
            return candidate, "Converted to PDF."

        return None, (result.stderr or result.stdout or "The conversion produced no output.").strip()[:400]

    @staticmethod
    def pdf_page_count(path: Path) -> int | None:
        """Exact page count after conversion, used to report what was printed."""
        if shutil.which("pdfinfo") is None:
            return None
        result = run_quiet(["pdfinfo", str(path)], timeout=30)
        if result.returncode != 0:
            return None
        match = re.search(r"^Pages:\s*(\d+)", result.stdout, re.MULTILINE)
        return int(match.group(1)) if match else None


# ---------------------------------------------------------------------------
# Windows
# ---------------------------------------------------------------------------

class Windows:
    """
    The Windows print spooler, driven through PowerShell and SumatraPDF.

    Windows has no CUPS and no built-in way to print a PDF from a script
    without opening a window, so the work is split:

      * PowerShell's PrintManagement module reports the queue's real state and
        sets paper size, colour and duplex on the queue.
      * SumatraPDF renders the PDF and submits it silently, carrying copies and
        the page range.

    The queue configuration is a property of the printer, not of one job, so it
    is set immediately before each submission. The agent processes one job at a
    time, which is what makes that safe.
    """

    direct_formats = WINDOWS_DIRECT_FORMATS
    convert_formats = WINDOWS_CONVERT_FORMATS

    #: Win32_Printer DetectedErrorState, mapped to the IPP-style reasons the
    #: server already understands. Anything blocking here stops the printer
    #: being offered to customers.
    ERROR_STATES = {
        3: "media-low",
        4: "media-empty",
        5: "toner-low",
        6: "toner-empty",
        7: "cover-open",
        8: "media-jam",
        9: "offline",
        10: "service-requested",
        11: "output-area-full",
    }

    @staticmethod
    def available() -> bool:
        return os.name == "nt" and Windows._powershell() is not None

    @staticmethod
    def _powershell() -> str | None:
        return shutil.which("powershell") or shutil.which("pwsh")

    @staticmethod
    def sumatra() -> str | None:
        """SumatraPDF, from PATH or the places the installer puts it."""
        found = shutil.which("SumatraPDF") or shutil.which("SumatraPDF.exe")
        if found:
            return found
        for candidate in (
            Path(os.environ.get("ProgramFiles", r"C:\Program Files")) / "SumatraPDF" / "SumatraPDF.exe",
            Path(os.environ.get("ProgramFiles(x86)", r"C:\Program Files (x86)")) / "SumatraPDF" / "SumatraPDF.exe",
            Path(os.environ.get("LOCALAPPDATA", "")) / "SumatraPDF" / "SumatraPDF.exe",
            Path(r"C:\kpms-agent\SumatraPDF.exe"),
        ):
            if candidate.is_file():
                return str(candidate)
        return None

    @classmethod
    def _ps(cls, script: str, timeout: int = 30) -> subprocess.CompletedProcess:
        shell = cls._powershell()
        return run_quiet(
            [shell, "-NoProfile", "-NonInteractive", "-ExecutionPolicy", "Bypass", "-Command", script],
            timeout=timeout,
        )

    @staticmethod
    def _quote(value: str) -> str:
        """Single-quote a value for PowerShell, escaping embedded quotes."""
        return "'" + value.replace("'", "''") + "'"

    @classmethod
    def local_printers(cls) -> list[str]:
        """Every printer installed on this machine, for the desktop app."""
        # One name per line, which is what PowerShell does with a stream of
        # strings anyway - no -join, and so no quoting to get wrong.
        script = (
            "$ErrorActionPreference='SilentlyContinue';"
            "Get-Printer | ForEach-Object { $_.Name }"
        )
        result = cls._ps(script, timeout=60)
        if result.returncode != 0:
            return []
        return [line.strip() for line in result.stdout.splitlines() if line.strip()]

    @classmethod
    def printer_state(cls, queue: str) -> dict[str, Any]:
        started = time.monotonic()
        script = (
            "$ErrorActionPreference='Stop';"
            f"$p = Get-CimInstance Win32_Printer -Filter \"Name='{queue.replace(chr(39), chr(39) * 2)}'\";"
            "if (-not $p) { Write-Output 'NOTFOUND'; exit 0 };"
            "[pscustomobject]@{ status=$p.PrinterStatus; offline=$p.WorkOffline;"
            " error=$p.DetectedErrorState; state=$p.PrinterState } | ConvertTo-Json -Compress"
        )
        result = cls._ps(script)
        latency = int((time.monotonic() - started) * 1000)

        if result.returncode != 0:
            return {
                "status": "unknown",
                "message": (result.stderr or "PowerShell could not read the printer.").strip()[:400],
                "state_reasons": [],
                "latency_ms": latency,
            }

        if "NOTFOUND" in result.stdout:
            return {
                "status": "offline",
                "message": f'Windows has no printer named "{queue}".',
                "state_reasons": ["offline"],
                "latency_ms": latency,
            }

        try:
            data = json.loads(result.stdout.strip())
        except (ValueError, TypeError):
            return {
                "status": "unknown",
                "message": "Could not read the printer state from Windows.",
                "state_reasons": [],
                "latency_ms": latency,
            }

        reasons: list[str] = []
        error_state = data.get("error")
        if isinstance(error_state, int) and error_state in cls.ERROR_STATES:
            reasons.append(cls.ERROR_STATES[error_state])
        if data.get("offline"):
            reasons.append("offline")

        blocked = [r for r in reasons if r not in ("media-low", "toner-low")]
        status = "offline" if blocked else "online"
        message = (
            "Ready." if status == "online"
            else "The printer needs attention: " + ", ".join(blocked)
        )

        return {
            "status": status,
            "message": message,
            "state_reasons": reasons,
            "latency_ms": latency,
        }

    #: System.Printing PageMediaSizeName -> the sizes this application knows.
    MEDIA_NAMES = {
        "isoa3": "A3",
        "isoa4": "A4",
        "isoa5": "A5",
        "jisb5": "B5",
        "northamericaletter": "Letter",
        "northamericalegal": "Legal",
    }

    @classmethod
    def capabilities(cls, queue: str) -> dict[str, Any] | None:
        """
        What the driver says this printer can do.

        Read from System.Printing's PrintCapabilities, which returns the
        driver's actual capability collections. An earlier version searched the
        JSON of Get-PrintConfiguration for the words "color" and "duplex" and
        so matched the *field names* every time - every Windows printer came
        back colour-capable and duplex-capable, including a monochrome
        simplex-only laser. Customers would have been offered, and charged for,
        colour and double-sided work the machine cannot do.

        Reported as a probe, exactly like the CUPS path: the server still keeps
        it separate from a physical test print, because a driver advertising
        duplex is not proof that paper comes out printed on both sides.
        """
        script = (
            "$ErrorActionPreference='Stop';"
            "Add-Type -AssemblyName System.Printing;"
            "$s = New-Object System.Printing.LocalPrintServer;"
            f"$q = $s.GetPrintQueue({cls._quote(queue)});"
            "$c = $q.GetPrintCapabilities();"
            "[pscustomobject]@{"
            " duplex = @($c.DuplexingCapability | ForEach-Object { $_.ToString() });"
            " color  = @($c.OutputColorCapability | ForEach-Object { $_.ToString() });"
            " media  = @($c.PageMediaSizeCapability | ForEach-Object "
            "{ $_.PageMediaSizeName.ToString() })"
            "} | ConvertTo-Json -Compress -Depth 3"
        )
        result = cls._ps(script, timeout=60)
        if result.returncode != 0:
            return None

        try:
            data = json.loads(result.stdout.strip())
        except (ValueError, TypeError):
            return None

        def values(key: str) -> list[str]:
            raw = data.get(key) or []
            if isinstance(raw, str):
                raw = [raw]
            return [str(item).lower() for item in raw]

        sizes: list[str] = []
        for name in values("media"):
            mapped = cls.MEDIA_NAMES.get(name)
            if mapped and mapped not in sizes:
                sizes.append(mapped)

        # OutputColor is an enum: Color, Grayscale, Monochrome. Only the first
        # of those means this printer can put colour on paper.
        colour_capable = "color" in values("color")

        # DuplexingCapability lists the modes the driver will accept. Manual
        # duplex is not automatic duplex and must not be offered as it: a job
        # sent double-sided to a printer that cannot do it prints single-sided
        # and the customer has paid for something they did not get.
        duplex_capable = any(
            mode.startswith("twosided") for mode in values("duplex")
        )

        return {
            "paper_sizes": sizes,
            "color_modes": ["bw"] + (["color"] if colour_capable else []),
            "duplex_modes": ["single"] + (["double"] if duplex_capable else []),
        }

    @classmethod
    def _configure(cls, queue: str, settings: dict[str, str]) -> str:
        """Apply paper size, colour and duplex to the queue. Returns a note."""
        parts = [f"Set-PrintConfiguration -PrinterName {cls._quote(queue)}"]
        if "paper" in settings:
            parts.append(f"-PaperSize {settings['paper']}")
        if "color" in settings:
            parts.append("-Color $" + ("true" if settings["color"] == "color" else "false"))
        if "duplex" in settings:
            parts.append(f"-DuplexingMode {settings['duplex']}")

        if len(parts) == 1:
            return ""

        result = cls._ps(" ".join(parts))
        if result.returncode != 0:
            # Not fatal on its own: the job can still print on the queue's
            # current settings, and saying so beats failing silently.
            return "The printer would not accept these settings: " + (
                (result.stderr or "").strip()[:200] or "unknown error"
            )
        return ""

    @classmethod
    def submit(cls, queue: str, path: Path, title: str, options: list[str]) -> tuple[bool, str, str | None]:
        viewer = cls.sumatra()
        if viewer is None:
            return False, (
                "SumatraPDF is not installed on this agent. Windows cannot print a PDF "
                "from a script without it. Install it and try again."
            ), None

        queue_settings, print_settings, unsupported = translate_options(options)

        note = cls._configure(queue, queue_settings)

        args = [viewer, "-print-to", queue, "-silent"]
        if print_settings:
            args.extend(["-print-settings", ",".join(print_settings)])
        args.append(str(path))

        # What is already in the queue, so a job added by this run can be told
        # apart from one that was there before.
        before = cls._queue_job_ids(queue) or set()

        try:
            result = run_quiet(args, timeout=300)
        except subprocess.TimeoutExpired:
            return False, "The printer did not accept the job within five minutes.", None

        if result.returncode != 0:
            # Log what was actually run: a rejected command line is impossible
            # to diagnose from the error text alone.
            log.error("SumatraPDF exited %s running: %s", result.returncode, " ".join(args))

            detail = (result.stderr or result.stdout or "").strip()[:300]
            hint = ""
            # SumatraPDF is a GUI application. Run from a task that is not in
            # an interactive desktop session it fails with nothing useful to
            # say, which looks exactly like this.
            if not detail or "ParseFlags" in detail:
                hint = (
                    " SumatraPDF gave no reason. The usual cause is that it is not running in "
                    "a desktop session: check that the KrishnaPrinterAgent scheduled task is set "
                    "to run only when the user is logged on."
                )

            return False, (
                f"SumatraPDF exited with code {result.returncode}."
                + (f" {detail}" if detail else "")
                + hint
            ), None

        # A zero exit code is not proof that anything was queued. Wait until the
        # job actually appears in the spooler.
        #
        # Without this the agent reported "completed" for a job that never
        # reached the printer at all: it asked the queue for a job by name,
        # found none, and read that as "already finished". A customer was told
        # their document had printed while the printer had not moved.
        job_id = cls._await_spooled(queue, before)
        if job_id is None:
            return False, (
                "SumatraPDF ran without error but no job appeared in the Windows print "
                "queue, so nothing was printed. Check that the printer is not paused, and "
                "that its Advanced properties are set to spool jobs rather than \"Print "
                "directly to the printer\", which bypasses the queue entirely."
            ), None

        messages = [m for m in (note, unsupported) if m]
        return True, " ".join(messages) or "Sent to the Windows print queue.", f"{queue}::{job_id}"

    @classmethod
    def _queue_job_ids(cls, queue: str) -> set[int] | None:
        """The ids currently in this printer's queue, or None if unreadable."""
        script = (
            "$ErrorActionPreference='SilentlyContinue';"
            f"$j = Get-PrintJob -PrinterName {cls._quote(queue)};"
            "if ($j) { ($j | ForEach-Object { $_.Id }) -join ',' } else { '' }"
        )
        result = cls._ps(script, timeout=30)
        if result.returncode != 0:
            return None
        return {int(part) for part in result.stdout.strip().split(",") if part.strip().isdigit()}

    @classmethod
    def _await_spooled(cls, queue: str, before: set[int], seconds: float = 20.0) -> int | None:
        """
        Wait for a new job to appear in the queue, and return its id.

        Matched by id, not by document name. Name matching looked obvious and
        was wrong: SumatraPDF names the spooled job after the PDF's internal
        title, not the file it was handed, so the agent searched the queue for
        "document.pdf" while it actually held "Krishna Printer test page" -
        found nothing, and reported that the job had never been queued.
        """
        deadline = time.monotonic() + seconds
        while time.monotonic() < deadline:
            current = cls._queue_job_ids(queue)
            if current is not None:
                fresh = current - before
                if fresh:
                    return min(fresh)
            time.sleep(0.4)
        return None

    @classmethod
    def job_finished(cls, job_id: str) -> tuple[bool, bool, str]:
        """
        Has the job left the queue, and did it leave cleanly?

        job_id is "<queue>::<windows job id>", issued by submit() only after it
        saw the job in the spooler. That ordering matters: because the job is
        known to have existed, its absence now genuinely means it finished,
        rather than meaning it never arrived.
        """
        queue, _, raw_id = job_id.partition("::")
        if not raw_id.isdigit():
            # An id from an older agent build, or a malformed one. Say so
            # instead of reporting a completion nobody verified.
            return True, False, "This job cannot be tracked; its queue id is unknown."

        script = (
            "$ErrorActionPreference='SilentlyContinue';"
            f"$j = Get-PrintJob -PrinterName {cls._quote(queue)} -ID {int(raw_id)};"
            "if ($j) { $j.JobStatus } else { 'GONE' }"
        )
        result = cls._ps(script)

        if result.returncode != 0:
            return False, False, "Could not read the print queue."

        output = result.stdout.strip()
        if output in ("", "GONE"):
            return True, True, "The job has left the Windows print queue."

        lowered = output.lower()

        # "Printing", "Spooling" and "Retained" are all progress. These are not.
        for bad, explanation in (
            ("error", "the printer reported an error"),
            ("deleted", "the job was deleted"),
            ("blocked", "the job is blocked"),
            ("offline", "the printer is offline"),
            ("paperout", "the printer is out of paper"),
            ("userintervention", "the printer needs attention"),
        ):
            if bad in lowered:
                return True, False, f"Windows reports {explanation} ({output})."

        return False, False, f"Still in the queue ({output})."


def translate_options(options: list[str]) -> tuple[dict[str, str], list[str], str]:
    """
    Turn the server's CUPS options into Windows queue settings and SumatraPDF
    print settings.

    Paper size, colour and duplex go on the queue through
    Set-PrintConfiguration, which is a documented Windows API. Only copies and
    the page range are passed to SumatraPDF.

    That split is deliberate. Sending paper, colour and duplex to SumatraPDF as
    well got the whole command line rejected - "ParseFlags: argName:
    '-print-settings'" - and nothing printed. Setting the same thing twice
    through two mechanisms gains nothing and doubles the ways it can fail, so
    each setting now travels by exactly one route, and the one that is a
    documented API is preferred.

    Returns (queue_settings, print_settings, note). The note names anything
    that could not be carried, so the agent reports it rather than quietly
    printing something the customer did not ask for.
    """
    queue_settings: dict[str, str] = {}
    print_settings: list[str] = []
    dropped: list[str] = []

    for option in options:
        key, _, value = option.partition("=")
        key = key.strip()
        value = value.strip()

        if key == "copies":
            try:
                count = max(1, int(value))
            except ValueError:
                count = 1
            if count > 1:
                print_settings.append(f"{count}x")

        elif key == "media":
            size = PWG_TO_SIZE.get(value)
            if size:
                queue_settings["paper"] = size
            else:
                dropped.append(f"paper size {value}")

        elif key == "sides":
            if value == "two-sided-long-edge":
                queue_settings["duplex"] = "TwoSidedLongEdge"
            elif value == "two-sided-short-edge":
                queue_settings["duplex"] = "TwoSidedShortEdge"
            else:
                queue_settings["duplex"] = "OneSided"

        elif key == "print-color-mode":
            queue_settings["color"] = "color" if value == "color" else "monochrome"

        elif key == "page-ranges":
            print_settings.append(value)

        elif key in ("ColorModel", "orientation-requested"):
            # ColorModel duplicates print-color-mode, which is already handled.
            # Orientation is a PrintTicket property that Set-PrintConfiguration
            # does not expose, so it is reported rather than assumed.
            if key == "orientation-requested" and value == "4":
                dropped.append("landscape orientation")

        else:
            dropped.append(option)

    note = ""
    if dropped:
        note = "This agent could not apply: " + ", ".join(dropped) + "."

    return queue_settings, print_settings, note


def select_spooler() -> type | None:
    """
    Pick the printing backend for this machine.

    Linux and macOS have CUPS; Windows does not, and needs an entirely
    different set of tools. Which one is in use is decided once, at startup,
    and logged — an agent that silently picked the wrong backend would report
    printers as offline for reasons nobody could follow.
    """
    if Cups.available():
        return Cups
    if Windows.available():
        return Windows
    return None


class Agent:
    def __init__(self, config: Config):
        self.config = config
        self.api = Api(config)
        self.running = True
        self.printers: list[dict[str, Any]] = []
        self.last_heartbeat = 0.0
        self.capabilities_reported: set[str] = set()
        self.spooler: type = Cups

        config.work_dir.mkdir(parents=True, exist_ok=True)

    def stop(self, *_: Any) -> None:
        log.info("Shutting down after the current job…")
        self.running = False

    def run(self) -> int:
        spooler = select_spooler()
        if spooler is None:
            if os.name == "nt":
                log.error(
                    "PowerShell was not found, so the Windows print spooler cannot be reached."
                )
            else:
                log.error("CUPS tools not found. Install with: sudo apt install cups cups-client")
            return 2
        self.spooler = spooler

        log.info("Krishna Printer agent %s starting", AGENT_VERSION)
        log.info("Printing backend: %s", spooler.__name__)
        log.info("Server: %s", self.config.server)

        if not self.config.verify_tls:
            log.warning(
                "TLS certificate verification is DISABLED. Only acceptable on a private network "
                "with a self-signed certificate you control."
            )

        try:
            configuration = self.api.config_call()
        except ApiError as error:
            log.error("Could not fetch the agent configuration: %s", error)
            return 3

        device = configuration.get("device", {})
        self.printers = configuration.get("printers", [])
        self.config.poll_interval = int(device.get("poll_interval", self.config.poll_interval))

        log.info(
            "Registered as '%s' with %d printer(s); polling every %ds",
            device.get("name", "unknown"),
            len(self.printers),
            self.config.poll_interval,
        )

        if not self.printers:
            log.warning("No printers are assigned to this agent yet. Add one in the admin panel.")

        # Only the main thread may install signal handlers. The desktop app
        # runs this in a worker, where attempting to raised ValueError and
        # killed the agent before its first heartbeat - the admin panel showed
        # the agent as "pending" for ever and nobody could see why. Started
        # from a service or the command line it is the main thread, and the
        # handlers are installed as before.
        if threading.current_thread() is threading.main_thread():
            signal.signal(signal.SIGTERM, self.stop)
            signal.signal(signal.SIGINT, self.stop)
        else:
            log.info("Running in a worker thread; stop it from the window rather than a signal.")

        backoff = 1

        while self.running:
            try:
                self.tick()
                backoff = 1
            except ApiError as error:
                if not error.retryable:
                    log.error("Fatal API error: %s", error)
                    if error.status in (401, 403):
                        log.error("The token was rejected. Issue a new one in the admin panel.")
                        return 4
                # Exponential backoff: a server outage should not become a
                # request flood from fifty agents at once.
                log.warning("API error (%s); retrying in %ds", error, backoff)
                time.sleep(backoff)
                backoff = min(backoff * 2, 300)
                continue
            except Exception:  # noqa: BLE001 - the loop must survive anything
                log.exception("Unexpected error in the main loop")
                time.sleep(min(backoff * 2, 60))
                backoff = min(backoff * 2, 300)
                continue

            time.sleep(self.config.poll_interval)

        log.info("Agent stopped.")
        return 0

    def tick(self) -> None:
        now = time.monotonic()

        if now - self.last_heartbeat >= self.config.heartbeat_interval:
            self.send_heartbeat()
            self.last_heartbeat = now

        response = self.api.claim()
        job = response.get("job")
        if job:
            self.process(job)

    def refresh_printers(self) -> None:
        """
        Re-read which printers this agent is responsible for.

        The list used to be fetched once at startup, so a printer added in the
        admin panel was invisible to an agent already running: the operator saw
        "the print agent is connected but has not yet reported this printer's
        state" forever, and nothing but a restart fixed it. Adding a printer is
        the normal way to set one up, so the agent has to notice.
        """
        try:
            configuration = self.api.config_call()
        except ApiError as error:
            # Keep the current list and try again next time: a momentary
            # failure here must not take working printers out of service.
            log.warning("Could not refresh the printer list: %s", error)
            return

        previous = {printer["code"] for printer in self.printers}
        self.printers = configuration.get("printers", [])
        current = {printer["code"] for printer in self.printers}

        for code in sorted(current - previous):
            log.info("Printer %s is now assigned to this agent", code)
        for code in sorted(previous - current):
            log.info("Printer %s is no longer assigned to this agent", code)
            # Its capabilities are re-reported if it comes back, since the
            # queue behind it may have changed in the meantime.
            self.capabilities_reported.discard(code)

    def send_heartbeat(self) -> None:
        self.refresh_printers()

        reports = []

        for printer in self.printers:
            if not printer.get("enabled", True):
                continue

            queue = printer.get("queue_name") or printer.get("code")
            state = self.spooler.printer_state(queue)
            state["code"] = printer["code"]
            reports.append(state)

            # Report capabilities once per run, and only for a printer CUPS can
            # actually see — that is what turns a declared option into a
            # verified one the customer may be offered.
            if state["status"] in ("online", "error") and printer["code"] not in self.capabilities_reported:
                capabilities = self.spooler.capabilities(queue)
                if capabilities:
                    try:
                        self.api.report_capabilities(printer["code"], capabilities)
                        self.capabilities_reported.add(printer["code"])
                        log.info(
                            "Reported capabilities for %s: %s, colour=%s, duplex=%s",
                            printer["code"],
                            ",".join(capabilities["paper_sizes"]),
                            "color" in capabilities["color_modes"],
                            "double" in capabilities["duplex_modes"],
                        )
                    except ApiError as error:
                        log.warning("Could not report capabilities for %s: %s", printer["code"], error)

        result = self.api.heartbeat(reports)

        if result.get("unknown_printers"):
            log.warning(
                "The server does not recognise these printer codes: %s",
                ", ".join(result["unknown_printers"]),
            )

    def process(self, job: dict[str, Any]) -> None:
        job_number = job["job_number"]
        lease = job["lease_token"]
        queue = job.get("queue_name") or job["printer_code"]
        document = job["document"]
        options = job.get("cups_options", [])

        log.info("Claimed job %s for queue %s", job_number, queue)

        with tempfile.TemporaryDirectory(dir=self.config.work_dir) as temp:
            work = Path(temp)
            extension = ("." + document["extension"].lower()) if document.get("extension") else ""
            source = work / f"document{extension}"

            try:
                self.api.download(job_number, lease, source)
            except ApiError as error:
                log.error("Download failed for %s: %s", job_number, error)
                self.safe_report(job_number, lease, "failed",
                                 message=f"Could not download the document: {error}",
                                 error_code="download_failed",
                                 retryable=error.retryable)
                return

            printable = source
            note = ""

            if extension in self.spooler.convert_formats:
                converted, message = Converter.to_pdf(source, work)
                if converted is None:
                    log.error("Conversion failed for %s: %s", job_number, message)
                    # A missing converter is a configuration problem on this
                    # machine, not something a retry fixes.
                    self.safe_report(job_number, lease, "failed",
                                     message=f"Could not convert the document: {message}",
                                     error_code="conversion_failed",
                                     retryable=Converter.libreoffice() is not None)
                    return
                printable = converted
                note = message

                pages = Converter.pdf_page_count(converted)
                if pages:
                    note += f" ({pages} pages after conversion)"

            elif extension not in self.spooler.direct_formats:
                self.safe_report(job_number, lease, "failed",
                                 message=f"This agent cannot print {extension} files.",
                                 error_code="unsupported_format",
                                 retryable=False)
                return

            accepted, message, cups_job = self.spooler.submit(
                queue, printable, f"{job_number} {document['name']}", options
            )

            if not accepted:
                log.error("CUPS rejected job %s: %s", job_number, message)
                self.safe_report(job_number, lease, "failed",
                                 message=f"The printer queue rejected the job: {message}",
                                 error_code="cups_rejected",
                                 retryable=True)
                return

            log.info("Submitted %s to CUPS as %s", job_number, cups_job or "(no id)")
            self.safe_report(job_number, lease, "printing",
                             message=(note + " " if note else "") + "Submitted to the printer queue.",
                             remote_job_id=cups_job)

            # Wait for the job to leave the queue so the customer sees a real
            # completion rather than an assumed one.
            if cups_job:
                self.await_completion(job_number, lease, cups_job)
            else:
                self.safe_report(job_number, lease, "completed",
                                 message="Submitted; CUPS returned no job id to track.")

    def await_completion(self, job_number: str, lease: str, cups_job: str) -> None:
        deadline = time.monotonic() + 600  # ten minutes is generous for a large job

        while time.monotonic() < deadline and self.running:
            time.sleep(3)
            finished, successful, message = self.spooler.job_finished(cups_job)

            if finished:
                if successful:
                    log.info("Job %s completed", job_number)
                    self.safe_report(job_number, lease, "completed", message=message)
                else:
                    log.warning("Job %s failed at the printer: %s", job_number, message)
                    self.safe_report(job_number, lease, "failed",
                                     message=message, error_code="printer_aborted", retryable=True)
                return

        log.warning("Job %s did not finish within the wait window", job_number)
        self.safe_report(
            job_number, lease, "failed",
            message="The job was accepted by the printer queue but did not finish within ten minutes.",
            error_code="print_timeout",
            retryable=True,
        )

    def safe_report(self, job_number: str, lease: str, status: str, **fields: Any) -> None:
        """A failed status report must not lose the job — the server's lease expiry requeues it."""
        for attempt in range(3):
            try:
                self.api.report(job_number, lease, status, **fields)
                return
            except ApiError as error:
                log.warning("Could not report %s for %s (attempt %d): %s",
                            status, job_number, attempt + 1, error)
                time.sleep(2 ** attempt)

        log.error(
            "Gave up reporting '%s' for %s. The server will requeue it when the lease expires.",
            status, job_number,
        )


def main() -> int:
    parser = argparse.ArgumentParser(description="Krishna Printer location agent")
    parser.add_argument("--server", help="Cloud application URL, e.g. https://print.example.com")
    parser.add_argument("--token", help="Agent API token")
    parser.add_argument("--config", default="/etc/kpms-agent/config.json", help="Config file path")
    parser.add_argument("--poll-interval", type=int, help="Seconds between polls")
    parser.add_argument("--work-dir", help="Scratch directory")
    parser.add_argument("--no-verify-tls", action="store_true",
                        help="Disable TLS verification (private CA only)")
    parser.add_argument("--verbose", action="store_true")
    parser.add_argument("--test", action="store_true",
                        help="Check the connection and print the printer states, then exit")
    args = parser.parse_args()

    logging.basicConfig(
        level=logging.DEBUG if args.verbose else logging.INFO,
        format="%(asctime)s %(levelname)-7s %(message)s",
        datefmt="%Y-%m-%d %H:%M:%S",
    )

    settings: dict[str, Any] = {}
    config_path = Path(args.config)
    if config_path.is_file():
        try:
            # utf-8-sig, not utf-8: Windows PowerShell and Notepad both write a
            # byte-order mark, and on a machine whose default encoding is a
            # code page that mark arrives as three stray characters that make
            # the file "invalid JSON at column 1". Reading it this way strips a
            # BOM when there is one and changes nothing when there is not.
            settings = json.loads(config_path.read_text(encoding="utf-8-sig"))
        except json.JSONDecodeError as error:
            log.error("Config file %s is not valid JSON: %s", config_path, error)
            return 1

    server = args.server or settings.get("server") or os.environ.get("KPMS_SERVER")
    token = args.token or settings.get("token") or os.environ.get("KPMS_TOKEN")

    if not server or not token:
        log.error(
            "A server URL and token are required. Pass --server and --token, or write them to %s",
            config_path,
        )
        return 1

    if not server.startswith("https://") and "localhost" not in server and "127.0.0.1" not in server:
        log.warning(
            "The server URL is not HTTPS. The token and every customer document would travel in "
            "clear text. Use HTTPS for anything beyond a local test."
        )

    config = Config(
        server=server,
        token=token,
        poll_interval=args.poll_interval or int(settings.get("poll_interval", 10)),
        work_dir=Path(args.work_dir or settings.get("work_dir", "/var/lib/kpms-agent")),
        verify_tls=not (args.no_verify_tls or settings.get("verify_tls") is False),
    )

    # Log to a file as well as the console.
    #
    # Under systemd stdout goes to the journal; under a Windows scheduled task
    # it goes nowhere at all, so an agent that misbehaves leaves no trace and
    # there is nothing to look at but the job queue. The file lives beside the
    # work directory, which the installer already creates and secures.
    try:
        config.work_dir.mkdir(parents=True, exist_ok=True)
        log_path = config.work_dir / "agent.log"

        # Keep one previous file rather than growing without bound. A shop
        # machine runs this for months.
        if log_path.is_file() and log_path.stat().st_size > 5 * 1024 * 1024:
            log_path.replace(log_path.with_suffix(".log.1"))

        handler = logging.FileHandler(log_path, encoding="utf-8")
        handler.setFormatter(
            logging.Formatter("%(asctime)s %(levelname)-7s %(message)s", "%Y-%m-%d %H:%M:%S")
        )
        logging.getLogger().addHandler(handler)
        log.info("Logging to %s", log_path)
    except OSError as error:
        log.warning("Could not open a log file: %s", error)

    agent = Agent(config)

    if args.test:
        spooler = select_spooler()
        if spooler is None:
            log.error(
                "No printing backend is available: neither the CUPS tools nor PowerShell "
                "could be found."
            )
            return 2
        agent.spooler = spooler
        print(f"Printing backend: {spooler.__name__}")

        try:
            configuration = agent.api.config_call()
        except ApiError as error:
            log.error("Connection test failed: %s", error)
            return 1

        device = configuration.get("device", {})
        print(f"Connected as: {device.get('name')} ({device.get('status')})")
        print(f"Printers assigned: {len(configuration.get('printers', []))}")

        for printer in configuration.get("printers", []):
            queue = printer.get("queue_name") or printer["code"]
            state = spooler.printer_state(queue)
            print(f"  {printer['code']:<24} queue={queue:<24} {state['status']:<8} {state['message']}")

            capabilities = spooler.capabilities(queue)
            if capabilities:
                print(f"    paper: {', '.join(capabilities['paper_sizes'])}")
                print(f"    colour: {', '.join(capabilities['color_modes'])}")
                print(f"    sides: {', '.join(capabilities['duplex_modes'])}")
            else:
                print("    capabilities: could not be read from the printing backend")

        print(f"LibreOffice: {Converter.libreoffice() or 'NOT INSTALLED — Office files cannot be printed'}")
        if spooler is Windows:
            print(f"SumatraPDF:  {Windows.sumatra() or 'NOT INSTALLED — nothing can be printed'}")
        return 0

    return agent.run()


if __name__ == "__main__":
    sys.exit(main())
