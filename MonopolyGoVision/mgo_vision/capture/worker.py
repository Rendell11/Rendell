"""One background thread per monitored emulator.

Each worker repeatedly grabs a screenshot via adb, runs the recognizer and
emits results to the UI thread. ADB failures never crash the worker: after a
few consecutive failures the instance is reported as disconnected and the
worker retries with exponential backoff until it comes back or is stopped.
"""
from __future__ import annotations

import logging
import threading
import time

import cv2
import numpy as np
from PySide6.QtCore import QThread, Signal
from PySide6.QtGui import QImage

from ..adb import AdbClient, AdbError, EmulatorInstance
from ..vision import (CalibrationProfile, ObservationStabilizer, OverlayRecognizer,
                      TemplateLibrary, draw_debug)

log = logging.getLogger(__name__)

STATUS_CONNECTING = "connecting"
STATUS_LIVE = "live"
STATUS_DISCONNECTED = "disconnected"
STATUS_STOPPED = "stopped"


def bgr_to_qimage(frame: np.ndarray) -> QImage:
    rgb = cv2.cvtColor(frame, cv2.COLOR_BGR2RGB)
    h, w = rgb.shape[:2]
    return QImage(rgb.data, w, h, 3 * w, QImage.Format.Format_RGB888).copy()


class CaptureWorker(QThread):
    # instance key, display image, raw BGR frame, FrameResult
    frame_ready = Signal(str, QImage, object, object)
    # instance key, stabilized FrameResult, raw BGR frame
    observation_ready = Signal(str, object, object)
    # instance key, status, message
    status_changed = Signal(str, str, str)

    def __init__(self, instance: EmulatorInstance, adb: AdbClient, profile: CalibrationProfile,
                 templates: TemplateLibrary, interval_ms: int = 500,
                 failures_before_disconnect: int = 3, max_backoff_s: float = 10.0,
                 show_debug: bool = True, parent=None):
        super().__init__(parent)
        self.instance = instance
        self.adb = adb
        self.interval_ms = max(100, interval_ms)
        self.failures_before_disconnect = max(1, failures_before_disconnect)
        self.max_backoff_s = max_backoff_s
        self.show_debug = show_debug
        self._lock = threading.Lock()
        self._recognizer = OverlayRecognizer(profile, templates)
        self._stabilizer = ObservationStabilizer(profile.stable_frames)
        self._status = ""

    @property
    def key(self) -> str:
        return self.instance.key

    def set_profile(self, profile: CalibrationProfile, templates: TemplateLibrary) -> None:
        with self._lock:
            self._recognizer = OverlayRecognizer(profile, templates)
            self._stabilizer = ObservationStabilizer(profile.stable_frames)

    def reset_stabilizer(self) -> None:
        with self._lock:
            self._stabilizer.reset()

    def _set_status(self, status: str, message: str = "") -> None:
        if status != self._status:
            self._status = status
            self.status_changed.emit(self.key, status, message)

    def _sleep(self, seconds: float) -> None:
        end = time.monotonic() + seconds
        while not self.isInterruptionRequested() and time.monotonic() < end:
            self.msleep(50)

    def _try_reconnect(self) -> None:
        serial = self.instance.serial
        if ":" in serial:  # TCP serial: ask adb to reconnect
            self.adb.connect(serial)

    def run(self) -> None:
        self._set_status(STATUS_CONNECTING, self.instance.serial)
        failures, backoff = 0, 1.0
        serial = self.instance.serial
        while not self.isInterruptionRequested():
            started = time.monotonic()
            try:
                frame = self.adb.screencap(serial)
            except AdbError as exc:
                failures += 1
                log.debug("[%s] capture failed (%d): %s", self.key, failures, exc)
                if failures >= self.failures_before_disconnect:
                    self._set_status(STATUS_DISCONNECTED, str(exc))
                    self.reset_stabilizer()
                    self._try_reconnect()
                    self._sleep(backoff)
                    backoff = min(self.max_backoff_s, backoff * 2)
                else:
                    self._sleep(self.interval_ms / 1000)
                continue

            failures, backoff = 0, 1.0
            self._set_status(STATUS_LIVE, f"{frame.shape[1]}x{frame.shape[0]}")

            with self._lock:
                recognizer, stabilizer = self._recognizer, self._stabilizer
                try:
                    result = recognizer.recognize(frame)
                except Exception:  # noqa: BLE001 - a bad profile must not kill the thread
                    log.exception("[%s] recognition failed", self.key)
                    result = None
                stable = stabilizer.feed(result) if result is not None else None

            display = draw_debug(frame, recognizer.profile, result) if self.show_debug else frame
            self.frame_ready.emit(self.key, bgr_to_qimage(display), frame, result)
            if stable is not None:
                self.observation_ready.emit(self.key, stable, frame)

            elapsed = time.monotonic() - started
            self._sleep(max(0.0, self.interval_ms / 1000 - elapsed))
        self._set_status(STATUS_STOPPED)

    def stop(self, wait_ms: int = 5000) -> None:
        self.requestInterruption()
        self.wait(wait_ms)
