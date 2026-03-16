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
  <button class="app-btn" id="btn-refresh-files" onclick="refreshFileSelects()" title="Ricarica la lista file senza ricaricare la pagina">⟳ Aggiorna file</button>
</nav>

<main>
  <?php require __DIR__ . '/inc/views/messages.php'; ?>
  <?php require __DIR__ . '/inc/views/cards_mp3.php'; ?>
  <?php require __DIR__ . '/inc/views/cards_processing.php'; ?>
  <?php require __DIR__ . '/inc/views/card_file_manager.php'; ?>
</main>


<script>
// ── Refresh dinamico dei select file ─────────────────────────────────────────
// Chiama ?action=filemgr_json e ripopola tutti i <select> con classe
// sraw-select, mp3-select, dir-select, mgr-select senza ricaricare la pagina.

async function refreshFileSelects() {
  const btn = document.getElementById('btn-refresh-files');
  if (btn) { btn.textContent = '⟳ …'; btn.disabled = true; }

  let data;
  try {
    const res = await fetch('?action=filemgr_json', { cache: 'no-store' });
    data = await res.json();
  } catch (e) {
    console.error('refreshFileSelects fetch error', e);
    if (btn) { btn.textContent = '⟳ Aggiorna file'; btn.disabled = false; }
    return;
  }
  if (!data.ok) {
    if (btn) { btn.textContent = '⟳ Aggiorna file'; btn.disabled = false; }
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

  if (btn) { btn.textContent = '⟳ Aggiorna file'; btn.disabled = false; }
}

// Refresh automatico al focus su qualsiasi select dinamico
document.addEventListener('focusin', e => {
  if (e.target.matches('select.sraw-select, select.mp3-select, select.dir-select, select.mgr-select')) {
    refreshFileSelects();
  }
});

// Refresh iniziale al caricamento della pagina
window.addEventListener('DOMContentLoaded', refreshFileSelects);
</script>
</body>
</html>