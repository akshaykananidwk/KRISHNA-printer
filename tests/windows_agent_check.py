#!/usr/bin/env python3
"""
Checks for the agent's Windows printing backend.

The backend itself cannot be exercised from Linux — there is no Windows print
spooler here to drive. What can be checked, and is checked here, is everything
that decides whether it behaves correctly once it is on Windows:

  * the CUPS options the server sends are translated into Windows queue
    settings and SumatraPDF arguments without losing any of them,
  * anything that cannot be carried is reported rather than silently dropped,
  * the printer states Windows reports map to the reasons the server treats as
    blocking, so a printer out of paper stops taking orders,
  * a printer name containing a quote cannot break out of the PowerShell
    string it is interpolated into,
  * and the embedded PowerShell actually parses (when pwsh is available).

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
    agent.Windows.job_finished("AK100042")
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
