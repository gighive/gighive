<?php declare(strict_types=1);
// Minimal manual UI for testing Upload API
?>
<!DOCTYPE html>
<html lang="en">
<head>
  <meta charset="utf-8" />
  <meta name="viewport" content="width=device-width, initial-scale=1" />
  <title>Upload File</title>
  <style>
    body { font-family: system-ui, Arial, sans-serif; margin: 2rem; padding-bottom: 84px; }
    form { max-width: 640px; }
    label { display: block; margin-top: 12px; font-weight: 600; }
    input[type="text"], input[type="date"], input[type="number"], textarea, select { width: 100%; padding: 8px; box-sizing: border-box; }
    input[type="file"] { margin-top: 8px; }
    button { margin-top: 16px; padding: 10px 16px; font-weight: 700; cursor: pointer; }
    .hint { color: #666; font-size: 12px; }
    .legend { color: #666; font-size: 12px; margin-top: 8px; }
    .row { display: flex; align-items: center; gap: 8px; }
    .row label.inline { display: inline; font-weight: 400; margin-top: 0; }
    .admin-link { margin-top: 16px; display: inline-block; font-size: 14px; text-decoration: underline; }
    .user-indicator { font-size: 12px; color: #666; margin-bottom: 8px; }
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
  <!-- This page is under /db/, protected by Basic Auth via Apache LocationMatch -->
</head>
<body>
  <?php
  $user = $_SERVER['PHP_AUTH_USER']
      ?? $_SERVER['REMOTE_USER']
      ?? $_SERVER['REDIRECT_REMOTE_USER']
      ?? 'Unknown';

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
  <div class="user-indicator">User is logged in as <?= htmlspecialchars($user, ENT_QUOTES) ?></div>
  <h1>Upload Media</h1>
  <form id="uploadForm" action="/api/uploads.php" method="POST" enctype="multipart/form-data">
    <label for="file">Media file (audio/video) *</label>
    <input id="file" name="file" type="file" accept="<?= htmlspecialchars($accept, ENT_QUOTES) ?>" required />

    <label for="event_date">Event date *</label>
    <input id="event_date" name="event_date" type="date" required value="<?= htmlspecialchars(date('Y-m-d'), ENT_QUOTES) ?>" />

    <label for="org_name">Band or wedding party name *</label>
    <input id="org_name" name="org_name" type="text" value="Band or Wedding Event Name" />
    <div class="hint">Band name or wedding short name</div>

    <label for="event_type">Event type *</label>
    <select id="event_type" name="event_type">
      <option value="band" selected>band</option>
      <option value="wedding">wedding</option>
    </select>

    <label for="label">Song title or wedding table / identifier *</label>
    <input id="label" name="label" type="text" placeholder="Song title or wedding table label" required />
    <div class="row">
      <input id="auto_label" type="checkbox" />
      <label class="inline" for="auto_label">Autogenerate label?</label>
    </div>
    <div class="hint">If checked, the label will be set to "Auto YYYY-MM-DD" based on the Event date.</div>

    <button id="btnUpload" type="submit">Upload</button>
    <div class="legend">* = mandatory</div>
    <a class="admin-link" href="/db/upload_form_admin.php">For Admins</a>
    <div id="status">Ready.</div>
  </form>
  <div id="myUploads" style="margin-top:16px; display:none;"></div>
  <pre id="result" style="margin-top:24px; white-space:pre-wrap;"></pre>

  <script>
    (function() {
      const autoBox = document.getElementById('auto_label');
      const labelInput = document.getElementById('label');
      const dateInput = document.getElementById('event_date');
      const form = document.getElementById('uploadForm');
      const myUploadsEl = document.getElementById('myUploads');
      const btn = document.getElementById('btnUpload');
      const statusEl = document.getElementById('status');
      const resultEl = document.getElementById('result');

      const STORAGE_KEY = 'uploader_delete_tokens_v1';

      function loadTokens() {
        try {
          const raw = window.localStorage ? window.localStorage.getItem(STORAGE_KEY) : null;
          const parsed = raw ? JSON.parse(raw) : null;
          if (parsed && typeof parsed === 'object' && !Array.isArray(parsed)) return parsed;
        } catch(_) {}
        return {};
      }

      function saveTokens(map) {
        try {
          if (!window.localStorage) return;
          window.localStorage.setItem(STORAGE_KEY, JSON.stringify(map || {}));
        } catch(_) {}
      }

      function renderMyUploads() {
        if (!myUploadsEl) return;
        const tokens = loadTokens();
        const ids = Object.keys(tokens || {}).filter(function(k) { return /^[0-9]+$/.test(String(k)); });
        ids.sort(function(a, b) { return Number(b) - Number(a); });
        myUploadsEl.style.display = 'block';
        const rows = ids.map(function(id) {
          const safeId = String(id).replace(/"/g, '');
          return '<div class="row" style="margin-top:8px;">'
            + '<div><strong>File ID</strong> ' + safeId + '</div>'
            + '<button type="button" data-file-id="' + safeId + '" style="margin-top:0; padding:6px 10px;">Delete</button>'
            + '</div>';
        }).join('');

        const body = !ids.length
          ? '<div class="hint">No uploads from this device yet.</div>'
          : ('<div class="hint">These entries exist because this browser saved a delete token at upload time.</div>' + rows);

        myUploadsEl.innerHTML = '<h2 style="margin:0 0 8px 0;">My uploads from this device</h2>' + body;

        const buttons = myUploadsEl.querySelectorAll('button[data-file-id]');
        buttons.forEach(function(b) {
          b.addEventListener('click', async function() {
            const fileId = String(this.getAttribute('data-file-id') || '');
            const cur = loadTokens();
            const token = cur && cur[fileId] ? String(cur[fileId]) : '';
            if (!fileId || !token) return;

            this.disabled = true;
            const prevText = this.textContent;
            this.textContent = 'Deleting…';

            try {
              const resp = await fetch('/db/delete_media_files.php', {
                method: 'POST',
                headers: { 'Content-Type': 'application/json' },
                body: JSON.stringify({ file_ids: [Number(fileId)], file_id: Number(fileId), delete_token: token }),
                credentials: 'same-origin'
              });
              const text = await resp.text();
              let json = null;
              try { json = text ? JSON.parse(text) : null; } catch(_) {}
              if (!resp.ok) {
                throw new Error('Delete failed (' + resp.status + '): ' + (json ? JSON.stringify(json, null, 2) : text));
              }
              delete cur[fileId];
              saveTokens(cur);
              renderMyUploads();
              if (statusEl) {
                statusEl.textContent = 'File ID ' + fileId + ' has been deleted.';
              }
              if (resultEl) {
                resultEl.textContent = json ? JSON.stringify(json, null, 2) : text;
              }
            } catch (e) {
              alert(String(e && e.message ? e.message : e));
            } finally {
              this.disabled = false;
              this.textContent = prevText;
            }
          });
        });
      }

      function checkLocalStoragePersistence() {
        try {
          if (!window.localStorage) return false;
          const k = '__gighive_ls_test__' + String(Date.now());
          window.localStorage.setItem(k, '1');
          const v = window.localStorage.getItem(k);
          window.localStorage.removeItem(k);
          return v === '1';
        } catch(_) {
          return false;
        }
      }

      // rolled back: no localStorage persistence

      const hasPersistentStorage = checkLocalStoragePersistence();
      if (!hasPersistentStorage) {
        if (btn) btn.disabled = true;
        if (statusEl) statusEl.textContent = 'Uploads disabled: this browser does not support persistent local storage (private browsing?).';
        try { form && form.addEventListener('submit', function(e) { e.preventDefault(); }); } catch(_) {}
      } else {
        renderMyUploads();
      }

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
                  if (r.status === 409) {
                    let msg = 'Duplicate Upload. A file with the same content (SHA256) already exists on the server. Upload rejected to prevent duplicates.';
                    try {
                      if (j && typeof j === 'object' && j.message) {
                        msg = String(j.message);
                      }
                    } catch(_) {}
                    throw new Error(msg);
                  }
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

                try {
                  if (payload && typeof payload === 'object' && payload.id && payload.delete_token) {
                    const tokens = loadTokens();
                    tokens[String(payload.id)] = String(payload.delete_token);
                    saveTokens(tokens);
                    renderMyUploads();
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
                  const m = String(err && err.message ? err.message : err);
                  if (/duplicate upload/i.test(m)) {
                    statusEl.textContent = 'Duplicate Upload: ' + m;
                  } else {
                    statusEl.textContent = ((finalStatusText && typeof finalStatusText === 'string') ? finalStatusText : 'Finalizing…') + ' Upload Failed.';
                  }
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
