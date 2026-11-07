/* Stored state alone enables copying. Editing either checkbox disables stale copying. */
(() => {
  'use strict';
  const section=document.querySelector('[data-sharing-controls]');if(!section)return;
  const pub=section.querySelector('[data-public-checkbox]'),route=section.querySelector('[data-route-checkbox]'),copy=section.querySelector('[data-copy-public]'),link=section.querySelector('[data-public-link]'),status=section.querySelector('[data-copy-status]');
  const changed=()=>{
    if(route&&pub&&!pub.checked){route.checked=false;route.disabled=true;}else if(route)route.disabled=false;
    if(copy)copy.disabled=true;
    if(link){link.value='';link.disabled=true;}
    if(status)status.textContent='Save sharing changes before copying a public link.';
  };
  if(pub)pub.addEventListener('change',changed);
  if(route)route.addEventListener('change',changed);
  if(copy)copy.addEventListener('click',async()=>{
    if(copy.disabled||!link||!link.value)return;
    let current;
    try{
      const response=await fetch('/?action=workout_public_link',{method:'POST',credentials:'same-origin',headers:{'Content-Type':'application/x-www-form-urlencoded'},body:new URLSearchParams({workout_id:copy.dataset.copyId,nonce:copy.dataset.copyNonce,revision:copy.dataset.copyRevision})});
      try{current=await response.json();}catch(_){throw new Error('Sharing could not be verified. Reload the workout and sign in again if needed.');}
      if(!response.ok||typeof current.url!=='string')throw new Error(current.error||'Reload the workout before copying.');
      link.value=current.url;
    }catch(error){copy.disabled=true;link.value='';link.disabled=true;status.textContent=error.message;return;}
    try{
      if(!navigator.clipboard||!navigator.clipboard.writeText)throw new Error('Clipboard unavailable');
      await navigator.clipboard.writeText(link.value);status.textContent='Public link copied.';
    }catch(_){link.focus();link.select();status.textContent='Clipboard access is unavailable. The public link is selected; copy it manually.';}
  });
  // A back/forward-cache restoration must recheck canonical state before offering a link.
  window.addEventListener('pageshow',event=>{if(event.persisted){if(copy)copy.disabled=true;location.reload();}});
})();
