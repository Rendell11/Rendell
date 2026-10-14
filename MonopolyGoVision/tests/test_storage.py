import csv
import json

from conftest import GREEN, RED, make_frame

from mgo_vision.storage import Database, export_csv, export_json
from mgo_vision.vision import OverlayRecognizer


def test_record_correct_export(profile, tmp_path):
    db = Database(tmp_path / "t.sqlite3")
    sid = db.start_session("ld0")
    result = OverlayRecognizer(profile).recognize(make_frame({(0, 0): GREEN, (1, 2): RED}))
    obs_id = db.add_observation(sid, "ld0", "emulator-5554", "LDPlayer", result)
    db.add_manual_observation(sid, "ld1", "emulator-5556", "LD-2", "cash x2", note="seen by eye")

    db.correct_observation(obs_id, "corrected_summary", "cash/x3")
    db.correct_observation(obs_id, "status", "verified")
    cell = db.cells(obs_id)[0]
    db.correct_cell(cell["id"], "cash/x3")

    obs = db.observation(obs_id)
    assert obs["summary"].startswith("[0,0] cash")  # original kept
    assert obs["corrected_summary"] == "cash/x3" and obs["status"] == "verified"
    assert [c["field"] for c in db.corrections(obs_id)] == ["corrected_summary", "status", "corrected_label"]
    assert len(db.observations(sid, "ld0")) == 1

    n = export_json(db, tmp_path / "o.json", session_id=sid)
    data = json.loads((tmp_path / "o.json").read_text())
    assert n == 2 and len(data[0]["cells"]) == 2 and data[1]["source"] == "manual"

    export_csv(db, tmp_path / "o.csv", session_id=sid)
    with open(tmp_path / "o.csv", encoding="utf-8-sig") as fh:
        rows = list(csv.DictReader(fh))
    assert len(rows) == 3  # 2 cell rows + 1 manual row without cells
    assert rows[0]["cell_tile_type"] == "cash" and rows[0]["cell_corrected_label"] == "cash/x3"
    db.end_session(sid)
    assert db.sessions()[0]["ended_at"]


def test_rejects_unknown_field(tmp_path):
    import pytest
    db = Database(":memory:")
    with pytest.raises(ValueError):
        db.correct_observation(1, "summary; DROP TABLE observations", "x")
