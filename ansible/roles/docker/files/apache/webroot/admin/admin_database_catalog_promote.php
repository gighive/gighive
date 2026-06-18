<?php declare(strict_types=1);

$user = $_SERVER['PHP_AUTH_USER'] ?? $_SERVER['REMOTE_USER'] ?? $_SERVER['REDIRECT_REMOTE_USER'] ?? null;
if ($user !== 'admin') { http_response_code(403); echo '<h1>Forbidden</h1>'; exit; }

$__json_env_array = function(string $key): array {
    $raw = getenv($key);
    if (!is_string($raw) || trim($raw) === '') return [];
    $decoded = json_decode($raw, true);
    return is_array($decoded) ? array_values(array_filter(array_map('strtolower', array_map('trim', $decoded)), 'strlen')) : [];
};

$tusRetryDelaysJson = getenv('TUS_CLIENT_RETRY_DELAYS_JSON') ?: '[0,1000,3000]';
$__tus_retry_delays = json_decode($tusRetryDelaysJson, true);
if (!is_array($__tus_retry_delays)) $__tus_retry_delays = [0, 1000, 3000];
$__tus_retry_delays_js = json_encode(array_values(array_map('intval', $__tus_retry_delays)));

$__tus_remove_fingerprint = filter_var(getenv('TUS_CLIENT_REMOVE_FINGERPRINT_ON_SUCCESS') ?: 'true', FILTER_VALIDATE_BOOLEAN) ? 'true' : 'false';
$__tus_parallel_uploads   = max(1, (int)(getenv('TUS_CLIENT_PARALLEL_UPLOADS') ?: '1'));
$__upload_trace_max       = max(50, (int)(getenv('UPLOAD_TRACE_MAX_CLIENT') ?: '400'));
?>
<!doctype html>
<html lang="en">
<head>
  <meta charset="utf-8"/>
  <meta name="viewport" content="width=device-width,initial-scale=1"/>
  <title>Promote Catalog to Upload</title>
  <style>
    :root { font-family: system-ui,-apple-system,Segoe UI,Roboto,sans-serif; }
    body  { margin:0; background:#0b1020; color:#e9eef7; }
    .wrap { max-width:920px; margin:2rem auto; padding:1rem; }
    .card { background:#121a33; border:1px solid #1d2a55; border-radius:16px; padding:1.5rem; position:relative; }
    button { padding:.65rem 1rem; border-radius:10px; border:1px solid #3b82f6; background:transparent; color:#e9eef7; cursor:pointer; font-size:.9rem; }
    button:hover:not(:disabled) { background:#1e40af; }
    button:disabled { cursor:not-allowed; opacity:.55; }
    button.danger  { border-color:#dc2626; }
    button.danger:hover:not(:disabled) { background:#991b1b; }
    button.success { border-color:#22c55e; }
    button.success:hover:not(:disabled) { background:#15803d; }
    .muted   { color:#a8b3cf; font-size:.9rem; }
    a        { color:#60a5fa; }
    .alert-ok  { background:#11331a; border:1px solid #1f7a3b; padding:.8rem 1rem; border-radius:10px; margin-bottom:.75rem; }
    .alert-err { background:#3b0d14; border:1px solid #b4232a; padding:.8rem 1rem; border-radius:10px; margin-bottom:.75rem; }
    .alert-warn { background:#3b2800; border:1px solid #b45309; padding:.8rem 1rem; border-radius:10px; margin-bottom:.75rem; }
    .alert-info { background:#0d2240; border:1px solid #1e40af; padding:.8rem 1rem; border-radius:10px; margin-bottom:.75rem; }
    .upload-row   { display:flex; align-items:center; gap:.5rem; flex-wrap:wrap; margin:.25rem 0; font-size:.85rem; }
    .upload-badge { display:inline-block; min-width:90px; font-size:.78rem; font-weight:700; padding:.15rem .45rem; border-radius:6px; text-align:center; }
    .badge-pending   { background:#1d2a55; color:#a8b3cf; }
    .badge-uploading { background:#1e40af; color:#fff; }
    .badge-done      { background:#11331a; color:#22c55e; }
    .badge-present   { background:#0d2240; color:#38bdf8; }
    .badge-wb-ok     { background:#0d2240; color:#4ade80; }
    .badge-failed    { background:#3b0d14; color:#ef4444; }
    .upload-summary  { margin:.5rem 0; font-size:.82rem; color:#a8b3cf; }
    .spinner { display:inline-block; width:16px; height:16px; border:2px solid #3b82f6; border-top-color:transparent; border-radius:50%; animation:spin .7s linear infinite; vertical-align:middle; margin-right:.4rem; }
    @keyframes spin { to { transform:rotate(360deg); } }
    .picker-box { border:2px dashed #33427a; border-radius:10px; padding:1.25rem; text-align:center; margin:.75rem 0; }
    .nav-links  { position:absolute; top:1.5rem; right:1.5rem; display:flex; flex-direction:column; gap:.4rem; align-items:flex-end; }
    .upload-rows-wrap { max-height:340px; overflow-y:auto; margin-top:.5rem; }
    .group-header { font-size:.82rem; font-weight:700; color:#a8b3cf; margin:.75rem 0 .25rem; border-bottom:1px solid #1d2a55; padding-bottom:.2rem; }
  </style>
  <script src="https://cdn.jsdelivr.net/npm/tus-js-client@4.1.0/dist/tus.min.js"></script>
  <link rel="stylesheet" href="/admin/assets/import_progress.css"/>
  <script src="/admin/assets/import_progress.js"></script>
</head>
<body>
<div class="wrap">
<div class="card">
  <div class="nav-links">
    <a href="/db/database_catalog.php"><button type="button" style="border-color:#a855f7;font-size:.8rem;padding:.4rem .8rem">← Catalog</button></a>
  </div>

  <h1 style="padding-right:180px;margin:0 0 .25rem">Promote Catalog to Upload</h1>
  <p class="muted" style="margin:0 0 1.25rem">Uploads all <strong>Selected</strong> supported catalog entries into GigHive using the existing import pipeline.</p>

  <div id="main-status" style="margin-bottom:.75rem"></div>
  <div id="main-content"></div>

  <div id="upload-section" style="display:none;margin-top:1.25rem">
    <h3 style="margin-bottom:.35rem">Upload Progress</h3>
    <div id="upload-summary" class="upload-summary"></div>
    <div id="upload-rows-wrap" class="upload-rows-wrap">
      <div id="upload-rows"></div>
    </div>
  </div>
</div>
</div>

<script>
'use strict';

// ── PHP-injected constants ─────────────────────────────────────────────────
const TUS_RETRY_DELAYS         = <?= $__tus_retry_delays_js ?>;
const TUS_REMOVE_FINGERPRINT   = <?= $__tus_remove_fingerprint ?>;
const TUS_PARALLEL_UPLOADS     = <?= $__tus_parallel_uploads ?>;
const UPLOAD_TRACE_MAX         = <?= $__upload_trace_max ?>;

// ── State ──────────────────────────────────────────────────────────────────
let _mf            = null;   // manifest response from catalog_promote_start.php
let _pickerIdx     = 0;      // index into _mf.source_roots currently being picked
let _pickedFiles   = {};     // { sourceRoot: File[] }
let _allUploadRows = {};     // { checksum_sha256: { state, source_relpath, file_name } }
let _abortCtl      = null;
let _hashingActive = false;
let _uploadTrace   = [];

// ── Utilities ──────────────────────────────────────────────────────────────
function esc(s)         { return String(s||'').replace(/[&<>"]/g,c=>({'&':'&amp;','<':'&lt;','>':'&gt;','"':'&quot;'}[c]||c)); }
function el(id)         { return document.getElementById(id); }
function html(id, h)    { const e=el(id); if(e) e.innerHTML=h; }
function formatBytes(n) { const v=Number(n)||0; if(v>=1073741824) return (v/1073741824).toFixed(2)+' GB'; if(v>=1048576) return (v/1048576).toFixed(2)+' MB'; if(v>=1024) return (v/1024).toFixed(2)+' KB'; return v+' B'; }
function formatElapsed(ms) { const s=Math.max(0,Math.floor((Number(ms)||0)/1000)); return Math.floor(s/60)+'m '+String(s%60).padStart(2,'0')+'s'; }
function nowIso() { return new Date().toISOString().replace('T',' ').slice(0,23); }

function setStatus(h) { html('main-status', h); }
function setContent(h) { html('main-content', h); }

// ── IndexedDB hash cache ───────────────────────────────────────────────────
const HC_DB='gighive_hash_cache_v1', HC_STORE='hashes';
function openHcDb(){return new Promise((res,rej)=>{const r=indexedDB.open(HC_DB,1);r.onupgradeneeded=()=>{if(!r.result.objectStoreNames.contains(HC_STORE))r.result.createObjectStore(HC_STORE,{keyPath:'k'})};r.onsuccess=()=>res(r.result);r.onerror=()=>rej(r.error)});}
function hcKey(fk,rp,sz,lm){return fk+'::'+rp+'::'+sz+'::'+lm;}
async function getCached(fk,rp,sz,lm){if(!('indexedDB'in window))return null;const db=await openHcDb();try{return await new Promise((res,rej)=>{const tx=db.transaction(HC_STORE,'readonly');const r=tx.objectStore(HC_STORE).get(hcKey(fk,rp,sz,lm));r.onsuccess=()=>res(r.result?r.result.sha256||null:null);r.onerror=()=>rej(r.error)});}finally{db.close();}}
async function putCached(fk,rp,sz,lm,sha256){if(!('indexedDB'in window))return;const db=await openHcDb();try{await new Promise((res,rej)=>{const tx=db.transaction(HC_STORE,'readwrite');const r=tx.objectStore(HC_STORE).put({k:hcKey(fk,rp,sz,lm),folderKey:fk,relpath:rp,sizeBytes:sz,lastModifiedMs:lm,sha256});r.onsuccess=()=>res();r.onerror=()=>rej(r.error)});}finally{db.close();}}

// ── SHA-256 worker (inline) ────────────────────────────────────────────────
function createSha256WorkerUrl(){const src=`function rotr(n,x){return(x>>>n)|(x<<(32-n));}function bytesToHex(u8){const h=[];for(let i=0;i<u8.length;i++)h.push(u8[i].toString(16).padStart(2,'0'));return h.join('');}function Sha256(){this._h=new Uint32Array([0x6a09e667,0xbb67ae85,0x3c6ef372,0xa54ff53a,0x510e527f,0x9b05688c,0x1f83d9ab,0x5be0cd19]);this._buf=new Uint8Array(64);this._bufLen=0;this._bytesHashed=0;this._w=new Uint32Array(64);}Sha256.prototype._k=new Uint32Array([0x428a2f98,0x71374491,0xb5c0fbcf,0xe9b5dba5,0x3956c25b,0x59f111f1,0x923f82a4,0xab1c5ed5,0xd807aa98,0x12835b01,0x243185be,0x550c7dc3,0x72be5d74,0x80deb1fe,0x9bdc06a7,0xc19bf174,0xe49b69c1,0xefbe4786,0x0fc19dc6,0x240ca1cc,0x2de92c6f,0x4a7484aa,0x5cb0a9dc,0x76f988da,0x983e5152,0xa831c66d,0xb00327c8,0xbf597fc7,0xc6e00bf3,0xd5a79147,0x06ca6351,0x14292967,0x27b70a85,0x2e1b2138,0x4d2c6dfc,0x53380d13,0x650a7354,0x766a0abb,0x81c2c92e,0x92722c85,0xa2bfe8a1,0xa81a664b,0xc24b8b70,0xc76c51a3,0xd192e819,0xd6990624,0xf40e3585,0x106aa070,0x19a4c116,0x1e376c08,0x2748774c,0x34b0bcb5,0x391c0cb3,0x4ed8aa4a,0x5b9cca4f,0x682e6ff3,0x748f82ee,0x78a5636f,0x84c87814,0x8cc70208,0x90befffa,0xa4506ceb,0xbef9a3f7,0xc67178f2]);Sha256.prototype._compress=function(c){const w=this._w;for(let i=0;i<16;i++){const j=i*4;w[i]=((c[j]<<24)|(c[j+1]<<16)|(c[j+2]<<8)|c[j+3])>>>0;}for(let i=16;i<64;i++){const s0=(rotr(7,w[i-15])^rotr(18,w[i-15])^(w[i-15]>>>3))>>>0;const s1=(rotr(17,w[i-2])^rotr(19,w[i-2])^(w[i-2]>>>10))>>>0;w[i]=(w[i-16]+s0+w[i-7]+s1)>>>0;}let a=this._h[0],b=this._h[1],cc=this._h[2],d=this._h[3],e=this._h[4],f=this._h[5],g=this._h[6],h=this._h[7];const k=this._k;for(let i=0;i<64;i++){const S1=(rotr(6,e)^rotr(11,e)^rotr(25,e))>>>0;const ch=((e&f)^(~e&g))>>>0;const t1=(h+S1+ch+k[i]+w[i])>>>0;const S0=(rotr(2,a)^rotr(13,a)^rotr(22,a))>>>0;const maj=((a&b)^(a&cc)^(b&cc))>>>0;const t2=(S0+maj)>>>0;h=g;g=f;f=e;e=(d+t1)>>>0;d=cc;cc=b;b=a;a=(t1+t2)>>>0;}this._h[0]=(this._h[0]+a)>>>0;this._h[1]=(this._h[1]+b)>>>0;this._h[2]=(this._h[2]+cc)>>>0;this._h[3]=(this._h[3]+d)>>>0;this._h[4]=(this._h[4]+e)>>>0;this._h[5]=(this._h[5]+f)>>>0;this._h[6]=(this._h[6]+g)>>>0;this._h[7]=(this._h[7]+h)>>>0;};Sha256.prototype.update=function(data){let pos=0;const len=data.length;this._bytesHashed+=len;while(pos<len){const take=Math.min(64-this._bufLen,len-pos);this._buf.set(data.subarray(pos,pos+take),this._bufLen);this._bufLen+=take;pos+=take;if(this._bufLen===64){this._compress(this._buf);this._bufLen=0;}}};Sha256.prototype.digest=function(){const totalBitsHi=Math.floor(this._bytesHashed/0x20000000)>>>0;const totalBitsLo=(this._bytesHashed<<3)>>>0;this._buf[this._bufLen++]=0x80;if(this._bufLen>56){while(this._bufLen<64)this._buf[this._bufLen++]=0;this._compress(this._buf);this._bufLen=0;}while(this._bufLen<56)this._buf[this._bufLen++]=0;this._buf[56]=(totalBitsHi>>>24)&0xff;this._buf[57]=(totalBitsHi>>>16)&0xff;this._buf[58]=(totalBitsHi>>>8)&0xff;this._buf[59]=totalBitsHi&0xff;this._buf[60]=(totalBitsLo>>>24)&0xff;this._buf[61]=(totalBitsLo>>>16)&0xff;this._buf[62]=(totalBitsLo>>>8)&0xff;this._buf[63]=totalBitsLo&0xff;this._compress(this._buf);const out=new Uint8Array(32);for(let i=0;i<8;i++){const v=this._h[i];out[i*4]=(v>>>24)&0xff;out[i*4+1]=(v>>>16)&0xff;out[i*4+2]=(v>>>8)&0xff;out[i*4+3]=v&0xff;}return out;};self.onmessage=async e=>{try{const file=e.data&&e.data.file;const cs=Number(e.data&&e.data.chunkSize)||(16*1024*1024);if(!file)throw new Error('No file');const total=Number(file.size)||0;const hasher=new Sha256();let offset=0;while(offset<total){const end=Math.min(total,offset+cs);const buf=await file.slice(offset,end).arrayBuffer();hasher.update(new Uint8Array(buf));offset=end;self.postMessage({ok:true,progress:{bytes:offset,total}});}const d=hasher.digest();self.postMessage({ok:true,sha256:bytesToHex(d),done:true});}catch(err){self.postMessage({ok:false,error:(err&&err.message)?err.message:String(err)});}};`;return URL.createObjectURL(new Blob([src],{type:'application/javascript'}));}
async function sha256Abortable(file, signal, onProgress) {
  const url=createSha256WorkerUrl(); const w=new Worker(url); let settled=false;
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

// ── Upload row helpers ─────────────────────────────────────────────────────
function uploadBadge(state) {
  const map={'pending':'badge-pending','uploading':'badge-uploading','already_present':'badge-present',
    'db_done':'badge-done','thumbnail_done':'badge-done','uploaded':'badge-done','wb_ok':'badge-wb-ok','failed':'badge-failed'};
  const labels={'pending':'Pending','uploading':'Uploading…','already_present':'Already Present',
    'db_done':'Done','thumbnail_done':'Done','uploaded':'Done','wb_ok':'Imported ✓','failed':'Failed'};
  const cls=map[state]||'badge-pending';
  return '<span class="upload-badge '+cls+'">'+esc(labels[state]||state)+'</span>';
}
function setRowState(cs, state) {
  if (_allUploadRows[cs]) _allUploadRows[cs].state = state;
  const rowEl = el('urow-'+cs);
  if (rowEl) { const spans=rowEl.querySelectorAll('span'); if(spans[0]) spans[0].outerHTML=uploadBadge(state); }
}
function renderUploadRows() {
  const rowsEl = el('upload-rows');
  const summEl = el('upload-summary');
  if (!rowsEl) return;
  const rows = Object.values(_allUploadRows);
  if (!rows.length) return;
  const terminal = f => ['db_done','thumbnail_done','uploaded','already_present','wb_ok'].includes(f.state);
  const done = rows.filter(terminal).length;
  const failed = rows.filter(f=>f.state==='failed').length;
  const pending = rows.filter(f=>f.state==='pending').length;
  const uploading = rows.filter(f=>f.state==='uploading').length;
  const pct = rows.length > 0 ? Math.round((done/rows.length)*100) : 0;
  if (summEl) summEl.textContent = 'Pending: '+pending+' | Uploading: '+uploading+' | Done: '+done+' | Failed: '+failed+' | '+pct+'% complete';
  let h='';
  for (const f of rows) {
    h += '<div class="upload-row" id="urow-'+esc(f.checksum_sha256)+'">'+uploadBadge(f.state)
       + '<span class="muted" style="word-break:break-all">'+esc(f.source_relpath)+'</span></div>';
  }
  rowsEl.innerHTML = h;
}

// ── pollManifestJob ────────────────────────────────────────────────────────
function pollManifestJob(jobId, onDone) {
  const start = Date.now();
  let stopped = false;
  const tick = async () => {
    if (stopped) return;
    try {
      const r = await fetch('import_manifest_status.php?job_id='+encodeURIComponent(jobId)+'&_t='+Date.now(), {cache:'no-store'});
      const d = await r.json().catch(()=>null);
      const state = (d&&d.state) ? String(d.state) : 'queued';
      const elapsed = formatElapsed(Date.now()-start);
      const etaText = (d&&d.steps) ? getImportProgressEtaText(d.steps, Date.now()-start) : '';
      setStatus('<div class="muted"><span class="spinner"></span>Job '+esc(jobId)+': '+esc(state)+' (elapsed: '+elapsed+(etaText?' — '+esc(etaText):'')+')</div>'
        + (d&&d.steps ? renderImportStepsShared(d.steps, {showProgressBar:false,label:'Steps:',statusIndentPx:70}) : ''));
      if (state==='ok'||state==='error'||state==='canceled') {
        stopped=true; onDone(state,d); return;
      }
    } catch(e) {
      setStatus('<div class="alert-err">Polling error: '+esc(String(e&&e.message?e.message:e))+'</div>');
    }
    setTimeout(tick, Date.now()-start < 15000 ? 1000 : 2500);
  };
  tick();
}

// ── writeBack ──────────────────────────────────────────────────────────────
async function writeBack(pathHash, checksum, uploadJobId) {
  try {
    const r = await fetch('catalog_promote_writeback.php', {
      method:'POST', headers:{'Content-Type':'application/json'},
      body: JSON.stringify({path_hash:pathHash, checksum_sha256:checksum, upload_job_id:uploadJobId}),
    });
    return await r.json().catch(()=>null);
  } catch(e) {
    return null;
  }
}

// ── uploadOneFile ──────────────────────────────────────────────────────────
async function uploadOneFile(fileInfo, localFile, jobId, relpathToPathHash) {
  setRowState(fileInfo.checksum_sha256, 'uploading');
  const metadata = {
    filename:        localFile.name,
    checksum_sha256: fileInfo.checksum_sha256,
    job_id:          jobId,
    filetype:        fileInfo.file_type,
  };
  await new Promise(resolve => {
    const upload = new tus.Upload(localFile, {
      endpoint:                 '/files/',
      retryDelays:              TUS_RETRY_DELAYS,
      removeFingerprintOnSuccess: TUS_REMOVE_FINGERPRINT,
      parallelUploads:          TUS_PARALLEL_UPLOADS,
      metadata,
      chunkSize: 8*1024*1024,
      onError: err => {
        setRowState(fileInfo.checksum_sha256, 'failed');
        resolve();
      },
      onSuccess: async () => {
        let uploadId = '';
        try {
          if (upload.url) {
            const parts = new URL(upload.url, window.location.origin).pathname.split('/').filter(Boolean);
            uploadId = parts.length ? parts[parts.length-1] : '';
          }
        } catch(e) { uploadId = upload.url ? String(upload.url).split('/').pop() : ''; }

        try {
          const fr = await fetch('import_manifest_upload_finalize.php', {
            method:'POST', headers:{'Content-Type':'application/json'},
            body: JSON.stringify({job_id:jobId, upload_id:uploadId, checksum_sha256:fileInfo.checksum_sha256}),
          });
          const fd = await fr.json().catch(()=>null);
          if (fr.ok && fd && fd.success) {
            setRowState(fileInfo.checksum_sha256, fd.state || 'db_done');
            const pathHash = relpathToPathHash[fileInfo.source_relpath];
            if (pathHash) {
              const wbr = await writeBack(pathHash, fileInfo.checksum_sha256, jobId);
              if (wbr && wbr.success) setRowState(fileInfo.checksum_sha256, 'wb_ok');
            }
          } else {
            setRowState(fileInfo.checksum_sha256, 'failed');
          }
        } catch(e) {
          setRowState(fileInfo.checksum_sha256, 'failed');
        }
        resolve();
      },
    });
    upload.start();
  });
}

// ── runGroup ───────────────────────────────────────────────────────────────
async function runGroup(groupIdx, groupCount, group, fileMap, relpathToPathHash) {
  const groupLabel = (groupCount > 1) ? ' (group '+( groupIdx+1)+'/'+groupCount+': '+esc(group.org_name)+')' : '';

  // 1. Prepare manifest
  setStatus('<div class="muted"><span class="spinner"></span>Preparing import job'+groupLabel+'…</div>');
  const prepItems = group.items.map(it => ({
    file_name:        it.file_name,
    source_relpath:   it.source_root + '/' + it.source_relpath,
    file_type:        it.file_type,
    event_date:       it.event_date,
    size_bytes:       it.size_bytes,
    checksum_sha256:  it.checksum_sha256,
  }));

  let jobId;
  const prepRes = await fetch('import_manifest_prepare.php', {
    method:'POST', headers:{'Content-Type':'application/json'},
    body: JSON.stringify({mode:'add', org_name:group.org_name, event_type:group.event_type, items:prepItems, duplicates:[]}),
  });
  const prepData = await prepRes.json().catch(()=>null);
  if (!(prepRes.ok && prepData && prepData.success && prepData.job_id)) {
    throw new Error('Manifest prepare failed: ' + ((prepData&&prepData.message)||prepRes.status));
  }
  jobId = String(prepData.job_id);

  // 2. Finalize (kicks off background worker)
  const finRes = await fetch('import_manifest_finalize.php', {
    method:'POST', headers:{'Content-Type':'application/json'},
    body: JSON.stringify({job_id:jobId, resolutions:[]}),
  });
  const finData = await finRes.json().catch(()=>null);
  if (finRes.status === 409 && finData && finData.job_id) {
    jobId = String(finData.job_id);
  } else if (!(finRes.ok && finData && finData.success)) {
    throw new Error('Manifest finalize failed: ' + ((finData&&finData.message)||finRes.status));
  }

  // 3. Poll until manifest import worker completes
  await new Promise((resolve, reject) => {
    pollManifestJob(jobId, (state, data) => {
      if (state === 'ok' && data && data.success !== false) resolve();
      else reject(new Error((data&&(data.message||data.error)) || 'Import job failed (state: '+state+')'));
    });
  });

  // 4. Upload start — get file list
  const startRes = await fetch('import_manifest_upload_start.php', {
    method:'POST', headers:{'Content-Type':'application/json'},
    body: JSON.stringify({job_id:jobId}),
  });
  const startData = await startRes.json().catch(()=>null);
  if (!(startRes.ok && startData && startData.success && Array.isArray(startData.files))) {
    throw new Error('Upload start failed: ' + ((startData&&startData.message)||startRes.status));
  }

  const files = startData.files;

  // Register rows in upload display
  for (const f of files) {
    _allUploadRows[f.checksum_sha256] = {
      checksum_sha256: f.checksum_sha256,
      source_relpath:  f.source_relpath,
      file_name:       f.file_name,
      file_type:       f.file_type,
      state:           f.state,
    };
  }
  el('upload-section').style.display = 'block';
  renderUploadRows();

  // 5. Process each file
  const pending  = files.filter(f => f.state === 'pending');
  const present  = files.filter(f => f.state === 'already_present');

  setStatus('<div class="muted"><span class="spinner"></span>Uploading files'+groupLabel+'…</div>');

  // Write-back for already_present files (asset in DB, just update catalog_entries)
  for (const f of present) {
    const pathHash = relpathToPathHash[f.source_relpath];
    if (pathHash) {
      const wbr = await writeBack(pathHash, f.checksum_sha256, jobId);
      if (wbr && wbr.success) setRowState(f.checksum_sha256, 'wb_ok');
    }
    renderUploadRows();
  }

  // TUS uploads for pending files (sequential or parallel per TUS_PARALLEL_UPLOADS)
  let taskIdx = 0;
  const uploadWorker = async () => {
    while (taskIdx < pending.length) {
      const fileInfo = pending[taskIdx++];
      if (!fileInfo) break;
      const localFile = fileMap.get(fileInfo.source_relpath);
      if (!localFile) {
        setRowState(fileInfo.checksum_sha256, 'failed');
        renderUploadRows();
        continue;
      }
      await uploadOneFile(fileInfo, localFile, jobId, relpathToPathHash);
      renderUploadRows();
    }
  };
  await Promise.all(Array.from({length: Math.min(TUS_PARALLEL_UPLOADS, pending.length||1)}, uploadWorker));
}

// ── Main flow: hash items then run groups ──────────────────────────────────
async function startHashAndUpload() {
  _hashingActive = true;
  _abortCtl = new AbortController();
  const signal = _abortCtl.signal;

  // Build flat fileMap: webkitRelativePath → File
  const fileMap = new Map();
  for (const files of Object.values(_pickedFiles)) {
    for (const f of files) {
      const rel = (f.webkitRelativePath && f.webkitRelativePath.trim()) ? f.webkitRelativePath : f.name;
      fileMap.set(rel, f);
    }
  }

  const items = _mf.items;
  const total = items.length;
  const runAt  = Date.now();

  setContent('');
  setStatus('<div class="muted"><span class="spinner"></span>Hashing 0 / '+total+' files…</div>');

  // Hash each item
  for (let i = 0; i < items.length; i++) {
    if (signal.aborted) { setStatus('<div class="alert-err">Aborted.</div>'); return; }
    const item = items[i];
    const fullRelpath = item.source_root + '/' + item.source_relpath;
    const localFile   = fileMap.get(fullRelpath);

    if (!localFile) {
      _hashingActive = false;
      setStatus('<div class="alert-err">File not found in selected folder: <code>'+esc(fullRelpath)+'</code><br>'
        +'Please ensure you selected the correct folder(s) and try again.<br>'
        +'<a href="/admin/admin_database_catalog_promote.php">Restart</a></div>');
      return;
    }

    const sz = Number(localFile.size)||0;
    const lm = Number(localFile.lastModified)||0;
    const fk = item.source_root;

    let cs = null;
    try { cs = await getCached(fk, fullRelpath, sz, lm); } catch(e) {}

    if (!cs) {
      setStatus('<div class="muted"><span class="spinner"></span>Hashing '+(i+1)+' / '+total+'… (elapsed: '+formatElapsed(Date.now()-runAt)+')</div>'
        +'<div class="muted" style="font-size:.8rem">'+esc(fullRelpath)+'</div>');
      try {
        cs = await sha256Abortable(localFile, signal, (bytes, tot) => {
          const pct = tot > 0 ? Math.floor(bytes/tot*100) : 0;
          setStatus('<div class="muted"><span class="spinner"></span>Hashing '+(i+1)+' / '+total+' — '+pct+'%…</div>'
            +'<div class="muted" style="font-size:.8rem">'+esc(fullRelpath)+'</div>');
        });
      } catch(e) {
        _hashingActive = false;
        if (e.name === 'AbortError') { setStatus('<div class="alert-err">Aborted.</div>'); return; }
        setStatus('<div class="alert-err">Hashing error: '+esc(String(e&&e.message?e.message:e))+'</div>');
        return;
      }
      try { await putCached(fk, fullRelpath, sz, lm, cs); } catch(e) {}
    }
    items[i] = {...item, checksum_sha256: cs};
  }
  _hashingActive = false;

  // Build relpathToPathHash map: fullRelpath → path_hash
  const relpathToPathHash = {};
  for (const item of items) {
    relpathToPathHash[item.source_root + '/' + item.source_relpath] = item.path_hash;
  }

  // Group items by (org_name + event_type)
  const groupMap = {};
  for (const item of items) {
    const key = (item.org_name||'') + '|||' + (item.event_type||'band');
    if (!groupMap[key]) groupMap[key] = {org_name:item.org_name, event_type:item.event_type||'band', items:[]};
    groupMap[key].items.push(item);
  }
  const groups = Object.values(groupMap);

  setStatus('<div class="muted"><span class="spinner"></span>Hashing complete. Starting '+groups.length+' import group(s)…</div>');

  try {
    resetProgressLatch();
    for (let gi = 0; gi < groups.length; gi++) {
      await runGroup(gi, groups.length, groups[gi], fileMap, relpathToPathHash);
    }
  } catch(e) {
    setStatus('<div class="alert-err">'+esc(String(e&&e.message?e.message:e))+'</div>'
      +'<p><a href="/admin/admin_database_catalog_promote.php">Restart</a> &mdash; <a href="/db/database_catalog.php">Back to Catalog</a></p>');
    return;
  }

  // Done
  const rows   = Object.values(_allUploadRows);
  const wbOk   = rows.filter(f=>f.state==='wb_ok').length;
  const present = rows.filter(f=>f.state==='already_present').length;
  const failed  = rows.filter(f=>f.state==='failed').length;

  setStatus('<div class="alert-ok"><strong>Promote complete!</strong> '
    + wbOk+' file(s) newly imported, '+present+' already present'
    + (failed > 0 ? ', <strong>'+failed+' failed</strong>' : '') + '.'
    + ' <a href="/db/database.php?view=librarian" target="_blank" rel="noopener">See Updated Database</a>'
    + '&emsp;<a href="/db/database_catalog.php">Back to Catalog</a></div>');
  setContent('');
}

// ── Picker UI ──────────────────────────────────────────────────────────────
function showPickerForIdx(idx) {
  const roots = _mf.source_roots;
  const expectedRoot = String(roots[idx]);
  const total = roots.length;
  const excludedNote = (_mf.excluded_count > 0)
    ? '<div class="alert-warn" style="margin-bottom:.5rem">⚠ '+_mf.excluded_count+' selected '
      +(_mf.excluded_count===1?'entry is':'entries are')+' unsupported and will not be uploaded.</div>'
    : '';

  setContent(excludedNote
    + '<div style="margin-bottom:.75rem">'
    + (total > 1 ? '<span class="muted">Folder '+( idx+1)+' of '+total+'</span><br/>' : '')
    + '<strong>Select folder: <code>'+esc(expectedRoot)+'</code></strong></div>'
    + '<div class="picker-box">'
    + '<p class="muted">Pick the folder named <strong>'+esc(expectedRoot)+'</strong> from your local machine.</p>'
    + '<input type="file" id="folder-input" webkitdirectory directory multiple style="display:none"/>'
    + '<button type="button" onclick="el(\'folder-input\').click()">Choose Folder</button>'
    + '<span id="picked-label" class="muted" style="display:block;margin-top:.5rem"></span>'
    + '</div>'
    + '<div id="picker-err" style="margin-top:.5rem"></div>'
    + '<div style="margin-top:.75rem;display:flex;gap:.5rem">'
    + (idx > 0 ? '<button type="button" onclick="showPickerForIdx('+(idx-1)+')">← Previous</button>' : '')
    + '</div>');

  const inp = el('folder-input');
  if (inp) {
    inp.addEventListener('change', () => {
      const files = Array.from(inp.files||[]);
      if (!files.length) return;
      const firstRel = files.find(f => f.webkitRelativePath && f.webkitRelativePath.trim());
      if (!firstRel) { html('picker-err','<div class="alert-err">Could not read folder structure.</div>'); return; }
      const pickedRoot    = firstRel.webkitRelativePath.split('/')[0].trim();
      const expectedTrim  = expectedRoot.trim();
      if (pickedRoot !== expectedTrim) {
        const dbg = pickedRoot === expectedRoot
          ? ' <small class="muted">(lengths match visually but differ: expected='+expectedTrim.length+' picked='+pickedRoot.length+')</small>'
          : ' <small class="muted">(expected len='+expectedTrim.length+' ['+Array.from(expectedTrim).map(c=>c.codePointAt(0).toString(16)).join(',')
            +'] picked len='+pickedRoot.length+' ['+Array.from(pickedRoot).map(c=>c.codePointAt(0).toString(16)).join(',')+'])</small>';
        html('picker-err','<div class="alert-err">Expected folder <strong>'+esc(expectedTrim)+'</strong> but you selected <strong>'+esc(pickedRoot)+'</strong>. Please pick the correct folder.'+dbg+'</div>');
        return;
      }
      html('picker-err','');
      html('picked-label', files.length+' files in <strong>'+esc(pickedRoot)+'</strong>');
      _pickedFiles[expectedRoot] = files;

      const nextIdx = idx + 1;
      if (nextIdx < roots.length) {
        setTimeout(() => showPickerForIdx(nextIdx), 300);
      } else {
        setContent('');
        startHashAndUpload();
      }
    });
  }
}

// ── Collision warning ──────────────────────────────────────────────────────
function showCollisionWarning() {
  const warnings = _mf.collision_warnings;
  let list = '';
  for (const w of warnings) list += '<li><code>'+esc(w)+'</code></li>';
  setContent(
    '<div class="alert-warn">'
    + '<strong>⚠ Source root name collision detected</strong><br>'
    + 'The following folder name(s) appear in more than one scan. Files from both scans may be included under the same folder name, which could cause unexpected matches:<ul style="margin:.4rem 0 0 1.25rem">'+list+'</ul>'
    + '</div>'
    + '<p class="muted">You can proceed and the upload will still work correctly as long as you pick each folder from your local machine when prompted. Or go back to the Catalog and resolve the duplicate scans first.</p>'
    + '<div style="display:flex;gap:.75rem;flex-wrap:wrap">'
    + '<button type="button" class="success" onclick="proceedFromCollision()">Proceed Anyway</button>'
    + '<a href="/db/database_catalog.php"><button type="button">Back to Catalog</button></a>'
    + '</div>'
  );
}
function proceedFromCollision() {
  setContent('');
  showPickerForIdx(0);
}

// ── loadManifest ───────────────────────────────────────────────────────────
async function loadManifest() {
  setStatus('<div class="muted"><span class="spinner"></span>Loading manifest from catalog…</div>');
  setContent('');
  try {
    const r = await fetch('catalog_promote_start.php', {method:'POST', headers:{'Content-Type':'application/json'}});
    const d = await r.json().catch(()=>null);

    if (!d) { setStatus('<div class="alert-err">Server returned an invalid response. Try reloading.</div>'); return; }

    if (!d.success && d.status === 'validation_error') {
      let rows = '';
      for (const e of (d.entries||[])) {
        rows += '<li>Entry #'+e.catalog_entry_id+' — <code>'+esc(e.file_name)+'</code>'
          +' (source: <code>'+esc(e.source_root)+'</code>) — missing: '+e.missing.join(', ')+'</li>';
      }
      setStatus('<div class="alert-err"><strong>Validation error:</strong> '+esc(d.message)
        +'<ul style="margin:.4rem 0 0 1.25rem">'+rows+'</ul>'
        +'<p style="margin:.5rem 0 0">Please <a href="/db/database_catalog.php">edit the catalog entries</a> to fill in <strong>org_name</strong> and <strong>event_date</strong> before promoting.</p></div>');
      return;
    }

    if (!d.success) {
      setStatus('<div class="alert-err">Failed to load manifest: '+esc(d.message||d.error||'Unknown error')+'</div>');
      return;
    }

    if (d.item_count === 0) {
      setStatus('<div class="alert-info">No selected supported entries found.'
        +' Go to <a href="/db/database_catalog.php">the Catalog</a>, set entries to <strong>Selected</strong>, then return here.</div>');
      return;
    }

    setStatus('');
    _mf = d;

    if (d.excluded_count > 0) {
      setStatus('<div class="alert-warn">⚠ '+d.excluded_count+' selected unsupported '
        +(d.excluded_count===1?'entry':'entries')+' will be excluded from this upload.</div>');
    }

    if (d.collision_warnings && d.collision_warnings.length > 0) {
      showCollisionWarning();
    } else {
      showPickerForIdx(0);
    }

  } catch(e) {
    setStatus('<div class="alert-err">Error loading manifest: '+esc(String(e&&e.message?e.message:e))+'</div>');
  }
}

// ── Init ───────────────────────────────────────────────────────────────────
window.addEventListener('beforeunload', e => {
  if (_hashingActive || Object.values(_allUploadRows).some(f=>f.state==='uploading'||f.state==='pending')) {
    e.preventDefault(); e.returnValue = '';
  }
});

window.addEventListener('load', loadManifest);
</script>
</body>
</html>
