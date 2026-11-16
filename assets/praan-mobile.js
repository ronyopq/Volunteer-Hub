/* PRAAN v1.8.0 — progressive enhancement, no tracking, no personal-data caching. */
(()=>{'use strict';
  const opt=name=>document.querySelector(`meta[name="${name}"]`)?.content==='1';
  const version=document.documentElement.dataset.praanVersion||'1.8.0';
  const motionReduced=matchMedia('(prefers-reduced-motion: reduce)').matches||document.documentElement.dataset.praanMotion==='reduce';
  // Only static application shell is pre-cached. HTML, API, photos and personal reports are always network-only.
  if('serviceWorker' in navigator){
    if(opt('praan-pwa'))navigator.serviceWorker.register('./service-worker.js',{scope:'./',updateViaCache:'none'})
      .then(r=>{r.update().catch(()=>{});}).catch(()=>{});
    else navigator.serviceWorker.getRegistrations().then(list=>list.filter(r=>r.scope.endsWith(new URL('./',location.href).pathname)).forEach(r=>r.unregister())).catch(()=>{});
  }
  let installEvent=null;
  const installButtons=()=>Array.from(document.querySelectorAll('[data-praan-install]'));
  const installed=()=>matchMedia('(display-mode: standalone)').matches||!!navigator.standalone;
  window.addEventListener('beforeinstallprompt',event=>{
    event.preventDefault();installEvent=event;
    if(!installed()) installButtons().forEach(b=>{b.hidden=false;});
  });
  window.addEventListener('appinstalled',()=>{installEvent=null;installButtons().forEach(b=>b.hidden=true);});
  // iOS Safari lacks beforeinstallprompt; show a helpful fallback Install control.
  if(opt('praan-pwa') && !installed() && /iPhone|iPad|iPod/i.test(navigator.userAgent)) {
    installButtons().forEach(b=>b.hidden=false);
  }
  const connection=navigator.connection||navigator.mozConnection||navigator.webkitConnection;
  const syncConnection=()=>{
    if(connection?.saveData||['slow-2g','2g'].includes(connection?.effectiveType))document.documentElement.dataset.praanLowData='1';
    else delete document.documentElement.dataset.praanLowData;
  };
  if(connection){syncConnection();connection.addEventListener?.('change',syncConnection)}
  installButtons().forEach(b=>b.addEventListener('click',async()=>{
    if(!installEvent){alert('আপনার ব্রাউজারের মেনু থেকে “Install app” বা “Add to Home Screen” ব্যবহার করুন।');return;}
    const event=installEvent;installEvent=null;await event.prompt();
    installButtons().forEach(item=>item.hidden=true);
  }));

  // Accessible command palette; links were permission-filtered on the server.
  const dialog=document.getElementById('praanCommandDialog');
  const search=document.getElementById('praanCommandInput');
  const links=dialog?[...dialog.querySelectorAll('.praan-command-item')]:[];
  const close=()=>dialog?.close();
  function filter(term){
    const norm=term.trim().toLocaleLowerCase();
    links.forEach(link=>{link.hidden=!((link.dataset.commandLabel||'').toLocaleLowerCase().includes(norm))});
    const menuAllowed=links.some(link=>link.dataset.commandLabel==='স্বেচ্ছাসেবক তালিকা');
    const old=dialog.querySelector('[data-praan-search-volunteers]');if(old)old.remove();
    if(norm&&menuAllowed){
      const action=document.createElement('a');action.className='praan-command-item praan-command-search';action.dataset.praanSearchVolunteers='1';
      action.href='index.php?page=volunteers&q='+encodeURIComponent(term.trim().slice(0,120));
      const icon=document.createElement('i');icon.className='bi bi-search';const label=document.createElement('span');label.textContent='স্বেচ্ছাসেবক খুঁজুন: '+term.trim().slice(0,60);action.append(icon,label);dialog.querySelector('#praanCommandResults').prepend(action);
    }
  }
  function open(){if(!dialog||dialog.open)return;dialog.showModal();search.value='';filter('');search.focus();}
  document.querySelectorAll('[data-praan-command-open]').forEach(button=>button.addEventListener('click',open));
  dialog?.querySelector('[data-praan-command-close]')?.addEventListener('click',close);
  dialog?.addEventListener('click',event=>{if(event.target===dialog)close()});
  search?.addEventListener('input',()=>filter(search.value));
  search?.addEventListener('keydown',event=>{
    const displayed=[...dialog.querySelectorAll('.praan-command-item:not([hidden])')];
    const current=displayed.findIndex(link=>link===document.activeElement);
    if(event.key==='ArrowDown'){event.preventDefault();(displayed[Math.max(0,current+1)]||displayed[0])?.focus()}
    if(event.key==='Enter'&&displayed.length){event.preventDefault();location.assign(displayed[0].href)}
  });
  dialog?.addEventListener('keydown',event=>{
    const displayed=[...dialog.querySelectorAll('.praan-command-item:not([hidden])')];
    const current=displayed.indexOf(document.activeElement);
    if(current!==-1&&(event.key==='ArrowUp'||event.key==='ArrowDown')){
      event.preventDefault();const next=event.key==='ArrowUp'?(current===0?-1:current-1):(current===displayed.length-1?0:current+1);
      if(next===-1)search.focus();else displayed[next]?.focus();
    }
  });
  document.addEventListener('keydown',event=>{
    if(!dialog)return;
    if((event.ctrlKey||event.metaKey)&&event.key.toLowerCase()==='k'){event.preventDefault();if(dialog.open)close();else open();return;}
    if(event.key==='/'&&!event.ctrlKey&&!event.metaKey&&!event.altKey&&!event.repeat&&!event.isComposing&&
      !['INPUT','TEXTAREA','SELECT'].includes(document.activeElement?.tagName)&&!document.activeElement?.isContentEditable){event.preventDefault();open()}
  });
  // Mobile nav reflects actual location, no guess based on private server state.
  const page=new URLSearchParams(location.search).get('page')||'home';
  document.querySelectorAll('.praan-bottom-link[data-page]').forEach(link=>{
    if(link.dataset.page===page){link.classList.add('active');link.setAttribute('aria-current','page')}
  });
  // Skeleton overlay is only shown after intentional same-origin page navigation.
  if(!motionReduced){
    const loader=document.createElement('div');loader.className='praan-page-transition';loader.setAttribute('aria-hidden','true');
    loader.innerHTML='<span class="praan-page-progress"></span><span class="praan-page-skeleton"></span>';
    document.body.append(loader);
    document.addEventListener('click',event=>{
      const link=event.target.closest('a[href]');if(!link||event.defaultPrevented||event.button!==0||event.metaKey||event.ctrlKey||event.shiftKey||event.altKey||link.target==='\u005fblank'||link.hasAttribute('download'))return;
      let to;try{to=new URL(link.href,location.href)}catch{return;}
      if(to.origin!==location.origin||to.pathname!==location.pathname||to.href===location.href||to.hash&&to.pathname===location.pathname&&to.search===location.search)return;
      document.body.classList.add('praan-navigating');
    });
    window.addEventListener('pageshow',()=>document.body.classList.remove('praan-navigating'));
  }
  if(!navigator.onLine)document.documentElement.classList.add('praan-offline');
  window.addEventListener('online',()=>document.documentElement.classList.remove('praan-offline'));
  window.addEventListener('offline',()=>document.documentElement.classList.add('praan-offline'));
  const bar=document.createElement('div');bar.className='praan-connection-pill';bar.setAttribute('role','status');bar.textContent='ইন্টারনেট সংযোগ নেই · নিরাপদ ড্রাফট কিউ ব্যবহার করুন';document.body.append(bar);
})();
