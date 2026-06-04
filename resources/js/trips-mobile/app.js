import '../../css/trips-mobile/app.css';
import Alpine from 'alpinejs';
import { api, auth, ApiError } from './api.js';
import {
    cacheCompanies,
    deleteTrip,
    listTrips,
    loadCompanies,
    loadReferenceData,
    saveReferenceData,
    saveTrip,
} from './db.js';
// Receipt compression is identical to the fair module — reuse it directly.
import { compressImage } from '../fair-mobile/image.js';
import { defaultLocale, numberLocale, resolveLocale, t as translate } from './i18n.js';
import { requestBackgroundSync, syncAllTrips, tripHasPending } from './sync.js';

window.Alpine = Alpine;

function uuid() {
    if (typeof crypto !== 'undefined' && crypto.randomUUID) {
        return crypto.randomUUID();
    }
    // RFC 4122 v4 fallback for non-secure contexts (e.g. the PWA opened over
    // plain HTTP on a LAN IP), where crypto.randomUUID is unavailable but the
    // server still requires a valid UUID.
    const bytes = new Uint8Array(16);
    if (typeof crypto !== 'undefined' && crypto.getRandomValues) {
        crypto.getRandomValues(bytes);
    } else {
        for (let i = 0; i < 16; i++) bytes[i] = Math.floor(Math.random() * 256);
    }
    bytes[6] = (bytes[6] & 0x0f) | 0x40; // version 4
    bytes[8] = (bytes[8] & 0x3f) | 0x80; // variant 10xx
    const hex = [...bytes].map((b) => b.toString(16).padStart(2, '0'));
    return `${hex.slice(0, 4).join('')}-${hex.slice(4, 6).join('')}-${hex.slice(6, 8).join('')}-${hex.slice(8, 10).join('')}-${hex.slice(10, 16).join('')}`;
}

// Local 'YYYY-MM-DDTHH:mm' for <input type="datetime-local"> — captures the
// time of day (lunch vs dinner) at the moment the expense is logged.
function nowLocalDateTime() {
    const d = new Date();
    const pad = (n) => String(n).padStart(2, '0');
    return `${d.getFullYear()}-${pad(d.getMonth() + 1)}-${pad(d.getDate())}T${pad(d.getHours())}:${pad(d.getMinutes())}`;
}

function todayISO() {
    return new Date().toISOString().slice(0, 10);
}

function emptyTripHeader() {
    return {
        title: '',
        company: null,          // { id, name } when linked
        is_internal: false,
        destination_city: '',
        destination_country: 'CN',
        start_date: todayISO(),
        end_date: '',
        notes: '',
    };
}

function emptyExpense() {
    return {
        category: '',
        description: '',
        amount: '',
        currency_code: 'CNY',
        expense_date: nowLocalDateTime(),
        photos: [],             // { blob, filename, type, preview }
    };
}

Alpine.data('tripsApp', () => ({
    screen: 'loading',          // loading | login | list | tripForm | trip | expenseForm
    submitting: false,
    error: null,
    online: navigator.onLine,
    locale: defaultLocale(),    // follows the logged-in user's locale once known

    user: null,
    reference: { expense_categories: [], currencies: [], countries: [] },
    cacheStale: false,

    trips: [],
    currentTripLocalId: null,
    syncing: false,

    loginForm: { email: '', password: '' },
    tripDraft: emptyTripHeader(),
    expenseDraft: emptyExpense(),
    editingExpenseUuid: null,   // null = adding a new expense; set = editing

    // Company picker
    companyQuery: '',
    companyResults: [],
    searchingCompany: false,

    t(key, params = {}) {
        return translate(this.locale, key, params);
    },

    applyUserLocale() {
        const resolved = resolveLocale(auth.user()?.locale);
        if (resolved) {
            this.locale = resolved;
        }
    },

    async init() {
        this.applyUserLocale();
        window.addEventListener('online', () => { this.online = true; this.handleOnline(); });
        window.addEventListener('offline', () => { this.online = false; });
        document.addEventListener('visibilitychange', () => {
            if (!document.hidden && this.online && auth.token()) {
                this.runSync();
            }
        });

        if (auth.token()) {
            await this.bootIntoTrips();
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
            await this.bootIntoTrips();
        } catch (err) {
            this.error = err instanceof ApiError
                ? (err.body?.errors?.email?.[0] || err.message)
                : this.t('err_connect');
        } finally {
            this.submitting = false;
        }
    },

    async bootIntoTrips() {
        this.screen = 'loading';
        this.cacheStale = false;
        try {
            const refResponse = await api.referenceData();
            await saveReferenceData(refResponse);
            this.reference = refResponse;
        } catch (err) {
            console.error('[trips-mobile] boot failed', err);
            if (err instanceof ApiError && err.status === 401) {
                this.screen = 'login';
                return;
            }
            const cachedRef = await loadReferenceData();
            if (cachedRef) {
                this.reference = cachedRef;
                this.cacheStale = true;
            } else {
                this.error = this.describeBootError(err);
                this.screen = 'login';
                return;
            }
        }

        this.user = auth.user();
        this.applyUserLocale();
        await this.loadTrips();
        this.screen = 'list';

        if (this.online) {
            this.runSync();
        }
    },

    async loadTrips() {
        this.trips = await listTrips();
    },

    /**
     * Re-download the traveler's DRAFT trips from the server onto this device,
     * including receipts (fetched as blobs) so they remain fully editable.
     * Trips already present locally (by client_uuid) are skipped.
     */
    async recoverDrafts() {
        if (this.submitting) return;
        if (!this.online) { this.error = this.t('recover_offline'); return; }
        this.submitting = true;
        this.error = null;
        try {
            const response = await api.recoverableTrips();
            const localUuids = new Set(this.trips.map((trip) => trip.client_uuid).filter(Boolean));
            let recovered = 0;

            for (const r of (response.data || [])) {
                if (!r.client_uuid || localUuids.has(r.client_uuid)) continue;

                const expenses = [];
                for (const e of (r.expenses || [])) {
                    const photos = [];
                    for (const url of (e.photos || [])) {
                        const blob = await this.downloadBlob(url);
                        if (blob) {
                            photos.push({ blob, filename: url.split('/').pop() || 'receipt.jpg', type: blob.type || 'image/jpeg' });
                        }
                    }
                    expenses.push({
                        client_uuid: e.client_uuid,
                        category: e.category,
                        description: e.description || null,
                        amount: Number(e.amount),
                        currency_code: e.currency_code || 'CNY',
                        expense_date: e.expense_date,
                        synced: true,
                        photos,
                    });
                }

                await saveTrip({
                    client_uuid: r.client_uuid,
                    serverId: r.server_id ?? null,
                    serverStatus: 'draft',
                    title: r.title,
                    is_internal: !!r.is_internal,
                    company_id: r.company_id ?? null,
                    company_name: r.company_name ?? null,
                    destination_city: r.destination_city ?? null,
                    destination_country: r.destination_country ?? null,
                    start_date: r.start_date,
                    end_date: r.end_date ?? null,
                    notes: r.notes ?? null,
                    status: 'draft',
                    headerSynced: true,
                    finalizeRequested: false,
                    lastError: null,
                    deletedExpenseUuids: [],
                    expenses,
                    createdAt: Date.now(),
                    updatedAt: Date.now(),
                });
                recovered++;
            }

            await this.loadTrips();
            alert(this.t('recovered_count', { count: recovered }));
        } catch (err) {
            this.error = err?.message || this.t('recover_failed');
        } finally {
            this.submitting = false;
        }
    },

    async downloadBlob(url) {
        try {
            const res = await fetch(url);
            if (!res.ok) return null;
            return await res.blob();
        } catch {
            return null;
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

    async resetLocalState() {
        auth.clear();
        try {
            await saveReferenceData(null);
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
        if (err?.name === 'SyntaxError') {
            return 'Servidor devolveu HTML em vez de JSON — sessão pode ter expirado. Limpe os dados locais e faça login de novo.';
        }
        return err?.message || 'Não foi possível conectar.';
    },

    // ─── Trip list ──────────────────────────────────────────────

    newTrip() {
        this.tripDraft = emptyTripHeader();
        this.companyQuery = '';
        this.companyResults = [];
        this.error = null;
        this.screen = 'tripForm';
    },

    openTrip(localId) {
        this.currentTripLocalId = localId;
        this.error = null;
        this.screen = 'trip';
    },

    currentTrip() {
        return this.trips.find((t) => t.localId === this.currentTripLocalId) || null;
    },

    backToList() {
        this.currentTripLocalId = null;
        this.screen = 'list';
    },

    // ─── Company picker (trip header) ───────────────────────────

    async searchCompanies() {
        const q = this.companyQuery.trim();
        if (q.length < 2) {
            this.companyResults = [];
            return;
        }
        this.searchingCompany = true;
        try {
            if (this.online) {
                const response = await api.searchCompanies(q);
                this.companyResults = response.data || [];
                cacheCompanies(this.companyResults).catch(() => {});
            } else {
                this.companyResults = await this.filterCachedCompanies(q);
            }
        } catch {
            this.companyResults = await this.filterCachedCompanies(q);
        } finally {
            this.searchingCompany = false;
        }
    },

    async filterCachedCompanies(q) {
        const cached = await loadCompanies();
        const needle = q.toLowerCase();
        return cached.filter((c) => (c.name || '').toLowerCase().includes(needle)).slice(0, 15);
    },

    selectCompany(company) {
        this.tripDraft.company = { id: company.id, name: company.name };
        this.tripDraft.is_internal = false;
        this.companyQuery = '';
        this.companyResults = [];
    },

    clearCompany() {
        this.tripDraft.company = null;
    },

    toggleInternal() {
        this.tripDraft.is_internal = !this.tripDraft.is_internal;
        if (this.tripDraft.is_internal) {
            this.tripDraft.company = null;
            this.companyResults = [];
            this.companyQuery = '';
        }
    },

    canCreateTrip() {
        if (!this.tripDraft.title.trim()) return false;
        if (!this.tripDraft.start_date) return false;
        return this.tripDraft.is_internal || !!this.tripDraft.company;
    },

    async createTrip() {
        if (!this.canCreateTrip() || this.submitting) return;
        this.submitting = true;
        this.error = null;
        try {
            const d = this.tripDraft;
            const trip = {
                client_uuid: uuid(),
                serverId: null,
                serverStatus: null,
                title: d.title.trim(),
                is_internal: !!d.is_internal,
                company_id: d.is_internal ? null : (d.company?.id ?? null),
                company_name: d.is_internal ? null : (d.company?.name ?? null),
                destination_city: d.destination_city || null,
                destination_country: d.destination_country || null,
                start_date: d.start_date,
                end_date: d.end_date || null,
                notes: d.notes || null,
                status: 'draft',
                headerSynced: false,
                finalizeRequested: false,
                lastError: null,
                expenses: [],
                deletedExpenseUuids: [],
                createdAt: Date.now(),
                updatedAt: Date.now(),
            };
            await saveTrip(trip);
            await this.loadTrips();
            this.currentTripLocalId = trip.localId;
            this.screen = 'trip';
            requestBackgroundSync();
            if (this.online) this.runSync();
        } catch (err) {
            this.error = err?.message || this.t('err_create_trip');
        } finally {
            this.submitting = false;
        }
    },

    // ─── Expense capture ────────────────────────────────────────

    addExpense() {
        this.editingExpenseUuid = null;
        this.expenseDraft = emptyExpense();
        const currencies = this.reference.currencies || [];
        // Prefer CNY (the default); otherwise fall back to the first available.
        this.expenseDraft.currency_code = currencies.includes('CNY')
            ? 'CNY'
            : (currencies[0] || 'CNY');
        this.error = null;
        this.screen = 'expenseForm';
    },

    editExpense(clientUuid) {
        const trip = this.currentTrip();
        const expense = (trip?.expenses || []).find((e) => e.client_uuid === clientUuid);
        if (!expense) return;
        this.editingExpenseUuid = clientUuid;
        this.expenseDraft = {
            category: expense.category,
            description: expense.description || '',
            amount: String(expense.amount ?? ''),
            currency_code: expense.currency_code || 'CNY',
            expense_date: expense.expense_date,
            // Rebuild previews from the stored blobs so they render in the form.
            photos: (expense.photos || []).map((p) => ({
                blob: p.blob,
                filename: p.filename,
                type: p.type,
                preview: p.blob ? URL.createObjectURL(p.blob) : null,
            })),
        };
        this.error = null;
        this.screen = 'expenseForm';
    },

    async onReceiptChange(event) {
        const files = Array.from(event.target.files || []);
        if (!files.length) return;
        const MAX_PHOTOS = 8;
        for (const file of files) {
            if (this.expenseDraft.photos.length >= MAX_PHOTOS) break;
            const compressed = await compressImage(file);
            this.expenseDraft.photos.push({
                ...compressed,
                preview: URL.createObjectURL(compressed.blob),
            });
        }
        event.target.value = '';
    },

    removeReceipt(photoIdx) {
        const removed = this.expenseDraft.photos.splice(photoIdx, 1)[0];
        if (removed?.preview) URL.revokeObjectURL(removed.preview);
    },

    // Accept both ',' and '.' as the decimal separator (mobile keyboards vary).
    parseAmount(value) {
        if (value === '' || value == null) return NaN;
        return Number(String(value).replace(',', '.'));
    },

    canSaveExpense() {
        const e = this.expenseDraft;
        const amount = this.parseAmount(e.amount);
        return !!e.category && !Number.isNaN(amount) && amount > 0 && !!e.expense_date;
    },

    async saveExpense() {
        if (!this.canSaveExpense() || this.submitting) return;
        const trip = this.currentTrip();
        if (!trip) return;
        this.submitting = true;
        this.error = null;
        try {
            const e = this.expenseDraft;
            const photos = (e.photos || [])
                .map((p) => (p && p.blob) ? { blob: p.blob, filename: p.filename, type: p.type } : null)
                .filter(Boolean);

            const plain = this.toPlainTrip(trip);

            if (this.editingExpenseUuid) {
                // Editing: update the existing expense in place and mark it dirty
                // (synced=false) so the correction is re-sent to the server.
                const target = plain.expenses.find((x) => x.client_uuid === this.editingExpenseUuid);
                if (target) {
                    target.category = e.category;
                    target.description = e.description || null;
                    target.amount = this.parseAmount(e.amount);
                    target.currency_code = e.currency_code || 'USD';
                    target.expense_date = e.expense_date;
                    target.photos = photos;
                    target.synced = false;
                }
            } else {
                plain.expenses.push({
                    client_uuid: uuid(),
                    category: e.category,
                    description: e.description || null,
                    amount: this.parseAmount(e.amount),
                    currency_code: e.currency_code || 'USD',
                    expense_date: e.expense_date,
                    synced: false,
                    photos,
                });
            }

            plain.updatedAt = Date.now();
            await saveTrip(plain);
            await this.loadTrips();
            this.editingExpenseUuid = null;
            this.screen = 'trip';
            requestBackgroundSync();
            if (this.online) this.runSync();
        } catch (err) {
            this.error = err?.message || this.t('err_save_expense');
        } finally {
            this.submitting = false;
        }
    },

    async deleteExpense(clientUuid) {
        const trip = this.currentTrip();
        if (!trip) return;
        if (!confirm(this.t('confirm_delete_expense'))) return;

        const plain = this.toPlainTrip(trip);
        const existed = plain.expenses.some((x) => x.client_uuid === clientUuid);
        plain.expenses = plain.expenses.filter((x) => x.client_uuid !== clientUuid);
        // Tell the server to drop it too (no-op if it was never synced).
        if (existed && !plain.deletedExpenseUuids.includes(clientUuid)) {
            plain.deletedExpenseUuids.push(clientUuid);
        }
        plain.updatedAt = Date.now();
        await saveTrip(plain);
        await this.loadTrips();
        requestBackgroundSync();
        if (this.online) this.runSync();
    },

    async finalizeTrip() {
        const trip = this.currentTrip();
        if (!trip) return;
        if (!confirm(this.t('confirm_finalize'))) {
            return;
        }
        const plain = this.toPlainTrip(trip);
        plain.finalizeRequested = true;
        plain.updatedAt = Date.now();
        await saveTrip(plain);
        await this.loadTrips();
        requestBackgroundSync();
        if (this.online) this.runSync();
    },

    async removeTrip(localId) {
        if (!confirm(this.t('confirm_discard_trip'))) return;
        await deleteTrip(localId);
        await this.loadTrips();
        if (this.currentTripLocalId === localId) this.backToList();
    },

    /**
     * Rebuild a plain (proxy-free) copy of a trip so IndexedDB's structuredClone
     * can store it. Blobs pass through untouched.
     */
    toPlainTrip(t) {
        return {
            localId: t.localId,
            client_uuid: t.client_uuid,
            serverId: t.serverId ?? null,
            serverStatus: t.serverStatus ?? null,
            title: t.title,
            is_internal: !!t.is_internal,
            company_id: t.company_id ?? null,
            company_name: t.company_name ?? null,
            destination_city: t.destination_city ?? null,
            destination_country: t.destination_country ?? null,
            start_date: t.start_date,
            end_date: t.end_date ?? null,
            notes: t.notes ?? null,
            status: t.status || 'draft',
            headerSynced: !!t.headerSynced,
            finalizeRequested: !!t.finalizeRequested,
            lastError: t.lastError ?? null,
            deletedExpenseUuids: [...(t.deletedExpenseUuids || [])],
            createdAt: t.createdAt ?? Date.now(),
            updatedAt: t.updatedAt ?? Date.now(),
            expenses: (t.expenses || []).map((e) => ({
                client_uuid: e.client_uuid,
                category: e.category,
                description: e.description ?? null,
                amount: e.amount,
                currency_code: e.currency_code,
                expense_date: e.expense_date,
                synced: !!e.synced,
                photos: (e.photos || [])
                    .map((p) => (p && p.blob) ? { blob: p.blob, filename: p.filename, type: p.type } : null)
                    .filter(Boolean),
            })),
        };
    },

    // ─── Sync ───────────────────────────────────────────────────

    async runSync() {
        if (this.syncing || !this.online || !auth.token()) return;
        this.syncing = true;
        try {
            await syncAllTrips(() => {}, this.locale);
            await this.reconcileFromServer();
            await this.loadTrips();
        } catch (err) {
            console.warn('Sync failed', err);
        } finally {
            this.syncing = false;
        }
    },

    /**
     * Pull the server's view to reflect approvals/rejections that happened in
     * the admin panel back onto the local trips (matched by client_uuid).
     */
    async reconcileFromServer() {
        if (!this.online) return;
        try {
            const response = await api.listTrips();
            const byUuid = new Map((response.data || []).map((t) => [t.client_uuid, t]));
            const locals = await listTrips();
            for (const trip of locals) {
                const remote = byUuid.get(trip.client_uuid);
                if (remote && remote.status && remote.status !== trip.serverStatus) {
                    trip.serverStatus = remote.status;
                    if (['submitted', 'approved', 'rejected'].includes(remote.status)) {
                        trip.status = remote.status;
                    }
                    await saveTrip(trip);
                }
            }
        } catch { /* best-effort */ }
    },

    async handleOnline() {
        if (auth.token()) {
            this.tryRefreshReference();
            this.runSync();
        }
    },

    async tryRefreshReference() {
        try {
            const refResponse = await api.referenceData();
            await saveReferenceData(refResponse);
            this.reference = refResponse;
            this.cacheStale = false;
        } catch { /* keep cached values */ }
    },

    // ─── Display helpers ────────────────────────────────────────

    tripPending(trip) {
        return tripHasPending(trip);
    },

    tripStatusLabel(trip) {
        if (this.tripPending(trip)) return this.t('status_not_synced');
        return {
            draft: this.t('status_draft'),
            submitted: this.t('status_submitted'),
            approved: this.t('status_approved'),
            rejected: this.t('status_rejected'),
        }[trip.status] || trip.status;
    },

    tripStatusClass(trip) {
        if (this.tripPending(trip)) return 'bg-amber-100 text-amber-800';
        return {
            draft: 'bg-gray-100 text-gray-700',
            submitted: 'bg-blue-100 text-blue-800',
            approved: 'bg-green-100 text-green-800',
            rejected: 'bg-red-100 text-red-800',
        }[trip.status] || 'bg-gray-100 text-gray-700';
    },

    isDraft(trip) {
        return (trip?.status || 'draft') === 'draft';
    },

    categoryLabel(value) {
        const found = (this.reference.expense_categories || []).find((c) => c.value === value);
        return found?.label || value;
    },

    money(amount, currency) {
        const n = Number(amount || 0);
        return `${n.toLocaleString(numberLocale(this.locale), { minimumFractionDigits: 2, maximumFractionDigits: 2 })} ${currency || ''}`.trim();
    },

    tripTotals(trip) {
        const totals = {};
        for (const e of (trip?.expenses || [])) {
            const cur = e.currency_code || '?';
            totals[cur] = (totals[cur] || 0) + Number(e.amount || 0);
        }
        return Object.entries(totals).map(([cur, sum]) => this.money(sum, cur)).join(' · ') || '—';
    },

    formatDateTime(value) {
        if (!value) return '';
        const d = new Date(value);
        if (Number.isNaN(d.getTime())) return value;
        const pad = (n) => String(n).padStart(2, '0');
        return `${pad(d.getDate())}/${pad(d.getMonth() + 1)} ${pad(d.getHours())}:${pad(d.getMinutes())}`;
    },
}));

Alpine.start();

if ('serviceWorker' in navigator) {
    window.addEventListener('load', () => {
        navigator.serviceWorker.register('/trips-mobile/sw.js').catch((err) => {
            console.warn('Service worker registration failed', err);
        });
    });

    navigator.serviceWorker.addEventListener('message', (event) => {
        if (event.data?.type === 'sync-now') {
            const root = document.querySelector('[x-data]');
            const ctx = root && Alpine.$data(root);
            ctx?.runSync?.();
        }
    });
}
