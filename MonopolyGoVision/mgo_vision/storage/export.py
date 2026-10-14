"""CSV / JSON export of recorded observations."""
from __future__ import annotations

import csv
import json
from pathlib import Path

from .db import Database

OBS_FIELDS = [
    "id", "session_id", "instance_key", "serial", "instance_name", "timestamp", "source",
    "overlay_visible", "summary", "multiplier", "confidence", "uncertain", "status",
    "corrected_summary", "note", "profile", "snapshot_path",
]
CELL_FIELDS = [
    "row", "col", "color", "color_conf", "tile_type", "icon", "icon_conf",
    "multiplier", "multiplier_conf", "uncertain", "corrected_label",
]


def export_json(db: Database, path: Path | str, session_id: int | None = None,
                instance_key: str | None = None) -> int:
    rows = db.export_rows(session_id=session_id, instance_key=instance_key)
    Path(path).write_text(json.dumps(rows, indent=2, ensure_ascii=False), encoding="utf-8")
    return len(rows)


def export_csv(db: Database, path: Path | str, session_id: int | None = None,
               instance_key: str | None = None) -> int:
    """One row per recognized cell; observations without cells get one row."""
    rows = db.export_rows(session_id=session_id, instance_key=instance_key)
    header = OBS_FIELDS + [f"cell_{f}" for f in CELL_FIELDS]
    with open(path, "w", newline="", encoding="utf-8-sig") as fh:
        writer = csv.writer(fh)
        writer.writerow(header)
        for obs in rows:
            base = [obs.get(f) for f in OBS_FIELDS]
            cells = obs["cells"] or [None]
            for cell in cells:
                writer.writerow(base + [cell.get(f) if cell else None for f in CELL_FIELDS])
    return len(rows)
