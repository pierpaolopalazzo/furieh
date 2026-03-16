"""
sraw_lib.py — Shared library for SRAW format
Fourier UNIFG Project

All Python tools import this module for consistent SRAW read/write operations.
"""

import os
import sys
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

    Primitive values are read from conf; all derived quantities are
    computed here so the conf stays minimal and non-redundant.

    Primitives (from conf):
        FORMAT_VERSION, MAX_SAMPLES,
        TIME_RESOLUTION_S, FREQ_RESOLUTION_HZ,
        AMP_TIME_RESOLUTION_V, AMP_FREQ_RESOLUTION_VS

    Derived (computed):
        AMP_INT_MAX  = round(5.0 / AMP_TIME_RESOLUTION_V)   (+-5 V range / step)
        AMP_INT_MIN  = -AMP_INT_MAX
        TIME_MAX     = MAX_SAMPLES * TIME_RESOLUTION_S / 2   (positive half-span)
        FREQ_MAX     = MAX_SAMPLES * FREQ_RESOLUTION_HZ / 2  (positive half-span)
    """
    if conf is None:
        conf = load_conf()

    # --- primitives ---
    time_res     = float(conf["TIME_RESOLUTION_S"])
    freq_res     = float(conf["FREQ_RESOLUTION_HZ"])
    amp_time_res = float(conf["AMP_TIME_RESOLUTION_V"])
    amp_freq_res = float(conf["AMP_FREQ_RESOLUTION_VS"])
    max_samples  = int(conf["MAX_SAMPLES"])
    format_ver   = conf["FORMAT_VERSION"]

    # --- derived ---
    amp_int_max  = round(5.0 / amp_time_res)   # 500000
    time_max     = max_samples * time_res / 2   # 12.5 s  (positive half-span)
    freq_max     = max_samples * freq_res / 2   # 10000 Hz (positive half-span)

    return {
        # primitives
        "FORMAT_VER":    format_ver,
        "MAX_SAMPLES":   max_samples,
        "TIME_RES":      time_res,
        "FREQ_RES":      freq_res,
        "AMP_TIME_RES":  amp_time_res,
        "AMP_FREQ_RES":  amp_freq_res,
        # derived
        "AMP_INT_MAX":   amp_int_max,
        "AMP_INT_MIN":  -amp_int_max,
        "TIME_MAX":      time_max,
        "FREQ_MAX":      freq_max,
    }


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
    Parse a .sraw file.
    Returns SrawData on success.
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
    C = get_constants()

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

            # Validate amplitude range
            for val, name in ((re, "real"), (im, "imag")):
                if not (C["AMP_INT_MIN"] <= val <= C["AMP_INT_MAX"]):
                    raise ValueError(
                        f"Line {lineno}: {name} value {val} out of range "
                        f"[{C['AMP_INT_MIN']}, {C['AMP_INT_MAX']}]."
                    )
            samples.append((x, re, im))

    if axis_mode is None:
        raise ValueError("Missing 'axis_mode' directive.")
    if len(samples) == 0:
        raise ValueError("No data samples found.")
    if len(samples) > C["MAX_SAMPLES"]:
        raise ValueError(f"Too many samples: {len(samples)} > {C['MAX_SAMPLES']}.")

    # ── Silent clipping ────────────────────────────────────────
    # x indices outside the valid range for axis_mode are silently clamped.
    # symmetric : [-500000, +500000]
    # positive  : [0, +500000]
    x_clip_min = -(C["MAX_SAMPLES"] // 2) if axis_mode == "symmetric" else 0
    x_clip_max =  (C["MAX_SAMPLES"] // 2) if axis_mode == "symmetric" else C["MAX_SAMPLES"]
    clipped = []
    for (x, re, im) in samples:
        x  = max(x_clip_min,        min(x_clip_max,        x))
        re = max(-C["AMP_INT_MAX"], min(C["AMP_INT_MAX"], re))
        im = max(-C["AMP_INT_MAX"], min(C["AMP_INT_MAX"], im))
        clipped.append((x, re, im))
    samples = clipped

    data = SrawData(axis_mode=axis_mode, samples=samples)
    data.sort()
    return data


# ─────────────────────────────────────────────
# SRAW file writer
# ─────────────────────────────────────────────

def write_sraw(data: SrawData, filepath, comment=None):
    """
    Write an SrawData object to a .sraw file.
    Optional comment string is written after the header as # lines.
    """
    C = get_constants()
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