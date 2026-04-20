const CACHE_NAME = 'pp-rendszer-v1';
const ASSETS_TO_CACHE = [
  'assets/css/bootstrap.min.css',
  'assets/js/bootstrap.bundle.min.js',
  'assets/icons/icon-192.png',
  'assets/icons/icon-512.png',
  'records.php'
];

// Install: statikus dolgok cache-be
self.addEventListener('install', event => {
  event.waitUntil(
    caches.open(CACHE_NAME).then(cache => cache.addAll(ASSETS_TO_CACHE))
  );
});

// Activate: régi cache-ek takarítása
self.addEventListener('activate', event => {
  event.waitUntil(
    caches.keys().then(keys =>
      Promise.all(
        keys
          .filter(k => k !== CACHE_NAME)
          .map(k => caches.delete(k))
      )
    )
  );
});

// Fetch: statikusra cache-first, PHP-re inkább hálózat-first
self.addEventListener('fetch', event => {
  const req = event.request;

  // csak GET kérések
  if (req.method !== 'GET') return;

  const url = new URL(req.url);

  // ha nem a saját domain (CDN, stb.), ne piszkáljuk
  if (url.origin !== self.location.origin) return;

  // ha PHP (dinamikus), inkább hálózat-first
  if (url.pathname.endsWith('.php')) {
    event.respondWith(
      fetch(req).catch(() => caches.match(req))
    );
    return;
  }

  // minden másra cache-first
  event.respondWith(
    caches.match(req).then(cached => {
      return (
        cached ||
        fetch(req).then(res => {
          const resClone = res.clone();
          caches.open(CACHE_NAME).then(cache => cache.put(req, resClone));
          return res;
        })
      );
    })
  );
});