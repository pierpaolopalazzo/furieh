<div class="card full">
  <h2>File Manager</h2>

  <div class="subblock">
    <h3>Crea cartella</h3>
    <form method="POST">
      <input type="hidden" name="tool" value="filemgr_mkdir">

      <label>Cartella padre</label>
      <select name="parent_dir" class="dir-select">
        <?php foreach ($all_dirs as $d): ?>
          <option value="<?= h($d) ?>"><?= $d === '' ? '/' : h($d) ?></option>
        <?php endforeach; ?>
      </select>

      <label>Nome nuova cartella</label>
      <input type="text" name="new_dir_name" value="nuova_cartella">

      <button type="submit">Crea cartella →</button>
    </form>
  </div>

  <div class="subblock">
    <h3>Rinomina</h3>
    <form method="POST">
      <input type="hidden" name="tool" value="filemgr_rename">

      <label>Elemento</label>
      <select name="target_rel" class="mgr-select">
        <optgroup label="Cartelle">
          <?php foreach ($all_dirs as $d): ?>
            <?php if ($d !== ''): ?>
              <option value="<?= h($d) ?>">[DIR] <?= h($d) ?></option>
            <?php endif; ?>
          <?php endforeach; ?>
        </optgroup>
        <optgroup label="File">
          <?php foreach ($manageable_files as $f): ?>
            <option value="<?= h($f) ?>">[FILE] <?= h($f) ?></option>
          <?php endforeach; ?>
        </optgroup>
      </select>

      <label>Nuovo nome</label>
      <input type="text" name="new_name" value="nuovo_nome">

      <button type="submit">Rinomina →</button>
    </form>
  </div>

  <div class="subblock">
    <h3>Sposta</h3>
    <form method="POST">
      <input type="hidden" name="tool" value="filemgr_move">

      <label>Elemento sorgente</label>
      <select name="source_rel" class="mgr-select">
        <optgroup label="Cartelle">
          <?php foreach ($all_dirs as $d): ?>
            <?php if ($d !== ''): ?>
              <option value="<?= h($d) ?>">[DIR] <?= h($d) ?></option>
            <?php endif; ?>
          <?php endforeach; ?>
        </optgroup>
        <optgroup label="File">
          <?php foreach ($manageable_files as $f): ?>
            <option value="<?= h($f) ?>">[FILE] <?= h($f) ?></option>
          <?php endforeach; ?>
        </optgroup>
      </select>

      <label>Cartella di destinazione</label>
      <select name="target_dir" class="dir-select">
        <?php foreach ($all_dirs as $d): ?>
          <option value="<?= h($d) ?>"><?= $d === '' ? '/' : h($d) ?></option>
        <?php endforeach; ?>
      </select>

      <button type="submit">Sposta →</button>
    </form>
  </div>

  <div class="subblock">
    <h3>Cancella</h3>
    <form method="POST">
      <input type="hidden" name="tool" value="filemgr_delete">

      <label>Elemento</label>
      <select name="target_rel" class="mgr-select">
        <optgroup label="Cartelle (solo se vuote)">
          <?php foreach ($all_dirs as $d): ?>
            <?php if ($d !== ''): ?>
              <option value="<?= h($d) ?>">[DIR] <?= h($d) ?></option>
            <?php endif; ?>
          <?php endforeach; ?>
        </optgroup>
        <optgroup label="File .sraw / .mp3">
          <?php foreach ($manageable_files as $f): ?>
            <option value="<?= h($f) ?>">[FILE] <?= h($f) ?></option>
          <?php endforeach; ?>
        </optgroup>
      </select>

      <button type="submit">Cancella →</button>
    </form>
  </div>

  <div class="hint">Rinomina e cancella restano limitati a file <code>.sraw</code> e <code>.mp3</code>. Le cartelle si cancellano solo se vuote.</div>
</div>

<div class="card full">
  <h2>File in /data</h2>
  <div class="tree">
    <?php if (!$manageable_files && count($all_dirs) <= 1): ?>
      <div class="file-tag">— cartella vuota —</div>
    <?php else: ?>
      <?php foreach ($all_dirs as $d): ?>
        <?php if ($d !== ''): ?>
          <div class="dir">[DIR] <?= h($d) ?></div>
        <?php endif; ?>
      <?php endforeach; ?>

      <?php foreach ($manageable_files as $f): ?>
        <div class="file">[FILE] <?= h($f) ?></div>
      <?php endforeach; ?>
    <?php endif; ?>
  </div>
</div>