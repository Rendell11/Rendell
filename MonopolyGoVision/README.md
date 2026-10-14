# Monopoly GO Live Vision Recorder (v1)

A desktop app (Python, PySide6, OpenCV) that monitors running **LDPlayer** instances over
**ADB**. It recognizes the prediction overlay shown in the game and records timestamped
observations for each emulator.

> **Version 1 only observes the game.** It only takes screenshots (`adb exec-out screencap`)
> and never sends taps, swipes or any other input. There is no auto-farming or automated gameplay.

## Features

- **Instance discovery.** The app finds every running LDPlayer instance on its own, so the
  number of instances isn't fixed. It uses `adb devices`, plus `ldconsole list2` when it can
  find it, which adds instance names and connects instances that adb can't see yet.
- **Instance selection.** You check which instances to monitor. Each checked instance gets its
  own live-view panel, updated continuously.
- **Overlay recognition**, set up through calibration:
  - the overlay region and its grid (rows × columns) → grid positions
  - HSV color classes → tile colors and **tile types**
  - icon templates → **icons**
  - multiplier templates (per cell or per overlay) → **multipliers**
- **Per-emulator records.** Observations are timestamped and stored separately for each
  emulator. Each has a confidence score, and low-confidence results are flagged as
  **uncertain**, never silently accepted. An observation is only recorded once it has been
  stable for N frames and differs from the previous one, which avoids duplicates.
- **Manual review.** You can mark records verified or rejected, type a corrected value for a
  whole observation or a single cell, add notes, or add a fully manual record. The original
  detection is always kept, and every edit is logged in `corrections`.
- **Controls.** **Start / Pause / Stop** control the recording session; the live view keeps
  running while paused or stopped. **Export** writes CSV (one row per cell) or JSON (cells
  nested).
- **SQLite storage** in `data/observations.sqlite3`. A JPEG snapshot of each recorded frame can
  be saved for later review.
- **Disconnect handling.** If an instance disconnects, its panel turns red and the app retries
  with backoff. Disconnect and reconnect events are logged, and the other instances keep running.

## Install and run (Windows)

```bat
cd MonopolyGoVision
python -m venv .venv
.venv\Scripts\activate
pip install -r requirements.txt
python -m mgo_vision        (or double-click run.bat)
```

1. In LDPlayer, go to **Settings → Other settings → ADB debugging** and set it to *Open local connection*.
2. Make sure `adb` is on your PATH, or set its path in **Settings** in the app. LDPlayer ships
   `adb.exe` in its install folder, e.g. `C:\LDPlayer\LDPlayer9\adb.exe`. `ldconsole.exe` in the
   same folder is detected automatically from common install paths.

## Calibration workflow: start with ONE emulator

The overlay's exact look depends on your game version and resolution, so recognition is
driven entirely by calibration data. Nothing about it is hard-coded.

1. Check **one** instance and open the screen that shows the prediction overlay.
2. Open **Calibrate…** and click **Grab live frame**. Save the frame (**Save frame…**) so you
   can re-calibrate offline later with **Load image…**.
3. **Draw ROI**: drag a rectangle tightly around the overlay grid, then set **Rows / Columns**.
   Dashed boxes show the cells that will be analysed. Use **Cell margin** to trim borders.
4. **Pick color**: click inside each distinct tile color. Name it and give it a tile type.
   Adjust the H/S/V tolerances before clicking if the colors bleed into each other.
5. **Select template**: drag around an icon or multiplier badge, enter a label (e.g. `dice`, `x2`)
   and save it as an icon or multiplier. To add variants of the same label, save it again;
   the extra files are named `label__1.png`, `label__2.png`, and so on.
6. Click **Test recognition**. Cells are outlined green when confident and orange when uncertain.
   Tune the thresholds, then click **Save profile**.
   - `default` applies to every instance. Save under an instance key (e.g. `ld1`) to override it
     for an instance with a different resolution or layout.
7. Press **Start** and watch the records for a while. Fix any mistakes in the table; the
   correction data shows which thresholds need adjusting.
8. When one instance is reliable, check more instances and confirm their panels show the same
   results. Add per-instance profiles only where they're needed.

## Project layout

```
mgo_vision/
  adb/          AdbClient (devices, connect, screencap), LdPlayerDiscovery
  capture/      CaptureWorker – one QThread per instance: capture → recognize → stabilize
  vision/       CalibrationProfile/ProfileStore/TemplateLibrary, OverlayRecognizer,
                ObservationStabilizer, result models
  storage/      SQLite Database (sessions, observations, cells, corrections, events), CSV/JSON export
  ui/           MainWindow, InstancePanel, InstanceSelector, ObservationsView,
                CalibrationDialog, small dialogs
  config.py     AppSettings (data/settings.json)
calibration/
  profiles/     <name>.json calibration profiles (git-ignored; per-machine)
  templates/    icons/*.png, multipliers/*.png
tests/          pytest suite (synthetic frames, fake adb, headless Qt)
```

Extension points for later versions: the recognizer only depends on a profile and a frame;
workers emit Qt signals, so new consumers (analytics, alerts, etc.) can subscribe without
touching capture; storage is isolated behind `Database`.

## Tests

```bash
pip install -r requirements.txt
python -m pytest tests
```
On a headless Linux box the Qt tests use `QT_QPA_PLATFORM=offscreen` (set in `conftest.py`).
