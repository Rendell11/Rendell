"""Small dialogs: manual observation entry and export options."""
from __future__ import annotations

from PySide6.QtWidgets import (QCheckBox, QComboBox, QDialog, QDialogButtonBox, QFormLayout,
                               QLineEdit, QPlainTextEdit, QSpinBox)


class ManualRecordDialog(QDialog):
    def __init__(self, instance_keys: list[str], default_key: str | None = None,
                 suggestion: str = "", parent=None):
        super().__init__(parent)
        self.setWindowTitle("Manual observation")
        self.instance = QComboBox()
        self.instance.addItems(instance_keys)
        if default_key in instance_keys:
            self.instance.setCurrentText(default_key)
        self.summary = QLineEdit(suggestion)
        self.summary.setPlaceholderText("What is shown, e.g. [0,1] cash/x3, [0,2] railroad")
        self.note = QPlainTextEdit()
        self.note.setMaximumHeight(80)
        buttons = QDialogButtonBox(QDialogButtonBox.StandardButton.Ok | QDialogButtonBox.StandardButton.Cancel)
        buttons.accepted.connect(self.accept)
        buttons.rejected.connect(self.reject)
        form = QFormLayout(self)
        form.addRow("Instance", self.instance)
        form.addRow("Observation", self.summary)
        form.addRow("Note", self.note)
        form.addRow(buttons)
        self.resize(520, 220)


class ExportDialog(QDialog):
    def __init__(self, sessions: list, instance_keys: list[str], current_session: int | None, parent=None):
        super().__init__(parent)
        self.setWindowTitle("Export observations")
        self.format = QComboBox()
        self.format.addItem("CSV (one row per cell)", "csv")
        self.format.addItem("JSON (cells nested)", "json")
        self.session = QComboBox()
        self.session.addItem("All sessions", None)
        for s in sessions:
            label = f"Session {s['id']} — {s['started_at'][:19]}"
            if s["note"]:
                label += f" ({s['note']})"
            self.session.addItem(label, s["id"])
        if current_session is not None:
            self.session.setCurrentIndex(max(0, self.session.findData(current_session)))
        self.instance = QComboBox()
        self.instance.addItem("All instances", None)
        for k in instance_keys:
            self.instance.addItem(k, k)
        buttons = QDialogButtonBox(QDialogButtonBox.StandardButton.Ok | QDialogButtonBox.StandardButton.Cancel)
        buttons.accepted.connect(self.accept)
        buttons.rejected.connect(self.reject)
        form = QFormLayout(self)
        form.addRow("Format", self.format)
        form.addRow("Session", self.session)
        form.addRow("Instance", self.instance)
        form.addRow(buttons)


class SettingsDialog(QDialog):
    def __init__(self, settings, parent=None):
        super().__init__(parent)
        self.setWindowTitle("Settings")
        self.adb_path = QLineEdit(settings.adb_path)
        self.adb_path.setPlaceholderText("auto (adb on PATH), e.g. C:\\LDPlayer\\LDPlayer9\\adb.exe")
        self.ldconsole_path = QLineEdit(settings.ldconsole_path)
        self.ldconsole_path.setPlaceholderText("auto-detect, e.g. C:\\LDPlayer\\LDPlayer9\\ldconsole.exe")
        self.interval = QSpinBox(minimum=100, maximum=10000, singleStep=100, suffix=" ms")
        self.interval.setValue(settings.capture_interval_ms)
        self.snapshots = QCheckBox("Save a snapshot image with each recorded observation")
        self.snapshots.setChecked(settings.save_snapshots)
        self.debug = QCheckBox("Draw ROI/grid/labels on live view")
        self.debug.setChecked(settings.show_debug_overlay)
        buttons = QDialogButtonBox(QDialogButtonBox.StandardButton.Ok | QDialogButtonBox.StandardButton.Cancel)
        buttons.accepted.connect(self.accept)
        buttons.rejected.connect(self.reject)
        form = QFormLayout(self)
        form.addRow("adb executable", self.adb_path)
        form.addRow("ldconsole executable", self.ldconsole_path)
        form.addRow("Capture interval", self.interval)
        form.addRow(self.snapshots)
        form.addRow(self.debug)
        form.addRow(buttons)
        self.resize(560, 220)

    def apply(self, settings) -> None:
        settings.adb_path = self.adb_path.text().strip()
        settings.ldconsole_path = self.ldconsole_path.text().strip()
        settings.capture_interval_ms = self.interval.value()
        settings.save_snapshots = self.snapshots.isChecked()
        settings.show_debug_overlay = self.debug.isChecked()
