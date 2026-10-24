/* TNTS 3.8.11 — client-side display controls for existing administration tables.
   No network requests, database writes, or form-submit interception. */
(function () {
  'use strict';
  function boot() {
    document.querySelectorAll('[data-tnts-admin-grid]').forEach(grid => {
      const table=grid.querySelector('table[data-admin-table]');
      const body=table?.tBodies?.[0];
      if (!body) return;
      const rows=Array.from(body.querySelectorAll('tr[data-admin-row]'));
      const records=rows.map((row,index)=>({row,index}));
      const field=key=>grid.querySelector('[data-admin-filter="'+key+'"]');
      const search=field('search'),status=field('status'),course=field('course'),category=field('category'),pageSize=field('page-size');
      const reset=grid.querySelector('[data-admin-reset]');
      const prev=grid.querySelector('[data-admin-prev]'),next=grid.querySelector('[data-admin-next]');
      const count=grid.querySelector('[data-admin-count]'),pagesText=grid.querySelector('[data-admin-page]');
      const sorters=Array.from(grid.querySelectorAll('[data-admin-sort]'));
      let sortKey='',sortDirection='',sortType='text',page=1;
      const empty=document.createElement('tr');
      empty.hidden=true; empty.dataset.adminEmpty='';
      const td=document.createElement('td');td.colSpan=table.tHead?.rows[0]?.cells.length||1;
      td.className='tnts-admin-no-results';td.textContent='No records match your search or filters.';
      empty.append(td);body.append(empty);
      function compare(a,b) {
        const av=a.row.getAttribute('data-sort-'+sortKey)||'',bv=b.row.getAttribute('data-sort-'+sortKey)||'';
        if (!av || !bv) return av===bv?a.index-b.index:(av?-1:1); // missing values last
        let delta=0;
        if (sortType==='number') delta=Number(av)-Number(bv);
        else delta=av.localeCompare(bv,undefined,{numeric:sortType!=='date',sensitivity:'base'});
        return (sortDirection==='desc'?-1:1)*(Number.isFinite(delta)?delta:0)||a.index-b.index;
      }
      function render() {
        const query=(search?.value||'').toLocaleLowerCase().trim();
        const wantedStatus=status?.value||'',wantedCourse=course?.value||'',wantedCategory=category?.value||'';
        const filtered=records.filter(({row})=>{
          const d=row.dataset;
          return (!query||(d.search||row.textContent||'').toLocaleLowerCase().includes(query)) &&
            (!wantedStatus||d.status===wantedStatus) &&
            (!wantedCourse||d.course===wantedCourse) &&
            (!wantedCategory||d.category===wantedCategory);
        });
        const sorted=filtered.slice();
        if(sortDirection) sorted.sort(compare);
        const size=pageSize?.value==='all'?Infinity:Math.max(1,Number(pageSize?.value)||10);
        const totalPages=Math.max(1,Math.ceil(sorted.length/size));
        page=Math.min(page,totalPages);
        const start=Number.isFinite(size)?(page-1)*size:0;
        const shown=new Set(sorted.slice(start,start+size).map(x=>x.row));
        const matching=new Set(filtered.map(x=>x.row));
        const fragment=document.createDocumentFragment();
        sorted.forEach(({row})=>{row.hidden=!shown.has(row);fragment.append(row);});
        records.filter(({row})=>!matching.has(row)).forEach(({row})=>{row.hidden=true;fragment.append(row);});
        body.insertBefore(fragment,empty);
        empty.hidden=filtered.length!==0;
        if(count)count.textContent=filtered.length+' of '+records.length+' records';
        if(pagesText)pagesText.textContent=filtered.length?('Page '+page+' of '+totalPages+' · Showing '+(start+1)+'–'+Math.min(sorted.length,start+size)):'No matching records';
        if(prev)prev.disabled=page===1;
        if(next)next.disabled=page>=totalPages;
      }
      sorters.forEach(btn=>btn.addEventListener('click',()=>{
        const next=btn.dataset.order==='asc'?'desc':btn.dataset.order==='desc'?'':'asc';
        sorters.forEach(x=>{x.dataset.order='';x.setAttribute('aria-pressed','false');x.querySelector('span').textContent='–';x.closest('th')?.removeAttribute('aria-sort');});
        sortKey=next?btn.dataset.adminSort:'';
        sortDirection=next;
        sortType=btn.dataset.adminType||'text';
        btn.dataset.order=next;btn.setAttribute('aria-pressed',String(!!next));
        btn.querySelector('span').textContent=next==='asc'?'↑':next==='desc'?'↓':'–';
        if(next)btn.closest('th')?.setAttribute('aria-sort',next==='asc'?'ascending':'descending');
        page=1;render();
      }));
      [search,status,course,category].forEach(f=>f?.addEventListener(f===search?'input':'change',()=>{page=1;render();}));
      pageSize?.addEventListener('change',()=>{page=1;render();});
      reset?.addEventListener('click',()=>{
        [search,status,course,category].forEach(f=>{if(f)f.value='';});
        page=1;render();
      });
      prev?.addEventListener('click',()=>{page=Math.max(1,page-1);render();});
      next?.addEventListener('click',()=>{page++;render();});
      render();
    });
  }
  if(document.readyState==='loading')document.addEventListener('DOMContentLoaded',boot);
  else boot();
})();
