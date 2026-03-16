<div class="card">
  <h2>Trasformata (FFT / DFT)</h2>
  <form method="POST">
    <input type="hidden" name="tool" value="transformer">

    <label>File SRAW input</label>
    <select name="input_sraw" class="sraw-select">
      <?php foreach ($sraw_files as $f): ?>
        <option value="<?= h($f) ?>"><?= h($f) ?></option>
      <?php endforeach; ?>
      <?php if (!$sraw_files): ?><option value="">— nessun SRAW in /data —</option><?php endif; ?>
    </select>

    <label>Nome file output</label>
    <input type="text" name="output_sraw" value="spectrum.sraw">

    <label>Modalità</label>
    <select name="mode">
      <option value="fft">FFT (veloce)</option>
      <option value="dft">DFT (naïve, O(N²))</option>
      <option value="ifft">IFFT (inversa FFT)</option>
      <option value="idft">IDFT (inversa DFT naïve)</option>
    </select>

    <button type="submit">Trasforma →</button>
  </form>
</div>

<div class="card">
  <h2>Convoluzione / Correlazione</h2>
  <form method="POST">
    <input type="hidden" name="tool" value="convolver">

    <label>File A</label>
    <select name="input_a" class="sraw-select">
      <?php foreach ($sraw_files as $f): ?>
        <option value="<?= h($f) ?>"><?= h($f) ?></option>
      <?php endforeach; ?>
      <?php if (!$sraw_files): ?><option value="">— nessun SRAW in /data —</option><?php endif; ?>
    </select>

    <label>File B (ignorato per autocorr)</label>
    <select name="input_b" class="sraw-select">
      <?php foreach ($sraw_files as $f): ?>
        <option value="<?= h($f) ?>"><?= h($f) ?></option>
      <?php endforeach; ?>
      <?php if (!$sraw_files): ?><option value="">— nessun SRAW in /data —</option><?php endif; ?>
    </select>

    <label>Nome file output</label>
    <input type="text" name="output_sraw" value="conv_out.sraw">

    <label>Operazione</label>
    <select name="mode">
      <option value="conv">Convoluzione (A * B)</option>
      <option value="corr">Auto-correlazione (solo A)</option>
      <option value="xcorr">Cross-correlazione (A, B)</option>
    </select>

    <label>Dominio fisico</label>
    <select name="domain">
      <option value="time" selected>tempo</option>
      <option value="freq">frequenza</option>
    </select>

    <button type="submit">Esegui →</button>
  </form>
</div>

<div class="card">
  <h2>Somma SRAW</h2>
  <form method="POST">
    <input type="hidden" name="tool" value="sraw_op">
    <input type="hidden" name="op" value="sum">

    <label>File A</label>
    <select name="input_a" class="sraw-select">
      <?php foreach ($sraw_files as $f): ?><option value="<?= h($f) ?>"><?= h($f) ?></option><?php endforeach; ?>
      <?php if (!$sraw_files): ?><option value="">— nessun SRAW —</option><?php endif; ?>
    </select>

    <label>File B</label>
    <select name="input_b" class="sraw-select">
      <?php foreach ($sraw_files as $f): ?><option value="<?= h($f) ?>"><?= h($f) ?></option><?php endforeach; ?>
      <?php if (!$sraw_files): ?><option value="">— nessun SRAW —</option><?php endif; ?>
    </select>

    <label>Output</label>
    <input type="text" name="output_sraw" value="sum_out.sraw">

    <button type="submit">Somma →</button>
  </form>
</div>

<div class="card">
  <h2>Moltiplicazione SRAW</h2>
  <form method="POST">
    <input type="hidden" name="tool" value="sraw_op">
    <input type="hidden" name="op" value="mul">

    <label>File A</label>
    <select name="input_a" class="sraw-select">
      <?php foreach ($sraw_files as $f): ?><option value="<?= h($f) ?>"><?= h($f) ?></option><?php endforeach; ?>
      <?php if (!$sraw_files): ?><option value="">— nessun SRAW —</option><?php endif; ?>
    </select>

    <label>File B</label>
    <select name="input_b" class="sraw-select">
      <?php foreach ($sraw_files as $f): ?><option value="<?= h($f) ?>"><?= h($f) ?></option><?php endforeach; ?>
      <?php if (!$sraw_files): ?><option value="">— nessun SRAW —</option><?php endif; ?>
    </select>

    <label>Output</label>
    <input type="text" name="output_sraw" value="mul_out.sraw">

    <button type="submit">Moltiplica →</button>
  </form>
</div>

<div class="card">
  <h2>Gain / Attenuazione</h2>
  <form method="POST">
    <input type="hidden" name="tool" value="sraw_op">
    <input type="hidden" name="op" value="gain">

    <label>File A</label>
    <select name="input_a" class="sraw-select">
      <?php foreach ($sraw_files as $f): ?><option value="<?= h($f) ?>"><?= h($f) ?></option><?php endforeach; ?>
      <?php if (!$sraw_files): ?><option value="">— nessun SRAW —</option><?php endif; ?>
    </select>

    <label>Fattore</label>
    <input type="number" name="gain" value="1" step="0.1">

    <label>Output</label>
    <input type="text" name="output_sraw" value="gain_out.sraw">

    <button type="submit">Applica gain →</button>
  </form>
</div>

<div class="card">
  <h2>Traslazione</h2>
  <form method="POST">
    <input type="hidden" name="tool" value="sraw_op">
    <input type="hidden" name="op" value="shift">

    <label>File A</label>
    <select name="input_a" class="sraw-select">
      <?php foreach ($sraw_files as $f): ?><option value="<?= h($f) ?>"><?= h($f) ?></option><?php endforeach; ?>
      <?php if (!$sraw_files): ?><option value="">— nessun SRAW —</option><?php endif; ?>
    </select>

    <label>Valore traslazione</label>
    <input type="number" name="shift_value" value="100" step="0.1">

    <label>Unità traslazione</label>
    <select name="shift_unit">
      <option value="samples" selected>campioni</option>
      <option value="time">secondi</option>
      <option value="freq">hertz</option>
    </select>

    <label>Output</label>
    <input type="text" name="output_sraw" value="shift_out.sraw">

    <button type="submit">Trasla →</button>
  </form>
</div>

<div class="card">
  <h2>Specchio Y</h2>
  <form method="POST">
    <input type="hidden" name="tool" value="sraw_op">
    <input type="hidden" name="op" value="mirror_y">

    <label>File A</label>
    <select name="input_a" class="sraw-select">
      <?php foreach ($sraw_files as $f): ?><option value="<?= h($f) ?>"><?= h($f) ?></option><?php endforeach; ?>
      <?php if (!$sraw_files): ?><option value="">— nessun SRAW —</option><?php endif; ?>
    </select>

    <label>Output</label>
    <input type="text" name="output_sraw" value="mirror_out.sraw">

    <button type="submit">Specchia →</button>
  </form>
</div>

<div class="card">
  <h2>Dilazione X</h2>
  <form method="POST">
    <input type="hidden" name="tool" value="sraw_op">
    <input type="hidden" name="op" value="dilate_x">

    <label>File A</label>
    <select name="input_a" class="sraw-select">
      <?php foreach ($sraw_files as $f): ?><option value="<?= h($f) ?>"><?= h($f) ?></option><?php endforeach; ?>
      <?php if (!$sraw_files): ?><option value="">— nessun SRAW —</option><?php endif; ?>
    </select>

    <label>Fattore dilazione</label>
    <input type="number" name="dilate_factor" value="2" step="0.1">

    <label>Output</label>
    <input type="text" name="output_sraw" value="dilate_out.sraw">

    <button type="submit">Dilata →</button>
  </form>
</div>