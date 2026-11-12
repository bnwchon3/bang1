/* Scoped PWA lifecycle worker: no caching of private or live records. */
self.addEventListener('install',()=>self.skipWaiting());
self.addEventListener('activate',event=>event.waitUntil(self.clients.claim()));
