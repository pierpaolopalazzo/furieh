/**
 * sraw_shared.js — Shared JS library for SRAW viewer and designer
 * Fourier UNIFG Project
 *
 * Depends on a global `C` (conf constants) and a global `cv` (canvas element)
 * and `ctx` (its 2D context), plus a global `vp` viewport object and `pan` state.
 * Each app sets these up before calling anything here.
 *
 * Public API surface — everything exported is documented inline.
 */

'use strict';

// ─────────────────────────────────────────────────────────────
// Config loading  (mirrors get_constants() in sraw_lib.py)
// ─────────────────────────────────────────────────────────────

function parseConf(text) {
  const conf = {};
  for (const raw of text.split('\n')) {
    const line = raw.trim();
    if (!line || line.startsWith('#')) continue;
    const eq = line.indexOf('=');
    if (eq < 0) continue;
    conf[line.slice(0, eq).trim()] = line.slice(eq + 1).trim();
  }
  // Primitives
  const timeRes    = parseFloat(conf['TIME_RESOLUTION_S']);
  const freqRes    = parseFloat(conf['FREQ_RESOLUTION_HZ']);
  const ampTimeRes = parseFloat(conf['AMP_TIME_RESOLUTION_V']);
  const ampFreqRes = parseFloat(conf['AMP_FREQ_RESOLUTION_VS']);
  const maxSamples = parseInt(conf['MAX_SAMPLES']);
  // Derived (mirrors get_constants() in sraw_lib.py)
  const ampIntMax  = Math.round(5.0 / ampTimeRes);   // 500000
  const timeMax    = maxSamples * timeRes / 2;        // 12.5 s
  const freqMax    = maxSamples * freqRes / 2;        // 10000 Hz
  return {
    FORMAT_VER:   conf['FORMAT_VERSION'],
    MAX_SAMPLES:  maxSamples,
    TIME_RES:     timeRes,
    FREQ_RES:     freqRes,
    AMP_TIME_RES: ampTimeRes,
    AMP_FREQ_RES: ampFreqRes,
    AMP_INT_MAX:  ampIntMax,
    AMP_INT_MIN: -ampIntMax,
    TIME_MAX:     timeMax,
    FREQ_MAX:     freqMax,
  };
}

/**
 * Load sraw.conf relative to the page.
 * confRelPath: path from the calling HTML page to sraw.conf
 *              e.g. '../../conf/sraw.conf'
 * Sets the global C and returns it.
 */
async function loadConf(confRelPath) {
  const res = await fetch(confRelPath);
  if (!res.ok) throw new Error(`Cannot load sraw.conf (HTTP ${res.status})`);
  return parseConf(await res.text());
}

// ─────────────────────────────────────────────────────────────
// SRAW parser
// ─────────────────────────────────────────────────────────────

/**
 * Parse a SRAW-1 text string.
 * Returns { axisMode, samples } where samples is an array of
 * { x, re, im } objects sorted by x.
 * Throws on format errors.
 */
function parseSraw(text) {
  const lines = text.split('\n').map(l => l.trim()).filter(l => l && !l.startsWith('#'));
  if (lines[0] !== 'SRAW-1') throw new Error(`Header non valido: "${lines[0]}"`);

  let axisMode = null;
  let inData = false;
  const samples = [];

  for (let i = 1; i < lines.length; i++) {
    const line = lines[i];
    if (line.startsWith('axis_mode,')) {
      axisMode = line.slice('axis_mode,'.length).trim();
      if (axisMode !== 'positive' && axisMode !== 'symmetric')
        throw new Error(`axis_mode non valido: "${axisMode}"`);
      continue;
    }
    if (line === 'data') { inData = true; continue; }
    if (!inData) continue;

    const parts = line.split(',');
    if (parts.length !== 3) throw new Error(`Riga ${i + 1}: attese 3 colonne`);
    const x  = parseInt(parts[0]);
    const re = parseInt(parts[1]);
    const im = parseInt(parts[2]);
    if (!Number.isFinite(x) || !Number.isFinite(re) || !Number.isFinite(im))
      throw new Error(`Riga ${i + 1}: valori non interi`);
    samples.push({ x, re, im });
  }

  if (!axisMode) throw new Error('axis_mode mancante');
  if (samples.length === 0) throw new Error('Nessun campione trovato');

  samples.sort((a, b) => a.x - b.x);
  return { axisMode, samples };
}

/**
 * Serialize samples to a SRAW-1 text string.
 * samples: array of { x, re, im }
 * axisMode: 'symmetric' | 'positive'
 * comment: optional string appended as # lines after header
 */
function serializeSraw(samples, axisMode, comment) {
  const lines = ['SRAW-1'];
  if (comment) comment.trim().split('\n').forEach(l => lines.push('# ' + l));
  lines.push(`axis_mode,${axisMode}`);
  lines.push('data');
  for (const s of samples) lines.push(`${s.x},${s.re},${s.im}`);
  return lines.join('\n') + '\n';
}

/**
 * Clamp an integer amplitude value to the valid SRAW range.
 * Requires global C.
 */
function clampAmp(v) {
  if (v < -C.AMP_INT_MAX) return -C.AMP_INT_MAX;
  if (v >  C.AMP_INT_MAX) return  C.AMP_INT_MAX;
  return v;
}

// ─────────────────────────────────────────────────────────────
// Binary search helpers
// ─────────────────────────────────────────────────────────────

function lowerBoundX(samples, xq) {
  let lo = 0, hi = samples.length;
  while (lo < hi) {
    const mid = (lo + hi) >> 1;
    if (samples[mid].x < xq) lo = mid + 1; else hi = mid;
  }
  return lo;
}

function upperBoundX(samples, xq) {
  let lo = 0, hi = samples.length;
  while (lo < hi) {
    const mid = (lo + hi) >> 1;
    if (samples[mid].x <= xq) lo = mid + 1; else hi = mid;
  }
  return lo;
}

// ─────────────────────────────────────────────────────────────
// Interpolation
// ─────────────────────────────────────────────────────────────

function interpolate(samples, xq) {
  if (xq <= samples[0].x) return { re: samples[0].re, im: samples[0].im };
  const last = samples[samples.length - 1];
  if (xq >= last.x) return { re: last.re, im: last.im };

  const hi = lowerBoundX(samples, xq);
  if (hi <= 0) return { re: samples[0].re, im: samples[0].im };
  if (hi >= samples.length) return { re: last.re, im: last.im };

  const s1 = samples[hi];
  const s0 = samples[hi - 1];
  if (s1.x === xq) return { re: s1.re, im: s1.im };
  if (s0.x === s1.x) return { re: s1.re, im: s1.im };

  const t = (xq - s0.x) / (s1.x - s0.x);
  return {
    re: s0.re + t * (s1.re - s0.re),
    im: s0.im + t * (s1.im - s0.im),
  };
}

function nearestSample(samples, xq) {
  if (xq <= samples[0].x) return samples[0];
  const last = samples[samples.length - 1];
  if (xq >= last.x) return last;
  const hi = lowerBoundX(samples, xq);
  const s0 = samples[hi - 1];
  const s1 = samples[hi];
  return (xq - s0.x) <= (s1.x - xq) ? s0 : s1;
}

// ─────────────────────────────────────────────────────────────
// Coordinate transforms  (use globals: cv, vp)
// ─────────────────────────────────────────────────────────────

function worldToCanvas(wx, wy) {
  const W = cv.width, H = cv.height;
  return [
    (wx - vp.xMin) / (vp.xMax - vp.xMin) * W,
    H - (wy - vp.yMin) / (vp.yMax - vp.yMin) * H,
  ];
}

function canvasToWorld(cx, cy) {
  const W = cv.width, H = cv.height;
  return [
    vp.xMin + cx / W * (vp.xMax - vp.xMin),
    vp.yMin + (1 - cy / H) * (vp.yMax - vp.yMin),
  ];
}

/** Convert a mouse event to canvas-space pixels (handles DPR scaling). */
function eventToCanvasPx(e) {
  const rect = cv.getBoundingClientRect();
  return [
    (e.clientX - rect.left) * (cv.width  / rect.width),
    (e.clientY - rect.top)  * (cv.height / rect.height),
  ];
}

// ─────────────────────────────────────────────────────────────
// Formatting
// ─────────────────────────────────────────────────────────────

function niceStep(range, targetTicks = 8) {
  if (range <= 0 || !Number.isFinite(range)) return 1;
  const raw = range / targetTicks;
  const mag = Math.pow(10, Math.floor(Math.log10(raw)));
  const frac = raw / mag;
  let nice;
  if (frac < 1.5) nice = 1;
  else if (frac < 3.5) nice = 2;
  else if (frac < 7.5) nice = 5;
  else nice = 10;
  return nice * mag;
}

function fmtNum(v, digits = 4) {
  if (!Number.isFinite(v)) return '—';
  if (v === 0) return '0';
  if (Math.abs(v) >= 1e4 || (Math.abs(v) < 1e-3 && v !== 0))
    return v.toExponential(2);
  return parseFloat(v.toPrecision(digits)).toString();
}

// ─────────────────────────────────────────────────────────────
// Sample iteration helper
// ─────────────────────────────────────────────────────────────

function forEachVisibleSample(samples, xMin, xMax, callback) {
  const start = Math.max(0, lowerBoundX(samples, xMin) - 1);
  const end   = Math.min(samples.length, upperBoundX(samples, xMax) + 1);
  for (let i = start; i < end; i++) callback(samples[i], i);
  return { start, end, count: end - start };
}

// ─────────────────────────────────────────────────────────────
// Out-of-range shading  (use globals: ctx, cv, vp, C)
// axisMode: 'symmetric' | 'positive'
// yHardMin/Max: hard limits in world-space Y (integers for SRAW, or ±π for phase)
// ─────────────────────────────────────────────────────────────

function drawOutOfRangeZones(W, H, axisMode, yHardMin, yHardMax) {
  const X_HARD_MIN = axisMode === 'symmetric' ? -(C.MAX_SAMPLES / 2) : 0;
  const X_HARD_MAX = axisMode === 'symmetric' ?  (C.MAX_SAMPLES / 2) - 1 : C.MAX_SAMPLES - 1;
  const greyColor  = 'rgba(30,36,50,0.72)';

  function fillLeft(xBound) {
    const [cx] = worldToCanvas(xBound, 0);
    const w = Math.max(0, Math.min(W, cx));
    if (w > 0) { ctx.fillStyle = greyColor; ctx.fillRect(0, 0, w, H); }
  }
  function fillRight(xBound) {
    const [cx] = worldToCanvas(xBound, 0);
    const x = Math.max(0, Math.min(W, cx));
    if (x < W) { ctx.fillStyle = greyColor; ctx.fillRect(x, 0, W - x, H); }
  }
  function fillBottom(yBound) {
    const [, cy] = worldToCanvas(0, yBound);
    const y = Math.max(0, Math.min(H, cy));
    if (y < H) { ctx.fillStyle = greyColor; ctx.fillRect(0, y, W, H - y); }
  }
  function fillTop(yBound) {
    const [, cy] = worldToCanvas(0, yBound);
    const h = Math.max(0, Math.min(H, cy));
    if (h > 0) { ctx.fillStyle = greyColor; ctx.fillRect(0, 0, W, h); }
  }

  if (vp.xMin < X_HARD_MIN) fillLeft(X_HARD_MIN);
  if (vp.xMax > X_HARD_MAX) fillRight(X_HARD_MAX);
  if (yHardMin != null && vp.yMin < yHardMin) fillBottom(yHardMin);
  if (yHardMax != null && vp.yMax > yHardMax) fillTop(yHardMax);

  // Dashed boundary lines
  ctx.setLineDash([4, 6]);
  ctx.strokeStyle = 'rgba(80,100,130,0.5)';
  ctx.lineWidth = 1;

  for (const xi of [X_HARD_MIN, X_HARD_MAX]) {
    if (xi >= vp.xMin && xi <= vp.xMax) {
      const [cx] = worldToCanvas(xi, 0);
      ctx.beginPath(); ctx.moveTo(cx, 0); ctx.lineTo(cx, H); ctx.stroke();
    }
  }
  if (yHardMin != null && yHardMin >= vp.yMin && yHardMin <= vp.yMax) {
    const [, cy] = worldToCanvas(0, yHardMin);
    ctx.beginPath(); ctx.moveTo(0, cy); ctx.lineTo(W, cy); ctx.stroke();
  }
  if (yHardMax != null && yHardMax >= vp.yMin && yHardMax <= vp.yMax) {
    const [, cy] = worldToCanvas(0, yHardMax);
    ctx.beginPath(); ctx.moveTo(0, cy); ctx.lineTo(W, cy); ctx.stroke();
  }

  ctx.setLineDash([]);
}

// ─────────────────────────────────────────────────────────────
// Grid and axes  (use globals: ctx, cv, vp)
// xLabelFn(indexVal) -> string   e.g. "1.25s" or "50Hz"
// yLabelFn(indexVal) -> string   e.g. "3.14V" or "1.57rad"
// ─────────────────────────────────────────────────────────────

function drawGridAndAxes(W, H, xLabelFn, yLabelFn) {
  const xStep  = niceStep(vp.xMax - vp.xMin);
  const yStep  = niceStep(vp.yMax - vp.yMin);
  const xStart = Math.ceil(vp.xMin / xStep) * xStep;
  const yStart = Math.ceil(vp.yMin / yStep) * yStep;
  const fontSize = 9 * devicePixelRatio;

  ctx.strokeStyle = '#12171f';
  ctx.lineWidth   = 1;
  ctx.font        = `${fontSize}px 'Share Tech Mono', monospace`;
  ctx.fillStyle   = '#3a4050';
  ctx.textAlign   = 'center';

  for (let x = xStart; x <= vp.xMax; x += xStep) {
    const [cx] = worldToCanvas(x, 0);
    ctx.beginPath(); ctx.moveTo(cx, 0); ctx.lineTo(cx, H); ctx.stroke();
    ctx.fillText(xLabelFn(x), cx, H - 4);
  }

  ctx.textAlign = 'right';
  for (let y = yStart; y <= vp.yMax; y += yStep) {
    const [, cy] = worldToCanvas(0, y);
    ctx.beginPath(); ctx.moveTo(0, cy); ctx.lineTo(W, cy); ctx.stroke();
    ctx.fillText(yLabelFn(y), 52 * devicePixelRatio, cy - 3);
  }

  // Axis lines
  ctx.strokeStyle = '#2a3040';
  ctx.lineWidth   = 1.5;
  const [ax0, ay0] = worldToCanvas(vp.xMin, 0);
  const [ax1]      = worldToCanvas(vp.xMax, 0);
  const [axY]      = worldToCanvas(0, 0);
  ctx.beginPath(); ctx.moveTo(ax0, ay0); ctx.lineTo(ax1, ay0); ctx.stroke();
  ctx.beginPath(); ctx.moveTo(axY, 0);   ctx.lineTo(axY, H);   ctx.stroke();
}

// ─────────────────────────────────────────────────────────────
// Signal rendering
// valueFromSampleFn(sample) -> number  (the Y world value to plot)
// signalColor: CSS color string
// ─────────────────────────────────────────────────────────────

function drawSignal(samples, W, H, valueFromSampleFn, getYFn, signalColor, isPhaseMode) {
  const visible = forEachVisibleSample(samples, vp.xMin, vp.xMax, () => {});
  const visibleCount = visible.count;
  const samplesPerPixel = visibleCount / Math.max(1, W);

  if (visibleCount <= 0) return 'vuoto';

  if (samplesPerPixel <= 1.25 && visibleCount <= 20000) {
    _drawPolyline(samples, visible.start, visible.end, valueFromSampleFn, getYFn, signalColor);
    return `polyline, vis=${visibleCount}`;
  } else {
    _drawEnvelope(samples, W, valueFromSampleFn, getYFn, signalColor, isPhaseMode);
    return `envelope, vis=${visibleCount}, spp=${samplesPerPixel.toFixed(2)}`;
  }
}

function _drawPolyline(samples, start, end, valueFromSampleFn, getYFn, color) {
  ctx.beginPath();
  ctx.strokeStyle = color;
  ctx.lineWidth   = 1.5 * devicePixelRatio;
  ctx.shadowColor = color;
  ctx.shadowBlur  = 4;

  const yLeft = getYFn(samples, vp.xMin);
  let [cx, cy] = worldToCanvas(vp.xMin, yLeft);
  ctx.moveTo(cx, cy);

  for (let i = start; i < end; i++) {
    const s = samples[i];
    if (s.x < vp.xMin || s.x > vp.xMax) continue;
    [cx, cy] = worldToCanvas(s.x, valueFromSampleFn(s));
    ctx.lineTo(cx, cy);
  }

  const yRight = getYFn(samples, vp.xMax);
  [cx, cy] = worldToCanvas(vp.xMax, yRight);
  ctx.lineTo(cx, cy);
  ctx.stroke();
  ctx.shadowBlur = 0;
}

function _drawEnvelope(samples, W, valueFromSampleFn, getYFn, color, isPhaseMode) {
  ctx.strokeStyle = color;
  ctx.lineWidth   = Math.max(1, devicePixelRatio);
  ctx.shadowColor = color;
  ctx.shadowBlur  = 2;

  let prevBand = null;
  const yWorldPerPixel = (vp.yMax - vp.yMin) / Math.max(1, cv.height);

  for (let px = 0; px < W; px++) {
    const [wx0] = canvasToWorld(px,     0);
    const [wx1] = canvasToWorld(px + 1, 0);
    const x0 = Math.min(wx0, wx1);
    const x1 = Math.max(wx0, wx1);

    let i0 = lowerBoundX(samples, x0);
    let i1 = upperBoundX(samples, x1);
    if (i0 > 0) i0--;
    if (i1 < samples.length) i1++;

    // Phase: interpolating re/im through zero produces spurious atan2 sweeps.
    // Plot a single point per pixel using the nearest-knot value instead.
    if (isPhaseMode) {
      const xMid = 0.5 * (x0 + x1);
      const yMid = getYFn(samples, xMid);
      if (!Number.isFinite(yMid)) continue;
      const [cxM, cyM] = worldToCanvas(xMid, yMid);
      ctx.beginPath();
      ctx.arc(cxM, cyM, 0.75 * devicePixelRatio, 0, Math.PI * 2);
      ctx.fillStyle = color;
      ctx.fill();
      continue;
    }

    let yMin = Infinity, yMax = -Infinity;
    const yA = getYFn(samples, x0);
    const yB = getYFn(samples, x1);
    if (yA < yMin) yMin = yA; if (yA > yMax) yMax = yA;
    if (yB < yMin) yMin = yB; if (yB > yMax) yMax = yB;
    for (let i = i0; i < i1; i++) {
      const s = samples[i];
      if (s.x < x0 || s.x > x1) continue;
      const y = valueFromSampleFn(s);
      if (y < yMin) yMin = y; if (y > yMax) yMax = y;
    }
    if (!Number.isFinite(yMin) || !Number.isFinite(yMax)) continue;

    const [, cyMin] = worldToCanvas(0, yMin);
    const [, cyMax] = worldToCanvas(0, yMax);
    ctx.beginPath(); ctx.moveTo(px, cyMin); ctx.lineTo(px, cyMax); ctx.stroke();

    const band = { px, yMin, yMax, yMid: 0.5 * (yMin + yMax) };
    if (prevBand) {
      const overlapMin = Math.max(prevBand.yMin, band.yMin);
      const overlapMax = Math.min(prevBand.yMax, band.yMax);
      let bridgeY = null;
      if (overlapMin <= overlapMax) {
        bridgeY = 0.5 * (overlapMin + overlapMax);
      } else if (Math.abs(prevBand.yMid - band.yMid) <= yWorldPerPixel) {
        bridgeY = 0.5 * (prevBand.yMid + band.yMid);
      }
      if (bridgeY !== null) {
        const [, cyB] = worldToCanvas(0, bridgeY);
        ctx.beginPath(); ctx.moveTo(prevBand.px, cyB); ctx.lineTo(band.px, cyB); ctx.stroke();
      }
    }
    prevBand = band;
  }
  ctx.shadowBlur = 0;
}

function drawKnots(samples, W, H, valueFromSampleFn, color) {
  const visible = forEachVisibleSample(samples, vp.xMin, vp.xMax, () => {});
  if (visible.count <= 0 || visible.count > 400) return;
  if (W / visible.count <= 6 * devicePixelRatio) return;

  ctx.fillStyle = color;
  for (let i = visible.start; i < visible.end; i++) {
    const s = samples[i];
    if (s.x < vp.xMin || s.x > vp.xMax) continue;
    const [cx, cy] = worldToCanvas(s.x, valueFromSampleFn(s));
    ctx.beginPath();
    ctx.arc(cx, cy, 2.5 * devicePixelRatio, 0, Math.PI * 2);
    ctx.fill();
  }
}

// ─────────────────────────────────────────────────────────────
// Pan + zoom event binding
// opts.getZoomMode()  -> 'xy' | 'x' | 'y'
// opts.onUpdate()     -> called after any pan/zoom to trigger redraw
// opts.onMouseMove(wx, wy)  -> called with world coords on hover
// ─────────────────────────────────────────────────────────────

function bindPanZoom(opts) {
  const getMode  = opts.getZoomMode  || (() => 'xy');
  const onUpdate = opts.onUpdate     || (() => {});
  const onMove   = opts.onMouseMove  || (() => {});
  const factor   = opts.zoomFactor   || 1.15;

  cv.addEventListener('mousedown', e => {
    const [cx, cy] = eventToCanvasPx(e);
    pan.active  = true;
    pan.startX  = cx;
    pan.startY  = cy;
    pan.vpStart = { ...vp };
    cv.style.cursor = 'grabbing';
  });

  cv.addEventListener('mousemove', e => {
    const [cx, cy] = eventToCanvasPx(e);
    if (pan.active) {
      const dx = cx - pan.startX;
      const dy = cy - pan.startY;
      const xRange = pan.vpStart.xMax - pan.vpStart.xMin;
      const yRange = pan.vpStart.yMax - pan.vpStart.yMin;
      vp.xMin = pan.vpStart.xMin - dx / cv.width  * xRange;
      vp.xMax = pan.vpStart.xMax - dx / cv.width  * xRange;
      vp.yMin = pan.vpStart.yMin + dy / cv.height * yRange;
      vp.yMax = pan.vpStart.yMax + dy / cv.height * yRange;
      onUpdate();
    } else {
      const [wx, wy] = canvasToWorld(cx, cy);
      onMove(wx, wy);
    }
  });

  cv.addEventListener('mouseup',    () => { pan.active = false; cv.style.cursor = 'crosshair'; });
  cv.addEventListener('mouseleave', () => { pan.active = false; cv.style.cursor = 'crosshair'; });

  cv.addEventListener('wheel', e => {
    e.preventDefault();
    const [cx, cy] = eventToCanvasPx(e);
    const [wx, wy] = canvasToWorld(cx, cy);
    const f    = e.deltaY < 0 ? 1 / factor : factor;
    const mode = getMode();
    if (mode === 'xy' || mode === 'x') {
      vp.xMin = wx + (vp.xMin - wx) * f;
      vp.xMax = wx + (vp.xMax - wx) * f;
    }
    if (mode === 'xy' || mode === 'y') {
      vp.yMin = wy + (vp.yMin - wy) * f;
      vp.yMax = wy + (vp.yMax - wy) * f;
    }
    onUpdate();
  }, { passive: false });

  cv.style.cursor = 'crosshair';
}

// ─────────────────────────────────────────────────────────────
// SRAW clipping utility
// Clips samples to the valid x range for the given axisMode,
// and clamps amplitudes to ±AMP_INT_MAX. Silent (no errors).
// Returns a new samples array.
// ─────────────────────────────────────────────────────────────

function clipSrawSamples(samples, axisMode) {
  const xMin = axisMode === 'symmetric' ? -(C.MAX_SAMPLES / 2) : 0;
  const xMax = axisMode === 'symmetric' ?  (C.MAX_SAMPLES / 2) - 1 : C.MAX_SAMPLES - 1;
  return samples
    .filter(s => s.x >= xMin && s.x <= xMax)
    .map(s => ({
      x:  s.x,
      re: Math.max(-C.AMP_INT_MAX, Math.min(C.AMP_INT_MAX, s.re)),
      im: Math.max(-C.AMP_INT_MAX, Math.min(C.AMP_INT_MAX, s.im)),
    }));
}

// ─────────────────────────────────────────────────────────────
// SrawFileBrowser — Modal file browser backed by filemgr_json
//
// Usage:
//   const fb = new SrawFileBrowser({ phpRoot: '../' });
//
//   // Open to pick a .sraw to LOAD:
//   fb.open({ mode: 'load', filter: 'sraw', onPick: relPath => { ... } });
//
//   // Open to pick a destination and SAVE a .sraw:
//   fb.open({ mode: 'save', filter: 'sraw', defaultName: 'signal.sraw',
//              onSave: (relPath, content) => { ... } });
//
// phpRoot: path from the HTML file to the www/ root (e.g. '../')
// ─────────────────────────────────────────────────────────────

class SrawFileBrowser {
  constructor({ phpRoot = '../' } = {}) {
    this._root = phpRoot.replace(/\/?$/, '/');
    this._modal = null;
    this._opts = null;
    this._files = [];
    this._dirs  = [];
    this._currentDir = '';
    this._build();
  }

  // ── public ──────────────────────────────────────────────────

  open(opts) {
    // opts: { mode:'load'|'save', filter:'sraw'|'mp3'|'all',
    //         onPick, onSave, defaultName, content }
    this._opts = opts;
    this._currentDir = '';
    this._modal.style.display = 'flex';
    this._refresh();
  }

  close() {
    this._modal.style.display = 'none';
  }

  // ── build DOM ────────────────────────────────────────────────

  _build() {
    const m = document.createElement('div');
    m.id = 'sraw-fb-modal';
    m.style.cssText = [
      'display:none;position:fixed;inset:0;z-index:9999',
      'background:rgba(7,9,12,0.82)',
      'align-items:center;justify-content:center',
      'font-family:\'Share Tech Mono\',monospace',
    ].join(';');

    m.innerHTML = `
<div id="sraw-fb-box" style="
  background:#0d1016;border:1px solid #1a2030;
  width:min(560px,92vw);max-height:80vh;
  display:flex;flex-direction:column;overflow:hidden">

  <div id="sraw-fb-header" style="
    display:flex;align-items:center;gap:12px;
    padding:12px 16px;border-bottom:1px solid #1a2030;flex-shrink:0">
    <span id="sraw-fb-title" style="color:#00e5ff;font-size:.82rem;letter-spacing:.12em;flex:1"></span>
    <button id="sraw-fb-close" style="
      background:transparent;border:1px solid #1a2030;color:#4a5568;
      font-family:inherit;font-size:.75rem;padding:4px 10px;cursor:pointer">✕</button>
  </div>

  <div id="sraw-fb-breadcrumb" style="
    padding:8px 16px;border-bottom:1px solid #1a2030;
    font-size:.72rem;color:#4a5568;flex-shrink:0"></div>

  <div id="sraw-fb-list" style="flex:1;overflow-y:auto;padding:8px 0"></div>

  <div id="sraw-fb-footer" style="
    padding:12px 16px;border-top:1px solid #1a2030;flex-shrink:0;display:none">
    <div style="font-size:.7rem;color:#4a5568;letter-spacing:.08em;
                text-transform:uppercase;margin-bottom:6px">Nome file</div>
    <div style="display:flex;gap:8px">
      <input id="sraw-fb-name" type="text" style="
        flex:1;background:#07090c;border:1px solid #1a2030;
        color:#b8c4d8;font-family:inherit;font-size:.8rem;
        padding:6px 8px;outline:none"/>
      <button id="sraw-fb-save-btn" style="
        background:transparent;border:1px solid #00ff88;color:#00ff88;
        font-family:inherit;font-size:.8rem;letter-spacing:.1em;
        padding:6px 14px;cursor:pointer;text-transform:uppercase">Salva</button>
    </div>
    <div id="sraw-fb-savemsg" style="margin-top:6px;font-size:.72rem;min-height:1em"></div>
  </div>

  <div id="sraw-fb-status" style="
    padding:8px 16px;font-size:.72rem;color:#4a5568;flex-shrink:0;
    border-top:1px solid #1a2030;min-height:2em"></div>
</div>`;

    document.body.appendChild(m);
    this._modal = m;

    m.querySelector('#sraw-fb-close').addEventListener('click', () => this.close());
    m.addEventListener('click', e => { if (e.target === m) this.close(); });

    m.querySelector('#sraw-fb-save-btn').addEventListener('click', () => this._doSave());

    m.querySelector('#sraw-fb-name').addEventListener('keydown', e => {
      if (e.key === 'Enter') this._doSave();
    });
  }

  // ── data fetch ───────────────────────────────────────────────

  async _refresh() {
    this._setStatus('Caricamento…');
    try {
      const res = await fetch(this._root + 'index.php?action=filemgr_json');
      if (!res.ok) throw new Error(`HTTP ${res.status}`);
      const data = await res.json();
      if (!data.ok) throw new Error(data.error || 'Errore server');
      const filter = this._opts.filter || 'sraw';
      this._files = filter === 'mp3' ? data.mp3
                  : filter === 'all' ? [...data.sraw, ...data.mp3]
                  : data.sraw;
      this._dirs = data.dirs;
      this._setStatus('');
      this._render();
    } catch (e) {
      this._setStatus('⚠ ' + e.message);
      this._renderEmpty('Impossibile contattare il server PHP.\nL\'apertura locale funziona solo tramite drag &amp; drop.');
    }
  }

  // ── render ───────────────────────────────────────────────────

  _render() {
    const opts = this._opts;
    const isSave = opts.mode === 'save';

    this._modal.querySelector('#sraw-fb-title').textContent =
      isSave ? 'SALVA IN /DATA' : 'APRI DA /DATA';

    // Breadcrumb
    const bc = this._modal.querySelector('#sraw-fb-breadcrumb');
    const crumbs = [{ label: '/', dir: '' }];
    if (this._currentDir) {
      let acc = '';
      for (const seg of this._currentDir.split('/')) {
        acc = acc ? acc + '/' + seg : seg;
        crumbs.push({ label: seg, dir: acc });
      }
    }
    bc.innerHTML = crumbs.map((c, i) =>
      i < crumbs.length - 1
        ? `<span style="cursor:pointer;color:#00e5ff" data-dir="${c.dir}">${c.label}</span> / `
        : `<span style="color:#b8c4d8">${c.label}</span>`
    ).join('');
    bc.querySelectorAll('[data-dir]').forEach(el =>
      el.addEventListener('click', () => { this._currentDir = el.dataset.dir; this._render(); }));

    // Footer (save mode)
    const footer = this._modal.querySelector('#sraw-fb-footer');
    footer.style.display = isSave ? 'block' : 'none';
    if (isSave) {
      const nameEl = this._modal.querySelector('#sraw-fb-name');
      if (!nameEl.dataset.touched) {
        nameEl.value = (this._currentDir ? this._currentDir + '/' : '') + (opts.defaultName || 'signal.sraw');
      }
      this._modal.querySelector('#sraw-fb-savemsg').textContent = '';
    }

    // Subdirs in current dir
    const childDirs = this._dirs.filter(d => {
      if (d === '') return false;
      const parent = d.includes('/') ? d.slice(0, d.lastIndexOf('/')) : '';
      return parent === this._currentDir;
    });

    // Files in current dir
    const filesHere = this._files.filter(f => {
      const parent = f.includes('/') ? f.slice(0, f.lastIndexOf('/')) : '';
      return parent === this._currentDir;
    });

    const list = this._modal.querySelector('#sraw-fb-list');
    list.innerHTML = '';

    if (childDirs.length === 0 && filesHere.length === 0) {
      list.innerHTML = '<div style="padding:16px 20px;color:#4a5568;font-size:.78rem">— cartella vuota —</div>';
    }

    for (const d of childDirs) {
      const name = d.includes('/') ? d.slice(d.lastIndexOf('/') + 1) : d;
      const row = this._makeRow('📁 ' + name, false, () => {
        this._currentDir = d;
        if (isSave) {
          const nameEl = this._modal.querySelector('#sraw-fb-name');
          const base = nameEl.value.split('/').pop();
          nameEl.value = d + '/' + base;
          nameEl.dataset.touched = '1';
        }
        this._render();
      });
      list.appendChild(row);
    }

    for (const f of filesHere) {
      const base = f.includes('/') ? f.slice(f.lastIndexOf('/') + 1) : f;
      const row = this._makeRow(base, true, () => {
        if (isSave) {
          const nameEl = this._modal.querySelector('#sraw-fb-name');
          nameEl.value = f;
          nameEl.dataset.touched = '1';
        } else {
          this._loadFile(f);
        }
      });
      list.appendChild(row);
    }
  }

  _renderEmpty(msg) {
    const list = this._modal.querySelector('#sraw-fb-list');
    list.innerHTML = `<div style="padding:16px 20px;color:#4a5568;font-size:.78rem">${msg}</div>`;
  }

  _makeRow(label, isFile, onClick) {
    const row = document.createElement('div');
    row.style.cssText = [
      'display:flex;align-items:center;padding:8px 20px',
      'font-size:.8rem;cursor:pointer;color:' + (isFile ? '#b8c4d8' : '#00e5ff'),
      'border-bottom:1px solid #0d1016',
    ].join(';');
    row.textContent = label;
    row.addEventListener('mouseenter', () => { row.style.background = '#12171f'; });
    row.addEventListener('mouseleave', () => { row.style.background = ''; });
    row.addEventListener('click', onClick);
    return row;
  }

  // ── load ─────────────────────────────────────────────────────

  async _loadFile(relPath) {
    this._setStatus('Caricamento ' + relPath + '…');
    try {
      const url = this._root + 'data/' + relPath.split('/').map(encodeURIComponent).join('/');
      const res = await fetch(url);
      if (!res.ok) throw new Error(`HTTP ${res.status}`);
      const text = await res.text();
      this.close();
      if (this._opts.onPick) this._opts.onPick(relPath, text);
    } catch (e) {
      this._setStatus('⚠ ' + e.message);
    }
  }

  // ── save ─────────────────────────────────────────────────────

  async _doSave() {
    const nameEl = this._modal.querySelector('#sraw-fb-name');
    const msgEl  = this._modal.querySelector('#sraw-fb-savemsg');
    const relPath = nameEl.value.trim();
    if (!relPath) { msgEl.textContent = 'Inserisci un nome file.'; msgEl.style.color = '#ff4060'; return; }

    const content = (this._opts.content instanceof Function)
      ? this._opts.content()
      : (this._opts.content || '');

    if (!content) { msgEl.textContent = 'Nessun contenuto da salvare.'; msgEl.style.color = '#ff4060'; return; }

    msgEl.textContent = 'Salvataggio…'; msgEl.style.color = '#4a5568';

    try {
      const fd = new FormData();
      fd.append('tool', 'save_sraw');
      fd.append('output_sraw', relPath);
      fd.append('content', content);
      const res = await fetch(this._root + 'index.php?action=save_sraw', { method: 'POST', body: fd });
      const body = await res.text();
      if (res.ok && body.includes('__SRAW_SAVE_OK__')) {
        msgEl.textContent = '✓ Salvato: ' + relPath;
        msgEl.style.color = '#00ff88';
        nameEl.dataset.touched = '';
        if (this._opts.onSave) this._opts.onSave(relPath);
      } else {
        throw new Error(body.replace('__SRAW_SAVE_ERR__', '').trim() || 'Errore server');
      }
    } catch (e) {
      msgEl.textContent = '⚠ ' + e.message;
      msgEl.style.color = '#ff4060';
    }
  }

  // ── helpers ──────────────────────────────────────────────────

  _setStatus(msg) {
    this._modal.querySelector('#sraw-fb-status').textContent = msg;
  }
}
