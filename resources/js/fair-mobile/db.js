// Tiny promise wrapper over native IndexedDB. No deps.
// Single schema version — bump DB_VERSION + handle the migration in onupgradeneeded
// if the schema ever changes.

const DB_NAME = 'impex-fair-mobile';
const DB_VERSION = 1;

export const STORE_PENDING = 'pending_suppliers';
export const STORE_REFERENCE = 'cached_reference_data';
export const STORE_FAIRS = 'cached_fairs';

const STATUS_PENDING = 'pending';
const STATUS_SYNCING = 'syncing';
const STATUS_FAILED = 'failed';
const STATUS_NEEDS_REVIEW = 'needs_review';

export const SubmissionStatus = {
    PENDING: STATUS_PENDING,
    SYNCING: STATUS_SYNCING,
    FAILED: STATUS_FAILED,
    NEEDS_REVIEW: STATUS_NEEDS_REVIEW,
};

let dbPromise = null;

function openDb() {
    if (dbPromise) return dbPromise;
    dbPromise = new Promise((resolve, reject) => {
        const req = indexedDB.open(DB_NAME, DB_VERSION);
        req.onupgradeneeded = (event) => {
            const db = req.result;
            if (!db.objectStoreNames.contains(STORE_PENDING)) {
                db.createObjectStore(STORE_PENDING, { keyPath: 'id', autoIncrement: true });
            }
            if (!db.objectStoreNames.contains(STORE_REFERENCE)) {
                db.createObjectStore(STORE_REFERENCE, { keyPath: 'key' });
            }
            if (!db.objectStoreNames.contains(STORE_FAIRS)) {
                db.createObjectStore(STORE_FAIRS, { keyPath: 'id' });
            }
        };
        req.onsuccess = () => resolve(req.result);
        req.onerror = () => reject(req.error);
        req.onblocked = () => reject(new Error('IndexedDB blocked by another tab'));
    });
    return dbPromise;
}

async function tx(storeName, mode, fn) {
    const db = await openDb();
    return new Promise((resolve, reject) => {
        const transaction = db.transaction(storeName, mode);
        const store = transaction.objectStore(storeName);
        let result;
        try {
            result = fn(store);
        } catch (err) {
            reject(err);
            return;
        }
        transaction.oncomplete = () => resolve(result);
        transaction.onabort = () => reject(transaction.error || new Error('IDB transaction aborted'));
        transaction.onerror = () => reject(transaction.error);
    });
}

function reqToPromise(req) {
    return new Promise((resolve, reject) => {
        req.onsuccess = () => resolve(req.result);
        req.onerror = () => reject(req.error);
    });
}

// ─── Pending submissions ────────────────────────────────────────

/**
 * @param {object} payload  serializable supplier data + Blob photos
 * @returns {Promise<number>} the auto-generated id of the queued item
 */
export async function queuePendingSubmission(payload) {
    const item = {
        payload,
        status: STATUS_PENDING,
        lastError: null,
        createdAt: Date.now(),
        updatedAt: Date.now(),
    };
    return tx(STORE_PENDING, 'readwrite', (store) => {
        const req = store.add(item);
        return reqToPromise(req);
    });
}

export async function listPendingSubmissions() {
    return tx(STORE_PENDING, 'readonly', (store) => reqToPromise(store.getAll()));
}

export async function getPendingSubmission(id) {
    return tx(STORE_PENDING, 'readonly', (store) => reqToPromise(store.get(id)));
}

export async function updatePendingSubmission(id, patch) {
    return tx(STORE_PENDING, 'readwrite', async (store) => {
        const current = await reqToPromise(store.get(id));
        if (!current) return null;
        const merged = { ...current, ...patch, updatedAt: Date.now() };
        await reqToPromise(store.put(merged));
        return merged;
    });
}

export async function deletePendingSubmission(id) {
    return tx(STORE_PENDING, 'readwrite', (store) => reqToPromise(store.delete(id)));
}

export async function countPendingSubmissions() {
    return tx(STORE_PENDING, 'readonly', (store) => reqToPromise(store.count()));
}

// ─── Reference data cache (singleton row) ───────────────────────

export async function saveReferenceData(referenceData) {
    return tx(STORE_REFERENCE, 'readwrite', (store) => reqToPromise(store.put({
        key: 'reference',
        data: referenceData,
        savedAt: Date.now(),
    })));
}

export async function loadReferenceData() {
    const row = await tx(STORE_REFERENCE, 'readonly', (store) => reqToPromise(store.get('reference')));
    return row?.data || null;
}

// ─── Active fairs cache ─────────────────────────────────────────

export async function saveFairs(fairs) {
    return tx(STORE_FAIRS, 'readwrite', async (store) => {
        await reqToPromise(store.clear());
        for (const fair of fairs) {
            await reqToPromise(store.put(fair));
        }
    });
}

export async function loadFairs() {
    return tx(STORE_FAIRS, 'readonly', (store) => reqToPromise(store.getAll()));
}
