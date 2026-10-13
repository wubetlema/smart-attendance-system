const CACHE_VERSION = 'smartattend-v3';
const STATIC_CACHE = 'smartattend-static-v3';
const DATA_CACHE = 'smartattend-data-v3';

// Static shell — always cached
const STATIC_URLS = [
    '/attendance-system/',
    '/attendance-system/auth/login.php',
    '/attendance-system/assets/style.css',
    '/attendance-system/offline.html'
];

// Dynamic pages to cache for offline access
const OFFLINE_PAGES = [
    '/attendance-system/student/dashboard.php',
    '/attendance-system/student/my_attendance.php',
    '/attendance-system/teacher/dashboard.php',
    '/attendance-system/teacher/mark_attendance.php',
    '/attendance-system/teacher/students.php',
];

// API endpoints to cache responses for offline reads
const CACHEABLE_API = [
    '/attendance-system/api/offline_data.php',
];

// ── Install ───────────────────────────────────────────────────────────────────
self.addEventListener('install', e => {
    e.waitUntil(
        caches.open(STATIC_CACHE)
            .then(c => c.addAll(STATIC_URLS))
            .then(() => self.skipWaiting())
    );
});

// ── Activate ──────────────────────────────────────────────────────────────────
self.addEventListener('activate', e => {
    e.waitUntil(
        caches.keys().then(keys =>
            Promise.all(
                keys.filter(k => k !== STATIC_CACHE && k !== DATA_CACHE)
                    .map(k => caches.delete(k))
            )
        ).then(() => self.clients.claim())
    );
});

// ── Fetch Strategy ────────────────────────────────────────────────────────────
self.addEventListener('fetch', e => {
    const url = new URL(e.request.url);

    // Skip non-GET and cross-origin
    if (e.request.method !== 'GET') return;
    if (!url.origin.includes(self.location.origin.split('//')[1])) return;

    // Static assets — cache first
    if (url.pathname.includes('/assets/')) {
        e.respondWith(cacheFirst(e.request, STATIC_CACHE));
        return;
    }

    // Offline data API — network first, fallback to cache
    if (CACHEABLE_API.some(p => url.pathname.includes(p.split('/').pop()))) {
        e.respondWith(networkFirstWithCache(e.request, DATA_CACHE));
        return;
    }

    // Offline pages — network first, fallback to cached version
    if (OFFLINE_PAGES.some(p => url.pathname.includes(p.split('/').pop()))) {
        e.respondWith(networkFirstWithCache(e.request, DATA_CACHE));
        return;
    }

    // Everything else — network first, fallback to offline page
    e.respondWith(
        fetch(e.request)
            .then(res => {
                if (res.ok) {
                    const clone = res.clone();
                    caches.open(DATA_CACHE).then(c => c.put(e.request, clone));
                }
                return res;
            })
            .catch(() =>
                caches.match(e.request)
                    .then(r => r || caches.match('/attendance-system/offline.html'))
            )
    );
});

// ── Cache Strategies ──────────────────────────────────────────────────────────
async function cacheFirst(request, cacheName) {
    const cached = await caches.match(request);
    if (cached) return cached;
    const res = await fetch(request);
    const cache = await caches.open(cacheName);
    cache.put(request, res.clone());
    return res;
}

async function networkFirstWithCache(request, cacheName) {
    try {
        const res = await fetch(request);
        if (res.ok) {
            const cache = await caches.open(cacheName);
            cache.put(request, res.clone());
        }
        return res;
    } catch {
        const cached = await caches.match(request);
        return cached || caches.match('/attendance-system/offline.html');
    }
}

// ── Background Sync ───────────────────────────────────────────────────────────
self.addEventListener('sync', e => {
    if (e.tag === 'sync-attendance') {
        e.waitUntil(syncOfflineAttendance());
    }
    if (e.tag === 'sync-cache-refresh') {
        e.waitUntil(refreshOfflineCache());
    }
});

// ── Periodic Background Sync (refresh cached data) ───────────────────────────
self.addEventListener('periodicsync', e => {
    if (e.tag === 'refresh-offline-data') {
        e.waitUntil(refreshOfflineCache());
    }
});

async function refreshOfflineCache() {
    const cache = await caches.open(DATA_CACHE);
    const toRefresh = [
        '/attendance-system/api/offline_data.php',
        ...OFFLINE_PAGES
    ];
    await Promise.allSettled(
        toRefresh.map(url =>
            fetch(url, { credentials: 'include' })
                .then(res => { if (res.ok) cache.put(url, res); })
                .catch(() => { })
        )
    );
    notifyClients({ type: 'CACHE_REFRESHED', timestamp: Date.now() });
}

// ── Attendance Sync ───────────────────────────────────────────────────────────
async function syncOfflineAttendance() {
    const db = await openDB();
    const records = await getAllPending(db);
    if (!records.length) return;

    try {
        const res = await fetch('/attendance-system/api/sync_attendance.php', {
            method: 'POST',
            headers: { 'Content-Type': 'application/json' },
            body: JSON.stringify({ records })
        });

        if (!res.ok) return;
        const data = await res.json();

        for (const result of (data.results || [])) {
            const local = records.find(r => r.token === result.token);
            if (!local) continue;
            if (result.success || result.conflict) {
                await markSynced(db, local.id);
                if (result.conflict) await storeConflictNotification(db, result);
            }
        }

        notifyClients({
            type: 'SYNC_COMPLETE',
            synced: data.synced,
            conflicts: data.conflicts,
            failed: data.failed,
            message: data.message,
            results: data.results
        });

    } catch {
        // Network unavailable — retry on next sync
    }
}

// ── Message Handler (from page) ───────────────────────────────────────────────
self.addEventListener('message', e => {
    if (e.data?.type === 'CACHE_PAGES') {
        // Page requests specific URLs to be cached
        const urls = e.data.urls || [];
        caches.open(DATA_CACHE).then(cache => {
            urls.forEach(url =>
                fetch(url, { credentials: 'include' })
                    .then(res => { if (res.ok) cache.put(url, res); })
                    .catch(() => { })
            );
        });
    }
    if (e.data?.type === 'SKIP_WAITING') {
        self.skipWaiting();
    }
});

// ── Helpers ───────────────────────────────────────────────────────────────────
function notifyClients(msg) {
    self.clients.matchAll({ type: 'window' }).then(clients =>
        clients.forEach(c => c.postMessage(msg))
    );
}

function openDB() {
    return new Promise((res, rej) => {
        const req = indexedDB.open('smartattend', 2);
        req.onupgradeneeded = e => {
            const db = e.target.result;
            if (!db.objectStoreNames.contains('pending'))
                db.createObjectStore('pending', { keyPath: 'id', autoIncrement: true });
            if (!db.objectStoreNames.contains('offline_data'))
                db.createObjectStore('offline_data', { keyPath: 'key' });
        };
        req.onsuccess = e => res(e.target.result);
        req.onerror = e => rej(e);
    });
}
function getAllPending(db) {
    return new Promise(res => {
        const tx = db.transaction('pending', 'readonly');
        tx.objectStore('pending').getAll().onsuccess = e => res(e.target.result);
    });
}
function markSynced(db, id) {
    return new Promise(res => {
        const tx = db.transaction('pending', 'readwrite');
        tx.objectStore('pending').delete(id).onsuccess = res;
    });
}
function storeConflictNotification(db, result) {
    return new Promise(res => {
        const tx = db.transaction('pending', 'readwrite');
        tx.objectStore('pending').add({
            type: 'conflict',
            message: result.message,
            existing_status: result.existing_status,
            existing_method: result.existing_method,
            token: result.token
        }).onsuccess = res;
    });
}
