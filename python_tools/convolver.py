#!/usr/bin/env python3
"""
convolver.py — Convolution / correlation on SRAW files
Fourier UNIFG Project

Evoluzione allineata a transformer.py:
- tutti i parametri letti dinamicamente da sraw.conf tramite get_constants()
- ricostruzione su griglia uniforme fissa da MAX_SAMPLES campioni
- supporto axis_mode,symmetric e axis_mode,positive
- operazioni FFT-based, senza normalizzazione al picco
- output sempre su griglia fissa di MAX_SAMPLES campioni
- clipping finale al range intero SRAW
"""

import sys
import argparse
import time
from pathlib import Path

import numpy as np

sys.path.insert(0, str(Path(__file__).parent))
from sraw_lib import get_constants, read_sraw, write_sraw, SrawData


def symmetric_indices(N: int) -> np.ndarray:
    return np.arange(-(N // 2), N // 2, dtype=np.int64)


def positive_indices(N: int) -> np.ndarray:
    return np.arange(0, N, dtype=np.int64)


def _dedupe_sorted_samples(samples):
    if not samples:
        return []

    out = [samples[0]]
    for sample in samples[1:]:
        if sample[0] == out[-1][0]:
            out[-1] = sample
        else:
            out.append(sample)
    return out


def build_uniform_complex(data: SrawData, N: int):
    """
    Ricostruisce una griglia uniforme di N campioni in base ad axis_mode.

    Restituisce:
        x_grid     : np.ndarray int64
        values_int : np.ndarray complex128 (unità intere SRAW)
        method     : 'direct' | 'interpolated' | 'empty'
    """
    x_grid = symmetric_indices(N) if data.axis_mode == "symmetric" else positive_indices(N)

    samples = _dedupe_sorted_samples(data.samples)
    if not samples:
        return x_grid, np.zeros(N, dtype=np.complex128), "empty"

    xs = np.fromiter((s[0] for s in samples), dtype=np.int64)
    re = np.fromiter((s[1] for s in samples), dtype=np.float64)
    im = np.fromiter((s[2] for s in samples), dtype=np.float64)

    if len(xs) == N and np.array_equal(xs, x_grid):
        return x_grid, re + 1j * im, "direct"

    out_re = np.interp(x_grid, xs, re, left=re[0], right=re[-1])
    out_im = np.interp(x_grid, xs, im, left=im[0], right=im[-1])

    return x_grid, out_re + 1j * out_im, "interpolated"


def complex_to_ints(cx_array: np.ndarray, quantum: float, amp_max: int):
    real_ints = np.round(cx_array.real / quantum).astype(np.int64)
    imag_ints = np.round(cx_array.imag / quantum).astype(np.int64)

    real_ints = np.clip(real_ints, -amp_max, amp_max).astype(np.int32)
    imag_ints = np.clip(imag_ints, -amp_max, amp_max).astype(np.int32)

    return real_ints, imag_ints


def next_pow2(n: int) -> int:
    return 1 << (n - 1).bit_length()


def fft_linear_convolution(a: np.ndarray, b: np.ndarray) -> np.ndarray:
    full_len = len(a) + len(b) - 1
    fft_len = next_pow2(full_len)
    fa = np.fft.fft(a, fft_len)
    fb = np.fft.fft(b, fft_len)
    out = np.fft.ifft(fa * fb)
    return out[:full_len]


def fft_cross_correlation(a: np.ndarray, b: np.ndarray) -> np.ndarray:
    """
    R_ab[l] = sum_n a[n] * conj(b[n-l])  (full)
    """
    full_len = len(a) + len(b) - 1
    fft_len = next_pow2(full_len)
    fa = np.fft.fft(a, fft_len)
    fb = np.fft.fft(b, fft_len)
    out = np.fft.ifft(fa * np.conj(fb))
    return np.concatenate((out[-(len(b) - 1):], out[:len(a)]))


def select_domain_constants(C, domain: str):
    if domain == "time":
        return C["TIME_RES"], C["AMP_TIME_RES"], "s", "V"
    if domain == "freq":
        return C["FREQ_RES"], C["AMP_FREQ_RES"], "Hz", "Vs"
    raise ValueError(f"Unknown domain: {domain}")


def place_on_output_grid(x_full: np.ndarray, y_full: np.ndarray, N_out: int):
    """
    Riporta il risultato pieno sulla griglia standard simmetrica di uscita.
    I campioni fuori finestra vengono scartati; i buchi vengono riempiti a zero.
    """
    x_out = symmetric_indices(N_out)
    out = np.zeros(N_out, dtype=np.complex128)

    mask = (x_full >= x_out[0]) & (x_full <= x_out[-1])
    if np.any(mask):
        x_keep = x_full[mask]
        y_keep = y_full[mask]
        out[(x_keep - x_out[0]).astype(np.int64)] = y_keep

    return x_out, out


def run(input_a, input_b, output_path, mode="conv", domain="time", verbose=False, benchmark=False):
    C = get_constants()
    N = C["MAX_SAMPLES"]
    axis_step, amp_quantum, axis_unit, amp_unit = select_domain_constants(C, domain)

    if verbose:
        print(f"[convolver] Reading A: {input_a}")
    data_a = read_sraw(input_a)
    x_a, a_int, method_a = build_uniform_complex(data_a, N)

    if mode in ("conv", "xcorr"):
        if verbose:
            print(f"[convolver] Reading B: {input_b}")
        data_b = read_sraw(input_b)
        x_b, b_int, method_b = build_uniform_complex(data_b, N)
    else:
        data_b = None
        x_b = None
        b_int = None
        method_b = "unused"

    a_phys = a_int * amp_quantum
    if b_int is not None:
        b_phys = b_int * amp_quantum

    t0 = time.perf_counter()

    if mode == "conv":
        y_full = axis_step * fft_linear_convolution(a_phys, b_phys)
        x_full = np.arange(x_a[0] + x_b[0], x_a[-1] + x_b[-1] + 1, dtype=np.int64)
        op_name = "conv(A, B)"

    elif mode == "corr":
        y_full = axis_step * fft_cross_correlation(a_phys, a_phys)
        x_full = np.arange(x_a[0] - x_a[-1], x_a[-1] - x_a[0] + 1, dtype=np.int64)
        op_name = "autocorr(A)"

    elif mode == "xcorr":
        y_full = axis_step * fft_cross_correlation(a_phys, b_phys)
        x_full = np.arange(x_a[0] - x_b[-1], x_a[-1] - x_b[0] + 1, dtype=np.int64)
        op_name = "xcorr(A, B)"

    else:
        raise ValueError(f"Unknown mode: '{mode}'. Choose: conv, corr, xcorr.")

    elapsed = time.perf_counter() - t0

    x_out, y_out = place_on_output_grid(x_full, y_full, N_out=N)
    real_out, imag_out = complex_to_ints(y_out, quantum=amp_quantum, amp_max=C["AMP_INT_MAX"])

    samples_out = [
        (int(x_out[i]), int(real_out[i]), int(imag_out[i]))
        for i in range(N)
    ]
    data_out = SrawData(axis_mode="symmetric", samples=samples_out)

    comment_lines = [
        f"Operation: {op_name}",
        f"Domain: {domain}",
        f"N={N}",
        f"Axis step: {axis_step} {axis_unit}",
        f"Amplitude quantum: {amp_quantum} {amp_unit}",
        f"A: {Path(input_a).name}, axis_mode={data_a.axis_mode}, extraction={method_a}",
    ]
    if mode in ("conv", "xcorr"):
        comment_lines.append(
            f"B: {Path(input_b).name}, axis_mode={data_b.axis_mode}, extraction={method_b}"
        )
    comment_lines.extend([
        f"Full result length: {len(y_full)}",
        f"Output grid: symmetric, {N} samples",
        f"Time: {elapsed:.4f}s",
    ])

    write_sraw(data_out, output_path, comment="\n".join(comment_lines))

    if verbose or benchmark:
        print(
            f"[convolver] mode={mode}, domain={domain}, N={N}, "
            f"A={method_a}, B={method_b}, full_len={len(y_full)}, time={elapsed:.4f}s"
        )


def main():
    parser = argparse.ArgumentParser(description="Convolution / correlation on SRAW files.")
    parser.add_argument("input_a", help="Input A .sraw file")
    parser.add_argument("input_b", help="Input B .sraw file (ignored for --mode corr)")
    parser.add_argument("output", help="Output .sraw file")
    parser.add_argument(
        "--mode",
        choices=["conv", "corr", "xcorr"],
        default="conv",
        help="Operation mode (default: conv)"
    )
    parser.add_argument(
        "--domain",
        choices=["time", "freq"],
        default="time",
        help="Axis/amplitude quantum to use for physical scaling (default: time)"
    )
    parser.add_argument("--verbose", "-v", action="store_true")
    parser.add_argument("--benchmark", "-b", action="store_true")
    args = parser.parse_args()

    try:
        run(
            input_a=args.input_a,
            input_b=args.input_b,
            output_path=args.output,
            mode=args.mode,
            domain=args.domain,
            verbose=args.verbose,
            benchmark=args.benchmark,
        )
    except Exception as e:
        print(f"Error: {e}", file=sys.stderr)
        sys.exit(1)


if __name__ == "__main__":
    main()