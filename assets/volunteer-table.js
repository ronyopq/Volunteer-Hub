(function(){'use strict';
  // Actionable toast notices: native JS, independent of CDN scripts.
  document.querySelectorAll('.pran-toast').forEach((node)=>{
    const close=()=>{node.classList.add('is-leaving');setTimeout(()=>node.remove(),230)};
    node.querySelector('[data-toast-close]')?.addEventListener('click',close);
    if(!node.classList.contains('danger'))setTimeout(close,7000);
  });
  const root=document.getElementById('pranVolunteerTable');if(!root)return;
  const all=root.querySelector('#proSelectAll');const inputs=[...root.querySelectorAll('.pro-row-check')];
  const bar=root.querySelector('#proSelectionBar');const count=root.querySelector('#proSelectedCount');
  const form=document.getElementById('proBulkForm');const container=document.getElementById('proBulkIds');
  const dialog=document.getElementById('proActionDialog');const btnOk=document.getElementById('proDialogConfirm');
  const modalTitle=document.getElementById('proDialogTitle');const modalText=document.getElementById('proDialogText');const dangerous=document.getElementById('proDangerNotice');
  const selected=()=>inputs.filter(x=>x.checked).map(x=>x.value);
  function updateSelection(){const ids=selected();bar.hidden=ids.length===0;count.textContent=String(ids.length);all.checked=ids.length===inputs.length&&ids.length>0;all.indeterminate=ids.length>0&&ids.length<inputs.length;inputs.forEach(input=>input.closest('tr')?.classList.toggle('is-selected',input.checked));}
  all?.addEventListener('change',()=>{inputs.forEach(x=>x.checked=all.checked);updateSelection()});
  inputs.forEach(x=>x.addEventListener('change',updateSelection));
  root.querySelector('#proClearSelection')?.addEventListener('click',()=>{inputs.forEach(x=>x.checked=false);updateSelection()});
  root.querySelector('#proPerPage')?.addEventListener('change',(event)=>{const u=new URL(location.href);u.searchParams.set('per',event.target.value);u.searchParams.set('p','1');location.assign(u.toString())});
  let openedMenu=null;
  function closeMenu(){if(!openedMenu)return;openedMenu.menu.hidden=true;openedMenu.button.setAttribute('aria-expanded','false');openedMenu=null;}
  root.addEventListener('click',(event)=>{
    const toggle=event.target.closest('[data-menu-button]');
    if(toggle){event.preventDefault();if(openedMenu?.button===toggle){closeMenu();return;}
      closeMenu();const menu=toggle.parentElement.querySelector('.pro-menu');const r=toggle.getBoundingClientRect();menu.hidden=false;
      menu.style.top=Math.min(r.bottom+6,window.innerHeight-menu.offsetHeight-12)+'px';
      menu.style.left=Math.max(9,Math.min(window.innerWidth-menu.offsetWidth-9,r.right-menu.offsetWidth))+'px';
      toggle.setAttribute('aria-expanded','true');openedMenu={menu,button:toggle};menu.querySelector('a,button')?.focus({preventScroll:true});return;
    }
    const one=event.target.closest('[data-one]');if(one){closeMenu();ask([one.dataset.one],one.dataset.action);return;}
    if(openedMenu&&!event.target.closest('.pro-menu'))closeMenu();
    const bulk=event.target.closest('[data-bulk]');if(bulk)ask(selected(),bulk.dataset.bulk);
  });
  document.addEventListener('click',event=>{if(openedMenu&&!root.contains(event.target))closeMenu()});
  document.addEventListener('keydown',event=>{
    if(event.key==='Escape')closeMenu();
    if(event.key==='/'&&!['INPUT','TEXTAREA','SELECT'].includes(document.activeElement?.tagName)){event.preventDefault();document.querySelector('#proFilters input[name=q]')?.focus()}
  });
  window.addEventListener('scroll',closeMenu,true);
  const descriptions={archive:'আর্কাইভ (Soft Delete)',active:'সক্রিয় করুন',pending:'অপেক্ষমাণ করুন',export_xlsx:'Excel ডাউনলোড',export_csv:'CSV ডাউনলোড',restore:'Restore'};
  function ask(ids,action){if(!ids.length)return;
    modalTitle.textContent=descriptions[action]||'অ্যাকশন';
    modalText.textContent=ids.length+'টি নির্বাচিত রেকর্ডে এই কাজটি সম্পন্ন করতে চান?';
    dangerous.hidden=action!=='archive';
    btnOk.textContent=action==='archive'?'আর্কাইভ নিশ্চিত করুন':(action.startsWith('export')?'ডাউনলোড করুন':'নিশ্চিত করুন');
    btnOk.className='btn '+(action==='archive'?'btn-danger':'btn-brand');
    if(typeof dialog.showModal==='function')dialog.showModal();else dialog.setAttribute('open','');
    btnOk.onclick=()=>{
      container.replaceChildren();for(const id of ids){const el=document.createElement('input');el.type='hidden';el.name='ids[]';el.value=String(id);container.append(el);}
      form.querySelector('[name=action_type]').value=action;
      if(typeof dialog.close==='function')dialog.close();else dialog.removeAttribute('open');
      if(action.startsWith('export')){form.target='_blank';setTimeout(()=>{form.target=''},200);}else form.removeAttribute('target');
      form.submit();
    };
    btnOk.focus();
  }
  dialog.querySelectorAll('[data-cancel]').forEach(btn=>btn.addEventListener('click',()=>typeof dialog.close==='function'?dialog.close():dialog.removeAttribute('open')));
  dialog.addEventListener('click',event=>{if(event.target===dialog)dialog.close?.()});
})();
