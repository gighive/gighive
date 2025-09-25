<?php /** @var array $media */ ?>
<!DOCTYPE html>
<html lang="en">
<head>
  <meta charset="utf-8">
  <meta name="viewport" content="width=device-width, initial-scale=1">
  <title>Playing Random Media</title>
  <style>
    body{font-family:system-ui,Arial,sans-serif;margin:20px;text-align:center}
    h1{margin-bottom:12px}
    p{color:#666}
    video,audio{margin-top:20px;max-width:100%}
    .meta{margin-top:8px}
    .btn{margin-top:18px;padding:8px 14px;font-weight:700;cursor:pointer}
    .controls{margin-top:16px;display:flex;gap:16px;justify-content:center;align-items:center}
    .toggle{display:flex;gap:6px;align-items:center}
    .notice{margin-top:12px;color:#a33}
  </style>
  <script>
    function getAutoNext(){
      try { return localStorage.getItem('random_auto_next') === '1'; } catch(e){ return true; }
    }
    function setAutoNext(on){
      try { localStorage.setItem('random_auto_next', on ? '1' : '0'); } catch(e){}
    }
    function markUserIntent(){ try { localStorage.setItem('random_user_intent', '1'); } catch(e){} }
    function clearUserIntent(){ try { localStorage.removeItem('random_user_intent'); } catch(e){} }
    function hasUserIntent(){ try { return localStorage.getItem('random_user_intent') === '1'; } catch(e){ return false; } }
    async function fetchNext(){
      markUserIntent();
      try {
        const res = await fetch('/db/singlesRandomPlayer.php?format=json', { cache: 'no-store' });
        if(!res.ok) throw new Error('HTTP '+res.status);
        const j = await res.json();
        if(!j || !j.url) throw new Error('Invalid JSON');
        updateMetadata(j);
        swapPlayer(j);
      } catch(err){ console.error('fetchNext failed', err); }
    }
    function handleEnded(){ if(getAutoNext()) fetchNext(); }
    function updateMetadata(j){
      try {
        document.getElementById('fileName').textContent = j.file_name || '';
        document.getElementById('urlText').textContent = j.url || '';
        document.getElementById('crewText').textContent = j.crew || '';
        document.getElementById('dateText').textContent = j.date || '';
      } catch(_){}
    }
    function swapPlayer(j){
      const wrap = document.getElementById('playerWrap');
      if(!wrap) return;
      const old = document.getElementById('playerEl');
      let el;
      if((j.type||'').toLowerCase()==='video' || /\.mp4($|\?)/i.test(j.url||'')){
        el = document.createElement('video');
        el.setAttribute('playsinline','');
      } else {
        el = document.createElement('audio');
      }
      el.id = 'playerEl';
      el.controls = true;
      el.autoplay = true;
      try { el.muted = false; el.volume = 1.0; } catch(_){}
      el.onended = handleEnded;
      // Use direct src for simplicity
      el.src = j.url;
      if(old){ wrap.replaceChild(el, old); } else { wrap.appendChild(el); }
      // Try to play unmuted; if blocked, show manual start
      el.play().catch(function(){
        const btn = document.getElementById('startPlayback');
        const note = document.getElementById('autoplayNotice');
        if(btn) btn.style.display = 'inline-block';
        if(note) note.style.display = 'block';
      });
    }
    function initControls(){
      const cb = document.getElementById('autoNext');
      if(!cb) return;
      cb.checked = getAutoNext();
      cb.addEventListener('change', function(){ setAutoNext(!!this.checked); });
    }
    function tryAutoplay(){
      const el = document.getElementById('playerEl');
      if(el && typeof el.play === 'function'){
        // Try unmuted first per request
        try { el.muted = false; el.volume = 1.0; } catch(_){}
        el.play().then(function(){
          clearUserIntent();
        }).catch(function(){
          // If unmuted autoplay is blocked, try muted video autoplay as a fallback
          if (el.tagName === 'VIDEO') {
            try { el.muted = true; } catch(_){}
            el.play().then(function(){
              // If user intent recorded (e.g., from clicking Next), unmute after start
              if (hasUserIntent()) {
                try { el.muted = false; el.volume = 1.0; } catch(_){}
                clearUserIntent();
              }
            }).catch(function(){
              // Still blocked: show manual start
              const btn = document.getElementById('startPlayback');
              const note = document.getElementById('autoplayNotice');
              if(btn) btn.style.display = 'inline-block';
              if(note) note.style.display = 'block';
            });
          } else {
            // Audio can't reliably autoplay unmuted; show manual start
            const btn = document.getElementById('startPlayback');
            const note = document.getElementById('autoplayNotice');
            if(btn) btn.style.display = 'inline-block';
            if(note) note.style.display = 'block';
          }
        });
      }
    }
    document.addEventListener('DOMContentLoaded', function(){ initControls(); tryAutoplay(); });
  </script>
</head>
<body>
<?php
  $file = (string)($media['file_name'] ?? '');
  $url  = (string)($media['url'] ?? '');
  $crew = (string)($media['crew'] ?? '');
  $date = (string)($media['date'] ?? '');
  $ext  = strtolower(pathinfo($file, PATHINFO_EXTENSION));
?>
  <h1>Now Playing: <span id="fileName"><?= htmlspecialchars($file, ENT_QUOTES) ?></span></h1>
  <p class="meta">URL: <strong id="urlText"><?= htmlspecialchars($url, ENT_QUOTES) ?></strong></p>
  <p class="meta">Crew: <strong id="crewText"><?= htmlspecialchars($crew, ENT_QUOTES) ?></strong></p>
  <p class="meta">Date: <strong id="dateText"><?= htmlspecialchars($date, ENT_QUOTES) ?></strong></p>

  <div id="playerWrap">
  <?php if ($ext === 'mp4'): ?>
    <video id="playerEl" controls autoplay playsinline onended="handleEnded()" src="<?= htmlspecialchars($url, ENT_QUOTES) ?>"></video>
  <?php elseif ($ext === 'mp3'): ?>
    <audio id="playerEl" controls autoplay onended="handleEnded()" src="<?= htmlspecialchars($url, ENT_QUOTES) ?>"></audio>
  <?php else: ?>
    <p>Unsupported media format: <?= htmlspecialchars($file, ENT_QUOTES) ?></p>
  <?php endif; ?>
  </div>

  <div class="controls">
    <label class="toggle"><input id="autoNext" type="checkbox"> Auto-play next</label>
    <button class="btn" onclick="fetchNext()">Play Another Random</button>
  </div>
  <div>
    <button id="startPlayback" class="btn" style="display:none;" onclick="(function(){var el=document.getElementById('playerEl'); if(el){ try{ el.muted=false; el.volume=1.0; }catch(_){} if(el.play){ el.play(); } this.style.display='none'; var n=document.getElementById('autoplayNotice'); if(n) n.style.display='none'; }})();">Start Playback</button>
    <div id="autoplayNotice" class="notice" style="display:none;">Autoplay was blocked by the browser. Click Start Playback once to enable audio.</div>
  </div>
</body>
</html>
