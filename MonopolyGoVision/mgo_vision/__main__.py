"""Entry point: `python -m mgo_vision`."""
from __future__ import annotations

import logging
import sys
from logging.handlers import RotatingFileHandler
from pathlib import Path

from PySide6.QtWidgets import QApplication

from .config import AppSettings
from .ui import MainWindow


def _setup_logging(data_dir: Path) -> None:
    data_dir.mkdir(parents=True, exist_ok=True)
    handlers = [logging.StreamHandler(),
                RotatingFileHandler(data_dir / "app.log", maxBytes=2_000_000, backupCount=3, encoding="utf-8")]
    logging.basicConfig(level=logging.INFO, handlers=handlers,
                        format="%(asctime)s %(levelname)s %(name)s: %(message)s")


def main() -> int:
    settings = AppSettings.load()
    _setup_logging(Path(settings.data_dir))
    app = QApplication(sys.argv)
    app.setApplicationName("Monopoly GO Live Vision Recorder")
    window = MainWindow(settings)
    window.show()
    return app.exec()


if __name__ == "__main__":
    sys.exit(main())
