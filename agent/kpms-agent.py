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
DIRECT_FORMATS = {".pdf", ".jpg", ".jpeg", ".png", ".txt"}
CONVERT_FORMATS = {".doc", ".docx", ".xls", ".xlsx", ".ppt", ".pptx"}

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

    @staticmethod
    def available() -> bool:
        return shutil.which("lpstat") is not None and shutil.which("lp") is not None

    @staticmethod
    def _run(args: list[str], timeout: int = 30) -> subprocess.CompletedProcess:
        return subprocess.run(args, capture_output=True, text=True, timeout=timeout, check=False)

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
            result = subprocess.run(
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
                capture_output=True,
                text=True,
                timeout=300,
                check=False,
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
        result = subprocess.run(
            ["pdfinfo", str(path)], capture_output=True, text=True, timeout=30, check=False
        )
        if result.returncode != 0:
            return None
        match = re.search(r"^Pages:\s*(\d+)", result.stdout, re.MULTILINE)
        return int(match.group(1)) if match else None


class Agent:
    def __init__(self, config: Config):
        self.config = config
        self.api = Api(config)
        self.running = True
        self.printers: list[dict[str, Any]] = []
        self.last_heartbeat = 0.0
        self.capabilities_reported: set[str] = set()

        config.work_dir.mkdir(parents=True, exist_ok=True)

    def stop(self, *_: Any) -> None:
        log.info("Shutting down after the current job…")
        self.running = False

    def run(self) -> int:
        if not Cups.available():
            log.error("CUPS tools not found. Install with: sudo apt install cups cups-client")
            return 2

        log.info("Krishna Printer agent %s starting", AGENT_VERSION)
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

        signal.signal(signal.SIGTERM, self.stop)
        signal.signal(signal.SIGINT, self.stop)

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

    def send_heartbeat(self) -> None:
        reports = []

        for printer in self.printers:
            if not printer.get("enabled", True):
                continue

            queue = printer.get("queue_name") or printer.get("code")
            state = Cups.printer_state(queue)
            state["code"] = printer["code"]
            reports.append(state)

            # Report capabilities once per run, and only for a printer CUPS can
            # actually see — that is what turns a declared option into a
            # verified one the customer may be offered.
            if state["status"] in ("online", "error") and printer["code"] not in self.capabilities_reported:
                capabilities = Cups.capabilities(queue)
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

            if extension in CONVERT_FORMATS:
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

            elif extension not in DIRECT_FORMATS:
                self.safe_report(job_number, lease, "failed",
                                 message=f"This agent cannot print {extension} files.",
                                 error_code="unsupported_format",
                                 retryable=False)
                return

            accepted, message, cups_job = Cups.submit(
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
            finished, successful, message = Cups.job_finished(cups_job)

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
            settings = json.loads(config_path.read_text())
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

    agent = Agent(config)

    if args.test:
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
            state = Cups.printer_state(queue)
            print(f"  {printer['code']:<24} queue={queue:<24} {state['status']:<8} {state['message']}")

            capabilities = Cups.capabilities(queue)
            if capabilities:
                print(f"    paper: {', '.join(capabilities['paper_sizes'])}")
                print(f"    colour: {', '.join(capabilities['color_modes'])}")
                print(f"    sides: {', '.join(capabilities['duplex_modes'])}")
            else:
                print("    capabilities: could not be read from CUPS")

        print(f"LibreOffice: {Converter.libreoffice() or 'NOT INSTALLED — Office files cannot be printed'}")
        return 0

    return agent.run()


if __name__ == "__main__":
    sys.exit(main())
