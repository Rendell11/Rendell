"""Interactive calibration of the overlay recognizer.

Workflow (start with ONE emulator):
  1. Grab a live frame where the prediction overlay is visible (or load a PNG).
  2. "Draw ROI" mode: drag a rectangle around the overlay; set rows/cols.
  3. "Pick color" mode: click inside each distinct tile color to add a color class
     and give it a tile type.
  4. "Select template" mode: drag around an icon or multiplier badge and save it.
  5. Test recognition, adjust thresholds, Save. Then verify on other instances.
"""
from __future__ import annotations

from collections.abc import Callable

import cv2
import numpy as np
from PySide6.QtCore import QPointF, QRectF, Qt, QUrl, Signal
from PySide6.QtGui import QColor, QDesktopServices, QImage, QPainter, QPen
from PySide6.QtWidgets import (QButtonGroup, QComboBox, QDialog, QDoubleSpinBox, QFileDialog,
                               QFormLayout, QGroupBox, QHBoxLayout, QInputDialog, QLabel,
                               QLineEdit, QMessageBox, QPlainTextEdit, QPushButton, QRadioButton,
                               QScrollArea, QSpinBox, QTableWidget, QTableWidgetItem, QVBoxLayout,
                               QWidget)

from ..capture import bgr_to_qimage
from ..vision import (CalibrationProfile, ColorClass, OverlayRecognizer, ProfileStore,
                      TemplateLibrary, draw_debug)

MODE_ROI, MODE_TEMPLATE, MODE_COLOR = "roi", "template", "color"


class ImageCanvas(QWidget):
    rect_drawn = Signal(QRectF)   # image pixel coordinates
    point_clicked = Signal(int, int)

    def __init__(self, parent=None):
        super().__init__(parent)
        self.image: QImage | None = None
        self.mode = MODE_ROI
        self.profile: CalibrationProfile | None = None
        self.selection: QRectF | None = None
        self._drag_start: QPointF | None = None
        self._drag_now: QPointF | None = None
        self.setMinimumSize(480, 360)
        self.setMouseTracking(True)

    def set_image(self, image: QImage | None) -> None:
        self.image = image
        self.update()

    # widget <-> image coordinate mapping
    def _geometry(self) -> tuple[float, float, float]:
        if self.image is None or self.image.width() == 0:
            return 1.0, 0.0, 0.0
        s = min(self.width() / self.image.width(), self.height() / self.image.height())
        ox = (self.width() - self.image.width() * s) / 2
        oy = (self.height() - self.image.height() * s) / 2
        return s, ox, oy

    def _to_image(self, p: QPointF) -> QPointF:
        s, ox, oy = self._geometry()
        x = min(max((p.x() - ox) / s, 0), self.image.width() - 1)
        y = min(max((p.y() - oy) / s, 0), self.image.height() - 1)
        return QPointF(x, y)

    def _to_widget(self, r: QRectF) -> QRectF:
        s, ox, oy = self._geometry()
        return QRectF(ox + r.x() * s, oy + r.y() * s, r.width() * s, r.height() * s)

    def paintEvent(self, _event) -> None:  # noqa: N802
        p = QPainter(self)
        p.fillRect(self.rect(), QColor("#111"))
        if self.image is None:
            p.setPen(QColor("#aaa"))
            p.drawText(self.rect(), Qt.AlignmentFlag.AlignCenter, "Grab a live frame or load an image")
            return
        s, ox, oy = self._geometry()
        p.drawImage(QRectF(ox, oy, self.image.width() * s, self.image.height() * s), self.image)
        if self.profile is not None:
            w, h = self.image.width(), self.image.height()
            p.setPen(QPen(QColor("#00e5ff"), 2))
            p.drawRect(self._to_widget(QRectF(*self.profile.roi_rect(w, h))))
            p.setPen(QPen(QColor(255, 255, 255, 140), 1, Qt.PenStyle.DashLine))
            for _r, _c, rect in self.profile.cell_rects(w, h):
                p.drawRect(self._to_widget(QRectF(*rect)))
        if self.selection is not None:
            p.setPen(QPen(QColor("#ffea00"), 2, Qt.PenStyle.DashLine))
            p.drawRect(self._to_widget(self.selection))
        if self._drag_start is not None and self._drag_now is not None:
            p.setPen(QPen(QColor("#ff4081"), 2))
            p.drawRect(self._to_widget(QRectF(self._drag_start, self._drag_now).normalized()))

    def mousePressEvent(self, e) -> None:  # noqa: N802
        if self.image is None or e.button() != Qt.MouseButton.LeftButton:
            return
        pt = self._to_image(e.position())
        if self.mode == MODE_COLOR:
            self.point_clicked.emit(int(pt.x()), int(pt.y()))
        else:
            self._drag_start = self._drag_now = pt

    def mouseMoveEvent(self, e) -> None:  # noqa: N802
        if self._drag_start is not None:
            self._drag_now = self._to_image(e.position())
            self.update()

    def mouseReleaseEvent(self, e) -> None:  # noqa: N802
        if self._drag_start is None:
            return
        rect = QRectF(self._drag_start, self._to_image(e.position())).normalized()
        self._drag_start = self._drag_now = None
        if rect.width() >= 4 and rect.height() >= 4:
            if self.mode == MODE_TEMPLATE:
                self.selection = rect
            self.rect_drawn.emit(rect)
        self.update()


class CalibrationDialog(QDialog):
    def __init__(self, store: ProfileStore, templates: TemplateLibrary,
                 frame_sources: dict[str, Callable[[], np.ndarray | None]],
                 initial_key: str | None = None, parent=None):
        super().__init__(parent)
        self.setWindowTitle("Calibrate overlay recognition")
        self.resize(1300, 820)
        self.store = store
        self.templates = templates
        self.frame_sources = frame_sources
        self.frame: np.ndarray | None = None
        self.profile = CalibrationProfile()
        self.saved_profile_name: str | None = None

        self.canvas = ImageCanvas()
        self.canvas.rect_drawn.connect(self._on_rect)
        self.canvas.point_clicked.connect(self._on_color_pick)

        # --- source ---
        self.source_combo = QComboBox()
        for key in frame_sources:
            self.source_combo.addItem(key, key)
        if initial_key:
            self.source_combo.setCurrentIndex(max(0, self.source_combo.findData(initial_key)))
        grab = QPushButton("Grab live frame")
        grab.clicked.connect(self._grab)
        load = QPushButton("Load image…")
        load.clicked.connect(self._load_image)
        save_img = QPushButton("Save frame…")
        save_img.clicked.connect(self._save_frame)
        src_box = QGroupBox("Frame source")
        src_l = QVBoxLayout(src_box)
        row = QHBoxLayout()
        row.addWidget(self.source_combo, 1)
        row.addWidget(grab)
        src_l.addLayout(row)
        row = QHBoxLayout()
        row.addWidget(load)
        row.addWidget(save_img)
        src_l.addLayout(row)

        # --- profile ---
        self.profile_name = QComboBox()
        self.profile_name.setEditable(True)
        names = ["default"] + [k for k in frame_sources]
        for p in sorted(store.dir.glob("*.json")):
            if p.stem not in names:
                names.append(p.stem)
        self.profile_name.addItems(names)
        load_profile = QPushButton("Load")
        load_profile.clicked.connect(self._load_profile)
        prof_box = QGroupBox("Profile ('default' applies to all; an instance key overrides it)")
        prof_l = QHBoxLayout(prof_box)
        prof_l.addWidget(self.profile_name, 1)
        prof_l.addWidget(load_profile)

        # --- mode ---
        mode_box = QGroupBox("Mouse mode")
        mode_l = QHBoxLayout(mode_box)
        self.mode_group = QButtonGroup(self)
        for label, mode in (("Draw ROI", MODE_ROI), ("Select template", MODE_TEMPLATE), ("Pick color", MODE_COLOR)):
            rb = QRadioButton(label)
            rb.setProperty("mode", mode)
            self.mode_group.addButton(rb)
            mode_l.addWidget(rb)
            if mode == MODE_ROI:
                rb.setChecked(True)
        self.mode_group.buttonToggled.connect(self._mode_changed)

        # --- grid & thresholds ---
        self.rows = QSpinBox(minimum=1, maximum=20)
        self.cols = QSpinBox(minimum=1, maximum=20)
        self.margin = QDoubleSpinBox(minimum=0, maximum=0.45, singleStep=0.02, decimals=2)
        self.min_color = QDoubleSpinBox(minimum=0.01, maximum=1, singleStep=0.05, decimals=2)
        self.full_color = QDoubleSpinBox(minimum=0.05, maximum=1, singleStep=0.05, decimals=2)
        self.icon_thr = QDoubleSpinBox(minimum=0.1, maximum=1, singleStep=0.02, decimals=2)
        self.mult_thr = QDoubleSpinBox(minimum=0.1, maximum=1, singleStep=0.02, decimals=2)
        self.uncertain_thr = QDoubleSpinBox(minimum=0, maximum=1, singleStep=0.05, decimals=2)
        self.min_cells = QSpinBox(minimum=1, maximum=400)
        self.stable = QSpinBox(minimum=1, maximum=20)
        self.mult_scope = QComboBox()
        self.mult_scope.addItems(["cell", "roi"])
        grid_box = QGroupBox("Grid and thresholds")
        form = QFormLayout(grid_box)
        form.addRow("Rows", self.rows)
        form.addRow("Columns", self.cols)
        form.addRow("Cell margin", self.margin)
        form.addRow("Min color coverage", self.min_color)
        form.addRow("Full-confidence coverage", self.full_color)
        form.addRow("Icon match threshold", self.icon_thr)
        form.addRow("Multiplier threshold", self.mult_thr)
        form.addRow("Multiplier search area", self.mult_scope)
        form.addRow("Uncertain below", self.uncertain_thr)
        form.addRow("Min cells = overlay visible", self.min_cells)
        form.addRow("Stable frames before record", self.stable)
        for w in (self.rows, self.cols, self.margin, self.min_color, self.full_color, self.icon_thr, self.mult_thr,
                  self.uncertain_thr, self.min_cells, self.stable):
            w.valueChanged.connect(self._form_to_profile)
        self.mult_scope.currentIndexChanged.connect(self._form_to_profile)

        # --- colors ---
        self.h_tol = QSpinBox(minimum=1, maximum=90, value=8)
        self.s_tol = QSpinBox(minimum=1, maximum=255, value=70)
        self.v_tol = QSpinBox(minimum=1, maximum=255, value=70)
        self.colors = QTableWidget(0, 4)
        self.colors.setHorizontalHeaderLabels(["Name", "Tile type", "Lower HSV", "Upper HSV"])
        self.colors.horizontalHeader().setStretchLastSection(True)
        self.colors.itemChanged.connect(self._colors_to_profile)
        rm_color = QPushButton("Remove selected color")
        rm_color.clicked.connect(self._remove_color)
        color_box = QGroupBox("Color classes (Pick color mode: click a tile)")
        color_l = QVBoxLayout(color_box)
        tol = QHBoxLayout()
        for lbl, w in (("H±", self.h_tol), ("S±", self.s_tol), ("V±", self.v_tol)):
            tol.addWidget(QLabel(lbl))
            tol.addWidget(w)
        color_l.addLayout(tol)
        color_l.addWidget(self.colors)
        color_l.addWidget(rm_color)

        # --- templates ---
        self.template_label = QLineEdit()
        self.template_label.setPlaceholderText("label, e.g. dice or x2")
        save_icon = QPushButton("Save selection as icon")
        save_icon.clicked.connect(lambda: self._save_template("icon"))
        save_mult = QPushButton("Save selection as multiplier")
        save_mult.clicked.connect(lambda: self._save_template("multiplier"))
        open_dir = QPushButton("Open templates folder")
        open_dir.clicked.connect(lambda: QDesktopServices.openUrl(QUrl.fromLocalFile(str(self.templates.root))))
        self.template_info = QLabel()
        tmpl_box = QGroupBox("Templates (Select template mode: drag around an icon)")
        tmpl_l = QVBoxLayout(tmpl_box)
        tmpl_l.addWidget(self.template_label)
        row = QHBoxLayout()
        row.addWidget(save_icon)
        row.addWidget(save_mult)
        tmpl_l.addLayout(row)
        tmpl_l.addWidget(open_dir)
        tmpl_l.addWidget(self.template_info)

        # --- test / save ---
        test = QPushButton("Test recognition")
        test.clicked.connect(self._test)
        save = QPushButton("Save profile")
        save.setDefault(True)
        save.clicked.connect(self._save_profile)
        close = QPushButton("Close")
        close.clicked.connect(self.accept)
        self.output = QPlainTextEdit(readOnly=True)
        self.output.setMaximumHeight(140)

        side = QWidget()
        side_l = QVBoxLayout(side)
        for w in (src_box, prof_box, mode_box, grid_box, color_box, tmpl_box):
            side_l.addWidget(w)
        row = QHBoxLayout()
        row.addWidget(test)
        row.addWidget(save)
        row.addWidget(close)
        side_l.addLayout(row)
        side_l.addStretch(1)
        scroll = QScrollArea()
        scroll.setWidget(side)
        scroll.setWidgetResizable(True)
        scroll.setMinimumWidth(430)

        left = QVBoxLayout()
        left.addWidget(self.canvas, 1)
        left.addWidget(self.output)
        main = QHBoxLayout(self)
        main.addLayout(left, 1)
        main.addWidget(scroll)

        self._loading = False
        self.profile_name.setCurrentText(initial_key if initial_key and store.load(initial_key) else "default")
        self._load_profile()
        self._update_template_info()
        if initial_key:
            self._grab()

    # ---- frame source -----------------------------------------------------
    def _set_frame(self, frame: np.ndarray) -> None:
        self.frame = frame
        self.canvas.selection = None
        self.canvas.set_image(bgr_to_qimage(frame))

    def _grab(self) -> None:
        key = self.source_combo.currentData()
        getter = self.frame_sources.get(key)
        frame = getter() if getter else None
        if frame is None:
            self.output.setPlainText(f"No live frame available from {key}. Is it connected and checked?")
            return
        self._set_frame(frame)
        self.output.setPlainText(f"Grabbed {frame.shape[1]}x{frame.shape[0]} frame from {key}.")

    def _load_image(self) -> None:
        path, _ = QFileDialog.getOpenFileName(self, "Load screenshot", "", "Images (*.png *.jpg *.jpeg *.bmp)")
        if path:
            img = cv2.imdecode(np.fromfile(path, np.uint8), cv2.IMREAD_COLOR)
            if img is None:
                QMessageBox.warning(self, "Load image", "Could not read that image.")
                return
            self._set_frame(img)

    def _save_frame(self) -> None:
        if self.frame is None:
            return
        path, _ = QFileDialog.getSaveFileName(self, "Save frame", "frame.png", "PNG (*.png)")
        if path:
            ok, buf = cv2.imencode(".png", self.frame)
            if ok:
                buf.tofile(path)

    # ---- profile <-> form -------------------------------------------------
    def _load_profile(self) -> None:
        name = self.profile_name.currentText().strip() or "default"
        loaded = self.store.load(name)
        self.profile = loaded or CalibrationProfile(name=name)
        self.profile.name = name
        self._profile_to_form()
        self.output.setPlainText(f"Loaded profile '{name}'." if loaded else f"New profile '{name}'.")

    def _profile_to_form(self) -> None:
        p = self.profile
        self._loading = True
        self.rows.setValue(p.rows)
        self.cols.setValue(p.cols)
        self.margin.setValue(p.cell_margin)
        self.min_color.setValue(p.min_color_fraction)
        self.full_color.setValue(p.full_color_fraction)
        self.icon_thr.setValue(p.icon_threshold)
        self.mult_thr.setValue(p.multiplier_threshold)
        self.uncertain_thr.setValue(p.uncertain_threshold)
        self.min_cells.setValue(p.overlay_min_cells)
        self.stable.setValue(p.stable_frames)
        self.mult_scope.setCurrentText(p.multiplier_scope)
        self.colors.setRowCount(len(p.color_classes))
        for r, cc in enumerate(p.color_classes):
            for c, v in enumerate((cc.name, cc.tile_type, ",".join(map(str, cc.lower)),
                                   ",".join(map(str, cc.upper)))):
                self.colors.setItem(r, c, QTableWidgetItem(v))
        self._loading = False
        self.canvas.profile = p
        self.canvas.update()

    def _form_to_profile(self, *_):
        if self._loading:
            return
        p = self.profile
        p.rows, p.cols = self.rows.value(), self.cols.value()
        p.cell_margin = self.margin.value()
        p.min_color_fraction = self.min_color.value()
        p.full_color_fraction = self.full_color.value()
        p.icon_threshold = self.icon_thr.value()
        p.multiplier_threshold = self.mult_thr.value()
        p.uncertain_threshold = self.uncertain_thr.value()
        p.overlay_min_cells = self.min_cells.value()
        p.stable_frames = self.stable.value()
        p.multiplier_scope = self.mult_scope.currentText()
        self.canvas.update()

    def _colors_to_profile(self, *_):
        if self._loading:
            return
        classes = []
        for r in range(self.colors.rowCount()):
            try:
                vals = [self.colors.item(r, c).text() if self.colors.item(r, c) else "" for c in range(4)]
                lower = [int(x) for x in vals[2].split(",")]
                upper = [int(x) for x in vals[3].split(",")]
                if len(lower) != 3 or len(upper) != 3:
                    raise ValueError
            except ValueError:
                self.output.setPlainText(f"Row {r + 1}: HSV must be three comma-separated integers.")
                return
            classes.append(ColorClass(name=vals[0] or f"color{r}", tile_type=vals[1], lower=lower, upper=upper))
        self.profile.color_classes = classes

    def _remove_color(self) -> None:
        rows = sorted({i.row() for i in self.colors.selectedIndexes()}, reverse=True)
        for r in rows:
            self.colors.removeRow(r)
        self._colors_to_profile()

    # ---- canvas interaction -----------------------------------------------
    def _mode_changed(self, button, checked) -> None:
        if checked:
            self.canvas.mode = button.property("mode")

    def _on_rect(self, rect: QRectF) -> None:
        if self.canvas.mode != MODE_ROI or self.frame is None:
            return
        h, w = self.frame.shape[:2]
        self.profile.roi = [rect.x() / w, rect.y() / h, rect.width() / w, rect.height() / h]
        self.canvas.update()

    def _on_color_pick(self, x: int, y: int) -> None:
        if self.frame is None:
            return
        patch = self.frame[max(0, y - 2):y + 3, max(0, x - 2):x + 3]
        hsv = cv2.cvtColor(patch, cv2.COLOR_BGR2HSV).reshape(-1, 3)
        median = np.median(hsv, axis=0).astype(int)
        name, ok = QInputDialog.getText(self, "New color class",
                                        f"HSV {tuple(median)} — name for this color:")
        if not ok or not name.strip():
            return
        tile_type, _ = QInputDialog.getText(self, "Tile type", "Tile type for this color (optional):")
        cc = ColorClass.from_sample(name.strip(), median, self.h_tol.value(), self.s_tol.value(),
                                    self.v_tol.value(), tile_type.strip())
        self.profile.color_classes.append(cc)
        self._profile_to_form()

    def _save_template(self, kind: str) -> None:
        sel = self.canvas.selection
        label = self.template_label.text().strip()
        if self.frame is None or sel is None:
            QMessageBox.information(self, "Template", "Use 'Select template' mode and drag a region first.")
            return
        if not label or any(ch in label for ch in r'\/:*?"<>|'):
            QMessageBox.information(self, "Template", "Enter a valid label (no path characters).")
            return
        x, y, w, h = int(sel.x()), int(sel.y()), int(sel.width()), int(sel.height())
        path = self.templates.save_template(kind, label, self.frame[y:y + h, x:x + w].copy())
        # Templates are now tied to this frame resolution.
        self.profile.reference_width = self.frame.shape[1]
        self.output.setPlainText(f"Saved {kind} template '{label}' -> {path}")
        self._update_template_info()

    def _update_template_info(self) -> None:
        t = self.templates
        self.template_info.setText(f"Icons: {', '.join(sorted(t.icons)) or 'none'}\n"
                                   f"Multipliers: {', '.join(sorted(t.multipliers)) or 'none'}")

    # ---- test / save -------------------------------------------------------
    def _test(self) -> None:
        if self.frame is None:
            self.output.setPlainText("No frame loaded.")
            return
        self._colors_to_profile()
        result = OverlayRecognizer(self.profile, self.templates).recognize(self.frame)
        self.canvas.selection = None
        self.canvas.set_image(bgr_to_qimage(draw_debug(self.frame, self.profile, result)))
        lines = [f"overlay_visible={result.overlay_visible} confidence={result.confidence:.2f} "
                 f"uncertain={result.uncertain} multiplier={result.multiplier}"]
        for c in result.cells:
            lines.append(f"  [{c.row},{c.col}] color={c.color}({c.color_conf:.2f}) tile={c.tile_type} "
                         f"icon={c.icon}({c.icon_conf:.2f}) mult={c.multiplier}({c.multiplier_conf:.2f})"
                         f"{' UNCERTAIN' if c.uncertain else ''}")
        self.output.setPlainText("\n".join(lines))

    def _save_profile(self) -> None:
        self._colors_to_profile()
        name = self.profile_name.currentText().strip() or "default"
        if any(ch in name for ch in r'\/:*?"<>|'):
            QMessageBox.warning(self, "Save", "Invalid profile name.")
            return
        self.profile.name = name
        if self.frame is not None and not self.profile.reference_width:
            self.profile.reference_width = self.frame.shape[1]
        path = self.store.save(self.profile)
        self.saved_profile_name = name
        self.output.setPlainText(f"Saved profile '{name}' -> {path}")
