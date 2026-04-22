const CACHE = 'smartattend-v1';
const OFFLINE_URLS = [
    '/attendance-system/',
    '/attendance-system/auth/login.php',
    '/attendance-system/assets/style.css',
    '/attendance-system/offline.html'
];

self.addEventListener('install', e => {
    e.waitUntil(caches.open(CACHE).then(c => c.addAll(OFFLINE_URLS)));
    self.skipWaiting();
});

self.addEventListener('activate', e => {
    e.waitUntil(caches.keys().then(keys =>
        Promise.all(keys.filter(k => k !== CACHE).map(k => caches.delete(k)))
    ));
});

self.addEventListener('fetch', e => {
    if (e.request.method !== 'GET') return;
    e.respondWith(
        fetch(e.request)
            .then(res => {
                const clone = res.clone();
                caches.open(CACHE).then(c => c.put(e.request, clone));
                return res;
            })
            .catch(() => caches.match(e.request).then(r => r || caches.match('/attendance-system/offline.html')))
    );
});

// Background sync for offline attendance
self.addEventListener('sync', e => {
    if (e.tag === 'sync-attendance') {
        e.waitUntil(syncOfflineAttendance());
    }
});

async function syncOfflineAttendance() {
    const db = await openDB();
    const records = await getAllPending(db);
    if (!records.length) return;

    try {
        // Send all pending records in one batch request
        const res = await fetch('/attendance-system/api/sync_attendance.php', {
            method: 'POST',
            headers: { 'Content-Type': 'application/json' },
            body: JSON.stringify({ records: records })
        });

        if (!res.ok) return;

        const data = await res.json();

        // Process each result
        for (const result of (data.results || [])) {
            // Find matching local record by token
            const local = records.find(r => r.token === result.token);
            if (!local) continue;

            if (result.success) {
                // Successfully synced — remove from IndexedDB
                await markSynced(db, local.id);
            } else if (result.conflict) {
                // Conflict: teacher already marked — remove local record
                // and notify the user via a stored notification
                await markSynced(db, local.id);
                await storeConflictNotification(db, result);
            }
            // If failed for other reason (expired session), leave it for retry
        }

        // Notify the page about sync results
        const clients = await self.clients.matchAll({ type: 'window' });
        clients.forEach(client => client.postMessage({
            type: 'SYNC_COMPLETE',
            synced: data.synced,
            conflicts: data.conflicts,
            failed: data.failed,
            message: data.message,
            results: data.results
        }));

    } catch (e) {
        // Network still unavailable — will retry on next sync event
    }
}

function openDB() {
    return new Promise((res, rej) => {
        const req = indexedDB.open('smartattend', 1);
        req.onupgradeneeded = e => e.target.result.createObjectStore('pending', { keyPath: 'id', autoIncrement: true });
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
        // Store conflict as a special record so the page can display it
        tx.objectStore('pending').add({
            type: 'conflict',
            message: result.message,
            existing_status: result.existing_status,
            existing_method: result.existing_method,
            token: result.token
        }).onsuccess = res;
    });
}
