/* Searchable, sortable, paged maintenance activity timeline. */
(function(){
 function init(){const list=document.getElementById('p72-history-list');if(!list)return;
  const cards=Array.from(list.querySelectorAll('.p72-history-event'));
  const search=document.getElementById('p72-history-search'),action=document.getElementById('p72-history-action'),order=document.getElementById('p72-history-order');
  const back=document.getElementById('p72-history-prev'),next=document.getElementById('p72-history-next'),count=document.getElementById('p72-history-count'),label=document.getElementById('p72-history-page'),empty=document.getElementById('p72-history-empty');
  let page=0;const size=10;
  function render(){const q=search.value.trim().toLocaleLowerCase(),filter=action.value;
   const matched=cards.filter(el=>(!q||el.dataset.search.includes(q))&&(!filter||el.dataset.action===filter));
   matched.sort((a,b)=>order.value==='oldest'?a.dataset.sort.localeCompare(b.dataset.sort):b.dataset.sort.localeCompare(a.dataset.sort));
   const pages=Math.max(1,Math.ceil(matched.length/size));page=Math.min(Math.max(0,page),pages-1);
   cards.forEach(el=>el.hidden=true);for(const card of matched.slice(page*size,(page+1)*size)){list.appendChild(card);card.hidden=false;}
   count.textContent=matched.length+' matching event(s)';label.textContent='Page '+(page+1)+' of '+pages;back.disabled=page===0;next.disabled=page===pages-1;empty.hidden=matched.length!==0;
  }
  [search,action,order].forEach(el=>el.addEventListener(el===search?'input':'change',()=>{page=0;render();}));
  back.addEventListener('click',()=>{page--;render();});next.addEventListener('click',()=>{page++;render();});
  render();
  const id=new URLSearchParams(location.search).get('event_id');
  if(id && /^\d+$/.test(id)){
   const found=cards.find(x=>x.dataset.eventId===id);
   if(found){search.value='';action.value='';order.value='newest';
    const sorted=cards.slice().sort((a,b)=>b.dataset.sort.localeCompare(a.dataset.sort));
    page=Math.floor(sorted.indexOf(found)/size);render();found.classList.add('tn-focus-record');
    requestAnimationFrame(()=>found.scrollIntoView({block:'center',behavior:'auto'}));
   }
  }
 }
 if(document.readyState==='loading')document.addEventListener('DOMContentLoaded',init);else init();
})();
