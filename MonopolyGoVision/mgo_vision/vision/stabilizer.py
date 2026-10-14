"""Debounces recognition results so only stable, changed observations are recorded."""
from __future__ import annotations

from .models import FrameResult


class ObservationStabilizer:
    """Emit a result only after it has been identical for `stable_frames`
    consecutive frames and differs from the last emitted one.

    Frames with no overlay reset the "last emitted" state, so the same overlay
    appearing again later is recorded again as a new observation.
    """

    def __init__(self, stable_frames: int = 2):
        self.stable_frames = max(1, stable_frames)
        self._candidate: str | None = None
        self._count = 0
        self._last_emitted: str | None = None

    def reset(self) -> None:
        self._candidate, self._count, self._last_emitted = None, 0, None

    def feed(self, result: FrameResult) -> FrameResult | None:
        sig = result.signature()
        if sig == self._candidate:
            self._count += 1
        else:
            self._candidate, self._count = sig, 1

        if sig == "none":
            if self._count >= self.stable_frames:
                self._last_emitted = None
            return None
        if self._count >= self.stable_frames and sig != self._last_emitted:
            self._last_emitted = sig
            return result
        return None
