<?php
/**
 * gauss-hamming
 * furiè UniFG 0.0.3-alpha
 *
 * Questa cartella va in: furieh/www/gauss-hamming/
 * Include bootstrap.php di furieh per run_python(), data_root(), list_sraw(), ecc.
 */

require_once __DIR__ . '/../inc/bootstrap.php';

// ── API JSON (chiamate AJAX dal frontend) ─────────────────────────
if (isset($_POST['api_action'])) {
    header('Content-Type: application/json; charset=utf-8');
    $api = $_POST['api_action'];

    try {
        // Lista file .sraw per il selettore
        if ($api === 'list_sraw') {
            echo json_encode(['files' => list_sraw()]);
            exit;
        }

        // Lista file .mp3 per il selettore audio
        if ($api === 'list_mp3') {
            echo json_encode(['files' => list_mp3()]);
            exit;
        }

        // Lista cartelle per il salvataggio
        if ($api === 'list_dirs') {
            echo json_encode(['dirs' => list_recursive_dirs()]);
            exit;
        }

        // Leggi contenuto SRAW (per analisi gaussiana nel browser)
        if ($api === 'read_sraw') {
            $rel = $_POST['sraw_file'] ?? '';
            $abs = rel_to_abs_data_path($rel, true, false, ['sraw']);
            echo json_encode(['content' => file_get_contents($abs)]);
            exit;
        }

        // Salva SRAW-1.1 (output del designer o del decoder)
        if ($api === 'save_sraw') {
            $rel_out = sanitize_output_rel_path(
                $_POST['output_sraw'] ?? 'gauss-hamming/signal.sraw',
                'gauss-hamming/signal.sraw', ['sraw']
            );
            $content = $_POST['content'] ?? '';
            if ($content === '') { echo json_encode(['error' => 'Contenuto vuoto']); exit; }
            ensure_parent_dir_exists_for_rel_file($rel_out);
            $abs = rel_to_abs_data_path($rel_out, false, false, ['sraw']);
            file_put_contents($abs, $content, LOCK_EX);
            echo json_encode(['ok' => true, 'path' => $rel_out]);
            exit;
        }

        // Encode audio: upload WAV/MP3 → Python pipeline
        if ($api === 'encode_audio') {
            $rel = $_POST['audio_file'] ?? '';
            if ($rel === '') { echo json_encode(['error' => 'Nessun file selezionato']); exit; }
            $abs_input = rel_to_abs_data_path($rel, true, false, ['mp3', 'wav']);
            $ext = strtolower(pathinfo($rel, PATHINFO_EXTENSION));

            // Se MP3, converti in WAV con ffmpeg
            $wav_path = $abs_input;
            $tmp_wav = null;
            if ($ext === 'mp3') {
                $tmp_wav = tempnam(sys_get_temp_dir(), 'gh_') . '.wav';
                $wav_path = $tmp_wav;
                $ffmpeg = trim($_POST['ffmpeg_path'] ?? 'ffmpeg');
                if ($ffmpeg === '') $ffmpeg = 'ffmpeg';
                $cmd_parts = array_map('escapeshellarg', [
                    $ffmpeg, '-y', '-i', $abs_input,
                    '-ar', '8000', '-ac', '1', '-sample_fmt', 's16', $wav_path
                ]);
                exec(implode(' ', $cmd_parts) . ' 2>&1', $ff_out, $ff_ret);
                if ($ff_ret !== 0) {
                    echo json_encode(['error' => 'ffmpeg fallito', 'details' => implode("\n", $ff_out)]);
                    @unlink($tmp_wav); exit;
                }
            }

            $tmp_json = tempnam(sys_get_temp_dir(), 'gh_') . '.json';
            $n_errors = (int)($_POST['n_errors'] ?? 0);
            $err_mode = in_array($_POST['error_mode'] ?? '', ['spread','random','mixed'], true)
                      ? $_POST['error_mode'] : 'spread';

            $args = [$wav_path, $tmp_json];
            if ($n_errors > 0) { $args[] = '--errors'; $args[] = (string)$n_errors; $args[] = '--mode'; $args[] = $err_mode; }

            $ok = run_python('gauss_hamming.py', array_merge(['encode_audio'], $args), $out, $err_out);

            if ($ok && file_exists($tmp_json)) {
                echo file_get_contents($tmp_json);
                @unlink($tmp_json);
            } else {
                echo json_encode(['error' => 'Codifica audio fallita', 'details' => $err_out ?: $out]);
            }
            if ($tmp_wav) @unlink($tmp_wav);
            exit;
        }

        // Salva PCM originale come SRAW-1.1
        if ($api === 'save_original_sraw') {
            $json_data = $_POST['json_data'] ?? '';
            $rel_out = sanitize_output_rel_path(
                $_POST['output_sraw'] ?? 'gauss-hamming/originale.sraw',
                'gauss-hamming/originale.sraw', ['sraw']
            );
            ensure_parent_dir_exists_for_rel_file($rel_out);
            $abs_out = rel_to_abs_data_path($rel_out, false, false, ['sraw']);

            $tmp_json = tempnam(sys_get_temp_dir(), 'gh_') . '.json';
            file_put_contents($tmp_json, $json_data);

            $ok = run_python('gauss_hamming.py', ['samples_to_sraw', $tmp_json, $abs_out], $out, $err_out);
            @unlink($tmp_json);

            echo $ok
                ? json_encode(['ok' => true, 'path' => $rel_out])
                : json_encode(['error' => $err_out ?: $out]);
            exit;
        }

        // Decode audio: JSON con bit corrotti → WAV + SRAW
        if ($api === 'decode_audio') {
            $json_data = $_POST['json_data'] ?? '';
            $tmp_in = tempnam(sys_get_temp_dir(), 'gh_') . '.json';
            $tmp_dir = sys_get_temp_dir() . DIRECTORY_SEPARATOR . 'gh_dec_' . time() . '_' . mt_rand();
            file_put_contents($tmp_in, $json_data);

            $ok = run_python('gauss_hamming.py', ['decode_audio', $tmp_in, $tmp_dir], $out, $err_out);
            @unlink($tmp_in);

            if ($ok && is_dir($tmp_dir)) {
                $result = json_decode(file_get_contents("$tmp_dir/analysis.json"), true);
                $result['wav_base64'] = base64_encode(file_get_contents("$tmp_dir/decoded.wav"));
                $result['sraw_content'] = file_get_contents("$tmp_dir/decoded.sraw");
                echo json_encode($result);
                @unlink("$tmp_dir/analysis.json"); @unlink("$tmp_dir/decoded.wav");
                @unlink("$tmp_dir/decoded.sraw"); @rmdir($tmp_dir);
            } else {
                echo json_encode(['error' => 'Decodifica fallita', 'details' => $err_out ?: $out]);
            }
            exit;
        }

        // Analisi gaussiana di un file SRAW (via Python per consistenza con sraw_lib)
        if ($api === 'analyze_sraw') {
            $rel = $_POST['sraw_file'] ?? '';
            $abs = rel_to_abs_data_path($rel, true, false, ['sraw']);
            $tmp_json = tempnam(sys_get_temp_dir(), 'gh_') . '.json';

            $ok = run_python('gauss_hamming.py', ['analyze_sraw', $abs, $tmp_json], $out, $err_out);

            if ($ok && file_exists($tmp_json)) {
                echo file_get_contents($tmp_json);
                @unlink($tmp_json);
            } else {
                echo json_encode(['error' => $err_out ?: $out]);
            }
            exit;
        }

        echo json_encode(['error' => "Azione API sconosciuta: $api"]);
    } catch (Throwable $e) {
        echo json_encode(['error' => $e->getMessage()]);
    }
    exit;
}

// ── Pagina HTML ───────────────────────────────────────────────────
$sraw_files = list_sraw();
$mp3_files = list_mp3();
$dirs = list_recursive_dirs();
?>
<!DOCTYPE html>
<html lang="it">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1.0">
<title>gauss-hamming — furiè UniFG</title>
<link rel="stylesheet" href="../inc/style.css">
<style>
:root{--bg:#0f1117;--surface:#1a1d27;--surface2:#222633;--border:#2e3348;--text:#e0e2ec;--muted:#8890a8;--accent:#5b8def;--green:#4ec99b;--green-dim:#1a3a2e;--yellow:#f0c050;--yellow-dim:#3a3020;--red:#ef6b6b;--red-dim:#3a1e1e;--orange:#f0883e}
body{font-family:'Segoe UI',system-ui,sans-serif;background:var(--bg);color:var(--text);margin:0;line-height:1.5}
.mono{font-family:'Consolas','Courier New',monospace}
header.gh{background:linear-gradient(135deg,#1a1d27,#1e2540);border-bottom:1px solid var(--border);padding:1rem 1.5rem;text-align:center}
header.gh h1{font-size:1.3rem;margin:0} header.gh h1 span{color:var(--accent)}
header.gh .sub{color:var(--muted);font-size:.8rem}
.tabs{display:flex;justify-content:center;background:var(--surface);border-bottom:1px solid var(--border)}
.tab-btn{padding:.65rem 1.5rem;cursor:pointer;background:none;border:none;color:var(--muted);font:600 .9rem inherit;border-bottom:3px solid transparent;transition:.2s}
.tab-btn:hover{color:var(--text);background:var(--surface2)} .tab-btn.active{color:var(--accent);border-bottom-color:var(--accent)}
.tab-content{display:none;padding:1.2rem;max-width:1100px;margin:0 auto} .tab-content.active{display:block}
.card{background:var(--surface);border:1px solid var(--border);border-radius:7px;padding:1rem;margin-bottom:.8rem}
.card h3{font-size:.9rem;margin-bottom:.5rem;color:var(--accent)} .badge{font-size:.65rem;padding:.08rem .4rem;border-radius:8px;background:var(--accent);color:var(--bg);font-family:monospace;margin-left:.3rem}
label{display:block;margin-bottom:.2rem;font-size:.78rem;color:var(--muted)}
textarea,input[type="text"],select{width:100%;padding:.5rem;font-family:monospace;font-size:.8rem;background:var(--bg);color:var(--text);border:1px solid var(--border);border-radius:5px;resize:vertical}
textarea:focus,input:focus,select:focus{outline:none;border-color:var(--accent)}
input[type="file"]{color:var(--text);font-size:.78rem}
button{padding:.45rem .9rem;font:600 .85rem inherit;border:none;border-radius:5px;cursor:pointer;transition:.15s}
.btn-p{background:var(--accent);color:#fff} .btn-s{background:var(--surface2);color:var(--text);border:1px solid var(--border)} .btn-o{background:var(--orange);color:#fff} .btn-g{background:var(--green);color:#111}
.btn-p:hover,.btn-o:hover,.btn-g:hover{filter:brightness(1.15)} .btn-s:hover{background:var(--border)}
.btn-sm{padding:.25rem .5rem;font-size:.75rem}
.row{display:flex;gap:.5rem;flex-wrap:wrap;margin-top:.5rem}
.two-col{display:grid;grid-template-columns:1fr 1fr;gap:.8rem}
@media(max-width:700px){.two-col{grid-template-columns:1fr}}
.info{background:var(--surface2);border-left:3px solid var(--accent);padding:.5rem .8rem;margin-top:.5rem;font-size:.8rem;border-radius:0 5px 5px 0}
.info.w{border-left-color:var(--yellow)} .info.e{border-left-color:var(--red)} .info.ok{border-left-color:var(--green)}
.block-row{display:flex;flex-wrap:wrap;gap:2px;margin-bottom:2px;align-items:center}
.blbl{font-family:monospace;font-size:.55rem;color:var(--muted);width:2.4rem;text-align:right;padding-right:.25rem;flex-shrink:0}
.bit{width:17px;height:19px;display:inline-flex;align-items:center;justify-content:center;font-family:monospace;font-size:.62rem;border-radius:2px;cursor:pointer;user-select:none;border:1px solid transparent}
.bit.b0{background:var(--surface2);color:var(--muted)} .bit.b1{background:var(--accent);color:#fff;font-weight:700}
.bit.fl{background:var(--red)!important;color:#fff!important;font-weight:700;border-color:#ff9999}
.bst{width:10px;height:10px;border-radius:50%;margin-left:3px;flex-shrink:0}
.bst.ok{background:var(--green)} .bst.w{background:var(--yellow)} .bst.d{background:var(--red)}
.stats{display:grid;grid-template-columns:repeat(auto-fit,minmax(110px,1fr));gap:.4rem;margin-top:.5rem}
.sbox{background:var(--bg);border:1px solid var(--border);border-radius:5px;padding:.4rem;text-align:center}
.sbox .v{font-family:monospace;font-size:1.1rem;font-weight:700} .sbox .l{font-size:.6rem;color:var(--muted)}
.sbox.green .v{color:var(--green)} .sbox.yellow .v{color:var(--yellow)} .sbox.red .v{color:var(--red)}
.ww{margin-top:.5rem;overflow-x:auto;background:var(--bg);border:1px solid var(--border);border-radius:5px;padding:.3rem}
canvas{display:block}
.wleg{display:flex;gap:1rem;margin-top:.3rem;font-size:.75rem;justify-content:center}
.wleg span{display:flex;align-items:center;gap:.25rem} .wleg i{display:inline-block;width:14px;height:3px;border-radius:2px}
.wctrl{display:flex;gap:.3rem;align-items:center;flex-wrap:wrap;font-size:.75rem;margin-bottom:.3rem}
.tcnt{display:flex;gap:1.2rem;margin-top:.3rem;font-family:monospace;font-size:.78rem;flex-wrap:wrap}
.dtxt{font-size:1.1rem;padding:.7rem;background:var(--bg);border:1px solid var(--border);border-radius:5px;margin-top:.3rem;line-height:1.6;letter-spacing:.01em}
.dc.ok{color:var(--green)} .dc.co{color:var(--yellow)} .dc.su{color:var(--red);background:var(--red-dim);border-radius:2px;padding:0 2px}
.syn-tbl{width:100%;border-collapse:collapse;font-family:monospace;font-size:.68rem;margin-top:.3rem}
.syn-tbl th{background:var(--surface2);color:var(--muted);padding:.25rem;text-align:left;border-bottom:1px solid var(--border)}
.syn-tbl td{padding:.2rem .25rem;border-bottom:1px solid var(--border)}
.scroll{max-height:250px;overflow-y:auto}
.gauss-cv{width:100%;height:300px;background:var(--bg);border:1px solid var(--border);border-radius:5px;margin-top:.4rem}
.save-r{display:flex;gap:.4rem;align-items:center;flex-wrap:wrap;margin-top:.4rem}
.save-r select,.save-r input[type="text"]{width:auto;flex:1;min-width:100px}
audio{width:100%;margin-top:.4rem}
.ld{color:var(--muted);font-style:italic;padding:.8rem;text-align:center}
.cc{text-align:right;font-size:.68rem;color:var(--muted);margin-top:.1rem}
.back-link{display:inline-block;margin:1rem;color:var(--accent);text-decoration:none;font-size:.85rem}
.back-link:hover{text-decoration:underline}
</style>
</head>
<body>
<a href="../" class="back-link">← Torna al launcher furiè</a>
<header class="gh">
  <h1><span>gauss-hamming</span></h1>
  <div class="sub">furiè UniFG 0.0.3-α — Fondamenti di Telecomunicazioni</div>
</header>
<div class="tabs">
  <button class="tab-btn active" onclick="switchTab('enc')">Codificatore</button>
  <button class="tab-btn" onclick="switchTab('dec')">Decodificatore</button>
  <button class="tab-btn" onclick="switchTab('gauss')">Analisi gaussiana</button>
</div>

<!-- ====== CODIFICATORE ====== -->
<div id="tab-enc" class="tab-content active">
  <div class="card"><h3>1. Sorgente</h3>
    <div class="two-col">
      <div>
        <label>Testo (max 91 caratteri)</label>
        <textarea id="e-txt" rows="3" maxlength="91" placeholder="nel mezzo del cammin di nostra vita..."></textarea>
        <div class="cc"><span id="e-cc">0</span>/91</div>
        <div class="row"><button class="btn-p" onclick="encText()">Codifica testo</button>
          <button class="btn-s btn-sm" onclick="document.getElementById('e-txt').value='nel mezzo del cammin di nostra vita mi ritrovai per una selva oscura';document.getElementById('e-cc').textContent=70">Dante</button></div>
      </div>
      <div>
        <label>Audio MP3 / WAV da cartella data/</label>
        <select id="e-aud" onchange="updateMp3Preview()"><?php foreach($mp3_files as $f): ?><option value="<?=h($f)?>"><?=h($f)?></option><?php endforeach; ?></select>
        <audio id="e-mp3-preview" controls style="width:100%;margin-top:.3rem;height:32px"></audio>
        <div class="info" style="font-size:.72rem;margin-top:.3rem">Tagliato a 1 s → mono 8 kHz 16 bit → vocoder LPC → ~87 byte</div>
        <div class="row" style="align-items:center;margin-top:.3rem">
          <label style="margin:0;font-size:.72rem;white-space:nowrap">ffmpeg:</label>
          <input type="text" id="e-ffmpeg" placeholder="ffmpeg" style="flex:1;font-size:.72rem;padding:.25rem .4rem" onchange="setCookie('ffmpeg_path',this.value.trim())">
        </div>
        <div class="row"><button class="btn-o" onclick="encAudio()">Codifica audio</button>
          <button class="btn-s btn-sm" onclick="refreshMp3List()">Aggiorna lista</button></div>
      </div>
    </div>
  </div>
  <div id="e-log-card" class="card" style="display:none">
    <h3>Log elaborazione</h3>
    <textarea id="e-log" rows="6" readonly style="font-size:.72rem;background:#0a0c10;color:#8890a8"></textarea>
    <div id="e-lpc-player-card" style="display:none;margin-top:.5rem">
      <label style="font-size:.75rem;color:var(--orange)">Anteprima vocoder LPC (87 byte → 1 s audio):</label>
      <audio id="e-lpc-preview" controls style="width:100%;height:32px;margin-top:.2rem"></audio>
    </div>
  </div>
  <div id="e-res" style="display:none">
    <div class="card"><h3>2. Hamming(7,4)<span class="badge">max 160 byte</span></h3>
      <div class="info">Tipo: <b id="e-type"></b> — Payload: <b id="e-pay"></b> B — Blocchi: <b id="e-nb"></b> — Bit: <b id="e-nbit"></b></div></div>
    <div class="card"><h3>3. Errori<span class="badge">clicca o usa i bottoni</span></h3>
      <div class="info w"><span style="color:var(--green)">●0</span> <span style="color:var(--yellow)">●1</span> <span style="color:var(--red)">●2+</span></div>
      <div class="row" style="margin-bottom:.3rem">
        <button class="btn-s btn-sm" onclick="addErr(1)">+1</button>
        <button class="btn-s btn-sm" onclick="addErr(5)">+5</button>
        <button class="btn-s btn-sm" onclick="addErr(20)">+20 spread</button>
        <button class="btn-s btn-sm" style="color:var(--red)" onclick="rstErr()">Reset</button>
      </div>
      <div id="e-grid" style="max-height:280px;overflow-y:auto"></div>
      <div class="stats" id="e-stats"></div></div>
    <div class="card"><h3>4. Forme d'onda</h3>
      <div class="wctrl"><label>Bit:</label><input type="range" id="e-wn" min="14" max="140" value="56" oninput="drawEW()"><span id="e-wn-v" class="mono">56</span>
        <label style="margin-left:.4rem">Offset:</label><input type="range" id="e-wo" min="0" max="0" value="0" oninput="drawEW()"><span id="e-wo-v" class="mono">0</span></div>
      <div class="ww"><canvas id="e-nrz" height="100"></canvas></div>
      <div class="ww" style="margin-top:.15rem"><canvas id="e-man" height="100"></canvas></div>
      <div class="wleg"><span><i style="background:#5b8def"></i>NRZ-L</span><span><i style="background:#f0883e"></i>Manchester</span></div>
      <div class="tcnt" id="e-tr"></div></div>
    <div class="card"><h3>5. Trasmissione</h3>
      <textarea id="e-out" rows="3" readonly></textarea>
      <div class="row"><button class="btn-p" onclick="navigator.clipboard.writeText(document.getElementById('e-out').value).then(()=>{event.target.textContent='Copiato!';setTimeout(()=>event.target.textContent='Copia',1500)})">Copia</button></div></div>
    <div class="card" id="e-sraw-card" style="display:none"><h3>6. Salva x(t) originale SRAW-1.1<span class="badge" style="background:var(--orange)">furiè viewer</span></h3>
      <div class="save-r">
        <label style="margin:0;white-space:nowrap">data/</label>
        <select id="e-sf"><?php foreach($dirs as $d): ?><option value="<?=h($d)?>"<?=$d==='gauss-hamming'?' selected':''?>><?=$d===''?'(radice)':h($d)?></option><?php endforeach; ?></select>
        <label style="margin:0">/</label>
        <input type="text" id="e-sn" value="originale.sraw" style="width:180px">
        <button class="btn-g btn-sm" onclick="saveOrigSraw()">Salva</button>
      </div></div>
    <div class="card" id="e-lpc-sraw-card" style="display:none"><h3>7. Salva x<sub>1</sub>(t) LPC SRAW-1.1<span class="badge" style="background:var(--orange)">furiè viewer</span></h3>
      <div class="info" style="margin-bottom:.4rem">Segnale ricostruito dal vocoder LPC (dopo codifica+decodifica, senza errori Hamming)</div>
      <div class="save-r">
        <label style="margin:0;white-space:nowrap">data/</label>
        <select id="e-lpc-sf"><?php foreach($dirs as $d): ?><option value="<?=h($d)?>"<?=$d==='gauss-hamming'?' selected':''?>><?=$d===''?'(radice)':h($d)?></option><?php endforeach; ?></select>
        <label style="margin:0">/</label>
        <input type="text" id="e-lpc-sn" value="lpc.sraw" style="width:180px">
        <button class="btn-g btn-sm" onclick="saveLpcSraw()">Salva</button>
      </div></div>
  </div>
</div>

<!-- ====== DECODIFICATORE ====== -->
<div id="tab-dec" class="tab-content">
  <div class="card"><h3>1. Stringa ricevuta</h3>
    <textarea id="d-in" rows="4" placeholder="Incolla qui la sequenza di bit..."></textarea>
    <div class="row">
      <button class="btn-p" onclick="decText()">Decodifica come testo</button>
      <button class="btn-o" onclick="decAudio()">Decodifica come audio (LPC)</button>
    </div>
    <div id="d-log-card" class="card" style="display:none;margin-top:.5rem"><textarea id="d-log" rows="4" readonly style="font-size:.72rem;background:#0a0c10;color:#8890a8"></textarea></div>
  </div>
  <div id="d-res" style="display:none">
    <div class="card"><h3>2. Blocchi Hamming(7,4)</h3><div id="d-syn" class="scroll"></div><div class="stats" id="d-stats"></div></div>
    <div class="card"><h3>3. Forme d'onda ricevute</h3>
      <div class="wctrl"><label>Bit:</label><input type="range" id="d-wn" min="14" max="140" value="56" oninput="drawDW()"><span id="d-wn-v" class="mono">56</span>
        <label style="margin-left:.4rem">Offset:</label><input type="range" id="d-wo" min="0" max="0" value="0" oninput="drawDW()"><span id="d-wo-v" class="mono">0</span></div>
      <div class="ww"><canvas id="d-nrz" height="100"></canvas></div>
      <div class="ww" style="margin-top:.15rem"><canvas id="d-man" height="100"></canvas></div>
      <div class="wleg"><span><i style="background:#5b8def"></i>NRZ-L</span><span><i style="background:#f0883e"></i>Manchester</span></div>
      <div class="tcnt" id="d-tr"></div></div>
    <div class="card" id="d-txt-card"><h3>4. Messaggio</h3><div id="d-blk"></div><div class="dtxt" id="d-txt"></div><div class="info" id="d-sum"></div></div>
    <div class="card" id="d-aud-card" style="display:none"><h3>4. Audio decodificato</h3>
      <audio id="d-player" controls style="width:100%"></audio>
      <div class="save-r" style="margin-top:.4rem">
        <label style="margin:0;white-space:nowrap">data/</label>
        <select id="d-sf"><?php foreach($dirs as $d): ?><option value="<?=h($d)?>"<?=$d==='gauss-hamming'?' selected':''?>><?=$d===''?'(radice)':h($d)?></option><?php endforeach; ?></select>
        <label style="margin:0">/</label>
        <input type="text" id="d-sn" value="decodificato.sraw" style="width:200px">
        <button class="btn-g btn-sm" onclick="saveDecSraw()">Salva SRAW</button>
      </div></div>
  </div>
</div>

<!-- ====== ANALISI GAUSSIANA ====== -->
<div id="tab-gauss" class="tab-content">
  <div class="card"><h3>Analisi gaussiana dei livelli da file SRAW</h3>
    <div class="info">Seleziona un file <code>.sraw</code> dalla cartella <code>data/</code>. L'analisi mostra l'istogramma dei livelli (parte reale) con il fit gaussiano sovrapposto.</div>
    <div class="row" style="align-items:center">
      <select id="g-file" style="flex:1"><?php foreach($sraw_files as $f): ?><option value="<?=h($f)?>"><?=h($f)?></option><?php endforeach; ?></select>
      <button class="btn-p" onclick="analyzeGauss()">Analizza</button>
      <button class="btn-s btn-sm" onclick="refreshSrawList()">Aggiorna lista</button>
    </div>
  </div>
  <div id="g-res" style="display:none">
    <div class="card"><h3>Istogramma dei livelli</h3><canvas id="g-cv" height="300" class="gauss-cv"></canvas></div>
    <div class="card"><h3>Statistiche</h3><div id="g-stats"></div></div>
  </div>
</div>

<script>
/* === HAMMING(7,4) === */
const H=[[1,0,1,0,1,0,1],[0,1,1,0,0,1,1],[0,0,0,1,1,1,1]];
function hE(d){return[d[0]^d[1]^d[3],d[0]^d[2]^d[3],d[0],d[1]^d[2]^d[3],d[1],d[2],d[3]];}
function hD(r){let s=[0,0,0];for(let i=0;i<3;i++){let v=0;for(let j=0;j<7;j++)v^=(H[i][j]&r[j]);s[i]=v;}const syn=s[0]+s[1]*2+s[2]*4;const c=[...r];if(syn>0&&syn<=7)c[syn-1]^=1;return{corrected:c,syndrome:syn,data:[c[2],c[4],c[5],c[6]]};}

/* === STATE === */
let E={type:'',blocks:[],cur:[],errs:[],payload:null,audioData:null,lpcSrawContent:null};
let D={blocks:[],results:[],srawContent:'',wavB64:''};

/* === COOKIE HELPERS (same as furieh launcher) === */
function getCookie(name){const m=document.cookie.match(new RegExp('(^| )'+name+'=([^;]+)'));return m?decodeURIComponent(m[2]):'';}
function setCookie(name,val){document.cookie=name+'='+encodeURIComponent(val)+';path=/;max-age=31536000';}
function getFFmpeg(){return getCookie('ffmpeg_path')||'';}

/* === MP3 PREVIEW === */
function updateMp3Preview(){
  const sel=document.getElementById('e-aud');
  const player=document.getElementById('e-mp3-preview');
  if(sel.value){player.src='../data/'+sel.value;player.load();}
  else{player.src='';player.removeAttribute('src');}
}
/* === ENCODE TEXT === */
function encText(){
  const t=document.getElementById('e-txt').value;if(!t)return;
  E.type='text';E.payload={text:t,bytes:t.length};E.audioData=null;
  const bytes=[...t].map(c=>c.charCodeAt(0)&0x7F);
  let bits=[];bytes.forEach(b=>{for(let i=7;i>=0;i--)bits.push((b>>i)&1);});
  while(bits.length%4)bits.push(0);
  E.blocks=[];for(let i=0;i<bits.length;i+=4)E.blocks.push(hE(bits.slice(i,i+4)));
  E.cur=E.blocks.map(b=>[...b]);E.errs=E.blocks.map(()=>new Set());
  showEnc();
}

/* === ENCODE AUDIO === */
async function encAudio(){
  const sel=document.getElementById('e-aud');
  const file=sel.value;if(!file){alert('Seleziona un file MP3/WAV dalla lista.');return;}
  const fd=new FormData();fd.append('api_action','encode_audio');fd.append('audio_file',file);fd.append('ffmpeg_path',getFFmpeg());
  // Show log
  document.getElementById('e-log-card').style.display='block';
  document.getElementById('e-res').style.display='none';
  const log=document.getElementById('e-log');log.value='';
  function addLog(msg){log.value+=msg+'\n';log.scrollTop=log.scrollHeight;}
  addLog('File: '+file);
  addLog('ffmpeg: '+(getFFmpeg()||'(default)'));
  addLog('Invio richiesta...');
  try{
    const resp=await fetch('',{method:'POST',body:fd});
    addLog('HTTP '+resp.status+' — lettura risposta...');
    const text=await resp.text();
    addLog('Risposta: '+text.length+' byte');
    let r;
    try{r=JSON.parse(text);}catch(pe){addLog('ERRORE — risposta non JSON:');addLog(text.substring(0,800));return;}
    if(r.error){addLog('ERRORE: '+r.error);if(r.details)addLog(r.details);return;}
    addLog('OK — payload='+r.payload_bytes+'B, hamming='+(r.n_hamming_bits/8|0)+'B, frame='+r.lpc_meta.n_frames);
    E.type='audio';E.payload=r;E.audioData=r;
    const hb=r.hamming_bits_clean||r.hamming_bits;
    E.blocks=[];for(let i=0;i<hb.length;i+=7)E.blocks.push(hb.slice(i,i+7));
    E.cur=E.blocks.map(b=>[...b]);E.errs=E.blocks.map(()=>new Set());
    // Decodifica LPC anteprima (senza errori)
    addLog('Decodifica LPC anteprima...');
    try{
      const fd2=new FormData();fd2.append('api_action','decode_audio');fd2.append('json_data',JSON.stringify(r));
      const resp2=await fetch('',{method:'POST',body:fd2});
      const r2=await resp2.json();
      if(r2.wav_base64){
        document.getElementById('e-lpc-preview').src='data:audio/wav;base64,'+r2.wav_base64;
        document.getElementById('e-lpc-player-card').style.display='block';
        addLog('Anteprima LPC pronta — confronta con l\'originale');
      }
      if(r2.sraw_content){
        E.lpcSrawContent=r2.sraw_content;
        document.getElementById('e-lpc-sraw-card').style.display='block';
        addLog('SRAW x₁(t) LPC disponibile per il salvataggio');
      }
    }catch(e2){addLog('Anteprima LPC non disponibile: '+e2.message);}
    showEnc();
  }catch(e){addLog('ERRORE DI RETE: '+e.message);}
}

async function refreshMp3List(){
  const fd=new FormData();fd.append('api_action','list_mp3');
  const r=await(await fetch('',{method:'POST',body:fd})).json();
  const sel=document.getElementById('e-aud');sel.innerHTML='';
  (r.files||[]).forEach(f=>{const o=document.createElement('option');o.value=f;o.textContent=f;sel.appendChild(o);});
}

function showEnc(){
  document.getElementById('e-res').style.display='block';
  // Reset inner HTML to fresh state
  const r=document.getElementById('e-res');
  // Populate info
  setTimeout(()=>{
    const t=document.getElementById('e-type'),p=document.getElementById('e-pay'),
          nb=document.getElementById('e-nb'),nbit=document.getElementById('e-nbit');
    if(t)t.textContent=E.type==='audio'?'Audio (LPC)':'Testo';
    if(p)p.textContent=E.type==='audio'?E.audioData.payload_bytes:E.payload.bytes;
    if(nb)nb.textContent=E.blocks.length;
    if(nbit)nbit.textContent=E.blocks.length*7;
    renderGrid();updStats();updOut();drawEW();
    document.getElementById('e-sraw-card').style.display=E.type==='audio'?'block':'none';
  },10);
}

/* === GRID === */
function renderGrid(){
  const c=document.getElementById('e-grid');if(!c)return;c.innerHTML='';
  E.cur.forEach((bl,bi)=>{const row=document.createElement('div');row.className='block-row';
    const l=document.createElement('span');l.className='blbl';l.textContent='B'+(bi+1);row.appendChild(l);
    bl.forEach((b,pi)=>{const cell=document.createElement('span');cell.className='bit b'+b;cell.textContent=b;
      if(E.errs[bi].has(pi))cell.classList.add('fl');cell.onclick=()=>flip(bi,pi);row.appendChild(cell);});
    const s=document.createElement('span');s.className='bst';s.classList.add(E.errs[bi].size===0?'ok':E.errs[bi].size===1?'w':'d');
    row.appendChild(s);c.appendChild(row);});
  const wo=document.getElementById('e-wo');if(wo)wo.max=Math.max(0,E.cur.length*7-14);
}
function flip(bi,pi){E.cur[bi][pi]^=1;if(E.errs[bi].has(pi))E.errs[bi].delete(pi);else E.errs[bi].add(pi);renderGrid();updStats();updOut();drawEW();}
function addErr(n){const avail=[];E.errs.forEach((s,i)=>{if(!s.size)avail.push(i);});
  if(!avail.length)E.errs.forEach((_,i)=>avail.push(i));
  for(let i=0;i<n&&avail.length;i++){const idx=Math.floor(Math.random()*avail.length);const bi=avail.splice(idx,1)[0];
    const pi=Math.floor(Math.random()*7);E.cur[bi][pi]^=1;
    if(E.errs[bi].has(pi))E.errs[bi].delete(pi);else E.errs[bi].add(pi);}
  renderGrid();updStats();updOut();drawEW();}
function rstErr(){E.cur=E.blocks.map(b=>[...b]);E.errs=E.blocks.map(()=>new Set());renderGrid();updStats();updOut();drawEW();}

function updStats(){const el=document.getElementById('e-stats');if(!el)return;const tb=E.cur.length*7;let te=0,cl=0,co=0,un=0;
  E.errs.forEach(s=>{te+=s.size;if(!s.size)cl++;else if(s.size===1)co++;else un++;});
  el.innerHTML=`<div class="sbox"><div class="v mono">${te}</div><div class="l">Errori</div></div>
    <div class="sbox green"><div class="v mono">${cl}</div><div class="l">Integri</div></div>
    <div class="sbox yellow"><div class="v mono">${co}</div><div class="l">1 (OK)</div></div>
    <div class="sbox red"><div class="v mono">${un}</div><div class="l">2+ (KO)</div></div>
    <div class="sbox"><div class="v mono">${tb?(te/tb).toExponential(2):'0'}</div><div class="l">BER</div></div>`;}

function updOut(){const el=document.getElementById('e-out');if(!el)return;const a=E.cur.flat();let o='';
  for(let i=0;i<a.length;i++){o+=a[i];if((i+1)%7===0&&i<a.length-1)o+=' ';}el.value=o;}

/* === SAVE SRAW === */
async function saveOrigSraw(){
  if(!E.audioData||!E.audioData.pcm_original){alert('Solo per audio codificato.');return;}
  const fd=new FormData();fd.append('api_action','save_original_sraw');
  fd.append('json_data',JSON.stringify(E.audioData));
  const folder=document.getElementById('e-sf').value;
  const name=document.getElementById('e-sn').value;
  fd.append('output_sraw',folder?folder+'/'+name:name);
  const r=await(await fetch('',{method:'POST',body:fd})).json();
  alert(r.ok?'Salvato: '+r.path:(r.error||'Errore'));
}
async function saveLpcSraw(){
  if(!E.lpcSrawContent){alert('Devi prima codificare un audio (anteprima LPC necessaria).');return;}
  const fd=new FormData();fd.append('api_action','save_sraw');
  const folder=document.getElementById('e-lpc-sf').value;
  const name=document.getElementById('e-lpc-sn').value;
  fd.append('output_sraw',folder?folder+'/'+name:name);
  fd.append('content',E.lpcSrawContent);
  const r=await(await fetch('',{method:'POST',body:fd})).json();
  alert(r.ok?'Salvato: '+r.path:(r.error||'Errore'));
}
async function saveDecSraw(){
  if(!D.srawContent){alert('Nessun SRAW decodificato disponibile.');return;}
  const fd=new FormData();fd.append('api_action','save_sraw');
  const folder=document.getElementById('d-sf').value;const name=document.getElementById('d-sn').value;
  fd.append('output_sraw',folder?folder+'/'+name:name);fd.append('content',D.srawContent);
  const r=await(await fetch('',{method:'POST',body:fd})).json();
  alert(r.ok?'Salvato: '+r.path:(r.error||'Errore'));
}

/* === DECODE: common Hamming step === */
function decHamming(){
  const raw=document.getElementById('d-in').value.replace(/[^01]/g,'');
  if(!raw.length){alert('Incolla una sequenza di bit.');return null;}
  if(raw.length%7){alert(raw.length+' bit: non multiplo di 7.');return null;}
  D.blocks=[];for(let i=0;i<raw.length;i+=7)D.blocks.push(raw.slice(i,i+7).split('').map(Number));
  D.results=D.blocks.map(b=>({...hD(b),original:b}));
  document.getElementById('d-res').style.display='block';
  renderSyn();updDStats();
  document.getElementById('d-wo').max=Math.max(0,raw.length-14);drawDW();
  // Extract decoded data bits
  const ad=[];D.results.forEach(r=>ad.push(...r.data));
  return ad;
}

/* === DECODE TEXT === */
function decText(){
  const ad=decHamming();if(!ad)return;
  document.getElementById('d-txt-card').style.display='block';
  document.getElementById('d-aud-card').style.display='none';
  document.getElementById('d-log-card').style.display='none';
  renderDec(ad);
}

/* === DECODE AUDIO === */
async function decAudio(){
  const ad=decHamming();if(!ad)return;
  document.getElementById('d-txt-card').style.display='none';
  document.getElementById('d-log-card').style.display='block';
  const log=document.getElementById('d-log');log.value='';
  function addLog(msg){log.value+=msg+'\n';log.scrollTop=log.scrollHeight;}

  // Ricostruisci i byte dal bitstream decodificato
  const decodedBytes=[];
  for(let i=0;i+7<ad.length;i+=8){
    let byte=0;for(let j=0;j<8;j++)byte=(byte<<1)|(ad[i+j]||0);
    decodedBytes.push(byte);
  }
  addLog('Hamming decodificato: '+D.results.length+' blocchi, '+decodedBytes.length+' byte');
  addLog('Blocchi con correzione: '+D.results.filter(r=>r.syndrome).length);

  // Costruisci il JSON per il backend con i bit corrotti dalla textarea
  const bitsForBackend=D.blocks.flat();
  // Metadati LPC: se disponibili dalla codifica, li usiamo; altrimenti li deriviamo dai dati
  let audioMeta;
  if(E.audioData&&E.audioData.lpc_meta){
    audioMeta=E.audioData;
  }else{
    // Parametri costanti del vocoder LPC (lpc_vocoder.py)
    const BITS_PER_FRAME=77, sr=8000;
    const payloadBytes=decodedBytes.length;
    const nFrames=Math.floor(payloadBytes*8/BITS_PER_FRAME);
    if(nFrames<1){addLog('ERRORE: dati insufficienti per decodifica audio ('+payloadBytes+' byte).');return;}
    addLog('Metadati LPC ricostruiti dai dati: '+nFrames+' frame, '+payloadBytes+' byte');
    audioMeta={sr:sr,payload_bytes:payloadBytes,lpc_meta:{n_frames:nFrames,sr:sr,bits_per_frame:BITS_PER_FRAME,total_bits:nFrames*BITS_PER_FRAME,total_bytes:payloadBytes}};
  }
  const payload={...audioMeta, hamming_bits:bitsForBackend};
  addLog('Invio al backend per decodifica LPC ('+audioMeta.lpc_meta.n_frames+' frame)...');

  const fd=new FormData();fd.append('api_action','decode_audio');fd.append('json_data',JSON.stringify(payload));
  try{
    const resp=await fetch('',{method:'POST',body:fd});
    addLog('HTTP '+resp.status);
    const text=await resp.text();
    let r;
    try{r=JSON.parse(text);}catch(pe){addLog('ERRORE — risposta non JSON:');addLog(text.substring(0,500));return;}
    if(r.error){addLog('ERRORE: '+r.error);if(r.details)addLog(r.details);return;}
    addLog('Decodifica LPC completata');
    if(r.wav_base64){
      D.wavB64=r.wav_base64;
      document.getElementById('d-player').src='data:audio/wav;base64,'+r.wav_base64;
      document.getElementById('d-aud-card').style.display='block';
      addLog('Audio pronto per la riproduzione');
    }
    if(r.sraw_content){D.srawContent=r.sraw_content;addLog('SRAW disponibile per il salvataggio');}
    addLog('Blocchi corretti dal decodificatore: '+(r.n_corrected||0));
  }catch(e){addLog('ERRORE DI RETE: '+e.message);}
}
function renderSyn(){let h='<table class="syn-tbl"><thead><tr><th>#</th><th>Ric.</th><th>Sind.</th><th>Pos</th><th>Corr.</th><th>Dati</th><th></th></tr></thead><tbody>';
  D.results.forEach((r,i)=>{const s=r.syndrome;h+=`<tr><td>${i+1}</td><td class="mono">${r.original.join('')}</td><td class="mono">${s.toString(2).padStart(3,'0')}</td><td>${s||'—'}</td><td class="mono">${r.corrected.join('')}</td><td class="mono">${r.data.join('')}</td><td style="color:var(--${s?'yellow':'green'})">${s?'⚡'+s:'✓'}</td></tr>`;});
  h+='</tbody></table>';document.getElementById('d-syn').innerHTML=h;}
function renderDec(ad){const chars=[];
  // 8 bit per carattere (ASCII 8 bit, come codificato da encText)
  for(let i=0;i+7<ad.length;i+=8){const bits=ad.slice(i,i+8);const code=bits.reduce((a,b)=>(a<<1)|b,0);
    const ch=(code>=32&&code<=126)?String.fromCharCode(code):null;let hc=false;
    for(let d=i;d<=i+7;d++){const bi=Math.floor(d/4);if(bi<D.results.length&&D.results[bi].syndrome)hc=true;}
    chars.push({char:ch||'�',st:!ch?'su':hc?'co':'ok',code:code});}
  // Mostra i byte sopra il testo decodificato
  let byteHtml='<div style="font-family:monospace;font-size:.7rem;color:var(--muted);margin-bottom:.3rem;word-break:break-all">';
  chars.forEach(c=>{const cls=c.st==='su'?'color:var(--red)':c.st==='co'?'color:var(--yellow)':'';
    byteHtml+=`<span style="${cls};margin-right:3px" title="${c.char}">${c.code}</span>`;});
  byteHtml+='</div>';
  document.getElementById('d-txt').innerHTML=byteHtml+chars.map(c=>`<span class="dc ${c.st}">${c.char===' '?'&nbsp;':c.char}</span>`).join('');
  const nc=D.results.filter(r=>r.syndrome).length,ns=chars.filter(c=>c.st==='su').length;
  let s=`<b>Riepilogo:</b> ${D.results.length} blocchi, ${nc} corretti. <b>${chars.length}</b> caratteri ricostruiti.`;
  if(ns)s+=`<br><span style="color:var(--red)">⚠ ${ns} caratteri sospetti (errori multipli).</span>`;
  const el=document.getElementById('d-sum');el.innerHTML=s;el.className=ns?'info e':'info ok';}
function updDStats(){const nb=D.blocks.length,ncl=D.results.filter(r=>!r.syndrome).length;
  document.getElementById('d-stats').innerHTML=`<div class="sbox"><div class="v mono">${nb}</div><div class="l">Blocchi</div></div>
    <div class="sbox green"><div class="v mono">${ncl}</div><div class="l">OK</div></div>
    <div class="sbox yellow"><div class="v mono">${nb-ncl}</div><div class="l">Corretti</div></div>`;}

/* === WAVEFORM === */
function dW(cid,bits,col,lbl,off,num,isM){const cv=document.getElementById(cid);if(!cv)return 0;const ctx=cv.getContext('2d');
  const pp=13,db=bits.slice(off,off+num),w=db.length*pp+50;cv.width=Math.max(w,260);const h=cv.height,my=h/2,amp=h*.28,lm=35;
  ctx.clearRect(0,0,cv.width,h);ctx.fillStyle='#0f1117';ctx.fillRect(0,0,cv.width,h);
  ctx.strokeStyle='#2e3348';ctx.lineWidth=1;ctx.beginPath();ctx.moveTo(lm,my);ctx.lineTo(cv.width-6,my);ctx.stroke();
  ctx.fillStyle='#8890a8';ctx.font='9px monospace';ctx.textAlign='right';ctx.fillText('+V',lm-2,my-amp+3);ctx.fillText('0',lm-2,my+3);ctx.fillText('−V',lm-2,my+amp+3);
  ctx.strokeStyle='#1e2230';ctx.setLineDash([2,2]);ctx.beginPath();ctx.moveTo(lm,my-amp);ctx.lineTo(cv.width-6,my-amp);ctx.moveTo(lm,my+amp);ctx.lineTo(cv.width-6,my+amp);ctx.stroke();ctx.setLineDash([]);
  ctx.fillStyle=col;ctx.font='bold 10px monospace';ctx.textAlign='left';ctx.fillText(lbl,lm,12);
  ctx.fillStyle='#8890a8';ctx.font='8px monospace';ctx.textAlign='center';db.forEach((b,i)=>ctx.fillText(b,lm+i*pp+pp/2,23));
  ctx.strokeStyle=col;ctx.lineWidth=2;ctx.beginPath();let tr=0;
  if(!isM){db.forEach((b,i)=>{const x=lm+i*pp,y=b?my-amp:my+amp;if(!i){ctx.moveTo(x,my);ctx.lineTo(x,y);tr++;}else{if((db[i-1]?my-amp:my+amp)!==y)tr++;ctx.lineTo(x,y);}ctx.lineTo(x+pp,y);});}
  else{let pe=0;db.forEach((b,i)=>{const x=lm+i*pp,xm=x+pp/2,xe=x+pp;let f=b?0:1,s=b?1:0;const yf=f?my-amp:my+amp,ys=s?my-amp:my+amp;
    if(!i){ctx.moveTo(x,my);ctx.lineTo(x,yf);tr++;}else{if((pe?my-amp:my+amp)!==yf){ctx.lineTo(x,yf);tr++;}}
    ctx.lineTo(xm,yf);ctx.lineTo(xm,ys);tr++;ctx.lineTo(xe,ys);pe=s;});}
  ctx.stroke();return tr;}
function drawEW(){if(!E.cur.length)return;const a=E.cur.flat(),n=+(document.getElementById('e-wn')||{}).value||56,o=+(document.getElementById('e-wo')||{}).value||0;
  const nv=document.getElementById('e-wn-v'),ov=document.getElementById('e-wo-v'),wo=document.getElementById('e-wo');
  if(nv)nv.textContent=n;if(ov)ov.textContent=o;if(wo)wo.max=Math.max(0,a.length-n);
  const t1=dW('e-nrz',a,'#5b8def','NRZ-L',o,n,0),t2=dW('e-man',a,'#f0883e','Manchester',o,n,1);
  const el=document.getElementById('e-tr');if(el)el.innerHTML=`<span style="color:#5b8def">NRZ:${t1}</span><span style="color:#f0883e">Man:${t2}</span><span style="color:var(--muted)">${t1?(t2/t1).toFixed(1):'—'}×</span>`;}
function drawDW(){if(!D.blocks.length)return;const a=D.blocks.flat(),n=+(document.getElementById('d-wn')||{}).value||56,o=+(document.getElementById('d-wo')||{}).value||0;
  const nv=document.getElementById('d-wn-v'),ov=document.getElementById('d-wo-v'),wo=document.getElementById('d-wo');
  if(nv)nv.textContent=n;if(ov)ov.textContent=o;if(wo)wo.max=Math.max(0,a.length-n);
  const t1=dW('d-nrz',a,'#5b8def','NRZ-L',o,n,0),t2=dW('d-man',a,'#f0883e','Manchester',o,n,1);
  const el=document.getElementById('d-tr');if(el)el.innerHTML=`<span style="color:#5b8def">NRZ:${t1}</span><span style="color:#f0883e">Man:${t2}</span><span style="color:var(--muted)">${t1?(t2/t1).toFixed(1):'—'}×</span>`;}

/* === ANALISI GAUSSIANA (da file SRAW) === */
async function analyzeGauss(){
  const file=document.getElementById('g-file').value;if(!file){alert('Seleziona un file.');return;}
  const fd=new FormData();fd.append('api_action','analyze_sraw');fd.append('sraw_file',file);
  try{
    const r=await(await fetch('',{method:'POST',body:fd})).json();
    if(r.error){alert(r.error);return;}
    document.getElementById('g-res').style.display='block';
    drawGauss(r,file);
  }catch(e){alert('Errore: '+e.message);}
}
function drawGauss(d,label){
  const cv=document.getElementById('g-cv');cv.width=cv.parentElement.clientWidth-20;const w=cv.width,h=300;cv.height=h;
  const ctx=cv.getContext('2d');ctx.clearRect(0,0,w,h);ctx.fillStyle='#0f1117';ctx.fillRect(0,0,w,h);
  const hist=d.histogram,gc=d.gaussian_fit,bc=d.bin_centers;
  const mx=Math.max(...hist,...gc);const m={l:55,r:20,t:25,b:30},pw=w-m.l-m.r,ph=h-m.t-m.b,bw=pw/hist.length;
  // Bars
  ctx.globalAlpha=.75;hist.forEach((v,i)=>{const bh=mx>0?v/mx*ph:0;ctx.fillStyle='#5b8def';ctx.fillRect(m.l+i*bw,m.t+ph-bh,bw-1,bh);});
  ctx.globalAlpha=1;
  // Gaussian fit
  ctx.strokeStyle='#f0883e';ctx.lineWidth=2;ctx.setLineDash([4,2]);ctx.beginPath();
  gc.forEach((v,i)=>{const x=m.l+i*bw+bw/2,y=m.t+ph-(mx>0?v/mx*ph:0);if(!i)ctx.moveTo(x,y);else ctx.lineTo(x,y);});ctx.stroke();ctx.setLineDash([]);
  // Axes
  ctx.strokeStyle='#2e3348';ctx.lineWidth=1;ctx.beginPath();ctx.moveTo(m.l,h-m.b);ctx.lineTo(w-m.r,h-m.b);ctx.moveTo(m.l,m.t);ctx.lineTo(m.l,h-m.b);ctx.stroke();
  ctx.fillStyle='#8890a8';ctx.font='10px monospace';ctx.textAlign='center';ctx.fillText('Livello',w/2,h-5);
  ctx.save();ctx.translate(10,h/2);ctx.rotate(-Math.PI/2);ctx.fillText('Frequenza',0,0);ctx.restore();
  ctx.fillStyle='#5b8def';ctx.font='bold 11px sans-serif';ctx.textAlign='left';ctx.fillText(label,m.l+5,18);
  ctx.fillStyle='#f0883e';ctx.fillText('Fit gaussiano',m.l+5+ctx.measureText(label).width+15,18);
  // Stats
  document.getElementById('g-stats').innerHTML=`<div class="stats">
    <div class="sbox"><div class="v mono">${d.mean.toFixed(1)}</div><div class="l">Media (µ)</div></div>
    <div class="sbox"><div class="v mono">${d.std.toFixed(1)}</div><div class="l">Dev. std (σ)</div></div>
    <div class="sbox"><div class="v mono">${d.min.toFixed(0)}</div><div class="l">Min</div></div>
    <div class="sbox"><div class="v mono">${d.max.toFixed(0)}</div><div class="l">Max</div></div>
    <div class="sbox"><div class="v mono">${d.n_samples}</div><div class="l">Campioni</div></div>
    <div class="sbox"><div class="v mono">${d.n_unique_levels}</div><div class="l">Livelli unici</div></div></div>`;
}
async function refreshSrawList(){
  const fd=new FormData();fd.append('api_action','list_sraw');
  const r=await(await fetch('',{method:'POST',body:fd})).json();
  const sel=document.getElementById('g-file');sel.innerHTML='';
  (r.files||[]).forEach(f=>{const o=document.createElement('option');o.value=f;o.textContent=f;sel.appendChild(o);});
}

/* === TABS === */
function switchTab(t){document.querySelectorAll('.tab-btn').forEach(b=>b.classList.remove('active'));
  document.querySelectorAll('.tab-content').forEach(c=>c.classList.remove('active'));
  document.getElementById('tab-'+t).classList.add('active');
  document.querySelectorAll('.tab-btn')[['enc','dec','gauss'].indexOf(t)].classList.add('active');}
document.getElementById('e-txt').addEventListener('input',function(){document.getElementById('e-cc').textContent=this.value.length;});
// Init ffmpeg path from cookie
const _ff=document.getElementById('e-ffmpeg');if(_ff)_ff.value=getCookie('ffmpeg_path')||'';
// Init MP3 preview
updateMp3Preview();
</script>
</body>
</html>
