"""Thin wrapper around the `adb` command-line tool.

Only read-only, officially supported operations are used: listing devices,
connecting to emulator ports, querying state and taking screenshots via
`screencap`. No input events are ever sent to the device.
"""
from __future__ import annotations

import logging
import shutil
import subprocess
from dataclasses import dataclass

import cv2
import numpy as np

log = logging.getLogger(__name__)

# Hide console windows spawned by subprocess on Windows.
_CREATE_NO_WINDOW = getattr(subprocess, "CREATE_NO_WINDOW", 0)


class AdbError(RuntimeError):
    """Raised when an adb command fails or returns unusable output."""


@dataclass(frozen=True)
class AdbDevice:
    serial: str
    state: str  # "device", "offline", "unauthorized", ...
    model: str = ""

    @property
    def online(self) -> bool:
        return self.state == "device"


def parse_devices_output(text: str) -> list[AdbDevice]:
    """Parse the output of `adb devices -l`."""
    devices: list[AdbDevice] = []
    for line in text.splitlines():
        line = line.strip()
        if not line or line.startswith("List of devices") or line.startswith("*"):
            continue
        parts = line.split()
        if len(parts) < 2:
            continue
        model = ""
        for token in parts[2:]:
            if token.startswith("model:"):
                model = token.split(":", 1)[1]
        devices.append(AdbDevice(serial=parts[0], state=parts[1], model=model))
    return devices


class AdbClient:
    def __init__(self, adb_path: str | None = None, timeout: float = 8.0):
        self.adb_path = adb_path or shutil.which("adb") or "adb"
        self.timeout = timeout

    def _run(self, *args: str, serial: str | None = None, timeout: float | None = None) -> bytes:
        cmd = [self.adb_path]
        if serial:
            cmd += ["-s", serial]
        cmd += list(args)
        try:
            proc = subprocess.run(
                cmd,
                capture_output=True,
                timeout=timeout or self.timeout,
                creationflags=_CREATE_NO_WINDOW,
            )
        except FileNotFoundError as exc:
            raise AdbError(f"adb not found at '{self.adb_path}'") from exc
        except subprocess.TimeoutExpired as exc:
            raise AdbError(f"adb timed out: {' '.join(cmd)}") from exc
        if proc.returncode != 0:
            err = proc.stderr.decode(errors="replace").strip()
            raise AdbError(f"adb failed ({proc.returncode}): {err}")
        return proc.stdout

    def available(self) -> bool:
        try:
            self._run("version", timeout=5)
            return True
        except AdbError:
            return False

    def devices(self) -> list[AdbDevice]:
        out = self._run("devices", "-l")
        return parse_devices_output(out.decode(errors="replace"))

    def connect(self, address: str) -> bool:
        try:
            out = self._run("connect", address, timeout=5).decode(errors="replace").lower()
        except AdbError as exc:
            log.debug("adb connect %s failed: %s", address, exc)
            return False
        return "connected" in out and "cannot" not in out and "failed" not in out

    def get_state(self, serial: str) -> str:
        try:
            return self._run("get-state", serial=serial, timeout=4).decode().strip()
        except AdbError:
            return "disconnected"

    def screencap(self, serial: str) -> np.ndarray:
        """Return the current screen as a BGR image."""
        data = self._run("exec-out", "screencap", "-p", serial=serial)
        if not data:
            raise AdbError(f"empty screencap from {serial}")
        img = cv2.imdecode(np.frombuffer(data, np.uint8), cv2.IMREAD_COLOR)
        if img is None:
            # Some older adb builds translate \n to \r\n on exec-out.
            img = cv2.imdecode(np.frombuffer(data.replace(b"\r\n", b"\n"), np.uint8), cv2.IMREAD_COLOR)
        if img is None:
            raise AdbError(f"could not decode screencap from {serial}")
        return img
