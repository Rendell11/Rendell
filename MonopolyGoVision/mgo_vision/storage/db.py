"""SQLite persistence for sessions, observations, per-cell results,
manual corrections and connection events.

Original recognizer output is never overwritten: corrections go to
`corrected_*` columns and every change is logged in `corrections`.
"""
from __future__ import annotations

import json
import sqlite3
import threading
from datetime import datetime, timezone
from pathlib import Path

from ..vision.models import FrameResult

SCHEMA = """
CREATE TABLE IF NOT EXISTS sessions (
    id INTEGER PRIMARY KEY AUTOINCREMENT,
    started_at TEXT NOT NULL,
    ended_at TEXT,
    note TEXT DEFAULT ''
);
CREATE TABLE IF NOT EXISTS observations (
    id INTEGER PRIMARY KEY AUTOINCREMENT,
    session_id INTEGER REFERENCES sessions(id),
    instance_key TEXT NOT NULL,
    serial TEXT NOT NULL,
    instance_name TEXT DEFAULT '',
    timestamp TEXT NOT NULL,
    source TEXT NOT NULL DEFAULT 'auto',          -- auto | manual
    overlay_visible INTEGER NOT NULL DEFAULT 0,
    summary TEXT DEFAULT '',
    multiplier TEXT,
    confidence REAL DEFAULT 0,
    uncertain INTEGER NOT NULL DEFAULT 0,
    status TEXT NOT NULL DEFAULT 'pending',       -- pending | verified | rejected
    corrected_summary TEXT,
    note TEXT DEFAULT '',
    profile TEXT DEFAULT '',
    frame_width INTEGER,
    frame_height INTEGER,
    snapshot_path TEXT
);
CREATE INDEX IF NOT EXISTS idx_obs_instance ON observations(instance_key, timestamp);
CREATE INDEX IF NOT EXISTS idx_obs_session ON observations(session_id);
CREATE TABLE IF NOT EXISTS cells (
    id INTEGER PRIMARY KEY AUTOINCREMENT,
    observation_id INTEGER NOT NULL REFERENCES observations(id) ON DELETE CASCADE,
    row INTEGER NOT NULL,
    col INTEGER NOT NULL,
    color TEXT, color_conf REAL,
    tile_type TEXT,
    icon TEXT, icon_conf REAL,
    multiplier TEXT, multiplier_conf REAL,
    uncertain INTEGER NOT NULL DEFAULT 0,
    corrected_label TEXT
);
CREATE INDEX IF NOT EXISTS idx_cells_obs ON cells(observation_id);
CREATE TABLE IF NOT EXISTS corrections (
    id INTEGER PRIMARY KEY AUTOINCREMENT,
    observation_id INTEGER NOT NULL REFERENCES observations(id) ON DELETE CASCADE,
    cell_id INTEGER,
    field TEXT NOT NULL,
    old_value TEXT,
    new_value TEXT,
    corrected_at TEXT NOT NULL
);
CREATE TABLE IF NOT EXISTS events (
    id INTEGER PRIMARY KEY AUTOINCREMENT,
    session_id INTEGER,
    instance_key TEXT,
    timestamp TEXT NOT NULL,
    kind TEXT NOT NULL,
    message TEXT DEFAULT ''
);
"""

OBS_EDITABLE = {"status", "corrected_summary", "note"}
CELL_EDITABLE = {"corrected_label"}


def _now() -> str:
    return datetime.now(timezone.utc).astimezone().isoformat(timespec="milliseconds")


class Database:
    def __init__(self, path: Path | str):
        self.path = str(path)
        if self.path != ":memory:":
            Path(self.path).parent.mkdir(parents=True, exist_ok=True)
        self._lock = threading.RLock()
        self.conn = sqlite3.connect(self.path, check_same_thread=False)
        self.conn.row_factory = sqlite3.Row
        self.conn.execute("PRAGMA foreign_keys = ON")
        if self.path != ":memory:":
            self.conn.execute("PRAGMA journal_mode = WAL")
        self.conn.executescript(SCHEMA)
        self.conn.commit()

    def close(self) -> None:
        with self._lock:
            self.conn.close()

    # ---- sessions ---------------------------------------------------------
    def start_session(self, note: str = "") -> int:
        with self._lock, self.conn:
            cur = self.conn.execute("INSERT INTO sessions(started_at, note) VALUES (?, ?)", (_now(), note))
            return int(cur.lastrowid)

    def end_session(self, session_id: int) -> None:
        with self._lock, self.conn:
            self.conn.execute("UPDATE sessions SET ended_at=? WHERE id=?", (_now(), session_id))

    def sessions(self) -> list[sqlite3.Row]:
        with self._lock:
            return self.conn.execute("SELECT * FROM sessions ORDER BY id DESC").fetchall()

    # ---- observations -----------------------------------------------------
    def add_observation(self, session_id: int | None, instance_key: str, serial: str,
                        instance_name: str, result: FrameResult,
                        snapshot_path: str | None = None) -> int:
        with self._lock, self.conn:
            cur = self.conn.execute(
                """INSERT INTO observations(session_id, instance_key, serial, instance_name, timestamp,
                       source, overlay_visible, summary, multiplier, confidence, uncertain, profile,
                       frame_width, frame_height, snapshot_path)
                   VALUES (?,?,?,?,?, 'auto', ?,?,?,?,?,?,?,?,?)""",
                (session_id, instance_key, serial, instance_name, result.timestamp,
                 int(result.overlay_visible), result.summary(), result.multiplier, result.confidence,
                 int(result.uncertain), result.profile, result.frame_size[0], result.frame_size[1],
                 snapshot_path),
            )
            obs_id = int(cur.lastrowid)
            self.conn.executemany(
                """INSERT INTO cells(observation_id, row, col, color, color_conf, tile_type, icon,
                       icon_conf, multiplier, multiplier_conf, uncertain)
                   VALUES (?,?,?,?,?,?,?,?,?,?,?)""",
                [(obs_id, c.row, c.col, c.color, c.color_conf, c.tile_type, c.icon, c.icon_conf,
                  c.multiplier, c.multiplier_conf, int(c.uncertain)) for c in result.occupied_cells()],
            )
            return obs_id

    def add_manual_observation(self, session_id: int | None, instance_key: str, serial: str,
                               instance_name: str, summary: str, note: str = "",
                               snapshot_path: str | None = None) -> int:
        with self._lock, self.conn:
            cur = self.conn.execute(
                """INSERT INTO observations(session_id, instance_key, serial, instance_name, timestamp,
                       source, overlay_visible, summary, confidence, uncertain, status, note, snapshot_path)
                   VALUES (?,?,?,?,?, 'manual', 1, ?, 1.0, 0, 'verified', ?, ?)""",
                (session_id, instance_key, serial, instance_name, _now(), summary, note, snapshot_path),
            )
            return int(cur.lastrowid)

    def observations(self, session_id: int | None = None, instance_key: str | None = None,
                     limit: int | None = None) -> list[sqlite3.Row]:
        sql = "SELECT * FROM observations WHERE 1=1"
        args: list = []
        if session_id is not None:
            sql += " AND session_id=?"
            args.append(session_id)
        if instance_key:
            sql += " AND instance_key=?"
            args.append(instance_key)
        sql += " ORDER BY id DESC"
        if limit:
            sql += f" LIMIT {int(limit)}"
        with self._lock:
            return self.conn.execute(sql, args).fetchall()

    def observation(self, obs_id: int) -> sqlite3.Row | None:
        with self._lock:
            return self.conn.execute("SELECT * FROM observations WHERE id=?", (obs_id,)).fetchone()

    def cells(self, obs_id: int) -> list[sqlite3.Row]:
        with self._lock:
            return self.conn.execute(
                "SELECT * FROM cells WHERE observation_id=? ORDER BY row, col", (obs_id,)).fetchall()

    # ---- corrections ------------------------------------------------------
    def correct_observation(self, obs_id: int, field: str, value: str | None) -> None:
        if field not in OBS_EDITABLE:
            raise ValueError(f"field '{field}' is not editable")
        with self._lock, self.conn:
            row = self.conn.execute(f"SELECT {field} FROM observations WHERE id=?", (obs_id,)).fetchone()
            if row is None:
                raise KeyError(obs_id)
            if row[0] == value:
                return
            self.conn.execute(f"UPDATE observations SET {field}=? WHERE id=?", (value, obs_id))
            self.conn.execute(
                "INSERT INTO corrections(observation_id, field, old_value, new_value, corrected_at) VALUES (?,?,?,?,?)",
                (obs_id, field, row[0], value, _now()))

    def correct_cell(self, cell_id: int, value: str | None) -> None:
        with self._lock, self.conn:
            row = self.conn.execute(
                "SELECT observation_id, corrected_label FROM cells WHERE id=?", (cell_id,)).fetchone()
            if row is None:
                raise KeyError(cell_id)
            if row["corrected_label"] == value:
                return
            self.conn.execute("UPDATE cells SET corrected_label=? WHERE id=?", (value, cell_id))
            self.conn.execute(
                """INSERT INTO corrections(observation_id, cell_id, field, old_value, new_value, corrected_at)
                   VALUES (?,?, 'corrected_label', ?,?,?)""",
                (row["observation_id"], cell_id, row["corrected_label"], value, _now()))

    def corrections(self, obs_id: int) -> list[sqlite3.Row]:
        with self._lock:
            return self.conn.execute(
                "SELECT * FROM corrections WHERE observation_id=? ORDER BY id", (obs_id,)).fetchall()

    # ---- events -----------------------------------------------------------
    def log_event(self, session_id: int | None, instance_key: str | None, kind: str, message: str = "") -> None:
        with self._lock, self.conn:
            self.conn.execute(
                "INSERT INTO events(session_id, instance_key, timestamp, kind, message) VALUES (?,?,?,?,?)",
                (session_id, instance_key, _now(), kind, message))

    def events(self, session_id: int | None = None) -> list[sqlite3.Row]:
        sql, args = "SELECT * FROM events", []
        if session_id is not None:
            sql, args = sql + " WHERE session_id=?", [session_id]
        with self._lock:
            return self.conn.execute(sql + " ORDER BY id", args).fetchall()

    # ---- export helpers ---------------------------------------------------
    def export_rows(self, session_id: int | None = None, instance_key: str | None = None) -> list[dict]:
        """Observations (oldest first) with their cells nested under 'cells'."""
        obs = list(reversed(self.observations(session_id, instance_key)))
        out = []
        for o in obs:
            d = dict(o)
            d["cells"] = [dict(c) for c in self.cells(o["id"])]
            out.append(d)
        return out

    def dumps(self, **kw) -> str:
        return json.dumps(self.export_rows(**kw), indent=2)
