{{-- resources/views/video/play.blade.php --}}
<!doctype html>
<html lang="en">
<head>
  <meta charset="utf-8">
  <meta name="viewport" content="width=device-width, initial-scale=1">

  <title>Video Player</title>

  <style>
    body { margin:0; font-family: system-ui, -apple-system, Segoe UI, Roboto, Arial; background:#0b0f17; color:#fff; }
    .wrap { max-width: 1100px; margin: 0 auto; padding: 24px; }
    .card { background:#111827; border:1px solid rgba(255,255,255,.08); border-radius: 14px; overflow:hidden; }
    .top { padding: 14px 16px; display:flex; gap:12px; align-items:center; justify-content:space-between; }
    .title { font-size: 14px; opacity:.9; }
    .btn { background:#0ea5e9; color:#001018; border:0; padding:8px 12px; border-radius:10px; cursor:pointer; font-weight:600; }
    .btn:disabled { opacity:.5; cursor:not-allowed; }
    .player { position: relative; background:#000; }
    video { width:100%; height:auto; display:block; background:#000; }
    .meta { padding: 14px 16px; font-size: 13px; opacity:.85; display:grid; gap:6px; }
    .row { display:flex; flex-wrap:wrap; gap:8px; }
    .pill { font-size:12px; padding:4px 10px; border-radius:999px; background:rgba(255,255,255,.07); border:1px solid rgba(255,255,255,.08); }
    .err { background: rgba(239,68,68,.12); border:1px solid rgba(239,68,68,.35); color:#fecaca; padding:10px 12px; border-radius:10px; display:none; }
    .thumb { width:100%; display:block; }
  </style>
</head>
<body>
@php
  /**
   * You can pass $master_url and $thumbnail from controller,
   * or keep these defaults for testing with your example paths.
   */
  $master_url = $master_url ?? '/storage/videos/Advanced Level 2025/8BAB1AD3B2F6DEC005CD/master.m3u8';
  $thumbnail  = $thumbnail  ?? '/storage/videos/Advanced Level 2025/8BAB1AD3B2F6DEC005CD/thumbnails/thumb.jpg';

  // Convert to full URLs
  $masterAbs = str_starts_with($master_url, 'http') ? $master_url : url($master_url);
  $thumbAbs  = str_starts_with($thumbnail, 'http') ? $thumbnail  : url($thumbnail);

  // Cache-buster to avoid old playlist caching during testing
  $masterAbsBusted = $masterAbs . (str_contains($masterAbs, '?') ? '&' : '?') . 't=' . time();
@endphp

<div class="wrap">
  <div class="card">
    <div class="top">
      <div class="title">HLS Player (Blade)</div>
      <div class="row">
        <button class="btn" id="btnPlay">Play</button>
        <button class="btn" id="btnReload">Reload</button>
      </div>
    </div>

    <div class="player">
      {{-- poster shown before play --}}
      <video
        id="video"
        controls
        playsinline
        preload="metadata"
        poster="{{ $thumbAbs }}"
      ></video>
    </div>

    <div class="meta">
      <div class="err" id="errBox"></div>

      <div class="row">
        <span class="pill" id="support"></span>
        <span class="pill" id="state">idle</span>
      </div>

      <div class="row" style="opacity:.8;">
        <div style="word-break:break-all;">
          <div><b>Master:</b> {{ $masterAbs }}</div>
          <div><b>Thumbnail:</b> {{ $thumbAbs }}</div>
        </div>
      </div>
    </div>
  </div>
</div>

{{-- hls.js from CDN --}}
<script src="https://cdn.jsdelivr.net/npm/hls.js@1"></script>

<script>
  const MASTER_URL = @json($masterAbsBusted);

  const video   = document.getElementById('video');
  const errBox  = document.getElementById('errBox');
  const support = document.getElementById('support');
  const stateEl = document.getElementById('state');

  const btnPlay   = document.getElementById('btnPlay');
  const btnReload = document.getElementById('btnReload');

  function setState(s) { stateEl.textContent = s; }
  function showError(msg) {
    errBox.style.display = 'block';
    errBox.textContent = msg;
  }
  function clearError() {
    errBox.style.display = 'none';
    errBox.textContent = '';
  }

  // Basic HLS support check:
  // - Safari: native HLS (canPlayType)
  // - Others: hls.js
  const nativeHls = video.canPlayType('application/vnd.apple.mpegurl');
  const useHlsJs = window.Hls && Hls.isSupported();

  support.textContent = nativeHls ? 'Native HLS (Safari/iOS)' : (useHlsJs ? 'hls.js supported' : 'HLS not supported');

  let hls = null;

  function destroyHls() {
    if (hls) {
      try { hls.destroy(); } catch (e) {}
      hls = null;
    }
  }

  function loadAndAttach() {
    clearError();
    setState('loading');

    destroyHls();
    video.pause();
    video.removeAttribute('src');
    video.load();

    // Prefer native HLS if available (Safari)
    if (nativeHls) {
      video.src = MASTER_URL;
      video.addEventListener('loadedmetadata', () => setState('ready'), { once: true });
      video.addEventListener('error', () => {
        const err = video.error;
        showError('Video error (native): ' + (err ? `code ${err.code}` : 'unknown'));
        setState('error');
      }, { once: true });
      return;
    }

    // Use hls.js if supported
    if (useHlsJs) {
      hls = new Hls({
        // helpful for live/seek stability
        enableWorker: true,
        lowLatencyMode: false,
        backBufferLength: 90,
        maxBufferLength: 60,
        maxMaxBufferLength: 120,
      });

      hls.on(Hls.Events.ERROR, function (event, data) {
        if (!data) return;

        // Show error
        showError(`HLS.js error: ${data.type} / ${data.details} (fatal=${data.fatal})`);
        setState('error');

        // Attempt recovery if possible
        if (data.fatal) {
          if (data.type === Hls.ErrorTypes.NETWORK_ERROR) {
            // try to restart loading
            try { hls.startLoad(); setState('recover:network'); } catch(e) {}
          } else if (data.type === Hls.ErrorTypes.MEDIA_ERROR) {
            // try to recover media error
            try { hls.recoverMediaError(); setState('recover:media'); } catch(e) {}
          } else {
            // unrecoverable
            destroyHls();
          }
        }
      });

      hls.on(Hls.Events.MANIFEST_PARSED, function () {
        setState('ready');
      });

      hls.attachMedia(video);
      hls.loadSource(MASTER_URL);
      return;
    }

    showError('This browser cannot play HLS. Try Safari or a modern Chrome/Edge/Firefox.');
    setState('unsupported');
  }

  btnPlay.addEventListener('click', async () => {
    clearError();

    // If not loaded yet, load first
    if (!video.src && !hls) {
      loadAndAttach();
      await new Promise(r => setTimeout(r, 200));
    }

    try {
      setState('playing');
      await video.play();
    } catch (e) {
      showError('Autoplay/play blocked. Click play again or allow media playback.');
      setState('blocked');
    }
  });

  btnReload.addEventListener('click', () => {
    // reload with a new cache buster
    const u = new URL(MASTER_URL, window.location.href);
    u.searchParams.set('t', Date.now().toString());
    // update MASTER_URL-like behavior by reloading page or simply re-load
    // easiest: full reload (keeps it simple)
    window.location.reload();
  });

  // Auto-load immediately
  loadAndAttach();
</script>
</body>
</html>
