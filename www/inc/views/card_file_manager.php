<div class="card full" id="filemgr">
  <h2>File Manager</h2>

  <div class="fm-toolbar">
    <button class="fm-btn" id="fm-btn-up" title="Su di un livello">&#8593; su</button>
    <button class="fm-btn" id="fm-btn-mkdir" title="Nuova cartella">+ cartella</button>
    <button class="fm-btn" id="fm-btn-refresh" title="Aggiorna">&#8635;</button>
    <span class="fm-path" id="fm-path">/</span>
  </div>

  <div class="fm-list" id="fm-list">
    <table class="fm-table" id="fm-table">
      <tbody id="fm-tbody"></tbody>
    </table>
  </div>
  <div class="fm-status" id="fm-status"></div>
</div>

<script>
(function () {
  const API = '?action=filemgr_json';
  let allFiles = [];
  let allDirs  = [];
  let cwd = '';

  const elTbody  = document.getElementById('fm-tbody');
  const elPath   = document.getElementById('fm-path');
  const elStatus = document.getElementById('fm-status');

  function showStatus(msg, isError) {
    elStatus.textContent = msg;
    elStatus.className = 'fm-status' + (isError ? ' fm-err' : ' fm-ok');
    clearTimeout(elStatus._t);
    elStatus._t = setTimeout(() => { elStatus.textContent = ''; elStatus.className = 'fm-status'; }, 4000);
  }

  async function postAction(tool, params) {
    const body = new URLSearchParams();
    body.set('tool', tool);
    for (const [k, v] of Object.entries(params)) body.set(k, v);
    const res = await fetch('', {
      method: 'POST',
      body,
      headers: { 'Accept': 'application/json' }
    });
    const data = await res.json();
    if (!data.ok) throw new Error(data.error || 'Errore sconosciuto');
    return data.message;
  }

  async function loadData() {
    const res = await fetch(API, { cache: 'no-store' });
    const data = await res.json();
    if (!data.ok) return;
    allDirs = (data.dirs || []).filter(d => d !== '');
    const sraw = (data.sraw || []).map(f => ({ path: f, ext: 'sraw' }));
    const mp3  = (data.mp3  || []).map(f => ({ path: f, ext: 'mp3' }));
    allFiles = [...sraw, ...mp3].sort((a, b) =>
      a.path.localeCompare(b.path, undefined, { sensitivity: 'base' }));
  }

  function getChildren() {
    const prefix = cwd === '' ? '' : cwd + '/';
    const dirs = [];
    const seen = new Set();
    for (const d of allDirs) {
      if (cwd === '' || (d.startsWith(prefix) && d !== cwd)) {
        const rest = d.substring(prefix.length);
        const child = rest.split('/')[0];
        if (child && !seen.has(child)) { seen.add(child); dirs.push(child); }
      }
    }
    dirs.sort((a, b) => a.localeCompare(b, undefined, { sensitivity: 'base' }));

    const files = [];
    for (const f of allFiles) {
      const dir = f.path.lastIndexOf('/') >= 0 ? f.path.substring(0, f.path.lastIndexOf('/')) : '';
      if (dir === cwd) {
        files.push({ name: f.path.substring(prefix.length), fullPath: f.path, ext: f.ext });
      }
    }
    return { dirs, files };
  }

  function render() {
    elPath.textContent = '/' + (cwd || '');
    const { dirs, files } = getChildren();
    elTbody.innerHTML = '';

    if (dirs.length === 0 && files.length === 0) {
      const tr = document.createElement('tr');
      tr.innerHTML = '<td colspan="2" class="fm-empty">— vuota —</td>';
      elTbody.appendChild(tr);
      return;
    }

    for (const d of dirs) {
      const fullPath = cwd === '' ? d : cwd + '/' + d;
      const tr = document.createElement('tr');
      tr.className = 'fm-row fm-row-dir';

      const tdName = document.createElement('td');
      tdName.className = 'fm-cell-name';
      tdName.innerHTML = '<span class="fm-icon-dir">&#x1F4C1;</span> ' + esc(d);
      tdName.style.cursor = 'pointer';
      tdName.addEventListener('click', () => { cwd = fullPath; render(); });
      tdName.title = 'Click per aprire';

      const tdAct = document.createElement('td');
      tdAct.className = 'fm-cell-actions';
      tdAct.appendChild(makeBtn('&#x270F; rinomina', () => doRename(fullPath)));
      tdAct.appendChild(makeBtn('&#x21C4; sposta', () => doMove(fullPath)));
      tdAct.appendChild(makeBtn('&#x2716; elimina', () => doDelete(fullPath), true));

      tr.appendChild(tdName);
      tr.appendChild(tdAct);
      elTbody.appendChild(tr);
    }

    for (const f of files) {
      const tr = document.createElement('tr');
      tr.className = 'fm-row fm-row-file';

      const tdName = document.createElement('td');
      tdName.className = 'fm-cell-name';
      const fileIcon = f.ext === 'sraw' ? '&#x1F4C8;' : '&#x266B;';
      tdName.innerHTML = '<span class="fm-icon-file">' + fileIcon + '</span> ' + esc(f.name);

      const tdAct = document.createElement('td');
      tdAct.className = 'fm-cell-actions';

      if (f.ext === 'sraw') {
        tdAct.appendChild(makeBtn('&#x1F441; viewer', () => {
          window.open('viewer/index.html?file=' + encodeURIComponent(f.fullPath), '_blank');
        }));
        tdAct.appendChild(makeBtn('&#x270D; designer', () => {
          window.open('designer/index.html?file=' + encodeURIComponent(f.fullPath), '_blank');
        }));
      }

      tdAct.appendChild(makeBtn('&#x270F; rinomina', () => doRename(f.fullPath)));
      tdAct.appendChild(makeBtn('&#x21C4; sposta', () => doMove(f.fullPath)));
      tdAct.appendChild(makeBtn('&#x2716; elimina', () => doDelete(f.fullPath), true));

      tr.appendChild(tdName);
      tr.appendChild(tdAct);
      elTbody.appendChild(tr);
    }
  }

  function esc(s) {
    const d = document.createElement('span');
    d.textContent = s;
    return d.innerHTML;
  }

  function makeBtn(labelHtml, fn, danger) {
    const b = document.createElement('button');
    b.className = 'fm-action-btn' + (danger ? ' fm-action-danger' : '');
    b.innerHTML = labelHtml;
    b.addEventListener('click', (e) => { e.stopPropagation(); fn(); });
    return b;
  }

  async function doRename(relPath) {
    const oldName = relPath.split('/').pop();
    const newName = prompt('Nuovo nome:', oldName);
    if (!newName || newName === oldName) return;
    try {
      await postAction('filemgr_rename', { target_rel: relPath, new_name: newName });
      showStatus('Rinominato: ' + newName, false);
      await loadData(); render();
      if (typeof refreshFileSelects === 'function') refreshFileSelects();
    } catch (e) { showStatus(e.message, true); }
  }

  async function doMove(relPath) {
    const choices = ['/ (radice)', ...allDirs];
    const msg = 'Sposta "' + relPath.split('/').pop() + '" in:\n\n' +
      choices.map((d, i) => i + ': ' + d).join('\n') +
      '\n\nInserisci il numero:';
    const idx = prompt(msg);
    if (idx === null) return;
    const n = parseInt(idx, 10);
    if (isNaN(n) || n < 0 || n >= choices.length) { showStatus('Scelta non valida', true); return; }
    const targetDir = n === 0 ? '' : allDirs[n - 1];
    try {
      await postAction('filemgr_move', { source_rel: relPath, target_dir: targetDir });
      showStatus('Spostato in: ' + (targetDir || '/'), false);
      await loadData(); render();
      if (typeof refreshFileSelects === 'function') refreshFileSelects();
    } catch (e) { showStatus(e.message, true); }
  }

  async function doDelete(relPath) {
    if (!confirm('Cancellare "' + relPath + '"?')) return;
    try {
      await postAction('filemgr_delete', { target_rel: relPath });
      showStatus('Cancellato: ' + relPath, false);
      await loadData(); render();
      if (typeof refreshFileSelects === 'function') refreshFileSelects();
    } catch (e) { showStatus(e.message, true); }
  }

  async function doMkdir() {
    const name = prompt('Nome nuova cartella:');
    if (!name) return;
    try {
      await postAction('filemgr_mkdir', { parent_dir: cwd, new_dir_name: name });
      showStatus('Creata: ' + name, false);
      await loadData(); render();
      if (typeof refreshFileSelects === 'function') refreshFileSelects();
    } catch (e) { showStatus(e.message, true); }
  }

  document.getElementById('fm-btn-up').addEventListener('click', () => {
    if (cwd === '') return;
    const i = cwd.lastIndexOf('/');
    cwd = i > 0 ? cwd.substring(0, i) : '';
    render();
  });
  document.getElementById('fm-btn-mkdir').addEventListener('click', doMkdir);
  document.getElementById('fm-btn-refresh').addEventListener('click', async () => {
    await loadData(); render();
    showStatus('Aggiornato', false);
  });

  loadData().then(render);
})();
</script>
