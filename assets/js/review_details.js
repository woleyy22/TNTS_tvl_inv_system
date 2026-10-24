/* Compact registration / personnel record details and paging. Presentation only. */
(function(){
  'use strict';
  function setOpen(row, show){
    if(!row)return;
    const table=row.closest('table');
    if(show && table){
      table.querySelectorAll('tr.tnts-review-detail').forEach(function(other){
        if(other!==row){
          other.hidden=true;
          const trigger=table.querySelector('.tnts-review-toggle[data-target="'+other.id+'"]');
          if(trigger){trigger.setAttribute('aria-expanded','false');trigger.textContent='View Details';}
        }
      });
    }
    row.hidden=!show;
    const trigger=table && table.querySelector('.tnts-review-toggle[data-target="'+row.id+'"]');
    if(trigger){trigger.setAttribute('aria-expanded',show?'true':'false');trigger.textContent=show?'Hide Details':'View Details';}
  }
  document.addEventListener('click',function(e){
    const trigger=e.target.closest('.tnts-review-toggle, .tnts-review-close');
    if(!trigger)return;
    const row=document.getElementById(trigger.getAttribute('data-target'));
    if(!row)return;
    setOpen(row,trigger.classList.contains('tnts-review-close')?false:row.hidden);
  });
  document.querySelectorAll('table.tnts-review-table').forEach(function(table){
    const rows=Array.from(table.querySelectorAll('tbody>tr.tnts-review-main'));
    if(rows.length<=10)return;
    const wrap=table.closest('.tnts-review-table-wrap');
    if(!wrap)return;
    const nav=document.createElement('nav');
    nav.className='tnts-review-pagination';nav.setAttribute('aria-label','Review table pagination');
    nav.innerHTML='<label>Rows per page <select aria-label="Rows per page"><option value="10">10</option><option value="20">20</option><option value="50">50</option></select></label><span class="tnts-review-page-summary"></span><button type="button" class="btn btn-outline btn-sm" data-page-prev>Previous</button><button type="button" class="btn btn-outline btn-sm" data-page-next>Next</button>';
    wrap.insertAdjacentElement('afterend',nav);
    const select=nav.querySelector('select'), prev=nav.querySelector('[data-page-prev]'), next=nav.querySelector('[data-page-next]'), summary=nav.querySelector('.tnts-review-page-summary');
    let page=0;
    function render(){
      const size=Number(select.value), pages=Math.ceil(rows.length/size);
      page=Math.min(page,Math.max(0,pages-1));
      table.querySelectorAll('tr.tnts-review-detail').forEach(function(detail){detail.hidden=true;});
      table.querySelectorAll('.tnts-review-toggle').forEach(function(trigger){trigger.setAttribute('aria-expanded','false');trigger.textContent='View Details';});
      rows.forEach(function(row,i){row.hidden=!(i>=page*size&&i<(page+1)*size);});
      summary.textContent='Page '+(page+1)+' of '+pages+' · '+rows.length+' records';
      prev.disabled=page===0;next.disabled=page>=pages-1;
    }
    select.addEventListener('change',function(){page=0;render();});
    prev.addEventListener('click',function(){if(page>0){page--;render();}});
    next.addEventListener('click',function(){page++;render();});
    render();
    // Deep links from notifications must reveal the exact record even on later pages.
    const qs=new URLSearchParams(window.location.search);
    const personId=qs.get('open') || qs.get('user_id');
    const selected=personId && (table.id==='personnel-review-table'
      ? rows.find(row=>row.nextElementSibling?.id==='personnel-'+personId)
      : rows.find(row=>row.dataset.notifyUser===personId));
    if(selected){
      page=Math.floor(rows.indexOf(selected)/Number(select.value));
      render();
      setOpen(selected.nextElementSibling,true);
      selected.classList.add('tn-focus-record');
      window.requestAnimationFrame(()=>selected.scrollIntoView({block:'center',behavior:'auto'}));
    }
  });
  // For short lists that do not need a pager, the same focused-row behavior applies.
  const qs=new URLSearchParams(window.location.search);
  document.querySelectorAll('table.tnts-review-table').forEach(table=>{
    const rows=Array.from(table.querySelectorAll('tbody>tr.tnts-review-main'));
    if(rows.length>10)return;
    const selectedId=qs.get('open') || qs.get('user_id');
    const chosen=selectedId && (table.id==='personnel-review-table'
      ? rows.find(row=>row.nextElementSibling?.id==='personnel-'+selectedId)
      : rows.find(row=>row.dataset.notifyUser===selectedId));
    if(chosen){setOpen(chosen.nextElementSibling,true);chosen.classList.add('tn-focus-record');
      requestAnimationFrame(()=>chosen.scrollIntoView({block:'center',behavior:'auto'}));}
  });
})();
