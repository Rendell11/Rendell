from mgo_vision.adb.client import AdbDevice, parse_devices_output
from mgo_vision.adb.discovery import LdPlayerDiscovery, parse_ldconsole_list2, serial_to_ld_index


def test_parse_devices_output():
    text = """List of devices attached
* daemon started successfully
emulator-5554          device product:SM-S908E model:SM_S908E device:z3q transport_id:1
emulator-5556          offline transport_id:2
127.0.0.1:5559         device model:ASUS_I003DD
"""
    devs = parse_devices_output(text)
    assert [d.serial for d in devs] == ["emulator-5554", "emulator-5556", "127.0.0.1:5559"]
    assert devs[0].online and devs[0].model == "SM_S908E"
    assert not devs[1].online


def test_serial_to_index():
    assert serial_to_ld_index("emulator-5554") == 0
    assert serial_to_ld_index("emulator-5560") == 3
    assert serial_to_ld_index("127.0.0.1:5555") == 0
    assert serial_to_ld_index("127.0.0.1:5561") == 3
    assert serial_to_ld_index("R58M123ABC") is None


def test_parse_ldconsole():
    text = "0,LDPlayer,1234,5678,1,4000,4100,960,540,240\n1,Farm-2,0,0,0,-1,-1,960,540,240\n"
    infos = parse_ldconsole_list2(text)
    assert [(i.index, i.title, i.android_running) for i in infos] == [(0, "LDPlayer", True), (1, "Farm-2", False)]


class FakeAdb:
    def __init__(self, devices):
        self._devices = devices
        self.connected = []

    def devices(self):
        return list(self._devices)

    def connect(self, addr):
        self.connected.append(addr)
        return False


def test_discovery_dedupes_and_counts_dynamically():
    adb = FakeAdb([AdbDevice("emulator-5554", "device"), AdbDevice("127.0.0.1:5555", "offline"),
                   AdbDevice("emulator-5556", "device"), AdbDevice("emulator-5562", "device")])
    disc = LdPlayerDiscovery(adb)
    disc.ldconsole_path = None
    found = disc.discover()
    assert [(i.key, i.serial, i.online) for i in found] == [
        ("ld0", "emulator-5554", True), ("ld1", "emulator-5556", True), ("ld4", "emulator-5562", True)]
