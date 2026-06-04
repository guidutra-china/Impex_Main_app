// Tiny promise wrapper over native IndexedDB. No deps.
//
// v2 model: a local `trips` store holds each trip with its expenses inline and
// per-entity sync flags. The trip is created as a draft, expenses are appended
// over the course of the trip, and everything syncs incrementally (only the
// not-yet-synced expenses are sent on each push).

const DB_NAME = 'impex-trips-mobile';
const DB_VERSION = 2;

export const STORE_TRIPS = 'trips';
export const STORE_REFERENCE = 'cached_reference_data';
export const STORE_COMPANIES = 'cached_companies';

let dbPromise = null;

function openDb() {
    if (dbPromise) return dbPromise;
    dbPromise = new Promise((resolve, reject) => {
        const req = indexedDB.open(DB_NAME, DB_VERSION);
        req.onupgradeneeded = () => {
            const db = req.result;
            // v1 used a `pending_trips` store with a different shape — drop it.
            if (db.objectStoreNames.contains('pending_trips')) {
                db.deleteObjectStore('pending_trips');
            }
            if (!db.objectStoreNames.contains(STORE_TRIPS)) {
                db.createObjectStore(STORE_TRIPS, { keyPath: 'localId', autoIncrement: true });
            }
            if (!db.objectStoreNames.contains(STORE_REFERENCE)) {
                db.createObjectStore(STORE_REFERENCE, { keyPath: 'key' });
            }
            if (!db.objectStoreNames.contains(STORE_COMPANIES)) {
                db.createObjectStore(STORE_COMPANIES, { keyPath: 'id' });
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

// ─── Trips ──────────────────────────────────────────────────────

/**
 * Insert or update a trip. New trips (no localId) are added and get one
 * assigned; the assigned id is written back onto the passed object.
 *
 * @param {object} trip  a PLAIN trip object (no Alpine proxies, see toPlainTrip)
 * @returns {Promise<number>} the trip's localId
 */
export async function saveTrip(trip) {
    return tx(STORE_TRIPS, 'readwrite', async (store) => {
        if (trip.localId == null) {
            const { localId, ...rest } = trip;
            const id = await reqToPromise(store.add(rest));
            trip.localId = id;
            return id;
        }
        await reqToPromise(store.put(trip));
        return trip.localId;
    });
}

export async function getTrip(localId) {
    return tx(STORE_TRIPS, 'readonly', (store) => reqToPromise(store.get(localId)));
}

export async function listTrips() {
    const trips = await tx(STORE_TRIPS, 'readonly', (store) => reqToPromise(store.getAll()));
    return trips.sort((a, b) => (b.updatedAt || 0) - (a.updatedAt || 0));
}

export async function deleteTrip(localId) {
    return tx(STORE_TRIPS, 'readwrite', (store) => reqToPromise(store.delete(localId)));
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

// ─── Companies cache (for offline trip linking) ─────────────────

export async function cacheCompanies(companies) {
    return tx(STORE_COMPANIES, 'readwrite', async (store) => {
        for (const company of companies) {
            if (company?.id) {
                await reqToPromise(store.put(company));
            }
        }
    });
}

export async function loadCompanies() {
    return tx(STORE_COMPANIES, 'readonly', (store) => reqToPromise(store.getAll()));
}
