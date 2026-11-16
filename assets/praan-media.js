/* PRAAN v1.5.0: resumable media + early form save. No third-party upload service. */
(()=>{'use strict';
  const form=document.getElementById('volunteerForm');
  if(!form || form.dataset.praanAsync!=='1')return;
  const input=form.querySelector('[data-praan-photo]');
  const draft=form.querySelector('[name="draft_uid"]');
  const csrf=form.querySelector('[name="csrf"]');
  const result=document.getElementById('praanEarlySaveResult');
  if(!input||!draft||!csrf)return;
  const enabled=!!(window.crypto?.subtle && window.FormData && window.XMLHttpRequest);
  const pageUrl=form.dataset.praanMediaEndpoint||'index.php';
  const api=page=>pageUrl+'?page='+encodeURIComponent(page);
  const recordId=Number(form.querySelector('[name="id"]')?.value||'0');
  const scopeKey=form.dataset.praanDraftKey||'public-new';
  const uidKey='praan_media_active_uid_'+scopeKey;
  let state=null, active=false, running=null, abort=null, selected=null, saved=false, uploadCompleted=false;
  const toast=document.createElement('aside');toast.className='praan-media-toast';toast.hidden=true;
  toast.setAttribute('role','status');toast.setAttribute('aria-live','polite');
  toast.innerHTML='<div class="praan-media-top"><span class="praan-media-indicator">⬆</span><strong id="praanMediaTitle">ছবি আপলোড</strong><button type="button" class="praan-media-minimize" aria-label="আপলোডের বিস্তারিত দেখান বা লুকান" aria-expanded="true">−</button></div><div class="praan-media-details"><p id="praanMediaDescription">আপলোড প্রস্তুত হচ্ছে…</p><div class="praan-media-track" role="progressbar" aria-label="ছবি আপলোডের অগ্রগতি" aria-valuemin="0" aria-valuemax="100" aria-valuenow="0"><div class="praan-media-fill"></div></div><small id="praanMediaFoot">নিরাপদ সংযোগ · ১ MB চাংক</small></div>';
  document.body.append(toast);
  const title=toast.querySelector('#praanMediaTitle');const description=toast.querySelector('#praanMediaDescription');const foot=toast.querySelector('#praanMediaFoot');
  const track=toast.querySelector('.praan-media-track');const fill=toast.querySelector('.praan-media-fill');
  const toggle=toast.querySelector('.praan-media-minimize');
  toggle.addEventListener('click',()=>{const collapsed=toast.classList.toggle('compact');toggle.setAttribute('aria-expanded',String(!collapsed));toggle.textContent=collapsed?'+':'−';});
  const memory=window.sessionStorage; // Short-lived bearer resume-token, never stored in cross-tab localStorage.
  const stateKey=()=> 'praan_media_state_'+draft.value;
  const validUid=v=>/^DRF-[0-7][0-9A-HJKMNP-TV-Z]{25}$/.test(v||'');
  // Persist draft_uid immediately, even if no text was entered before the connection drops.
  if(!recordId){
    try{
      const cached=memory.getItem(uidKey);
      if(cached&&validUid(cached))draft.value=cached;
      if(validUid(draft.value))memory.setItem(uidKey,draft.value);
    }catch(_e){}
  }
  const saveState=()=>{try{if(state)memory.setItem(stateKey(),JSON.stringify(state));}catch(_e){}};
  const clearState=()=>{try{memory.removeItem(stateKey());}catch(_e){}};
  const readState=()=>{try{const row=JSON.parse(memory.getItem(stateKey())||'null');return row&&row.uid&&row.token&&row.sha256?row:null;}catch(_e){return null;}};
  const setMessage=(head,detail='',progress=null,footer='')=>{
    toast.hidden=false;title.textContent=head;description.textContent=detail;
    if(progress!==null){const value=Math.min(100,Math.max(0,Math.round(progress)));fill.style.width=value+'%';track.setAttribute('aria-valuenow',String(value));}
    foot.textContent=footer||'১ MB চাংক • ইন্টারনেট বিচ্ছিন্ন হলে একই ছবি পুনরায় নির্বাচন করুন';
  };
  if(!enabled){
    const hint=input.parentElement?.querySelector('.praan-upload-help');if(hint)hint.textContent='এই ব্রাউজারে ব্যাকগ্রাউন্ড আপলোড সমর্থিত নয়। ফর্ম সংরক্ষণের সময় সাধারণভাবে ছবি আপলোড হবে।';
    return;
  }
  const hex=buffer=>Array.from(new Uint8Array(buffer),b=>b.toString(16).padStart(2,'0')).join('');
  const hashFile=async file=>hex(await crypto.subtle.digest('SHA-256',await file.arrayBuffer()));
  const post=async(page,values)=>{
    const data=new FormData();data.append('csrf',csrf.value);
    Object.entries(values||{}).forEach(([key,value])=>data.append(key,String(value)));
    const response=await fetch(api(page),{method:'POST',body:data,credentials:'same-origin',headers:{'Accept':'application/json'}});
    let result;try{result=await response.json();}catch(_e){throw Error('সার্ভার JSON উত্তর দেয়নি।');}
    if(!response.ok||!result.ok)throw Error(result.message||'সার্ভারে অনুরোধ ব্যর্থ হয়েছে।');
    return result;
  };
  const credentials=()=>({media_uid:state.uid,draft_uid:draft.value,resume_token:state.token});
  const showProgress=(done,part,total,started)=>{
    const bytes=Math.min(total,done+part);const pct=Math.round(bytes*100/total);
    const sec=Math.max(1,(Date.now()-started)/1000);const speed=Math.max(1,bytes/sec);
    const eta=Math.max(0,Math.ceil((total-bytes)/speed));
    setMessage('⏳ ছবি আপলোড হচ্ছে… '+pct+'%',`${(bytes/1048576).toFixed(1)} / ${(total/1048576).toFixed(1)} MB · প্রায় ${eta} সেকেন্ড বাকি`,pct);
  };
  const chunkXHR=(file,start,chunkSize,started)=>new Promise((resolve,reject)=>{
    const len=Math.min(chunkSize,file.size-start);const part=file.slice(start,start+len);
    const body=new FormData();body.append('csrf',csrf.value);
    Object.entries(credentials()).forEach(([key,value])=>body.append(key,String(value)));
    body.append('chunk_index',String(Math.floor(start/chunkSize)));
    body.append('chunk',part,'part.bin');
    const xhr=new XMLHttpRequest();abort=()=>xhr.abort();
    xhr.open('POST',api('media_chunk'));xhr.withCredentials=true;xhr.timeout=120000;
    xhr.setRequestHeader('Accept','application/json');
    xhr.upload.addEventListener('progress',evt=>{if(evt.lengthComputable)showProgress(start,evt.loaded,file.size,started);});
    xhr.onload=()=>{
      let data;try{data=JSON.parse(xhr.responseText);}catch(_e){reject(Error('চাংক আপলোডে সার্ভারের উত্তর সঠিক নয়।'));return;}
      if(xhr.status>=200&&xhr.status<300&&data.ok)resolve(data);
      else reject(Error(data.message||'চাংক আপলোড হয়নি।'));
    };
    xhr.onerror=()=>reject(Error('নেটওয়ার্ক সংযোগ বিচ্ছিন্ন।'));
    xhr.ontimeout=()=>reject(Error('আপলোডের সময়সীমা শেষ।'));
    xhr.onabort=()=>reject(Error('আপলোড বন্ধ করা হয়েছে।'));
    xhr.send(body);
  });
  const runUpload=async(file,snapshot)=>{
    const started=Date.now();active=true;
    try{
      let status=await post('media_status',credentials());
      let offset=status.received_bytes;
      if(['ready','attached'].includes(status.status)){
        setMessage('✅ ছবি আপলোড সম্পন্ন','ফর্ম সংরক্ষিত হলে ছবিটি স্বয়ংক্রিয়ভাবে সংযুক্ত হবে।',100,'আপলোড শেষ');
        uploadCompleted=true;if(saved)input.disabled=true;clearState();return;
      }
      if(offset<0||offset>file.size)throw Error('সার্ভারের আপলোড অবস্থান সঠিক নয়।');
      const chunksize=status.chunk_bytes||1048576;
      while(offset<file.size){
        if(selected!==snapshot)return;
        let success=false;
        for(let attempt=0;attempt<4&&!success;attempt++){
          try{
            const next=await chunkXHR(file,offset,chunksize,started);
            offset=next.received_bytes;
            showProgress(offset,0,file.size,started);
            if(['ready','attached'].includes(next.status))offset=file.size;
            success=true;
          }catch(err){
            if(selected!==snapshot)return;
            if(attempt===3)throw err;
            setMessage('🔄 আপলোড আবার চেষ্টা করা হচ্ছে…',`সংযোগ পরীক্ষা চলছে (${attempt+1}/3)` ,offset*100/file.size);
            await new Promise(r=>setTimeout(r,Math.min(5000,1000*(attempt+1))));
            status=await post('media_status',credentials());
            offset=status.received_bytes;
            if(['ready','attached'].includes(status.status)){success=true;offset=file.size;}
          }
        }
      }
      setMessage('✅ ছবি আপলোড সম্পন্ন','ছবি সংরক্ষিত হয়েছে এবং ফর্ম জমা হলে একই রেকর্ডে যুক্ত হবে।',100,'সফল হয়েছে');
      uploadCompleted=true;clearState();
      if(saved){input.disabled=true;description.textContent='ফর্মের রেকর্ডে ছবিটি সংযুক্ত হয়েছে।';}
    }catch(err){
      setMessage('⚠ ছবি আপলোড থেমে গেছে',err.message||'আবার একই ছবি নির্বাচন করুন।',state?.bytes||0,'একই ফাইল নির্বাচন করলে আগের অংশ থেকে আবার চেষ্টা করবে');
    }finally{if(selected===snapshot){active=false;running=null;abort=null;}}
  };
  const start=async file=>{
    const snapshot=Symbol('selected');selected=snapshot;
    if(abort)abort();
    if(!file)return;
    const permitted=['image/jpeg','image/png','image/webp'];
    if(!permitted.includes(file.type)){setMessage('⚠ অসমর্থিত ছবি','শুধু JPG, PNG বা WebP নির্বাচন করুন।',0);return;}
    setMessage('ছবি প্রস্তুত হচ্ছে…','ফাইলের নিরাপত্তা যাচাই চলছে।',0);
    let digest;try{digest=await hashFile(file);}catch(_e){setMessage('⚠ ছবি যাচাই সম্ভব হয়নি','নিরাপদ সংযোগ অথবা নতুন ব্রাউজার ব্যবহার করুন।',0);return;}
    if(selected!==snapshot)return;
    try{
      const stored=readState();
      let resumeValid=false;
      if(stored&&stored.sha256===digest&&stored.size===file.size){
        state=stored;
        try{
          const checkpoint=await post('media_status',credentials());
          setMessage('🔄 আগের আপলোড পাওয়া গেছে',`${Math.round(checkpoint.received_bytes*100/file.size)}% থেকে Resume হচ্ছে…`,checkpoint.received_bytes*100/file.size);
          resumeValid=true;
        }catch(_e){clearState();state=null;}
      }
      if(!resumeValid){
        uploadCompleted=false;clearState();const init=await post('media_init',{draft_uid:draft.value,record_id:recordId,size:file.size,sha256:digest});
        state={uid:init.media_uid,token:init.resume_token,sha256:digest,size:file.size};saveState();
      }
      if(selected===snapshot)running=runUpload(file,snapshot);
    }catch(err){setMessage('⚠ ছবি আপলোড শুরু হয়নি',err.message||'আবার চেষ্টা করুন।',0,'চাইলে ফর্মটি সাধারণভাবে জমা দিতে পারেন');}
  };
  input.addEventListener('change',()=>{if(input.files?.[0])start(input.files[0]);});
  const pending=readState();
  if(pending){
    setMessage('⏸ অসম্পূর্ণ ছবি আপলোড','আগের ছবির আপলোড Resume করতে একই ছবি আবার নির্বাচন করুন।',0,'ড্রাফট আইডি বজায় থাকবে');
  }
  // Preserve the form while media is uploading; no navigation/full reload needed for early submit.
  let submitting=false;
  form.addEventListener('submit',async event=>{
    if(event.defaultPrevented||!input.files?.length||!state||!selected||submitting||saved)return;
    event.preventDefault();submitting=true;
    const button=form.querySelector('#formSubmit');if(button){button.disabled=true;button.textContent='তথ্য সংরক্ষণ হচ্ছে…';}
    try{
      const body=new FormData(form);body.delete('photo');
      const response=await fetch(form.action,{method:'POST',body,credentials:'same-origin',headers:{'X-PRAAN-AJAX':'1','Accept':'application/json'}});
      let data;try{data=await response.json();}catch(_e){throw Error('সার্ভার থেকে সঠিক উত্তর পাওয়া যায়নি।');}
      if(!response.ok||!data.ok)throw Error(data.message||'ফর্ম সংরক্ষণ হয়নি।');
      saved=true;form.dataset.praanSaved='1';if(uploadCompleted)input.disabled=true;
      if(result){result.hidden=false;result.replaceChildren();
        const strong=document.createElement('strong');strong.textContent='✅ নিবন্ধন তথ্য সংরক্ষিত হয়েছে।';
        const paragraph=document.createElement('p');paragraph.textContent=active?'ছবি আপলোড চলছে। পৃষ্ঠা খোলা রাখলে শেষে স্বয়ংক্রিয়ভাবে যুক্ত হবে।':'ছবি সম্পন্ন হয়ে থাকলে রেকর্ডে সংযুক্ত হয়েছে।';
        const link=document.createElement('a');link.className='btn btn-brand';link.href=data.redirect_url;link.textContent='সংরক্ষিত রেকর্ড দেখুন';
        result.append(strong,paragraph,link);result.scrollIntoView({behavior:'smooth',block:'center'});
      }
      try{
        const formKey='praan_draft_'+scopeKey;
        localStorage.removeItem(formKey);sessionStorage.removeItem(formKey);
        sessionStorage.removeItem(uidKey);
      }catch(_e){}
      if(button)button.textContent='✅ সংরক্ষণ সম্পন্ন';
    }catch(err){
      if(button){button.disabled=false;button.textContent='আবার সংরক্ষণ করুন';}
      setMessage('⚠ ফর্ম সংরক্ষণ হয়নি',err.message||'তথ্য যাচাই করে আবার চেষ্টা করুন।',null,'ছবির চলমান আপলোড আলাদাভাবে চলবে');
    }finally{submitting=false;}
  });
})();
