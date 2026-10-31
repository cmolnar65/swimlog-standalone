/* One top-level menu tree at a time; retain native details keyboard/touch behavior. */
(() => {
  'use strict';
  const nav=document.querySelector('.top-nav');if(!nav)return;
  const trees=Array.from(nav.querySelectorAll(':scope > details.tools-menu'));
  const position=tree=>{
    const panel=tree.querySelector(':scope > .tools-links');
    if(!matchMedia('(min-width:701px)').matches){panel.style.left='';panel.style.right='';return;}
    if(!tree.open)return;
    const trigger=tree.querySelector(':scope > summary').getBoundingClientRect(), container=nav.getBoundingClientRect();
    // Anchor horizontally to this trigger, but stay below every wrapped nav row.
    const left=Math.max(12,Math.min(trigger.left,innerWidth-12-panel.getBoundingClientRect().width));
    panel.style.left=(left-container.left)+'px';panel.style.right='auto';
  };
  const layout=()=>trees.forEach(position);
  window.addEventListener('resize',layout);
  if(typeof ResizeObserver==='function')new ResizeObserver(layout).observe(nav);
  if(document.fonts)document.fonts.ready.then(layout);
  const close=tree=>{tree.open=false;tree.querySelectorAll('details[open]').forEach(child=>{child.open=false;});};
  for(const tree of trees) {
    tree.querySelector(':scope > summary').addEventListener('click',()=>{if(!tree.open)trees.filter(other=>other!==tree).forEach(close);});
    tree.addEventListener('toggle',()=>{if(tree.open){trees.filter(other=>other!==tree).forEach(close);position(tree);}else tree.querySelectorAll('details[open]').forEach(child=>{child.open=false;});});
  }
  document.addEventListener('click',e=>{if(!nav.contains(e.target))trees.forEach(close);});
  nav.addEventListener('keydown',e=>{if(e.key==='Escape'){const tree=e.target.closest('details.tools-menu');if(tree){close(tree);tree.querySelector(':scope > summary').focus();e.preventDefault();}}});
})();
