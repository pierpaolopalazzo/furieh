<div class="card">
  <h2>MP3 → SRAW</h2>
  <form method="POST">
    <input type="hidden" name="tool" value="mp3_to_sraw">
    <input type="hidden" name="ffmpeg_path" class="ffmpeg-path-hidden" value="">

    <label>File MP3 sorgente</label>
    <select name="input_mp3" class="mp3-select">
      <?php foreach ($mp3_files as $f): ?>
        <option value="<?= h($f) ?>"><?= h($f) ?></option>
      <?php endforeach; ?>
      <?php if (!$mp3_files): ?><option value="">— nessun MP3 in /data —</option><?php endif; ?>
    </select>

    <label>Nome file output</label>
    <input type="text" name="output_sraw" value="out.sraw">

    <label>Canale</label>
    <select name="channel">
      <option value="MIX">MIX (mono)</option>
      <option value="L">L (sinistro)</option>
      <option value="R">R (destro)</option>
    </select>

    <button type="submit">Converti →</button>
  </form>
</div>

<div class="card">
  <h2>SRAW → MP3</h2>
  <form method="POST">
    <input type="hidden" name="tool" value="sraw_to_mp3">
    <input type="hidden" name="ffmpeg_path" class="ffmpeg-path-hidden" value="">

    <label>File SRAW sorgente</label>
    <select name="input_sraw" class="sraw-select">
      <?php foreach ($sraw_files as $f): ?>
        <option value="<?= h($f) ?>"><?= h($f) ?></option>
      <?php endforeach; ?>
      <?php if (!$sraw_files): ?><option value="">— nessun SRAW in /data —</option><?php endif; ?>
    </select>

    <label>Nome file output</label>
    <input type="text" name="output_mp3" value="out.mp3">

    <label>Bitrate MP3</label>
    <select name="bitrate">
      <option value="64k">64k</option>
      <option value="128k" selected>128k</option>
      <option value="192k">192k</option>
      <option value="320k">320k</option>
    </select>

    <label>Parte del segnale</label>
    <select name="part">
      <option value="real" selected>parte reale</option>
      <option value="imag">parte immaginaria</option>
      <option value="modulus">modulo</option>
    </select>

    <label>Sample rate output</label>
    <select name="sample_rate" id="setup-sample-rate">
      <option value="22050">22050 Hz</option>
      <option value="44100" selected>44100 Hz</option>
      <option value="48000">48000 Hz</option>
    </select>

    <button type="submit">Converti →</button>
  </form>
</div>

<div class="card full">
  <h2>Setup MP3</h2>
  <label>Percorso ffmpeg</label>
  <input type="text" id="setup-ffmpeg-path" placeholder="ffmpeg"
         value="">
  <div class="hint">Percorso assoluto o nome eseguibile nel PATH. Salvato nel browser.</div>
</div>

<div class="card full">
  <h2>Riproduzione MP3</h2>

  <?php if ($mp3_files): ?>
    <?php $default_mp3 = $mp3_files[0]; ?>
    <label>File MP3</label>
    <select id="mp3-player-select" class="mp3-select">
      <?php foreach ($mp3_files as $f): ?>
        <option value="<?= h(rel_data_url($f)) ?>" <?= $f === $default_mp3 ? 'selected' : '' ?>>
          <?= h($f) ?>
        </option>
      <?php endforeach; ?>
    </select>

    <audio id="mp3-player" controls preload="metadata" src="<?= h(rel_data_url($default_mp3)) ?>"></audio>

    <div class="hint">Riproduzione diretta dei file MP3 presenti in <code>data/</code> e sottocartelle.</div>

    <script>
      (function () {
        const sel = document.getElementById('mp3-player-select');
        const player = document.getElementById('mp3-player');
        if (!sel || !player) return;

        sel.addEventListener('change', function () {
          player.src = this.value;
          player.load();
        });
      })();
    </script>
  <?php else: ?>
    <div class="file-tag">— nessun MP3 in /data —</div>
  <?php endif; ?>
</div>