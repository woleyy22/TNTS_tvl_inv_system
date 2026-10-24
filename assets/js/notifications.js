/* TNTS Notification Center: UI only. Only the server controls recipients and record permissions. */
(() => {
  'use strict';
  const host = document.getElementById('tn-notify-shell');
  if (!host) return;
  const endpoint = host.dataset.endpoint;
  const csrf = host.dataset.csrf;
  const accountId = host.dataset.userId || 'unknown';
  const bell = document.getElementById('tn-bell');
  const badge = document.getElementById('tn-bell-count');
  const popover = document.getElementById('tn-notify-popover');
  const preview = document.getElementById('tn-notify-preview');
  const history = document.getElementById('tn-notify-history');
  const historyCount = document.getElementById('tn-history-count');
  const unreadOnly = document.getElementById('tn-history-unread');
  const prev = document.getElementById('tn-prev');
  const next = document.getElementById('tn-next');
  const pageText = document.getElementById('tn-page-text');
  let offset = 0;
  let requestInProgress = false;

  // Respect browser media policies: Web Audio only unlocks after a real user gesture.
  // Separate per-user keys prevent shared-browser logins from borrowing another account's baseline.
  const seenKey = 'tnts.notify.seen.v1.' + accountId;
  const soundKey = 'tnts.notify.sound.v1.' + accountId;
  const soundButton = document.getElementById('tn-sound-toggle');
  const audioStatus = document.getElementById('tn-audio-status');
  const getSaved = key => { try { return localStorage.getItem(key); } catch (_) { return null; } };
  const save = (key, value) => { try { localStorage.setItem(key, String(value)); } catch (_) {} };
  let soundEnabled = getSaved(soundKey) !== 'off';
  let audioContext = null;
  let audioUnlocked = false;
  let bellAnimation = null;
  let baselineLoaded = false;
  let mostRecentId = Number(getSaved(seenKey)) || 0;
  function updateSoundLabel() {
    if (!soundButton) return;
    soundButton.setAttribute('aria-label', soundEnabled ? 'Mute notification sounds' : 'Enable notification sounds');
    soundButton.title = soundEnabled ? 'Sound on — mute alerts' : 'Sound off — enable alerts';
    soundButton.setAttribute('aria-pressed', String(soundEnabled));
    soundButton.querySelector('.tn-sound-icon-on')?.toggleAttribute('hidden', !soundEnabled);
    soundButton.querySelector('.tn-sound-icon-off')?.toggleAttribute('hidden', soundEnabled);
  }
  updateSoundLabel();
  soundButton?.addEventListener('click', () => {
    soundEnabled = !soundEnabled;
    save(soundKey, soundEnabled ? 'on' : 'off');
    updateSoundLabel();
    setAudioStatus('');
    if (soundEnabled) unlockAudio(); // Toggle is an explicit user gesture.
  });
  // Arm Web Audio on a user's first real interaction. Browsers decide whether
  // that unlock is permitted; never generate audio on hover or bell click.
  // A new notification can then ring immediately during the next active poll.
  function unlockAudio() {
    if (!soundEnabled) return Promise.resolve(false);
    try {
      const Ctor = window.AudioContext || window.webkitAudioContext;
      if (!Ctor) return Promise.resolve(false);
      if (!audioContext) audioContext = new Ctor();
      return Promise.resolve(audioContext.resume()).then(() => {
        audioUnlocked = audioContext.state === 'running';
        return audioUnlocked;
      }).catch(() => false);
    } catch (_) { return Promise.resolve(false); }
  }
  function wiggleBell() {
    if (bellAnimation !== null) window.clearTimeout(bellAnimation);
    bell.classList.remove('tn-bell-new-alert');
    void bell.offsetWidth; // Restart motion for repeated clicks and new alerts.
    bell.classList.add('tn-bell-new-alert');
    bellAnimation = window.setTimeout(() => {
      bell.classList.remove('tn-bell-new-alert'); bellAnimation = null;
    }, 880);
  }
  // No audio is produced until a genuinely new alert arrives in this open tab.
  // Polling itself is not a trusted user gesture, so we only use an already
  // running AudioContext. If blocked, the visual alert still works.
  function setAudioStatus(message, isProblem = false) {
    if (!audioStatus) return;
    audioStatus.hidden = !message;
    audioStatus.textContent = message || '';
    audioStatus.classList.toggle('is-problem', !!isProblem);
  }
  // A modest two-note chime. This uses no remote sound files. The browser
  // may still prevent audio until TNTS has received a genuine user gesture.
  function playNewNotificationChime() {
    if (!soundEnabled || !audioContext || !audioUnlocked || audioContext.state !== 'running') return false;
    try {
      const now = audioContext.currentTime;
      for (const [frequency, delay, duration] of [[740, 0, 0.20], [988, 0.16, 0.26]]) {
        const envelope = audioContext.createGain();
        const onset = now + delay;
        envelope.gain.setValueAtTime(0.0001, onset);
        envelope.gain.exponentialRampToValueAtTime(0.11, onset + 0.025);
        envelope.gain.exponentialRampToValueAtTime(0.0001, onset + duration);
        envelope.connect(audioContext.destination);
        const tone = audioContext.createOscillator();
        tone.type = 'sine';
        tone.frequency.value = frequency;
        tone.connect(envelope);
        tone.start(onset);
        tone.stop(onset + duration);
      }
      return true;
    } catch (_) { return false; }
  }
  // Users may interact anywhere in TNTS before a notification arrives. Only
  // that interaction initializes audio; no sound plays during initialization.
  function armAudioOnce() {
    if (soundEnabled) unlockAudio();
  }
  document.addEventListener('pointerdown', armAudioOnce, {once: true, capture: true});
  document.addEventListener('keydown', armAudioOnce, {once: true, capture: true});
  function trackNewEntries(entries, allowSound = false) {
    const newest = entries.reduce((max, r) => Math.max(max, Number(r.notification_id)||0), 0);
    // First poll on each page load is a silent baseline, including after navigation/refresh.
    if (!baselineLoaded) {
      baselineLoaded = true;
      mostRecentId = Math.max(mostRecentId, newest);
      if (mostRecentId) save(seenKey, mostRecentId);
      return;
    }
    if (newest > mostRecentId) {
      mostRecentId = newest; save(seenKey, newest);
      wiggleBell();
      if (allowSound) playNewNotificationChime(); // Poll-only: never chime for opening the bell.
    }
  }

  // The school-photo header intentionally clips overflowing children. Move only
  // the floating panel outside that stacking/overflow context. Its bell stays
  // in the shared header, so this works on every page using includes/header.php.
  document.body.appendChild(popover);
  popover.classList.add('tn-notify-portal');
  let positionPending = false;
  function positionPopover() {
    if (popover.hidden) return;
    const rect = bell.getBoundingClientRect();
    if (rect.bottom < 0 || rect.top > window.innerHeight) { closePopover(); return; }
    const viewportWidth = document.documentElement.clientWidth;
    const width = Math.min(390, viewportWidth - 24);
    const left = Math.max(12, Math.min(rect.right - width, viewportWidth - width - 12));
    const below = window.innerHeight - rect.bottom - 12;
    const above = rect.top - 12;
    const placeAbove = below < 190 && above > below;
    const available = Math.max(110, (placeAbove ? above : below) - 10);
    popover.style.width = `${width}px`;
    popover.style.left = `${left}px`;
    popover.style.top = placeAbove ? 'auto' : `${Math.max(8, rect.bottom + 10)}px`;
    popover.style.bottom = placeAbove ? `${Math.max(8, window.innerHeight - rect.top + 10)}px` : 'auto';
    popover.style.maxHeight = `${Math.min(570, available)}px`;
  }
  function closePopover() {
    popover.hidden = true;
    bell.setAttribute('aria-expanded','false');
  }
  function requestPosition() {
    if (popover.hidden || positionPending) return;
    positionPending = true;
    window.requestAnimationFrame(() => {positionPending = false; positionPopover();});
  }
  window.addEventListener('resize',requestPosition,{passive:true});
  window.addEventListener('scroll',requestPosition,{passive:true,capture:true});

  const make = (tag, cls, text) => {
    const node = document.createElement(tag);
    if (cls) node.className = cls;
    if (text !== undefined) node.textContent = text;
    return node;
  };
  const time = (date) => {
    const dt = new Date(String(date || '').replace(' ', 'T'));
    return Number.isNaN(dt.getTime()) ? String(date || '') : dt.toLocaleString(undefined, {dateStyle:'medium',timeStyle:'short'});
  };
  const apiGet = async (mode, extra = {}) => {
    const p = new URLSearchParams({mode,...extra});
    const r = await fetch(endpoint + '?' + p, {credentials:'same-origin',headers:{Accept:'application/json'},cache:'no-store'});
    const body = await r.json();
    if (!r.ok || !body.ok) throw Error(body.error || 'Could not load notifications.');
    return body;
  };
  const apiPost = async (action, id) => {
    const p = new URLSearchParams({action,csrf});
    if (id != null) p.set('id', String(id));
    const r = await fetch(endpoint, {method:'POST',credentials:'same-origin',headers:{'Content-Type':'application/x-www-form-urlencoded','Accept':'application/json'},body:p});
    const body = await r.json();
    if (!r.ok || !body.ok) throw Error(body.error || 'Could not update notification.');
    return body;
  };
  function count(v) {
    const n = Math.max(0,Number(v)||0);
    badge.hidden = !n;
    badge.textContent = n > 99 ? '99+' : String(n);
    bell.setAttribute('aria-label',n ? `Notifications, ${n} unread` : 'Notifications, none unread');
  }
  function entry(record) {
    const outer = make('article','tn-notify-entry' + (record.read_at ? '' : ' is-unread'));
    const marker = make('span','tn-notify-entry-marker');marker.setAttribute('aria-hidden','true');
    const main = make('div','tn-notify-entry-main');
    const head = make('div','tn-notify-entry-head');
    head.append(make('strong','',record.title),make('time','',time(record.occurred_at)));
    main.append(head,make('p','',record.message));
    const actions = make('div','tn-notify-entry-actions');
    const view = make('a','tn-notify-view','View details →');
    view.href = record.href;
    view.addEventListener('click', (event) => {
      if (event.ctrlKey || event.metaKey || event.shiftKey || event.altKey || event.button !== 0) return;
      event.preventDefault();
      apiPost('read',record.notification_id).catch(()=>{}).finally(()=>window.location.assign(record.href));
    });
    actions.append(view);
    if (!record.read_at) {
      const mark = make('button','tn-notify-mark','Mark read');mark.type='button';
      mark.addEventListener('click',async()=>{
        mark.disabled=true;
        try { const result=await apiPost('read',record.notification_id);count(result.unread);outer.classList.remove('is-unread');mark.remove();if(history) loadHistory(); }
        catch(e){mark.disabled=false;announce(actions,e.message);}
      });
      actions.append(mark);
    }
    main.append(actions);
    outer.append(marker,main);
    return outer;
  }
  function announce(where,message) {const el=make('p','tn-notify-error',message);where.append(el);}
  async function loadBell(allowSound = false) {
    if (requestInProgress) return;
    requestInProgress=true;
    try {
      const response=await apiGet('bell');
      count(response.unread);
      trackNewEntries(response.entries, allowSound);
      preview.replaceChildren();
      if (!response.entries.length) preview.append(make('p','tn-notify-empty','No notifications yet.'));
      response.entries.forEach(n=>preview.append(entry(n)));
    } catch(e) { if(!preview.children.length || preview.querySelector('.tn-notify-empty')){ preview.replaceChildren();announce(preview,e.message);} }
    finally{requestInProgress=false;}
  }
  async function loadHistory() {
    if (!history) return;
    try {
      const result=await apiGet('history',{offset,unread:unreadOnly?.checked?'1':'0'});
      count(result.unread);
      historyCount.textContent=`${result.total} record(s)`;
      history.replaceChildren();
      if(!result.entries.length) history.append(make('p','tn-notify-empty','No notifications found.'));
      result.entries.forEach(r=>history.append(entry(r)));
      const perPage=30;
      prev.disabled=offset===0;
      next.disabled=offset+perPage>=result.total;
      pageText.textContent=result.total===0?'0 records':`${offset+1}–${Math.min(offset+perPage,result.total)} of ${result.total}`;
    } catch(e){history.replaceChildren();announce(history,e.message);}
  }
  async function readAll() {
    try {
      const response=await apiPost('read_all');count(response.unread);
      await loadBell();if(history)await loadHistory();
    }catch(e){if(history)announce(history,e.message);else announce(preview,e.message);}
  }
  async function clearReadNotifications() {
    // Intentionally never include unread notifications in this action.
    const buttons = [document.getElementById('tn-popover-clear-read')].filter(Boolean);
    buttons.forEach(button => { button.disabled = true; });
    try {
      await apiPost('clear_read');
      // Only the popover filters cleared_at. Full history is unaffected.
      await loadBell();
      if (history) await loadHistory();
    } catch (e) {
      announce(history || preview, e.message);
    } finally {
      buttons.forEach(button => { button.disabled = false; });
    }
  }
  // Read actions may remove their own button during the click; keep that
  // click inside the floating panel even if its target disappears mid-event.
  popover.addEventListener('click',(event)=>event.stopPropagation());
  bell.addEventListener('click', () => {
    wiggleBell(); // Restore satisfying click feedback with no hover audio.
    const open=popover.hidden;
    if (!open) { closePopover();return; }
    popover.hidden=false;
    bell.setAttribute('aria-expanded','true');
    positionPopover();
    loadBell();
  });
  document.addEventListener('click',(event)=>{
    // The popover is portaled to <body>, so it is no longer inside the bell host.
    if (!popover.hidden && !host.contains(event.target) && !popover.contains(event.target)) {
      closePopover();
    }
  });
  document.addEventListener('keydown',(event)=>{
    if(event.key==='Escape' && !popover.hidden){closePopover();bell.focus();}
  });
  document.getElementById('tn-popover-read-all')?.addEventListener('click',readAll);
  document.getElementById('tn-read-all')?.addEventListener('click',readAll);
  document.getElementById('tn-popover-clear-read')?.addEventListener('click',clearReadNotifications);
  unreadOnly?.addEventListener('change',()=>{offset=0;loadHistory();});
  prev?.addEventListener('click',()=>{offset=Math.max(0,offset-30);loadHistory();});
  next?.addEventListener('click',()=>{offset+=30;loadHistory();});
  loadBell();if(history)loadHistory();
  // Check approximately every 8 seconds while this TNTS tab is visible.
  // Avoid reloading the page; existing in-flight request guards prevent overlaps.
  window.setInterval(()=>{if(!document.hidden){loadBell(true);if(history)loadHistory();}},8000);
})();
