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
          and "duplexshort" in colour_settings,
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
        agent.Windows._await_spooled = classmethod(
            lambda cls, queue, document, seconds=0.5: spooled
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
          "never reached the Windows print queue" in message, message[:120])

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
