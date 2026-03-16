#!/usr/bin/env python3
"""
transformer.py — FFT / DFT / IFFT / IDFT
Fourier UNIFG Project

Tutti i parametri operativi sono letti da sraw.conf tramite get_constants().

Scelte operative:
- input axis_mode,symmetric  -> griglia fissa centrata di MAX_SAMPLES campioni
- input axis_mode,positive   -> griglia fissa positiva di MAX_SAMPLES campioni
- output FFT / DFT           -> sempre axis_mode,symmetric, MAX_SAMPLES campioni
- nessun remap artificiale dei bin di frequenza
- nessuna normalizzazione al picco
"""

import sys
import argparse
import time
from pathlib import Path

import numpy as np

sys.path.insert(0, str(Path(__file__).parent))
from sraw_lib import get_constants, read_sraw, write_sraw, SrawData


def sparse_samples(out_indices: np.ndarray, real_out: np.ndarray, imag_out: np.ndarray):
    """
    Filtra l'output denso producendo solo i knot non-zero con guardie zero adiacenti.
    Riduce drasticamente le dimensioni del file per spettri sparsi.
    """
    nonzero = np.flatnonzero((real_out != 0) | (imag_out != 0))
    if len(nonzero) == 0:
        # Segnale zero: restituisci i due estremi
        return [(int(out_indices[0]), 0, 0), (int(out_indices[-1]), 0, 0)]

    idx_set = set()
    for i in nonzero:
        idx_set.add(int(i))
        if i > 0:
            idx_set.add(int(i) - 1)
        if i < len(out_indices) - 1:
            idx_set.add(int(i) + 1)

    return [
        (int(out_indices[i]), int(real_out[i]), int(imag_out[i]))
        for i in sorted(idx_set)
    ]


def naive_dft(x_complex: np.ndarray) -> np.ndarray:
    N = len(x_complex)
    X = np.zeros(N, dtype=np.complex128)
    n = np.arange(N)
    for k in range(N):
        X[k] = np.sum(x_complex * np.exp(-2j * np.pi * k * n / N))
    return X


def naive_idft(X_complex: np.ndarray) -> np.ndarray:
    N = len(X_complex)
    x = np.zeros(N, dtype=np.complex128)
    k = np.arange(N)
    for n in range(N):
        x[n] = np.sum(X_complex * np.exp(2j * np.pi * k * n / N)) / N
    return x


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
    Costruisce una griglia uniforme di N campioni in base ad axis_mode:
    - symmetric -> x = -N/2 .. +N/2-1
    - positive  -> x = 0 .. N-1

    Restituisce:
        x_grid       : np.ndarray int64
        values_int   : np.ndarray complex128 (unità intere SRAW)
        method       : 'direct' oppure 'interpolated'
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


def build_uniform_complex_freq(data, N: int, native_df: float, freq_res: float):
    """
    Come build_uniform_complex, ma per spettri in ingresso all'IFFT.
    Gli indici SRAW del file frequenza sono su scala FREQ_RES;
    i bin FFT sono su scala native_df. Rimappa prima di interpolare.

    Indice SRAW -> frequenza fisica: f = idx * freq_res
    Frequenza fisica -> bin FFT:     k = round(f / native_df)
    """
    scale = freq_res / native_df          # es. 0.01/0.04 = 0.25

    fft_grid = symmetric_indices(N)       # bin k da -N/2 a +N/2-1

    samples = _dedupe_sorted_samples(data.samples)
    if not samples:
        return fft_grid, np.zeros(N, dtype=np.complex128), "empty"

    xs_sraw = np.fromiter((s[0] for s in samples), dtype=np.float64)
    re      = np.fromiter((s[1] for s in samples), dtype=np.float64)
    im      = np.fromiter((s[2] for s in samples), dtype=np.float64)

    # Converti indici SRAW -> bin FFT (spazio continuo per interp)
    xs_fft = xs_sraw * scale

    out_re = np.interp(fft_grid, xs_fft, re,  left=re[0],  right=re[-1])
    out_im = np.interp(fft_grid, xs_fft, im,  left=im[0],  right=im[-1])

    return fft_grid, out_re + 1j * out_im, "interpolated"


def complex_to_ints(cx_array: np.ndarray, quantum: float, amp_max: int):
    """
    Converte un array complesso fisico in interi SRAW usando il quantum indicato.
    Nessuna normalizzazione al picco.
    """
    real_ints = np.round(cx_array.real / quantum).astype(np.int64)
    imag_ints = np.round(cx_array.imag / quantum).astype(np.int64)

    real_ints = np.clip(real_ints, -amp_max, amp_max).astype(np.int32)
    imag_ints = np.clip(imag_ints, -amp_max, amp_max).astype(np.int32)

    return real_ints, imag_ints


def compute_native_df(C):
    """
    Restituisce il bin di frequenza nativo della FFT discreta:
        native_df = 1 / (MAX_SAMPLES * TIME_RES)

    Questo valore e' usato internamente per scalare lo spettro FFT in Volt*secondi.
    Non deve necessariamente coincidere con FREQ_RES: quest'ultimo e' la risoluzione
    della griglia SRAW (quantizzazione degli indici di frequenza), ed e' un parametro
    indipendente che puo' essere piu' fine di native_df.
    """
    N = C["MAX_SAMPLES"]
    return 1.0 / (N * C["TIME_RES"])


def transform(input_path, output_path, mode="fft", verbose=False, benchmark=False):
    C = get_constants()
    N = C["MAX_SAMPLES"]
    native_freq_res = compute_native_df(C)
    amp_freq_res = C["AMP_FREQ_RES"]

    if verbose:
        print(f"[transformer] Reading: {input_path}, mode={mode}")

    data = read_sraw(input_path)
    _, uniform_int, extraction_method = build_uniform_complex(data, N)

    if mode in ("fft", "dft"):
        # interi SRAW tempo -> Volt fisici
        signal_t = uniform_int * C["AMP_TIME_RES"]

        # asse simmetrico: riporto l'origine al sample 0 prima della FFT
        base = np.fft.ifftshift(signal_t) if data.axis_mode == "symmetric" else signal_t

        t0 = time.perf_counter()
        if mode == "fft":
            spectrum_f = C["TIME_RES"] * np.fft.fftshift(np.fft.fft(base))
        else:
            spectrum_f = C["TIME_RES"] * np.fft.fftshift(naive_dft(base))
        elapsed = time.perf_counter() - t0

        # Volt*secondi -> interi SRAW frequenza
        real_out, imag_out = complex_to_ints(
            spectrum_f,
            quantum=amp_freq_res,
            amp_max=C["AMP_INT_MAX"]
        )

        # I bin FFT k hanno frequenza fisica k * native_df.
        # L'indice SRAW corrispondente è round(k * native_df / FREQ_RES).
        # Con native_df != FREQ_RES (es. 0.04 vs 0.01) i bin vanno
        # rimappati sulla griglia SRAW, non usati direttamente come indici.
        native_df = compute_native_df(C)
        freq_res  = C["FREQ_RES"]
        scale     = native_df / freq_res          # es. 0.04/0.01 = 4.0
        fft_bins  = symmetric_indices(N)          # k da -N/2 a +N/2-1
        out_indices = np.round(fft_bins * scale).astype(np.int64)
        samples_out = sparse_samples(out_indices, real_out, imag_out)
        data_out = SrawData(axis_mode="symmetric", samples=samples_out)

    elif mode in ("ifft", "idft"):
        # interi SRAW frequenza -> Volt*secondi fisici
        # Rimappa gli indici SRAW freq -> bin FFT prima dell'interpolazione
        native_df = compute_native_df(C)
        _, uniform_int, extraction_method = build_uniform_complex_freq(
            data, N, native_df, C["FREQ_RES"]
        )
        spectrum_f = uniform_int * amp_freq_res

        base = np.fft.ifftshift(spectrum_f) if data.axis_mode == "symmetric" else spectrum_f

        t0 = time.perf_counter()
        if mode == "ifft":
            signal_t = (1.0 / C["TIME_RES"]) * np.fft.ifft(base)
        else:
            signal_t = (1.0 / C["TIME_RES"]) * naive_idft(base)

        if data.axis_mode == "symmetric":
            signal_t = np.fft.fftshift(signal_t)

        elapsed = time.perf_counter() - t0

        # Volt fisici -> interi SRAW tempo
        real_out, imag_out = complex_to_ints(
            signal_t,
            quantum=C["AMP_TIME_RES"],
            amp_max=C["AMP_INT_MAX"]
        )

        # Analogo al forward: i bin IFFT k corrispondono all'indice tempo
        # k * native_dt / TIME_RES, dove native_dt = TIME_RES (già 1:1 per il tempo).
        # Il dominio del tempo non ha disallineamento: l'indice SRAW coincide
        # con il bin IFFT perché TIME_RES è la risoluzione nativa del campionamento.
        if data.axis_mode == "symmetric":
            out_indices = symmetric_indices(N)
        else:
            out_indices = positive_indices(N)
        samples_out = sparse_samples(out_indices, real_out, imag_out)
        data_out = SrawData(axis_mode=data.axis_mode, samples=samples_out)

    else:
        raise ValueError(f"Unknown mode: '{mode}'. Choose: fft, dft, ifft, idft.")

    comment = (
        f"Transform of: {Path(input_path).name}\n"
        f"Mode: {mode}, N={N}, extraction: {extraction_method}, "
        f"time: {elapsed:.4f}s, time_res={C['TIME_RES']}, freq_res={native_freq_res}, "
        f"amp_time_res={C['AMP_TIME_RES']}, amp_freq_res={amp_freq_res}"
    )

    write_sraw(data_out, output_path, comment=comment)

    if verbose or benchmark:
        print(
            f"[transformer] mode={mode}, N={N}, extraction={extraction_method}, "
            f"time={elapsed:.4f}s, time_res={C['TIME_RES']}, freq_res={native_freq_res}, "
            f"amp_time_res={C['AMP_TIME_RES']}, amp_freq_res={amp_freq_res}"
        )


def main():
    parser = argparse.ArgumentParser(description="FFT / DFT / IFFT / IDFT on SRAW files.")
    parser.add_argument("input", help="Input .sraw file")
    parser.add_argument("output", help="Output .sraw file")
    parser.add_argument(
        "--mode",
        choices=["fft", "dft", "ifft", "idft"],
        default="fft",
        help="Transform mode (default: fft)"
    )
    parser.add_argument("--verbose", "-v", action="store_true")
    parser.add_argument("--benchmark", "-b", action="store_true")
    args = parser.parse_args()

    try:
        transform(
            input_path=args.input,
            output_path=args.output,
            mode=args.mode,
            verbose=args.verbose,
            benchmark=args.benchmark,
        )
    except Exception as e:
        print(f"Error: {e}", file=sys.stderr)
        sys.exit(1)


if __name__ == "__main__":
    main()