import os
import sys
from pathlib import Path

import cv2
import numpy as np
import pytest

os.environ.setdefault("QT_QPA_PLATFORM", "offscreen")
sys.path.insert(0, str(Path(__file__).resolve().parent.parent))

from mgo_vision.vision import CalibrationProfile, ColorClass  # noqa: E402

# Pure BGR colors with well separated hues.
GREEN = (0, 200, 0)
RED = (0, 0, 220)
BLUE = (220, 80, 0)


def make_frame(cells: dict[tuple[int, int], tuple[int, int, int]], rows=2, cols=3,
               size=(540, 960), icon_at: tuple[int, int] | None = None) -> np.ndarray:
    """Synthetic 'screenshot': grey background, overlay grid in the ROI below."""
    w, h = size
    frame = np.full((h, w, 3), 60, np.uint8)
    rx, ry, rw, rh = int(0.1 * w), int(0.4 * h), int(0.8 * w), int(0.2 * h)
    cw, ch = rw // cols, rh // rows
    for (r, c), color in cells.items():
        x, y = rx + c * cw, ry + r * ch
        cv2.rectangle(frame, (x + 2, y + 2), (x + cw - 2, y + ch - 2), color, -1)
    if icon_at is not None:
        r, c = icon_at
        cx, cy = rx + c * cw + cw // 2, ry + r * ch + ch // 2
        draw_icon(frame, cx, cy)
    return frame


def draw_icon(frame, cx, cy):
    cv2.circle(frame, (cx, cy), 14, (255, 255, 255), -1)
    cv2.rectangle(frame, (cx - 6, cy - 6), (cx + 6, cy + 6), (0, 0, 0), -1)


def icon_template() -> np.ndarray:
    t = np.full((40, 40, 3), 0, np.uint8)
    t[:] = GREEN
    draw_icon(t, 20, 20)
    return t


@pytest.fixture
def profile() -> CalibrationProfile:
    return CalibrationProfile(
        name="test", roi=[0.1, 0.4, 0.8, 0.2], rows=2, cols=3, cell_margin=0.1,
        color_classes=[
            ColorClass.from_sample("green", (60, 255, 200), tile_type="cash"),
            ColorClass.from_sample("red", (0, 255, 220), tile_type="danger"),
            ColorClass.from_sample("blue", (105, 255, 220), tile_type="bonus"),
        ],
        stable_frames=2,
    )
