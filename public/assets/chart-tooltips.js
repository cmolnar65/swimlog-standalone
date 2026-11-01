/* Values are already present in the private page; no requests or persistent storage. */
(() => {
 'use strict';
 const points=Array.from(document.querySelectorAll('[data-chart-point]'));if(!points.length)return;
 const tip=document.createElement('div');tip.id='swimlog-chart-tooltip';tip.className='chart-tooltip';tip.setAttribute('role','tooltip');tip.hidden=true;document.body.appendChild(tip);let active=null;
 // Native SVG titles remain the no-JavaScript fallback; avoid duplicate hover bubbles.
 points.forEach(point=>{const title=point.querySelector('title');if(title)title.remove();});
 const hide=()=>{if(active)active.removeAttribute('aria-describedby');active=null;tip.hidden=true;};
 const show=point=>{if(active&&active!==point)active.removeAttribute('aria-describedby');active=point;tip.textContent=point.getAttribute('data-chart-tooltip');point.setAttribute('aria-describedby',tip.id);tip.hidden=false;const r=point.getBoundingClientRect(),t=tip.getBoundingClientRect();tip.style.left=Math.max(12,Math.min(r.left+r.width/2-t.width/2,innerWidth-t.width-12))+'px';tip.style.top=Math.max(12,r.bottom+t.height+20<innerHeight?r.bottom+10:r.top-t.height-10)+'px';};
 const pointOf=target=>target.closest?target.closest('[data-chart-point]'):null;
 document.addEventListener('pointerover',e=>{const point=pointOf(e.target);if(point&&e.pointerType!=='touch')show(point);});
 document.addEventListener('pointerout',e=>{const point=pointOf(e.target);if(point&&!point.contains(e.relatedTarget)&&document.activeElement!==point)hide();});
 document.addEventListener('focusin',e=>{const point=pointOf(e.target);if(point)show(point);});
 document.addEventListener('focusout',e=>{if(pointOf(e.target))hide();});
 // Prevent touch/mouse focus from showing the tooltip before click can toggle it.
 document.addEventListener('pointerdown',e=>{if(pointOf(e.target))e.preventDefault();});
 document.addEventListener('click',e=>{const point=pointOf(e.target);if(!point){hide();return;}if(active===point&&!tip.hidden)hide();else show(point);});
 document.addEventListener('keydown',e=>{if(e.key==='Escape')hide();else if((e.key==='Enter'||e.key===' ')&&pointOf(e.target)){e.preventDefault();const point=pointOf(e.target);if(active===point&&!tip.hidden)hide();else show(point);}});
 window.addEventListener('scroll',hide,true);window.addEventListener('resize',hide);
})();
