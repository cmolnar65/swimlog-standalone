/* One top-level menu tree at a time; retain native details keyboard/touch behavior. */
(() => {
  'use strict';
  const nav=document.querySelector('.top-nav');if(!nav)return;
  const trees=Array.from(nav.querySelectorAll(':scope > details.tools-menu'));
  const close=tree=>{tree.open=false;tree.querySelectorAll('details[open]').forEach(child=>{child.open=false;});};
  for(const tree of trees) {
    tree.querySelector(':scope > summary').addEventListener('click',()=>{if(!tree.open)trees.filter(other=>other!==tree).forEach(close);});
    tree.addEventListener('toggle',()=>{if(tree.open)trees.filter(other=>other!==tree).forEach(close);else tree.querySelectorAll('details[open]').forEach(child=>{child.open=false;});});
  }
  document.addEventListener('click',e=>{if(!nav.contains(e.target))trees.forEach(close);});
  nav.addEventListener('keydown',e=>{if(e.key==='Escape'){const tree=e.target.closest('details.tools-menu');if(tree){close(tree);tree.querySelector(':scope > summary').focus();e.preventDefault();}}});
})();
