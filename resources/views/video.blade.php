<!doctype html>
<html lang="en">
<head>
  <meta charset="utf-8" />
  <meta name="viewport" content="width=device-width, initial-scale=1" />
  <title>HLS Video Player</title>

  {{-- HLS.js --}}
  <script src="https://cdn.jsdelivr.net/npm/hls.js@latest"></script>

  <style>
    body { margin: 0; font-family: system-ui, -apple-system, Segoe UI, Roboto, Arial; background: #0b1220; color: #e5e7eb; }
    .wrap { max-width: 1000px; margin: 24px auto; padding: 0 16px; }
    .card { background: #0f172a; border: 1px solid rgba(255,255,255,.08); border-radius: 14px; overflow: hidden; }
    .top { padding: 14px 16px; border-bottom: 1px solid rgba(255,255,255,.08); display:flex; gap:12px; align-items:center; }
    .badge { font-size: 12px; opacity: .9; padding: 4px 10px; border-radius: 999px; background: rgba(255,255,255,.08); }
    video { width: 100%; height: auto; display:block; background:#000; }
    .meta { padding: 12px 16px; font-size: 13px; opacity:.9; display:flex; gap:10px; flex-wrap:wrap; }
    .meta code { background: rgba(255,255,255,.08); padding: 2px 6px; border-radius: 6px; }
  </style>
</head>
<body>
  <div class="wrap">
    <div class="card">
      <div class="top">
        <div style="font-weight:700;">HLS Player (S3)</div>
        <div class="badge" id="status">Loading…</div>
      </div>

      {{-- ✅ Poster (thumbnail) --}}
      <video id="video" controls playsinline preload="metadata"></video>

      <div class="meta">
        <div>Master: <code id="masterText"></code></div>
      </div>
    </div>
  </div>

  <script>
    // ✅ Your S3 "folder" base URL (keep ending slash)
    const BASE = "https://payout-system-s3.s3.eu-north-1.amazonaws.com/Advanced+Level+2025/8BAB1AD3B2F6DEC005CD/";

    // ✅ If your keys have spaces, S3 usually expects URL-encoding.
    // You already use + for spaces in folder name; that's OK for S3 links.
    // Build final URLs:
    const masterUrl = BASE + "master.m3u8";
    const thumbUrl  = BASE + "thumbnails/thumb.jpg"; // change if different

    const video = document.getElementById("video");
    const status = document.getElementById("status");
    document.getElementById("masterText").textContent = masterUrl;

    // poster
    video.setAttribute("poster", thumbUrl);

    function setStatus(t) { status.textContent = t; }

    // ---- HLS logic ----
    if (Hls.isSupported()) {
      const hls = new Hls({
        // Good defaults for public S3
        enableWorker: true,
        lowLatencyMode: false,
        backBufferLength: 90,
        maxBufferLength: 60,
        maxMaxBufferLength: 120,
      });

      hls.attachMedia(video);

      hls.on(Hls.Events.MEDIA_ATTACHED, () => {
        setStatus("Loading playlist…");
        hls.loadSource(masterUrl);
      });

      hls.on(Hls.Events.MANIFEST_PARSED, () => {
        setStatus("Ready");
        // video.play().catch(()=>{});
      });

      hls.on(Hls.Events.ERROR, (event, data) => {
        console.log("HLS error", data);
        if (data.fatal) {
          setStatus("Error (fatal): " + data.type);
          try { hls.destroy(); } catch (e) {}
        } else {
          setStatus("Error: " + data.type);
        }
      });
    } else if (video.canPlayType("application/vnd.apple.mpegurl")) {
      // Safari native HLS
      video.src = masterUrl;
      setStatus("Ready (native HLS)");
    } else {
      setStatus("HLS not supported in this browser");
    }
  </script>
</body>
</html>

