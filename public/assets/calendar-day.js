/* Expand the original entries, preserving their detail links and actions. */
(() => {
  'use strict';
  const dialog=document.getElementById('calendar-day-dialog');
  if(new URL(location.href).searchParams.has('day')) return;
  if(!dialog || typeof dialog.showModal!=='function') return;
  const cells=Array.from(document.querySelectorAll('.calendar-day'));
  let source=null,trigger=null,scroll=0,outsideDown=false;
  function measure() {
    for(const cell of cells) {
      if(cell===source) continue;
      const items=cell.querySelector('.day-items'); let used=0,hidden=0;
      for(const entry of items.children) {
        entry.hidden=false;
        const height=entry.getBoundingClientRect().height+12;
        if(hidden || used+height>(matchMedia('(max-width:700px)').matches?200:180)) { entry.hidden=true;hidden++; } else used+=height;
      }
      const more=cell.querySelector('[data-day-open]');more.hidden=!hidden;
      more.setAttribute('aria-label','Show all items for '+cell.dataset.date+' ('+hidden+' more)');
    }
  }
  document.addEventListener('click',event=>{
    const link=event.target.closest?.('[data-day-open]');
    if(!link||event.button!==0||event.ctrlKey||event.metaKey||event.shiftKey||event.altKey) return;
    event.preventDefault();outsideDown=false;source=link.closest('.calendar-day');trigger=link;scroll=window.scrollY;
    dialog.querySelector('#calendar-day-title').textContent=source.getAttribute('aria-label');
    const body=dialog.querySelector('[data-day-items]');
    for(const entry of Array.from(source.querySelector('.day-items').children)) { entry.hidden=false;entry.draggable=false;body.append(entry); }
    const actions=dialog.querySelector('[data-day-actions]');actions.replaceChildren();
    const plan=source.querySelector('.plan-day-link');if(plan) actions.append(plan.cloneNode(true));
    const add=document.querySelector('[data-add-event]');if(add){const link=add.cloneNode(true),url=new URL(link.href);url.searchParams.set('event_date',source.dataset.date);link.href=url.pathname+url.search;actions.append(link);}
    document.documentElement.classList.add('workout-dialog-open');dialog.showModal();dialog.querySelector('[data-day-close]').focus();
  });
  dialog.querySelector('[data-day-close]').addEventListener('click',()=>dialog.close());
  dialog.addEventListener('close',()=>{
    if(!source)return;
    source.querySelector('.day-items').append(...dialog.querySelector('[data-day-items]').children);
    source=null;measure();document.documentElement.classList.remove('workout-dialog-open');
    trigger?.focus({preventScroll:true});window.scrollTo(0,scroll);window.dispatchEvent(new Event('resize'));
  });
  const outside=e=>{const r=dialog.getBoundingClientRect();return e.clientX<r.left||e.clientX>r.right||e.clientY<r.top||e.clientY>r.bottom;};
  dialog.addEventListener('pointerdown',e=>{outsideDown=e.target===dialog&&outside(e);});
  dialog.addEventListener('click',e=>{if(outsideDown&&e.target===dialog&&outside(e)){e.stopPropagation();dialog.close();}outsideDown=false;});
  window.addEventListener('resize',measure);measure();
})();
