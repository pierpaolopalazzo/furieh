"""
lpc_vocoder.py — Mini-vocoder LPC didattico
furiè UniFG 0.0.3-alpha — gauss-hamming

Puro Python + numpy. Nessuna dipendenza di sistema.
Patch qualità: quantizzazione diretta dei coeff. LPC, senza passaggio LSP.
"""

import numpy as np

# --- Parametri del vocoder ---
LPC_ORDER  = 8
FRAME_MS   = 72
GAIN_BITS  = 6
VOICED_BIT = 1
PITCH_BITS = 6
LPC_BITS   = 8
BITS_PER_FRAME = LPC_ORDER * LPC_BITS + GAIN_BITS + VOICED_BIT + PITCH_BITS  # 77

PITCH_MIN = 20
PITCH_MAX = 83


# =====================================================================
# Analisi LPC
# =====================================================================

def _autocorrelation(x, order):
    n = len(x)
    r = np.zeros(order + 1)
    for k in range(order + 1):
        r[k] = np.dot(x[:n - k], x[k:])
    return r


def _levinson_durbin(r, order):
    a = np.zeros(order + 1)
    a[0] = 1.0
    e = r[0]
    if e == 0:
        return np.zeros(order), 0.0
    for i in range(1, order + 1):
        lam = (r[i] - sum(a[j] * r[i - j] for j in range(1, i))) / e
        a_new = np.copy(a)
        a_new[i] = lam
        for j in range(1, i):
            a_new[j] = a[j] - lam * a[i - j]
        a = a_new
        e *= (1.0 - lam * lam)
        if e <= 0:
            e = 1e-10
    return a[1:], e


def _lpc_analysis(frame, order):
    pre = np.append(frame[0], frame[1:] - 0.97 * frame[:-1])
    windowed = pre * np.hamming(len(pre))
    r = _autocorrelation(windowed, order)
    if r[0] == 0:
        return np.zeros(order), 0.0
    coeffs, e = _levinson_durbin(r, order)
    return coeffs, np.sqrt(max(e, 1e-10))


# =====================================================================
# Pitch detection
# =====================================================================

def _detect_pitch(frame, sr=8000):
    if np.max(np.abs(frame)) < 50:
        return False, PITCH_MIN
    r = np.correlate(frame, frame, mode='full')[len(frame) - 1:]
    if r[0] == 0:
        return False, PITCH_MIN
    r = r / r[0]
    search = r[PITCH_MIN:PITCH_MAX + 1]
    if not len(search):
        return False, PITCH_MIN
    peak_idx = np.argmax(search)
    return search[peak_idx] > 0.3, peak_idx + PITCH_MIN


# =====================================================================
# Quantizzazione
# =====================================================================

def _quantize_lpc(coeffs):
    coeffs = np.asarray(coeffs, dtype=float)
    coeffs = np.clip(coeffs, -1.99, 1.99)
    levels = 2 ** LPC_BITS - 1
    return np.round((coeffs + 1.99) / 3.98 * levels).astype(int)


def _dequantize_lpc(q):
    q = np.asarray(q, dtype=float)
    levels = 2 ** LPC_BITS - 1
    return (q / levels) * 3.98 - 1.99


def _quantize_gain(gain):
    if gain <= 0:
        return 0
    norm = (np.log2(max(gain, 1e-10)) + 10) / 26.0
    return int(np.clip(np.round(norm * (2 ** GAIN_BITS - 1)), 0, 2 ** GAIN_BITS - 1))


def _dequantize_gain(q):
    return 2.0 ** ((q / (2 ** GAIN_BITS - 1)) * 26.0 - 10.0)


# =====================================================================
# Bit packing
# =====================================================================

def _pack_frames(frames_data):
    bits = []
    for lpc_q, gain_q, voiced, pitch_q in frames_data:
        for c in lpc_q:
            for b in range(LPC_BITS - 1, -1, -1):
                bits.append((int(c) >> b) & 1)
        for b in range(GAIN_BITS - 1, -1, -1):
            bits.append((int(gain_q) >> b) & 1)
        bits.append(1 if voiced else 0)
        for b in range(PITCH_BITS - 1, -1, -1):
            bits.append((int(pitch_q) >> b) & 1)
    while len(bits) % 8:
        bits.append(0)
    result = bytearray()
    for i in range(0, len(bits), 8):
        byte = 0
        for j in range(8):
            byte = (byte << 1) | (bits[i + j] if i + j < len(bits) else 0)
        result.append(byte)
    return bytes(result)


def _unpack_frames(data, n_frames):
    bits = []
    for byte in data:
        for b in range(7, -1, -1):
            bits.append((byte >> b) & 1)
    frames = []
    pos = 0
    for _ in range(n_frames):
        if pos + BITS_PER_FRAME > len(bits):
            break
        lpc_q = np.zeros(LPC_ORDER, dtype=int)
        for i in range(LPC_ORDER):
            val = 0
            for _ in range(LPC_BITS):
                val = (val << 1) | bits[pos]
                pos += 1
            lpc_q[i] = val
        gain_q = 0
        for _ in range(GAIN_BITS):
            gain_q = (gain_q << 1) | bits[pos]
            pos += 1
        voiced = bits[pos] == 1
        pos += 1
        pitch_q = 0
        for _ in range(PITCH_BITS):
            pitch_q = (pitch_q << 1) | bits[pos]
            pos += 1
        frames.append((lpc_q, gain_q, voiced, pitch_q))
    return frames


# =====================================================================
# Sintesi
# =====================================================================

def _check_stability(coeffs):
    a_poly = np.concatenate(([1.0], -coeffs))
    roots = np.roots(a_poly)
    if len(roots) and np.max(np.abs(roots)) >= 0.99:
        for i in range(len(roots)):
            if np.abs(roots[i]) >= 0.99:
                roots[i] = roots[i] / np.abs(roots[i]) * 0.98
        new_poly = np.real(np.poly(roots))
        coeffs = -new_poly[1:len(coeffs) + 1]
    return coeffs


def _synthesize_frame(coeffs, gain, voiced, pitch, frame_len):
    coeffs = _check_stability(coeffs)
    if voiced and pitch > 0:
        exc = np.zeros(frame_len)
        p = 0
        while p < frame_len:
            exc[int(p)] = 1.0
            p += pitch
    else:
        exc = np.random.randn(frame_len) * 0.5
    exc *= gain
    order = len(coeffs)
    y = np.zeros(frame_len)
    for n in range(frame_len):
        y[n] = exc[n]
        for k in range(order):
            if n - k - 1 >= 0:
                y[n] += coeffs[k] * y[n - k - 1]
        y[n] = max(-1e6, min(1e6, y[n]))
    return y


# =====================================================================
# Interfaccia pubblica
# =====================================================================

def lpc_encode(samples, sr=8000):
    """Codifica campioni int16 mono 8kHz → bytes."""
    samples = np.array(samples, dtype=np.float64)
    frame_len = int(sr * FRAME_MS / 1000)
    n_frames = max(1, int(np.ceil(len(samples) / frame_len)))
    total = n_frames * frame_len
    if len(samples) < total:
        samples = np.pad(samples, (0, total - len(samples)))
    else:
        samples = samples[:total]

    frames_data = []
    for i in range(n_frames):
        frame = samples[i * frame_len:(i + 1) * frame_len]
        coeffs, gain = _lpc_analysis(frame, LPC_ORDER)
        voiced, pitch = _detect_pitch(frame, sr)
        frames_data.append((
            _quantize_lpc(coeffs),
            _quantize_gain(gain),
            voiced,
            int(np.clip(pitch - PITCH_MIN, 0, 2 ** PITCH_BITS - 1))
        ))

    bitstream = _pack_frames(frames_data)
    meta = {
        'n_frames': n_frames,
        'sr': sr,
        'frame_len': frame_len,
        'bits_per_frame': BITS_PER_FRAME,
        'total_bits': n_frames * BITS_PER_FRAME,
        'total_bytes': len(bitstream)
    }
    return bitstream, meta


def lpc_decode(bitstream, n_frames, sr=8000):
    """Decodifica bytes → campioni int16."""
    frame_len = int(sr * FRAME_MS / 1000)
    frames = _unpack_frames(bitstream, n_frames)
    output = np.zeros(n_frames * frame_len)
    for i, (lpc_q, gain_q, voiced, pitch_q) in enumerate(frames):
        gain = _dequantize_gain(gain_q)
        coeffs = _dequantize_lpc(lpc_q)
        output[i * frame_len:(i + 1) * frame_len] = _synthesize_frame(
            coeffs, gain, voiced, pitch_q + PITCH_MIN, frame_len)
    for i in range(1, len(output)):
        output[i] += 0.97 * output[i - 1]
    peak = np.max(np.abs(output))
    if peak > 0:
        output = output / peak * 30000
    return np.clip(output, -32768, 32767).astype(np.int16)


# =====================================================================
# µ-law
# =====================================================================

MU = 255


def mulaw_encode(samples):
    s = np.array(samples, dtype=np.float64) / 32768.0
    c = np.sign(s) * np.log1p(MU * np.abs(s)) / np.log1p(MU)
    return ((c + 1.0) / 2.0 * 255).astype(np.uint8)


def mulaw_decode(encoded):
    y = np.array(encoded, dtype=np.float64) / 255.0 * 2.0 - 1.0
    return (np.sign(y) * (1.0 / MU) * ((1.0 + MU) ** np.abs(y) - 1.0) * 32768).astype(np.int16)
