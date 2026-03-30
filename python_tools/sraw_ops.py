#!/usr/bin/env python3
"""
sraw_ops.py — Basic operations between SRAW signals
Fourier UNIFG Project

Semantica adottata:
- interpolazione lineare tra punti espliciti
- zero fuori dal supporto noto
- operazioni su griglia uniforme fissa determinata da MAX_SAMPLES
- parametri letti dinamicamente da sraw.conf tramite get_constants()

Operazioni:
- sum       : A + B
- mul       : prodotto complesso campione per campione
- gain      : A * gain
- shift     : y(x) = A(x - shift)
- mirror_y  : y(x) = A(-x)
- conj      : y(x) = conj(A(x))   (parte immaginaria negata)
- dilate_x  : y(x) = A(x / factor)
"""

import sys
import argparse
import time
from pathlib import Path

import numpy as np

sys.path.insert(0, str(Path(__file__).parent))
from sraw_lib import get_constants, make_constants


def axis_bounds(axis_mode: str, n: int):
    if axis_mode == "positive":
        return 0, n - 1
    if axis_mode == "symmetric":
        return -(n // 2), (n // 2) - 1
    raise ValueError(f"axis_mode non valido: {axis_mode}")


def axis_grid(axis_mode: str, n: int) -> np.ndarray:
    xmin, xmax = axis_bounds(axis_mode, n)
    return np.arange(xmin, xmax + 1, dtype=np.float64)


def clip_amp_scalar(v: float, amp_max: int) -> int:
    if v < -amp_max:
        return -amp_max
    if v > amp_max:
        return amp_max
    return int(round(v))


def stream_read_dense_sraw(filepath: str, c: dict):
    """
    Legge un file SRAW e ricostruisce direttamente due array dense (re, im)
    evitando liste Python enormi.

    Ipotesi:
    - campioni ordinati per ascissa crescente
    - punti duplicati: vince l'ultimo
    - interpolazione lineare tra punti consecutivi
    - fuori dal supporto noto: zero

    Returns dict with keys: axis_mode, xmin, xmax, re, im, constants.
    """
    n = c["MAX_SAMPLES"]

    with open(filepath, "r", encoding="utf-8") as f:
        header = f.readline().strip()
        if header != "SRAW-1":
            raise ValueError(f"Header non valido in {Path(filepath).name}")

        axis_mode = None
        in_data = False
        file_consts = {}

        for line in f:
            s = line.strip()
            if not s or s.startswith("#"):
                continue
            if s.startswith("axis_mode,"):
                axis_mode = s.split(",", 1)[1].strip()
                if axis_mode not in ("positive", "symmetric"):
                    raise ValueError(f"axis_mode non valido in {Path(filepath).name}: {axis_mode}")
                continue
            # SRAW-1.1 per-file constants
            if s.startswith("time_res,"):
                file_consts["time_res"] = float(s.split(",", 1)[1].strip())
                continue
            if s.startswith("freq_res,"):
                file_consts["freq_res"] = float(s.split(",", 1)[1].strip())
                continue
            if s.startswith("amp_time_res,"):
                file_consts["amp_time_res"] = float(s.split(",", 1)[1].strip())
                continue
            if s.startswith("amp_freq_res,"):
                file_consts["amp_freq_res"] = float(s.split(",", 1)[1].strip())
                continue
            if s == "data":
                in_data = True
                break

        if axis_mode is None:
            raise ValueError(f"axis_mode mancante in {Path(filepath).name}")
        if not in_data:
            raise ValueError(f"Sezione data mancante in {Path(filepath).name}")

        # Build per-file constants
        if file_consts:
            file_c = make_constants(**file_consts, max_samples=n)
        else:
            file_c = make_constants(amp_time_res=0.00001, amp_freq_res=0.00001, max_samples=n)
        amp_max = file_c["AMP_INT_MAX"]

        xmin, xmax = axis_bounds(axis_mode, n)
        length = xmax - xmin + 1

        re = np.zeros(length, dtype=np.float64)
        im = np.zeros(length, dtype=np.float64)

        prev = None
        only_one_point = None

        for line in f:
            s = line.strip()
            if not s or s.startswith("#"):
                continue

            parts = s.split(",")
            if len(parts) != 3:
                continue

            try:
                x = int(parts[0])
                r = int(parts[1])
                j = int(parts[2])
            except ValueError:
                continue

            if x < xmin:
                x = xmin
            if x > xmax:
                x = xmax

            if amp_max is not None:
                r = clip_amp_scalar(r, amp_max)
                j = clip_amp_scalar(j, amp_max)

            cur = (x, float(r), float(j))

            if prev is None:
                prev = cur
                only_one_point = cur
                continue

            if cur[0] == prev[0]:
                prev = cur
                only_one_point = cur
                continue

            if cur[0] < prev[0]:
                raise ValueError(
                    f"I campioni devono essere ordinati per ascissa crescente in {Path(filepath).name}"
                )

            x0, r0, i0 = prev
            x1, r1, i1 = cur

            xs = np.arange(x0, x1 + 1, dtype=np.float64)
            t = (xs - x0) / (x1 - x0)

            seg_re = r0 + (r1 - r0) * t
            seg_im = i0 + (i1 - i0) * t

            start_idx = int(x0 - xmin)
            end_idx = int(x1 - xmin) + 1

            re[start_idx:end_idx] = seg_re
            im[start_idx:end_idx] = seg_im

            prev = cur
            only_one_point = None

        if prev is None:
            raise ValueError(f"Nessun campione trovato in {Path(filepath).name}")

        if only_one_point is not None:
            x, r, j = only_one_point
            idx = int(x - xmin)
            re[idx] = r
            im[idx] = j

        return {
            "axis_mode": axis_mode,
            "xmin": xmin,
            "xmax": xmax,
            "re": re,
            "im": im,
            "constants": file_c,
        }


def resample_dense(sig: dict, xq: np.ndarray):
    """
    Campionamento/interpolazione lineare vettoriale.
    Fuori dal supporto noto -> zero.
    """
    xq = np.asarray(xq, dtype=np.float64)

    out_re = np.zeros_like(xq, dtype=np.float64)
    out_im = np.zeros_like(xq, dtype=np.float64)

    xmin = sig["xmin"]
    xmax = sig["xmax"]

    mask = (xq >= xmin) & (xq <= xmax)
    if not np.any(mask):
        return out_re, out_im

    xin = xq[mask]
    x0 = np.floor(xin).astype(np.int64)
    x1 = np.ceil(xin).astype(np.int64)

    idx_out = np.nonzero(mask)[0]

    exact = (x0 == x1)
    if np.any(exact):
        p = x0[exact] - xmin
        out_re[idx_out[exact]] = sig["re"][p]
        out_im[idx_out[exact]] = sig["im"][p]

    interp = ~exact
    if np.any(interp):
        xa = x0[interp]
        xb = x1[interp]
        pa = xa - xmin
        pb = xb - xmin
        t = (xin[interp] - xa) / (xb - xa)

        out_re[idx_out[interp]] = sig["re"][pa] + (sig["re"][pb] - sig["re"][pa]) * t
        out_im[idx_out[interp]] = sig["im"][pa] + (sig["im"][pb] - sig["im"][pa]) * t

    return out_re, out_im


def write_dense_sraw(filepath: str, axis_mode: str, re: np.ndarray, im: np.ndarray, c: dict, comment: str = ""):
    """
    Scrive un array denso in formato SRAW-1.1 usando rappresentazione knot-sparse:
    vengono scritti solo i campioni non-zero più un campione zero di guardia
    immediatamente prima e dopo ogni zona attiva. Questo riduce drasticamente
    le dimensioni del file per segnali sparsi (es. spettri a poche righe).
    """
    amp_max = c["AMP_INT_MAX"]  # None for SRAW-1.1
    xmin, xmax = axis_bounds(axis_mode, c["MAX_SAMPLES"])
    length = xmax - xmin + 1

    if len(re) != length or len(im) != length:
        raise ValueError("Dimensione array di output non coerente con axis_mode / MAX_SAMPLES")

    if amp_max is not None:
        re_i = np.clip(np.rint(re), -amp_max, amp_max).astype(np.int64)
        im_i = np.clip(np.rint(im), -amp_max, amp_max).astype(np.int64)
    else:
        re_i = np.rint(re).astype(np.int64)
        im_i = np.rint(im).astype(np.int64)

    # Costruisci insieme degli indici da scrivere (knot-sparse con guardie zero)
    nonzero = np.flatnonzero((re_i != 0) | (im_i != 0))
    if len(nonzero) == 0:
        # Segnale completamente zero: scrivi solo due punti sentinella
        indices_to_write = {0, length - 1}
    else:
        indices_to_write = set()
        for idx in nonzero:
            indices_to_write.add(idx)
            if idx > 0:
                indices_to_write.add(idx - 1)   # guardia zero prima
            if idx < length - 1:
                indices_to_write.add(idx + 1)   # guardia zero dopo

    with open(filepath, "w", encoding="utf-8") as f:
        f.write("SRAW-1\n")
        if comment:
            for line in comment.strip().splitlines():
                f.write(f"# {line}\n")
        f.write(f"axis_mode,{axis_mode}\n")
        # SRAW-1.1: embed resolution constants
        f.write(f"time_res,{c['TIME_RES']}\n")
        f.write(f"freq_res,{c['FREQ_RES']}\n")
        f.write(f"amp_time_res,{c['AMP_TIME_RES']}\n")
        f.write(f"amp_freq_res,{c['AMP_FREQ_RES']}\n")
        f.write("data\n")

        for i in sorted(indices_to_write):
            x = xmin + i
            f.write(f"{x},{int(re_i[i])},{int(im_i[i])}\n")


def choose_output_axis_mode(op: str, axis_a: str, axis_b: str | None = None, factor: float | None = None):
    if op in ("sum", "mul"):
        return "symmetric" if ("symmetric" in (axis_a, axis_b)) else "positive"

    if op == "mirror_y":
        return "symmetric"

    if op == "dilate_x":
        if factor is not None and factor < 0:
            return "symmetric"
        return axis_a

    return axis_a


def run(
    input_a: str,
    output_path: str,
    op: str,
    input_b: str | None = None,
    gain: float = 1.0,
    shift_value: float = 0.0,
    shift_unit: str = "samples",
    dilate_factor: float = 1.0,
    verbose: bool = False,
    benchmark: bool = False,
):
    c = get_constants()
    n = c["MAX_SAMPLES"]

    if verbose:
        print(f"[sraw_ops] Reading A: {input_a}")
    a = stream_read_dense_sraw(input_a, c)
    # Use per-file constants from input A for output
    c = a["constants"]

    b = None
    if op in ("sum", "mul"):
        if not input_b:
            raise ValueError(f"L'operazione '{op}' richiede anche input_b")
        if verbose:
            print(f"[sraw_ops] Reading B: {input_b}")
        b = stream_read_dense_sraw(input_b, c)

    if op == "dilate_x":
        if not np.isfinite(dilate_factor) or dilate_factor == 0.0:
            raise ValueError("dilate_factor non valido (non può essere zero)")
        if dilate_factor > 0:
            dilate_factor = min(max(dilate_factor, 1e-6), 1e6)
        else:
            dilate_factor = max(min(dilate_factor, -1e-6), -1e6)

    out_axis_mode = choose_output_axis_mode(
        op,
        a["axis_mode"],
        b["axis_mode"] if b is not None else None,
        dilate_factor if op == "dilate_x" else None,
    )

    x_out = axis_grid(out_axis_mode, n)

    t0 = time.perf_counter()

    if op == "sum":
        a_re, a_im = resample_dense(a, x_out)
        b_re, b_im = resample_dense(b, x_out)
        out_re = a_re + b_re
        out_im = a_im + b_im
        comment = (
            f"Operation: sum\n"
            f"A: {Path(input_a).name}\n"
            f"B: {Path(input_b).name}\n"
            f"Output axis_mode: {out_axis_mode}"
        )

    elif op == "mul":
        a_re, a_im = resample_dense(a, x_out)
        b_re, b_im = resample_dense(b, x_out)

        # I campioni sono in interi SRAW; il prodotto è in interi^2.
        # Per riportarlo in interi SRAW occorre moltiplicare per AMP_TIME_RES
        # (equivalente a dividere per 1/AMP_TIME_RES = 100000), non per
        # 1/AMP_INT_MAX = 1/500000 che introdurrebbe un fattore 5 di errore.
        amp = c["AMP_TIME_RES"]
        out_re = (a_re * b_re - a_im * b_im) * amp
        out_im = (a_re * b_im + a_im * b_re) * amp

        comment = (
            f"Operation: mul\n"
            f"A: {Path(input_a).name}\n"
            f"B: {Path(input_b).name}\n"
            f"Output axis_mode: {out_axis_mode}"
        )

    elif op == "gain":
        out_re, out_im = resample_dense(a, x_out)
        out_re *= gain
        out_im *= gain
        comment = (
            f"Operation: gain\n"
            f"A: {Path(input_a).name}\n"
            f"Gain: {gain}\n"
            f"Output axis_mode: {out_axis_mode}"
        )

    elif op == "shift":
        if shift_unit == "time":
            shift_samples = shift_value / c["TIME_RES"]
        elif shift_unit == "freq":
            shift_samples = shift_value / c["FREQ_RES"]
        elif shift_unit == "samples":
            shift_samples = shift_value
        else:
            raise ValueError("shift_unit deve essere: samples, time, freq")

        out_re, out_im = resample_dense(a, x_out - shift_samples)

        comment = (
            f"Operation: shift\n"
            f"A: {Path(input_a).name}\n"
            f"Shift: {shift_value} ({shift_unit}) = {shift_samples} samples\n"
            f"Rule: y(x)=A(x-shift)\n"
            f"Output axis_mode: {out_axis_mode}"
        )

    elif op == "mirror_y":
        out_re, out_im = resample_dense(a, -x_out)
        comment = (
            f"Operation: mirror_y\n"
            f"A: {Path(input_a).name}\n"
            f"Rule: y(x)=A(-x)\n"
            f"Output axis_mode: {out_axis_mode}"
        )

    elif op == "conj":
        out_re, out_im = resample_dense(a, x_out)
        out_im = -out_im
        comment = (
            f"Operation: conj\n"
            f"A: {Path(input_a).name}\n"
            f"Rule: y(x)=conj(A(x))\n"
            f"Output axis_mode: {out_axis_mode}"
        )

    elif op == "dilate_x":
        out_re, out_im = resample_dense(a, x_out / dilate_factor)
        comment = (
            f"Operation: dilate_x\n"
            f"A: {Path(input_a).name}\n"
            f"Factor: {dilate_factor}\n"
            f"Rule: y(x)=A(x/factor)\n"
            f"Output axis_mode: {out_axis_mode}"
        )

    else:
        raise ValueError("Operazione non valida")

    elapsed = time.perf_counter() - t0
    comment += f"\nN={n}\nTime: {elapsed:.4f}s"

    write_dense_sraw(output_path, out_axis_mode, out_re, out_im, c, comment=comment)

    if verbose or benchmark:
        print(f"[sraw_ops] op={op}, N={n}, axis_out={out_axis_mode}, time={elapsed:.4f}s")


def main():
    parser = argparse.ArgumentParser(description="Basic SRAW operations in Python.")
    parser.add_argument("input_a", help="Input A .sraw")
    parser.add_argument("output", help="Output .sraw")
    parser.add_argument(
        "--op",
        required=True,
        choices=["sum", "mul", "gain", "shift", "mirror_y", "conj", "dilate_x"],
        help="Operation to execute"
    )
    parser.add_argument("--input-b", help="Input B .sraw (required for sum / mul)")
    parser.add_argument("--gain", type=float, default=1.0, help="Gain for op=gain")
    parser.add_argument("--shift-value", type=float, default=0.0, help="Shift value for op=shift")
    parser.add_argument(
        "--shift-unit",
        choices=["samples", "time", "freq"],
        default="samples",
        help="Shift unit for op=shift"
    )
    parser.add_argument("--dilate-factor", type=float, default=1.0, help="Factor for op=dilate_x")
    parser.add_argument("--verbose", "-v", action="store_true")
    parser.add_argument("--benchmark", "-b", action="store_true")
    args = parser.parse_args()

    try:
        run(
            input_a=args.input_a,
            output_path=args.output,
            op=args.op,
            input_b=args.input_b,
            gain=args.gain,
            shift_value=args.shift_value,
            shift_unit=args.shift_unit,
            dilate_factor=args.dilate_factor,
            verbose=args.verbose,
            benchmark=args.benchmark,
        )
    except Exception as e:
        print(f"Error: {e}", file=sys.stderr)
        sys.exit(1)


if __name__ == "__main__":
    main()