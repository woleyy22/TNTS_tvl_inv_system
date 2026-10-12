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
        document.querySelectorAll('[data-nav-group]').forEach(group => {
            const key = 'tnts.nav.' + (bar ? bar.dataset.menuRole : '') + '.' + group.dataset.navGroup;
            const remembered = readPreference(key);
            if (!group.classList.contains('has-active') && remembered !== null) group.open = remembered === 'true';
            group.addEventListener('toggle', () => writePreference(key, String(group.open)));
            group.querySelector('summary').addEventListener('click', event => {
                if (document.body.classList.contains('sidebar-collapsed')) {
                    event.preventDefault(); setCollapsed(false); group.open = true;
                }
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
        if (/\/(inventory|item_maintenance)\.php$/.test(location.pathname)) {
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
