"""Checkable list of discovered emulator instances."""
from __future__ import annotations

from PySide6.QtCore import Qt, Signal
from PySide6.QtWidgets import QListWidget, QListWidgetItem, QPushButton, QVBoxLayout, QWidget, QLabel

from ..adb import EmulatorInstance


class InstanceSelector(QWidget):
    refresh_requested = Signal()
    selection_changed = Signal(list)  # list[EmulatorInstance] currently checked

    def __init__(self, parent=None):
        super().__init__(parent)
        self._instances: dict[str, EmulatorInstance] = {}
        self.info = QLabel("Click Refresh to detect LDPlayer instances.")
        self.info.setWordWrap(True)
        self.list = QListWidget()
        self.list.itemChanged.connect(self._emit_selection)
        refresh = QPushButton("Refresh instances")
        refresh.clicked.connect(self.refresh_requested)
        layout = QVBoxLayout(self)
        layout.addWidget(refresh)
        layout.addWidget(self.info)
        layout.addWidget(self.list, 1)

    def set_instances(self, instances: list[EmulatorInstance], checked_keys: set[str]) -> None:
        """Replace the list. Previously checked instances that vanished are kept
        (marked offline) so their monitoring is not silently dropped."""
        current = {i.key: i for i in instances}
        for key in checked_keys:
            if key not in current and key in self._instances:
                old = self._instances[key]
                current[key] = EmulatorInstance(old.serial, old.name, old.index, online=False)
        self._instances = current

        self.list.blockSignals(True)
        self.list.clear()
        for inst in sorted(current.values(), key=lambda i: (i.index is None, i.index or 0, i.serial)):
            state = "online" if inst.online else "OFFLINE"
            label = inst.serial if inst.name == inst.serial else f"{inst.name}  —  {inst.serial}"
            item = QListWidgetItem(f"{label}  ({state})")
            item.setData(Qt.ItemDataRole.UserRole, inst.key)
            item.setFlags(item.flags() | Qt.ItemFlag.ItemIsUserCheckable)
            item.setCheckState(Qt.CheckState.Checked if inst.key in checked_keys else Qt.CheckState.Unchecked)
            if not inst.online:
                item.setForeground(Qt.GlobalColor.gray)
            self.list.addItem(item)
        self.list.blockSignals(False)
        n_online = sum(i.online for i in instances)
        self.info.setText(f"{n_online} online instance(s) detected." if instances
                          else "No instances found. Is LDPlayer running with ADB debugging enabled?")
        self._emit_selection()

    def checked(self) -> list[EmulatorInstance]:
        out = []
        for row in range(self.list.count()):
            item = self.list.item(row)
            if item.checkState() == Qt.CheckState.Checked:
                out.append(self._instances[item.data(Qt.ItemDataRole.UserRole)])
        return out

    def instance(self, key: str) -> EmulatorInstance | None:
        return self._instances.get(key)

    def _emit_selection(self, *_):
        self.selection_changed.emit(self.checked())
