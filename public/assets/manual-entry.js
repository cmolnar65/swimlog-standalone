(() => {
  'use strict';
  const form=document.querySelector('[data-manual-entry]');if(!form)return;
  const list=form.querySelector('[data-set-list]'), template=document.getElementById('manual-set-template');
  const update=()=>{const sets=Array.from(list.children);sets.forEach((set,i)=>{set.querySelector('legend').textContent='Set '+(i+1);set.querySelectorAll('[data-field]').forEach(el=>{el.name='sets['+i+']['+el.dataset.field+']';el.setAttribute('aria-label','Set '+(i+1)+': '+el.closest('label').querySelector('span').textContent);});set.querySelector('[data-set-up]').disabled=i===0;set.querySelector('[data-set-down]').disabled=i===sets.length-1;});form.querySelector('[data-add-set]').disabled=sets.length>=100;};
  form.querySelector('[data-add-set]').addEventListener('click',()=>{const node=template.content.firstElementChild.cloneNode(true);list.append(node);update();node.querySelector('input,select').focus();});
  list.addEventListener('click',e=>{const button=e.target.closest('button');if(!button)return;const set=button.closest('[data-manual-set]');if(button.hasAttribute('data-remove-set'))set.remove();else if(button.hasAttribute('data-set-up')&&set.previousElementSibling)list.insertBefore(set,set.previousElementSibling);else if(button.hasAttribute('data-set-down')&&set.nextElementSibling)list.insertBefore(set.nextElementSibling,set);update();});
  form.addEventListener('submit',()=>{form.querySelector('button:not([type="button"])').disabled=true;});
  window.addEventListener('pageshow',()=>{form.querySelector('button:not([type="button"])').disabled=false;});update();
})();
