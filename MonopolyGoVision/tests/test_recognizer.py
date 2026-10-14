from conftest import BLUE, GREEN, RED, icon_template, make_frame

from mgo_vision.vision import (CalibrationProfile, ObservationStabilizer, OverlayRecognizer,
                               TemplateLibrary)


def test_no_overlay(profile):
    result = OverlayRecognizer(profile).recognize(make_frame({}))
    assert not result.overlay_visible
    assert result.signature() == "none"


def test_colors_and_positions(profile):
    frame = make_frame({(0, 0): GREEN, (0, 2): RED, (1, 1): BLUE})
    result = OverlayRecognizer(profile).recognize(frame)
    assert result.overlay_visible
    got = {(c.row, c.col): (c.color, c.tile_type) for c in result.occupied_cells()}
    assert got == {(0, 0): ("green", "cash"), (0, 2): ("red", "danger"), (1, 1): ("blue", "bonus")}
    assert result.confidence > 0.8 and not result.uncertain


def test_partial_coverage_is_uncertain(profile):
    import cv2
    frame = make_frame({})
    # Only ~30% of cell (0,0) is green: above min coverage, below confident coverage.
    h, w = frame.shape[:2]
    rects = profile.cell_rects(w, h)
    _, _, (x, y, cw, ch) = rects[0]
    cv2.rectangle(frame, (x, y), (x + int(cw * 0.30), y + ch), GREEN, -1)
    result = OverlayRecognizer(profile).recognize(frame)
    cell = result.cells[0]
    assert cell.color == "green"
    assert cell.uncertain and result.uncertain


def test_icon_template(profile, tmp_path):
    lib = TemplateLibrary(tmp_path)
    lib.save_template("icon", "dice", icon_template())
    frame = make_frame({(0, 1): GREEN}, icon_at=(0, 1))
    result = OverlayRecognizer(profile, lib).recognize(frame)
    cell = next(c for c in result.cells if (c.row, c.col) == (0, 1))
    assert cell.icon == "dice" and cell.icon_conf > 0.8
    assert all(c.icon is None for c in result.cells if (c.row, c.col) != (0, 1))


def test_profile_roundtrip(profile, tmp_path):
    profile.save(tmp_path / "p.json")
    loaded = CalibrationProfile.load(tmp_path / "p.json")
    assert loaded == profile


def test_stabilizer(profile):
    rec = OverlayRecognizer(profile)
    a = rec.recognize(make_frame({(0, 0): GREEN}))
    b = rec.recognize(make_frame({(0, 0): RED}))
    none = rec.recognize(make_frame({}))
    st = ObservationStabilizer(2)
    assert st.feed(a) is None          # first sighting
    assert st.feed(a) is a             # stable -> emit
    assert st.feed(a) is None          # unchanged -> no duplicate
    assert st.feed(b) is None
    assert st.feed(b) is b
    assert st.feed(none) is None and st.feed(none) is None
    assert st.feed(b) is None and st.feed(b) is b  # reappears after overlay vanished
