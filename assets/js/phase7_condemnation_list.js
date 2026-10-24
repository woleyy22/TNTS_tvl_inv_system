/* Phase 7.3: choose the damaged ICS item from an Inventory-style list, not a nested picker. */
(function(){
 'use strict';
 function start(){
  const table=document.getElementById('p73-eligible-table');
  const selected=document.getElementById('condemn_item');
  if(!table||!selected)return;
  const list=document.getElementById('p73-eligible-list');
  const search=document.getElementById('p73-eligible-search');
  const order=document.getElementById('p73-eligible-sort');
  const count=document.getElementById('p73-eligible-count');
  const empty=document.getElementById('p73-eligible-empty');
  const prev=document.getElementById('p73-eligible-prev');
  const next=document.getElementById('p73-eligible-next');
  const pageLabel=document.getElementById('p73-eligible-page');
  const chosen=document.getElementById('p73-selected-item');
  const cards=[...list.querySelectorAll('tr.p73-eligible-record')];
  let page=0; const pageSize=10;
  function matches(){const q=search.value.trim().toLocaleLowerCase();
    const rows=cards.filter(x=>!q||(x.dataset.search||'').includes(q));
    if(order.value==='az')rows.sort((a,b)=>(a.dataset.name||'').localeCompare(b.dataset.name||''));
    if(order.value==='qty')rows.sort((a,b)=>Number(b.dataset.free)-Number(a.dataset.free));
    return rows;
  }
  function render(){const rows=matches();const total=Math.max(1,Math.ceil(rows.length/pageSize));page=Math.max(0,Math.min(page,total-1));
    rows.forEach(x=>list.appendChild(x));const shown=new Set(rows.slice(page*pageSize,(page+1)*pageSize));
    cards.forEach(x=>x.hidden=!shown.has(x));
    count.textContent=rows.length+' eligible item(s)';
    empty.hidden=rows.length!==0;
    prev.disabled=page===0;next.disabled=page>=total-1;
    pageLabel.textContent='Page '+(page+1)+' of '+total;
  }
  function syncItem(){
    const option=selected.selectedOptions[0];
    chosen.textContent=option&&option.value ? (option.dataset.name||'Equipment')+' · '+(option.dataset.ics||'ICS')+' · '+(option.dataset.damaged||'0')+' damaged unit(s) eligible' : 'Choose an item from Damaged Equipment first.';
  }
  function closeAndReturn(){
    if(typeof window.closeCondemnModal==='function')window.closeCondemnModal();
    document.getElementById('p73-eligible')?.scrollIntoView({behavior:'auto',block:'start'});
    search.focus({preventScroll:true});
  }
  list.addEventListener('click',e=>{
    const button=e.target.closest('button[data-request-ics]');if(!button||button.disabled||!list.contains(button))return;
    const option=[...selected.options].find(x=>x.value===button.dataset.requestIcs);
    if(!option)return;
    selected.value=option.value;
    selected.dispatchEvent(new Event('change',{bubbles:true}));
    syncItem();
    if(typeof window.openCondemnModal==='function')window.openCondemnModal();
  });
  selected.addEventListener('change',syncItem);
  document.getElementById('p73-change-item')?.addEventListener('click',closeAndReturn);
  search.addEventListener('input',()=>{page=0;render();});
  order.addEventListener('change',()=>{page=0;render();});
  prev.addEventListener('click',()=>{page--;render();});
  next.addEventListener('click',()=>{page++;render();});
  syncItem();render();
 }
 if(document.readyState==='loading')document.addEventListener('DOMContentLoaded',start);
 else start();
})();
