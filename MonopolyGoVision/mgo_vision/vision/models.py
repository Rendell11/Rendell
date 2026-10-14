"""Plain data structures produced by the recognizer."""
from __future__ import annotations

from dataclasses import asdict, dataclass, field
from datetime import datetime, timezone


@dataclass
class CellResult:
    row: int
    col: int
    color: str | None = None
    color_conf: float = 0.0
    tile_type: str | None = None
    icon: str | None = None
    icon_conf: float = 0.0
    multiplier: str | None = None
    multiplier_conf: float = 0.0
    uncertain: bool = False

    @property
    def occupied(self) -> bool:
        return self.color is not None or self.icon is not None

    @property
    def confidence(self) -> float:
        confs = [c for c, v in ((self.color_conf, self.color), (self.icon_conf, self.icon),
                                (self.multiplier_conf, self.multiplier)) if v is not None]
        return min(confs) if confs else self.color_conf

    def label(self) -> str:
        parts = [p for p in (self.tile_type or self.color, self.icon, self.multiplier) if p]
        return "/".join(parts) if parts else "-"


@dataclass
class FrameResult:
    timestamp: str
    frame_size: tuple[int, int]  # (width, height)
    overlay_visible: bool
    cells: list[CellResult] = field(default_factory=list)
    multiplier: str | None = None  # ROI-level multiplier, if configured
    multiplier_conf: float = 0.0
    confidence: float = 0.0
    uncertain: bool = False
    profile: str = "default"

    @staticmethod
    def now() -> str:
        return datetime.now(timezone.utc).astimezone().isoformat(timespec="milliseconds")

    def occupied_cells(self) -> list[CellResult]:
        return [c for c in self.cells if c.occupied]

    def signature(self) -> str:
        """Compact, comparison-friendly description of what is visible.

        Confidence values are excluded so that small fluctuations do not
        create duplicate records.
        """
        if not self.overlay_visible:
            return "none"
        cells = ";".join(f"{c.row},{c.col}={c.label()}" for c in self.occupied_cells())
        return f"{cells}|m={self.multiplier or ''}"

    def summary(self) -> str:
        if not self.overlay_visible:
            return "no overlay"
        items = [f"[{c.row},{c.col}] {c.label()}" for c in self.occupied_cells()]
        if self.multiplier:
            items.append(f"mult {self.multiplier}")
        return ", ".join(items)

    def to_dict(self) -> dict:
        return asdict(self)
