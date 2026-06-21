(function (global) {
  'use strict';

  // Latch: step key → true once the 💯 badge has been shown.
  // Prevents stale poll responses from reverting a completed badge back to the heartbeat.
  var _latch = {};

  function resetProgressLatch() {
    _latch = {};
  }

  function _esc(s) {
    return String(s || '').replace(/[&<>"]/g, function (c) {
      return { '&': '&amp;', '<': '&lt;', '>': '&gt;', '"': '&quot;' }[c] || c;
    });
  }

  function _formatEtaFromMs(ms) {
    var totalSeconds = Math.max(0, Math.floor(Number(ms || 0) / 1000));
    var hours = Math.floor(totalSeconds / 3600);
    var minutes = Math.floor((totalSeconds % 3600) / 60);
    var seconds = totalSeconds % 60;

    if (hours > 0) {
      return hours + 'h ' + String(minutes).padStart(2, '0') + 'm ' + String(seconds).padStart(2, '0') + 's';
    }
    return minutes + 'm ' + String(seconds).padStart(2, '0') + 's';
  }

  function getImportProgressEtaText(steps, elapsedMs) {
    if (!Array.isArray(steps)) return '';

    var elapsed = Number(elapsedMs);
    if (!Number.isFinite(elapsed) || elapsed <= 0) return '';

    for (var i = 0; i < steps.length; i++) {
      var s = steps[i];
      var status = (s && s.status) ? String(s.status) : 'pending';
      var progress = (s && s.progress && typeof s.progress === 'object') ? s.progress : null;
      var processed = progress ? Number(progress.processed) : NaN;
      var total = progress ? Number(progress.total) : NaN;
      var valid = Number.isFinite(processed) && Number.isFinite(total) && total > 0;

      if (!valid) continue;
      if (status === 'error' || status === 'ok') continue;
      if (processed <= 0 || processed >= total) continue;

      var remaining = total - processed;
      var etaMs = (elapsed / processed) * remaining;
      if (!Number.isFinite(etaMs) || etaMs < 0) continue;

      return 'ETA: ' + _formatEtaFromMs(etaMs);
    }

    return '';
  }

  var _STEP_TO_TABLE = {
    'Load sessions':          'sessions',
    'Load musicians':         'musicians',
    'Load songs':             'songs',
    'Load files':             'files',
    'Load session_musicians': 'session_musicians',
    'Load session_songs':     'session_songs',
    'Load song_files':        'song_files'
  };

  /**
   * Render import steps with an animated heartbeat while active and a 💯 badge on completion.
   *
   * @param {Array}   steps
   * @param {Object}  [options]
   * @param {Object}  [options.tableCounts]        Map of table name → row count
   * @param {boolean} [options.showProgressBar]    Render a progress bar (default true)
   * @param {boolean} [options.showCompletedBadge] Show 💯 on completion (default true)
   * @param {string}  [options.label]              Section label (default 'Progress:')
   * @param {number}  [options.statusIndentPx]     Left-indent in px (default 72)
   */
  function renderImportStepsShared(steps, options) {
    if (!Array.isArray(steps)) return '';

    var opts    = options || {};
    var counts  = (opts.tableCounts && typeof opts.tableCounts === 'object') ? opts.tableCounts : null;
    var showBar = opts.showProgressBar !== false;
    var showBadgeOpt = opts.showCompletedBadge !== false;
    var label   = typeof opts.label === 'string' ? opts.label : 'Progress:';
    var indent  = typeof opts.statusIndentPx === 'number' ? opts.statusIndentPx : 72;
    var ipx     = indent + 'px';

    var h = '<div class="muted">' + _esc(label) + '</div><div style="margin-top:.5rem">';

    for (var i = 0; i < steps.length; i++) {
      var s      = steps[i];
      var status = (s && s.status)  ? String(s.status)  : 'pending';
      var name   = (s && s.name)    ? String(s.name)    : '';
      var msg    = (s && s.message) ? String(s.message) : '';

      var progress  = (s && s.progress && typeof s.progress === 'object') ? s.progress : null;
      var processed = progress ? Number(progress.processed) : NaN;
      var total     = progress ? Number(progress.total)     : NaN;
      var valid     = Number.isFinite(processed) && Number.isFinite(total) && total > 0;
      var pct       = valid ? Math.max(0, Math.min(100, Math.round((processed / total) * 100))) : 0;

      // Latch: once a step reaches completion keep the badge shown even if a
      // stale poll response arrives later with a lower processed count.
      var key = name || String(i);
      if (valid && status !== 'error' && (processed >= total || status === 'ok')) {
        _latch[key] = true;
      }
      var done = !!_latch[key];

      // Heartbeat: step is actively progressing, not yet done, not errored
      var showHeart = valid && !done && status !== 'ok' && status !== 'error' && processed < total;
      // 💯 badge: step completed (latched), not errored — error always wins
      var showHundo = showBadgeOpt && valid && status !== 'error' && done;

      var indicator = showHeart
        ? '<span class="progress-heartbeat" aria-hidden="true">&#x2665;</span>'
        : (showHundo ? '<span class="progress-complete-badge" aria-hidden="true">&#x1F4AF;</span>' : '');

      // Augment message with table row count when available
      if (counts && name) {
        var tbl = Object.prototype.hasOwnProperty.call(_STEP_TO_TABLE, name) ? _STEP_TO_TABLE[name] : null;
        if (tbl && Object.prototype.hasOwnProperty.call(counts, tbl)) {
          var cv = Number(counts[tbl]);
          if (Number.isFinite(cv) && msg) {
            msg = msg.replace(/\s*$/, '') + ': ' + cv;
          }
        }
      }

      // Preserve "PROGRESS METER:" prefix for progress messages on no-bar pages
      var displayMsg = (!showBar && msg && /^Processed /i.test(msg))
        ? 'PROGRESS METER: ' + msg
        : msg;

      var color = status === 'ok' ? '#22c55e' : (status === 'error' ? '#ef4444' : '#a8b3cf');

      // Progress bar block — only when showBar is true and there is progress data
      var barBlock = '';
      if (showBar && valid) {
        barBlock = '<div style="margin-left:' + ipx + ';margin-top:.35rem">'
          + '<div class="muted" style="margin-bottom:.2rem">'
          + processed + ' / ' + total + ' (' + pct + '%)' + indicator
          + '</div>'
          + '<div style="height:10px;border:1px solid #1d2a55;border-radius:999px;overflow:hidden;background:#0e1530">'
          + '<div style="height:10px;width:' + pct + '%;background:#22c55e"></div>'
          + '</div>'
          + '</div>';
      }

      // Inline indicator appended after the message on no-bar pages
      var inlineInd = (!showBar && valid) ? indicator : '';

      h += '<div style="margin:.25rem 0">'
        + '<span style="display:inline-block;min-width:' + ipx + ';color:' + color + '">'
        + status.toUpperCase()
        + '</span>'
        + '<span>' + _esc(name) + '</span>'
        + (displayMsg
            ? '<div class="muted" style="margin-left:' + ipx + ';white-space:pre-wrap">'
              + displayMsg.replace(/[&<>]/g, function (c) { return { '&': '&amp;', '<': '&lt;', '>': '&gt;' }[c]; })
              + inlineInd
              + '</div>'
            : '')
        + barBlock
        + '</div>';
    }

    return h + '</div>';
  }

  /**
   * Poll a job status endpoint and optionally render steps via renderImportStepsShared().
   *
   * @param {string}        jobId         Job ID returned by the start endpoint
   * @param {string}        statusUrl     URL to poll (job_id appended as ?job_id=...)
   * @param {Element|null}  stepsEl       DOM element to auto-render steps into (pass null to skip)
   * @param {Function}      onDone        Called with (state, data) when state is 'done' or 'error'
   * @param {number}        [intervalMs]  Poll interval in ms (default 1500)
   * @param {Object}        [renderOpts]  Options passed to renderImportStepsShared when stepsEl is set
   * @param {Function}      [onProgress]  Called with (data) on every successful poll response
   * @returns {{ stop: Function }}        Call stop() to halt polling externally
   */
  function pollJobStatus(jobId, statusUrl, stepsEl, onDone, intervalMs, renderOpts, onProgress) {
    var _interval   = (typeof intervalMs === 'number' && intervalMs > 0) ? intervalMs : 1500;
    var _renderOpts = (renderOpts && typeof renderOpts === 'object') ? renderOpts
                    : { showProgressBar: true, label: 'Progress:', statusIndentPx: 80 };
    var _timer      = null;
    var _stopped    = false;

    function _poll() {
      if (_stopped) return;
      fetch(statusUrl + '?job_id=' + encodeURIComponent(String(jobId)))
        .then(function (resp) {
          return resp.ok ? resp.json() : Promise.reject('HTTP ' + resp.status);
        })
        .then(function (data) {
          if (_stopped) return;
          if (stepsEl && data && Array.isArray(data.steps)) {
            stepsEl.innerHTML = renderImportStepsShared(data.steps, _renderOpts);
          }
          if (typeof onProgress === 'function') onProgress(data);
          var state = (data && typeof data.state === 'string') ? data.state : 'running';
          if (state === 'done' || state === 'error') {
            _stopped = true;
            if (typeof onDone === 'function') onDone(state, data);
          } else {
            _timer = setTimeout(_poll, _interval);
          }
        })
        .catch(function () {
          if (!_stopped) _timer = setTimeout(_poll, _interval);
        });
    }

    _timer = setTimeout(_poll, _interval);

    return {
      stop: function () {
        _stopped = true;
        if (_timer !== null) { clearTimeout(_timer); _timer = null; }
      }
    };
  }

  global.renderImportStepsShared = renderImportStepsShared;
  global.getImportProgressEtaText = getImportProgressEtaText;
  global.resetProgressLatch      = resetProgressLatch;
  global.pollJobStatus           = pollJobStatus;

})(window);
