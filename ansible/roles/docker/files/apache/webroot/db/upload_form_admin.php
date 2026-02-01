<?php declare(strict_types=1);
// Admin upload form with advanced fields visible. Protect this path with Basic Auth for admins only.
?>
<!DOCTYPE html>
<html lang="en">
<head>
  <meta charset="utf-8" />
  <meta name="viewport" content="width=device-width, initial-scale=1" />
  <title>StormPigs Upload (Admin)</title>
  <style>
    body { font-family: system-ui, Arial, sans-serif; margin: 2rem; padding-bottom: 84px; }
    form { max-width: 720px; }
    label { display: block; margin-top: 12px; font-weight: 600; }
    input[type="text"], input[type="date"], input[type="number"], textarea, select { width: 100%; padding: 8px; box-sizing: border-box; }
    input[type="file"] { margin-top: 8px; }
    button { margin-top: 16px; padding: 10px 16px; font-weight: 700; cursor: pointer; }
    .hint { color: #666; font-size: 12px; }
    .legend { color: #666; font-size: 12px; margin-top: 8px; }
    .row { display: flex; align-items: center; gap: 8px; }
    .row label.inline { display: inline; font-weight: 400; margin-top: 0; }
    /* simple in-flight indicator (no XHR, no percent) */
    #status {
      position: fixed;
      left: 16px;
      bottom: 16px;
      z-index: 2147483647;
      font-size: 13px;
      color: #111;
      background: rgba(255, 255, 255, 0.95);
      border: 1px solid #ddd;
      border-radius: 8px;
      padding: 10px 12px;
      box-shadow: 0 6px 18px rgba(0,0,0,0.10);
      max-width: min(900px, calc(100vw - 32px));
      overflow: hidden;
      text-overflow: ellipsis;
      white-space: nowrap;
    }
    .spinner { display:inline-block; width:14px; height:14px; border:2px solid #999; border-top-color: transparent; border-radius:50%; animation: spin 0.8s linear infinite; margin-left:8px; }
    @keyframes spin { to { transform: rotate(360deg); } }
  </style>
  <script src="https://cdn.jsdelivr.net/npm/tus-js-client@4.1.0/dist/tus.min.js"></script>
</head>
<body>
  <?php
  $audioExtsJson = getenv('UPLOAD_AUDIO_EXTS_JSON') ?: '[]';
  $videoExtsJson = getenv('UPLOAD_VIDEO_EXTS_JSON') ?: '[]';
  $audioExts = json_decode($audioExtsJson, true);
  $videoExts = json_decode($videoExtsJson, true);
  if (!is_array($audioExts)) $audioExts = [];
  if (!is_array($videoExts)) $videoExts = [];
  $exts = array_values(array_unique(array_filter(array_merge($audioExts, $videoExts), function($x) {
      return is_string($x) && preg_match('/^[A-Za-z0-9]+$/', $x) === 1;
  })));
  sort($exts);
  $accept = 'audio/*,video/*';
  if (!empty($exts)) {
      $accept .= ',' . implode(',', array_map(function($e) { return '.' . strtolower($e); }, $exts));
  }

  $chunkSizeBytes = (int)(getenv('TUS_CLIENT_CHUNK_SIZE_BYTES') ?: '8388608');
  if ($chunkSizeBytes <= 0) $chunkSizeBytes = 8388608;
  ?>
  <h1>Upload Media (Admin)</h1>
  <form id="uploadForm" action="/api/uploads.php" method="POST" enctype="multipart/form-data">
    <label for="file">Media file (audio/video) *</label>
    <input id="file" name="file" type="file" accept="<?= htmlspecialchars($accept, ENT_QUOTES) ?>" required />

    <label for="event_date">Event date *</label>
    <input id="event_date" name="event_date" type="date" required value="<?= htmlspecialchars(date('Y-m-d'), ENT_QUOTES) ?>" />

    <label for="org_name">Organization name *</label>
    <input id="org_name" name="org_name" type="text" value="StormPigs" />
    <div class="hint">Band name or wedding short name</div>

    <label for="event_type">Event type *</label>
    <select id="event_type" name="event_type">
      <option value="band" selected>band</option>
      <option value="wedding">wedding</option>
    </select>

    <label for="label">Label *</label>
    <input id="label" name="label" type="text" placeholder="Song title or wedding table label" required />
    <div class="row">
      <input id="auto_label" type="checkbox" />
      <label class="inline" for="auto_label">Autogenerate label?</label>
    </div>
    <div class="hint">If checked, the label will be set to "Auto YYYY-MM-DD" based on the Event date.</div>

    <hr />
    <h3>Advanced (Admin)</h3>
    <label for="participants">Participants</label>
    <input id="participants" name="participants" type="text" placeholder="Comma-separated names" />

    <label for="keywords">Keywords</label>
    <input id="keywords" name="keywords" type="text" placeholder="Comma-separated keywords" />

    <label for="location">Location</label>
    <input id="location" name="location" type="text" />

    <label for="rating">Rating</label>
    <input id="rating" name="rating" type="text" placeholder="1-5 or text" />

    <label for="notes">Notes</label>
    <textarea id="notes" name="notes" rows="3"></textarea>

    <button id="btnUpload" type="submit">Upload</button>
    <div class="legend">* = mandatory</div>
    <div id="status">Ready.</div>
  </form>
  <pre id="result" style="margin-top:16px; white-space:pre-wrap;"></pre>

  <script>
    (function() {
      const autoBox = document.getElementById('auto_label');
      const labelInput = document.getElementById('label');
      const dateInput = document.getElementById('event_date');
      const form = document.getElementById('uploadForm');
      // rolled back: no localStorage persistence or XHR

      function ymd(d) {
        const dt = new Date(d);
        if (isNaN(dt.getTime())) return '';
        const m = (dt.getMonth() + 1).toString().padStart(2, '0');
        const day = dt.getDate().toString().padStart(2, '0');
        return dt.getFullYear() + '-' + m + '-' + day;
      }

      function maybeSetAuto() {
        if (autoBox.checked) {
          const v = ymd(dateInput.value || new Date());
          if (v) labelInput.value = 'Auto ' + v;
        }
      }

      autoBox.addEventListener('change', function() {
        if (this.checked) {
          maybeSetAuto();
        }
      });
      dateInput.addEventListener('change', function() {
        if (autoBox.checked) maybeSetAuto();
      });

      form.addEventListener('submit', function(e) {
        e.preventDefault();
        const lbl = (labelInput.value || '').trim();
        if (!lbl) {
          alert('Label is required.');
          labelInput.focus();
          return;
        }

        const fileEl = document.getElementById('file');
        const file = fileEl && fileEl.files ? fileEl.files[0] : null;
        if (!file) {
          alert('Media file is required.');
          return;
        }

        const statusEl = document.getElementById('status');
        const resultEl = document.getElementById('result');
        const btn = document.getElementById('btnUpload');

        function setBusy(text) {
          if (statusEl) {
            statusEl.innerHTML = text + '<span class="spinner"></span>';
          }
          if (btn) {
            btn.disabled = true;
            btn.textContent = 'Uploading…';
          }
        }

        function clearBusy() {
          if (statusEl) statusEl.textContent = (statusEl.textContent || '').replace(/\s+$/, '');
          if (btn) {
            btn.disabled = false;
            btn.textContent = 'Upload';
          }
        }

        try {
          if (resultEl) resultEl.textContent = '';
        } catch(_) {}

        const fd = new FormData(form);
        const metadata = { filename: file.name };
        for (const [k, v] of fd.entries()) {
          if (k === 'file') continue;
          if (typeof v === 'string' && v.trim() === '') continue;
          metadata[k] = String(v);
        }

        const CHUNK_SIZE = <?= (int)$chunkSizeBytes ?>;

        function fmtBytes(n) {
          const x = Number(n || 0);
          if (!isFinite(x) || x <= 0) return '0 B';
          const units = ['B','KB','MB','GB','TB'];
          let v = x;
          let i = 0;
          while (v >= 1024 && i < units.length - 1) { v /= 1024; i++; }
          const p = i === 0 ? 0 : 1;
          return v.toFixed(p) + ' ' + units[i];
        }

        function fmtEta(seconds) {
          const s = Math.max(0, Math.floor(Number(seconds || 0)));
          if (!isFinite(s) || s <= 0) return '0s';
          const h = Math.floor(s / 3600);
          const m = Math.floor((s % 3600) / 60);
          const ss = s % 60;
          if (h > 0) return String(h) + 'h ' + String(m).padStart(2, '0') + 'm ' + String(ss).padStart(2, '0') + 's';
          if (m > 0) return String(m) + 'm ' + String(ss).padStart(2, '0') + 's';
          return String(ss) + 's';
        }

        let lastReq = '';
        let lastStatus = '';
        let chunksDone = 0;
        let uploadStartMs = Date.now();
        let lastSampleMs = uploadStartMs;
        let lastSampleBytes = 0;
        let speedBps = 0;
        let lastEtaSeconds = null;
        let finalStatusText = '';
        let finalChecksum = '';

        function buildStatusLine(pct, bytesDone, bytesTotal, chunkCount, etaSeconds, checksum) {
          const metaLine = (lastReq ? (' | ' + lastReq) : '') + (lastStatus ? (' (' + lastStatus + ')') : '');
          const etaLine = (etaSeconds !== null && isFinite(Number(etaSeconds))) ? (' | ETA ' + fmtEta(etaSeconds)) : '';
          const checksumLine = (checksum && typeof checksum === 'string') ? (' | sha256 ' + checksum) : '';
          return 'Uploading… ' + pct + '% (' + fmtBytes(bytesDone) + ' / ' + fmtBytes(bytesTotal) + ') | chunks ' + chunksDone + '/' + chunkCount + etaLine + checksumLine + metaLine;
        }

        uploadStartMs = Date.now();
        lastSampleMs = uploadStartMs;
        lastSampleBytes = 0;
        speedBps = 0;
        lastEtaSeconds = null;
        finalStatusText = '';
        finalChecksum = '';
        setBusy('Uploading… 0% (0 B / ' + fmtBytes(file.size) + ') | chunks 0/' + Math.max(1, Math.ceil(file.size / CHUNK_SIZE)) + ' | ETA --');

        const upload = new tus.Upload(file, {
          endpoint: '/files/',
          retryDelays: [0, 1000, 3000, 5000],
          chunkSize: CHUNK_SIZE,
          metadata: metadata,
          withCredentials: true,
          onBeforeRequest: function(req) {
            try {
              const u = req && req._xhr && req._xhr.responseURL ? req._xhr.responseURL : (upload.url || '');
              lastReq = (req && req._method ? req._method : '') + ' ' + (u || '');
              lastStatus = '';
            } catch(_) {}
          },
          onAfterResponse: function(req, res) {
            try {
              lastStatus = String(res && res.getStatus ? res.getStatus() : '');
            } catch(_) {}
          },
          onError: function(error) {
            clearBusy();
            alert('Upload failed: ' + (error && error.message ? error.message : error));
          },
          onProgress: function(bytesUploaded, bytesTotal) {
            const pct = bytesTotal > 0 ? Math.floor((bytesUploaded / bytesTotal) * 100) : 0;
            const now = Date.now();
            const dt = Math.max(1, now - lastSampleMs);
            const db = Math.max(0, bytesUploaded - lastSampleBytes);
            const instBps = (db * 1000) / dt;
            speedBps = speedBps > 0 ? (speedBps * 0.85 + instBps * 0.15) : instBps;
            lastSampleMs = now;
            lastSampleBytes = bytesUploaded;

            if (speedBps > 0 && bytesTotal > 0 && bytesUploaded <= bytesTotal) {
              lastEtaSeconds = (bytesTotal - bytesUploaded) / speedBps;
            }
            if (statusEl) {
              const chunkCount = bytesTotal > 0 ? Math.max(1, Math.ceil(bytesTotal / CHUNK_SIZE)) : 0;
              finalStatusText = buildStatusLine(pct, bytesUploaded, bytesTotal, chunkCount, lastEtaSeconds, finalChecksum);
              statusEl.innerHTML = finalStatusText + '<span class="spinner"></span>';
            }
          },
          onChunkComplete: function(chunkSize, bytesAccepted, bytesTotal) {
            chunksDone += 1;
            if (statusEl) {
              const pct = bytesTotal > 0 ? Math.floor((bytesAccepted / bytesTotal) * 100) : 0;
              const chunkCount = bytesTotal > 0 ? Math.max(1, Math.ceil(bytesTotal / CHUNK_SIZE)) : 0;
              finalStatusText = buildStatusLine(pct, bytesAccepted, bytesTotal, chunkCount, lastEtaSeconds, finalChecksum);
              statusEl.innerHTML = finalStatusText + '<span class="spinner"></span>';
            }
          },
          onSuccess: function() {
            const url = upload.url || '';
            const uploadId = url ? url.split('/').pop() : '';
            if (!uploadId) {
              clearBusy();
              alert('Upload succeeded, but could not determine upload_id.');
              return;
            }

            if (statusEl) {
              const prefix = (finalStatusText && typeof finalStatusText === 'string') ? finalStatusText : 'Finalizing…';
              statusEl.innerHTML = prefix.replace(/^Uploading…/, 'Finalizing…') + '<span class="spinner"></span>';
            }

            fetch('/api/uploads/finalize', {
              method: 'POST',
              headers: { 'Content-Type': 'application/json' },
              body: JSON.stringify({ upload_id: uploadId }),
              credentials: 'same-origin'
            })
              .then(async function(r) {
                const t = await r.text();
                let j = null;
                try { j = t ? JSON.parse(t) : null; } catch(_) {}
                if (!r.ok) {
                  throw new Error('Finalize failed (' + r.status + '): ' + (j ? JSON.stringify(j, null, 2) : t));
                }
                return j || t;
              })
              .then(function(payload) {
                let checksum = '';
                try {
                  if (payload && typeof payload === 'object' && payload.checksum_sha256) {
                    checksum = String(payload.checksum_sha256);
                  }
                } catch(_) {}

                if (checksum) {
                  finalChecksum = checksum;
                }

                if (statusEl) {
                  const doneText = (finalStatusText && typeof finalStatusText === 'string') ? finalStatusText : 'Uploading… 100%';
                  const withChecksum = finalChecksum ? (doneText + ' | sha256 ' + finalChecksum) : doneText;
                  statusEl.textContent = withChecksum.replace(/^Uploading…/, 'Uploading Completed.');
                }
                clearBusy();
                if (resultEl) {
                  resultEl.textContent = typeof payload === 'string' ? payload : JSON.stringify(payload, null, 2);

                  const existingLink = document.getElementById('viewFileInDatabaseLink');
                  if (existingLink && existingLink.parentNode) {
                    existingLink.parentNode.removeChild(existingLink);
                  }

                  const link = document.createElement('a');
                  link.id = 'viewFileInDatabaseLink';
                  link.href = '/db/database.php';
                  link.textContent = 'View File in Database';
                  link.style.display = 'inline-block';
                  link.style.marginTop = '10px';
                  resultEl.parentNode.insertBefore(link, resultEl.nextSibling);
                }
              })
              .catch(function(err) {
                clearBusy();
                if (statusEl) {
                  statusEl.textContent = ((finalStatusText && typeof finalStatusText === 'string') ? finalStatusText : 'Finalizing…') + ' Upload Failed.';
                }
                alert(String(err && err.message ? err.message : err));
              });
          }
        });

        upload.start();
      });
    })();
  </script>
</body>
</html>
