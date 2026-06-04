import '../../css/fair-mobile/app.css';
import Alpine from 'alpinejs';
import { api, auth, ApiError } from './api.js';
import {
    SubmissionStatus,
    countPendingSubmissions,
    deletePendingSubmission,
    listPendingSubmissions,
    loadFairs,
    loadReferenceData,
    queuePendingSubmission,
    saveFairs,
    saveReferenceData,
    updatePendingSubmission,
} from './db.js';
import { compressImage } from './image.js';
import { drainQueue, requestBackgroundSync } from './sync.js';

window.Alpine = Alpine;

function emptyProduct() {
    return {
        name: '',
        category_id: '',
        unit_price: '',
        currency_code: 'USD',
        moq: '',
        // Array of { blob, filename, type, preview } — first entry is the cover.
        photos: [],
    };
}

function emptyDraft() {
    return {
        company_name: '',
        address_city: '',
        address_country: 'CN',
        company_notes: '',
        contact_name: '',
        contact_email: '',
        contact_phone: '',
        contact_wechat: '',
        // Array of { blob, filename, type, preview } — first entry is the cover/scanned card.
        company_photos: [],
        products: [emptyProduct()],
    };
}

Alpine.data('fairApp', () => ({
    screen: 'loading',                // loading | login | capture | success | pending
    submitting: false,
    error: null,
    online: navigator.onLine,

    user: null,
    fairs: [],
    selectedFairId: null,
    reference: { categories: [], currencies: [], countries: [] },
    cacheStale: false,                // true when we booted from IDB (no network)

    pendingCount: 0,
    pendingItems: [],
    syncing: false,
    syncStats: null,

    loginForm: { email: '', password: '' },
    draft: emptyDraft(),
    lastResult: null,

    async init() {
        window.addEventListener('online', () => { this.online = true; this.handleOnline(); });
        window.addEventListener('offline', () => { this.online = false; });
        document.addEventListener('visibilitychange', () => {
            if (!document.hidden && this.online && auth.token()) {
                this.runSync();
            }
        });

        await this.refreshPendingCount();

        if (auth.token()) {
            await this.bootIntoCapture();
        } else {
            this.screen = 'login';
        }
    },

    async login() {
        this.error = null;
        this.submitting = true;
        try {
            const deviceName = navigator.userAgent.slice(0, 100);
            await api.login(this.loginForm.email, this.loginForm.password, deviceName);
            await this.bootIntoCapture();
        } catch (err) {
            this.error = err instanceof ApiError
                ? (err.body?.errors?.email?.[0] || err.message)
                : 'Unable to connect.';
        } finally {
            this.submitting = false;
        }
    },

    async bootIntoCapture() {
        this.screen = 'loading';
        this.cacheStale = false;
        try {
            const [fairsResponse, refResponse] = await Promise.all([
                api.activeFairs(),
                api.referenceData(),
            ]);
            // Persist to IDB FIRST, while the data is still a plain object.
            // Once it goes through Alpine's reactive() wrapper, structuredClone
            // (which IndexedDB uses internally) throws DataCloneError on some
            // browsers: "the object can not be cloned".
            await Promise.all([
                saveFairs(fairsResponse.data),
                saveReferenceData(refResponse),
            ]);
            this.fairs = fairsResponse.data;
            this.reference = refResponse;
        } catch (err) {
            console.error('[fair-mobile] boot failed', err);
            if (err instanceof ApiError && err.status === 401) {
                this.screen = 'login';
                return;
            }
            // Try cache fallback for transient network errors. If the underlying
            // problem is a server error (5xx) or unauthorised (403), surface it
            // instead of pretending the user is offline.
            const [cachedFairs, cachedRef] = await Promise.all([
                loadFairs(),
                loadReferenceData(),
            ]);
            if (cachedRef && cachedFairs?.length) {
                this.fairs = cachedFairs;
                this.reference = cachedRef;
                this.cacheStale = true;
            } else {
                this.error = this.describeBootError(err);
                this.screen = 'login';
                return;
            }
        }

        this.user = auth.user();
        if (this.fairs.length > 0) {
            this.selectedFairId = this.fairs[0].id;
        }
        this.screen = 'capture';

        if (this.online) {
            this.runSync();
        }
    },

    async logout() {
        try {
            await api.logout();
        } catch { /* offline logout is fine */ }
        this.screen = 'login';
        this.loginForm = { email: '', password: '' };
        this.user = null;
    },

    /**
     * Wipe local token + IndexedDB caches and reload. Used when the user is
     * stuck on the "no cached data" screen with a bad/stale token.
     */
    async resetLocalState() {
        auth.clear();
        try {
            await Promise.all([
                saveFairs([]),
                saveReferenceData(null),
            ]);
        } catch { /* IDB might not be initialised yet */ }
        window.location.reload();
    },

    describeBootError(err) {
        if (err instanceof ApiError) {
            if (err.status === 403) {
                return 'API recusou o acesso (403). Sua conta talvez não esteja como tipo "internal" no servidor.';
            }
            if (err.status >= 500) {
                return `Erro ${err.status} no servidor ao carregar dados. Veja o console / logs do servidor.`;
            }
            return `Falha na API: ${err.status} ${err.message || ''}`.trim();
        }
        // SyntaxError (JSON parse) usually means the API returned HTML — common
        // when the request is redirected to a non-existent /login route.
        if (err?.name === 'SyntaxError') {
            return 'Servidor devolveu HTML em vez de JSON — sessão pode ter expirado. Limpe os dados locais e faça login de novo.';
        }
        return err?.message || 'Não foi possível conectar.';
    },

    // ─── Capture form handlers ──────────────────────────────────

    async onCompanyPhotoChange(event) {
        const files = Array.from(event.target.files || []);
        if (!files.length) return;

        const MAX_PHOTOS = 8;

        for (const file of files) {
            if (this.draft.company_photos.length >= MAX_PHOTOS) break;
            const compressed = await compressImage(file);
            this.draft.company_photos.push({
                ...compressed,
                preview: URL.createObjectURL(compressed.blob),
            });
        }

        // Allow re-selecting the same file(s) again.
        event.target.value = '';
    },

    removeCompanyPhoto(photoIdx) {
        const removed = this.draft.company_photos.splice(photoIdx, 1)[0];
        if (removed?.preview) {
            URL.revokeObjectURL(removed.preview);
        }
    },

    async onProductPhotoChange(event, idx) {
        const files = Array.from(event.target.files || []);
        if (!files.length) return;

        const product = this.draft.products[idx];
        const MAX_PHOTOS = 8;

        for (const file of files) {
            if (product.photos.length >= MAX_PHOTOS) break;
            const compressed = await compressImage(file);
            product.photos.push({
                ...compressed,
                preview: URL.createObjectURL(compressed.blob),
            });
        }

        // Allow re-selecting the same file(s) again.
        event.target.value = '';
    },

    removeProductPhoto(idx, photoIdx) {
        const product = this.draft.products[idx];
        const removed = product.photos.splice(photoIdx, 1)[0];
        if (removed?.preview) {
            URL.revokeObjectURL(removed.preview);
        }
    },

    addProduct() { this.draft.products.push(emptyProduct()); },

    removeProduct(idx) {
        if (this.draft.products.length === 1) {
            this.draft.products[0] = emptyProduct();
        } else {
            this.draft.products.splice(idx, 1);
        }
    },

    resetDraft() { this.draft = emptyDraft(); },

    canSubmit() {
        if (!this.selectedFairId) return false;
        if (!this.draft.company_name.trim()) return false;
        if (!this.draft.contact_name.trim()) return false;
        const validProducts = this.draft.products.filter(p => p.name.trim());
        if (validProducts.length === 0) return false;
        return validProducts.every(p => p.category_id);
    },

    /**
     * Serialise the draft into the JSON-safe payload that lives in IndexedDB.
     * Blobs travel inside the photo wrappers — IndexedDB stores them natively.
     *
     * IMPORTANT: this method must return plain objects, not the Alpine-reactive
     * proxies that live inside `this.draft`. IndexedDB's structuredClone refuses
     * to clone Proxy-wrapped objects on some browsers (DataCloneError: "the
     * object can not be cloned"). Blobs themselves are passed through as-is
     * because they are host objects that Alpine does not wrap.
     */
    buildPayload() {
        const flattenFile = (f) => (f && f.blob)
            ? { blob: f.blob, filename: f.filename, type: f.type }
            : null;

        return {
            trade_fair_id: this.selectedFairId,
            company_name: this.draft.company_name.trim(),
            address_city: this.draft.address_city || null,
            address_country: this.draft.address_country || null,
            company_notes: this.draft.company_notes || null,
            contact_name: this.draft.contact_name.trim(),
            contact_email: this.draft.contact_email || null,
            contact_phone: this.draft.contact_phone || null,
            contact_wechat: this.draft.contact_wechat || null,
            company_photos: (this.draft.company_photos || []).map(flattenFile).filter(Boolean),
            products: this.draft.products
                .filter(p => p.name.trim())
                .map(p => ({
                    name: p.name.trim(),
                    category_id: p.category_id,
                    unit_price: p.unit_price === '' ? null : Number(p.unit_price),
                    currency_code: p.currency_code || 'USD',
                    moq: p.moq === '' ? null : Number(p.moq),
                    photos: (p.photos || []).map(flattenFile).filter(Boolean),
                })),
        };
    },

    async submit() {
        if (!this.canSubmit() || this.submitting) return;
        this.error = null;
        this.submitting = true;
        try {
            const payload = this.buildPayload();
            await queuePendingSubmission(payload);
            await this.refreshPendingCount();

            // Reset form right away — the user can keep capturing while sync runs.
            this.lastResult = { company: { name: payload.company_name }, product_names: payload.products.map(p => p.name) };
            this.resetDraft();
            this.screen = 'success';

            // Trigger sync (best-effort). Background Sync registration is also fine
            // to call when offline — the SW will fire when the network returns.
            requestBackgroundSync();
            if (this.online) {
                this.runSync();
            }
        } catch (err) {
            this.error = err?.message || 'Could not queue submission.';
        } finally {
            this.submitting = false;
        }
    },

    captureAnother() {
        this.lastResult = null;
        this.error = null;
        this.resetDraft();
        this.screen = 'capture';
    },

    // ─── Sync + pending list ────────────────────────────────────

    async refreshPendingCount() {
        this.pendingCount = await countPendingSubmissions();
    },

    async openPending() {
        this.pendingItems = await listPendingSubmissions();
        this.screen = 'pending';
    },

    async runSync() {
        if (this.syncing || !this.online || !auth.token()) return;
        this.syncing = true;
        try {
            const stats = await drainQueue();
            this.syncStats = stats;
            await this.refreshPendingCount();
            if (this.screen === 'pending') {
                this.pendingItems = await listPendingSubmissions();
            }
        } catch (err) {
            console.warn('Sync failed', err);
        } finally {
            this.syncing = false;
        }
    },

    async handleOnline() {
        if (auth.token()) {
            // Light refresh: try to pull fresh reference data + fairs in background.
            this.tryRefreshReference();
            this.runSync();
        }
    },

    async tryRefreshReference() {
        try {
            const [fairsResponse, refResponse] = await Promise.all([
                api.activeFairs(),
                api.referenceData(),
            ]);
            // Save before assigning to reactive props — see bootIntoCapture note.
            await Promise.all([
                saveFairs(fairsResponse.data),
                saveReferenceData(refResponse),
            ]);
            this.fairs = fairsResponse.data;
            this.reference = refResponse;
            this.cacheStale = false;
        } catch { /* keep cached values */ }
    },

    async retryPending(id) {
        const item = await updatePendingSubmission(id, { status: SubmissionStatus.PENDING, lastError: null });
        if (item) {
            await this.runSync();
        }
    },

    async deletePending(id) {
        await deletePendingSubmission(id);
        this.pendingItems = await listPendingSubmissions();
        await this.refreshPendingCount();
    },

    statusLabel(status) {
        return {
            [SubmissionStatus.PENDING]: 'Pendente',
            [SubmissionStatus.SYNCING]: 'Sincronizando…',
            [SubmissionStatus.FAILED]: 'Falhou',
            [SubmissionStatus.NEEDS_REVIEW]: 'Precisa revisão',
        }[status] || status;
    },

    statusClass(status) {
        return {
            [SubmissionStatus.PENDING]: 'bg-amber-100 text-amber-800',
            [SubmissionStatus.SYNCING]: 'bg-blue-100 text-blue-800',
            [SubmissionStatus.FAILED]: 'bg-red-100 text-red-800',
            [SubmissionStatus.NEEDS_REVIEW]: 'bg-purple-100 text-purple-800',
        }[status] || 'bg-gray-100 text-gray-800';
    },
}));

Alpine.start();

if ('serviceWorker' in navigator) {
    window.addEventListener('load', () => {
        navigator.serviceWorker.register('/fair-mobile/sw.js').catch((err) => {
            console.warn('Service worker registration failed', err);
        });
    });

    // Background Sync API (via SW) wakes the SPA to drain when connectivity
    // returns even if the user did not open the app. The SW posts 'sync-now'
    // and we trigger drainQueue on the currently mounted Alpine component.
    navigator.serviceWorker.addEventListener('message', (event) => {
        if (event.data?.type === 'sync-now') {
            const root = document.querySelector('[x-data]');
            const ctx = root && Alpine.$data(root);
            ctx?.runSync?.();
        }
    });
}
