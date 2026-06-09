// Tiny promise wrapper over native IndexedDB. No deps.
// Bump DB_VERSION + handle the migration in onupgradeneeded when the schema changes.

const DB_NAME = 'impex-fair-mobile';
const DB_VERSION = 2;

export const STORE_COMPANIES = 'fair_companies';
export const STORE_PENDING = 'pending_suppliers'; // legacy queue (v1) — drained on boot
export const STORE_REFERENCE = 'cached_reference_data';
export const STORE_FAIRS = 'cached_fairs';

let dbPromise = null;

/** RFC-4122 v4 uuid, with a non-secure-context fallback (PWA over plain HTTP). */
export function uuid() {
    if (typeof crypto !== 'undefined' && crypto.randomUUID) {
        return crypto.randomUUID();
    }
    const bytes = new Uint8Array(16);
    if (typeof crypto !== 'undefined' && crypto.getRandomValues) {
        crypto.getRandomValues(bytes);
    } else {
        for (let i = 0; i < 16; i++) bytes[i] = Math.floor(Math.random() * 256);
    }
    bytes[6] = (bytes[6] & 0x0f) | 0x40;
    bytes[8] = (bytes[8] & 0x3f) | 0x80;
    const hex = [...bytes].map((b) => b.toString(16).padStart(2, '0'));
    return `${hex.slice(0, 4).join('')}-${hex.slice(4, 6).join('')}-${hex.slice(6, 8).join('')}-${hex.slice(8, 10).join('')}-${hex.slice(10, 16).join('')}`;
}

function openDb() {
    if (dbPromise) return dbPromise;
    dbPromise = new Promise((resolve, reject) => {
        const req = indexedDB.open(DB_NAME, DB_VERSION);
        req.onupgradeneeded = () => {
            const db = req.result;
            if (!db.objectStoreNames.contains(STORE_COMPANIES)) {
                db.createObjectStore(STORE_COMPANIES, { keyPath: 'localId', autoIncrement: true });
            }
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

/**
 * Deep-copy a value into a structured-clone-safe plain object. IndexedDB uses
 * structuredClone internally, which throws "Proxy object could not be cloned"
 * on Alpine/Vue reactive proxies — so anything coming from component state must
 * pass through here first. Blobs/Files are preserved; object-URL `preview`
 * fields are dropped (not needed in storage).
 */
function toStorable(value) {
    if (value === null || typeof value !== 'object') return value;
    if (value instanceof Blob || value instanceof ArrayBuffer) return value;
    if (Array.isArray(value)) return value.map(toStorable);
    const out = {};
    for (const key of Object.keys(value)) {
        if (key === 'preview') continue;
        out[key] = toStorable(value[key]);
    }
    return out;
}

// ─── Fair companies (offline-first CRUD store) ──────────────────

export function emptyCompany(tradeFairId) {
    return {
        client_uuid: uuid(),
        server_id: null,
        trade_fair_id: tradeFairId ?? null,
        name: '',
        address_city: '',
        address_country: 'CN',
        company_notes: '',
        contact: { name: '', email: '', phone: '', wechat: '' },
        existing_photos: [],
        company_photos: [],
        products: [],
        deletedProductUuids: [],
        deletedProductIds: [],
        headerSynced: false,
        lastError: null,
        createdAt: Date.now(),
        updatedAt: Date.now(),
    };
}

export function emptyProduct() {
    return {
        client_uuid: uuid(),
        server_id: null,
        name: '',
        category_id: '',
        unit_price: '',
        currency_code: 'USD',
        moq: '',
        existing_images: [],
        photos: [],
        deleted_image_ids: [],
        synced: false,
    };
}

export async function saveFairCompany(company) {
    const record = toStorable(company);
    record.updatedAt = Date.now();
    return tx(STORE_COMPANIES, 'readwrite', async (store) => {
        if (record.localId == null) {
            const { localId, ...rest } = record;
            const id = await reqToPromise(store.add(rest));
            company.localId = id; // reflect the new id back to the caller's object
            return id;
        }
        await reqToPromise(store.put(record));
        return record.localId;
    });
}

export async function listFairCompanies(tradeFairId = null) {
    const all = await tx(STORE_COMPANIES, 'readonly', (store) => reqToPromise(store.getAll()));
    const rows = tradeFairId != null ? all.filter((c) => c.trade_fair_id === tradeFairId) : all;
    return rows.sort((a, b) => (b.updatedAt || 0) - (a.updatedAt || 0));
}

export async function getFairCompany(localId) {
    return tx(STORE_COMPANIES, 'readonly', (store) => reqToPromise(store.get(localId)));
}

export async function deleteFairCompany(localId) {
    return tx(STORE_COMPANIES, 'readwrite', (store) => reqToPromise(store.delete(localId)));
}

/** True when the device holds changes the server has not received yet. */
export function companyHasPending(company) {
    if (!company) return false;
    if (!company.headerSynced) return true;
    if ((company.products || []).some((p) => !p.synced)) return true;
    if ((company.deletedProductIds || []).length > 0) return true;
    if ((company.deletedProductUuids || []).length > 0) return true;
    return false;
}

/** Map a server presenter snapshot into a local (synced) company record. */
export function serverToLocal(serverCompany, tradeFairId) {
    return {
        client_uuid: serverCompany.client_uuid || uuid(),
        server_id: serverCompany.id,
        trade_fair_id: serverCompany.trade_fair_id ?? tradeFairId ?? null,
        name: serverCompany.name || '',
        address_city: serverCompany.address_city || '',
        address_country: serverCompany.address_country || 'CN',
        company_notes: serverCompany.company_notes || '',
        contact: serverCompany.contact || { name: '', email: '', phone: '', wechat: '' },
        existing_photos: serverCompany.photos || [],
        company_photos: [],
        products: (serverCompany.products || []).map((p) => ({
            client_uuid: p.client_uuid || uuid(),
            server_id: p.company_product_id,
            name: p.name || '',
            category_id: p.category_id || '',
            unit_price: p.unit_price ?? '',
            currency_code: p.currency_code || 'USD',
            moq: p.moq ?? '',
            existing_images: p.images || [],
            photos: [],
            deleted_image_ids: [],
            synced: true,
        })),
        deletedProductUuids: [],
        deletedProductIds: [],
        headerSynced: true,
        lastError: null,
        updatedAt: Date.now(),
    };
}

/**
 * Merge the server's company list into the local store. Companies with pending
 * local changes are preserved; clean ones are refreshed from the server; unknown
 * ones are inserted. Matches by server_id, then by client_uuid.
 */
export async function mergeServerCompanies(serverCompanies, tradeFairId) {
    const locals = await listFairCompanies();
    const byServerId = new Map(locals.filter((c) => c.server_id != null).map((c) => [c.server_id, c]));
    const byUuid = new Map(locals.filter((c) => c.client_uuid).map((c) => [c.client_uuid, c]));

    for (const sc of serverCompanies) {
        const local = byServerId.get(sc.id) || (sc.client_uuid ? byUuid.get(sc.client_uuid) : null);
        if (local && companyHasPending(local)) {
            continue; // keep local edits — they will overwrite the server on sync
        }
        const mapped = serverToLocal(sc, tradeFairId);
        if (local) {
            mapped.localId = local.localId;
            mapped.createdAt = local.createdAt;
        } else {
            mapped.createdAt = Date.now();
        }
        await saveFairCompany(mapped);
    }
}

// ─── Legacy v1 queue migration (pending_suppliers → fair_companies) ──

export async function migrateLegacyQueue() {
    let pending = [];
    try {
        pending = await tx(STORE_PENDING, 'readonly', (store) => reqToPromise(store.getAll()));
    } catch {
        return; // store may not exist
    }
    if (!pending.length) return;

    for (const item of pending) {
        const p = item.payload || {};
        const company = {
            ...emptyCompany(p.trade_fair_id ?? null),
            name: p.company_name || '',
            address_city: p.address_city || '',
            address_country: p.address_country || 'CN',
            company_notes: p.company_notes || '',
            contact: {
                name: p.contact_name || '',
                email: p.contact_email || '',
                phone: p.contact_phone || '',
                wechat: p.contact_wechat || '',
            },
            company_photos: p.company_photos || [],
            products: (p.products || []).map((pr) => ({
                ...emptyProduct(),
                name: pr.name || '',
                category_id: pr.category_id || '',
                unit_price: pr.unit_price ?? '',
                currency_code: pr.currency_code || 'USD',
                moq: pr.moq ?? '',
                photos: pr.photos || [],
            })),
        };
        await saveFairCompany(company);
    }

    try {
        await tx(STORE_PENDING, 'readwrite', (store) => reqToPromise(store.clear()));
    } catch { /* best effort */ }
}

// ─── Reference data cache (singleton row) ───────────────────────

export async function saveReferenceData(referenceData) {
    return tx(STORE_REFERENCE, 'readwrite', (store) => reqToPromise(store.put({
        key: 'reference',
        data: toStorable(referenceData),
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
            await reqToPromise(store.put(toStorable(fair)));
        }
    });
}

export async function loadFairs() {
    return tx(STORE_FAIRS, 'readonly', (store) => reqToPromise(store.getAll()));
}
