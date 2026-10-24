(()=>{'use strict';
const root=document.getElementById('p6-history');if(!root)return;
const el=id=>document.getElementById(id), body=el('p6-records').tBodies[0], rows=[...body.querySelectorAll('tr.p3813-record')];
const search=el('p3813-search'),status=el('p3813-status'),from=el('p3813-from'),to=el('p3813-to'),sort=el('p3813-sort'),count=el('p3813-count'),pageLabel=el('p3813-page');
let page=1;const pageSize=10;const original=new Map(rows.map((r,i)=>[r,i]));
function sortRows(arr){const v=sort.value;if(v==='default'||v==='new')return arr.sort((a,b)=>original.get(a)-original.get(b));
if(v==='old')return arr.sort((a,b)=>original.get(b)-original.get(a));
const field=v.startsWith('due')?'due':'item',dir=v.endsWith('desc')?-1:1;
return arr.sort((a,b)=>{const val=a.dataset[field].localeCompare(b.dataset[field],undefined,{numeric:true});return val*dir||original.get(a)-original.get(b)});}
function render(){const term=search.value.trim().toLowerCase(),matched=sortRows(rows.filter(r=>r.dataset.search.includes(term)&&(!status.value||r.dataset.status===status.value)&&(!from.value||r.dataset.requested>=from.value)&&(!to.value||r.dataset.requested<=to.value)));
const pages=Math.max(1,Math.ceil(matched.length/pageSize));page=Math.min(page,pages);const shown=new Set(matched.slice((page-1)*pageSize,page*pageSize));
rows.forEach(r=>{r.hidden=!shown.has(r);const detail=r.nextElementSibling;if(!shown.has(r)){detail.hidden=true;r.querySelector('.p3813-toggle').setAttribute('aria-expanded','false')}});
matched.forEach(r=>{body.append(r,r.nextElementSibling)});count.textContent=`${matched.length} matching record${matched.length===1?'':'s'}`;pageLabel.textContent=`Page ${page} of ${pages}`;el('p3813-prev').disabled=page===1;el('p3813-next').disabled=page===pages;}
[search,status,from,to,sort].forEach(x=>x.addEventListener('input',()=>{page=1;render()}));
el('p3813-reset').addEventListener('click',()=>{search.value=status.value=from.value=to.value='';sort.value='default';page=1;render()});
el('p3813-prev').addEventListener('click',()=>{if(page>1){page--;render()}});el('p3813-next').addEventListener('click',()=>{page++;render()});
rows.forEach(r=>r.querySelector('.p3813-toggle').addEventListener('click',()=>{const d=r.nextElementSibling;d.hidden=!d.hidden;r.querySelector('.p3813-toggle').setAttribute('aria-expanded',String(!d.hidden));}));
const params=new URLSearchParams(location.search),target=params.get('request_id');if(target&&/^\d+$/.test(target)){const hit=rows.find(r=>r.dataset.request===target);if(hit){search.value='';status.value='';from.value=to.value='';sort.value='default';page=Math.floor(original.get(hit)/pageSize)+1;render();hit.nextElementSibling.hidden=false;hit.querySelector('.p3813-toggle').setAttribute('aria-expanded','true');hit.scrollIntoView({block:'center'});}}
else render();
})();