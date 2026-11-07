/* Desktop plan rescheduling. Touch users keep ordinary scrolling and date forms. */
(() => {
  'use strict';
  const calendar=document.querySelector('[data-plan-calendar]');
  if(!calendar)return;
  const status=calendar.querySelector('[data-plan-drag-status]');
  const desktop=matchMedia('(min-width:701px)');
  let drag=null, pending=false, suppressUntil=0;
  function enable() {
    calendar.querySelectorAll('a[data-plan-drag]').forEach(el=>{el.draggable=desktop.matches&&!pending&&!document.querySelector('#calendar-day-dialog[open]');});
  }
  function clearTargets(){calendar.querySelectorAll('.plan-drop-target').forEach(el=>el.classList.remove('plan-drop-target'));}
  function message(text){status.textContent=text;}
  function target(event,source) {
    const day=event.target instanceof Element?event.target.closest('.calendar-day[data-date]'):null;
    return day&&calendar.contains(day)&&day.dataset.date>=calendar.dataset.today&&day.dataset.date!==source.sourceDate?day:null;
  }
  async function context(source) {
    const url=new URL('/',location.origin), origin=new URL(source.href);
    url.searchParams.set('action','plan_drag_context');url.searchParams.set('id',source.dataset.planId);
    for(const key of ['date','view'])if(origin.searchParams.has(key))url.searchParams.set(key,origin.searchParams.get(key));
    try {
      const response=await fetch(url,{credentials:'same-origin',cache:'no-store',headers:{'X-Swimlog-Nonce':calendar.dataset.dragNonce,'Accept':'application/json'}});
      const data=await response.json();
      return response.ok?{data}:{error:data.error||'This plan cannot be moved. Refresh the calendar and try again.'};
    } catch(_) {return {error:'Could not prepare the move. Refresh the calendar and try again.'};}
  }
  calendar.addEventListener('dragstart',event=>{
    const source=event.target instanceof Element?event.target.closest('a[data-plan-drag]'):null;
    if(!source)return;
    if(!desktop.matches||pending||!event.dataTransfer){event.preventDefault();return;}
    suppressUntil=Date.now()+800;
    drag={source,sourceDate:source.closest('[data-date]').dataset.date,context:context(source)};
    source.classList.add('plan-dragging');
    event.dataTransfer.clearData();event.dataTransfer.setData('application/x-swimlog-plan',source.dataset.planId);event.dataTransfer.effectAllowed='move';
    message('Drop on today or a future day to move this plan.');
  });
  calendar.addEventListener('dragover',event=>{
    if(!drag||pending)return;
    const day=target(event,drag);clearTargets();
    if(day){event.preventDefault();event.dataTransfer.dropEffect='move';day.classList.add('plan-drop-target');}
    else if(event.dataTransfer)event.dataTransfer.dropEffect='none';
  });
  calendar.addEventListener('dragleave',event=>{
    const day=event.target instanceof Element?event.target.closest('.calendar-day'):null;
    if(day&&(!event.relatedTarget||!day.contains(event.relatedTarget)))day.classList.remove('plan-drop-target');
  });
  calendar.addEventListener('drop',async event=>{
    if(!drag||pending)return;
    event.preventDefault();
    const current=drag,day=target(event,current);clearTargets();
    if(!day){message('Choose a different day, today or later.');return;}
    current.dropped=true;pending=true;calendar.setAttribute('aria-busy','true');enable();message('Moving planned workout…');
    const result=await current.context;
    if(result.error){
      pending=false;calendar.removeAttribute('aria-busy');enable();message(result.error);status.focus({preventScroll:true});return;
    }
    // Native same-origin POST retains Origin/nonce protections and the existing PRG handler.
    const form=document.createElement('form');form.method='post';form.action='/?action=plan_transfer';
    const fields={...result.data,target_date:day.dataset.date,return_to:'calendar'};
    for(const [name,value]of Object.entries(fields)){
      const input=document.createElement('input');input.type='hidden';input.name=name;input.value=String(value);form.append(input);
    }
    document.body.append(form);form.submit();
  });
  calendar.addEventListener('dragend',()=>{
    suppressUntil=Date.now()+800;clearTargets();
    const dropped=drag?.dropped;
    if(drag)drag.source.classList.remove('plan-dragging');drag=null;
    if(!pending&&!dropped)message('');
  });
  calendar.addEventListener('click',event=>{
    if(Date.now()<suppressUntil&&event.target instanceof Element&&event.target.closest('a[data-plan-drag]')){event.preventDefault();event.stopImmediatePropagation();}
  },true);
  desktop.addEventListener('change',enable);enable();
})();
