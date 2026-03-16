#!/usr/bin/env python3
"""
sraw_to_mp3.py — Convert a SRAW file (time domain) to MP3
Fourier UNIFG Project

Scelte semantiche:
- MP3 <-> SRAW vale solo per il dominio del tempo
- in export si considera solo il tempo positivo:
  * axis_mode=positive  -> da x=0 in poi
  * axis_mode=symmetric -> solo x>=0
- default: si usa la parte reale
- supporto opzionale: imag, modulus
"""

import sys
import argparse
import numpy as np
from pathlib import Path
import subprocess
import tempfile
import os
import wave
import shutil

sys.path.insert(0, str(Path(__file__).parent))
from sraw_lib import load_conf, get_constants, read_sraw


def resolve_ffmpeg_path(conf: dict) -> str:
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


def dedupe_sorted_samples(samples):
    if not samples:
        return []

    samples = sorted(samples, key=lambda s: s[0])
    out = [samples[0]]
    for s in samples[1:]:
        if s[0] == out[-1][0]:
            out[-1] = s
        else:
            out.append(s)
    return out


def build_positive_time_arrays(data, C):
    """
    Ricostruisce il tratto temporale x>=0 su griglia uniforme intera.
    Usa interpolazione lineare sui campioni espliciti.
    Fuori dal supporto noto: zero.
    """
    samples = dedupe_sorted_samples(data.samples)
    xs_all = np.array([s[0] for s in samples], dtype=np.float64)
    re_all = np.array([s[1] for s in samples], dtype=np.float64)
    im_all = np.array([s[2] for s in samples], dtype=np.float64)

    if data.axis_mode == "positive":
        x_start = 0
        x_end_limit = C["MAX_SAMPLES"] - 1
    else:
        x_start = 0
        x_end_limit = (C["MAX_SAMPLES"] // 2) - 1

    xs_nonneg = xs_all[xs_all >= 0]
    if xs_nonneg.size == 0:
        raise ValueError("Il file non contiene alcun tratto a tempo positivo esportabile in MP3.")

    x_end = int(min(np.max(xs_nonneg), x_end_limit))
    if x_end < x_start:
        raise ValueError("Intervallo positivo non valido per export MP3.")

    x_grid = np.arange(x_start, x_end + 1, dtype=np.float64)

    # Interpolazione lineare sul set completo, con zero fuori dal supporto noto
    re = np.interp(x_grid, xs_all, re_all, left=0.0, right=0.0)
    im = np.interp(x_grid, xs_all, im_all, left=0.0, right=0.0)

    return x_grid.astype(np.int64), re, im


def choose_signal_part(re: np.ndarray, im: np.ndarray, part: str) -> np.ndarray:
    if part == "real":
        return re
    if part == "imag":
        return im
    if part == "modulus":
        return np.sqrt(re * re + im * im)
    raise ValueError(f"Parte non valida: {part}")


def write_wav_pcm16(wav_path: str, pcm_int16: np.ndarray, sample_rate: int):
    with wave.open(wav_path, "wb") as wavf:
        wavf.setnchannels(1)
        wavf.setsampwidth(2)
        wavf.setframerate(sample_rate)
        wavf.writeframes(pcm_int16.tobytes())


def sraw_to_mp3(input_path, output_path, bitrate="128k", part="real", verbose=False):
    conf = load_conf()
    C = get_constants(conf)
    ffmpeg_path = resolve_ffmpeg_path(conf)

    sraw_sr = int(round(1.0 / C["TIME_RES"]))
    mp3_out_sr = int(conf.get("MP3_EXPORT_SAMPLE_RATE_HZ", "44100"))

    if verbose:
        print(f"[sraw_to_mp3] Input: {input_path}")
        print(f"[sraw_to_mp3] Output: {output_path}")
        print(f"[sraw_to_mp3] ffmpeg: {ffmpeg_path}")
        print(f"[sraw_to_mp3] TIME_RES: {C['TIME_RES']} s")
        print(f"[sraw_to_mp3] Internal WAV rate: {sraw_sr} Hz")
        print(f"[sraw_to_mp3] MP3 output rate: {mp3_out_sr} Hz")
        print(f"[sraw_to_mp3] Selected part: {part}")

    data = read_sraw(input_path)

    x_grid, re, im = build_positive_time_arrays(data, C)
    signal_int = choose_signal_part(re, im, part)

    amp_max = C["AMP_INT_MAX"]
    signal_norm = np.clip(signal_int / amp_max, -1.0, 1.0)
    pcm_int16 = np.round(signal_norm * 32767.0).astype(np.int16)

    if verbose:
        duration_s = len(pcm_int16) * C["TIME_RES"]
        print(f"[sraw_to_mp3] axis_mode: {data.axis_mode}")
        print(f"[sraw_to_mp3] Exported positive samples: {len(pcm_int16)}")
        print(f"[sraw_to_mp3] Duration: {duration_s:.6f} s")

    with tempfile.NamedTemporaryFile(suffix=".wav", delete=False) as tmp:
        tmp_wav = tmp.name

    try:
        write_wav_pcm16(tmp_wav, pcm_int16, sraw_sr)

        cmd = [
            ffmpeg_path,
            "-y",
            "-i", tmp_wav,
            "-ar", str(mp3_out_sr),
            "-ac", "1",
            "-b:a", bitrate,
            "-f", "mp3",
            output_path,
        ]

        if verbose:
            print(f"[sraw_to_mp3] Running ffmpeg: {' '.join(cmd)}")

        result = subprocess.run(cmd, capture_output=True, text=True)
        if result.returncode != 0:
            raise RuntimeError(f"ffmpeg error:\n{result.stderr}")

        if verbose:
            print(f"[sraw_to_mp3] Written: {output_path}")

    finally:
        if os.path.exists(tmp_wav):
            os.unlink(tmp_wav)


def main():
    parser = argparse.ArgumentParser(description="Convert SRAW to MP3 (time domain only, positive time only).")
    parser.add_argument("input", help="Input .sraw file path")
    parser.add_argument("output", help="Output .mp3 file path")
    parser.add_argument("--bitrate", default="128k", help="MP3 bitrate (default: 128k)")
    parser.add_argument("--part", choices=["real", "imag", "modulus"], default="real")
    parser.add_argument("--verbose", "-v", action="store_true")
    args = parser.parse_args()

    try:
        sraw_to_mp3(
            args.input,
            args.output,
            bitrate=args.bitrate,
            part=args.part,
            verbose=args.verbose
        )
        print(f"OK: {args.output}")
        sys.exit(0)
    except Exception as e:
        print(f"ERROR: {e}", file=sys.stderr)
        sys.exit(1)


if __name__ == "__main__":
    main()