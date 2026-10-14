"""Headless tests of the capture worker and main window, using a fake adb."""
import time

import pytest
from conftest import GREEN, make_frame

pytest.importorskip("PySide6")
from PySide6.QtWidgets import QApplication  # noqa: E402

from mgo_vision.adb import AdbError, EmulatorInstance  # noqa: E402
from mgo_vision.capture import STATUS_DISCONNECTED, STATUS_LIVE, CaptureWorker  # noqa: E402
from mgo_vision.config import AppSettings  # noqa: E402
from mgo_vision.vision import TemplateLibrary  # noqa: E402


@pytest.fixture(scope="module")
def app():
    return QApplication.instance() or QApplication([])


class FlakyAdb:
    """Returns frames, then fails (simulated disconnect), then recovers."""

    def __init__(self, frame, fail_between=(3, 8)):
        self.frame, self.calls, self.fail_between = frame, 0, fail_between

    def screencap(self, serial):
        self.calls += 1
        if self.fail_between[0] <= self.calls < self.fail_between[1]:
            raise AdbError("device offline")
        return self.frame.copy()

    def connect(self, addr):
        return False


def _pump(app, seconds):
    end = time.monotonic() + seconds
    while time.monotonic() < end:
        app.processEvents()
        time.sleep(0.01)


def test_worker_disconnect_and_recover(app, profile, tmp_path):
    adb = FlakyAdb(make_frame({(0, 0): GREEN}))
    inst = EmulatorInstance("emulator-5554", "LD", 0, True)
    worker = CaptureWorker(inst, adb, profile, TemplateLibrary(tmp_path), interval_ms=100,
                           failures_before_disconnect=2, max_backoff_s=0.2)
    worker._sleep = lambda s: time.sleep(min(s, 0.2))  # speed up backoff
    statuses, observations, frames = [], [], []
    worker.status_changed.connect(lambda k, s, m: statuses.append(s))
    worker.observation_ready.connect(lambda k, r, f: observations.append(r))
    worker.frame_ready.connect(lambda k, img, f, r: frames.append(r))
    worker.start()
    _pump(app, 3.0)
    worker.stop()
    _pump(app, 0.2)
    assert STATUS_LIVE in statuses and STATUS_DISCONNECTED in statuses
    assert statuses.index(STATUS_DISCONNECTED) < len(statuses) - 1  # recovered afterwards
    assert frames and observations
    assert observations[0].cells[0].tile_type == "cash"


def test_main_window_without_adb(app, tmp_path):
    from mgo_vision.ui import MainWindow
    settings = AppSettings(adb_path=str(tmp_path / "no-adb"), data_dir=str(tmp_path / "data"),
                           calibration_dir=str(tmp_path / "cal"))
    win = MainWindow(settings)
    win._discovery_thread.wait(5000)
    _pump(app, 0.3)
    assert "adb not found" in win.statusBar().currentMessage()
    win.start_recording()  # no instances selected -> info box would block; guarded below
    win.close()


@pytest.fixture(autouse=True)
def _no_modal(monkeypatch):
    from PySide6.QtWidgets import QMessageBox
    monkeypatch.setattr(QMessageBox, "information", staticmethod(lambda *a, **k: None))
    monkeypatch.setattr(QMessageBox, "question", staticmethod(lambda *a, **k: QMessageBox.StandardButton.Yes))
