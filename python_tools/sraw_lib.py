"""
sraw_lib.py — Shared library for SRAW format
Fourier UNIFG Project

All Python tools import this module for consistent SRAW read/write operations.
"""

import os
import sys
import shutil
from pathlib import Path

# ─────────────────────────────────────────────
# Config loading
# ─────────────────────────────────────────────

def load_conf(conf_path=None):
    """Load sraw.conf and return a dict of parameters."""
    if conf_path is None:
        # Default: look for conf/sraw.conf relative to this file
        base = Path(__file__).parent.parent
        conf_path = base / "conf" / "sraw.conf"

    conf = {}
    with open(conf_path, "r") as f:
        for line in f:
            line = line.strip()
            if not line or line.startswith("#"):
                continue
            if "=" in line:
                key, val = line.split("=", 1)
                conf[key.strip()] = val.strip()
    return conf


def get_constants(conf=None):
    """
    Return a dict of typed constants.

    MAX_SAMPLES is always read from conf (sraw.conf).
    Resolution values (TIME_RES, FREQ_RES, AMP_TIME_RES, AMP_FREQ_RES) use
    SRAW-1.1 defaults (1 nV / 1 nVs) but can be overridden by the caller
    (e.g. when reading a legacy SRAW-1 file without embedded constants).

    AMP_INT_MAX is None for SRAW-1.1 (no clipping).  For legacy SRAW-1 files,
    callers may pass overrides with the old resolution values; in that case
    AMP_INT_MAX is computed as round(5.0 / AMP_TIME_RESOLUTION_V).
    """
    if conf is None:
        conf = load_conf()

    max_samples = int(conf["MAX_SAMPLES"])

    # SRAW-1.1 defaults
    time_res     = float(conf.get("TIME_RESOLUTION_S",       "0.0000125"))
    freq_res     = float(conf.get("FREQ_RESOLUTION_HZ",      "0.01"))
    amp_time_res = float(conf.get("AMP_TIME_RESOLUTION_V",   "0.000000001"))
    amp_freq_res = float(conf.get("AMP_FREQ_RESOLUTION_VS",  "0.000000001"))

    # derived
    time_max = max_samples * time_res / 2
    freq_max = max_samples * freq_res / 2

    # AMP_INT_MAX: None for 1.1 (unlimited); computed for legacy 1.0
    amp_int_max = None
    if amp_time_res >= 1e-6:  # legacy 10 µV resolution
        amp_int_max = round(5.0 / amp_time_res)

    return {
        "MAX_SAMPLES":   max_samples,
        "TIME_RES":      time_res,
        "FREQ_RES":      freq_res,
        "AMP_TIME_RES":  amp_time_res,
        "AMP_FREQ_RES":  amp_freq_res,
        "AMP_INT_MAX":   amp_int_max,
        "AMP_INT_MIN":  -amp_int_max if amp_int_max is not None else None,
        "TIME_MAX":      time_max,
        "FREQ_MAX":      freq_max,
    }


def make_constants(time_res=0.0000125, freq_res=0.01,
                   amp_time_res=0.000000001, amp_freq_res=0.000000001,
                   max_samples=None, conf=None):
    """
    Build a constants dict from explicit resolution values.
    Used when reading per-file constants from SRAW headers.
    """
    if max_samples is None:
        if conf is None:
            conf = load_conf()
        max_samples = int(conf["MAX_SAMPLES"])

    amp_int_max = None
    if amp_time_res >= 1e-6:
        amp_int_max = round(5.0 / amp_time_res)

    return {
        "MAX_SAMPLES":   max_samples,
        "TIME_RES":      time_res,
        "FREQ_RES":      freq_res,
        "AMP_TIME_RES":  amp_time_res,
        "AMP_FREQ_RES":  amp_freq_res,
        "AMP_INT_MAX":   amp_int_max,
        "AMP_INT_MIN":  -amp_int_max if amp_int_max is not None else None,
        "TIME_MAX":      max_samples * time_res / 2,
        "FREQ_MAX":      max_samples * freq_res / 2,
    }


# ─────────────────────────────────────────────
# ffmpeg path resolution
# ─────────────────────────────────────────────

def resolve_ffmpeg_path(conf=None):
    """
    Resolve the ffmpeg executable path from conf or system PATH.
    Accepts a conf dict (from load_conf()) or loads it automatically.
    """
    if conf is None:
        conf = load_conf()

    raw = conf.get("FFMPEG_PATH", "ffmpeg").strip().strip('"').strip("'")
    if raw == "":
        raw = "ffmpeg"

    if os.path.isabs(raw) or os.sep in raw or (os.altsep and os.altsep in raw):
        if not os.path.exists(raw):
            raise FileNotFoundError(f"ffmpeg non trovato: {raw}")
        return raw

    found = shutil.which(raw)
    if not found:
        raise FileNotFoundError(
            f"ffmpeg non trovato nel PATH. Valore attuale FFMPEG_PATH='{raw}'"
        )
    return found


# ─────────────────────────────────────────────
# SRAW data structure
# ─────────────────────────────────────────────

class SrawData:
    """
    Holds SRAW file contents.

    Attributes:
        axis_mode  : 'positive' or 'symmetric'
        samples    : list of (x_index:int, real_int:int, imag_int:int)
                     sorted by x_index
    """
    def __init__(self, axis_mode="symmetric", samples=None):
        self.axis_mode = axis_mode
        self.samples = samples if samples is not None else []

    def sort(self):
        self.samples.sort(key=lambda s: s[0])

    def x_indices(self):
        return [s[0] for s in self.samples]

    def real_ints(self):
        return [s[1] for s in self.samples]

    def imag_ints(self):
        return [s[2] for s in self.samples]

    def to_physical_time(self, C=None):
        """Convert x_indices to seconds using TIME_RES."""
        if C is None:
            C = get_constants()
        return [x * C["TIME_RES"] for x in self.x_indices()]

    def to_physical_freq(self, C=None):
        """Convert x_indices to Hz using FREQ_RES."""
        if C is None:
            C = get_constants()
        return [x * C["FREQ_RES"] for x in self.x_indices()]

    def to_real_volts(self, C=None):
        if C is None:
            C = get_constants()
        return [r * C["AMP_TIME_RES"] for r in self.real_ints()]

    def to_imag_volts(self, C=None):
        if C is None:
            C = get_constants()
        return [i * C["AMP_TIME_RES"] for i in self.imag_ints()]

    def to_real_vs(self, C=None):
        """Real part in Volt·seconds (frequency domain amplitude)."""
        if C is None:
            C = get_constants()
        return [r * C["AMP_FREQ_RES"] for r in self.real_ints()]

    def to_imag_vs(self, C=None):
        if C is None:
            C = get_constants()
        return [i * C["AMP_FREQ_RES"] for i in self.imag_ints()]


# ─────────────────────────────────────────────
# SRAW file reader
# ─────────────────────────────────────────────

def read_sraw(filepath):
    """
    Parse a .sraw file (SRAW-1 or SRAW-1.1).

    SRAW-1.1 files embed resolution constants as header directives
    (time_res, freq_res, amp_time_res, amp_freq_res) before the data section.
    If these are absent the file is treated as legacy SRAW-1 with 10 µV resolution
    and ±5 V amplitude limits.

    Returns (SrawData, constants_dict) on success.
    Raises ValueError with a descriptive message on format errors.
    """
    filepath = Path(filepath)
    if not filepath.exists():
        raise FileNotFoundError(f"File not found: {filepath}")

    with open(filepath, "r") as f:
        lines = [l.rstrip("\n") for l in f.readlines()]

    if not lines:
        raise ValueError("Empty file.")

    # Line 1: format version
    if lines[0].strip() != "SRAW-1":
        raise ValueError(f"Unknown format header: '{lines[0]}'. Expected 'SRAW-1'.")

    axis_mode = None
    in_data = False
    samples = []

    # Per-file resolution constants (SRAW-1.1)
    file_consts = {}

    for lineno, line in enumerate(lines[1:], start=2):
        line = line.strip()
        if not line or line.startswith("#"):
            continue

        if line.startswith("axis_mode,"):
            val = line.split(",", 1)[1].strip()
            if val not in ("positive", "symmetric"):
                raise ValueError(f"Line {lineno}: invalid axis_mode '{val}'.")
            axis_mode = val
            continue

        # SRAW-1.1 per-file constants
        if line.startswith("time_res,"):
            file_consts["time_res"] = float(line.split(",", 1)[1].strip())
            continue
        if line.startswith("freq_res,"):
            file_consts["freq_res"] = float(line.split(",", 1)[1].strip())
            continue
        if line.startswith("amp_time_res,"):
            file_consts["amp_time_res"] = float(line.split(",", 1)[1].strip())
            continue
        if line.startswith("amp_freq_res,"):
            file_consts["amp_freq_res"] = float(line.split(",", 1)[1].strip())
            continue

        if line == "data":
            in_data = True
            continue

        if in_data:
            parts = line.split(",")
            if len(parts) != 3:
                raise ValueError(f"Line {lineno}: expected 3 columns, got {len(parts)}: '{line}'")
            try:
                x   = int(parts[0])
                re  = int(parts[1])
                im  = int(parts[2])
            except ValueError as e:
                raise ValueError(f"Line {lineno}: cannot parse integers — {e}")

            samples.append((x, re, im))

    if axis_mode is None:
        raise ValueError("Missing 'axis_mode' directive.")
    if len(samples) == 0:
        raise ValueError("No data samples found.")

    # Build constants: per-file overrides > sraw.conf defaults
    if file_consts:
        # SRAW-1.1 file with embedded constants
        C = make_constants(**file_consts)
    else:
        # Legacy SRAW-1: use old 10 µV resolution with ±5V limits
        C = make_constants(
            amp_time_res=0.00001,
            amp_freq_res=0.00001,
        )

    if len(samples) > C["MAX_SAMPLES"]:
        raise ValueError(f"Too many samples: {len(samples)} > {C['MAX_SAMPLES']}.")

    # ── Silent clipping ────────────────────────────────────────
    x_clip_min = -(C["MAX_SAMPLES"] // 2)     if axis_mode == "symmetric" else 0
    x_clip_max =  (C["MAX_SAMPLES"] // 2) - 1 if axis_mode == "symmetric" else C["MAX_SAMPLES"] - 1
    amp_max = C["AMP_INT_MAX"]  # None for 1.1

    clipped = []
    for (x, re, im) in samples:
        x = max(x_clip_min, min(x_clip_max, x))
        if amp_max is not None:
            re = max(-amp_max, min(amp_max, re))
            im = max(-amp_max, min(amp_max, im))
        clipped.append((x, re, im))
    samples = clipped

    data = SrawData(axis_mode=axis_mode, samples=samples)
    data.sort()
    return data, C


# ─────────────────────────────────────────────
# SRAW file writer
# ─────────────────────────────────────────────

def write_sraw(data: SrawData, filepath, comment=None, constants=None):
    """
    Write an SrawData object to a .sraw file in SRAW-1.1 format.
    Embeds resolution constants in the header so the file is self-describing.
    Optional comment string is written after the header as # lines.
    If constants is provided, uses those values; otherwise uses get_constants().
    """
    if constants is None:
        constants = get_constants()
    C = constants
    filepath = Path(filepath)

    data.sort()

    if len(data.samples) > C["MAX_SAMPLES"]:
        raise ValueError(
            f"Cannot write: {len(data.samples)} samples exceed MAX_SAMPLES={C['MAX_SAMPLES']}."
        )

    with open(filepath, "w") as f:
        f.write("SRAW-1\n")
        if comment:
            for line in comment.strip().splitlines():
                f.write(f"# {line}\n")
        f.write(f"axis_mode,{data.axis_mode}\n")
        # SRAW-1.1: embed resolution constants
        f.write(f"time_res,{C['TIME_RES']}\n")
        f.write(f"freq_res,{C['FREQ_RES']}\n")
        f.write(f"amp_time_res,{C['AMP_TIME_RES']}\n")
        f.write(f"amp_freq_res,{C['AMP_FREQ_RES']}\n")
        f.write("data\n")
        for x, re, im in data.samples:
            f.write(f"{x},{re},{im}\n")


# ─────────────────────────────────────────────
# Interpolation utility
# ─────────────────────────────────────────────

def interpolate_sraw(data: SrawData, x_query):
    """
    Linearly interpolate the complex value at integer index x_query.
    Returns (real_int, imag_int) as floats (caller may round/floor as needed).

    Behaviour:
    - x_query inside the range of known indices: piecewise linear interpolation.
    - x_query outside: returns the nearest endpoint value (clamp).
    - If only one sample exists: returns that sample's value.
    """
    samples = data.samples  # already sorted

    if not samples:
        return (0.0, 0.0)

    xs = [s[0] for s in samples]
    rs = [s[1] for s in samples]
    ims = [s[2] for s in samples]

    # Exact hit
    if x_query in xs:
        idx = xs.index(x_query)
        return (float(rs[idx]), float(ims[idx]))

    # Clamp left
    if x_query < xs[0]:
        return (float(rs[0]), float(ims[0]))

    # Clamp right
    if x_query > xs[-1]:
        return (float(rs[-1]), float(ims[-1]))

    # Binary search for bracket
    lo, hi = 0, len(xs) - 1
    while lo + 1 < hi:
        mid = (lo + hi) // 2
        if xs[mid] <= x_query:
            lo = mid
        else:
            hi = mid

    x0, x1 = xs[lo], xs[hi]
    r0, r1 = rs[lo], rs[hi]
    i0, i1 = ims[lo], ims[hi]

    t = (x_query - x0) / (x1 - x0)
    return (r0 + t * (r1 - r0), i0 + t * (i1 - i0))


def sample_uniform(data: SrawData, n_points=None, x_start=None, x_end=None):
    """
    Resample the SRAW signal on a uniform integer grid via interpolation.

    Parameters:
        data     : SrawData
        n_points : number of output samples (default: use all unique x with step 1)
        x_start  : start index (default: first sample index)
        x_end    : end index   (default: last sample index)

    Returns:
        list of (x_index, real_int_float, imag_int_float)
    """
    xs = [s[0] for s in data.samples]
    if x_start is None:
        x_start = xs[0]
    if x_end is None:
        x_end = xs[-1]

    if n_points is None:
        n_points = x_end - x_start + 1

    if n_points < 2:
        raise ValueError("n_points must be >= 2.")

    step = (x_end - x_start) / (n_points - 1)
    result = []
    for i in range(n_points):
        xq = x_start + i * step
        r, im = interpolate_sraw(data, xq)
        result.append((xq, r, im))
    return result