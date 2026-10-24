/* Phase 7.2b: one accessible searchable Accountable Equipment list opens the existing maintenance form. */
(function () {
  'use strict';
  function init() {
    const list = document.getElementById('p72bItemList');
    const search = document.getElementById('p72bItemSearch');
    const condition = document.getElementById('p72bConditionFilter');
    const order = document.getElementById('p72bItemSort');
    const count = document.getElementById('p72bItemResults');
    const empty = document.getElementById('p72bItemEmpty');
    const previous = document.getElementById('p72bItemPrev');
    const next = document.getElementById('p72bItemNext');
    const pageLabel = document.getElementById('p72bPageIndicator');
    if (!list || !search || !condition || !order) return;
    const entries = Array.from(list.querySelectorAll('.p72c-record'));
    const size = document.getElementById('p72cPageSize');
    function pageSize() { return Number(size?.value) || 10; }
    let page = 0;
    function candidates() {
      const needle = search.value.trim().toLocaleLowerCase();
      const state = condition.value;
      const matches = entries.filter(card => {
        const textMatch = !needle || (card.dataset.search || '').includes(needle);
        const stateMatch = state === 'all' || Number(card.dataset[state] || 0) > 0;
        return textMatch && stateMatch;
      });
      if (order.value === 'az' || order.value === 'za') {
        matches.sort((a,b) => {
          const byName = (a.dataset.name || '').localeCompare(b.dataset.name || '');
          return (order.value === 'za' ? -byName : byName) || Number(a.dataset.index)-Number(b.dataset.index);
        });
      } else {
        matches.sort((a,b) => Number(a.dataset.index)-Number(b.dataset.index));
      }
      return matches;
    }
    function render() {
      const matching = candidates();
      const totalPages = Math.max(1, Math.ceil(matching.length / pageSize()));
      page = Math.max(0, Math.min(page, totalPages-1));
      // Keep the server-rendered cards and buttons. Sorting never clones form controls.
      const from = page * pageSize();
      const showing = matching.slice(from, from + pageSize());
      const visible = new Set(showing);
      for (const card of matching) list.append(card);
      for (const card of entries) card.hidden = !visible.has(card);
      if (count) count.textContent = matching.length + ' matching item(s)' + (matching.length ? ' · showing ' + (from+1) + '–' + (from+showing.length) : '');
      if (empty) empty.hidden = matching.length !== 0 || entries.length === 0;
      if (previous) previous.disabled = !matching.length || page === 0;
      if (next) next.disabled = !matching.length || page === totalPages-1;
      if (pageLabel) pageLabel.textContent = matching.length ? 'Page '+(page+1)+' of '+totalPages : 'No matches';
    }
    [search,condition,order].forEach(el => el.addEventListener(el === search ? 'input' : 'change',() => { page=0;render(); }));
    size?.addEventListener('change',() => { page=0;render(); });
    previous?.addEventListener('click',() => { page--;render(); });
    next?.addEventListener('click',() => { page++;render(); });
    list.addEventListener('click',event => {
      const button = event.target.closest('button[data-maintain-id]');
      if (!button || button.disabled || !list.contains(button)) return;
      if (typeof window.openReportModal === 'function') window.openReportModal(button.dataset.maintainId);
    });
    render();
  }
  if (document.readyState === 'loading') document.addEventListener('DOMContentLoaded', init);
  else init();
})();
