"""Main window: instance selection, live views, recording controls, review table."""
from __future__ import annotations

import logging
import math
from datetime import datetime
from pathlib import Path

import cv2
from PySide6.QtCore import QThread, Qt, Signal
from PySide6.QtGui import QAction, QKeySequence
from PySide6.QtWidgets import (QDockWidget, QFileDialog, QGridLayout, QLabel, QMainWindow,
                               QMessageBox, QScrollArea, QToolBar, QWidget)

from ..adb import AdbClient, EmulatorInstance, LdPlayerDiscovery
from ..capture import STATUS_DISCONNECTED, STATUS_LIVE, CaptureWorker
from ..config import AppSettings
from ..storage import Database, export_csv, export_json
from ..vision import ProfileStore, TemplateLibrary
from .calibration_dialog import CalibrationDialog
from .dialogs import ExportDialog, ManualRecordDialog, SettingsDialog
from .instance_panel import InstancePanel
from .instance_selector import InstanceSelector
from .observations_view import ObservationsView

log = logging.getLogger(__name__)

STATE_IDLE, STATE_RECORDING, STATE_PAUSED = "idle", "recording", "paused"


class DiscoveryThread(QThread):
    finished_with = Signal(list, str)  # instances, error message

    def __init__(self, discovery: LdPlayerDiscovery, parent=None):
        super().__init__(parent)
        self.discovery = discovery

    def run(self) -> None:
        if not self.discovery.adb.available():
            self.finished_with.emit([], f"adb not found ({self.discovery.adb.adb_path}). Set it in Settings.")
            return
        try:
            self.finished_with.emit(self.discovery.discover(), "")
        except Exception as exc:  # noqa: BLE001
            log.exception("discovery failed")
            self.finished_with.emit([], str(exc))


class MainWindow(QMainWindow):
    def __init__(self, settings: AppSettings):
        super().__init__()
        self.settings = settings
        self.setWindowTitle("Monopoly GO Live Vision Recorder — v1 (monitoring only)")
        self.resize(1500, 950)

        self.db = Database(settings.db_path)
        self.store = ProfileStore(Path(settings.calibration_dir))
        self.templates = TemplateLibrary(settings.template_dir)
        self.adb = AdbClient(settings.adb_path or None)
        self.discovery = LdPlayerDiscovery(self.adb, settings.ldconsole_path or None)
        self._discovery_thread: DiscoveryThread | None = None

        self.state = STATE_IDLE
        self.session_id: int | None = None
        self.workers: dict[str, CaptureWorker] = {}
        self.panels: dict[str, InstancePanel] = {}
        self.record_counts: dict[str, int] = {}
        self._first_refresh = True

        self._build_ui()
        self._update_controls()
        self.refresh_instances()

    # ---- UI construction --------------------------------------------------
    def _build_ui(self) -> None:
        tb = QToolBar("Controls")
        tb.setMovable(False)
        self.addToolBar(tb)

        def action(text, slot, shortcut=None, tip=""):
            a = QAction(text, self)
            a.triggered.connect(slot)
            if shortcut:
                a.setShortcut(QKeySequence(shortcut))
            a.setToolTip(tip or text)
            tb.addAction(a)
            return a

        self.act_start = action("▶ Start", self.start_recording, "F5", "Start or resume recording")
        self.act_pause = action("⏸ Pause", self.pause_recording, "F6", "Pause recording (live view continues)")
        self.act_stop = action("■ Stop", self.stop_recording, "F7", "End the recording session")
        tb.addSeparator()
        self.act_export = action("Export…", self.export, "Ctrl+E")
        self.act_manual = action("Manual record…", self.manual_record, "Ctrl+M")
        self.act_calibrate = action("Calibrate…", self.calibrate, "Ctrl+K")
        tb.addSeparator()
        action("Settings…", self.open_settings)

        self.selector = InstanceSelector()
        self.selector.refresh_requested.connect(self.refresh_instances)
        self.selector.selection_changed.connect(self._sync_workers)
        dock = QDockWidget("LDPlayer instances", self)
        dock.setWidget(self.selector)
        dock.setFeatures(QDockWidget.DockWidgetFeature.DockWidgetMovable)
        self.addDockWidget(Qt.DockWidgetArea.LeftDockWidgetArea, dock)

        self.observations = ObservationsView(self.db)
        obs_dock = QDockWidget("Observations", self)
        obs_dock.setWidget(self.observations)
        obs_dock.setFeatures(QDockWidget.DockWidgetFeature.DockWidgetMovable
                             | QDockWidget.DockWidgetFeature.DockWidgetFloatable)
        self.addDockWidget(Qt.DockWidgetArea.BottomDockWidgetArea, obs_dock)

        self.panel_host = QWidget()
        self.panel_grid = QGridLayout(self.panel_host)
        self.empty_label = QLabel("Select one or more instances on the left to start live view.\n"
                                  "Tip: calibrate with a single instance first.")
        self.empty_label.setAlignment(Qt.AlignmentFlag.AlignCenter)
        self.panel_grid.addWidget(self.empty_label, 0, 0)
        scroll = QScrollArea()
        scroll.setWidgetResizable(True)
        scroll.setWidget(self.panel_host)
        self.setCentralWidget(scroll)

        self.state_label = QLabel()
        self.statusBar().addPermanentWidget(self.state_label)

    def _update_controls(self) -> None:
        self.act_start.setEnabled(self.state != STATE_RECORDING)
        self.act_start.setText("▶ Resume" if self.state == STATE_PAUSED else "▶ Start")
        self.act_pause.setEnabled(self.state == STATE_RECORDING)
        self.act_stop.setEnabled(self.state != STATE_IDLE)
        total = sum(self.record_counts.values())
        session = f"session {self.session_id}" if self.session_id else "no session"
        self.state_label.setText(f"{self.state.upper()} — {session} — {total} recorded")

    # ---- discovery & workers ----------------------------------------------
    def refresh_instances(self) -> None:
        if self._discovery_thread and self._discovery_thread.isRunning():
            return
        self.statusBar().showMessage("Detecting LDPlayer instances…")
        self._discovery_thread = DiscoveryThread(self.discovery, self)
        self._discovery_thread.finished_with.connect(self._on_discovered)
        self._discovery_thread.start()

    def _on_discovered(self, instances: list[EmulatorInstance], error: str) -> None:
        if error:
            self.statusBar().showMessage(error, 10000)
        else:
            self.statusBar().showMessage(f"Found {len(instances)} instance(s).", 5000)
        checked = {w for w in self.workers}
        if self._first_refresh:
            # Restore last selection, but only for instances that exist now.
            available = {i.key for i in instances}
            checked |= set(self.settings.selected_instances) & available
            self._first_refresh = False
        self.selector.set_instances(instances, checked)
        self.observations.set_instance_keys(sorted({i.key for i in instances} | set(self.workers)))

    def _sync_workers(self, checked: list[EmulatorInstance]) -> None:
        wanted = {i.key: i for i in checked}
        for key in list(self.workers):
            if key not in wanted:
                self._remove_worker(key)
            elif wanted[key].serial != self.workers[key].instance.serial and wanted[key].online:
                # Instance came back on a different serial: restart its worker.
                self._remove_worker(key)
        for key, inst in wanted.items():
            if key not in self.workers:
                self._add_worker(inst)
        self.settings.selected_instances = sorted(wanted)
        self._relayout_panels()

    def _add_worker(self, inst: EmulatorInstance) -> None:
        panel = self.panels.get(inst.key) or InstancePanel(inst.key, inst.name)
        self.panels[inst.key] = panel
        worker = CaptureWorker(
            inst, self.adb, self.store.for_instance(inst.key), self.templates,
            interval_ms=self.settings.capture_interval_ms,
            failures_before_disconnect=self.settings.failures_before_disconnect,
            max_backoff_s=self.settings.max_reconnect_backoff_s,
            show_debug=self.settings.show_debug_overlay, parent=self)
        worker.frame_ready.connect(self._on_frame)
        worker.status_changed.connect(self._on_status)
        worker.observation_ready.connect(self._on_observation)
        self.workers[inst.key] = worker
        worker.start()

    def _remove_worker(self, key: str) -> None:
        worker = self.workers.pop(key, None)
        if worker:
            worker.stop()
            worker.deleteLater()
        panel = self.panels.pop(key, None)
        if panel:
            panel.setParent(None)
            panel.deleteLater()

    def _relayout_panels(self) -> None:
        while self.panel_grid.count():
            self.panel_grid.takeAt(0)
        panels = [self.panels[k] for k in sorted(self.panels)]
        self.empty_label.setVisible(not panels)
        if not panels:
            self.panel_grid.addWidget(self.empty_label, 0, 0)
            return
        cols = max(1, math.ceil(math.sqrt(len(panels))))
        for i, panel in enumerate(panels):
            self.panel_grid.addWidget(panel, i // cols, i % cols)

    # ---- worker signals ---------------------------------------------------
    def _on_frame(self, key: str, image, frame, result) -> None:
        panel = self.panels.get(key)
        if panel:
            panel.update_frame(image, frame, result)

    def _on_status(self, key: str, status: str, message: str) -> None:
        panel = self.panels.get(key)
        if panel:
            panel.set_status(status, message)
        if status == STATUS_DISCONNECTED:
            self.statusBar().showMessage(f"{key} disconnected — retrying automatically. ({message})", 8000)
        if self.session_id is not None and status in (STATUS_LIVE, STATUS_DISCONNECTED):
            self.db.log_event(self.session_id, key, status, message)

    def _on_observation(self, key: str, result, frame) -> None:
        if self.state != STATE_RECORDING or self.session_id is None:
            return
        worker = self.workers.get(key)
        if worker is None:
            return
        inst = worker.instance
        snapshot = self._save_snapshot(key, frame) if self.settings.save_snapshots else None
        self.db.add_observation(self.session_id, key, inst.serial, inst.name, result, snapshot)
        self.record_counts[key] = self.record_counts.get(key, 0) + 1
        self.observations.schedule_reload()
        self._update_controls()

    def _save_snapshot(self, key: str, frame) -> str | None:
        if frame is None:
            return None
        folder = self.settings.snapshot_dir / f"session_{self.session_id or 'none'}"
        folder.mkdir(parents=True, exist_ok=True)
        path = folder / f"{key}_{datetime.now():%Y%m%d_%H%M%S_%f}.jpg"
        ok, buf = cv2.imencode(".jpg", frame, [cv2.IMWRITE_JPEG_QUALITY, 85])
        if not ok:
            return None
        buf.tofile(str(path))
        return str(path)

    # ---- recording controls -----------------------------------------------
    def start_recording(self) -> None:
        if not self.workers:
            QMessageBox.information(self, "Start", "Select at least one instance to monitor first.")
            return
        if self.state == STATE_IDLE:
            if self.store.load("default") is None and not any(self.store.load(k) for k in self.workers):
                if QMessageBox.question(
                        self, "Not calibrated",
                        "No calibration profile exists yet, so nothing will be recognized.\n"
                        "Start recording anyway?") != QMessageBox.StandardButton.Yes:
                    return
            self.session_id = self.db.start_session(note=", ".join(sorted(self.workers)))
            self.record_counts.clear()
            self.observations.set_session(self.session_id)
        for w in self.workers.values():
            w.reset_stabilizer()  # record what is on screen now, even if seen before pausing
        self.db.log_event(self.session_id, None, "start" if self.state == STATE_IDLE else "resume")
        self.state = STATE_RECORDING
        self._update_controls()

    def pause_recording(self) -> None:
        if self.state == STATE_RECORDING:
            self.state = STATE_PAUSED
            self.db.log_event(self.session_id, None, "pause")
            self._update_controls()

    def stop_recording(self) -> None:
        if self.session_id is not None:
            self.db.log_event(self.session_id, None, "stop")
            self.db.end_session(self.session_id)
        self.state = STATE_IDLE
        self._update_controls()
        self.statusBar().showMessage(f"Session {self.session_id} ended. Live view continues.", 6000)
        # Keep session_id so the table still shows the finished session until the next Start.

    # ---- other actions ----------------------------------------------------
    def export(self) -> None:
        keys = sorted({r["instance_key"] for r in self.db.observations()})
        dlg = ExportDialog(self.db.sessions(), keys, self.session_id, self)
        if dlg.exec() != ExportDialog.DialogCode.Accepted:
            return
        fmt = dlg.format.currentData()
        session, instance = dlg.session.currentData(), dlg.instance.currentData()
        default = f"observations_{datetime.now():%Y%m%d_%H%M%S}.{fmt}"
        path, _ = QFileDialog.getSaveFileName(self, "Export", str(Path(self.settings.data_dir) / default),
                                              "CSV (*.csv)" if fmt == "csv" else "JSON (*.json)")
        if not path:
            return
        try:
            fn = export_csv if fmt == "csv" else export_json
            n = fn(self.db, path, session_id=session, instance_key=instance)
        except OSError as exc:
            QMessageBox.critical(self, "Export failed", str(exc))
            return
        self.statusBar().showMessage(f"Exported {n} observation(s) to {path}", 8000)

    def manual_record(self) -> None:
        keys = sorted(self.panels)
        if not keys:
            QMessageBox.information(self, "Manual record", "Select an instance first.")
            return
        selected = self.observations.instance_filter.currentData()
        default = selected if selected in keys else keys[0]
        panel = self.panels[default]
        suggestion = panel.last_result.summary() if panel.last_result and panel.last_result.overlay_visible else ""
        dlg = ManualRecordDialog(keys, default, suggestion, self)
        if dlg.exec() != ManualRecordDialog.DialogCode.Accepted or not dlg.summary.text().strip():
            return
        key = dlg.instance.currentText()
        inst = self.workers[key].instance
        frame = self.panels[key].last_frame
        snapshot = self._save_snapshot(key, frame) if self.settings.save_snapshots else None
        self.db.add_manual_observation(self.session_id, key, inst.serial, inst.name,
                                       dlg.summary.text().strip(), dlg.note.toPlainText().strip(), snapshot)
        self.observations.reload()

    def calibrate(self) -> None:
        sources = {k: (lambda p=p: p.last_frame) for k, p in self.panels.items()}
        current = self.observations.instance_filter.currentData()
        initial = current if current in sources else (next(iter(sources), None))
        dlg = CalibrationDialog(self.store, self.templates, sources, initial, self)
        dlg.exec()
        self.templates.reload()
        for key, worker in self.workers.items():
            worker.set_profile(self.store.for_instance(key), self.templates)

    def open_settings(self) -> None:
        dlg = SettingsDialog(self.settings, self)
        if dlg.exec() != SettingsDialog.DialogCode.Accepted:
            return
        dlg.apply(self.settings)
        self.settings.save()
        self.adb.adb_path = self.settings.adb_path or self.adb.adb_path
        self.discovery = LdPlayerDiscovery(self.adb, self.settings.ldconsole_path or None)
        for w in self.workers.values():
            w.interval_ms = max(100, self.settings.capture_interval_ms)
            w.show_debug = self.settings.show_debug_overlay
        self.refresh_instances()

    def closeEvent(self, event) -> None:  # noqa: N802
        if self.state != STATE_IDLE:
            self.stop_recording()
        for key in list(self.workers):
            self._remove_worker(key)
        if self._discovery_thread:
            self._discovery_thread.wait(10000)
        self.settings.save()
        self.db.close()
        super().closeEvent(event)
