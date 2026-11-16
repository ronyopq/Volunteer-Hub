/* PRAAN 1.8 secure offline forms: explicit opt-in, AES-256-GCM, password-derived key,
 * no plaintext form values, passwords, CSRF, photos or auth tokens are persisted.
 * Manual retry after closing tab; opportunistic retry on online event while unlocked in RAM.
 */
(()=>{'use strict';
  if(document.querySelector('meta[name="praan-offline-queue"]')?.content!=='1')return;
  const actor=Number(document.querySelector('meta[name="praan-actor"]')?.content||0);
  if(!actor||!crypto?.subtle||!window.indexedDB||!window.isSecureContext)return;
  const DB='praan-encrypted-offline-queue', STORE='records', MAX=Math.min(20,Math.max(5,Number(document.querySelector('meta[name="praan-queue-limit"]')?.content)||20));
  const passwords=new Map();let sending=false;
  const encoder=new TextEncoder();const decoder=new TextDecoder();
  const form=document.querySelector('#volunteerForm[data-praan-offline-form="1"]');
  const dialog=document.getElementById('praanQueueDialog'), fab=document.getElementById('praanQueueFab');
  const rows=document.getElementById('praanQueueRows'),count=document.getElementById('praanQueueCount');
  const openDB=()=>new Promise((resolve,reject)=>{
    const r=indexedDB.open(DB,1);
    r.onupgradeneeded=()=>{if(!r.result.objectStoreNames.contains(STORE))r.result.createObjectStore(STORE,{keyPath:'id'})};
    r.onsuccess=()=>resolve(r.result);r.onerror=()=>reject(r.error||Error('IndexedDB inaccessible'));
  });
  const transact=async(mode,method,value)=>{
    const db=await openDB();return new Promise((resolve,reject)=>{
      const tx=db.transaction(STORE,mode);const store=tx.objectStore(STORE);
      const req=value===undefined?store[method]():store[method](value);
      req.onsuccess=()=>resolve(req.result);req.onerror=()=>reject(req.error||Error('Storage error'));
      tx.oncomplete=()=>db.close();tx.onerror=()=>db.close();tx.onabort=()=>db.close();
    });
  };
  const list=async()=>((await transact('readonly','getAll')).filter(r=>r.actor===actor)).sort((a,b)=>a.time-b.time);
  const del=async id=>transact('readwrite','delete',id);
  const rnd=len=>crypto.getRandomValues(new Uint8Array(len));
  const derive=async(password,salt)=>{
    const base=await crypto.subtle.importKey('raw',encoder.encode(password),'PBKDF2',false,['deriveKey']);
    return crypto.subtle.deriveKey({name:'PBKDF2',salt,iterations:220000,hash:'SHA-256'},base,{name:'AES-GCM',length:256},false,['encrypt','decrypt']);
  };
  const encrypt=async(value,password)=>{
    const salt=rnd(16),iv=rnd(12);const key=await derive(password,salt);
    const bytes=encoder.encode(JSON.stringify(value));const ciphertext=await crypto.subtle.encrypt({name:'AES-GCM',iv},key,bytes);
    return {salt:Array.from(salt),iv:Array.from(iv),ciphertext};
  };
  const decrypt=async(value,password)=>{
    const key=await derive(password,new Uint8Array(value.salt));
    return JSON.parse(decoder.decode(await crypto.subtle.decrypt({name:'AES-GCM',iv:new Uint8Array(value.iv)},key,value.ciphertext)));
  };
  const button=(label,cls='btn btn-sm btn-outline-secondary')=>{const b=document.createElement('button');b.type='button';b.textContent=label;b.className=cls;return b};
  const message=(txt,error=false)=>{
    let box=document.getElementById('praanOfflineNotice');if(!box){box=document.createElement('div');box.id='praanOfflineNotice';box.className='praan-offline-notice';document.body.append(box)}
    box.textContent=txt;box.dataset.error=error?'1':'0';box.hidden=false;clearTimeout(box._time);box._time=setTimeout(()=>box.hidden=true,5500);
  };
  const passwordPrompt=(isNew)=>new Promise(resolve=>{
    const modal=document.createElement('dialog');modal.className='praan-encrypt-modal';modal.setAttribute('aria-label','কিউ এনক্রিপশন');
    const wrap=document.createElement('form');wrap.method='dialog';
    const h=document.createElement('h3');h.textContent=isNew?'নিরাপদ অফলাইন ড্রাফট':'ড্রাফট আনলক করুন';
    const desc=document.createElement('p');desc.textContent=isNew?'এই ডিভাইসে ফর্মের তথ্য এনক্রিপ্ট হবে। পাসফ্রেজ ভুলে গেলে ড্রাফট আর খোলা যাবে না। ছবি আলাদা করে আপলোড করতে হবে।':'ড্রাফটের পাসফ্রেজ দিন। এটি সার্ভার বা ব্রাউজার স্টোরেজে রাখা হয়নি।';
    const input=document.createElement('input');input.type='password';input.required=true;input.minLength=12;input.placeholder='অন্তত ১২ অক্ষরের পাসফ্রেজ';input.autocomplete='off';input.className='form-control';
    const bar=document.createElement('div');bar.className='d-flex gap-2 justify-content-end mt-3';
    const cancel=button('বাতিল');const save=button(isNew?'এনক্রিপ্ট করে রাখুন':'আনলক করুন','btn btn-sm btn-brand');
    save.type='submit';cancel.addEventListener('click',()=>modal.close());
    wrap.addEventListener('submit',e=>{e.preventDefault();if(input.value.length<12){input.setCustomValidity('কমপক্ষে ১২ অক্ষর দিন');input.reportValidity();return}const value=input.value;modal._value=value;modal.close()});
    bar.append(cancel,save);wrap.append(h,desc,input,bar);modal.append(wrap);document.body.append(modal);
    modal.addEventListener('close',()=>{const val=modal._value||null;modal.remove();resolve(val)},{once:true});modal.showModal();input.focus();
  });
  function formPairs(){const values=[];const fd=new FormData(form);
    fd.forEach((value,key)=>{
      if(value instanceof File || ['csrf','photo','admin_password','password'].includes(key))return;
      values.push([key,String(value)]);
    });return values;
  }
  async function render(){
    if(!rows)return;
    const all=await list();rows.replaceChildren();
    if(count){count.textContent=String(all.length);count.hidden=all.length===0}
    if(!all.length){const p=document.createElement('p');p.className='text-muted';p.textContent='এই অ্যাডমিনের এনক্রিপ্টেড কিউ খালি।';rows.append(p);return}
    for(const item of all){
      const row=document.createElement('div');row.className='praan-queue-row';
      const info=document.createElement('div');const strong=document.createElement('strong');strong.textContent='ড্রাফট '+item.id.slice(0,16)+'…';
      const time=document.createElement('small');time.className='d-block';time.textContent=new Date(item.time).toLocaleString('bn-BD');info.append(strong,time);
      const actions=document.createElement('div');actions.className='praan-queue-actions';
      const sync=button('পাঠান','btn btn-sm btn-outline-success'),remove=button('মুছুন','btn btn-sm btn-outline-danger');
      sync.addEventListener('click',async()=>{
        let pass=passwords.get(item.id);if(!pass)pass=await passwordPrompt(false);if(!pass)return;
        passwords.set(item.id,pass);await replay(item,pass);
      });
      remove.addEventListener('click',async()=>{
        if(!confirm('এই ডিভাইসের এনক্রিপ্টেড ড্রাফট স্থায়ীভাবে মুছবেন? সার্ভারে পাঠানো হয়নি।'))return;
        await del(item.id);passwords.delete(item.id);await render();message('কিউ থেকে ড্রাফট সরানো হয়েছে');
      });
      actions.append(sync,remove);row.append(info,actions);rows.append(row);
    }
  }
  async function queueCurrent(){
    if(!form)return;
    if(!form.querySelector('[name="data[name_bn]"]')?.value?.trim()&&!form.querySelector('[name="data[name_en]"]')?.value?.trim()){
      message('কমপক্ষে বাংলা বা ইংরেজি নাম দিন।',true);return;
    }
    const current=await list();const uid=form.querySelector('[name="draft_uid"]')?.value||'';
    if(!/^DRF-[0-7][0-9A-HJKMNP-TV-Z]{25}$/.test(uid)){message('ড্রাফট আইডি পাওয়া যায়নি; পেজ রিফ্রেশ করুন।',true);return}
    if(current.length>=MAX&&!current.some(r=>r.id===uid)){message('অফলাইন কিউ পূর্ণ হয়েছে। আগে কিছু রেকর্ড পাঠান।',true);return}
    if(form.querySelector('input[type=file]')?.files?.length)message('ছবি অফলাইন কিউতে রাখা হবে না; পরে রেকর্ডে আবার যোগ করুন।');
    const pass=await passwordPrompt(true);if(!pass)return;
    const pairs=formPairs();
    const cipher=await encrypt({v:1,pairs},pass);
    const old=current.find(r=>r.id===uid);
    await transact('readwrite','put',{id:uid,actor,time:old?.time||Date.now(),attempts:0,...cipher});
    passwords.set(uid,pass);
    await render();message('ড্রাফট এনক্রিপ্ট হয়ে এই ডিভাইসে সংরক্ষিত হয়েছে।');
    // When online: do not silently overwrite the normal submit flow; let the user explicitly sync.
  }
  async function status(){
    const r=await fetch('index.php?page=offline_status',{credentials:'same-origin',cache:'no-store',headers:{Accept:'application/json'}});
    if(!r.ok)throw Error('লগইন সেশন শেষ অথবা অফলাইন। আগে লগইন করুন।');
    const json=await r.json();if(!json.ok||!json.enabled||Number(json.actor_id)!==actor)throw Error('অন্য অ্যাকাউন্ট বা Offline Queue বন্ধ রয়েছে।');
    return json;
  }
  async function replay(item,pass){
    if(sending)return; sending=true;
    try{
      const json=await decrypt(item,pass);if(json.v!==1||!Array.isArray(json.pairs))throw Error('ড্রাফটের ফরম্যাট সঠিক নয়');
      const live=await status();
      const body=new FormData();body.append('csrf',live.csrf);
      for(const [name,value] of json.pairs){if(name!=='csrf'&&name!=='photo'&&typeof name==='string'&&typeof value==='string')body.append(name,value);}
      const response=await fetch('index.php?page=save',{method:'POST',credentials:'same-origin',cache:'no-store',headers:{'X-PRAAN-AJAX':'1','Accept':'application/json'},body});
      const result=await response.json().catch(()=>null);
      if(!response.ok||!result?.ok)throw Error(result?.message||'সার্ভার ফর্ম সংরক্ষণ করেনি।');
      await del(item.id);passwords.delete(item.id);await render();
      message('ড্রাফট সফলভাবে সার্ভারে সংরক্ষিত হয়েছে'+(result.already_saved?' (আগেই সংরক্ষিত ছিল)':'')+'।');
      if(form?.querySelector('[name="draft_uid"]')?.value===item.id){
        const note=document.createElement('a');note.className='btn btn-brand';note.href=result.redirect_url;note.textContent='সংরক্ষিত রেকর্ড দেখুন';form.prepend(note);
      }
    }catch(error){
      message('পাঠানো যায়নি: '+(error.name==='OperationError'?'পাসফ্রেজ ভুল অথবা ডাটা ক্ষতিগ্রস্ত।':error.message),true);
    }finally{sending=false;}
  }
  async function retryUnlocked(){
    if(sending||!navigator.onLine)return;
    const all=await list();for(const item of all){const p=passwords.get(item.id);if(p){await replay(item,p);if(!navigator.onLine)return;}}
  }
  document.getElementById('praanOfflineSave')?.addEventListener('click',()=>queueCurrent().catch(e=>message(e.message,true)));
  fab?.addEventListener('click',async()=>{try{await render();dialog.showModal()}catch(e){message('অফলাইন স্টোরেজ চালু নেই: '+e.message,true)}});
  dialog?.querySelector('[data-praan-queue-close]')?.addEventListener('click',()=>dialog.close());
  dialog?.addEventListener('click',event=>{if(event.target===dialog)dialog.close()});
  // Admin new form only. Original normal online submit still works untouched.
  form?.addEventListener('submit',event=>{
    if(navigator.onLine)return;
    event.preventDefault();event.stopImmediatePropagation();
    if(confirm('ইন্টারনেট নেই। ছবিবিহীন ফর্মটি পাসফ্রেজে এনক্রিপ্ট করে এই ডিভাইসে রাখবেন?')){
      queueCurrent().catch(e=>message(e.message,true));
    }
  },true);
  window.addEventListener('online',()=>retryUnlocked().catch(()=>{}));
  render().catch(()=>{});
})();
