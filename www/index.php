<?php
require __DIR__ . '/inc/bootstrap.php';

// ── JSON API: lista file per designer/viewer ──────────────────────────────────
if (($_GET['action'] ?? '') === 'filemgr_json') {
    header('Content-Type: application/json; charset=UTF-8');
    header('Cache-Control: no-store');
    try {
        echo json_encode([
            'ok'    => true,
            'sraw'  => list_sraw(),
            'mp3'   => list_mp3(),
            'dirs'  => list_recursive_dirs(),
        ], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    } catch (Throwable $e) {
        http_response_code(500);
        echo json_encode(['ok' => false, 'error' => $e->getMessage()]);
    }
    exit;
}

// ── JSON API: operazioni file manager (POST) ────────────────────────────────
if ($_SERVER['REQUEST_METHOD'] === 'POST'
    && in_array($_POST['tool'] ?? '', ['filemgr_mkdir', 'filemgr_rename', 'filemgr_move', 'filemgr_delete'], true)
    && (isset($_SERVER['HTTP_X_REQUESTED_WITH']) || str_contains($_SERVER['HTTP_ACCEPT'] ?? '', 'application/json'))
) {
    header('Content-Type: application/json; charset=UTF-8');
    try {
        $tool = $_POST['tool'];
        if ($tool === 'filemgr_mkdir') {
            $created = create_subdir($_POST['parent_dir'] ?? '', $_POST['new_dir_name'] ?? '');
            echo json_encode(['ok' => true, 'message' => "Cartella creata: $created"]);
        } elseif ($tool === 'filemgr_rename') {
            $renamed = rename_data_item($_POST['target_rel'] ?? '', $_POST['new_name'] ?? '');
            echo json_encode(['ok' => true, 'message' => "Rinominato: $renamed"]);
        } elseif ($tool === 'filemgr_move') {
            $moved = move_data_item($_POST['source_rel'] ?? '', $_POST['target_dir'] ?? '');
            echo json_encode(['ok' => true, 'message' => "Spostato: $moved"]);
        } elseif ($tool === 'filemgr_delete') {
            delete_data_item($_POST['target_rel'] ?? '');
            echo json_encode(['ok' => true, 'message' => "Cancellato: " . ($_POST['target_rel'] ?? '')]);
        }
    } catch (Throwable $e) {
        http_response_code(400);
        echo json_encode(['ok' => false, 'error' => $e->getMessage()]);
    }
    exit;
}

require __DIR__ . '/inc/actions.php';

$sraw_files       = list_sraw();
$mp3_files        = list_mp3();
$manageable_files = list_manageable_files();
$all_dirs         = list_recursive_dirs();
?>
<!DOCTYPE html>
<html lang="it">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1.0">
<title>furiè UniFG</title>
<link rel="stylesheet" href="inc/style.css">
</head>
<body>

<header>
  <h1>furiè UniFG</h1>
  <span><img src=logo_unifg.png width=75 height=75></span>
  <span>Signal Processing Toolkit</span>
</header>

<nav class="app-nav">
  <a class="app-btn" href="viewer/index.html" target="_blank">▸ VIEWER</a>
  <a class="app-btn" href="designer/index.html" target="_blank">▸ DESIGNER</a>
  <a class="app-btn" href="gauss-hamming/" target="_blank">▸ GAUSS-HAMMING</a>
</nav>

<main>
  <?php require __DIR__ . '/inc/views/messages.php'; ?>
  <?php require __DIR__ . '/inc/views/cards_mp3.php'; ?>
  <?php require __DIR__ . '/inc/views/cards_processing.php'; ?>
  <?php require __DIR__ . '/inc/views/card_file_manager.php'; ?>

  <div class="card full" style="opacity:0.5">
    <h2>Parametri formato</h2>
    <label>Max campioni</label>
    <input type="text" value="2000000" disabled>
  </div>
</main>


<script>
// ── Refresh dinamico dei select file ─────────────────────────────────────────
// Chiama ?action=filemgr_json e ripopola tutti i <select> con classe
// sraw-select, mp3-select, dir-select, mgr-select senza ricaricare la pagina.

async function refreshFileSelects() {

  let data;
  try {
    const res = await fetch('?action=filemgr_json', { cache: 'no-store' });
    data = await res.json();
  } catch (e) {
    console.error('refreshFileSelects fetch error', e);
    return;
  }
  if (!data.ok) {
    return;
  }

  const srawFiles = data.sraw || [];
  const mp3Files  = data.mp3  || [];
  const dirs      = data.dirs || [];

  // Helper: rebuild a <select>, preserving current selection if still valid
  function rebuildSelect(sel, entries, makeOption, emptyLabel) {
    const prev = sel.value;
    sel.innerHTML = '';
    if (entries.length === 0) {
      const opt = document.createElement('option');
      opt.value = '';
      opt.textContent = emptyLabel;
      sel.appendChild(opt);
      return;
    }
    for (const e of entries) {
      const { val, text, group } = makeOption(e);
      // optgroup support
      if (group) {
        let grp = sel.querySelector(`optgroup[label="${CSS.escape ? group : group}"]`);
        if (!grp) { grp = document.createElement('optgroup'); grp.label = group; sel.appendChild(grp); }
        const opt = document.createElement('option');
        opt.value = val; opt.textContent = text;
        grp.appendChild(opt);
      } else {
        const opt = document.createElement('option');
        opt.value = val; opt.textContent = text;
        sel.appendChild(opt);
      }
    }
    if (prev && [...sel.options].some(o => o.value === prev)) sel.value = prev;
  }

  // sraw-select: file SRAW semplici
  document.querySelectorAll('select.sraw-select').forEach(sel => {
    rebuildSelect(sel, srawFiles,
      f => ({ val: f, text: f }),
      '— nessun SRAW in /data —');
  });

  // mp3-select: file MP3
  // Il player-select usa URL relativi (data/...), gli altri usano path relativo
  document.querySelectorAll('select.mp3-select').forEach(sel => {
    const isPlayer = sel.id === 'mp3-player-select';
    rebuildSelect(sel, mp3Files,
      f => ({ val: isPlayer ? 'data/' + f : f, text: f }),
      '— nessun MP3 in /data —');
    // Se è il player, aggiorna anche la sorgente audio
    if (isPlayer) {
      const player = document.getElementById('mp3-player');
      if (player && sel.value) { player.src = sel.value; player.load(); }
    }
  });

  // dir-select: solo cartelle
  document.querySelectorAll('select.dir-select').forEach(sel => {
    rebuildSelect(sel, dirs,
      d => ({ val: d, text: d === '' ? '/' : d }),
      '/');
  });

  // mgr-select: cartelle + file gestibili (sraw + mp3)
  document.querySelectorAll('select.mgr-select').forEach(sel => {
    const entries = [
      ...dirs.filter(d => d !== '').map(d => ({ val: d, text: '[DIR] ' + d, group: 'Cartelle' })),
      ...srawFiles.map(f => ({ val: f, text: '[FILE] ' + f, group: 'File' })),
      ...mp3Files.map(f  => ({ val: f, text: '[FILE] ' + f, group: 'File' })),
    ];
    rebuildSelect(sel, entries, e => e, '— nessun elemento —');
  });

  if (typeof syncAllOutputDirs === 'function') syncAllOutputDirs();
}

// Refresh automatico al focus su qualsiasi select dinamico
document.addEventListener('focusin', e => {
  if (e.target.matches('select.sraw-select, select.mp3-select, select.dir-select, select.mgr-select')) {
    refreshFileSelects();
  }
});

// Refresh iniziale al caricamento della pagina
window.addEventListener('DOMContentLoaded', refreshFileSelects);

// ── Auto-prepend cartella sorgente nell'output ──────────────────────────────
function _getDir(filepath) {
  const i = filepath.lastIndexOf('/');
  return i > 0 ? filepath.substring(0, i + 1) : '';
}

function syncOutputDir(selectEl) {
  const form = selectEl.closest('form');
  if (!form) return;
  const out = form.querySelector('input[name="output_sraw"], input[name="output_mp3"]');
  if (!out) return;
  const dir = _getDir(selectEl.value);
  const basename = out.value.replace(/^.*\//, '');
  out.value = dir + basename;
}

function syncAllOutputDirs() {
  document.querySelectorAll('select[name="input_mp3"], select[name="input_sraw"], select[name="input_a"]')
    .forEach(sel => { if (sel.value) syncOutputDir(sel); });
}

document.addEventListener('change', function (e) {
  const sel = e.target;
  if (!sel.matches('select')) return;
  if (['input_mp3', 'input_sraw', 'input_a'].includes(sel.name)) {
    syncOutputDir(sel);
  }
});

// ── Setup MP3: persistenza cookie ───────────────────────────────────────────
(function () {
  const COOKIE_DAYS = 365 * 5;

  function setCookie(name, value) {
    document.cookie = name + '=' + encodeURIComponent(value) +
      ';max-age=' + (COOKIE_DAYS * 86400) + ';path=/;SameSite=Lax';
  }
  function getCookie(name) {
    const m = document.cookie.match('(?:^|; )' + name + '=([^;]*)');
    return m ? decodeURIComponent(m[1]) : '';
  }

  // ffmpeg path
  const ffmpegInput = document.getElementById('setup-ffmpeg-path');
  if (ffmpegInput) {
    ffmpegInput.value = getCookie('ffmpeg_path') || '';
    ffmpegInput.addEventListener('change', function () {
      setCookie('ffmpeg_path', this.value.trim());
      syncFfmpegHidden();
    });
  }

  function syncFfmpegHidden() {
    const val = ffmpegInput ? ffmpegInput.value.trim() : '';
    document.querySelectorAll('.ffmpeg-path-hidden').forEach(h => { h.value = val; });
  }

  // sample rate
  const srSelect = document.getElementById('setup-sample-rate');
  if (srSelect) {
    const saved = getCookie('mp3_sample_rate');
    if (saved && [...srSelect.options].some(o => o.value === saved)) {
      srSelect.value = saved;
    }
    srSelect.addEventListener('change', function () {
      setCookie('mp3_sample_rate', this.value);
    });
  }

  // sync hidden fields on page load and before form submit
  syncFfmpegHidden();
  document.querySelectorAll('form').forEach(form => {
    form.addEventListener('submit', syncFfmpegHidden);
  });
})();
</script>
</body>
</html>