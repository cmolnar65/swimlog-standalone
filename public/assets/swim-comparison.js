'use strict';
(() => {
 const form=document.querySelector('#swim-comparison-selection');if(!form)return;
 const boxes=Array.from(form.querySelectorAll('input[name="compare[]"]')),button=form.querySelector('[data-compare-button]'),status=form.querySelector('[data-compare-count]');
 const update=()=>{const n=boxes.filter(b=>b.checked).length;button.disabled=n!==2;status.textContent=n+' of 2 swims selected';boxes.forEach(b=>{b.disabled=n===2&&!b.checked;});};
 boxes.forEach(b=>b.addEventListener('change',update));form.addEventListener('submit',e=>{if(boxes.filter(b=>b.checked).length!==2){e.preventDefault();status.textContent='Choose exactly two different pool swims.';}});window.addEventListener('pageshow',update);update();
})();
