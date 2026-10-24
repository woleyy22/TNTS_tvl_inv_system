/* Shared searchable ICS equipment selector + optional declared source batches. */
(function(){
'use strict';
function start(){
 document.querySelectorAll('.p72-picker').forEach(root=>{
  const select=document.getElementById(root.dataset.select);if(!select)return;
  const open=root.querySelector('.p72-picker-open'),panel=root.querySelector('.p72-picker-panel');
  const search=root.querySelector('.p72-picker-search'),filter=root.querySelector('.p72-picker-condition');
  const results=root.querySelector('.p72-picker-results'),count=root.querySelector('.p72-picker-count');
  const back=root.querySelector('.p72-picker-prev'),next=root.querySelector('.p72-picker-next'),pageText=root.querySelector('.p72-picker-page');
  const label=root.querySelector('.p72-picker-label');let page=0;const size=8;
  const options=Array.from(select.options).filter(x=>x.value);
  function matches(){const q=search.value.trim().toLocaleLowerCase(),type=filter.value;
   return options.filter(x=>(!q||[x.dataset.name,x.dataset.ics,x.dataset.course,x.dataset.batches,x.textContent].join(' ').toLocaleLowerCase().includes(q))
      &&(type==='all'||Number(x.dataset[type]||0)>0));}
  function render(){const candidates=matches(),pages=Math.max(1,Math.ceil(candidates.length/size));page=Math.max(0,Math.min(page,pages-1));
   results.replaceChildren();count.textContent=candidates.length+' record(s)';pageText.textContent=(page+1)+' / '+pages;back.disabled=page===0;next.disabled=page>=pages-1;
   for(const option of candidates.slice(page*size,(page+1)*size)){
    const b=document.createElement('button');b.type='button';b.className='p72-picker-item';b.setAttribute('role','listitem');
    if(option.value===select.value)b.classList.add('p72-picked');
    const title=document.createElement('strong');title.textContent=option.dataset.name||option.textContent.trim();
    const meta=document.createElement('span');meta.className='p72-picker-item-meta';meta.textContent=(option.dataset.ics||'')+' · '+(option.dataset.course||'Unclassified');
    const values=document.createElement('span');values.className='p72-picker-numbers';values.textContent='Serviceable '+(option.dataset.serviceable||0)+'  ·  For repair '+(option.dataset.repair||0)+'  ·  Damaged '+(option.dataset.damaged||0);
    const batch=document.createElement('small');batch.textContent=option.dataset.batches||'No known receipt allocation';
    b.append(title,meta,values,batch);
    b.addEventListener('click',()=>{select.value=option.value;select.dispatchEvent(new Event('change',{bubbles:true}));panel.hidden=true;open.setAttribute('aria-expanded','false');label.textContent=(option.dataset.name||option.textContent.trim())+' — '+(option.dataset.ics||'');open.focus();render();});
    results.appendChild(b);
   }
   if(!candidates.length){const p=document.createElement('p');p.className='field-help';p.textContent='No matching equipment. Try another search or condition.';results.appendChild(p);}
  }
  open.addEventListener('click',()=>{panel.hidden=!panel.hidden;open.setAttribute('aria-expanded',String(!panel.hidden));if(!panel.hidden){page=0;render();search.focus();}});
  search.addEventListener('input',()=>{page=0;render();});filter.addEventListener('change',()=>{page=0;render();});
  back.addEventListener('click',()=>{page--;render();});next.addEventListener('click',()=>{page++;render();});
  select.addEventListener('change',()=>{let x=select.selectedOptions[0];label.textContent=x&&x.value?(x.dataset.name+' — '+x.dataset.ics):'Search and select equipment';render();});
  if(select.value)label.textContent=(select.selectedOptions[0]?.dataset.name||'Equipment')+' — '+(select.selectedOptions[0]?.dataset.ics||'');
  render();
 });
 document.querySelectorAll('select[data-p72-source-for]').forEach(batch=>{
  const select=document.getElementById(batch.dataset.p72SourceFor);if(!select)return;
  const map=window.TNTSPhase7Batches||{};
  function sync(){const selected=select.value,prior=batch.value;batch.replaceChildren();
   const unknown=document.createElement('option');unknown.value='0';unknown.textContent='Unknown / not sure (no batch attribution)';batch.appendChild(unknown);
   for(const item of (map[selected]||[])){
    if(Number(item.remaining_recorded)<=0)continue;
    const option=document.createElement('option');option.value=String(item.allocation_id);option.dataset.remaining=String(item.remaining_recorded);
    option.textContent=item.label+' · '+item.remaining_recorded+' '+(item.remaining_label||'unreturned (recorded)');batch.appendChild(option);
   }
   if([...batch.options].some(o=>o.value===prior))batch.value=prior;
   batch.dispatchEvent(new CustomEvent('p72-sources-updated',{bubbles:true}));
  }
  select.addEventListener('change',sync);sync();
 });
}
if(document.readyState==='loading')document.addEventListener('DOMContentLoaded',start);else start();
})();
