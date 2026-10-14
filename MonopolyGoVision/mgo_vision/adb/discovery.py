"""Discovery of running LDPlayer instances.

Two sources are combined, so the number of instances is never hard-coded:

1. `ldconsole list2` (optional, Windows only) lists every LDPlayer instance
   with its index, title and whether Android is running. Instance *i* exposes
   adb on port 5555 + 2*i, i.e. serial `emulator-<5554+2i>` or `127.0.0.1:<5555+2i>`.
2. `adb devices` lists what adb can actually reach.

Running instances found by ldconsole but not visible to adb are connected via
`adb connect 127.0.0.1:<port>`.
"""
from __future__ import annotations

import logging
import re
import shutil
import subprocess
from dataclasses import dataclass
from pathlib import Path

from .client import AdbClient, AdbError, _CREATE_NO_WINDOW

log = logging.getLogger(__name__)

_COMMON_LDCONSOLE_PATHS = [
    r"C:\LDPlayer\LDPlayer9\ldconsole.exe",
    r"C:\LDPlayer\LDPlayer4.0\ldconsole.exe",
    r"C:\Program Files\LDPlayer\LDPlayer9\ldconsole.exe",
    r"D:\LDPlayer\LDPlayer9\ldconsole.exe",
]


@dataclass(frozen=True)
class LdInstanceInfo:
    index: int
    title: str
    android_running: bool
    pid: int


@dataclass(frozen=True)
class EmulatorInstance:
    serial: str
    name: str
    index: int | None  # LDPlayer index if known
    online: bool

    @property
    def key(self) -> str:
        """Stable identifier used for storage/calibration lookups."""
        return f"ld{self.index}" if self.index is not None else self.serial


def parse_ldconsole_list2(text: str) -> list[LdInstanceInfo]:
    """Parse `ldconsole list2` output: index,title,top_hwnd,bind_hwnd,android_started,pid,vbox_pid,..."""
    result: list[LdInstanceInfo] = []
    for line in text.splitlines():
        parts = line.strip().split(",")
        if len(parts) < 6 or not parts[0].strip().isdigit():
            continue
        try:
            result.append(
                LdInstanceInfo(
                    index=int(parts[0]),
                    title=parts[1],
                    android_running=parts[4].strip() == "1",
                    pid=int(parts[5]) if parts[5].strip().lstrip("-").isdigit() else -1,
                )
            )
        except ValueError:
            continue
    return result


_EMU_RE = re.compile(r"^emulator-(\d+)$")
_TCP_RE = re.compile(r"^(?:127\.0\.0\.1|localhost):(\d+)$")


def serial_to_ld_index(serial: str) -> int | None:
    """Map an adb serial to an LDPlayer index (5554/5555 -> 0, 5556/5557 -> 1, ...)."""
    m = _EMU_RE.match(serial)
    if m:
        port = int(m.group(1))
        return (port - 5554) // 2 if port >= 5554 and port % 2 == 0 else None
    m = _TCP_RE.match(serial)
    if m:
        port = int(m.group(1))
        return (port - 5555) // 2 if port >= 5555 and port % 2 == 1 else None
    return None


def find_ldconsole(configured: str | None = None) -> str | None:
    candidates = [configured] if configured else []
    candidates += [shutil.which("ldconsole")] + _COMMON_LDCONSOLE_PATHS
    for path in candidates:
        if path and Path(path).is_file():
            return path
    return None


class LdPlayerDiscovery:
    def __init__(self, adb: AdbClient, ldconsole_path: str | None = None):
        self.adb = adb
        self.ldconsole_path = find_ldconsole(ldconsole_path)

    def _ldconsole_instances(self) -> list[LdInstanceInfo]:
        if not self.ldconsole_path:
            return []
        try:
            proc = subprocess.run(
                [self.ldconsole_path, "list2"],
                capture_output=True,
                timeout=8,
                creationflags=_CREATE_NO_WINDOW,
            )
        except (OSError, subprocess.TimeoutExpired) as exc:
            log.warning("ldconsole list2 failed: %s", exc)
            return []
        # ldconsole prints in the system ANSI code page; titles may be non-ASCII.
        text = proc.stdout.decode("utf-8", errors="replace")
        return parse_ldconsole_list2(text)

    def discover(self) -> list[EmulatorInstance]:
        ld_infos = {i.index: i for i in self._ldconsole_instances()}

        try:
            devices = self.adb.devices()
        except AdbError as exc:
            log.error("adb devices failed: %s", exc)
            devices = []

        known_indexes = {serial_to_ld_index(d.serial) for d in devices}
        connected_any = False
        for info in ld_infos.values():
            if info.android_running and info.index not in known_indexes:
                if self.adb.connect(f"127.0.0.1:{5555 + 2 * info.index}"):
                    connected_any = True
        if connected_any:
            try:
                devices = self.adb.devices()
            except AdbError:
                pass

        instances: dict[str, EmulatorInstance] = {}
        for dev in devices:
            idx = serial_to_ld_index(dev.serial)
            info = ld_infos.get(idx) if idx is not None else None
            name = info.title if info else (dev.model or dev.serial)
            inst = EmulatorInstance(serial=dev.serial, name=name, index=idx, online=dev.online)
            # One LDPlayer can appear as both emulator-5554 and 127.0.0.1:5555;
            # keep a single, preferably online, entry per instance.
            existing = instances.get(inst.key)
            if existing is None or (inst.online and not existing.online):
                instances[inst.key] = inst
        return sorted(instances.values(), key=lambda i: (i.index is None, i.index or 0, i.serial))
