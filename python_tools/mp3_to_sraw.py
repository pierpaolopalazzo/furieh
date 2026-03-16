#!/usr/bin/env python3
"""
mp3_to_sraw.py — Convert an MP3 file to SRAW format
Fourier UNIFG Project

Scelte semantiche:
- MP3 <-> SRAW vale solo per il dominio del tempo
- l'import da MP3 genera sempre axis_mode=positive
- si usa solo la parte reale (imag=0)
- nessuna normalizzazione al picco del file
- la scala assoluta dipende da AMP_TIME_RESOLUTION_V
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
from sraw_lib import load_conf, get_constants, SrawData, write_sraw


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


def decode_mp3_to_wav(input_path: str, target_sr: int, ffmpeg_path: str) -> str:
    """
    Decodifica MP3 -> WAV PCM 16-bit con sample rate fissato a target_sr.
    Non forza il mono: i canali restano quelli del file.
    """
    with tempfile.NamedTemporaryFile(suffix=".wav", delete=False) as tmp:
        tmp_path = tmp.name

    cmd = [
        ffmpeg_path,
        "-y",
        "-i", input_path,
        "-vn",
        "-acodec", "pcm_s16le",
        "-ar", str(target_sr),
        tmp_path,
    ]

    result = subprocess.run(cmd, capture_output=True, text=True)
    if result.returncode != 0:
        if os.path.exists(tmp_path):
            os.unlink(tmp_path)
        raise RuntimeError(f"ffmpeg error:\n{result.stderr}")

    return tmp_path


def load_wav_channels(wav_path: str):
    with wave.open(wav_path, "rb") as wavf:
        n_channels = wavf.getnchannels()
        sample_width = wavf.getsampwidth()
        frame_rate = wavf.getframerate()
        n_frames = wavf.getnframes()
        raw = wavf.readframes(n_frames)

    if sample_width != 2:
        raise RuntimeError(f"WAV temporaneo inatteso: {sample_width * 8} bit invece di 16 bit")

    pcm = np.frombuffer(raw, dtype=np.int16)
    if pcm.size == 0:
        return np.zeros((0, 1), dtype=np.float32), frame_rate

    pcm = pcm.reshape(-1, n_channels).astype(np.float32) / 32768.0
    return pcm, frame_rate


def choose_channel(pcm: np.ndarray, channel: str) -> np.ndarray:
    """
    pcm shape = (n_samples, n_channels), float32 in [-1, 1]
    """
    if pcm.ndim != 2 or pcm.shape[0] == 0:
        return np.zeros(0, dtype=np.float32)

    n_channels = pcm.shape[1]

    if n_channels == 1:
        return pcm[:, 0].copy()

    if channel == "L":
        return pcm[:, 0].copy()

    if channel == "R":
        idx = 1 if n_channels >= 2 else 0
        return pcm[:, idx].copy()

    # MIX
    return pcm.mean(axis=1).astype(np.float32)


def mp3_to_sraw(input_path, output_path, channel="MIX", verbose=False):
    conf = load_conf()
    C = get_constants(conf)
    ffmpeg_path = resolve_ffmpeg_path(conf)

    sraw_sr = int(round(1.0 / C["TIME_RES"]))
    amp_max = C["AMP_INT_MAX"]

    if verbose:
        print(f"[mp3_to_sraw] Input: {input_path}")
        print(f"[mp3_to_sraw] Output: {output_path}")
        print(f"[mp3_to_sraw] ffmpeg: {ffmpeg_path}")
        print(f"[mp3_to_sraw] TIME_RES: {C['TIME_RES']} s")
        print(f"[mp3_to_sraw] Sample rate target: {sraw_sr} Hz")
        print(f"[mp3_to_sraw] Channel mode: {channel}")

    wav_path = decode_mp3_to_wav(input_path, sraw_sr, ffmpeg_path)

    try:
        pcm_all, src_sr = load_wav_channels(wav_path)
        pcm = choose_channel(pcm_all, channel)

        if verbose:
            print(f"[mp3_to_sraw] Decoded WAV rate: {src_sr} Hz")
            print(f"[mp3_to_sraw] Channels in WAV: {pcm_all.shape[1] if pcm_all.ndim == 2 else 1}")
            print(f"[mp3_to_sraw] Selected samples: {len(pcm)}")

        if len(pcm) > C["MAX_SAMPLES"]:
            if verbose:
                print(
                    f"[mp3_to_sraw] Troncamento: {len(pcm)} -> {C['MAX_SAMPLES']} campioni "
                    f"({C['MAX_SAMPLES'] * C['TIME_RES']:.6f} s)"
                )
            pcm = pcm[:C["MAX_SAMPLES"]]

        real_ints = np.round(pcm * amp_max).astype(np.int32)
        real_ints = np.clip(real_ints, -amp_max, amp_max)

        samples = [(int(i), int(real_ints[i]), 0) for i in range(len(real_ints))]
        data = SrawData(axis_mode="positive", samples=samples)

        peak_in = float(np.max(np.abs(pcm))) if len(pcm) else 0.0
        comment = (
            f"Converted from MP3: {Path(input_path).name}\n"
            f"Channel: {channel}\n"
            f"SRAW domain: time\n"
            f"axis_mode: positive\n"
            f"TIME_RES: {C['TIME_RES']} s\n"
            f"Sample rate: {sraw_sr} Hz\n"
            f"Imported samples: {len(real_ints)}\n"
            f"Input peak (no renormalization): {peak_in:.6f}"
        )

        write_sraw(data, output_path, comment=comment)

        if verbose:
            duration_s = len(real_ints) * C["TIME_RES"]
            print(f"[mp3_to_sraw] Written: {output_path}")
            print(f"[mp3_to_sraw] Duration: {duration_s:.6f} s")

    finally:
        if os.path.exists(wav_path):
            os.unlink(wav_path)


def main():
    parser = argparse.ArgumentParser(description="Convert MP3 to SRAW (time domain only).")
    parser.add_argument("input", help="Input MP3 file path")
    parser.add_argument("output", help="Output .sraw file path")
    parser.add_argument("--channel", choices=["L", "R", "MIX"], default="MIX")
    parser.add_argument("--verbose", "-v", action="store_true")
    args = parser.parse_args()

    try:
        mp3_to_sraw(args.input, args.output, channel=args.channel, verbose=args.verbose)
        print(f"OK: {args.output}")
        sys.exit(0)
    except Exception as e:
        print(f"ERROR: {e}", file=sys.stderr)
        sys.exit(1)


if __name__ == "__main__":
    main()