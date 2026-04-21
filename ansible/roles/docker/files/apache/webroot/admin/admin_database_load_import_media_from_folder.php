<?php declare(strict_types=1);
$user = $_SERVER['PHP_AUTH_USER'] ?? $_SERVER['REMOTE_USER'] ?? $_SERVER['REDIRECT_REMOTE_USER'] ?? null;
if ($user !== 'admin') { http_response_code(403); echo '<h1>Forbidden</h1>'; exit; }

$__json_env_array = function(string $key): array {
    $raw = getenv($key);
    if (!is_string($raw) || trim($raw) === '') return [];
    $decoded = json_decode($raw, true);
    if (!is_array($decoded)) return [];
    $out = [];
    foreach ($decoded as $x) { if (is_string($x) && trim($x) !== '') $out[] = strtolower(trim($x)); }
    return array_values(array_unique($out));
};
$__audio_exts = $__json_env_array('UPLOAD_AUDIO_EXTS_JSON');
$__video_exts = $__json_env_array('UPLOAD_VIDEO_EXTS_JSON');
if (!$__audio_exts) $__audio_exts = ['mp3','wav','flac','aac','ogg','m4a'];
if (!$__video_exts) $__video_exts = ['mp4','mov','mkv','avi','webm','m4v'];
?>
<!doctype html>
<html lang="en">
<head>
  <meta charset="utf-8"/>
  <meta name="viewport" content="width=device-width,initial-scale=1"/>
  <title>Admin: Database Load, Import Media</title>
  <style>
    :root { font-family: system-ui,-apple-system,Segoe UI,Roboto,sans-serif; }
    body { margin:0; background:#0b1020; color:#e9eef7; }
    .wrap { max-width:920px; margin:3rem auto; padding:1rem; }
    .card { background:#121a33; border:1px solid #1d2a55; border-radius:16px; padding:1.5rem; position:relative; }
    button { padding:.8rem 1.1rem; border-radius:10px; border:1px solid #3b82f6; background:transparent; color:#e9eef7; cursor:pointer; }
    button:hover { background:#1e40af; color:#fff; }
    button.danger { border-color:#dc2626; }
    button.danger:hover { background:#991b1b; }
    button:disabled { cursor:not-allowed; opacity:.55; }
    .section-divider { border-top:2px solid #1d2a55; margin:2rem 0; padding-top:2rem; }
    .alert-ok  { background:#11331a; border:1px solid #1f7a3b; padding:.8rem 1rem; border-radius:10px; margin-bottom:.75rem; }
    .alert-err { background:#3b0d14; border:1px solid #b4232a; padding:.8rem 1rem; border-radius:10px; margin-bottom:.75rem; }
    .muted { color:#a8b3cf; font-size:.95rem; }
    .upload-row { display:flex; align-items:center; gap:.5rem; flex-wrap:wrap; margin:.3rem 0; }
    .upload-badge { display:inline-block; min-width:90px; font-size:.82rem; font-weight:700; padding:.2rem .5rem; border-radius:6px; text-align:center; }
    .badge-pending   { background:#1d2a55; color:#a8b3cf; }
    .badge-uploading { background:#1e40af; color:#fff; }
    .badge-done      { background:#11331a; color:#22c55e; }
    .badge-present   { background:#11331a; color:#22c55e; }
    .badge-failed    { background:#3b0d14; color:#ef4444; }
    .debug-log { margin-top:.9rem; background:#0e1530; border:1px solid #33427a; border-radius:10px; padding:.75rem; max-height:320px; overflow:auto; }
    .debug-log-row { padding:.45rem 0; border-top:1px solid #1d2a55; }
    .debug-log-row:first-child { border-top:none; padding-top:0; }
    .debug-log-meta { color:#a8b3cf; font-size:.82rem; }
    .debug-log-msg { margin-top:.2rem; font-size:.9rem; word-break:break-word; }
    .debug-log-pre { margin:.35rem 0 0 0; white-space:pre-wrap; word-break:break-word; font-size:.8rem; color:#cfd8ee; }
    /* duplicate modal */
    #dupModal { display:none; position:fixed; inset:0; background:rgba(0,0,0,.7); z-index:9999; overflow:auto; }
    #dupModalBox { background:#121a33; border:1px solid #1d2a55; border-radius:14px; max-width:640px; margin:4rem auto; padding:1.5rem; }
    select.dark { width:100%; padding:.6rem; border-radius:8px; border:1px solid #33427a; background:#0e1530; color:#e9eef7; margin-top:.35rem; }
    details summary { cursor:pointer; }
  </style>
  <script src="https://cdn.jsdelivr.net/npm/tus-js-client@4.1.0/dist/tus.min.js"></script>
  <link rel="stylesheet" href="/admin/assets/import_progress.css" />
  <script src="/admin/assets/import_progress.js"></script>
</head>
<body>
<div class="wrap"><div class="card">
  <div style="position:absolute;top:1.5rem;right:1.5rem;display:flex;flex-direction:column;gap:.4rem;align-items:flex-end">
    <a href="/admin/admin.php"><button type="button" style="border-color:#3b82f6;font-size:.8rem;padding:.4rem .8rem">Password Reset</button></a>
    <a href="/admin/admin_system.php"><button type="button" style="border-color:#3b82f6;font-size:.8rem;padding:.4rem .8rem">System &amp; Recovery</button></a>
    <a href="/admin/admin_database_load_import_csv.php"><button type="button" style="border-color:#3b82f6;font-size:.8rem;padding:.4rem .8rem">CSV Import</button></a>
  </div>
  <h1 style="padding-right:210px">Admin: Database Load, Import Media</h1>
  <p class="muted">Signed in as <code><?= htmlspecialchars($user) ?></code>.</p>
  <p class="muted">Two-step browser-based import: <strong>Step 1</strong> hashes files and loads metadata into the DB. <strong>Step 2</strong> uploads the actual media files.</p>

  <!-- Section A -->
  <div class="section-divider" id="secA">
    <h2>Section A: Reload Database from Folder <span class="muted" style="font-size:.8em">(destructive)</span></h2>
    <p class="muted">Truncates and rebuilds media tables from the selected folder. Requires confirmation.</p>
    <div style="background:#3b1f0d;border:1px solid #b45309;padding:.75rem;border-radius:10px;margin-bottom:.75rem">
      <strong>Warning:</strong> All existing sessions/songs/files/musicians will be deleted.
    </div>
    <div id="a-lastjob" class="muted" style="margin-bottom:.5rem"></div>
    <label for="a-folder" class="muted" id="a-folder-label">Select a folder:</label>
    <input type="file" id="a-folder" webkitdirectory directory multiple style="display:block;margin:.5rem 0"/>
    <div id="a-preview"></div>
    <div id="a-status" style="margin:.5rem 0"></div>
    <div style="display:flex;gap:.5rem;flex-wrap:wrap;margin-top:.5rem">
      <button id="a-scan-btn"  class="danger" disabled onclick="sectionScan('a')">Scan &amp; Submit (Reload DB)</button>
      <button id="a-stop-btn"  class="danger" disabled onclick="sectionStop('a')">Stop hashing &amp; submit hashed</button>
      <button id="a-cache-btn" class="danger" disabled onclick="sectionClearCache('a')">Clear cached hashes</button>
    </div>
    <!-- Step 2 panel -->
    <div id="a-upload-panel" style="display:none;margin-top:1.25rem">
      <h3 style="margin-bottom:.5rem">Step 2: Upload Media Files</h3>
      <div id="a-upload-status" style="margin:.5rem 0"></div>
      <div style="display:flex;gap:.5rem;flex-wrap:wrap">
        <button id="a-upload-btn" onclick="sectionStartUpload('a')" style="border-color:#22c55e">Upload Media</button>
        <button id="a-upload-refresh-btn" onclick="refreshUploadDebug('a')">Refresh Upload Log</button>
        <button id="a-mark-done-btn" onclick="sectionMarkDone('a')" style="display:none;border-color:#6b7280">Mark as Done</button>
      </div>
      <div id="a-upload-debug" class="debug-log"></div>
    </div>
    <!-- Recovery -->
    <details style="margin-top:1rem">
      <summary class="muted">Previous Jobs (Recovery) <span id="a-jobs-badge"></span></summary>
      <div style="margin-top:.75rem">
        <label class="muted">Saved jobs (most recent first)</label>
        <select id="a-jobs-select" class="dark"><option value="" disabled selected>Loading…</option></select>
        <div id="a-replay-status" style="margin:.5rem 0"></div>
        <div style="display:flex;gap:.5rem;flex-wrap:wrap;margin-top:.5rem">
          <button id="a-replay-btn" class="danger" disabled onclick="sectionReplay('a')">Retry Load</button>
          <button id="a-resume-upload-btn" disabled onclick="sectionResumeUpload('a')">Resume Upload</button>
        </div>
      </div>
    </details>
  </div>

  <!-- Section B -->
  <div class="section-divider" id="secB">
    <h2>Section B: Add to Database from Folder <span class="muted" style="font-size:.8em">(non-destructive)</span></h2>
    <p class="muted">Adds new files to the DB without deleting existing data. Duplicate checksums are skipped.</p>
    <div id="b-lastjob" class="muted" style="margin-bottom:.5rem"></div>
    <label for="b-folder" class="muted" id="b-folder-label">Select a folder:</label>
    <input type="file" id="b-folder" webkitdirectory directory multiple style="display:block;margin:.5rem 0"/>
    <div id="b-preview"></div>
    <div id="b-status" style="margin:.5rem 0"></div>
    <div style="display:flex;gap:.5rem;flex-wrap:wrap;margin-top:.5rem">
      <button id="b-scan-btn"  class="danger" disabled onclick="sectionScan('b')">Scan &amp; Submit (Add to DB)</button>
      <button id="b-stop-btn"  class="danger" disabled onclick="sectionStop('b')">Stop hashing &amp; submit hashed</button>
      <button id="b-cache-btn" class="danger" disabled onclick="sectionClearCache('b')">Clear cached hashes</button>
    </div>
    <div id="b-upload-panel" style="display:none;margin-top:1.25rem">
      <h3 style="margin-bottom:.5rem">Step 2: Upload Media Files</h3>
      <div id="b-upload-status" style="margin:.5rem 0"></div>
      <div style="display:flex;gap:.5rem;flex-wrap:wrap">
        <button id="b-upload-btn" onclick="sectionStartUpload('b')" style="border-color:#22c55e">Upload Media</button>
        <button id="b-upload-refresh-btn" onclick="refreshUploadDebug('b')">Refresh Upload Log</button>
        <button id="b-mark-done-btn" onclick="sectionMarkDone('b')" style="display:none;border-color:#6b7280">Mark as Done</button>
      </div>
      <div id="b-upload-debug" class="debug-log"></div>
    </div>
    <details style="margin-top:1rem">
      <summary class="muted">Previous Jobs (Recovery) <span id="b-jobs-badge"></span></summary>
      <div style="margin-top:.75rem">
        <label class="muted">Saved jobs (most recent first)</label>
        <select id="b-jobs-select" class="dark"><option value="" disabled selected>Loading…</option></select>
        <div id="b-replay-status" style="margin:.5rem 0"></div>
        <div style="display:flex;gap:.5rem;flex-wrap:wrap;margin-top:.5rem">
          <button id="b-replay-btn" class="danger" disabled onclick="sectionReplay('b')">Retry Load</button>
          <button id="b-resume-upload-btn" disabled onclick="sectionResumeUpload('b')">Resume Upload</button>
        </div>
      </div>
    </details>
  </div>

  <!-- Section C -->
  <div class="section-divider">
    <h2>Section C: Single File Upload</h2>
    <button type="button" class="danger" onclick="window.open('/db/upload_form.php', '_blank', 'noopener,noreferrer')">Upload Utility</button>
  </div>
</div></div>

<!-- Duplicate resolution modal -->
<div id="dupModal">
  <div id="dupModalBox">
    <h2>Resolve Duplicate Checksums</h2>
    <p class="muted">Multiple files have the same SHA-256 hash. Select which path to keep for each group.</p>
    <div id="dupModalGroups"></div>
    <div style="margin-top:1.25rem;display:flex;gap:.75rem">
      <button id="dupModalConfirm" onclick="dupModalConfirm()">Confirm Resolutions</button>
      <button onclick="dupModalCancel()">Cancel</button>
    </div>
  </div>
</div>

<script>
'use strict';

// ── PHP-injected constants ───────────────────────────────────────────────────
const AUDIO_EXTS = new Set(<?= json_encode($__audio_exts) ?>);
const VIDEO_EXTS = new Set(<?= json_encode($__video_exts) ?>);
const MEDIA_EXTS = new Set([...AUDIO_EXTS, ...VIDEO_EXTS]);

// ── Per-section state ────────────────────────────────────────────────────────
const _S = {
  a: { mode:'reload', cancelReq:false, abortCtl:null, runAt:0, folderKey:'',
       scanState:null, runState:'idle', jobId:null, uploadFiles:null, uploadTrace:[], uploadPollTimer:null, _batchState:null },
  b: { mode:'add',    cancelReq:false, abortCtl:null, runAt:0, folderKey:'',
       scanState:null, runState:'idle', jobId:null, uploadFiles:null, uploadTrace:[], uploadPollTimer:null, _batchState:null },
};

// ── Small utilities ──────────────────────────────────────────────────────────
function escapeHtml(s) {
  return String(s||'').replace(/[&<>"]/g,c=>({'&':'&amp;','<':'&lt;','>':'&gt;','"':'&quot;'}[c]||c));
}
function formatBytes(n) {
  const v=Number(n)||0;
  if(v>=1073741824) return (v/1073741824).toFixed(2)+' GB';
  if(v>=1048576)    return (v/1048576).toFixed(2)+' MB';
  if(v>=1024)       return (v/1024).toFixed(2)+' KB';
  return v+' B';
}
function formatElapsed(ms) {
  const s=Math.max(0,Math.floor((Number(ms)||0)/1000));
  return Math.floor(s/60)+'m '+String(s%60).padStart(2,'0')+'s';
}
function el(id)  { return document.getElementById(id); }
function html(id,h){ const e=el(id); if(e) e.innerHTML=h; }
function nowIso(){ return new Date().toISOString(); }
function safeJson(v){ try { return JSON.stringify(v, null, 2); } catch(e) { return String(v); } }

// ── Date helpers ─────────────────────────────────────────────────────────────
function formatDateYmd(d) {
  return d.getFullYear()+'-'+String(d.getMonth()+1).padStart(2,'0')+'-'+String(d.getDate()).padStart(2,'0');
}
function parseDateFromFilename(name) {
  const s=String(name||'');
  const m=s.match(/\b((?:19|20)\d{2})[_\-](0[1-9]|1[0-2])[_\-](0[1-9]|[12]\d|3[01])\b/);
  return m ? (m[1]+'-'+m[2]+'-'+m[3]) : null;
}
function parseYearFromText(name) {
  const m=String(name||'').match(/\b(19\d{2}|20\d{2})\b/);
  return m?m[1]:'';
}
function deriveEventDate(f) {
  const p=filePathForImport(f);
  let d=parseDateFromFilename(p);
  if(!d){const y=parseYearFromText(p);if(y)d=y+'-01-01';}
  if(!d){const lm=Number(f&&f.lastModified);d=(!isNaN(lm)&&lm>0)?formatDateYmd(new Date(lm)):'1970-01-01';}
  return d;
}

// ── File helpers ─────────────────────────────────────────────────────────────
function fileExtLower(name){const n=String(name||'');const d=n.lastIndexOf('.');return d>=0?n.slice(d+1).toLowerCase():'';}
function filePathForImport(f){return(f&&typeof f.webkitRelativePath==='string'&&f.webkitRelativePath.trim()!=='')?f.webkitRelativePath:(f&&f.name?f.name:'');}
function inferFileType(name){const e=fileExtLower(name);if(AUDIO_EXTS.has(e))return'audio';if(VIDEO_EXTS.has(e))return'video';return'';}
function folderKeyFromFiles(list){for(const f of list){const r=filePathForImport(f);if(r&&r.indexOf('/')>=0)return r.split('/')[0];}return'';}
function supportedFiles(list){
  const out=[];
  for(const f of list){
    const sz=Number(f&&f.size)||0;if(!sz)continue;
    const ext=fileExtLower(f.name);if(!MEDIA_EXTS.has(ext))continue;
    const ft=inferFileType(f.name);if(!ft)continue;
    out.push({file:f,fileType:ft,relpath:filePathForImport(f)});
  }
  out.sort((a,b)=>a.relpath.localeCompare(b.relpath,undefined,{numeric:true}));
  return out;
}

// ── Scan preview ─────────────────────────────────────────────────────────────
function buildScanState(list) {
  let sup=0,ign=0,supBytes=0;
  const extCounts=new Map();
  for(const f of list){
    const sz=Number(f&&f.size)||0;if(!sz){ign++;continue;}
    const e=fileExtLower(f.name);
    if(!MEDIA_EXTS.has(e)){ign++;continue;}
    sup++;supBytes+=sz;
    extCounts.set('.'+e,(extCounts.get('.'+e)||0)+1);
  }
  const exts=Array.from(extCounts.entries()).map(([e,c])=>e+':'+c).join(', ');
  return {totalCount:list.length,supportedCount:sup,ignoredCount:ign,supportedSizeBytes:supBytes,extSummary:exts};
}
function renderScanPreview(st) {
  if(!st)return'';
  let h='<div class="muted">Files: '+st.totalCount+' total, '+st.supportedCount+' supported ('+formatBytes(st.supportedSizeBytes)+')';
  if(st.extSummary)h+=' — '+escapeHtml(st.extSummary);
  h+='</div>';
  if(st.supportedCount===0)h+='<div class="muted" style="color:#dc2626"><strong>No supported media files found.</strong></div>';
  return h;
}

// ── IndexedDB hash cache ─────────────────────────────────────────────────────
const HC_DB='gighive_hash_cache_v1', HC_STORE='hashes';
function openHcDb(){return new Promise((res,rej)=>{const r=indexedDB.open(HC_DB,1);r.onupgradeneeded=()=>{if(!r.result.objectStoreNames.contains(HC_STORE))r.result.createObjectStore(HC_STORE,{keyPath:'k'})};r.onsuccess=()=>res(r.result);r.onerror=()=>rej(r.error)});}
function hcKey(fk,rp,sz,lm){return fk+'::'+rp+'::'+sz+'::'+lm;}
async function getCached(fk,rp,sz,lm){if(!('indexedDB'in window))return null;const db=await openHcDb();try{return await new Promise((res,rej)=>{const tx=db.transaction(HC_STORE,'readonly');const r=tx.objectStore(HC_STORE).get(hcKey(fk,rp,sz,lm));r.onsuccess=()=>res(r.result?r.result.sha256||null:null);r.onerror=()=>rej(r.error)});}finally{db.close();}}
async function putCached(fk,rp,sz,lm,sha256){if(!('indexedDB'in window))return;const db=await openHcDb();try{await new Promise((res,rej)=>{const tx=db.transaction(HC_STORE,'readwrite');const r=tx.objectStore(HC_STORE).put({k:hcKey(fk,rp,sz,lm),folderKey:fk,relpath:rp,sizeBytes:sz,lastModifiedMs:lm,sha256});r.onsuccess=()=>res();r.onerror=()=>rej(r.error)});}finally{db.close();}}
async function clearCachedForFolder(fk){if(!('indexedDB'in window))return 0;const db=await openHcDb();try{return await new Promise((res,rej)=>{let n=0;const tx=db.transaction(HC_STORE,'readwrite');const c=tx.objectStore(HC_STORE).openCursor();c.onsuccess=e=>{const cur=e.target.result;if(!cur){res(n);return;}if(cur.value&&cur.value.folderKey===fk){const d=cur.delete();d.onsuccess=()=>{n++;cur.continue()};d.onerror=()=>rej(d.error)}else cur.continue()};c.onerror=()=>rej(c.error)});}finally{db.close();}}

// ── SHA-256 worker ───────────────────────────────────────────────────────────
function createSha256WorkerUrl() {
  const src=`function rotr(n,x){return(x>>>n)|(x<<(32-n));}function bytesToHex(u8){const h=[];for(let i=0;i<u8.length;i++)h.push(u8[i].toString(16).padStart(2,'0'));return h.join('');}function Sha256(){this._h=new Uint32Array([0x6a09e667,0xbb67ae85,0x3c6ef372,0xa54ff53a,0x510e527f,0x9b05688c,0x1f83d9ab,0x5be0cd19]);this._buf=new Uint8Array(64);this._bufLen=0;this._bytesHashed=0;this._w=new Uint32Array(64);}Sha256.prototype._k=new Uint32Array([0x428a2f98,0x71374491,0xb5c0fbcf,0xe9b5dba5,0x3956c25b,0x59f111f1,0x923f82a4,0xab1c5ed5,0xd807aa98,0x12835b01,0x243185be,0x550c7dc3,0x72be5d74,0x80deb1fe,0x9bdc06a7,0xc19bf174,0xe49b69c1,0xefbe4786,0x0fc19dc6,0x240ca1cc,0x2de92c6f,0x4a7484aa,0x5cb0a9dc,0x76f988da,0x983e5152,0xa831c66d,0xb00327c8,0xbf597fc7,0xc6e00bf3,0xd5a79147,0x06ca6351,0x14292967,0x27b70a85,0x2e1b2138,0x4d2c6dfc,0x53380d13,0x650a7354,0x766a0abb,0x81c2c92e,0x92722c85,0xa2bfe8a1,0xa81a664b,0xc24b8b70,0xc76c51a3,0xd192e819,0xd6990624,0xf40e3585,0x106aa070,0x19a4c116,0x1e376c08,0x2748774c,0x34b0bcb5,0x391c0cb3,0x4ed8aa4a,0x5b9cca4f,0x682e6ff3,0x748f82ee,0x78a5636f,0x84c87814,0x8cc70208,0x90befffa,0xa4506ceb,0xbef9a3f7,0xc67178f2]);Sha256.prototype._compress=function(c){const w=this._w;for(let i=0;i<16;i++){const j=i*4;w[i]=((c[j]<<24)|(c[j+1]<<16)|(c[j+2]<<8)|c[j+3])>>>0;}for(let i=16;i<64;i++){const s0=(rotr(7,w[i-15])^rotr(18,w[i-15])^(w[i-15]>>>3))>>>0;const s1=(rotr(17,w[i-2])^rotr(19,w[i-2])^(w[i-2]>>>10))>>>0;w[i]=(w[i-16]+s0+w[i-7]+s1)>>>0;}let a=this._h[0],b=this._h[1],cc=this._h[2],d=this._h[3],e=this._h[4],f=this._h[5],g=this._h[6],h=this._h[7];const k=this._k;for(let i=0;i<64;i++){const S1=(rotr(6,e)^rotr(11,e)^rotr(25,e))>>>0;const ch=((e&f)^(~e&g))>>>0;const t1=(h+S1+ch+k[i]+w[i])>>>0;const S0=(rotr(2,a)^rotr(13,a)^rotr(22,a))>>>0;const maj=((a&b)^(a&cc)^(b&cc))>>>0;const t2=(S0+maj)>>>0;h=g;g=f;f=e;e=(d+t1)>>>0;d=cc;cc=b;b=a;a=(t1+t2)>>>0;}this._h[0]=(this._h[0]+a)>>>0;this._h[1]=(this._h[1]+b)>>>0;this._h[2]=(this._h[2]+cc)>>>0;this._h[3]=(this._h[3]+d)>>>0;this._h[4]=(this._h[4]+e)>>>0;this._h[5]=(this._h[5]+f)>>>0;this._h[6]=(this._h[6]+g)>>>0;this._h[7]=(this._h[7]+h)>>>0;};Sha256.prototype.update=function(data){let pos=0;const len=data.length;this._bytesHashed+=len;while(pos<len){const take=Math.min(64-this._bufLen,len-pos);this._buf.set(data.subarray(pos,pos+take),this._bufLen);this._bufLen+=take;pos+=take;if(this._bufLen===64){this._compress(this._buf);this._bufLen=0;}}};Sha256.prototype.digest=function(){const totalBitsHi=Math.floor(this._bytesHashed/0x20000000)>>>0;const totalBitsLo=(this._bytesHashed<<3)>>>0;this._buf[this._bufLen++]=0x80;if(this._bufLen>56){while(this._bufLen<64)this._buf[this._bufLen++]=0;this._compress(this._buf);this._bufLen=0;}while(this._bufLen<56)this._buf[this._bufLen++]=0;this._buf[56]=(totalBitsHi>>>24)&0xff;this._buf[57]=(totalBitsHi>>>16)&0xff;this._buf[58]=(totalBitsHi>>>8)&0xff;this._buf[59]=totalBitsHi&0xff;this._buf[60]=(totalBitsLo>>>24)&0xff;this._buf[61]=(totalBitsLo>>>16)&0xff;this._buf[62]=(totalBitsLo>>>8)&0xff;this._buf[63]=totalBitsLo&0xff;this._compress(this._buf);const out=new Uint8Array(32);for(let i=0;i<8;i++){const v=this._h[i];out[i*4]=(v>>>24)&0xff;out[i*4+1]=(v>>>16)&0xff;out[i*4+2]=(v>>>8)&0xff;out[i*4+3]=v&0xff;}return out;};self.onmessage=async e=>{try{const file=e.data&&e.data.file;const cs=Number(e.data&&e.data.chunkSize)||(16*1024*1024);if(!file)throw new Error('No file');const total=Number(file.size)||0;const hasher=new Sha256();let offset=0;while(offset<total){const end=Math.min(total,offset+cs);const buf=await file.slice(offset,end).arrayBuffer();hasher.update(new Uint8Array(buf));offset=end;self.postMessage({ok:true,progress:{bytes:offset,total}});}const d=hasher.digest();self.postMessage({ok:true,sha256:bytesToHex(d),done:true});}catch(err){self.postMessage({ok:false,error:(err&&err.message)?err.message:String(err)});}};`;
  return URL.createObjectURL(new Blob([src],{type:'application/javascript'}));
}
async function sha256Abortable(file, signal, onProgress) {
  const url=createSha256WorkerUrl();
  const w=new Worker(url);
  let settled=false;
  const cleanup=()=>{if(settled)return;settled=true;try{w.terminate();}catch(e){}try{URL.revokeObjectURL(url);}catch(e){}};
  return await new Promise((resolve,reject)=>{
    const onAbort=()=>{cleanup();const e=new Error('Aborted');e.name='AbortError';reject(e);};
    if(signal){if(signal.aborted)return onAbort();signal.addEventListener('abort',onAbort,{once:true});}
    w.onmessage=e=>{const d=e.data||{};if(d.ok&&d.progress&&d.progress.total){if(typeof onProgress==='function')try{onProgress(Number(d.progress.bytes)||0,Number(d.progress.total)||0);}catch(e){}return;}cleanup();if(d.ok&&d.sha256)resolve(String(d.sha256));else reject(new Error(d.error||'Hash worker failed'));};
    w.onerror=()=>{cleanup();reject(new Error('Hash worker error'));};
    const sz=Number(file&&file.size)||0;
    w.postMessage({file,chunkSize:sz>=(512*1024*1024)?8388608:16777216});
  });
}


const __dbLinkStyle='display:inline-block;margin-left:10px;padding:8px 16px;background:#28a745;color:white;text-decoration:none;border-radius:4px;font-weight:bold;';
function renderDbLinkButton(label){
  return ' <a href="/db/database.php" target="_blank" rel="noopener noreferrer" style="'+__dbLinkStyle+'">'+String(label)+'</a>';
}
function renderOkBannerWithDbLink(message,linkLabel){
  return '<div class="alert-ok">'+String(message)+renderDbLinkButton(linkLabel)+'</div>';
}

 function pushClientTrace(id, entry) {
  const s=_S[id];
  if(!s) return;
  const enriched=Object.assign({
    ts: nowIso(),
    source: 'browser',
  }, entry || {});
  s.uploadTrace = Array.isArray(s.uploadTrace) ? s.uploadTrace : [];
  s.uploadTrace.push(enriched);
  if(s.uploadTrace.length > 400) s.uploadTrace = s.uploadTrace.slice(-400);
  renderUploadDebug(id);
 }

 function mergeServerTrace(id, trace) {
  const s=_S[id];
  if(!s || !Array.isArray(trace)) return;
  const seen=new Set((s.uploadTrace||[]).map(item => [item.ts||'', item.source||'', item.endpoint||'', item.phase||'', item.upload_id||'', item.checksum_sha256||'', item.error||''].join('|')));
  for(const item of trace){
    const key=[item.ts||'', item.source||'', item.endpoint||'', item.phase||'', item.upload_id||'', item.checksum_sha256||'', item.error||''].join('|');
    if(seen.has(key)) continue;
    seen.add(key);
    s.uploadTrace.push(item);
  }
  s.uploadTrace.sort((a,b)=>String(a.ts||'').localeCompare(String(b.ts||'')));
  if(s.uploadTrace.length > 400) s.uploadTrace = s.uploadTrace.slice(-400);
  renderUploadDebug(id);
 }

 function uploadTraceSummary(entry) {
  const src=String(entry&&entry.source||'');
  const endpoint=String(entry&&entry.endpoint||'');
  const phase=String(entry&&entry.phase||'');
  const statusCode=(entry&&entry.status_code!==undefined&&entry.status_code!==null)?(' HTTP '+String(entry.status_code)) : '';
  const err=entry&&entry.error ? (' — ' + String(entry.error)) : '';
  const msg=entry&&entry.message ? (' — ' + String(entry.message)) : '';
  return [src, endpoint || phase, phase && endpoint ? ('['+phase+']') : '', statusCode].filter(Boolean).join(' ') + (err || msg);
 }

 function renderUploadDebug(id) {
  const s=_S[id];
  if(!s) return;
  const box=el(id+'-upload-debug');
  if(!box) return;
  const trace=Array.isArray(s.uploadTrace) ? s.uploadTrace.slice().reverse() : [];
  if(!trace.length){
    box.innerHTML='<div class="muted">No Step 2 log entries yet.</div>';
    return;
  }
  let h='';
  for(const item of trace){
    if(item&&item.phase==='upload_halted'){
      h+='<div class="debug-log-row" style="border-left:3px solid #ef4444;margin:.25rem 0">'
        +'<div class="debug-log-meta">'+escapeHtml(String(item.ts||''))+'</div>'
        +'<div style="color:#ef4444;font-weight:700;font-size:1rem;letter-spacing:.04em;padding:.25rem 0">'
        +'&#9888; UPLOADING HALTED'+(item.failure_code?' ('+escapeHtml(item.failure_code)+')':'')+(item.pending_count?' &mdash; '+item.pending_count+' FILE(S) STUCK PENDING':'')
        +'</div>'
        +'<div style="color:#fca5a5;margin-bottom:.25rem">'+escapeHtml(item.message||'')+'</div>'
        +(item.stuck_files&&item.stuck_files.length?'<pre class="debug-log-pre" style="border-color:#7f1d1d">'+escapeHtml(safeJson(item.stuck_files))+'</pre>':'')
        +'</div>';
      continue;
    }
    const extra={};
    for(const [k,v] of Object.entries(item||{})){
      if(['ts','ts_unix_ms','source','endpoint','phase','status_code','message','error'].includes(k)) continue;
      extra[k]=v;
    }
    h+='<div class="debug-log-row">'
      + '<div class="debug-log-meta">' + escapeHtml(String(item.ts||'')) + '</div>'
      + '<div class="debug-log-msg">' + escapeHtml(uploadTraceSummary(item)) + '</div>'
      + (Object.keys(extra).length ? ('<pre class="debug-log-pre">'+escapeHtml(safeJson(extra))+'</pre>') : '')
      + '</div>';
  }
  box.innerHTML=h;
 }

 async function fetchUploadDebug(id) {
  const s=_S[id];
  if(!s || !s.jobId) return null;
  try {
    const r=await fetch('import_manifest_upload_status.php?job_id='+encodeURIComponent(s.jobId)+'&_t='+Date.now(), { cache:'no-store' });
    const d=await r.json().catch(()=>null);
    const summary={
      status_code: r.status,
      response_ok: r.ok,
      complete: d && d.complete !== undefined ? d.complete : null,
      success: d && d.success !== undefined ? d.success : null,
      file_states: d && Array.isArray(d.files)
        ? d.files.reduce((acc,f)=>{
            const st=String((f&&f.state)||'unknown');
            acc[st]=(acc[st]||0)+1;
            return acc;
          }, {})
        : null,
    };
    const summaryKey=JSON.stringify(summary);
    if(summaryKey!==s._lastUploadPollSummaryKey){
      s._lastUploadPollSummaryKey=summaryKey;
      pushClientTrace(id, {
        endpoint: 'import_manifest_upload_status.php',
        phase: 'status_poll_response',
        status_code: r.status,
        message: (d&&d.message) || (d&&d.complete ? 'Upload batch complete' : 'Upload batch status changed'),
        response_ok: r.ok,
        complete: summary.complete,
        success: summary.success,
      });
    }
    if(d && Array.isArray(d.trace)) mergeServerTrace(id, d.trace);
    if(d && Array.isArray(d.files)) {
      if(s.uploadFiles&&s.uploadFiles.length){
        for(const sf of d.files){
          const cf=s.uploadFiles.find(f=>f.checksum_sha256===sf.checksum_sha256);
          if(cf&&sf.state==='pending'&&cf.state!=='pending'){
            sf.state=cf.state;
            if(cf.retryable!==undefined)sf.retryable=cf.retryable;
            if(cf.failure_code!==undefined)sf.failure_code=cf.failure_code;
            if(cf.last_error!==undefined)sf.last_error=cf.last_error;
            if(cf.retry_count!==undefined)sf.retry_count=cf.retry_count||0;
          }
        }
      }
      s.uploadFiles=d.files;
      renderUploadRows(id,d.files);
      if(d.complete||d.files.some(f=>f.state==='failed'))updateUploadButtonState(id);
    }
    return d;
  } catch (e) {
    pushClientTrace(id, {
      endpoint: 'import_manifest_upload_status.php',
      phase: 'status_poll_error',
      error: String(e&&e.message?e.message:e),
    });
    return null;
  }
 }

 async function refreshUploadDebug(id) {
  await fetchUploadDebug(id);
 }

 function startUploadDebugPolling(id) {
  const s=_S[id];
  if(!s || !s.jobId) return;
  if(s.uploadPollTimer) clearInterval(s.uploadPollTimer);
  s.uploadPollTimer = setInterval(async()=>{
    const data=await fetchUploadDebug(id);
    if(data && data.complete && s.uploadPollTimer){
      clearInterval(s.uploadPollTimer);
      s.uploadPollTimer=null;
    }
  }, 2500);
 }

 function stopUploadDebugPolling(id) {
  const s=_S[id];
  if(s && s.uploadPollTimer){ clearInterval(s.uploadPollTimer); s.uploadPollTimer=null; }
 }

// ── pollManifestJob ──────────────────────────────────────────────────────────
async function pollManifestJob(jobId, statusEl, onDone) {
  const start=Date.now();
  let stopped=false;
  const tick=async()=>{
    if(stopped)return;
    try{
      const r=await fetch('import_manifest_status.php?job_id='+encodeURIComponent(jobId)+'&_t='+Date.now(),{cache:'no-store'});
      const d=await r.json().catch(()=>null);
      const state=(d&&d.state)?String(d.state):'queued';
      if(statusEl){
        const elapsedMs=Date.now()-start;
        const elapsed=formatElapsed(elapsedMs);
        const etaText=(d&&d.steps)?getImportProgressEtaText(d.steps, elapsedMs):'';
        statusEl.innerHTML='<div class="muted">Job '+escapeHtml(jobId)+': '+escapeHtml(state)+' (elapsed: '+elapsed+(etaText?' — '+escapeHtml(etaText):'')+')</div>'
          +(d&&d.steps?renderImportStepsShared(d.steps, {showProgressBar: false, label: 'Steps:', statusIndentPx: 70}):'');
      }
      if(state==='ok'||state==='error'||state==='canceled'){
        stopped=true;if(onDone)onDone(state,d);return;
      }
    }catch(e){if(statusEl)statusEl.innerHTML='<div class="alert-err">Polling error: '+escapeHtml(String(e&&e.message?e.message:e))+'</div>';}
    setTimeout(tick,Date.now()-start<15000?1000:2500);
  };
  tick();
}

// ── Hash items from supported files ─────────────────────────────────────────
async function hashFilesToItems(supported, s, statusEl) {
  const items=[];
  let cached=0,hashed=0,hashedBytes=0;
  const total=supported.reduce((a,x)=>a+(Number(x.file&&x.file.size)||0),0);
  let done=0;
  for(let i=0;i<supported.length;i++){
    const{file,fileType,relpath}=supported[i];
    if(s.cancelReq)break;
    const sz=Number(file.size)||0;
    const lm=Number(file.lastModified)||0;
    let cs=s.folderKey?await getCached(s.folderKey,relpath,sz,lm).catch(()=>null):null;
    if(cs){cached++;done+=sz;}
    else{
      const elapsed=formatElapsed(Date.now()-s.runAt);
      if(statusEl)statusEl.innerHTML='<div class="muted">Hashing '+(i+1)+'/'+supported.length+'… cached:'+cached+' hashed:'+hashed+' ('+formatBytes(hashedBytes)+') elapsed:'+elapsed+'</div><div class="muted">'+escapeHtml(relpath)+'</div>';
      try{
        cs=await sha256Abortable(file,s.abortCtl?s.abortCtl.signal:null,(b)=>{
          if(statusEl)statusEl.innerHTML='<div class="muted">Hashing '+(i+1)+'/'+supported.length+' '+(Math.floor(b/sz*100))+'%… elapsed:'+formatElapsed(Date.now()-s.runAt)+'</div><div class="muted">'+escapeHtml(relpath)+'</div>';
        });
      }catch(e){if(s.cancelReq||e.name==='AbortError')break;throw e;}
      hashed++;hashedBytes+=sz;done+=sz;
      if(s.folderKey&&cs)putCached(s.folderKey,relpath,sz,lm,cs).catch(()=>{});
    }
    items.push({file_name:file.name,source_relpath:relpath,file_type:fileType,event_date:deriveEventDate(file),size_bytes:sz,checksum_sha256:cs});
  }
  return items;
}

// ── Detect duplicates ────────────────────────────────────────────────────────
function detectDuplicates(items) {
  const byCs=new Map();
  for(const it of items){
    const cs=it.checksum_sha256||'';if(!cs)continue;
    if(!byCs.has(cs))byCs.set(cs,[]);
    byCs.get(cs).push(it);
  }
  const groups=[];
  for(const[cs,list]of byCs.entries()){
    if(list.length>1)groups.push({checksum_sha256:cs,candidates:list.map(x=>({source_relpath:x.source_relpath,file_name:x.file_name}))});
  }
  return groups;
}

// ── Duplicate resolution modal ───────────────────────────────────────────────
let _dupResolve=null,_dupReject=null;

function showDupModal(groups) {
  return new Promise((resolve,reject)=>{
    _dupResolve=resolve;_dupReject=reject;
    let html='';
    for(const g of groups){
      const cs=g.checksum_sha256;
      html+='<div style="margin-bottom:1rem"><div class="muted" style="font-size:.85rem;word-break:break-all">'+escapeHtml(cs)+'</div>';
      html+='<select class="dark dup-select" data-cs="'+escapeHtml(cs)+'">';
      for(const c of g.candidates)html+='<option value="'+escapeHtml(c.source_relpath)+'">'+escapeHtml(c.source_relpath)+'</option>';
      html+='</select></div>';
    }
    el('dupModalGroups').innerHTML=html;
    el('dupModal').style.display='block';
  });
}
function dupModalConfirm() {
  const sels=document.querySelectorAll('.dup-select');
  const resolutions=Array.from(sels).map(s=>({checksum_sha256:s.dataset.cs,chosen_source_relpath:s.value}));
  el('dupModal').style.display='none';
  if(_dupResolve){_dupResolve(resolutions);_dupResolve=null;}
}
function dupModalCancel() {
  el('dupModal').style.display='none';
  if(_dupReject){_dupReject(new Error('Duplicate resolution canceled by user'));_dupReject=null;}
}

// ── Main Step 1 flow ─────────────────────────────────────────────────────────
async function sectionScan(id) {
  const s=_S[id];
  const folderInput=el(id+'-folder');
  const statusEl=el(id+'-status');
  const mode=s.mode;

  if(!(folderInput&&folderInput.files&&folderInput.files.length)){
    html(id+'-status','<div class="alert-err">Please select a folder first.</div>');return;
  }
  const confirmText=mode==='reload'
    ?'Scan and RELOAD database?\n\nThis will delete ALL existing sessions/songs/files.\n\nThis CANNOT be undone!'
    :'Scan and ADD to database?';
  if(!confirm(confirmText))return;

  s.cancelReq=false;
  resetProgressLatch();
  s.abortCtl=new AbortController();
  s.runAt=Date.now();
  el(id+'-scan-btn').disabled=true;
  el(id+'-stop-btn').disabled=false;
  el(id+'-upload-panel').style.display='none';

  const list=Array.from(folderInput.files);
  const sup=supportedFiles(list);
  if(!sup.length){html(id+'-status','<div class="alert-err">No supported media files found.</div>');el(id+'-scan-btn').disabled=false;el(id+'-stop-btn').disabled=true;return;}

  html(id+'-status','<div class="muted">Starting hashing…</div>');

  let items;
  try{items=await hashFilesToItems(sup,s,statusEl);}
  catch(e){
    const msg=String(e&&e.message?e.message:e);
    const isReadErr=msg.toLowerCase().includes('could not be read')||msg.toLowerCase().includes('notreadableerror');
    const hint=isReadErr?' <strong>Tip:</strong> If your files are in a OneDrive, Google Drive, or other cloud-synced folder, copy them to a plain local folder (e.g. Downloads) and try again.':'';
    html(id+'-status','<div class="alert-err">Hashing error: '+escapeHtml(msg)+hint+'</div>');
    el(id+'-scan-btn').disabled=false;el(id+'-stop-btn').disabled=true;return;
  }

  if(!items.length){html(id+'-status','<div class="alert-err">No files were hashed. Nothing to import.</div>');el(id+'-scan-btn').disabled=false;el(id+'-stop-btn').disabled=true;return;}

  el(id+'-stop-btn').disabled=true;
  html(id+'-status','<div class="muted">Hashing complete ('+items.length+' items). Detecting duplicates…</div>');

  const dupGroups=detectDuplicates(items);

  let resolutions=[];
  if(dupGroups.length){
    html(id+'-status','<div class="muted">'+dupGroups.length+' duplicate checksum group(s) found. Please resolve…</div>');
    try{resolutions=await showDupModal(dupGroups);}
    catch(e){html(id+'-status','<div class="alert-err">'+escapeHtml(String(e&&e.message?e.message:e))+'</div>');el(id+'-scan-btn').disabled=false;return;}
  }

  html(id+'-status','<div class="muted">Submitting manifest to server…</div>');

  let jobId;
  try{
    const prep=await fetch('import_manifest_prepare.php',{method:'POST',headers:{'Content-Type':'application/json'},body:JSON.stringify({mode,items,duplicates:dupGroups})});
    const pd=await prep.json().catch(()=>null);
    if(!(prep.ok&&pd&&pd.success&&pd.job_id))throw new Error((pd&&pd.message)||'prepare failed');
    jobId=String(pd.job_id);
  }catch(e){html(id+'-status','<div class="alert-err">Error submitting manifest: '+escapeHtml(String(e&&e.message?e.message:e))+'</div>');el(id+'-scan-btn').disabled=false;return;}

  html(id+'-status','<div class="muted">Finalizing job '+escapeHtml(jobId)+'…</div>');

  try{
    const fin=await fetch('import_manifest_finalize.php',{method:'POST',headers:{'Content-Type':'application/json'},body:JSON.stringify({job_id:jobId,resolutions})});
    const fd=await fin.json().catch(()=>null);
    if(fin.status===409&&fd&&fd.job_id){
      html(id+'-status','<div class="muted" style="color:#dc2626">Another import is running. Attaching to job '+escapeHtml(String(fd.job_id))+'…</div>');
      jobId=String(fd.job_id);
    } else if(!(fin.ok&&fd&&fd.success)){
      throw new Error((fd&&fd.message)||'finalize failed');
    }
  }catch(e){html(id+'-status','<div class="alert-err">Error starting import: '+escapeHtml(String(e&&e.message?e.message:e))+'</div>');el(id+'-scan-btn').disabled=false;return;}

  s.jobId=jobId;
  html(id+'-status','<div class="muted">Job '+escapeHtml(jobId)+' started. Polling…</div>');

  pollManifestJob(jobId,statusEl,(state,data)=>{
    el(id+'-scan-btn').disabled=false;
    if(state==='ok'&&data&&data.success!==false){
      html(id+'-status','<div class="alert-ok">Step 1 complete. Job: '+escapeHtml(jobId)+'</div>'+(data.steps?renderImportStepsShared(data.steps, {showProgressBar: false, label: 'Steps:', statusIndentPx: 70}):''));
      showUploadPanel(id,jobId,Array.from(folderInput.files));
    } else {
      const msg=(data&&(data.message||data.error))||'Import failed';
      html(id+'-status','<div class="alert-err">'+escapeHtml(String(msg))+'</div>'+(data&&data.steps?renderImportStepsShared(data.steps, {showProgressBar: false, label: 'Steps:', statusIndentPx: 70}):''));
    }
    refreshJobsUi(id);
  });
}

function sectionStop(id){
  const s=_S[id];
  s.cancelReq=true;
  if(s.abortCtl)try{s.abortCtl.abort();}catch(e){}
  el(id+'-stop-btn').disabled=true;
  html(id+'-status','<div class="muted">Stop requested. Submitting hashed files…</div>');
}

async function sectionClearCache(id){
  const s=_S[id];
  if(!s.folderKey){html(id+'-status','<div class="alert-err">Select a folder first.</div>');return;}
  if(!confirm('Clear cached hashes for this folder?\nThis forces re-hashing next run.'))return;
  el(id+'-cache-btn').disabled=true;
  try{const n=await clearCachedForFolder(s.folderKey);html(id+'-status','<div class="alert-ok">Cleared '+n+' cached hash entr'+(n===1?'y':'ies')+'.</div>');}
  catch(e){html(id+'-status','<div class="alert-err">Error: '+escapeHtml(String(e&&e.message?e.message:e))+'</div>');}
  el(id+'-cache-btn').disabled=!(s.folderKey&&s.scanState&&s.scanState.supportedCount>0);
}

// ── Step 2: Upload panel ─────────────────────────────────────────────────────
function showUploadPanel(id, jobId, fileList) {
  const s=_S[id];
  s.jobId=jobId;
  s._fileList=fileList||[];
  if(!Array.isArray(s.uploadTrace)) s.uploadTrace=[];
  el(id+'-upload-panel').style.display='block';
  el(id+'-upload-btn').textContent='Upload Media';
  el(id+'-upload-btn').disabled=false;
  html(id+'-upload-status','<div class="muted">Ready. '+s._fileList.length+' source files available.</div>');
  renderUploadDebug(id);
  startUploadDebugPolling(id);
}

async function sectionStartUpload(id){
  const s=_S[id];
  if(!s.jobId){html(id+'-upload-status','<div class="alert-err">No job_id. Run Step 1 first.</div>');return;}

  if(!s.uploadFiles||!s.uploadFiles.some(f=>f.state==='failed'))s.uploadTrace=[];
  pushClientTrace(id, {
    endpoint: 'admin_database_load_import_media_from_folder.php',
    phase: s.uploadFiles&&s.uploadFiles.some(f=>f.state==='failed')?'step2_retry':'step2_begin',
    job_id: s.jobId,
    local_source_file_count: Array.isArray(s._fileList) ? s._fileList.length : 0,
  });

  el(id+'-upload-btn').disabled=true;
  el(id+'-upload-btn').textContent='Starting…';
  html(id+'-upload-status','<div class="muted">Initialising upload session…</div>');

  let files;
  try{
    pushClientTrace(id, {
      endpoint: 'import_manifest_upload_start.php',
      phase: 'upload_start_request',
      job_id: s.jobId,
    });
    const r=await fetch('import_manifest_upload_start.php',{method:'POST',headers:{'Content-Type':'application/json'},body:JSON.stringify({job_id:s.jobId})});
    const d=await r.json().catch(()=>null);
    pushClientTrace(id, {
      endpoint: 'import_manifest_upload_start.php',
      phase: 'upload_start_response',
      status_code: r.status,
      response_ok: r.ok,
      resumed: d && d.resumed !== undefined ? d.resumed : null,
      file_count: d && Array.isArray(d.files) ? d.files.length : null,
      message: d && (d.message || d.error) ? (d.message || d.error) : null,
    });
    if(d && Array.isArray(d.trace)) mergeServerTrace(id, d.trace);
    if(!(r.ok&&d&&d.success&&Array.isArray(d.files)))throw new Error((d&&d.message)||'upload_start failed');
    files=d.files;
  }catch(e){html(id+'-upload-status','<div class="alert-err">'+escapeHtml(String(e&&e.message?e.message:e))+'</div>');el(id+'-upload-btn').disabled=false;el(id+'-upload-btn').textContent='Upload Media';return;}

  s.uploadFiles=files;

  const MAX_RETRIES=3;
  const pending=files.filter(f=>f.state==='pending'||(f.state==='failed'&&f.retryable===true&&(f.retry_count||0)<MAX_RETRIES));
  const present=files.filter(f=>f.state==='already_present');

  if(!pending.length){
    s._batchState='complete_success';
    html(id+'-upload-status',renderOkBannerWithDbLink('All '+files.length+' files already present on server. Nothing to upload.','See Updated Database'));
    el(id+'-upload-btn').textContent='Uploads Complete';
    el(id+'-upload-btn').disabled=true;
    renderUploadRows(id,files);
    startUploadDebugPolling(id);
    return;
  }

  html(id+'-upload-status','<div class="muted">'+pending.length+' files to upload, '+present.length+' already present.</div>');
  renderUploadRows(id,files);

  const fileMap=new Map();
  for(const f of (s._fileList||[])){
    const rel=filePathForImport(f);
    fileMap.set(rel,f);
  }

  const UPLOAD_CONCURRENCY = 3;
  let taskIdx = 0;
  const uploadWorker = async () => {
    while(taskIdx < pending.length) {
      const fileInfo = pending[taskIdx++];
      if(!fileInfo) break;
      const localFile = fileMap.get(fileInfo.source_relpath);
      if(!localFile){
        pushClientTrace(id, {
          endpoint: 'admin_database_load_import_media_from_folder.php',
          phase: 'local_file_missing',
          checksum_sha256: fileInfo.checksum_sha256,
          source_relpath: fileInfo.source_relpath,
          error: 'Local file not found in current folder selection',
        });
        setUploadRowState(id,fileInfo.checksum_sha256,'failed','Local file not found',{retryable:false,failure_code:'local_file_missing'});
        continue;
      }
      await uploadOneFile(id,fileInfo,localFile,s.jobId);
    }
  };
  await Promise.all(Array.from({length: Math.min(UPLOAD_CONCURRENCY, pending.length||1)}, uploadWorker));

  const allFiles=s.uploadFiles||[];
  const termSuccess=f=>['db_done','thumbnail_done','uploaded','already_present'].includes(f.state);
  const isRetryableFail=f=>f.state==='failed'&&f.retryable===true&&(f.retry_count||0)<MAX_RETRIES;
  const succeededCount=allFiles.filter(termSuccess).length;
  const retryableCount=allFiles.filter(isRetryableFail).length;
  const terminalFailCount=allFiles.filter(f=>f.state==='failed'&&!isRetryableFail(f)).length;
  const remainingPending=allFiles.filter(f=>f.state==='pending').length;
  if(retryableCount>0){s._batchState='blocked_retryable';}
  else if(remainingPending>0){s._batchState='in_progress';}
  else if(terminalFailCount>0){s._batchState='complete_failed_terminal';}
  else{s._batchState='complete_success';}
  if(remainingPending>0){
    pushClientTrace(id, {
      endpoint: 'admin_database_load_import_media_from_folder.php',
      phase: 'upload_halted',
      job_id: s.jobId,
      pending_count: remainingPending,
      stuck_files: allFiles.filter(f=>f.state==='pending').map(f=>({source_relpath:f.source_relpath,checksum_sha256:f.checksum_sha256})),
      message: 'Upload workers completed but '+remainingPending+' file(s) remain pending. This is likely a poll race — the status poll overwrote a just-completed file back to pending. Press Resume Upload to continue.',
    });
  }
  pushClientTrace(id, {
    endpoint: 'admin_database_load_import_media_from_folder.php',
    phase: 'step2_batch_complete',
    job_id: s.jobId,
    total: allFiles.length,
    succeeded: succeededCount,
    failed_retryable: retryableCount,
    failed_terminal: terminalFailCount,
    pending: remainingPending,
    batch_state: s._batchState,
  });
  updateUploadButtonState(id);
  await refreshUploadDebug(id);
  startUploadDebugPolling(id);
}

async function sectionResumeUpload(id){
  const sel=el(id+'-jobs-select');
  const jobId=sel&&sel.value?String(sel.value).trim():'';
  if(!jobId){html(id+'-replay-status','<div class="alert-err">Select a job first.</div>');return;}
  const s=_S[id];
  s.jobId=jobId;
  el(id+'-upload-panel').style.display='block';
  el(id+'-upload-btn').textContent='Upload Media';
  el(id+'-upload-btn').disabled=false;
  html(id+'-upload-status','<div class="muted">Ready to resume. Source files: select the folder first if uploading new files.</div>');
  s.uploadTrace=[];
  pushClientTrace(id, {
    endpoint: 'admin_database_load_import_media_from_folder.php',
    phase: 'resume_upload_selected',
    job_id: jobId,
  });
  await refreshUploadDebug(id);
  updateUploadButtonState(id);
  startUploadDebugPolling(id);
}

function renderUploadRows(id,files){
  const summaryEl=el(id+'-upload-summary');
  const el2=el(id+'-upload-rows');
  if(summaryEl){
    const MR=3;
    const counts={pending:0,uploading:0,done:0,already_present:0,failed_retryable:0,failed_terminal:0};
    for(const f of (files||[])){
      const st=String((f&&f.state)||'pending');
      if(st==='already_present')counts.already_present++;
      else if(st==='failed'){if(f.retryable===true&&(f.retry_count||0)<MR)counts.failed_retryable++;else counts.failed_terminal++;}
      else if(st==='pending')counts.pending++;
      else if(st==='uploading'||st==='resuming')counts.uploading++;
      else counts.done++;
    }
    const parts=['Pending: '+counts.pending,'Uploading: '+counts.uploading,'Done: '+counts.done,'Already Present: '+counts.already_present];
    if(counts.failed_retryable>0)parts.push('Failed (retryable): '+counts.failed_retryable);
    if(counts.failed_terminal>0)parts.push('Failed (permanent): '+counts.failed_terminal);
    summaryEl.textContent=parts.join(' | ');
  }
  if(!el2)return;
  let h='';
  for(const f of files){
    const badge=uploadBadge(f.state);
    h+='<div class="upload-row" id="urow-'+id+'-'+escapeHtml(f.checksum_sha256)+'">'+badge+'<span class="muted" style="font-size:.88rem;word-break:break-all">'+escapeHtml(f.source_relpath)+'</span></div>';
  }
  el2.innerHTML=h;
}
function uploadBadge(state){
  const cls=state==='already_present'?'badge-present':state==='failed'?'badge-failed':state==='pending'?'badge-pending':state==='uploading'?'badge-uploading':'badge-done';
  const labels={'thumbnail_done':'Done','db_done':'Done','uploaded':'Done','already_present':'Already Present','pending':'Pending','uploading':'Uploading…','resuming':'Resuming…','failed':'Failed'};
  const label=labels[state]||escapeHtml(state);
  return'<span class="upload-badge '+cls+'" title="'+escapeHtml(state)+'">'+label+'</span>';
}
function setUploadRowState(id,cs,state,note,extra){
  const row=el('urow-'+id+'-'+cs);
  if(row){const spans=row.querySelectorAll('span');if(spans[0])spans[0].outerHTML=uploadBadge(state);}
  const s=_S[id];
  if(s.uploadFiles){const f=s.uploadFiles.find(x=>x.checksum_sha256===cs);if(f){
    f.state=state;
    if(note!==undefined)f.last_error=note;
    if(extra){if(extra.retryable!==undefined)f.retryable=extra.retryable;if(extra.failure_code!==undefined)f.failure_code=extra.failure_code;if(extra.retry_count!==undefined)f.retry_count=extra.retry_count;}
  }}
}

function updateUploadButtonState(id){
  const s=_S[id];
  const btn=el(id+'-upload-btn');
  const markDoneBtn=el(id+'-mark-done-btn');
  if(!btn)return;
  const allFiles=s.uploadFiles||[];
  if(!allFiles.length)return;
  const MAX_RETRIES=3;
  const isRetryableFail=f=>f.state==='failed'&&f.retryable===true&&(f.retry_count||0)<MAX_RETRIES;
  const termSuccess=f=>['db_done','thumbnail_done','uploaded','already_present'].includes(f.state);
  const retryableCount=allFiles.filter(isRetryableFail).length;
  const pendingCount=allFiles.filter(f=>f.state==='pending').length;
  const terminalFailCount=allFiles.filter(f=>f.state==='failed'&&!isRetryableFail(f)).length;
  const hasFolderFiles=(s._fileList||[]).length>0;
  const needsLocalFiles=allFiles.some(f=>(f.state==='pending'||(f.state==='failed'&&isRetryableFail(f)))&&f.failure_code!=='finalize_error'&&f.failure_code!=='thumbnail_error'&&f.failure_code!=='db_error');
  if(markDoneBtn)markDoneBtn.style.display='none';
  if(retryableCount>0){
    btn.textContent='Retry Upload';
    btn.disabled=false;
    btn.onclick=()=>sectionStartUpload(id);
    const diskFullCount=allFiles.filter(f=>isRetryableFail(f)&&f.failure_code==='disk_full').length;
    let msg=retryableCount+' retryable failure(s)'+(pendingCount>0?', '+pendingCount+' pending':'')+'. ';
    if(diskFullCount>0){msg+='SERVER DISK FULL: free space on /srv/tusd-data/data/ then retry ('+diskFullCount+' file(s) affected). ';}
    else{msg+='Resolve the issue then retry. ';}
    if(needsLocalFiles&&!hasFolderFiles)msg+='Reselect source folder to enable retry.';
    html(id+'-upload-status','<div class="alert-err">'+escapeHtml(msg.trim())+'</div>');
  }else if(pendingCount>0){
    btn.textContent='Resume Upload';
    btn.disabled=!hasFolderFiles;
    btn.onclick=()=>sectionStartUpload(id);
    if(!hasFolderFiles)html(id+'-upload-status','<div class="alert-err">'+pendingCount+' file(s) pending. Reselect source folder to resume.</div>');
  }else if(terminalFailCount>0){
    btn.textContent='Retry Upload';
    btn.disabled=true;
    if(markDoneBtn)markDoneBtn.style.display='';
    html(id+'-upload-status','<div class="alert-err">'+terminalFailCount+' permanent failure(s). No further retries available. Click Mark as Done to acknowledge.</div>');
  }else{
    btn.textContent='Uploads Complete';
    btn.disabled=true;
    html(id+'-upload-status',renderOkBannerWithDbLink('All uploads complete.','See Updated Database'));
  }
}

function sectionMarkDone(id){
  const s=_S[id];
  s._batchState='complete_failed_terminal';
  const btn=el(id+'-upload-btn');
  const markDoneBtn=el(id+'-mark-done-btn');
  const allFiles=s.uploadFiles||[];
  const MAX_RETRIES=3;
  const isRetryableFail=f=>f.state==='failed'&&f.retryable===true&&(f.retry_count||0)<MAX_RETRIES;
  const terminalFailCount=allFiles.filter(f=>f.state==='failed'&&!isRetryableFail(f)).length;
  if(btn){btn.textContent='Uploads Complete';btn.disabled=true;}
  if(markDoneBtn)markDoneBtn.style.display='none';
  html(id+'-upload-status','<div class="alert-err">Upload complete with '+terminalFailCount+' permanent failure(s).</div>');
  pushClientTrace(id,{endpoint:'admin_database_load_import_media_from_folder.php',phase:'mark_done',job_id:s.jobId,terminal_fail_count:terminalFailCount});
}

// Ensure upload-rows div exists inside upload-panel
function ensureUploadRows(id){
  const panel=el(id+'-upload-panel');
  if(!panel)return;
  if(!el(id+'-upload-summary')){
    const s=document.createElement('div');s.id=id+'-upload-summary';s.className='muted';s.style.marginTop='.75rem';
    panel.appendChild(s);
  }
  if(!el(id+'-upload-rows')){
    const d=document.createElement('div');d.id=id+'-upload-rows';d.style.marginTop='.75rem';
    panel.appendChild(d);
  }
}

async function uploadOneFile(id, fileInfo, localFile, jobId){
  setUploadRowState(id,fileInfo.checksum_sha256,'uploading');
  pushClientTrace(id, {
    endpoint: '/files/',
    phase: 'tus_upload_begin',
    job_id: jobId,
    checksum_sha256: fileInfo.checksum_sha256,
    source_relpath: fileInfo.source_relpath,
    file_name: fileInfo.file_name,
    file_type: fileInfo.file_type,
    size_bytes: Number(localFile&&localFile.size)||null,
  });

  const metadata={filename:localFile.name,checksum_sha256:fileInfo.checksum_sha256,job_id:jobId,filetype:fileInfo.file_type};

  await new Promise((resolve)=>{
    const upload=new tus.Upload(localFile,{
      endpoint:'/files/',
      retryDelays:[0,1000,3000],
      metadata,
      chunkSize:8*1024*1024,
      onProgress:(bytesUploaded, bytesTotal)=>{
        const pct = bytesTotal > 0 ? Math.round((bytesUploaded / bytesTotal) * 100) : 0;
        const prev = fileInfo._lastTracePct || 0;
        if(prev < 100 && (pct >= prev + 10 || pct === 100)) {
          fileInfo._lastTracePct = pct;
          pushClientTrace(id, {
            endpoint: '/files/',
            phase: 'tus_upload_progress',
            checksum_sha256: fileInfo.checksum_sha256,
            source_relpath: fileInfo.source_relpath,
            bytes_uploaded: bytesUploaded,
            bytes_total: bytesTotal,
            percent: pct,
          });
        }
      },
      onError:(err)=>{
        const errMsg=String(err&&err.message?err.message:err);
        const statusMatch=errMsg.match(/\b([45]\d{2})\b/);
        const httpStatus=statusMatch?parseInt(statusMatch[1],10):null;
        let failure_code='tus_5xx',retryable=true;
        if(/no space left on device/i.test(errMsg)){failure_code='disk_full';}
        else if(httpStatus&&httpStatus>=400&&httpStatus<500){failure_code='tus_4xx';retryable=httpStatus!==409;}
        else if(!httpStatus&&!navigator.onLine){failure_code='network_error';}
        pushClientTrace(id, {
          endpoint: '/files/',
          phase: 'tus_upload_error',
          checksum_sha256: fileInfo.checksum_sha256,
          source_relpath: fileInfo.source_relpath,
          error: errMsg,
          http_status: httpStatus,
          failure_code,
          retryable,
          tus_retries_exhausted: true,
          upload_url: upload.url||null,
        });
        setUploadRowState(id,fileInfo.checksum_sha256,'failed',errMsg,{retryable,failure_code});
        resolve();
      },
      onSuccess:async()=>{
        let uploadId='';
        try {
          if (upload.url) {
            const u=new URL(upload.url, window.location.origin);
            const parts=u.pathname.split('/').filter(Boolean);
            uploadId = parts.length ? parts[parts.length - 1] : '';
          }
        } catch(e) {
          uploadId=upload.url?String(upload.url).split('/').pop():'';
        }
        pushClientTrace(id, {
          endpoint: '/files/',
          phase: 'tus_upload_success',
          checksum_sha256: fileInfo.checksum_sha256,
          source_relpath: fileInfo.source_relpath,
          upload_id: uploadId,
          upload_url: upload.url || null,
        });
        if(!uploadId) {
          pushClientTrace(id, {
            endpoint: '/files/',
            phase: 'tus_upload_id_missing',
            checksum_sha256: fileInfo.checksum_sha256,
            source_relpath: fileInfo.source_relpath,
            upload_url: upload.url || null,
            error: 'Could not extract upload_id from TUS URL — finalize will fail',
          });
        }
        try{
          pushClientTrace(id, {
            endpoint: 'import_manifest_upload_finalize.php',
            phase: 'finalize_request',
            job_id: jobId,
            checksum_sha256: fileInfo.checksum_sha256,
            upload_id: uploadId,
            source_relpath: fileInfo.source_relpath,
          });
          const r=await fetch('import_manifest_upload_finalize.php',{method:'POST',headers:{'Content-Type':'application/json'},body:JSON.stringify({job_id:jobId,upload_id:uploadId,checksum_sha256:fileInfo.checksum_sha256})});
          const d=await r.json().catch(()=>null);
          pushClientTrace(id, {
            endpoint: 'import_manifest_upload_finalize.php',
            phase: 'finalize_response',
            status_code: r.status,
            response_ok: r.ok,
            checksum_sha256: fileInfo.checksum_sha256,
            upload_id: uploadId,
            source_relpath: fileInfo.source_relpath,
            state: d && d.state !== undefined ? d.state : null,
            message: d && (d.message || d.error) ? (d.message || d.error) : null,
          });
          if(d && Array.isArray(d.trace)) mergeServerTrace(id, d.trace);
          if(r.ok&&d&&d.success){setUploadRowState(id,fileInfo.checksum_sha256,d.state||'db_done');}
          else{const fc=d&&d.failure_code||'finalize_error';const fr=d&&d.retryable!=null?d.retryable:true;setUploadRowState(id,fileInfo.checksum_sha256,'failed',(d&&d.message)||'finalize failed',{retryable:fr,failure_code:fc});}
        }catch(e){
          const fErrMsg=String(e&&e.message?e.message:e);
          pushClientTrace(id,{endpoint:'import_manifest_upload_finalize.php',phase:'finalize_fetch_error',checksum_sha256:fileInfo.checksum_sha256,source_relpath:fileInfo.source_relpath,upload_id:uploadId,error:fErrMsg,failure_code:'network_error',retryable:true});
          setUploadRowState(id,fileInfo.checksum_sha256,'failed',fErrMsg,{retryable:true,failure_code:'network_error'});
        }
        resolve();
      }
    });
    upload.start();
  });
}

// ── Recovery: jobs list ──────────────────────────────────────────────────────
async function refreshJobsUi(id){
  const mode=_S[id].mode;
  try{
    const r=await fetch('import_manifest_jobs.php?mode='+encodeURIComponent(mode)+'&limit=25');
    const d=await r.json().catch(()=>null);
    if(!(r.ok&&d&&d.success&&Array.isArray(d.jobs)))return;
    const sel=el(id+'-jobs-select');const btn=el(id+'-replay-btn');const rBtn=el(id+'-resume-upload-btn');
    if(!sel)return;
    sel.innerHTML='';
    if(!d.jobs.length){const o=document.createElement('option');o.disabled=o.selected=true;o.textContent='No saved jobs yet';sel.appendChild(o);if(btn)btn.disabled=true;return;}
    const ph=document.createElement('option');ph.disabled=ph.selected=true;ph.textContent='Select a job…';sel.appendChild(ph);
    for(const j of d.jobs){const o=document.createElement('option');o.value=j.job_id||'';o.textContent=(j.job_id||'')+'  '+(j.state||'').toUpperCase()+(j.item_count?' '+j.item_count+' items':'');sel.appendChild(o);}
    sel.onchange=()=>{if(btn)btn.disabled=!sel.value;if(rBtn)rBtn.disabled=!sel.value;};
    if(btn)btn.disabled=true;if(rBtn)rBtn.disabled=true;
    if(d.last_job)html(id+'-lastjob','Last job: '+escapeHtml(d.last_job.job_id||'')+' — '+escapeHtml((d.last_job.state||'').toUpperCase()));

    // Auto-show upload panel for recent ok jobs
    if(d.recent_jobs&&d.recent_jobs.length&&!_S[id].jobId){
      const latest=d.recent_jobs[0];
      if(latest&&latest.job_id){_S[id].jobId=String(latest.job_id);if(rBtn)rBtn.disabled=false;}
    }
  }catch(e){}
}

async function sectionReplay(id){
  const sel=el(id+'-jobs-select');
  const statusEl=el(id+'-replay-status');
  const jobId=sel&&sel.value?String(sel.value).trim():'';
  if(!jobId){html(id+'-replay-status','<div class="alert-err">Select a job first.</div>');return;}
  const mode=_S[id].mode;
  const btn=el(id+'-replay-btn');
  if(btn)btn.disabled=true;
  resetProgressLatch();
  html(id+'-replay-status','<div class="muted">Replaying job '+escapeHtml(jobId)+'\u2026</div>');
  try{
    const r=await fetch('import_manifest_replay.php',{method:'POST',headers:{'Content-Type':'application/json'},body:JSON.stringify({job_id:jobId})});
    const d=await r.json().catch(()=>null);
    const newId=d&&d.job_id?String(d.job_id):jobId;
    pollManifestJob(newId,statusEl,(state,data)=>{
      if(btn)btn.disabled=!sel.value;
      const msg=(data&&(data.message||data.error))||'';
      if(state==='ok')html(id+'-replay-status','<div class="alert-ok">Replay complete. '+escapeHtml(msg)+'</div>'+(data&&data.steps?renderImportStepsShared(data.steps, {showProgressBar: false, label: 'Steps:', statusIndentPx: 70}):''));
      else html(id+'-replay-status','<div class="alert-err">'+escapeHtml(msg||state)+'</div>'+(data&&data.steps?renderImportStepsShared(data.steps, {showProgressBar: false, label: 'Steps:', statusIndentPx: 70}):''));
      refreshJobsUi(id);
    });
  }catch(e){html(id+'-replay-status','<div class="alert-err">'+escapeHtml(String(e&&e.message?e.message:e))+'</div>');if(btn)btn.disabled=!sel.value;}
}

// ── Folder input change handlers ─────────────────────────────────────────────
['a','b'].forEach(id=>{
  const inp=el(id+'-folder');
  if(!inp)return;
  inp.addEventListener('change',()=>{
    const list=inp.files?Array.from(inp.files):[];
    const s=_S[id];
    s.folderKey=folderKeyFromFiles(list);
    s.scanState=buildScanState(list);
    html(id+'-preview',renderScanPreview(s.scanState));
    html(id+'-status','');
    const canRun=s.scanState&&s.scanState.supportedCount>0;
    el(id+'-scan-btn').disabled=!canRun;
    el(id+'-stop-btn').disabled=true;
    el(id+'-cache-btn').disabled=!(s.folderKey&&canRun);
    ensureUploadRows(id);
  });
});

// ── Init ─────────────────────────────────────────────────────────────────────
window.addEventListener('load',()=>{
  refreshJobsUi('a');
  refreshJobsUi('b');
  ensureUploadRows('a');
  ensureUploadRows('b');
  renderUploadDebug('a');
  renderUploadDebug('b');
});
</script>
</body>
</html>
