"""
gauss_hamming.py — Pipeline "Integrity" per furiè UniFG 0.0.3-alpha

Usa sraw_lib.py per leggere/scrivere file SRAW-1.1.
Usa lpc_vocoder.py per la compressione audio.

Uso CLI (da php via run_python):
  gauss_hamming.py encode_audio  <input.wav> <output.json>
  gauss_hamming.py decode_audio  <input.json> <output_dir/>
  gauss_hamming.py encode_text   <text>       <output.json> [--errors N] [--mode spread|random|mixed]
  gauss_hamming.py decode_text   <input.json> <output.txt>
  gauss_hamming.py analyze_sraw  <input.sraw> <output.json>
  gauss_hamming.py samples_to_sraw <input.json> <output.sraw>
"""

import sys, os, json, wave
import numpy as np

# Percorso relativo: siamo in python_tools/
sys.path.insert(0, os.path.dirname(os.path.abspath(__file__)))
from lpc_vocoder import lpc_encode, lpc_decode, mulaw_encode, mulaw_decode
from sraw_lib import SrawData, write_sraw, read_sraw, get_constants, make_constants


# =====================================================================
# Hamming(7,4)
# =====================================================================

_H = [[1,0,1,0,1,0,1],[0,1,1,0,0,1,1],[0,0,0,1,1,1,1]]

def _ham_enc(d):
    p1=d[0]^d[1]^d[3]; p2=d[0]^d[2]^d[3]; p3=d[1]^d[2]^d[3]
    return [p1,p2,d[0],p3,d[1],d[2],d[3]]

def _ham_dec(r):
    s=[0,0,0]
    for i in range(3):
        for j in range(7): s[i]^=(_H[i][j]&r[j])
    syn=s[0]+s[1]*2+s[2]*4; c=list(r)
    if 0<syn<=7: c[syn-1]^=1
    return [c[2],c[4],c[5],c[6]], syn

def hamming_encode(data_bytes):
    bits=[]
    for b in data_bytes:
        for i in range(7,-1,-1): bits.append((b>>i)&1)
    while len(bits)%4: bits.append(0)
    enc=[]
    for i in range(0,len(bits),4): enc.extend(_ham_enc(bits[i:i+4]))
    return enc

def hamming_decode(enc_bits):
    while len(enc_bits)%7: enc_bits.append(0)
    data_bits=[]; syndromes=[]
    for i in range(0,len(enc_bits),7):
        d,syn=_ham_dec(enc_bits[i:i+7])
        data_bits.extend(d)
        syndromes.append({'block':i//7,'syndrome':syn,'error_pos':syn if syn>0 else None})
    result=bytearray()
    for i in range(0,len(data_bits)-7,8):
        byte=0
        for j in range(8):
            byte=(byte<<1)|(data_bits[i+j] if i+j<len(data_bits) else 0)
        result.append(byte)
    return bytes(result), syndromes


# =====================================================================
# Inserimento errori
# =====================================================================

def insert_errors(bits, n_errors, mode='spread'):
    bits=list(bits); n_blocks=len(bits)//7; flipped=set()
    if mode=='spread':
        blocks=list(range(n_blocks)); np.random.shuffle(blocks)
        for i in range(min(n_errors,n_blocks)):
            pos=blocks[i]*7+np.random.randint(0,7); bits[pos]^=1; flipped.add(pos)
    elif mode=='mixed':
        n_s=max(1,int(n_errors*0.7)); n_d=n_errors-n_s
        blocks=list(range(n_blocks)); np.random.shuffle(blocks)
        for i in range(min(n_s,len(blocks))):
            pos=blocks[i]*7+np.random.randint(0,7); bits[pos]^=1; flipped.add(pos)
        for i in range(n_d):
            bi=blocks[np.random.randint(0,min(n_s,len(blocks)))]
            for p in np.random.choice(7,2,replace=False):
                pos=bi*7+p; bits[pos]^=1
                if pos in flipped: flipped.discard(pos)
                else: flipped.add(pos)
    else:
        for pos in np.random.choice(len(bits),min(n_errors,len(bits)),replace=False):
            bits[pos]^=1; flipped.add(pos)
    return bits, sorted(flipped)


# =====================================================================
# Analisi gaussiana dei livelli (da campioni o da file SRAW)
# =====================================================================

def gaussian_analysis(values, n_bins=64):
    v=np.array(values, dtype=float)
    hist, edges=np.histogram(v, bins=n_bins)
    centers=(edges[:-1]+edges[1:])/2
    mu=np.mean(v); sigma=np.std(v)
    gauss=(len(v)*(edges[1]-edges[0])/(sigma*np.sqrt(2*np.pi))*
           np.exp(-0.5*((centers-mu)/sigma)**2)) if sigma>0 else np.zeros_like(centers)
    return {'histogram':hist.tolist(),'bin_centers':centers.tolist(),
            'gaussian_fit':gauss.tolist(),'mean':float(mu),'std':float(sigma),
            'min':float(np.min(v)),'max':float(np.max(v)),
            'n_samples':len(v),'n_unique_levels':int(len(np.unique(v.astype(int))))}

def analyze_sraw_file(sraw_path):
    """Analizza un file SRAW: istogramma dei livelli della parte reale."""
    data, C = read_sraw(sraw_path)
    reals = data.real_ints()
    return gaussian_analysis(reals)


# =====================================================================
# SRAW export: campioni int16 a 8kHz → file .sraw via sraw_lib
# =====================================================================

def samples_to_sraw_file(samples, sr, output_path, comment=""):
    """Scrive campioni int16 mono come file SRAW-1.1 compatibile con furiè."""
    # Risoluzione: a 8kHz, dt=125µs, usiamo time_res=0.0000125 → dt_units=10
    time_res = 0.0000125
    dt_units = round(1.0 / sr / time_res)

    # amp_time_res: 1nV (SRAW-1.1 standard)
    # PCM int16 full-scale ±32768 ≈ ±1V → 1 unità PCM ≈ 1/32768 V ≈ 30.518 µV
    # Con amp_time_res = 1nV, valore SRAW = campione * 30518
    amp_res = 0.000000001  # 1 nV — standard SRAW-1.1

    C = make_constants(time_res=time_res, freq_res=0.01,
                       amp_time_res=amp_res, amp_freq_res=amp_res)

    # Scala PCM int16 → unità SRAW: campione/32768 V, in unità di amp_res
    pcm_to_sraw = 1.0 / 32768.0 / amp_res  # ≈ 30518 per amp_res=1nV

    tuples = []
    for i, s in enumerate(samples):
        x = i * dt_units
        tuples.append((x, int(round(float(s) * pcm_to_sraw)), 0))

    sraw = SrawData(axis_mode="positive", samples=tuples)
    write_sraw(sraw, output_path, comment=comment, constants=C)


# =====================================================================
# WAV reader (puro Python, nessuna dipendenza di sistema)
# =====================================================================

def read_wav_mono_8k(wav_path):
    with wave.open(wav_path, 'r') as wf:
        nch=wf.getnchannels(); sw=wf.getsampwidth(); sr=wf.getframerate()
        raw=wf.readframes(wf.getnframes())
    if sw==2: samples=np.frombuffer(raw, dtype=np.int16)
    elif sw==1: samples=(np.frombuffer(raw, dtype=np.uint8).astype(np.int16)-128)*256
    else: raise ValueError(f"Sample width {sw} non supportato")
    if nch>1: samples=samples.reshape(-1,nch).mean(axis=1).astype(np.int16)
    if sr!=8000:
        n_out=int(len(samples)*8000/sr)
        samples=np.interp(np.linspace(0,1,n_out),np.linspace(0,1,len(samples)),
                           samples.astype(float)).astype(np.int16)
    return samples, 8000


# =====================================================================
# Pipeline audio
# =====================================================================

def encode_audio(wav_path, n_errors=0, error_mode='spread'):
    samples, sr = read_wav_mono_8k(wav_path)
    samples = samples[:sr]
    if len(samples) < sr: samples = np.pad(samples, (0, sr - len(samples)))

    gauss_orig = gaussian_analysis(samples.tolist())
    bitstream, meta = lpc_encode(samples, sr)
    ham_bits = hamming_encode(bitstream)

    if n_errors > 0:
        corrupted, flipped = insert_errors(ham_bits, n_errors, error_mode)
    else:
        corrupted, flipped = list(ham_bits), []

    return {
        'type': 'audio', 'sr': sr, 'n_samples': len(samples),
        'lpc_meta': meta,
        'hamming_bits': corrupted, 'hamming_bits_clean': list(ham_bits),
        'n_hamming_bits': len(ham_bits), 'payload_bytes': len(bitstream),
        'hamming_bytes': (len(ham_bits)+7)//8,
        'n_errors': len(flipped), 'error_positions': flipped,
        'error_mode': error_mode,
        'gaussian_original': gauss_orig,
        'pcm_original': samples.tolist(),
    }

def decode_audio(data):
    bits = list(data['hamming_bits'])
    decoded_bytes, syndromes = hamming_decode(bits)
    decoded_bytes = decoded_bytes[:data['payload_bytes']]
    meta = data['lpc_meta']
    samples = lpc_decode(decoded_bytes, meta['n_frames'], data['sr'])
    gauss_dec = gaussian_analysis(samples.tolist())
    return {
        'samples': samples.tolist(), 'sr': data['sr'],
        'syndromes': syndromes,
        'n_corrected': sum(1 for s in syndromes if s['syndrome']>0),
        'gaussian_decoded': gauss_dec,
    }


# =====================================================================
# Pipeline testo
# =====================================================================

def encode_text(text, n_errors=0, error_mode='spread'):
    text = text[:91]
    text_bytes = text.encode('ascii', errors='replace')
    gauss_orig = gaussian_analysis(list(text_bytes), n_bins=32)
    ham_bits = hamming_encode(text_bytes)
    if n_errors > 0:
        corrupted, flipped = insert_errors(ham_bits, n_errors, error_mode)
    else:
        corrupted, flipped = list(ham_bits), []
    return {
        'type': 'text', 'text_original': text, 'text_bytes': len(text_bytes),
        'hamming_bits': corrupted, 'hamming_bits_clean': list(ham_bits),
        'n_hamming_bits': len(ham_bits), 'payload_bytes': len(text_bytes),
        'hamming_bytes': (len(ham_bits)+7)//8,
        'n_errors': len(flipped), 'error_positions': flipped,
        'error_mode': error_mode,
        'gaussian_original': gauss_orig,
    }

def decode_text(data):
    bits = list(data['hamming_bits'])
    decoded_bytes, syndromes = hamming_decode(bits)
    decoded_bytes = decoded_bytes[:data['payload_bytes']]
    text = ''.join(chr(b) if 32<=b<=126 else '?' for b in decoded_bytes)
    suspects = [i for i,b in enumerate(decoded_bytes) if b<32 or b>126]
    gauss_dec = gaussian_analysis(list(decoded_bytes), n_bins=32)
    return {
        'text_decoded': text, 'syndromes': syndromes,
        'n_corrected': sum(1 for s in syndromes if s['syndrome']>0),
        'suspect_chars': suspects, 'gaussian_decoded': gauss_dec,
    }


# =====================================================================
# CLI — chiamato da PHP via run_python()
# =====================================================================

if __name__ == '__main__':
    if len(sys.argv) < 2:
        print("Uso: gauss_hamming.py <comando> [args]", file=sys.stderr); sys.exit(1)

    cmd = sys.argv[1]
    args = sys.argv[2:]

    # Parse --errors e --mode
    n_errors=0; error_mode='spread'; filtered=[]
    i=0
    while i<len(args):
        if args[i]=='--errors' and i+1<len(args): n_errors=int(args[i+1]); i+=2
        elif args[i]=='--mode' and i+1<len(args): error_mode=args[i+1]; i+=2
        else: filtered.append(args[i]); i+=1
    args=filtered

    if cmd=='encode_audio' and len(args)>=2:
        result=encode_audio(args[0], n_errors, error_mode)
        with open(args[1],'w') as f: json.dump(result,f)
        print(f"OK payload={result['payload_bytes']}B hamming={result['hamming_bytes']}B errors={result['n_errors']}")

    elif cmd=='decode_audio' and len(args)>=2:
        with open(args[0]) as f: data=json.load(f)
        result=decode_audio(data)
        os.makedirs(args[1], exist_ok=True)
        # WAV
        s=np.array(result['samples'],dtype=np.int16)
        with wave.open(os.path.join(args[1],'decoded.wav'),'w') as wf:
            wf.setnchannels(1); wf.setsampwidth(2); wf.setframerate(result['sr'])
            wf.writeframes(s.tobytes())
        # SRAW-1.1 via sraw_lib
        samples_to_sraw_file(s, result['sr'],
                             os.path.join(args[1],'decoded.sraw'),
                             comment="gauss-hamming decoded")
        # Analysis JSON
        with open(os.path.join(args[1],'analysis.json'),'w') as f:
            json.dump({k:v for k,v in result.items() if k!='samples'},f,indent=2)
        print(f"OK blocks={len(result['syndromes'])} corrected={result['n_corrected']}")

    elif cmd=='encode_text' and len(args)>=2:
        result=encode_text(args[0], n_errors, error_mode)
        with open(args[1],'w') as f: json.dump(result,f)
        print(f"OK bytes={result['text_bytes']} hamming={result['hamming_bytes']}B errors={result['n_errors']}")

    elif cmd=='decode_text' and len(args)>=2:
        with open(args[0]) as f: data=json.load(f)
        result=decode_text(data)
        with open(args[1],'w') as f: json.dump(result,f)
        print(f"OK corrected={result['n_corrected']} suspects={len(result['suspect_chars'])}")

    elif cmd=='analyze_sraw' and len(args)>=2:
        result=analyze_sraw_file(args[0])
        with open(args[1],'w') as f: json.dump(result,f,indent=2)
        print(f"OK mean={result['mean']:.1f} std={result['std']:.1f} levels={result['n_unique_levels']}")

    elif cmd=='samples_to_sraw' and len(args)>=2:
        with open(args[0]) as f: data=json.load(f)
        samples=np.array(data['pcm_original'],dtype=np.int16)
        samples_to_sraw_file(samples, data.get('sr',8000), args[1],
                             comment="gauss-hamming original PCM")
        print(f"OK {len(samples)} samples -> {args[1]}")

    else:
        print("Comando sconosciuto o argomenti insufficienti.", file=sys.stderr)
        sys.exit(1)
