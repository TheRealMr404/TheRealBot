const CACHE_NAME = "mirza-miniapp-static-0.2.0";
const PRECACHE = [
  "./assets/account-iPu8sCHt.js",
  "./assets/app-layout-DHbzmql-.js",
  "./assets/button-DVA8JUsJ.js",
  "./assets/buy-Cma1zvMm.js",
  "./assets/card-nJeJbIly.js",
  "./assets/chevron-left-Drh8ieR2.js",
  "./assets/circle-alert-DZHB7FtA.js",
  "./assets/enhancements.css",
  "./assets/home-BmQoapB7.js",
  "./assets/index-BoHBsj0Z.css",
  "./assets/index-C-2a0Dur.js",
  "./assets/index.html",
  "./assets/input-BR9BBPrf.js",
  "./assets/not-found-CUooCWw6.js",
  "./assets/page-transition-BW0vKBw7.js",
  "./assets/service-detail-C7iGJ2PF.js",
  "./assets/services-yswqeuBe.js",
  "./assets/shopping-bag-Bz4mjJdB.js",
  "./assets/ui-BeEpfTFY.js",
  "./assets/use-copy-CVXJQEWW.js",
  "./assets/use-media-DozwHwmr.js",
  "./assets/user-mg4Hsar6.js",
  "./assets/vendor-CIGJ9g2q.js",
  "./assets/x-BTIcV1Z9.js",
  "./fonts/Vazir-Bold.woff",
  "./fonts/Vazir-Bold.woff2",
  "./fonts/Vazir-Light.woff",
  "./fonts/Vazir-Light.woff2",
  "./fonts/Vazir-Medium.woff",
  "./fonts/Vazir-Medium.woff2",
  "./icons/icon-192.png",
  "./icons/icon-512.png",
  "./index.php",
  "./js/enhancements.js",
  "./js/telegram-web-app.js",
  "./manifest.webmanifest",
  "./offline.html",
  "./version"
];

self.addEventListener("install", function (event) {
  event.waitUntil(caches.open(CACHE_NAME).then(function (cache) {
    return cache.addAll(PRECACHE);
  }).then(function () { return self.skipWaiting(); }));
});

self.addEventListener("activate", function (event) {
  event.waitUntil(caches.keys().then(function (keys) {
    return Promise.all(keys.filter(function (key) {
      return key.indexOf("mirza-miniapp-") === 0 && key !== CACHE_NAME;
    }).map(function (key) { return caches.delete(key); }));
  }).then(function () { return self.clients.claim(); }));
});

self.addEventListener("message", function (event) {
  if (event.data === "SKIP_WAITING") self.skipWaiting();
  if (event.data === "CLEAR_CACHE") {
    event.waitUntil(caches.delete(CACHE_NAME));
  }
});

self.addEventListener("fetch", function (event) {
  const request = event.request;
  if (request.method !== "GET") return;
  const url = new URL(request.url);

  // Never cache API responses or cross-origin requests.
  if (url.origin !== self.location.origin || url.pathname.indexOf("/api") === 0 || url.pathname.indexOf("/api/") !== -1) return;

  if (request.mode === "navigate") {
    event.respondWith(fetch(request).then(function (response) {
      const copy = response.clone();
      caches.open(CACHE_NAME).then(function (cache) { cache.put(request, copy); });
      return response;
    }).catch(function () {
      return caches.match(request).then(function (cached) {
        return cached || caches.match("./index.php") || caches.match("./offline.html");
      });
    }));
    return;
  }

  event.respondWith(caches.match(request).then(function (cached) {
    const network = fetch(request).then(function (response) {
      if (response && response.ok) {
        const copy = response.clone();
        caches.open(CACHE_NAME).then(function (cache) { cache.put(request, copy); });
      }
      return response;
    }).catch(function () { return cached; });
    return cached || network;
  }));
});
