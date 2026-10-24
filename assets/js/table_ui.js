/* TNTS 3.8.10: display-only table searching, filters, column sorting, and pagination.
 * Keeps PHP forms, routes and transaction handlers unchanged. */
(function () {
  'use strict';
  function boot() {
    document.querySelectorAll('[data-tnts-grid]').forEach(grid => {
      const table=grid.querySelector('table[data-grid-table]');
      if(!table||!table.tBodies.length) return;
      const tbody=table.tBodies[0];
      const original=Array.from(tbody.querySelectorAll('tr[data-grid-record]'));
      const indexed=original.map((row,index)=>({row,index}));
      const find = name => grid.querySelector('[data-grid-filter="'+name+'"]');
      const search=find('search'),status=find('status'),from=find('from'),to=find('to');
      const size=find('size'),prev=grid.querySelector('[data-grid-prev]'),next=grid.querySelector('[data-grid-next]');
      const count=grid.querySelector('[data-grid-count]'),pageInfo=grid.querySelector('[data-grid-page-info]');
      const sortButtons=Array.from(table.querySelectorAll('[data-grid-sort]'));
      let sortKey=null,sortType='text',sortDirection='none',page=1;
      let empty=grid.querySelector('[data-grid-empty]');
      if(!empty){
        empty=document.createElement('tr');empty.hidden=true;empty.dataset.gridEmpty='';
        const cell=document.createElement('td');cell.colSpan=table.tHead?.rows[0]?.cells.length||1;
        cell.textContent='No records match your filters.';cell.className='tnts-grid-empty';
        empty.appendChild(cell);tbody.appendChild(empty);
      }
      function resetSortIndicators(){
        sortButtons.forEach(btn=>{
          btn.dataset.sortDirection='none';
          btn.querySelector('[data-grid-indicator]').textContent='–';
          btn.closest('th').removeAttribute('aria-sort');
        });
      }
      function compare(a,b){
        const av=a.row.dataset[sortKey]||'',bv=b.row.dataset[sortKey]||'';
        if(!av||!bv){if(av===bv)return a.index-b.index;return av? -1:1;}
        let diff;
        if(sortType==='number')diff=Number(av)-Number(bv);
        else diff=av.localeCompare(bv,undefined,{numeric:sortType!=='date',sensitivity:'base'});
        if(!Number.isFinite(diff))diff=0;
        return (sortDirection==='desc'?-1:1)*diff||a.index-b.index;
      }
      function render(){
        const phrase=(search?.value||'').toLocaleLowerCase().trim();
        const selectedStatus=status?.value||'';
        const startDate=from?.value||'',endDate=to?.value||'';
        const matching=indexed.filter(o=>{
          const d=o.row.dataset;
          return (!phrase||(d.search||o.row.textContent||'').toLocaleLowerCase().includes(phrase))&&
            (!selectedStatus||d.status===selectedStatus)&&
            (!startDate||!!d.date&&d.date>=startDate)&&
            (!endDate||!!d.date&&d.date<=endDate);
        });
        const sorted=matching.slice();
        if(sortKey&&sortDirection!=='none')sorted.sort(compare);
        const limit=size?.value==='all'?Infinity:Math.max(1,Number(size?.value)||10);
        const pages=Math.max(1,Math.ceil(sorted.length/limit));
        page=Math.min(Math.max(1,page),pages);
        const start=Number.isFinite(limit)?(page-1)*limit:0,visible=new Set(sorted.slice(start,start+limit).map(o=>o.row));
        const fragment=document.createDocumentFragment();
        sorted.forEach(({row})=>{row.hidden=!visible.has(row);fragment.appendChild(row);});
        indexed.filter(o=>!matching.includes(o)).forEach(({row})=>{row.hidden=true;fragment.appendChild(row);});
        tbody.insertBefore(fragment,empty);
        empty.hidden=matching.length!==0||indexed.length===0;
        if(count)count.textContent=matching.length+' of '+indexed.length+' records';
        if(pageInfo)pageInfo.textContent=matching.length?('Showing '+(start+1)+'–'+Math.min(start+limit,matching.length)+' of '+matching.length):'No matching records';
        if(prev)prev.disabled=page<=1;
        if(next)next.disabled=page>=pages;
      }
      sortButtons.forEach(button=>button.addEventListener('click',()=>{
        const nextDirection=button.dataset.sortDirection==='none'?'asc':button.dataset.sortDirection==='asc'?'desc':'none';
        resetSortIndicators();
        sortKey=nextDirection==='none'?null:button.dataset.gridSort;
        sortType=button.dataset.gridType||'text';
        sortDirection=nextDirection;
        button.dataset.sortDirection=nextDirection;
        button.querySelector('[data-grid-indicator]').textContent=nextDirection==='none'?'–':nextDirection==='asc'?'↑':'↓';
        if(nextDirection!=='none')button.closest('th').setAttribute('aria-sort',nextDirection==='asc'?'ascending':'descending');
        page=1;render();
      }));
      [search,status,from,to].forEach(control=>control?.addEventListener(control===search?'input':'change',()=>{page=1;render();}));
      size?.addEventListener('change',()=>{page=1;render();});
      prev?.addEventListener('click',()=>{page=Math.max(1,page-1);render();});
      next?.addEventListener('click',()=>{page++;render();});
      render();
    });
  }
  if(document.readyState==='loading')document.addEventListener('DOMContentLoaded',boot);
  else boot();
})();
