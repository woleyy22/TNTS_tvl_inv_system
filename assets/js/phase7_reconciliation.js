(() => {
  const rows = [...document.querySelectorAll('.p74-finding')];
  const search = document.getElementById('p74-search');
  const severity = document.getElementById('p74-severity');
  const count = document.getElementById('p74-count');
  const empty = document.getElementById('p74-empty');
  if (!search || !severity || !count) return;
  function refresh() {
    const query = search.value.trim().toLocaleLowerCase();
    const level = severity.value;
    let shown = 0;
    rows.forEach(row => {
      const visible = (!level || row.dataset.severity === level) && (!query || (row.dataset.search || '').includes(query));
      row.hidden = !visible;
      if (visible) shown++;
    });
    count.textContent = `${shown} of ${rows.length} finding(s)`;
    if (empty) {
      empty.hidden = shown !== 0;
      empty.textContent = rows.length ? 'No findings match these filters.' : 'No exceptions found in the current database.';
    }
  }
  search.addEventListener('input', refresh);
  severity.addEventListener('change', refresh);
  refresh();
})();
