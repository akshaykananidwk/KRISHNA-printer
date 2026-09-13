#!/usr/bin/env python3
"""
Checks for the location print agent.

Most of this covers the Windows printing backend, which cannot be exercised
from Linux — there is no Windows print spooler here to drive. What can be
checked, and is checked here, is everything that decides whether it behaves
correctly once it is on Windows:

  * the CUPS options the server sends are translated into Windows queue
    settings and SumatraPDF arguments without losing any of them,
  * anything that cannot be carried is reported rather than silently dropped,
  * the printer states Windows reports map to the reasons the server treats as
    blocking, so a printer out of paper stops taking orders,
  * a printer name containing a quote cannot break out of the PowerShell
    string it is interpolated into,
  * and the embedded PowerShell actually parses (when pwsh is available).

Plus the parts of the agent that are not platform-specific at all, such as
noticing a printer that was assigned to it after it started.

Exits 0 when every check passes, 1 otherwise.
"""

from __future__ import annotations

import hashlib
import re
import importlib.util
import json
import shutil
import subprocess
import sys
import tempfile
from pathlib import Path

AGENT = Path(__file__).resolve().parent.parent / "agent" / "kpms-agent.py"

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


def load_agent():
    spec = importlib.util.spec_from_file_location("kpms_agent", AGENT)
    module = importlib.util.module_from_spec(spec)
    sys.modules["kpms_agent"] = module      # dataclass resolves types via sys.modules
    spec.loader.exec_module(module)
    return module


class FakeResult:
    def __init__(self, stdout: str = "", returncode: int = 0) -> None:
        self.stdout = stdout
        self.returncode = returncode
        self.stderr = ""


def main() -> int:
    agent = load_agent()

    # Several checks below replace class methods with stubs. Keep the originals
    # so a later section is not silently testing an earlier section's stub -
    # which is exactly what happened the first time this file grew.
    originals = {
        name: agent.Windows.__dict__[name]
        for name in ("_ps", "_queue_job_ids", "_await_spooled", "sumatra")
    }

    # --- Option translation ------------------------------------------------
    print("Option translation")

    # Exactly what PrintJobSpec::toCupsOptions() emits for a real order:
    # 3 copies, A4, black and white, double sided, pages 1-5, portrait.
    queue, settings, note = agent.translate_options([
        "copies=3", "media=iso_a4_210x297mm", "sides=two-sided-long-edge",
        "print-color-mode=monochrome", "orientation-requested=3",
        "ColorModel=Gray", "page-ranges=1-5",
    ])

    check("copies reach the printer", "3x" in settings, f"got {settings}")
    check("paper size is set on the queue", queue.get("paper") == "A4", f"got {queue}")
    check("duplex is set on the queue",
          queue.get("duplex") == "TwoSidedLongEdge", f"got {queue}")
    check("colour mode is set on the queue",
          queue.get("color") == "monochrome", f"got {queue}")
    check("the page range is passed through", "1-5" in settings, f"got {settings}")
    check("nothing is reported as dropped", note == "", f"got {note!r}")

    # Each setting travels by exactly one route. Passing paper, colour and
    # duplex to SumatraPDF as well got the whole command line rejected
    # ("ParseFlags: argName: '-print-settings'") and nothing printed.
    check("queue settings are not also sent to SumatraPDF",
          settings == ["3x", "1-5"], f"got {settings}")

    # The commonest job of all - one copy, every page - must produce no
    # -print-settings argument at all.
    _, plain, _ = agent.translate_options([
        "copies=1", "media=iso_a4_210x297mm", "sides=one-sided",
        "print-color-mode=monochrome", "orientation-requested=3", "ColorModel=Gray",
    ])
    check("a plain single-copy job passes no print settings",
          plain == [], f"got {plain}")

    _, single, _ = agent.translate_options(["copies=1"])
    check("a single copy needs no multiplier", "1x" not in single, f"got {single}")

    _, _, landscape_note = agent.translate_options(["orientation-requested=4"])
    check("landscape is reported as not applied",
          "landscape" in landscape_note, f"got {landscape_note!r}")

    _, _, paper_note = agent.translate_options(["media=iso_a0_841x1189mm"])
    check("an unknown paper size is reported, not guessed",
          "paper size" in paper_note, f"got {paper_note!r}")

    colour_queue, colour_settings, _ = agent.translate_options([
        "print-color-mode=color", "sides=two-sided-short-edge",
    ])
    check("colour and short-edge duplex translate",
          colour_queue.get("color") == "color"
          and colour_queue.get("duplex") == "TwoSidedShortEdge"
          and colour_settings == [],
          f"got {colour_queue} {colour_settings}")

    # --- Printer state -----------------------------------------------------
    print("Printer state reported to the server")

    def with_output(payload: str, returncode: int = 0) -> None:
        agent.Windows._ps = classmethod(
            lambda cls, script, timeout=30: FakeResult(payload, returncode)
        )

    # Win32_Printer DetectedErrorState values, and what each must mean.
    for label, state, expected_status, expected_reasons in [
        ("ready", {"status": 3, "offline": False, "error": 2}, "online", []),
        ("out of paper", {"status": 3, "offline": False, "error": 4}, "offline", ["media-empty"]),
        ("jammed", {"status": 3, "offline": False, "error": 8}, "offline", ["media-jam"]),
        ("cover open", {"status": 3, "offline": False, "error": 7}, "offline", ["cover-open"]),
        ("toner empty", {"status": 3, "offline": False, "error": 6}, "offline", ["toner-empty"]),
        ("unplugged", {"status": 7, "offline": True, "error": 2}, "offline", ["offline"]),
        # Low toner still prints. Treating it as blocking would close a shop
        # that is working perfectly well.
        ("toner low", {"status": 3, "offline": False, "error": 5}, "online", ["toner-low"]),
    ]:
        with_output(json.dumps(state))
        result = agent.Windows.printer_state("P")
        check(
            f"{label} -> {expected_status}",
            result["status"] == expected_status
            and result["state_reasons"] == expected_reasons,
            f"got {result['status']} {result['state_reasons']}",
        )

    with_output("NOTFOUND")
    missing = agent.Windows.printer_state("Missing")
    check("a queue Windows does not have reads as offline",
          missing["status"] == "offline" and "no printer named" in missing["message"],
          f"got {missing}")

    with_output("", 1)
    broken = agent.Windows.printer_state("P")
    check("an unreadable queue is unknown, never assumed online",
          broken["status"] == "unknown", f"got {broken}")

    # --- Capability probe --------------------------------------------------
    print("Capability probe")

    def with_caps(payload, returncode: int = 0) -> None:
        body = json.dumps(payload) if isinstance(payload, dict) else payload
        agent.Windows._ps = classmethod(
            lambda cls, script, timeout=30: FakeResult(body, returncode)
        )

    # An HP LaserJet M1005: monochrome, one-sided only, no A3.
    #
    # The first version of this probe searched the JSON of
    # Get-PrintConfiguration for "color" and "duplex" and matched the field
    # *names*, so every Windows printer came back colour and duplex capable.
    # On this printer that would have offered - and charged for - colour and
    # double-sided work it cannot do.
    with_caps({"duplex": ["OneSided"], "color": ["Grayscale", "Monochrome"],
               "media": ["ISOA4", "ISOA5", "JISB5", "NorthAmericaLetter",
                         "NorthAmericaLegal"]})
    mono = agent.Windows.capabilities("HP LaserJet M1005")
    check("a mono printer is not reported as colour",
          mono["color_modes"] == ["bw"], f"got {mono}")
    check("a simplex printer is not reported as duplex",
          mono["duplex_modes"] == ["single"], f"got {mono}")
    check("a printer that cannot take A3 is not offered it",
          "A3" not in mono["paper_sizes"] and "A4" in mono["paper_sizes"],
          f"got {mono}")

    # A printer that genuinely does colour and duplex must still be recognised.
    with_caps({"duplex": ["OneSided", "TwoSidedLongEdge"],
               "color": ["Color", "Grayscale"], "media": ["ISOA4", "ISOA3"]})
    rich = agent.Windows.capabilities("Colour MFP")
    check("a colour duplex printer is recognised",
          rich["color_modes"] == ["bw", "color"]
          and rich["duplex_modes"] == ["single", "double"]
          and "A3" in rich["paper_sizes"],
          f"got {rich}")

    # PowerShell collapses a single-element array to a bare string.
    with_caps({"duplex": "OneSided", "color": "Grayscale", "media": "ISOA4"})
    scalar = agent.Windows.capabilities("Simple")
    check("a single-value reply is read as one value",
          scalar["color_modes"] == ["bw"] and scalar["paper_sizes"] == ["A4"],
          f"got {scalar}")

    with_caps("", 1)
    check("an unreadable driver reports nothing rather than guessing",
          agent.Windows.capabilities("X") is None)
    with_caps("not json")
    check("an unparseable reply reports nothing rather than guessing",
          agent.Windows.capabilities("X") is None)

    # --- Configuration file ------------------------------------------------
    print("Configuration file")

    # Windows PowerShell 5.1 writes UTF-8 *with* a byte-order mark, and on a
    # machine whose default encoding is a code page those three bytes arrive as
    # stray characters that make the file "invalid JSON at column 1". The
    # installer no longer writes a BOM, and the agent no longer cares if
    # something else does - a config edited in Notepad must still load.
    import tempfile as _tempfile

    sample = json.dumps({
        "server": "https://nowhere.invalid", "token": "kpms_test",
        "poll_interval": 10, "work_dir": _tempfile.mkdtemp(), "verify_tls": True,
    })

    for label, payload in (
        ("with a BOM", b"\xef\xbb\xbf" + sample.encode("utf-8")),
        ("without a BOM", sample.encode("utf-8")),
    ):
        path = Path(_tempfile.mkdtemp()) / "config.json"
        path.write_bytes(payload)
        probe = subprocess.run(
            [sys.executable, str(AGENT), "--config", str(path), "--test"],
            capture_output=True, text=True, timeout=120, check=False,
        )
        output = probe.stdout + probe.stderr
        check(f"a config {label} is read",
              "is not valid JSON" not in output,
              output.strip()[:160])

    # The installer must not write one in the first place.
    installer_text = (AGENT.parent / "install.ps1").read_bytes().decode("ascii", "replace")
    check("the installer writes the config without a BOM",
          "UTF8Encoding $false" in installer_text
          and "Set-Content -Path $configPath -Encoding UTF8" not in installer_text,
          "install.ps1 still uses Set-Content -Encoding UTF8")

    # --- Submission and job tracking ---------------------------------------
    print("Submission and job tracking")

    check("Windows converts images instead of sending them to SumatraPDF",
          ".png" in agent.Windows.convert_formats
          and ".png" not in agent.Windows.direct_formats
          and ".pdf" in agent.Windows.direct_formats,
          f"direct={sorted(agent.Windows.direct_formats)}")
    check("CUPS still prints images directly",
          ".png" in agent.Cups.direct_formats,
          f"direct={sorted(agent.Cups.direct_formats)}")

    import subprocess as _sp
    real_run = _sp.run
    agent.Windows.sumatra = staticmethod(lambda: "sumatra.exe")

    def submit_with(spooled):
        agent.Windows._queue_job_ids = classmethod(lambda cls, queue: set())
        agent.Windows._await_spooled = classmethod(
            lambda cls, queue, before, seconds=0.5: spooled
        )
        _sp.run = lambda *a, **k: FakeResult("", 0)
        try:
            return agent.Windows.submit(
                "HP LaserJet M1005", Path("/tmp/x.pdf"), "AK100001 doc", ["copies=1"]
            )
        finally:
            _sp.run = real_run

    # The job that started all this: SumatraPDF exited 0, nothing was queued,
    # and the agent told the customer their document had printed.
    accepted, message, job_id = submit_with(None)
    check("a job that never reaches the queue is a failure, not a completion",
          accepted is False and job_id is None, f"got {accepted}, {job_id}")
    check("and the failure says what to check",
          "no job appeared in the Windows print queue" in message, message[:140])

    accepted, _, job_id = submit_with(7)
    check("a job seen in the queue returns its real Windows id",
          accepted is True and job_id == "HP LaserJet M1005::7", f"got {job_id}")

    def status_is(text):
        agent.Windows._ps = classmethod(
            lambda cls, script, timeout=30: FakeResult(text, 0)
        )
        return agent.Windows.job_finished("HP LaserJet M1005::7")

    for text, expected_finished, expected_ok, label in (
        ("Printing", False, False, "a printing job is not finished"),
        ("Spooling", False, False, "a spooling job is not finished"),
        ("GONE", True, True, "a job that has left the queue completed"),
        ("Error", True, False, "a job in error is a failure"),
        ("PaperOut", True, False, "a job stuck on paper is a failure"),
    ):
        finished, successful, _ = status_is(text)
        check(label, (finished, successful) == (expected_finished, expected_ok),
              f"got finished={finished} ok={successful}")

    # An id from the older build cannot be tracked. That must not read as
    # success - the whole point is that completion is observed, not assumed.
    finished, successful, _ = agent.Windows.job_finished("document.png")
    check("an untrackable job id is never reported as printed",
          finished is True and successful is False,
          f"got finished={finished} ok={successful}")

    # --- Spool detection ----------------------------------------------------
    print("Spool detection")

    for name, original in originals.items():
        setattr(agent.Windows, name, original)

    def queue_returns(text, returncode=0):
        agent.Windows._ps = classmethod(
            lambda cls, script, timeout=30: FakeResult(text, returncode)
        )

    queue_returns("12,13,14")
    check("the queue's job ids are read",
          agent.Windows._queue_job_ids("P") == {12, 13, 14},
          f"got {agent.Windows._queue_job_ids('P')}")

    queue_returns("")
    check("an empty queue reads as no jobs",
          agent.Windows._queue_job_ids("P") == set())

    queue_returns("", 1)
    check("an unreadable queue is None, not an empty queue",
          agent.Windows._queue_job_ids("P") is None)

    # A job already in the queue when the agent submits must not be mistaken
    # for the one it just sent.
    queue_returns("12")
    check("a pre-existing job is not claimed as ours",
          agent.Windows._await_spooled("P", {12}, seconds=0.5) is None)

    queue_returns("12,19")
    check("a genuinely new job is picked up",
          agent.Windows._await_spooled("P", {12}, seconds=0.5) == 19)

    # The whole file, not just this class: a bulk edit once left a second copy
    # of Converter and Windows in place, and Python kept the later one - so
    # fixes went into code that never ran.
    source = AGENT.read_text()
    for name in ("class Cups:", "class Windows:", "class Converter:", "class Agent:"):
        check(f"{name.rstrip(':')} is defined exactly once",
              source.count("\n" + name) == 1,
              f"found {source.count(chr(10) + name)}")

    # --- Printer list refresh ----------------------------------------------
    print("Printer assignment")

    class FakeApi:
        def __init__(self, printers, error=None):
            self.printers = printers
            self.error = error
            self.calls = 0

        def config_call(self):
            self.calls += 1
            if self.error:
                raise self.error
            return {"printers": self.printers}

    class Bare(agent.Agent):
        def __init__(self):            # no config, no network, no work dir
            self.printers = []
            self.capabilities_reported = set()
            self.api = None

    # A printer added in the admin panel after the agent started must be
    # picked up. It used to be fetched once at startup, so the operator saw
    # "connected but has not yet reported this printer's state" indefinitely.
    a = Bare()
    a.api = FakeApi([{"code": "office-01"}])
    a.refresh_printers()
    check("a newly assigned printer is picked up",
          [p["code"] for p in a.printers] == ["office-01"], f"got {a.printers}")

    # A momentary API failure must not take working printers out of service.
    a.api = FakeApi([], error=agent.ApiError("server down"))
    a.refresh_printers()
    check("a failed refresh keeps the printers it already had",
          [p["code"] for p in a.printers] == ["office-01"], f"got {a.printers}")

    # A printer taken away is dropped, and will re-report its capabilities if
    # it comes back, since the queue behind it may have changed.
    a.capabilities_reported.add("office-01")
    a.api = FakeApi([])
    a.refresh_printers()
    check("an unassigned printer is dropped",
          a.printers == [], f"got {a.printers}")
    check("its capabilities are re-reported if it returns",
          "office-01" not in a.capabilities_reported,
          f"got {a.capabilities_reported}")

    # --- Queues that are not printers --------------------------------------
    print("Queues that write files")

    # Microsoft Print to PDF is the one that caught a real operator out: the
    # agent submitted, SumatraPDF exited 0, nothing ever reached the spooler,
    # and thirty seconds later the message blamed the spool settings. The port
    # is the reliable signal - a renamed queue keeps it.
    check("a Save-As port is recognised",
          agent.looks_virtual("Anything At All", "PORTPROMPT:"))
    check("so is a fax port", agent.looks_virtual("Office Machine", "SHRFAX:"))
    check("so is printing to a file", agent.looks_virtual("Whatever", "FILE:"))

    # And the name, for a queue whose port cannot be read. "Fax" is the queue a
    # multifunction machine installs beside its printer queue: it really is a
    # fax and really cannot print.
    for name in ("Microsoft Print to PDF", "Microsoft XPS Document Writer",
                 "Send To OneNote 16", "Fax", "HP LaserJet MFP M234 Fax",
                 "Foxit PDF Writer"):
        check(f"{name!r} is recognised by name alone", agent.looks_virtual(name))

    # Real printers must not be swept up. This is the half that matters: a
    # false positive warns an operator away from the printer they actually own,
    # which is worse than missing a virtual one - the port check is there for
    # the ones a name cannot settle.
    for name, port in [
        ("HP LaserJet M1005", "USB001"),
        ("Canon PIXMA GM4070", "192.168.1.50"),
        ("Office Laser", "WSD-8f2c"),
        ("Reception HP", ""),
        # Named by a shop, not by a driver. "pdf" and "fax" as bare substrings
        # would flag both of these.
        ("Front desk (PDF and print)", "USB001"),
        ("Faxton Road Branch", "USB002"),
    ]:
        check(f"{name!r} is left alone", not agent.looks_virtual(name, port))

    # The failure message must name the real cause rather than the spooler.
    source = AGENT.read_text()
    submit = source[source.index("job_id = cls._await_spooled"):]
    submit = submit[:submit.index("return True")]
    check("a job that never queued checks for this first",
          "looks_virtual" in submit and submit.index("looks_virtual") < submit.index("SumatraPDF ran"),
          "the generic spooler advice comes first, so the real cause is never reached")
    check("and says so in words an operator can act on",
          "does not print onto paper" in submit)

    # --- Saying which backend refused --------------------------------------
    print("Naming the backend")

    # The log read "CUPS rejected job AK100023" on a Windows counter PC, which
    # sends whoever reads it looking for a CUPS that is not installed and never
    # was.
    check("each backend says what it is",
          agent.Cups.label == "CUPS" and "Windows" in agent.Windows.label,
          f"cups={agent.Cups.label!r} windows={agent.Windows.label!r}")

    # Only messages about a job: "CUPS tools not found" is the CUPS backend
    # talking about itself, which is correct and must stay.
    hard_coded = [
        line.strip() for line in source.splitlines()
        if "log." in line and "CUPS" in line and "job_number" in line
    ]
    check("the rejection is logged against the backend that rejected it",
          "self.spooler.label" in source and hard_coded == [],
          f"hard-coded CUPS in a message both backends produce: {hard_coded}")

    # --- Updating itself ---------------------------------------------------
    print("Updating the software")

    import http.server
    import threading as _threading

    # "1.10.0" is newer than "1.9.0" and sorts before it as text. Comparing
    # versions as strings would have offered people a downgrade and called it
    # an update.
    check("a higher version is offered", agent.is_newer("1.10.0", "1.9.0"))
    check("a lower one is not", not agent.is_newer("1.9.0", "1.10.0"))
    check("the same one is not", not agent.is_newer("1.0.0", "1.0.0"))
    check("a missing version is not", not agent.is_newer("", "1.0.0"))
    check("shorter and longer versions still compare",
          agent.is_newer("2.0", "1.99.99") and not agent.is_newer("1.0", "1.0.1"))

    # The download is trusted for its hash, not its address: an operator typed
    # that address into a settings box.
    payload = b"MZ this stands in for an installer" * 64
    good_hash = hashlib.sha256(payload).hexdigest()

    class Serve(http.server.BaseHTTPRequestHandler):
        def do_GET(self):
            self.send_response(200)
            self.send_header("Content-Length", str(len(payload)))
            self.end_headers()
            self.wfile.write(payload)

        def log_message(self, *args):
            pass

    server = http.server.HTTPServer(("127.0.0.1", 0), Serve)
    port = server.server_address[1]
    _threading.Thread(target=server.serve_forever, daemon=True).start()

    with tempfile.TemporaryDirectory() as tmp:
        work = Path(tmp)
        config = agent.Config(server=f"http://127.0.0.1:{port}", token="t", work_dir=work)
        api = agent.Api(config)

        # Plain HTTP is refused before a byte moves, whatever the hash says.
        target = work / "setup.exe"
        try:
            api.fetch(f"http://127.0.0.1:{port}/setup.exe", target, good_hash)
            http_refused = False
            http_reason = "it downloaded over plain HTTP"
        except agent.ApiError as error:
            http_refused = True
            http_reason = str(error)
        check("an update is never fetched over plain HTTP", http_refused, http_reason)
        check("and nothing is left behind when it is refused", not target.exists())

        # The checksum itself. fetch() insists on https and there is no
        # certificate here, so the transport is stubbed and the digest, the
        # comparison and the rename are exercised for real.
        class FakeResponse:
            def __init__(self, body: bytes) -> None:
                self.body = body
                self.offset = 0

            def read(self, size: int = -1) -> bytes:
                if size < 0:
                    size = len(self.body) - self.offset
                chunk = self.body[self.offset:self.offset + size]
                self.offset += len(chunk)
                return chunk

            def __enter__(self):
                return self

            def __exit__(self, *exc):
                return False

        saved_urlopen = agent.urllib.request.urlopen
        agent.urllib.request.urlopen = lambda request, **kwargs: FakeResponse(payload)
        try:
            # The right hash: the file arrives, under its final name.
            right = work / "right.exe"
            api.fetch("https://example.test/setup.exe", right, good_hash)
            landed = right.is_file() and right.read_bytes() == payload

            # The wrong hash: nothing may be left anywhere that could be run
            # later - not under the final name, not as a partial download.
            wrong = work / "wrong.exe"
            try:
                api.fetch("https://example.test/setup.exe", wrong, "0" * 64)
                mismatch = "it accepted a file whose hash did not match"
            except agent.ApiError as error:
                mismatch = str(error)
            leftovers = sorted(f.name for f in work.iterdir() if f.name.startswith("wrong"))
        finally:
            agent.urllib.request.urlopen = saved_urlopen

        check("a download whose hash matches is kept", landed)
        check("one whose hash does not match is refused",
              "not what the server said" in mismatch, mismatch)
        check("and leaves nothing behind, not even a partial file",
              leftovers == [], f"found {leftovers}")

        # A checksum that is not a checksum is refused before any request.
        malformed = work / "malformed.exe"
        try:
            api.fetch("https://example.invalid/x.exe", malformed, "not-a-hash")
            bad_sum_refused = False
        except agent.ApiError:
            bad_sum_refused = True
        check("a malformed checksum is refused outright", bad_sum_refused)
        check("and that refusal makes no request either", not malformed.exists())

    server.shutdown()

    # The installer must be started detached: it closes this application as its
    # first act, so waiting for it would be waiting for something waiting on us.
    source = AGENT.read_text()
    spawn = source[source.index("def spawn_detached"):source.index("def version_tuple")]
    # Code only: the docstring explains why subprocess.run is wrong here, and
    # scanning the whole block matched that explanation rather than a call.
    spawn_code = re.sub(r'"""[\s\S]*?"""', "", spawn)
    check("the updater starts the installer without waiting for it",
          "subprocess.Popen(" in spawn_code and "subprocess.run(" not in spawn_code,
          "subprocess.run would deadlock against an installer that closes this app")
    check("and detached, so it outlives the executable it replaces",
          "DETACHED_PROCESS" in spawn and "start_new_session" in spawn)

    # --- No window flashes on the counter PC -------------------------------
    print("Windows console suppression")

    import types

    # Nothing may call subprocess.run directly: on Windows that is a console
    # window appearing over whatever the operator is doing, several times a
    # minute, for as long as the agent is up.
    direct = [
        (number, line.strip())
        for number, line in enumerate(source.splitlines(), 1)
        if "subprocess.run(" in line and not line.lstrip().startswith("#")
    ]
    check("subprocess.run is called in exactly one place",
          len(direct) == 1, "; ".join(f"line {n}: {t}" for n, t in direct))

    saved_os = agent.os
    saved_subprocess = agent.subprocess
    calls: list[dict] = []

    class FakeStartupInfo:
        def __init__(self) -> None:
            self.dwFlags = 0
            self.wShowWindow = None

    fake_subprocess = types.SimpleNamespace(
        run=lambda args, **kwargs: calls.append(kwargs) or "ran",
        STARTUPINFO=FakeStartupInfo,
        STARTF_USESHOWWINDOW=1,
        SW_HIDE=0,
        CREATE_NO_WINDOW=0x08000000,
    )

    try:
        agent.os = types.SimpleNamespace(name="nt")
        agent.subprocess = fake_subprocess
        agent.CREATE_NO_WINDOW = 0x08000000
        agent.run_quiet(["powershell", "-Command", "Get-Printer"], timeout=30)
    finally:
        agent.os = saved_os
        agent.subprocess = saved_subprocess
        agent.CREATE_NO_WINDOW = getattr(saved_subprocess, "CREATE_NO_WINDOW", 0)

    passed = calls[0] if calls else {}
    check("on Windows it asks for no console window",
          passed.get("creationflags") == 0x08000000, f"got {passed.get('creationflags')}")
    check("and hides a window the program asks for itself",
          getattr(passed.get("startupinfo"), "wShowWindow", None) == 0
          and getattr(passed.get("startupinfo"), "dwFlags", 0) & 1 == 1,
          f"got {passed.get('startupinfo')}")
    check("output is still captured, which the agent reads",
          passed.get("capture_output") is True and passed.get("text") is True,
          f"got {passed}")

    # On Linux the flag does not exist and must not be invented.
    calls.clear()
    saved_subprocess = agent.subprocess
    try:
        agent.subprocess = fake_subprocess
        agent.run_quiet(["lpstat", "-e"])
    finally:
        agent.subprocess = saved_subprocess

    check("on Linux no Windows-only argument is passed",
          "creationflags" not in calls[0] and "startupinfo" not in calls[0],
          f"got {calls[0]}")

    # --- Running inside the desktop window ---------------------------------
    print("Running on a worker thread")

    # The desktop app runs the agent on a background thread so the window
    # stays responsive. Installing a signal handler off the main thread raises
    # ValueError, and it was raised *before* the first heartbeat: the agent
    # looked registered but the admin panel showed it pending, last seen
    # never, with the reason only visible in the app's log pane.
    import threading
    import time as _time

    with tempfile.TemporaryDirectory() as tmp:
        worker_config = agent.Config(
            server="https://nowhere.invalid", token="t",
            work_dir=Path(tmp), poll_interval=1, heartbeat_interval=0,
        )
        worker = agent.Agent(worker_config)

        beats = []
        worker.api = type("Api", (), {
            "config_call": lambda self: {"device": {"name": "counter", "poll_interval": 1},
                                         "printers": []},
            "heartbeat": lambda self, printers: beats.append(printers) or {},
            "claim": lambda self: {},
        })()

        saved_spooler = agent.select_spooler
        agent.select_spooler = lambda: agent.Cups
        raised: list[str] = []
        returned: list[int] = []
        try:
            def body():
                try:
                    returned.append(worker.run())
                except BaseException as error:        # noqa: BLE001 - that is the point
                    raised.append(f"{error.__class__.__name__}: {error}")

            thread = threading.Thread(target=body, daemon=True)
            thread.start()
            deadline = _time.monotonic() + 10
            while not beats and not raised and _time.monotonic() < deadline:
                _time.sleep(0.05)
            worker.stop()
            thread.join(timeout=10)
        finally:
            agent.select_spooler = saved_spooler

        check("run() on a worker thread raises nothing", not raised,
              raised[0] if raised else "")
        check("it reaches the heartbeat loop", bool(beats), "no heartbeat was sent")
        check("it stops cleanly when the window asks it to",
              returned == [0] and not thread.is_alive(),
              f"returned {returned}, alive={thread.is_alive()}")

    # On the main thread - a service, or the command line - the handlers are
    # still installed, so systemd and Ctrl-C shut it down as they always did.
    import signal as _signal

    installed = []
    saved_signal = _signal.signal
    saved_spooler = agent.select_spooler
    try:
        agent.select_spooler = lambda: agent.Cups
        _signal.signal = lambda number, handler: installed.append(number)
        agent.signal.signal = _signal.signal

        with tempfile.TemporaryDirectory() as tmp:
            main_config = agent.Config(
                server="https://nowhere.invalid", token="t",
                work_dir=Path(tmp), poll_interval=1,
            )
            main_agent = agent.Agent(main_config)
            main_agent.running = False          # install handlers, skip the loop
            main_agent.api = type("Api", (), {
                "config_call": lambda self: {"device": {}, "printers": []},
            })()
            main_agent.run()
    finally:
        _signal.signal = saved_signal
        agent.signal.signal = saved_signal
        agent.select_spooler = saved_spooler

    check("SIGTERM and SIGINT are still handled on the main thread",
          set(installed) == {_signal.SIGTERM, _signal.SIGINT}, f"got {installed}")

    # --- The installer script itself ---------------------------------------
    print("install.ps1 encoding")

    installer = AGENT.parent / "install.ps1"
    raw = installer.read_bytes()

    body = raw[3:] if raw.startswith(b"\xef\xbb\xbf") else raw
    check("install.ps1 starts with a UTF-8 BOM",
          raw.startswith(b"\xef\xbb\xbf"),
          "Windows PowerShell 5.1 reads a BOM-less file as ANSI")

    # Windows PowerShell 5.1 decodes a BOM-less script using the system code
    # page. A UTF-8 em dash becomes three CP1252 characters, the last of which
    # is a smart quote - and PowerShell accepts smart quotes as string
    # delimiters, so it silently ends the string it sits inside. That turned
    # this installer into "Missing closing '}'" on a real Windows 10 machine
    # while parsing cleanly under PowerShell 7, which assumes UTF-8.
    offenders = [(i, line) for i, line in enumerate(body.split(b"\n"), 1)
                 if any(byte > 127 for byte in line)]
    check("install.ps1 is plain ASCII",
          not offenders,
          "non-ASCII on line(s) " + ", ".join(str(i) for i, _ in offenders[:5]))

    # Prove it survives the decoding Windows PowerShell 5.1 would apply.
    try:
        body.decode("cp1252")
        decodes = True
    except UnicodeDecodeError:
        decodes = False
    check("install.ps1 survives a CP1252 read", decodes)

    # --- PowerShell generation --------------------------------------------
    print("Generated PowerShell")

    captured: list[str] = []
    agent.Windows._ps = classmethod(
        lambda cls, script, timeout=30: (captured.append(script), FakeResult("", 1))[1]
    )
    agent.Windows._powershell = staticmethod(lambda: "pwsh")

    agent.Windows.printer_state("HP LaserJet M1005")
    agent.Windows.capabilities("HP LaserJet M1005")
    agent.Windows.job_finished("HP LaserJet M1005::42")
    agent.Windows.printer_state("Ravi's Printer")
    agent.Windows._configure("HP LaserJet M1005", {
        "paper": "A4", "color": "monochrome", "duplex": "TwoSidedLongEdge",
    })

    check("every backend call produced a script", len(captured) == 5, f"got {len(captured)}")

    quoted = [s for s in captured if "Ravi" in s]
    check("a quote in a printer name is escaped, not injected",
          bool(quoted) and "Ravi''s Printer" in quoted[0],
          quoted[0][:120] if quoted else "no script")

    pwsh = shutil.which("pwsh") or shutil.which("powershell")
    if pwsh is None:
        print("  skip PowerShell parse check (pwsh not installed here)")
    else:
        with tempfile.TemporaryDirectory() as tmp:
            for index, script in enumerate(captured, 1):
                path = Path(tmp) / f"snippet{index}.ps1"
                path.write_text(script)
                probe = subprocess.run(
                    [pwsh, "-NoProfile", "-Command",
                     f'$e=$null;$t=$null;'
                     f'[System.Management.Automation.Language.Parser]::ParseFile('
                     f'"{path}",[ref]$t,[ref]$e)|Out-Null;'
                     f'if($e.Count -eq 0){{"OK"}}else{{$e[0].Message}}'],
                    capture_output=True, text=True, timeout=60, check=False,
                )
                check(f"snippet {index} is valid PowerShell",
                      probe.stdout.strip() == "OK", probe.stdout.strip()[:120])

    print()
    if failures:
        print(f"{passes} passed, {len(failures)} failed: {', '.join(failures)}")
        return 1

    print(f"{passes} passed, 0 failed")
    return 0


if __name__ == "__main__":
    sys.exit(main())
