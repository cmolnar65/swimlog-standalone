/* Private same-origin workout pop-up; no history or route data is persisted. */
(() => {
  'use strict';
  const dialog = document.getElementById('workout-dialog');
  if (!dialog || typeof dialog.showModal !== 'function') return; // Real links remain usable without JS.
  const content = dialog.querySelector('[data-workout-content]');
  let trigger = null, controller = null, generation = 0, savedScroll = 0, outsideDown = false;
  const close = () => { if (dialog.open) dialog.close(); };
  function clear() {
    ++generation;
    controller?.abort(); controller = null;
    content.replaceChildren();
    if(!document.querySelector('#calendar-day-dialog[open]')) document.documentElement.classList.remove('workout-dialog-open');
    const previous = trigger; trigger = null;
    if (previous?.isConnected) { previous.setAttribute('aria-expanded', 'false'); previous.focus({preventScroll:true}); }
    window.scrollTo(0,savedScroll);
  }
  function message(text, retry = false) {
    const paragraph = document.createElement('p'); paragraph.setAttribute('role', retry?'alert':'status'); paragraph.textContent = text;
    content.replaceChildren(paragraph);
    if (retry) {
      const button = document.createElement('button'); button.type = 'button'; button.textContent = 'Retry';
      button.addEventListener('click', () => load(trigger)); content.append(button);
    }
  }
  async function load(source) {
    controller?.abort(); controller = new AbortController(); const current = ++generation;
    message('Loading details…');
    try {
      const url = new URL('/', window.location.origin); const planned=!!source.dataset.planId, event=!!source.dataset.eventId;
      url.searchParams.set('action', event?'event_detail':(planned?'planned_detail':'workout_detail')); url.searchParams.set('id', event?source.dataset.eventId:(planned?source.dataset.planId:source.dataset.workoutId));
      const response = await fetch(url, {credentials:'same-origin', cache:'no-store', signal:controller.signal, headers:{'X-Swimlog-Nonce':event?dialog.dataset.eventNonce:(planned?dialog.dataset.planNonce:dialog.dataset.detailNonce), 'Accept':'application/json'}});
      const data = await response.json();
      if (current !== generation || !dialog.open) return;
      if (!response.ok) {
        message(response.status===401?'Your session has expired. Close this window and sign in again.':(data.error || 'Details are unavailable.'), response.status>=500);
        return;
      }
      // HTML is rendered/escaped by our authenticated PHP template; no raw records are serialized.
      content.innerHTML = data.html;
      // Keep a matched session's fallback return link in the originating calendar view.
      const links=(planned||event)?content.querySelectorAll('a[data-matched-workout],a[data-edit-plan],a[data-plan-action],a[data-match-plan],a[data-event-action]'):[];
      for(const link of links) {
        const context=new URL(source.href), destination=new URL(link.href);
        for(const key of ['date','view']) if(context.searchParams.has(key)) destination.searchParams.set(key,context.searchParams.get(key));
        link.href=destination.pathname+destination.search;
      }
    } catch (error) {
      if (error.name !== 'AbortError' && current === generation && dialog.open) message('Could not load details. Please retry.', true);
    }
  }
  document.addEventListener('click', event => {
    const source = event.target instanceof Element ? event.target.closest('a[data-workout-id],a[data-plan-id],a[data-event-id]') : null;
    if (!source || event.ctrlKey || event.metaKey || event.shiftKey || event.altKey || event.button !== 0) return;
    event.preventDefault();
    if (dialog.open && source === trigger) { close(); return; }
    if (trigger) trigger.setAttribute('aria-expanded', 'false');
    dialog.querySelector('#workout-dialog-title').textContent=source.dataset.eventId?'Event details':(source.dataset.planId?'Planned workout details':'Workout details');
    dialog.querySelector('[data-workout-close]').setAttribute('aria-label',source.dataset.eventId?'Close event details':'Close workout details');
    trigger = source; source.setAttribute('aria-expanded', 'true');
    if (!dialog.open) {
      savedScroll = window.scrollY; document.documentElement.classList.add('workout-dialog-open');
      dialog.showModal(); dialog.querySelector('[data-workout-close]').focus({preventScroll:true});
    }
    load(source);
  });
  dialog.addEventListener('keydown', event => {
    if (event.key !== 'Tab' || event.ctrlKey || event.metaKey || event.altKey) return;
    const focusable = Array.from(dialog.querySelectorAll('a[href],button,input,select,textarea,[tabindex]'))
      .filter(el => !el.disabled && el.tabIndex >= 0 && el.getClientRects().length);
    if (!focusable.length) { event.preventDefault(); return; }
    const index = focusable.indexOf(document.activeElement);
    if (event.shiftKey && index <= 0) { event.preventDefault(); focusable[focusable.length-1].focus(); }
    else if (!event.shiftKey && (index < 0 || index === focusable.length-1)) { event.preventDefault(); focusable[0].focus(); }
  });
  dialog.querySelector('[data-workout-close]').addEventListener('click', close);
  dialog.addEventListener('close', () => { if (!dialog.open) clear(); }); // Includes native Escape/cancel and mobile dismissal.
  const outside = event => { const r=dialog.getBoundingClientRect(); return event.clientX<r.left || event.clientX>r.right || event.clientY<r.top || event.clientY>r.bottom; };
  dialog.addEventListener('pointerdown', event => { outsideDown=outside(event); });
  dialog.addEventListener('click', event => { if (outsideDown && outside(event)) { event.stopPropagation(); close(); } outsideDown=false; });
})();
