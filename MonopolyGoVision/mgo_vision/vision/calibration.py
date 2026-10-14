"""Calibration profiles: where the overlay is, how it is gridded, and what
its colors/icons/multipliers look like.

Coordinates are stored normalized (0..1) relative to the full screenshot, so a
profile keeps working if the emulator resolution changes. Templates are
scaled by `frame_width / reference_width` at match time.
"""
from __future__ import annotations

import json
import logging
from dataclasses import asdict, dataclass, field
from pathlib import Path

import cv2
import numpy as np

log = logging.getLogger(__name__)


@dataclass
class ColorClass:
    name: str
    lower: list[int]  # HSV, OpenCV ranges: H 0-179, S 0-255, V 0-255
    upper: list[int]
    tile_type: str = ""

    def mask(self, hsv: np.ndarray) -> np.ndarray:
        lo = np.array(self.lower, np.uint8)
        hi = np.array(self.upper, np.uint8)
        if lo[0] <= hi[0]:
            return cv2.inRange(hsv, lo, hi)
        # Hue wraps around (e.g. red: 170..10).
        m1 = cv2.inRange(hsv, lo, np.array([179, hi[1], hi[2]], np.uint8))
        m2 = cv2.inRange(hsv, np.array([0, lo[1], lo[2]], np.uint8), hi)
        return cv2.bitwise_or(m1, m2)

    @classmethod
    def from_sample(cls, name: str, hsv_pixel, h_tol: int = 8, s_tol: int = 60,
                    v_tol: int = 60, tile_type: str = "") -> "ColorClass":
        h, s, v = (int(x) for x in hsv_pixel)
        lower = [(h - h_tol) % 180, max(0, s - s_tol), max(0, v - v_tol)]
        upper = [(h + h_tol) % 180, min(255, s + s_tol), min(255, v + v_tol)]
        return cls(name=name, lower=lower, upper=upper, tile_type=tile_type)


@dataclass
class CalibrationProfile:
    name: str = "default"
    roi: list[float] = field(default_factory=lambda: [0.1, 0.3, 0.8, 0.4])  # x, y, w, h
    rows: int = 1
    cols: int = 1
    cell_margin: float = 0.12  # fraction trimmed from each cell edge
    reference_width: int = 0  # frame width templates were captured at (0 = no scaling)
    color_classes: list[ColorClass] = field(default_factory=list)
    min_color_fraction: float = 0.25  # below this a cell counts as empty
    full_color_fraction: float = 0.6  # coverage at which color confidence reaches 1.0
    icon_threshold: float = 0.72
    multiplier_threshold: float = 0.75
    multiplier_scope: str = "cell"  # "cell" or "roi"
    uncertain_threshold: float = 0.6
    overlay_min_cells: int = 1
    stable_frames: int = 2

    # ---- geometry -------------------------------------------------------
    def roi_rect(self, width: int, height: int) -> tuple[int, int, int, int]:
        x, y, w, h = self.roi
        x0 = int(round(max(0.0, min(1.0, x)) * width))
        y0 = int(round(max(0.0, min(1.0, y)) * height))
        x1 = int(round(max(0.0, min(1.0, x + w)) * width))
        y1 = int(round(max(0.0, min(1.0, y + h)) * height))
        return x0, y0, max(0, x1 - x0), max(0, y1 - y0)

    def cell_rects(self, width: int, height: int) -> list[tuple[int, int, tuple[int, int, int, int]]]:
        """(row, col, (x, y, w, h)) in absolute frame coordinates, margins applied."""
        rx, ry, rw, rh = self.roi_rect(width, height)
        rows, cols = max(1, self.rows), max(1, self.cols)
        cw, ch = rw / cols, rh / rows
        mx, my = cw * self.cell_margin, ch * self.cell_margin
        out = []
        for r in range(rows):
            for c in range(cols):
                x = int(rx + c * cw + mx)
                y = int(ry + r * ch + my)
                w = max(1, int(cw - 2 * mx))
                h = max(1, int(ch - 2 * my))
                out.append((r, c, (x, y, w, h)))
        return out

    # ---- persistence ----------------------------------------------------
    def to_dict(self) -> dict:
        return asdict(self)

    @classmethod
    def from_dict(cls, data: dict) -> "CalibrationProfile":
        data = dict(data)
        data["color_classes"] = [ColorClass(**c) for c in data.get("color_classes", [])]
        known = set(cls.__dataclass_fields__)
        return cls(**{k: v for k, v in data.items() if k in known})

    def save(self, path: Path) -> None:
        path.parent.mkdir(parents=True, exist_ok=True)
        path.write_text(json.dumps(self.to_dict(), indent=2), encoding="utf-8")

    @classmethod
    def load(cls, path: Path) -> "CalibrationProfile":
        return cls.from_dict(json.loads(path.read_text(encoding="utf-8")))


class TemplateLibrary:
    """Icon and multiplier templates loaded from `<dir>/icons` and `<dir>/multipliers`.

    The file stem is the label, e.g. `multipliers/x2.png` -> "x2",
    `icons/dice.png` -> "dice". Several variants may share a label using a
    `__suffix`: `icons/dice__dark.png`.
    """

    def __init__(self, root: Path):
        self.root = Path(root)
        self.icons: dict[str, list[np.ndarray]] = {}
        self.multipliers: dict[str, list[np.ndarray]] = {}
        self.reload()

    @staticmethod
    def _load_dir(path: Path) -> dict[str, list[np.ndarray]]:
        out: dict[str, list[np.ndarray]] = {}
        if not path.is_dir():
            return out
        for f in sorted(path.iterdir()):
            if f.suffix.lower() not in (".png", ".jpg", ".jpeg", ".bmp"):
                continue
            img = cv2.imdecode(np.fromfile(str(f), np.uint8), cv2.IMREAD_COLOR)
            if img is None:
                log.warning("Unreadable template %s", f)
                continue
            label = f.stem.split("__", 1)[0]
            out.setdefault(label, []).append(img)
        return out

    def reload(self) -> None:
        self.icons = self._load_dir(self.root / "icons")
        self.multipliers = self._load_dir(self.root / "multipliers")

    def save_template(self, kind: str, label: str, image: np.ndarray) -> Path:
        folder = self.root / ("icons" if kind == "icon" else "multipliers")
        folder.mkdir(parents=True, exist_ok=True)
        path = folder / f"{label}.png"
        n = 1
        while path.exists():
            path = folder / f"{label}__{n}.png"
            n += 1
        ok, buf = cv2.imencode(".png", image)
        if not ok:
            raise ValueError("could not encode template")
        buf.tofile(str(path))
        self.reload()
        return path


class ProfileStore:
    """Profiles live in `<calibration_dir>/profiles/<name>.json`.

    An instance-specific profile (named after the instance key, e.g. `ld0`)
    overrides `default` when present.
    """

    def __init__(self, calibration_dir: Path):
        self.dir = Path(calibration_dir) / "profiles"
        self.dir.mkdir(parents=True, exist_ok=True)

    def path(self, name: str) -> Path:
        return self.dir / f"{name}.json"

    def load(self, name: str) -> CalibrationProfile | None:
        p = self.path(name)
        if not p.is_file():
            return None
        try:
            return CalibrationProfile.load(p)
        except (ValueError, TypeError, KeyError) as exc:
            log.error("Invalid calibration profile %s: %s", p, exc)
            return None

    def for_instance(self, instance_key: str) -> CalibrationProfile:
        return self.load(instance_key) or self.load("default") or CalibrationProfile()

    def save(self, profile: CalibrationProfile) -> Path:
        p = self.path(profile.name)
        profile.save(p)
        return p
