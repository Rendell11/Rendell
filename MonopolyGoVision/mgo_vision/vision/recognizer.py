"""OpenCV recognition of the prediction overlay.

Pipeline per frame:
  1. Crop the calibrated overlay ROI and split it into the calibrated grid.
  2. Per cell: classify the dominant color class (HSV ranges) -> tile type.
  3. Per cell: template-match icons and (optionally) multipliers.
  4. Combine into a FrameResult with per-cell and overall confidence.

Anything below the profile's `uncertain_threshold` is flagged `uncertain`
rather than silently accepted or dropped, so the user can review it.
"""
from __future__ import annotations

import cv2
import numpy as np

from .calibration import CalibrationProfile, TemplateLibrary
from .models import CellResult, FrameResult


def _scale(img: np.ndarray, factor: float) -> np.ndarray:
    if abs(factor - 1.0) < 0.02:
        return img
    h, w = img.shape[:2]
    nw, nh = max(1, int(w * factor)), max(1, int(h * factor))
    interp = cv2.INTER_AREA if factor < 1 else cv2.INTER_LINEAR
    return cv2.resize(img, (nw, nh), interpolation=interp)


def best_template_match(region: np.ndarray, library: dict[str, list[np.ndarray]],
                        scale: float = 1.0) -> tuple[str | None, float, float]:
    """Return (label, best_score, runner_up_score) using normalized correlation."""
    best_label, best, second = None, 0.0, 0.0
    if region.size == 0:
        return None, 0.0, 0.0
    for label, variants in library.items():
        label_best = 0.0
        for tmpl in variants:
            for s in (scale * 0.9, scale, scale * 1.1):
                t = _scale(tmpl, s)
                if t.shape[0] > region.shape[0] or t.shape[1] > region.shape[1]:
                    continue
                res = cv2.matchTemplate(region, t, cv2.TM_CCOEFF_NORMED)
                score = float(res.max()) if res.size else 0.0
                if np.isfinite(score):
                    label_best = max(label_best, score)
        if label_best > best:
            best_label, second, best = label, best, label_best
        elif label_best > second:
            second = label_best
    return best_label, best, second


class OverlayRecognizer:
    def __init__(self, profile: CalibrationProfile, templates: TemplateLibrary | None = None):
        self.profile = profile
        self.templates = templates

    def _template_scale(self, frame_width: int) -> float:
        ref = self.profile.reference_width
        return frame_width / ref if ref else 1.0

    def _classify_color(self, hsv: np.ndarray) -> tuple[str | None, str | None, float]:
        p = self.profile
        if not p.color_classes or hsv.size == 0:
            return None, None, 0.0
        total = hsv.shape[0] * hsv.shape[1]
        fracs = sorted(
            ((cv2.countNonZero(cc.mask(hsv)) / total, cc) for cc in p.color_classes),
            key=lambda t: t[0], reverse=True,
        )
        best_frac, best_cc = fracs[0]
        second_frac = fracs[1][0] if len(fracs) > 1 else 0.0
        if best_frac < p.min_color_fraction:
            return None, None, 0.0
        # Coverage (how much of the cell matches) x separation from the runner-up.
        coverage = min(1.0, best_frac / max(p.full_color_fraction, p.min_color_fraction, 1e-6))
        separation = best_frac / (best_frac + second_frac)
        return best_cc.name, best_cc.tile_type or None, round(coverage * separation, 3)

    def recognize(self, frame: np.ndarray) -> FrameResult:
        p = self.profile
        h, w = frame.shape[:2]
        result = FrameResult(timestamp=FrameResult.now(), frame_size=(w, h),
                             overlay_visible=False, profile=p.name)
        rx, ry, rw, rh = p.roi_rect(w, h)
        if rw < 4 or rh < 4:
            result.uncertain = True
            return result

        hsv_frame = cv2.cvtColor(frame, cv2.COLOR_BGR2HSV)
        scale = self._template_scale(w)
        icons = self.templates.icons if self.templates else {}
        mults = self.templates.multipliers if self.templates else {}

        for row, col, (x, y, cw, ch) in p.cell_rects(w, h):
            cell = CellResult(row=row, col=col)
            region = frame[y:y + ch, x:x + cw]
            color, tile_type, conf = self._classify_color(hsv_frame[y:y + ch, x:x + cw])
            cell.color, cell.tile_type, cell.color_conf = color, tile_type, conf

            if icons:
                label, score, second = best_template_match(region, icons, scale)
                if label and score >= p.icon_threshold:
                    cell.icon = label
                    # Penalize ambiguous matches where another icon scored almost as well.
                    ambiguous = second > 0.9 * score
                    cell.icon_conf = round(score * (0.75 if ambiguous else 1.0), 3)
            if mults and p.multiplier_scope == "cell":
                label, score, _ = best_template_match(region, mults, scale)
                if label and score >= p.multiplier_threshold:
                    cell.multiplier, cell.multiplier_conf = label, round(score, 3)

            cell.uncertain = cell.occupied and cell.confidence < p.uncertain_threshold
            result.cells.append(cell)

        if mults and p.multiplier_scope == "roi":
            label, score, _ = best_template_match(frame[ry:ry + rh, rx:rx + rw], mults, scale)
            if label and score >= p.multiplier_threshold:
                result.multiplier, result.multiplier_conf = label, round(score, 3)

        occupied = result.occupied_cells()
        result.overlay_visible = len(occupied) >= max(1, p.overlay_min_cells)
        confs = [c.confidence for c in occupied]
        if result.multiplier:
            confs.append(result.multiplier_conf)
        result.confidence = round(float(np.mean(confs)), 3) if confs else 0.0
        result.uncertain = any(c.uncertain for c in occupied) or (
            result.overlay_visible and result.confidence < p.uncertain_threshold)
        return result


def draw_debug(frame: np.ndarray, profile: CalibrationProfile, result: FrameResult | None = None) -> np.ndarray:
    """Draw ROI, grid and recognized labels onto a copy of `frame`."""
    out = frame.copy()
    h, w = out.shape[:2]
    rx, ry, rw, rh = profile.roi_rect(w, h)
    thick = max(1, w // 400)
    cv2.rectangle(out, (rx, ry), (rx + rw, ry + rh), (255, 255, 0), thick)
    cells = {(c.row, c.col): c for c in result.cells} if result else {}
    font_scale = max(0.35, w / 1800)
    for row, col, (x, y, cw, ch) in profile.cell_rects(w, h):
        cell = cells.get((row, col))
        color = (128, 128, 128)
        if cell and cell.occupied:
            color = (0, 165, 255) if cell.uncertain else (0, 220, 0)
        cv2.rectangle(out, (x, y), (x + cw, y + ch), color, thick)
        if cell and cell.occupied:
            text = f"{cell.label()} {cell.confidence:.2f}"
            cv2.putText(out, text, (x + 2, y + ch - 4), cv2.FONT_HERSHEY_SIMPLEX,
                        font_scale, (0, 0, 0), thick + 2, cv2.LINE_AA)
            cv2.putText(out, text, (x + 2, y + ch - 4), cv2.FONT_HERSHEY_SIMPLEX,
                        font_scale, (255, 255, 255), thick, cv2.LINE_AA)
    return out
