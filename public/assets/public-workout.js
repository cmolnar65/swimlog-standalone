/* Do not restore a retained shared page/geometry without a new policy evaluation. */
(() => {
  window.addEventListener('pagehide',()=>{const main=document.querySelector('main');if(main)main.replaceChildren();});
  window.addEventListener('pageshow',event=>{if(event.persisted)location.reload();});
})();
