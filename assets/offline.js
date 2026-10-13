/**
 * SmartAttend Offline Manager
 * Handles: offline data caching, IndexedDB access, offline UI, background sync registration
 */

const OfflineManager = (() => {
    const DB_NAME = 'smartattend';
    const DB_VERSION = 2;
    const STORE_DATA = 'offline_data';
    const STORE_QUEUE = 'pending';

    let db = null;

    // ── IndexedDB ─────────────────────────────────────────────────────────────
    async function openDB() {
        if (db) return db;
        return new Promise((res, rej) => {
            const req = indexedDB.open(DB_NAME, DB_VERSION);
            req.onupgradeneeded = e => {
                const d = e.target.result;
                if (!d.objectStoreNames.contains(STORE_QUEUE))
                    d.createObjectStore(STORE_QUEUE, { keyPath: 'id', autoIncrement: true });
                if (!d.objectStoreNames.contains(STORE_DATA))
                    d.createObjectStore(STORE_DATA, { keyPath: 'key' });
            };
            req.onsuccess = e => { db = e.target.result; res(db); };
            req.onerror = e => rej(e);
        });
    }

    async function saveData(key, value) {
        const d = await openDB();
        return new Promise(res => {
            const tx = d.transaction(STORE_DATA, 'readwrite');
            tx.objectStore(STORE_DATA).put({ key, value, saved_at: Date.now() }).onsuccess = res;
        });
    }

    async function getData(key) {
        const d = await openDB();
        return new Promise(res => {
            const tx = d.transaction(STORE_DATA, 'readonly');
            tx.objectStore(STORE_DATA).get(key).onsuccess = e => res(e.target.result?.value ?? null);
        });
    }

    async function queueAttendance(record) {
        const d = await openDB();
        return new Promise(res => {
            const tx = d.transaction(STORE_QUEUE, 'readwrite');
            tx.objectStore(STORE_QUEUE).add({ ...record, queued_at: Date.now() }).onsuccess = res;
        });
    }

    async function getPendingCount() {
        const d = await openDB();
        return new Promise(res => {
            const tx = d.transaction(STORE_QUEUE, 'readonly');
            tx.objectStore(STORE_QUEUE).count().onsuccess = e => res(e.target.result);
        });
    }

    // ── Fetch & Cache Offline Data ────────────────────────────────────────────
    async function refreshOfflineData() {
        if (!navigator.onLine) return;
        try {
            const res = await fetch('/attendance-system/api/offline_data.php', { credentials: 'include' });
            if (!res.ok) return;
            const data = await res.json();
            await saveData('offline_payload', data);
            await saveData('last_sync', Date.now());

            // Tell SW to cache key pages
            if ('serviceWorker' in navigator && navigator.serviceWorker.controller) {
                const role = data.role;
                const pages = role === 'teacher'
                    ? ['/attendance-system/teacher/dashboard.php', '/attendance-system/teacher/students.php', '/attendance-system/teacher/mark_attendance.php']
                    : ['/attendance-system/student/dashboard.php', '/attendance-system/student/my_attendance.php'];
                navigator.serviceWorker.controller.postMessage({ type: 'CACHE_PAGES', urls: pages });
            }

            updateOfflineIndicator(true);
            return data;
        } catch {
            updateOfflineIndicator(false);
        }
    }

    async function getOfflineData() {
        return getData('offline_payload');
    }

    // ── Online/Offline UI ─────────────────────────────────────────────────────
    function updateOfflineIndicator(isOnline) {
        let bar = document.getElementById('offline-bar');
        if (!bar) {
            bar = document.createElement('div');
            bar.id = 'offline-bar';
            bar.style.cssText = 'position:fixed;bottom:0;left:0;right:0;padding:.5rem 1rem;text-align:center;font-size:.85rem;font-weight:600;z-index:9999;transition:all .3s ease;display:none';
            document.body.appendChild(bar);
        }

        if (!isOnline) {
            bar.style.background = '#fef3c7';
            bar.style.color = '#b45309';
            bar.style.display = 'block';
            bar.innerHTML = '⚠️ You are offline. Showing cached data. Attendance will sync when reconnected.';
        } else {
            bar.style.background = '#dcfce7';
            bar.style.color = '#15803d';
            bar.style.display = 'block';
            bar.innerHTML = '✅ Back online. Syncing...';
            setTimeout(() => { bar.style.display = 'none'; }, 3000);
        }
    }

    async function updateSyncBadge() {
        const count = await getPendingCount();
        let badge = document.getElementById('sync-badge');
        if (count > 0) {
            if (!badge) {
                badge = document.createElement('div');
                badge.id = 'sync-badge';
                badge.style.cssText = 'position:fixed;bottom:3rem;right:1rem;background:#6366f1;color:#fff;border-radius:20px;padding:.4rem .9rem;font-size:.8rem;font-weight:600;cursor:pointer;z-index:9998;box-shadow:0 2px 8px rgba(0,0,0,.2)';
                badge.onclick = triggerSync;
                document.body.appendChild(badge);
            }
            badge.textContent = `📤 ${count} queued — tap to sync`;
            badge.style.display = 'block';
        } else if (badge) {
            badge.style.display = 'none';
        }
    }

    // ── Background Sync ───────────────────────────────────────────────────────
    async function triggerSync() {
        if (!navigator.onLine) {
            showToast?.('Still offline. Will sync automatically when connected.', 'warning');
            return;
        }
        if ('serviceWorker' in navigator && 'SyncManager' in window) {
            const reg = await navigator.serviceWorker.ready;
            await reg.sync.register('sync-attendance');
        } else {
            // Fallback: direct sync
            const res = await fetch('/attendance-system/api/sync_attendance.php', {
                method: 'POST',
                headers: { 'Content-Type': 'application/json' },
                body: JSON.stringify({ records: await getAllQueued() })
            });
            const data = await res.json();
            showToast?.(`Synced ${data.synced} record(s)`, 'success');
        }
    }

    async function getAllQueued() {
        const d = await openDB();
        return new Promise(res => {
            const tx = d.transaction(STORE_QUEUE, 'readonly');
            tx.objectStore(STORE_QUEUE).getAll().onsuccess = e => res(e.target.result);
        });
    }

    // ── Register Periodic Sync ────────────────────────────────────────────────
    async function registerPeriodicSync() {
        if (!('serviceWorker' in navigator)) return;
        const reg = await navigator.serviceWorker.ready;
        if ('periodicSync' in reg) {
            try {
                await reg.periodicSync.register('refresh-offline-data', { minInterval: 60 * 60 * 1000 }); // 1hr
            } catch { /* permission denied or not supported */ }
        }
    }

    // ── Init ──────────────────────────────────────────────────────────────────
    async function init() {
        // Online/offline events
        window.addEventListener('online', async () => {
            updateOfflineIndicator(true);
            await refreshOfflineData();
            await triggerSync();
            await updateSyncBadge();
        });
        window.addEventListener('offline', () => updateOfflineIndicator(false));

        // Initial state
        if (!navigator.onLine) {
            updateOfflineIndicator(false);
        } else {
            // Refresh cache in background
            setTimeout(refreshOfflineData, 2000);
        }

        await updateSyncBadge();
        await registerPeriodicSync();

        // SW messages
        if ('serviceWorker' in navigator) {
            navigator.serviceWorker.addEventListener('message', e => {
                if (e.data?.type === 'SYNC_COMPLETE') {
                    updateSyncBadge();
                    if (e.data.synced > 0) showToast?.(`${e.data.synced} offline record(s) synced`, 'success');
                    if (e.data.conflicts > 0) showToast?.(`${e.data.conflicts} conflict(s) — teacher already marked`, 'warning', 5000);
                }
                if (e.data?.type === 'CACHE_REFRESHED') {
                    console.log('[SW] Offline cache refreshed at', new Date(e.data.timestamp).toLocaleTimeString());
                }
            });
        }
    }

    return { init, refreshOfflineData, getOfflineData, queueAttendance, triggerSync, updateSyncBadge };
})();

// Auto-init on DOM ready
document.addEventListener('DOMContentLoaded', () => OfflineManager.init());
