/* PRAAN v1.4.0 progressive enhancements; no third-party tracker or network beacon. */
(()=>{'use strict';
  const toast=(message,kind='success')=>{
    const el=document.createElement('div');el.className='praan-draft-toast';el.setAttribute('role','status');
    const text=document.createElement('span');text.textContent=(kind==='danger'?'⚠ ':'✓ ')+message;el.append(text);
    const close=document.createElement('button');close.type='button';close.textContent='বন্ধ';close.addEventListener('click',()=>el.remove());el.append(close);
    document.body.append(el);setTimeout(()=>el.remove(),6500);
  };
  const modal=document.getElementById('praanDeveloperDialog');
  let clicks=Number(sessionStorage.getItem('praan_logo_taps')||'0');
  const openCredit=()=>{
    if(!modal)return;modal.showModal?.();
    const screen=document.getElementById('praanEmblem');if(screen){screen.animate([{transform:'rotate(-11deg) scale(.7)'},{transform:'rotate(6deg) scale(1.15)'},{transform:'rotate(-6deg) scale(1)'}],{duration:650,easing:'ease-out'});}
  };
  document.querySelectorAll('[data-praan-credit]').forEach(button=>button.addEventListener('click',openCredit));
  document.querySelectorAll('[data-praan-close]').forEach(button=>button.addEventListener('click',()=>modal?.close()));
  modal?.addEventListener('click',e=>{if(e.target===modal)modal.close();});
  document.querySelectorAll('.sidebar-logo,.public-brand img').forEach(img=>img.addEventListener('click',e=>{clicks++;try{sessionStorage.setItem('praan_logo_taps',String(clicks));}catch(_e){}if(clicks>=5){e.preventDefault();clicks=0;try{sessionStorage.removeItem('praan_logo_taps');}catch(_e){}openCredit();toast('PRAAN • পাঁচবার ক্লিকের সারপ্রাইজ!');}}));
  const wa=document.getElementById('praanWhatsapp');
  wa?.addEventListener('click',(event)=>{
    const device=[navigator.platform||'unknown',navigator.userAgent||'browser'].join(' · ').slice(0,210);
    const message=['PRAAN Volunteer Hub Support','App version: '+(document.documentElement.dataset.praanVersion||'?'),'Device: '+device,'Screen: '+screen.width+'x'+screen.height,'Page: '+location.origin+location.pathname,'সমস্যা: '].join('\n');
    const number=(wa.dataset.phone||'').replace(/\D/g,'');
    if(!number){event.preventDefault();toast('সাপোর্ট WhatsApp নম্বর সেটিংস থেকে যোগ করুন।','danger');return;}
    wa.href='https://wa.me/'+number+'?text='+encodeURIComponent(message);
  });
  document.querySelectorAll('.praan-control-tab[data-group]').forEach(button=>button.addEventListener('click',()=>{
    document.querySelectorAll('.praan-control-tab').forEach(b=>b.classList.remove('active'));button.classList.add('active');
    document.getElementById('group-'+button.dataset.group)?.scrollIntoView({behavior:'smooth',block:'start'});
  }));
  document.getElementById('praanSettingSearch')?.addEventListener('input',e=>{
    const term=e.target.value.toLocaleLowerCase();
    document.querySelectorAll('.praan-setting-field').forEach(el=>{el.hidden=!el.dataset.search.toLocaleLowerCase().includes(term);});
    document.querySelectorAll('.praan-setting-group').forEach(group=>{group.hidden=!!term&&!group.querySelector('.praan-setting-field:not([hidden])');});
  });
  const bg=document.getElementById('praanBackgroundUpload');
  bg?.addEventListener('change',async()=>{
    if(!bg.files?.length)return;
    const label=document.getElementById('praanBackgroundProgress');
    const body=new FormData();body.append('image',bg.files[0]);body.append('csrf',bg.dataset.csrf||'');
    if(label)label.textContent='⏳ ছবি আপলোড হচ্ছে...';
    try{
      const res=await fetch(bg.dataset.upload,{method:'POST',credentials:'same-origin',body,headers:{'Accept':'application/json'}});
      const json=await res.json();if(!res.ok||!json.ok)throw Error(json.message||'Upload error');
      if(label)label.textContent='✅ '+json.message;const preview=document.querySelector('.praan-bg-preview');if(preview)preview.src=json.url;else{const picture=document.createElement('img');picture.className='praan-bg-preview';picture.alt='ব্যাকগ্রাউন্ড প্রিভিউ';picture.src=json.url;bg.after(picture);}toast(json.message);
    }catch(err){if(label)label.textContent='⚠ আপলোড ব্যর্থ';toast(err.message||'আপলোড সম্ভব হয়নি','danger');}
  });
  document.getElementById('praanBackgroundRemove')?.addEventListener('click',async e=>{
    if(!confirm('বর্তমান ব্যাকগ্রাউন্ড ছবি মুছবেন?'))return;
    const button=e.currentTarget;const body=new FormData();body.append('csrf',button.dataset.csrf||'');
    try{const response=await fetch(button.dataset.action,{method:'POST',body,credentials:'same-origin'});const data=await response.json();if(!data.ok)throw Error(data.message);button.previousElementSibling?.remove();button.remove();toast('ব্যাকগ্রাউন্ড সরানো হয়েছে');}catch(err){toast(err.message,'danger');}
  });
  const form=document.getElementById('volunteerForm');
  if(form?.dataset.praanDraft==='1'){
    const storage=form.dataset.praanDraftScope==='local'?localStorage:sessionStorage;
    const draftInput=form.querySelector('input[name="draft_uid"]');
    const uid=draftInput?.value||'new';
    const key='praan_draft_'+(form.dataset.praanDraftKey||uid);
    // Minimise unencrypted draft storage to non-identifying selections only.
    // Full personal records are persisted ONLY via explicit passphrase-encrypted IndexedDB queue.
    const safeNames=new Set(['data[project]','data[interests][]','data[availability][]','data[time_preferences][]','data[travel_readiness][]','data[digital_skills][]','data[status]','data[category]']);
    const isPrivate=name=>!safeNames.has(name);
    // Clean legacy drafts created before v1.8.0 without silently discarding non-sensitive choices.
    try {const old=storage.getItem(key);if(old){const obj=JSON.parse(old);if(obj?.values){obj.values=Object.fromEntries(Object.entries(obj.values).filter(([name])=>safeNames.has(name)));storage.setItem(key,JSON.stringify(obj));}}}catch(_e){}
    const snapshot=()=>{
      const values={};
      [...form.elements].forEach(el=>{
        if(!el.name||isPrivate(el.name)||el.type==='file'||el.type==='hidden'||el.disabled) return;
        if(el.type==='checkbox'||el.type==='radio'){
          if(!Object.prototype.hasOwnProperty.call(values,el.name))values[el.name]=[];
          if(el.checked)values[el.name].push(el.value);
        }else values[el.name]=el.value;
      });
      return JSON.stringify({v:1,created:Date.now(),draft_uid:draftInput?.value||'',values});
    };
    const restore=(values)=>{
      [...form.elements].forEach(el=>{
        if(!el.name||isPrivate(el.name)||el.type==='file'||el.type==='hidden')return;
        if(!(el.name in values))return;
        if(el.type==='checkbox'||el.type==='radio')el.checked=Array.isArray(values[el.name])&&values[el.name].includes(el.value);
        else el.value=typeof values[el.name]==='string'?values[el.name]:el.value;
      });
      toast('ড্রাফট রিস্টোর হয়েছে; সংবেদনশীল ঘরগুলো পুনরায় যাচাই করুন।');
    };
    try{
      const raw=storage.getItem(key);const parsed=raw?JSON.parse(raw):null;
      if(parsed&&parsed.v===1&&Date.now()-parsed.created<86400000&&parsed.values){
        if(draftInput && /^DRF-[0-9A-HJKMNP-TV-Z]{26}$/.test(parsed.draft_uid||'')) draftInput.value=parsed.draft_uid;
        const panel=document.createElement('div');panel.className='praan-draft-toast';
        const label=document.createElement('div');label.textContent='অসম্পূর্ণ ড্রাফট পাওয়া গেছে। শুধুমাত্র অ-ব্যক্তিগত পছন্দগুলো ফিরবে; নাম, ফোন ও ঠিকানা পুনরায় দিন।';panel.append(label);
        const yes=document.createElement('button');yes.textContent='ড্রাফট ফিরিয়ে আনুন';yes.onclick=()=>{restore(parsed.values);panel.remove();};
        const no=document.createElement('button');no.textContent='মুছে নতুন শুরু';no.onclick=()=>{storage.removeItem(key);panel.remove();};
        panel.append(yes,no);document.body.append(panel);
      }
    }catch(_e){}
    let changed=false;form.addEventListener('input',()=>{changed=true;});form.addEventListener('change',()=>{changed=true;});
    setInterval(()=>{if(!changed)return;changed=false;try{storage.setItem(key,snapshot());}catch(_e){}},2000);
    form.addEventListener('submit',()=>{try{sessionStorage.setItem('praan_draft_just_submitted',key);}catch(_e){}});
  }
  if(['view','submitted'].includes(new URLSearchParams(location.search).get('page'))){
    try{const key=sessionStorage.getItem('praan_draft_just_submitted');if(key){localStorage.removeItem(key);sessionStorage.removeItem(key);sessionStorage.removeItem('praan_draft_just_submitted');}}catch(_e){}
  }
})();
