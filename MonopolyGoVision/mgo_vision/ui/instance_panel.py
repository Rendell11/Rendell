"""Live-view panel for a single emulator instance."""
from __future__ import annotations

import time

from PySide6.QtCore import Qt, QTimer
from PySide6.QtGui import QImage, QPixmap
from PySide6.QtWidgets import QFrame, QLabel, QSizePolicy, QVBoxLayout

from ..capture import STATUS_DISCONNECTED, STATUS_LIVE

_STATUS_COLORS = {
    STATUS_LIVE: "#2e7d32",
    STATUS_DISCONNECTED: "#c62828",
}


class InstancePanel(QFrame):
    STALE_AFTER_S = 5.0

    def __init__(self, key: str, title: str, parent=None):
        super().__init__(parent)
        self.key = key
        self.title = title
        self.status = "connecting"
        self.last_frame = None  # raw BGR numpy frame
        self.last_result = None
        self._last_frame_at = 0.0
        self._pixmap: QPixmap | None = None

        self.setFrameShape(QFrame.Shape.StyledPanel)
        self.setMinimumSize(240, 200)

        self.header = QLabel()
        self.header.setTextFormat(Qt.TextFormat.RichText)
        self.view = QLabel("Waiting for frames…")
        self.view.setAlignment(Qt.AlignmentFlag.AlignCenter)
        self.view.setSizePolicy(QSizePolicy.Policy.Ignored, QSizePolicy.Policy.Ignored)
        self.view.setStyleSheet("background:#111; color:#aaa;")
        self.footer = QLabel("-")
        self.footer.setWordWrap(True)

        layout = QVBoxLayout(self)
        layout.setContentsMargins(4, 4, 4, 4)
        layout.addWidget(self.header)
        layout.addWidget(self.view, 1)
        layout.addWidget(self.footer)

        self._stale_timer = QTimer(self)
        self._stale_timer.timeout.connect(self._refresh_header)
        self._stale_timer.start(1000)
        self._refresh_header()

    def set_status(self, status: str, message: str = "") -> None:
        self.status = status
        self.setToolTip(message)
        self._refresh_header()

    def _refresh_header(self) -> None:
        color = _STATUS_COLORS.get(self.status, "#f9a825")
        status = self.status
        if self.status == STATUS_LIVE and self._last_frame_at:
            age = time.monotonic() - self._last_frame_at
            if age > self.STALE_AFTER_S:
                status, color = f"stale ({age:.0f}s)", "#f9a825"
        self.header.setText(f"<b>{self.title}</b> &nbsp;<span style='color:{color}'>● {status}</span>"
                            f" <small>[{self.key}]</small>")

    def update_frame(self, image: QImage, frame, result) -> None:
        self.last_frame, self.last_result = frame, result
        self._last_frame_at = time.monotonic()
        self._pixmap = QPixmap.fromImage(image)
        self._rescale()
        if result is None:
            self.footer.setText("<span style='color:#c62828'>recognition error</span>")
        elif not result.overlay_visible:
            self.footer.setText("No overlay detected")
        else:
            flag = " <b style='color:#ef6c00'>UNCERTAIN</b>" if result.uncertain else ""
            self.footer.setText(f"conf {result.confidence:.2f}{flag}: {result.summary()}")

    def _rescale(self) -> None:
        if self._pixmap is not None:
            self.view.setPixmap(self._pixmap.scaled(
                self.view.size(), Qt.AspectRatioMode.KeepAspectRatio,
                Qt.TransformationMode.SmoothTransformation))

    def resizeEvent(self, event) -> None:  # noqa: N802 (Qt API)
        super().resizeEvent(event)
        self._rescale()
