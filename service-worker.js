/* PRAAN 1.8 PWA: cache only explicitly allowed static assets.
 * Never intercept/cache authenticated HTML, API, report exports, POST bodies, or photos.
 */
'use strict';
const VERSION='2.2.1';
const PREFIX='praan-static-';
const CACHE=PREFIX+VERSION;
const ROOT=new URL('./',self.registration.scope).pathname;
const PRELOAD=[
  'offline.html','assets/pwa-icon-192.png','assets/pwa-icon-512.png','assets/pran-logo.png',
  'assets/app.css','assets/app.js','assets/layout-fallback.css',
  'assets/praan-mobile.css','assets/praan-mobile.js','assets/praan-offline.js',
  'assets/analytics.css','assets/volunteer-table.css','assets/volunteer-table.js',
  'assets/praan-foundation.css','assets/praan-foundation.js',
  'assets/praan-media.css','assets/praan-media.js','assets/praan-access.css',
  'assets/praan-modules.css','assets/praan-modules.js','assets/praan-notifications.css','assets/praan-notifications.js','assets/update-center.css','assets/praan-identity.css', 'assets/favicon-32.png'
];
const files=new Set(PRELOAD);
self.addEventListener('install',event=>{
  event.waitUntil((async()=>{
    const cache=await caches.open(CACHE);
    await Promise.all(PRELOAD.map(async path=>{
      try{const response=await fetch(new URL(path,self.registration.scope),{cache:'reload',credentials:'omit'});
        if(response.ok && response.type==='basic')await cache.put(new URL(path,self.registration.scope),response);
      }catch(_e){}
    }));
    await self.skipWaiting();
  })());
});
self.addEventListener('activate',event=>{
  event.waitUntil((async()=>{
    const keys=await caches.keys();await Promise.all(keys.filter(k=>k.startsWith(PREFIX)&&k!==CACHE).map(k=>caches.delete(k)));
    await self.clients.claim();
  })());
});
self.addEventListener('fetch',event=>{
  const request=event.request;
  if(request.method!=='GET')return;
  const url=new URL(request.url);
  if(url.origin!==self.location.origin||!url.pathname.startsWith(ROOT))return;
  const path=url.pathname.slice(ROOT.length);
  if(request.mode==='navigate'){
    // Network-only for *all* pages with user data. Offline fallback is a public static document.
    event.respondWith(fetch(request).catch(async()=>{
      const cache=await caches.open(CACHE);
      return (await cache.match(new URL('offline.html',self.registration.scope)))||Response.error();
    }));
    return;
  }
  if(!files.has(path)||path==='offline.html')return;
  event.respondWith((async()=>{
    const cache=await caches.open(CACHE);
    const cached=await cache.match(new URL(path,self.registration.scope));
    if(cached)return cached;
    const response=await fetch(request);
    if(response.ok&&response.type==='basic')await cache.put(new URL(path,self.registration.scope),response.clone());
    return response;
  })());
});
