(function(){
  'use strict';
  document.querySelectorAll('.theme-toggle').forEach(button=>button.addEventListener('click',()=>{
    const current=document.documentElement.getAttribute('data-bs-theme')||'light';
    const next=current==='dark'?'light':'dark';
    document.documentElement.setAttribute('data-bs-theme',next);
    try{localStorage.setItem('pran_theme',next)}catch(e){}
  }));
  // Predictable mobile / desktop sidebar control, independent of CDN JS.
  const sidebarToggle=document.getElementById('pranSidebarToggle');
  const sidebar=document.getElementById('pranAdminSidebar');
  if(sidebarToggle && sidebar){
    const backdrop=document.createElement('button');
    backdrop.type='button';
    backdrop.className='pran-sidebar-backdrop';
    backdrop.setAttribute('aria-label','মেনু বন্ধ করুন');
    document.body.appendChild(backdrop);
    const mobile=()=>window.matchMedia('(max-width:991.98px)').matches;
    const sync=()=>{
      const expanded=mobile() ? document.body.classList.contains('pran-sidebar-open') : !document.body.classList.contains('pran-sidebar-collapsed');
      sidebarToggle.setAttribute('aria-expanded',String(expanded));
    };
    // Stop the conflicting AdminLTE data-api handler on this toggle only.
    sidebarToggle.addEventListener('click',event=>{
      event.preventDefault();event.stopPropagation();event.stopImmediatePropagation();
      if(mobile()){
        document.body.classList.toggle('pran-sidebar-open');
      } else {
        document.body.classList.toggle('pran-sidebar-collapsed');
      }
      sync();
    },true);
    backdrop.addEventListener('click',()=>{document.body.classList.remove('pran-sidebar-open');sync()});
    document.addEventListener('keydown',event=>{if(event.key==='Escape') {document.body.classList.remove('pran-sidebar-open');sync()}});
    window.addEventListener('resize',()=>{document.body.classList.remove('pran-sidebar-open');sync()});
    sidebar.querySelectorAll('a.nav-link').forEach(a=>a.addEventListener('click',()=>{if(mobile())document.body.classList.remove('pran-sidebar-open')}));
    sync();
  }
  const wizard=document.querySelector('[data-wizard]');
  if(wizard){
    const panels=[...wizard.querySelectorAll('.form-step')];
    const buttons=[...wizard.querySelectorAll('.step-link')];
    const prev=wizard.querySelector('#stepPrev');
    const next=wizard.querySelector('#stepNext');
    const submit=wizard.querySelector('#formSubmit');
    const stepCurrent=wizard.querySelector('#stepCurrent');
    let index=0;
    function showStep(n){
      index=Math.max(0,Math.min(n,panels.length-1));
      panels.forEach((node,i)=>node.classList.toggle('active',i===index));
      buttons.forEach((node,i)=>{node.classList.toggle('current',i===index);node.classList.toggle('visited',i<index);node.setAttribute('aria-current',i===index?'step':'false')});
      stepCurrent.textContent=String(index+1);
      prev.style.display=index===0?'none':'';
      next.style.display=index===panels.length-1?'none':'';
      submit.style.display=index===panels.length-1?'':'none';
      const heading=panels[index].querySelector('.form-section-heading');
      if(heading && window.scrollY>300) heading.scrollIntoView({behavior:'smooth',block:'start'});
    }
    buttons.forEach(button=>button.addEventListener('click',()=>showStep(Number(button.dataset.go))));
    prev.addEventListener('click',()=>showStep(index-1));
    next.addEventListener('click',()=>showStep(index+1));
    showStep(0);
  }
  const copy=document.querySelector('#copyAddress');
  if(copy){copy.addEventListener('click',()=>{
    for(const field of ['holding','village','ward','union','upazila','district']){
      const p=document.querySelector('[name="data[present_'+field+']"]');
      const dest=document.querySelector('[name="data[permanent_'+field+']"]');
      if(p&&dest) dest.value=p.value;
    }
    copy.innerHTML='<i class="bi bi-check-lg"></i> ঠিকানা কপি হয়েছে';
  });}
  const form=document.getElementById('volunteerForm');
  if(form){
    form.addEventListener('submit',e=>{
      const checked=[...form.querySelectorAll('input[name="data[consents][]"]:checked')].map(n=>n.value);
      const isPublic=window.location.search.includes('page=register');
      const mandatory=['code','safeguarding','confidentiality','data','emergency'];
      if(isPublic && !mandatory.every(x=>checked.includes(x))){
        e.preventDefault(); alert('আচরণবিধি ও তথ্য ব্যবহারের প্রথম পাঁচটি সম্মতি দিতে হবে। ছবি/ভিডিওর সম্মতি ঐচ্ছিক।');
        if(wizard) {const btn=document.querySelector('.step-link[data-go="4"]');if(btn)btn.click()}
        return;
      }
      const submit=form.querySelector('#formSubmit');
      if(submit){submit.disabled=true;submit.innerText='সংরক্ষণ হচ্ছে...';}
    });
  }
})();
