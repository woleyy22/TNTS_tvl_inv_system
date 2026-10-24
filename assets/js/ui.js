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
        // Phase 3.8.9 — preview on hover; inline expansion on click.
        // Existing PHP markup/role-filtered URLs remain the source of truth.
        const bar = document.getElementById('sidebar');
        const menu = document.getElementById('menuBtn');
        const mobileQuery = window.matchMedia('(max-width: 920px)');
        const readPreference = key => { try { return localStorage.getItem(key); } catch (_) { return null; } };
        const writePreference = (key, value) => { try { localStorage.setItem(key, value); } catch (_) {} };
        const groups = Array.from(document.querySelectorAll('#sidebar [data-nav-group]'));
        const pinKey = 'tnts.nav.pinned.v3.' + (bar?.dataset.menuRole || 'general');
        const collapseKey = 'tnts.sidebar.collapsed';
        const activeGroup = groups.find(g => g.classList.contains('has-active') || g.querySelector('.nav-submenu [aria-current="page"]'));
        const activeKey = activeGroup?.dataset.navGroup || null;
        const savedPin = readPreference(pinKey);
        let pinnedKey = groups.some(g => g.dataset.navGroup === savedPin) ? savedPin : null;
        let previewKey = null;
        let mobileKey = activeKey || pinnedKey;
        let mobileOpen = false;
        let drawerReturnFocus = null;
        let desktopCollapsed = readPreference(collapseKey) === 'true';
        let userClickedGroup = false;
        let hidePreviewHandle = null;
        const findGroup = key => groups.find(g => g.dataset.navGroup === key) || null;
        const getSummary = g => g?.querySelector(':scope > summary');
        const getSubmenu = g => g?.querySelector(':scope > .nav-submenu');
        const getTitle = g => getSummary(g)?.querySelector('.nav-group-label')?.textContent.trim() || 'Section';
        const isDesktop = () => !mobileQuery.matches;
        const flyout = document.createElement('div');
        flyout.id = 'tnts-nav-flyout';
        flyout.className = 'tnts-nav-flyout';
        flyout.setAttribute('aria-label', 'Preview section links');
        flyout.setAttribute('role', 'region');
        flyout.hidden = true;
        document.body.appendChild(flyout);
        const shade = document.createElement('button');
        shade.type = 'button';
        shade.className = 'tnts-mobile-shade';
        shade.setAttribute('aria-label', 'Close navigation menu');
        shade.hidden = true;
        document.body.appendChild(shade);
        function cancelPreviewTimer() {
            if (hidePreviewHandle !== null) { clearTimeout(hidePreviewHandle); hidePreviewHandle = null; }
        }
        function hidePreview() {
            cancelPreviewTimer();
            previewKey = null;
            flyout.hidden = true;
            flyout.replaceChildren();
            groups.forEach(g => g.classList.remove('tnts-nav-preview'));
        }
        function scheduleHidePreview() {
            cancelPreviewTimer();
            hidePreviewHandle = setTimeout(hidePreview, 240); // room to move mouse into the flyout
        }
        function positionFlyout() {
            if (flyout.hidden || !isDesktop() || !bar) return;
            const group = findGroup(previewKey);
            const trigger = getSummary(group)?.getBoundingClientRect();
            if (!trigger) { hidePreview(); return; }
            const side = bar.getBoundingClientRect();
            const gap = 8;
            const available = window.innerWidth - side.right - gap - 12;
            if (available < 160) { hidePreview(); return; }
            flyout.style.width = Math.min(270, available) + 'px';
            flyout.style.left = (side.right + gap) + 'px';
            flyout.style.maxHeight = Math.max(130, window.innerHeight - 24) + 'px';
            const height = Math.min(flyout.scrollHeight, window.innerHeight - 24);
            flyout.style.top = Math.max(12, Math.min(trigger.top - 5, window.innerHeight - height - 12)) + 'px';
        }
        function showPreview(key) {
            if (!isDesktop() || !bar || !findGroup(key)) return;
            cancelPreviewTimer();
            const group = findGroup(key);
            // Expanded groups already expose their original links inline.
            if (!desktopCollapsed && group.open) { hidePreview(); return; }
            if (previewKey === key && !flyout.hidden) return;
            previewKey = key;
            groups.forEach(g => g.classList.toggle('tnts-nav-preview', g === group));
            flyout.replaceChildren();
            const title = document.createElement('div');
            title.className = 'tnts-nav-flyout-title';
            title.textContent = getTitle(group);
            flyout.append(title, getSubmenu(group).cloneNode(true));
            flyout.hidden = false;
            positionFlyout();
        }
        function renderGroups() {
            groups.forEach(g => {
                const key = g.dataset.navGroup;
                let expanded;
                if (mobileQuery.matches) expanded = key === mobileKey;
                else if (desktopCollapsed) expanded = false;
                else if (userClickedGroup) expanded = key === pinnedKey;
                else expanded = key === pinnedKey || key === activeKey;
                g.open = !!expanded;
                g.classList.toggle('tnts-nav-selected', !!expanded);
                g.classList.toggle('is-pinned', key === pinnedKey);
                const summary = getSummary(g);
                if (summary) {
                    summary.setAttribute('aria-expanded', String(!!expanded));
                    summary.setAttribute('aria-controls', getSubmenu(g)?.id || flyout.id);
                    summary.title = expanded ? 'Collapse ' + getTitle(g) : 'Expand ' + getTitle(g);
                }
            });
            // Reopening an inline group should dismiss any hover-only preview.
            if (previewKey && findGroup(previewKey)?.open && !desktopCollapsed) hidePreview();
        }
        function setMobileOpen(open, restoreFocus = false) {
            const nextOpen = Boolean(open) && mobileQuery.matches;
            if (nextOpen && !mobileOpen) drawerReturnFocus = document.activeElement;
            mobileOpen = nextOpen;
            document.body.classList.toggle('tnts-mobile-nav-open', mobileOpen);
            if (bar) {
                bar.classList.toggle('open', mobileOpen);
                if (mobileQuery.matches) bar.inert = !mobileOpen;
            }
            shade.hidden = !mobileOpen;
            // On phones a drawer is modal navigation: prevent accidental background scrolling.
            document.documentElement.classList.toggle('tnts-drawer-lock', mobileOpen);
            if (mobileOpen && bar) {
                const first = bar.querySelector('a[href],summary,button');
                if (first) first.focus({preventScroll:true});
            }
            if (menu && mobileQuery.matches) {
                menu.setAttribute('aria-expanded', String(mobileOpen));
                menu.setAttribute('aria-label', mobileOpen ? 'Close navigation' : 'Open navigation');
                menu.title = mobileOpen ? 'Close navigation' : 'Open navigation';
            }
            if (restoreFocus && (drawerReturnFocus?.isConnected || menu)) (drawerReturnFocus?.isConnected ? drawerReturnFocus : menu).focus({preventScroll:true});
        }
        function applySidebarMode() {
            if (!bar || !menu) return;
            if (mobileQuery.matches) {
                document.body.classList.remove('sidebar-collapsed');
                bar.inert = !mobileOpen;
                setMobileOpen(mobileOpen);
            } else {
                setMobileOpen(false);
                bar.inert = false;
                document.body.classList.toggle('sidebar-collapsed', desktopCollapsed);
                menu.setAttribute('aria-expanded', String(!desktopCollapsed));
                menu.setAttribute('aria-label', desktopCollapsed ? 'Expand sidebar' : 'Collapse sidebar');
                menu.title = desktopCollapsed ? 'Expand sidebar' : 'Collapse sidebar';
            }
        }
        if (bar && menu) {
            groups.forEach((g, index) => {
                g.classList.add('tnts-nav-group');
                const summary = getSummary(g);
                const sub = getSubmenu(g);
                if (sub) sub.id = 'tnts-nav-submenu-' + index;
                if (!summary || !sub) return;
                summary.addEventListener('pointerenter', event => {
                    if (event.pointerType === 'mouse' || event.pointerType === 'pen') showPreview(g.dataset.navGroup);
                });
                summary.addEventListener('pointerleave', () => {
                    if (previewKey === g.dataset.navGroup) scheduleHidePreview();
                });
                summary.addEventListener('focus', () => { // Keyboard users can preview via ArrowDown.
                    cancelPreviewTimer();
                });
                summary.addEventListener('click', event => {
                    event.preventDefault();
                    hidePreview();
                    const key = g.dataset.navGroup;
                    if (mobileQuery.matches) mobileKey = mobileKey === key ? null : key;
                    else if (desktopCollapsed) {
                        // Icon-only mode: the submenu is hover/focus preview only.
                        // Do not modify the desktop pin or expand an inline section.
                        showPreview(key);
                        return;
                    } else {
                        pinnedKey = pinnedKey === key ? null : key;
                        writePreference(pinKey, pinnedKey || '');
                        userClickedGroup = true;
                    }
                    renderGroups();
                });
                summary.addEventListener('keydown', event => {
                    if (event.key === 'ArrowDown' && isDesktop()) {
                        event.preventDefault();
                        if (g.open) sub.querySelector('a[href], summary')?.focus();
                        else {
                            showPreview(g.dataset.navGroup);
                            flyout.querySelector('a[href],summary')?.focus();
                        }
                    }
                });
            });
            flyout.addEventListener('pointerenter', cancelPreviewTimer);
            flyout.addEventListener('pointerleave', scheduleHidePreview);
            flyout.addEventListener('focusin', cancelPreviewTimer);
            flyout.addEventListener('focusout', event => {
                if (!flyout.contains(event.relatedTarget)) scheduleHidePreview();
            });
            bar.classList.add('tnts-nav-ready');
            applySidebarMode();
            renderGroups();
            menu.addEventListener('click', () => {
                hidePreview();
                if (mobileQuery.matches) setMobileOpen(!mobileOpen);
                else {
                    desktopCollapsed = !desktopCollapsed;
                    writePreference(collapseKey, String(desktopCollapsed));
                    applySidebarMode();
                    renderGroups();
                }
            });
            shade.addEventListener('click', () => setMobileOpen(false, true));
            document.addEventListener('pointerdown', event => {
                if (mobileQuery.matches || flyout.hidden) return;
                if (!flyout.contains(event.target) && !bar.contains(event.target)) hidePreview();
            });
            document.addEventListener('keydown', event => {
                if (mobileQuery.matches && mobileOpen && event.key === 'Tab' && !activeModal && bar) {
                    const items = focusables(bar);
                    if (items.length) {
                        const first = items[0], last = items[items.length-1];
                        if (event.shiftKey && document.activeElement === first) {event.preventDefault();last.focus();}
                        else if (!event.shiftKey && document.activeElement === last) {event.preventDefault();first.focus();}
                    }
                }
                if (event.key !== 'Escape' || activeModal) return;
                if (mobileQuery.matches && mobileOpen) setMobileOpen(false, true);
                else if (!flyout.hidden) {
                    const originalTrigger = getSummary(findGroup(previewKey));
                    hidePreview();
                    originalTrigger?.focus();
                }
            });
            const modeChange = () => {
                hidePreview();
                userClickedGroup = false;
                mobileKey = activeKey || pinnedKey;
                applySidebarMode();
                renderGroups();
            };
            if (mobileQuery.addEventListener) mobileQuery.addEventListener('change', modeChange);
            else mobileQuery.addListener(modeChange);
            // Tab-switch/BFCache/devtools device emulation can restore a stale
            // desktop body class without firing matchMedia's 'change' event.
            // Reconcile with CSS viewport whenever the document is resumed.
            const resyncWorkspace = (dismissDrawer = false) => {
                if (!bar || !menu) return;
                if (mobileQuery.matches) {
                    document.body.classList.remove('sidebar-collapsed');
                    if (dismissDrawer) setMobileOpen(false);
                }
                applySidebarMode();
                renderGroups();
                positionFlyout();
            };
            window.addEventListener('pageshow', () => resyncWorkspace(true));
            document.addEventListener('visibilitychange', () => {
                if (!document.hidden) resyncWorkspace(true);
            });
            window.addEventListener('focus', () => resyncWorkspace(true));
            window.addEventListener('orientationchange', () => resyncWorkspace(true));
            window.addEventListener('resize', () => resyncWorkspace(), { passive: true });
            window.visualViewport?.addEventListener('resize', () => resyncWorkspace(), { passive: true });
            window.addEventListener('resize', positionFlyout, { passive: true });
            bar.addEventListener('scroll', positionFlyout, { passive: true });
            window.addEventListener('scroll', positionFlyout, { passive: true });
        }
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
            if (table.closest('.table-wrap, .tnts-admin-table-wrap, .tnts-grid-scroll')) return;
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


/* Phase 3.8.28 — responsive presentation only.
   Existing table rows, IDs, buttons, inputs and data attributes are retained:
   changing display at mobile widths must never create a second form value or
   detach a detail row from its parent. All server-side logic stays unchanged. */
(() => {
  'use strict';
  const attach = () => {
    const configurations = [
      ['#p34InventoryTable', 'inventory'],
      ['#p6-items', 'borrow-items'],
      ['#p6-records', 'borrow-records'],
      ['.tnts-data-table[data-grid-table]', 'receiving'],
      ['.p3817-iar-table', 'iar-inspection'],
      ['.card.legacy-030 table.legacy-031', 'delivery-items'],
      ['.p51-scroll table', 'transfer-requests'],
      ['.p3817-iar-table', 'iar-inspection'],
      ['.t18-history', 'transfer-history'],
      ['#p36Items', 'ics-issue'],
      ['.ics12-list', 'ics-records'],
      ['.ics31-table', 'ics-return']
    ];
    configurations.forEach(([selector, kind]) => {
      document.querySelectorAll(selector).forEach(table => {
        if (table.dataset.tntsMobileReady === '1') return;
        let th = [...table.querySelectorAll('thead tr:first-child > th')];
        // Older receiving-detail tables put a TH-only row directly in TBODY.
        // Keep that header row separate rather than treating it as an item.
        let implicitHeader = null;
        if (!th.length && table.rows.length && [...table.rows[0].cells].every(cell => cell.tagName === 'TH')) {
          implicitHeader = table.rows[0];
          th = [...implicitHeader.cells];
          implicitHeader.classList.add('tnts-m28-header-row');
        }
        if (!th.length) return;
        table.dataset.tntsMobileReady = '1';
        table.classList.add('tnts-m28-cards');
        table.dataset.tntsMobileKind = kind;
        const labels = th.map(head => {
          const clone = head.cloneNode(true);
          clone.querySelectorAll('.p34-indicator,.p6ui-sort-indicator,.tnts-grid-indicator,.t18-sort-indicator').forEach(el => el.remove());
          return (clone.textContent || '').replace(/[–↑↓−]+\s*$/, '').replace(/\s+/g, ' ').trim();
        });
        // The data label belongs to the existing physical cell, so all input
        // names and dynamic detail toggles continue to work untouched.
        [...table.tBodies].forEach(body => [...body.rows].forEach(row => {
          if (row === implicitHeader) return;
          if (row.cells.length === 1 && row.cells[0].colSpan > 1) {
            row.classList.add('tnts-m28-details');
            return;
          }
          [...row.cells].forEach((td, i) => {
            td.dataset.tntsMobileLabel = labels[i] || 'Details';
          });
          row.classList.add('tnts-m28-record');
        }));
        // Table-header sort actions would be hidden under card presentation.
        // Provide a mobile equivalent backed by the ORIGINAL existing controls.
        const sortButtons = th.map((h,i) => ({label:labels[i],button:h.querySelector('button[data-grid-sort],button.p6-sort,button[data-t18-sort]'),link:h.querySelector('a.ics12-sort')})).filter(o => o.button || o.link);
        if (!sortButtons.length) return;
        const holder = document.createElement('label');
        holder.className = 'tnts-m28-sort';
        const name = document.createElement('span'); name.textContent = 'Sort records';
        const select = document.createElement('select');
        select.setAttribute('aria-label', 'Mobile record sorting');
        const defaultOption = document.createElement('option');
        defaultOption.value = ''; defaultOption.textContent = 'Choose sort';
        select.append(defaultOption);
        sortButtons.forEach((o, i) => {
          const directions = o.link ? ['default'] : ['asc','desc'];
          directions.forEach(direction => {
            const option = document.createElement('option');
            option.value = i + ':' + direction;
            option.textContent = o.label + (direction === 'asc' ? ' ↑' : direction === 'desc' ? ' ↓' : '');
            select.append(option);
          });
        });
        select.addEventListener('change', () => {
          const [key, target] = select.value.split(':');
          const item = sortButtons[Number(key)];
          if (!item || !select.value) return;
          if (item.link) { window.location.assign(item.link.href); return; }
          const button = item.button;
          // Up to 3 cycles are enough for existing tri-state controls. Avoid
          // bypassing module pagination/filters or manually reordering rows.
          for (let attempts=0;attempts<3;attempts++) {
            const state = button.dataset.sortDirection || button.dataset.direction || button.dataset.sortState || null;
            const symbol = button.querySelector('[data-grid-indicator],.p6ui-sort-indicator,.t18-sort-indicator')?.textContent?.trim();
            const effective = state === 'asc' || symbol === '↑' ? 'asc' : state === 'desc' || symbol === '↓' ? 'desc' : 'none';
            if (effective === target) break;
            button.click();
          }
        });
        holder.append(name, select);
        const parent = table.parentElement;
        if (parent) parent.insertBefore(holder, table);
      });
    });
  };
  if (document.readyState === 'loading') document.addEventListener('DOMContentLoaded', attach);
  else attach();
})();


/* TNTS Phase 3.8.29 — Mobile administrative records and reports.
 * Add labels to the ORIGINAL table cells; preserve all PHP forms, sorting,
 * paging, approval actions and print links. Desktop remains unchanged.
 */
(() => {
  'use strict';
  function annotate() {
    const file = window.location.pathname.split('/').pop().toLowerCase();
    const pageType = {'history.php':'history','courses.php':'tracks',
      'positions_offices.php':'positions','units.php':'units'}[file];
    if (pageType) document.body.classList.add('tnts-m29-'+pageType);
    const tables = [
      ['#accounts-list', 'accounts'],
      ['#registration-review-table', 'registration'],
      ['#personnel-review-table', 'personnel'],
      ['.legacy-030 table.legacy-128', 'reports'],
      ['.legacy-030 table.legacy-133', 'reports'],
      ['body.tnts-m29-history .card .table-wrap > table.table', 'history'],
      ['body.tnts-m29-tracks .card .table-wrap > table.table', 'tracks'],
      ['body.tnts-m29-positions .card .table-wrap > table.table', 'positions'],
      ['body.tnts-m29-units .tnts-admin-table[data-admin-table]', 'units']
    ];
    tables.forEach(([selector, kind]) => {
      document.querySelectorAll(selector).forEach(table => {
        if (table.dataset.tntsM29Ready) return;
        const th = Array.from(table.querySelectorAll('thead tr:first-child > th'));
        if (!th.length) return;
        table.dataset.tntsM29Ready = '1';
        table.classList.add('tnts-m29-table');
        table.dataset.tntsM29Kind = kind;
        const labels = th.map(head => (head.textContent || '').replace(/[–↑↓−]+\s*$/, '').replace(/\s+/g, ' ').trim());
        Array.from(table.tBodies).forEach(body => Array.from(body.rows).forEach(row => {
          if (row.classList.contains('p451-detail-row') || row.classList.contains('tnts-review-detail')) {
            row.classList.add('tnts-m29-expanded');
            return;
          }
          if (row.cells.length === 1 && row.cells[0].colSpan > 1) {
            row.classList.add('tnts-m29-wide-row');
            return;
          }
          row.classList.add('tnts-m29-item');
          Array.from(row.cells).forEach((cell, i) => {
            cell.dataset.tntsM29Label = labels[i] || 'Details';
          });
        }));
      });
    });
    if (['accounts.php','approvals.php','personnel_reviews.php','reports.php',
         'history.php','transfer_audit.php','batch_reconciliation.php',
         'courses.php','positions_offices.php','units.php','inventory_categories.php',
         'inventory_batches.php','central_stock_conditions.php'].includes(file)) {
      document.body.classList.add('tnts-m29-administration');
    }
  }
  if (document.readyState === 'loading') document.addEventListener('DOMContentLoaded', annotate);
  else annotate();
})();
