/* Shared accessible interaction layer. Existing form names and PHP handlers are retained. */
(function () {
    'use strict';
    let activeModal = null;
    let returnFocus = null;
    const focusables = el => Array.from(el.querySelectorAll('button, a[href], input:not([type="hidden"]), select, textarea, [tabindex="0"]')).filter(x => !x.disabled && !x.hidden && x.getClientRects().length);
    function close(id) {
        const modal = typeof id === 'string' ? document.getElementById(id) : activeModal;
        if (!modal) return;
        modal.hidden = true;
        document.body.classList.remove('modal-open');
        activeModal = null;
        if (returnFocus && returnFocus.isConnected) returnFocus.focus();
    }
    function open(id) {
        const modal = document.getElementById(id);
        if (!modal) return;
        if (activeModal) close();
        returnFocus = document.activeElement;
        activeModal = modal;
        modal.hidden = false;
        document.body.classList.add('modal-open');
        const panel = modal.querySelector('.modal-panel');
        if (panel) (panel.querySelector('[data-initial-focus]') || focusables(panel)[0] || panel).focus();
    }
    window.TNTSUI = { open, close };
    document.addEventListener('DOMContentLoaded', function () {
        document.querySelectorAll('.modal-overlay').forEach((modal, index) => {
            modal.hidden = true;
            const panel = modal.firstElementChild;
            panel.classList.add('modal-panel');
            panel.setAttribute('role', 'dialog');
            panel.setAttribute('aria-modal', 'true');
            panel.tabIndex = -1;
            const heading = panel.querySelector('h3, h2');
            if (heading) {
                heading.id = heading.id || 'modal-title-' + index;
                panel.setAttribute('aria-labelledby', heading.id);
            }
            const dismiss = document.createElement('button');
            dismiss.type = 'button'; dismiss.className = 'modal-close';
            dismiss.setAttribute('aria-label', 'Close dialog'); dismiss.textContent = '×';
            dismiss.addEventListener('click', () => close(modal.id)); panel.prepend(dismiss);
            modal.addEventListener('click', event => { if (event.target === modal) close(modal.id); });
        });
        const bar = document.getElementById('sidebar');
        const menu = document.getElementById('menuBtn');
        const readPreference = key => { try { return localStorage.getItem(key); } catch (_) { return null; } };
        const writePreference = (key, value) => { try { localStorage.setItem(key, value); } catch (_) {} };
        function setCollapsed(collapsed) {
            if (!bar || !menu) return;
            document.body.classList.toggle('sidebar-collapsed', collapsed);
            menu.setAttribute('aria-expanded', String(!collapsed));
            menu.setAttribute('aria-label', collapsed ? 'Expand sidebar' : 'Collapse sidebar');
            menu.title = collapsed ? 'Expand sidebar' : 'Collapse sidebar';
            bar.inert = false;
            writePreference('tnts.sidebar.collapsed', String(collapsed));
        }
        if (menu) {
            setCollapsed(readPreference('tnts.sidebar.collapsed') === 'true');
            menu.addEventListener('click', () => setCollapsed(!document.body.classList.contains('sidebar-collapsed')));
        }
        // Accordion navigation: page-active groups open automatically; only a
        // direct click on a main heading makes that group permanently pinned.
        // Manual pins are stored separately from active-page state.
        const navGroups = Array.from(document.querySelectorAll('[data-nav-group]'));
        const navStates = new Map();
        const isHoverPreview = group => group.matches(':hover') && !document.body.classList.contains('sidebar-collapsed');
        function renderNavGroups() {
            navGroups.forEach(group => {
                const state = navStates.get(group);
                if (!state) return;
                const expanded = state.pinned || state.active || state.preview || isHoverPreview(group);
                group.open = expanded;
                group.classList.toggle('is-pinned', state.pinned);
                const summary = group.querySelector(':scope > summary');
                if (summary) {
                    summary.setAttribute('aria-expanded', String(expanded));
                    summary.setAttribute('aria-label', summary.textContent.trim() + (state.pinned ? ', pinned open' : ', not pinned'));
                    summary.title = state.pinned ? 'Pinned open — click to unpin' : 'Click to keep open';
                }
            });
        }
        navGroups.forEach(group => {
            const summary = group.querySelector(':scope > summary');
            if (!summary) return;
            const key = 'tnts.nav.manual.v2.' + (bar?.dataset.menuRole || 'general') + '.' + group.dataset.navGroup;
            const state = {
                key,
                pinned: readPreference(key) === 'true',
                active: group.classList.contains('has-active') || !!group.querySelector('.nav-submenu [aria-current="page"]'),
                preview: false
            };
            navStates.set(group, state);
            summary.addEventListener('click', event => {
                event.preventDefault();
                if (document.body.classList.contains('sidebar-collapsed')) setCollapsed(false);
                state.pinned = !state.pinned;
                writePreference(key, String(state.pinned));
                renderNavGroups();
            });
            // Submenu clicks change the active group on the destination page.
            // They intentionally do NOT pin the source group.
            group.addEventListener('pointerenter', event => {
                if (event.pointerType !== 'mouse') return;
                state.preview = true;
                renderNavGroups();
            });
            group.addEventListener('pointerleave', event => {
                if (event.pointerType !== 'mouse') return;
                state.preview = false;
                renderNavGroups();
            });
            group.addEventListener('focusin', () => { state.preview = true; renderNavGroups(); });
            group.addEventListener('focusout', event => {
                if (!group.contains(event.relatedTarget)) {
                    state.preview = false;
                    renderNavGroups();
                }
            });
        });
        renderNavGroups();
        // Account creation / editing: toggle the password field without
        // affecting its value, validation, or the server-side password hash.
        document.querySelectorAll('[data-toggle-password]').forEach(toggle => {
            const field = document.getElementById(toggle.dataset.togglePassword);
            if (!field) return;
            toggle.addEventListener('change', () => {
                field.type = toggle.checked ? 'text' : 'password';
            });
        });
        document.addEventListener('keydown', event => {
            if (event.key === 'Escape' && activeModal) close();
            const panel = activeModal ? activeModal.querySelector('.modal-panel') : null;
            if (event.key === 'Tab' && panel) {
                const items = focusables(panel); const first = items[0], last = items[items.length - 1];
                if (!first) { event.preventDefault(); panel.focus(); }
                else if (event.shiftKey && document.activeElement === first) { event.preventDefault(); last.focus(); }
                else if (!event.shiftKey && document.activeElement === last) { event.preventDefault(); first.focus(); }
            }
        });
        // Accessible, read-only detail views reuse the data already visible to this user.
        const details = document.createElement('div');
        details.id = 'recordDetailsModal'; details.className = 'modal-overlay'; details.hidden = true;
        details.innerHTML = '<section class="modal-panel" role="dialog" aria-modal="true" aria-labelledby="recordDetailsTitle" tabindex="-1"><button class="modal-close" type="button" aria-label="Close details">×</button><div class="eyebrow">Record overview</div><h2 id="recordDetailsTitle">Item details</h2><dl class="detail-list"></dl><button type="button" class="btn btn-primary details-done">Done</button></section>';
        document.body.append(details);
        details.querySelectorAll('button').forEach(button => button.addEventListener('click', () => close(details.id)));
        details.addEventListener('click', event => { if (event.target === details) close(details.id); });
        if (/\/item_maintenance\.php$/.test(location.pathname)) {
            document.querySelectorAll('main table').forEach(table => {
                const headers = Array.from(table.querySelectorAll('thead th')).map(th => th.textContent.trim());
                if (!headers.length) return;
                table.querySelectorAll('tbody tr').forEach(row => {
                    const cells = Array.from(row.children);
                    if (cells.length !== headers.length || cells.some(cell => cell.colSpan > 1)) return;
                    const button = document.createElement('button'); button.type = 'button'; button.className = 'btn btn-outline btn-sm record-details'; button.textContent = 'View details';
                    button.addEventListener('click', () => {
                        const dl = details.querySelector('dl'); dl.replaceChildren();
                        cells.forEach((cell, i) => {
                            const dt = document.createElement('dt'), dd = document.createElement('dd');
                            dt.textContent = headers[i];
                            const copy = cell.cloneNode(true); copy.querySelectorAll('button').forEach(b => b.remove()); dd.textContent = copy.textContent.trim();
                            dl.append(dt, dd);
                        });
                        open(details.id);
                    });
                    cells[0].append(document.createElement('br'), button);
                });
            });
        }
        // Wide record tables scroll within the desktop workspace.
        document.querySelectorAll('.app-page main table').forEach(table => {
            if (table.closest('.table-wrap')) return;
            const wrap = document.createElement('div'); wrap.className = 'table-wrap'; wrap.tabIndex = 0;
            wrap.setAttribute('role', 'region'); wrap.setAttribute('aria-label', 'Scrollable records table');
            table.before(wrap); wrap.append(table);
        });
        document.querySelectorAll('input, select, textarea').forEach((field, index) => {
            if (field.type === 'hidden' || field.id) return;
            const label = field.previousElementSibling;
            if (label && label.tagName === 'LABEL') { field.id = 'field-' + index; label.htmlFor = field.id; }
        });
    });
})();


/* Explicit table header sorting + workspace scroll preservation. */
(function () {
 'use strict';
 const store={get(k){try{return sessionStorage.getItem(k)}catch(e){return null}},set(k,v){try{sessionStorage.setItem(k,String(v))}catch(e){}}};
 const normalize = value => (value||'').replace(/\s+/g,' ').trim();
 const numeric = text => { const match=normalize(text).match(/^[-+]?(?:₱\s*)?([\d,]+(?:\.\d+)?)/); return match?Number(match[1].replace(/,/g,'')):null; };
 const dateValue = text => { const t=normalize(text);if(!/(\d{4}-\d{2}-\d{2}|[A-Za-z]{3}\s+\d{1,2},?\s+\d{4})/.test(t))return null;const date=Date.parse(t);return Number.isFinite(date)?date:null; };
 function compare(a,b){
   const na=numeric(a),nb=numeric(b);if(na!==null&&nb!==null)return na-nb;
   const da=dateValue(a),db=dateValue(b);if(da!==null&&db!==null)return da-db;
   return normalize(a).localeCompare(normalize(b),undefined,{numeric:true,sensitivity:'base'});
 }
 function bindSorting(){
  document.querySelectorAll('.tnts-sort-toggle').forEach(button=>{
   const table=button.closest('table'),th=button.closest('th');
   if(!table||!th||!table.tBodies.length)return;
   const body=table.tBodies[0],headers=Array.from(th.parentElement.children),index=headers.indexOf(th);
   // Capture original DOM order once, so the default state is truly restored.
   if(!body._tntsOriginalRows)body._tntsOriginalRows=Array.from(body.rows);
   const originals=body._tntsOriginalRows;
   function updateVisual(target,state){
    target.dataset.sortState=state;
    const icon=target.querySelector('.tnts-sort-icon');
    if(icon)icon.textContent=state==='asc'?'↑':state==='desc'?'↓':'−';
    const heading=normalize(target.querySelector('.tnts-sort-title')?.textContent||'column');
    const descriptions={none:'default order. Activate for ascending.',asc:'ascending. Activate for descending.',desc:'descending. Activate to restore default order.'};
    target.setAttribute('aria-label','Sort '+heading+': '+descriptions[state]);
    target.title='Sort '+heading+': '+(state==='none'?'default':state==='asc'?'ascending':'descending');
    target.setAttribute('aria-pressed',state==='none'?'false':'true');
   }
   button.addEventListener('click',()=>{
    const next=button.dataset.sortState==='none'?'asc':button.dataset.sortState==='asc'?'desc':'none';
    table.querySelectorAll('.tnts-sort-toggle').forEach(other=>updateVisual(other,'none'));
    headers.forEach(header=>header.removeAttribute('aria-sort'));
    const valid=originals.length>1&&originals.every(row=>row.cells.length===headers.length&&Array.from(row.cells).every(cell=>cell.colSpan===1));
    if(!valid)return;
    const rows=[...originals];
    if(next!=='none'){
     const direction=next==='asc'?1:-1;
     rows.sort((a,b)=>{
      const x=a.cells[index],y=b.cells[index];
      return direction*compare(x.dataset.sortValue||x.textContent,y.dataset.sortValue||y.textContent);
     });
     th.setAttribute('aria-sort',next==='asc'?'ascending':'descending');
    }
    rows.forEach(row=>body.appendChild(row));
    updateVisual(button,next);
   });
  });
 }
 document.addEventListener('DOMContentLoaded',()=>{
  bindSorting();
  const sidebar=document.getElementById('sidebar');
  if(sidebar){const k='tnts.sidebar.scroll.'+(sidebar.dataset.menuRole||'general'),saved=store.get(k);if(saved!==null){sidebar.scrollTop=Number(saved)||0;requestAnimationFrame(()=>sidebar.scrollTop=Number(saved)||0)}
   const save=()=>store.set(k,sidebar.scrollTop);sidebar.addEventListener('scroll',save,{passive:true});sidebar.querySelectorAll('a[href],summary').forEach(el=>el.addEventListener('click',save));window.addEventListener('pagehide',save);
  }
  const main=document.getElementById('pageContent'),key='tnts.content.scroll.'+location.pathname,saved=store.get(key);
  if(saved!==null){const y=Number(saved)||0;requestAnimationFrame(()=>{if(main&&main.scrollHeight>main.clientHeight)main.scrollTop=y;else window.scrollTo(0,y)})}
  const save=()=>store.set(key,main&&main.scrollHeight>main.clientHeight?main.scrollTop:window.scrollY);
  window.addEventListener('pagehide',save);window.addEventListener('scroll',save,{passive:true});if(main)main.addEventListener('scroll',save,{passive:true});
 });
})();
