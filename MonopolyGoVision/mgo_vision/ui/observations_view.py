"""Table of recorded observations with manual review and correction."""
from __future__ import annotations

from pathlib import Path

from PySide6.QtCore import Qt, QTimer
from PySide6.QtGui import QColor, QPixmap
from PySide6.QtWidgets import (QCheckBox, QComboBox, QHBoxLayout, QHeaderView, QLabel, QPushButton,
                               QSplitter, QTableWidget, QTableWidgetItem, QVBoxLayout, QWidget)

from ..storage import Database

OBS_COLUMNS = [
    ("id", "ID"), ("timestamp", "Time"), ("instance_key", "Instance"), ("source", "Source"),
    ("summary", "Detected"), ("confidence", "Conf"), ("uncertain", "Uncertain"),
    ("status", "Status"), ("corrected_summary", "Correction"), ("note", "Note"),
]
EDITABLE_OBS = {"corrected_summary", "note"}
CELL_COLUMNS = [
    ("row", "Row"), ("col", "Col"), ("color", "Color"), ("tile_type", "Tile"), ("icon", "Icon"),
    ("multiplier", "Mult"), ("color_conf", "Color conf"), ("icon_conf", "Icon conf"),
    ("uncertain", "Uncertain"), ("corrected_label", "Correction"),
]
UNCERTAIN_BG = QColor(255, 224, 178)
REJECTED_BG = QColor(230, 230, 230)
VERIFIED_BG = QColor(200, 230, 201)


def _fmt(value) -> str:
    if value is None:
        return ""
    if isinstance(value, float):
        return f"{value:.2f}"
    return str(value)


class ObservationsView(QWidget):
    ROW_LIMIT = 1000

    def __init__(self, db: Database, parent=None):
        super().__init__(parent)
        self.db = db
        self.session_id: int | None = None
        self._loading = False
        self._refresh_timer = QTimer(self, singleShot=True, interval=500)
        self._refresh_timer.timeout.connect(self.reload)

        # Filters
        self.instance_filter = QComboBox()
        self.instance_filter.addItem("All instances", None)
        self.instance_filter.currentIndexChanged.connect(self.reload)
        self.session_only = QCheckBox("Current session only")
        self.session_only.setChecked(True)
        self.session_only.toggled.connect(self.reload)
        self.uncertain_only = QCheckBox("Uncertain / pending review only")
        self.uncertain_only.toggled.connect(self.reload)
        verify_btn = QPushButton("Mark verified")
        verify_btn.clicked.connect(lambda: self._set_status("verified"))
        reject_btn = QPushButton("Mark rejected")
        reject_btn.clicked.connect(lambda: self._set_status("rejected"))
        pending_btn = QPushButton("Reset to pending")
        pending_btn.clicked.connect(lambda: self._set_status("pending"))
        filters = QHBoxLayout()
        for w in (QLabel("Show:"), self.instance_filter, self.session_only, self.uncertain_only):
            filters.addWidget(w)
        filters.addStretch(1)
        for w in (verify_btn, reject_btn, pending_btn):
            filters.addWidget(w)

        # Observation table
        self.table = QTableWidget(0, len(OBS_COLUMNS))
        self.table.setHorizontalHeaderLabels([c[1] for c in OBS_COLUMNS])
        self.table.setSelectionBehavior(QTableWidget.SelectionBehavior.SelectRows)
        self.table.horizontalHeader().setSectionResizeMode(4, QHeaderView.ResizeMode.Stretch)
        self.table.verticalHeader().setVisible(False)
        self.table.itemChanged.connect(self._obs_edited)
        self.table.itemSelectionChanged.connect(self._show_details)

        # Detail: cells + snapshot
        self.cells = QTableWidget(0, len(CELL_COLUMNS))
        self.cells.setHorizontalHeaderLabels([c[1] for c in CELL_COLUMNS])
        self.cells.verticalHeader().setVisible(False)
        self.cells.itemChanged.connect(self._cell_edited)
        self.snapshot = QLabel("Select an observation")
        self.snapshot.setAlignment(Qt.AlignmentFlag.AlignCenter)
        self.snapshot.setMinimumWidth(200)
        detail = QSplitter(Qt.Orientation.Horizontal)
        detail.addWidget(self.cells)
        detail.addWidget(self.snapshot)
        detail.setStretchFactor(0, 3)
        detail.setStretchFactor(1, 2)

        split = QSplitter(Qt.Orientation.Vertical)
        split.addWidget(self.table)
        split.addWidget(detail)
        split.setStretchFactor(0, 3)
        split.setStretchFactor(1, 2)

        hint = QLabel("Double-click <i>Correction</i> or <i>Note</i> to edit. Original detections are "
                      "kept; every edit is logged in the corrections table.")
        layout = QVBoxLayout(self)
        layout.addLayout(filters)
        layout.addWidget(split, 1)
        layout.addWidget(hint)

    # ---- public -----------------------------------------------------------
    def set_session(self, session_id: int | None) -> None:
        self.session_id = session_id
        self.reload()

    def set_instance_keys(self, keys: list[str]) -> None:
        current = self.instance_filter.currentData()
        self.instance_filter.blockSignals(True)
        self.instance_filter.clear()
        self.instance_filter.addItem("All instances", None)
        for k in keys:
            self.instance_filter.addItem(k, k)
        idx = self.instance_filter.findData(current)
        self.instance_filter.setCurrentIndex(max(0, idx))
        self.instance_filter.blockSignals(False)

    def schedule_reload(self) -> None:
        if not self._refresh_timer.isActive():
            self._refresh_timer.start()

    def selected_ids(self) -> list[int]:
        rows = {i.row() for i in self.table.selectedIndexes()}
        return [int(self.table.item(r, 0).text()) for r in sorted(rows)]

    # ---- loading ----------------------------------------------------------
    def reload(self) -> None:
        selected = set(self.selected_ids())
        session = self.session_id if self.session_only.isChecked() else None
        if self.session_only.isChecked() and session is None:
            rows = []
        else:
            rows = self.db.observations(session, self.instance_filter.currentData(), self.ROW_LIMIT)
        if self.uncertain_only.isChecked():
            rows = [r for r in rows if r["status"] == "pending" and (r["uncertain"] or r["confidence"] < 0.6)]

        self._loading = True
        self.table.setRowCount(len(rows))
        for r, row in enumerate(rows):
            bg = None
            if row["status"] == "rejected":
                bg = REJECTED_BG
            elif row["status"] == "verified":
                bg = VERIFIED_BG
            elif row["uncertain"]:
                bg = UNCERTAIN_BG
            for c, (field, _) in enumerate(OBS_COLUMNS):
                value = row[field]
                if field == "uncertain":
                    value = "yes" if value else ""
                item = QTableWidgetItem(_fmt(value))
                flags = item.flags()
                if field not in EDITABLE_OBS:
                    flags &= ~Qt.ItemFlag.ItemIsEditable
                item.setFlags(flags)
                if bg is not None:
                    item.setBackground(bg)
                self.table.setItem(r, c, item)
            if row["id"] in selected:
                self.table.selectRow(r)
        self.table.resizeColumnsToContents()
        self._loading = False

    def _show_details(self) -> None:
        ids = self.selected_ids()
        self._loading = True
        self.cells.setRowCount(0)
        self._loading = False
        if len(ids) != 1:
            self.snapshot.setText("Select one observation")
            return
        obs = self.db.observation(ids[0])
        cells = self.db.cells(ids[0])
        self._loading = True
        self.cells.setRowCount(len(cells))
        for r, cell in enumerate(cells):
            for c, (field, _) in enumerate(CELL_COLUMNS):
                value = cell[field]
                if field == "uncertain":
                    value = "yes" if value else ""
                item = QTableWidgetItem(_fmt(value))
                item.setData(Qt.ItemDataRole.UserRole, cell["id"])
                if field != "corrected_label":
                    item.setFlags(item.flags() & ~Qt.ItemFlag.ItemIsEditable)
                if cell["uncertain"]:
                    item.setBackground(UNCERTAIN_BG)
                self.cells.setItem(r, c, item)
        self.cells.resizeColumnsToContents()
        self._loading = False

        path = obs["snapshot_path"] if obs else None
        if path and Path(path).is_file():
            pix = QPixmap(path)
            self.snapshot.setPixmap(pix.scaled(self.snapshot.size(), Qt.AspectRatioMode.KeepAspectRatio,
                                               Qt.TransformationMode.SmoothTransformation))
        else:
            self.snapshot.setText("No snapshot")

    # ---- edits ------------------------------------------------------------
    def _obs_edited(self, item: QTableWidgetItem) -> None:
        if self._loading:
            return
        field = OBS_COLUMNS[item.column()][0]
        if field not in EDITABLE_OBS:
            return
        obs_id = int(self.table.item(item.row(), 0).text())
        value = item.text().strip() or None
        self.db.correct_observation(obs_id, field, value if field != "note" else (value or ""))
        if field == "corrected_summary" and value:
            # A manual correction implies the record has been reviewed.
            self.db.correct_observation(obs_id, "status", "verified")
        self.schedule_reload()

    def _cell_edited(self, item: QTableWidgetItem) -> None:
        if self._loading or CELL_COLUMNS[item.column()][0] != "corrected_label":
            return
        self.db.correct_cell(int(item.data(Qt.ItemDataRole.UserRole)), item.text().strip() or None)

    def _set_status(self, status: str) -> None:
        for obs_id in self.selected_ids():
            self.db.correct_observation(obs_id, "status", status)
        self.reload()
