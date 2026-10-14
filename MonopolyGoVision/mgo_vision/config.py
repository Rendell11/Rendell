"""Application settings, stored as JSON next to the data directory."""
from __future__ import annotations

import json
import logging
from dataclasses import asdict, dataclass, field
from pathlib import Path

log = logging.getLogger(__name__)

APP_ROOT = Path(__file__).resolve().parent.parent
DEFAULT_DATA_DIR = APP_ROOT / "data"
DEFAULT_CALIBRATION_DIR = APP_ROOT / "calibration"


@dataclass
class AppSettings:
    adb_path: str = ""  # empty = find `adb` on PATH (LDPlayer ships adb.exe in its install folder)
    ldconsole_path: str = ""  # empty = auto-detect common LDPlayer install paths
    capture_interval_ms: int = 500
    failures_before_disconnect: int = 3
    max_reconnect_backoff_s: float = 10.0
    save_snapshots: bool = True
    show_debug_overlay: bool = True
    data_dir: str = str(DEFAULT_DATA_DIR)
    calibration_dir: str = str(DEFAULT_CALIBRATION_DIR)
    selected_instances: list[str] = field(default_factory=list)

    @property
    def db_path(self) -> Path:
        return Path(self.data_dir) / "observations.sqlite3"

    @property
    def snapshot_dir(self) -> Path:
        return Path(self.data_dir) / "snapshots"

    @property
    def template_dir(self) -> Path:
        return Path(self.calibration_dir) / "templates"

    @staticmethod
    def settings_path() -> Path:
        return DEFAULT_DATA_DIR / "settings.json"

    @classmethod
    def load(cls) -> "AppSettings":
        path = cls.settings_path()
        if path.is_file():
            try:
                data = json.loads(path.read_text(encoding="utf-8"))
                known = set(cls.__dataclass_fields__)
                return cls(**{k: v for k, v in data.items() if k in known})
            except (ValueError, TypeError) as exc:
                log.error("Ignoring invalid settings file %s: %s", path, exc)
        return cls()

    def save(self) -> None:
        path = Path(self.data_dir) / "settings.json"
        path.parent.mkdir(parents=True, exist_ok=True)
        path.write_text(json.dumps(asdict(self), indent=2), encoding="utf-8")
