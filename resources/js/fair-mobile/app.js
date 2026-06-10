import '../../css/fair-mobile/app.css';
import Alpine from 'alpinejs';
import { api, auth, ApiError } from './api.js';
import {
    companyHasPending,
    deleteFairCompany,
    emptyCompany,
    emptyProduct,
    getFairCompany,
    listFairCompanies,
    loadFairs,
    loadReferenceData,
    mergeServerCompanies,
    migrateLegacyQueue,
    saveFairCompany,
    saveFairs,
    saveReferenceData,
} from './db.js';
import { compressImage } from './image.js';
import { defaultLocale, numberLocale, resolveLocale, t as translate } from './i18n.js';
import { requestBackgroundSync, syncFairCompanies } from './sync.js';

window.Alpine = Alpine;

const MAX_PHOTOS = 8;

function plainPhoto(p) {
    return p?.blob ? { blob: p.blob, filename: p.filename, type: p.type } : null;
}

function plainImage(i) {
    return { id: i.id, url: i.url };
}

/**
 * Deep-copy a (possibly Alpine-reactive) company into a plain object safe for
 * IndexedDB. structuredClone — used internally by IDB — throws DataCloneError on
 * Alpine's Proxy wrappers, so we rebuild the graph by hand. Blobs are kept;
 * object-URL previews and proxies are dropped.
 */
function toPlainCompany(c) {
    const plain = {
        client_uuid: c.client_uuid,
        server_id: c.server_id ?? null,
        trade_fair_id: c.trade_fair_id ?? null,
        name: c.name || '',
        address_city: c.address_city || '',
        address_country: c.address_country || 'CN',
        company_notes: c.company_notes || '',
        category_ids: [...(c.category_ids || [])],
        contact: {
            name: c.contact?.name || '',
            email: c.contact?.email || '',
            phone: c.contact?.phone || '',
            wechat: c.contact?.wechat || '',
        },
        existing_photos: (c.existing_photos || []).map(plainImage),
        company_photos: (c.company_photos || []).map(plainPhoto).filter(Boolean),
        products: (c.products || []).map((p) => ({
            client_uuid: p.client_uuid,
            server_id: p.server_id ?? null,
            name: p.name || '',
            category_id: p.category_id || '',
            unit_price: p.unit_price ?? '',
            currency_code: p.currency_code || 'USD',
            moq: p.moq ?? '',
            existing_images: (p.existing_images || []).map(plainImage),
            photos: (p.photos || []).map(plainPhoto).filter(Boolean),
            deleted_image_ids: [...(p.deleted_image_ids || [])],
            synced: !!p.synced,
        })),
        deletedProductUuids: [...(c.deletedProductUuids || [])],
        deletedProductIds: [...(c.deletedProductIds || [])],
        headerSynced: !!c.headerSynced,
        lastError: c.lastError ?? null,
        createdAt: c.createdAt || Date.now(),
        updatedAt: Date.now(),
    };
    if (c.localId != null) plain.localId = c.localId;
    return plain;
}

Alpine.data('fairApp', () => ({
    screen: 'loading',                // loading | login | list | companyForm | companyDetail | productForm
    submitting: false,
    error: null,
    online: navigator.onLine,
    locale: defaultLocale(),

    user: null,
    fairs: [],
    selectedFairId: null,
    reference: { categories: [], currencies: [], countries: [] },
    cacheStale: false,
    syncing: false,

    companies: [],
    currentCompanyLocalId: null,
    companyDraft: null,
    productDraft: null,
    editingProductUuid: null,

    // Category comboboxes
    categoryQuery: '',
    showCategoryDropdown: false,
    creatingCategory: false,
    companyCategoryQuery: '',
    showCompanyCatDropdown: false,

    loginForm: { email: '', password: '' },

    // ─── i18n / helpers ─────────────────────────────────────────

    t(key, params = {}) {
        return translate(this.locale, key, params);
    },

    applyUserLocale() {
        const resolved = resolveLocale(auth.user()?.locale);
        if (resolved) this.locale = resolved;
    },

    countryLabel(c) {
        let name = c.name;
        try {
            name = new Intl.DisplayNames([numberLocale(this.locale)], { type: 'region' }).of(c.code) || c.name;
        } catch { /* unsupported — fall back to API name */ }
        return `${c.code} — ${name}`;
    },

    categoryName(id) {
        const cat = (this.reference.categories || []).find((c) => c.id === id);
        return cat ? cat.name : '';
    },

    // ─── Boot ───────────────────────────────────────────────────

    async init() {
        this.applyUserLocale();
        window.addEventListener('online', () => { this.online = true; this.handleOnline(); });
        window.addEventListener('offline', () => { this.online = false; });
        document.addEventListener('visibilitychange', () => {
            if (!document.hidden && this.online && auth.token()) this.runSync();
        });

        await migrateLegacyQueue();

        if (auth.token()) {
            await this.bootIntoApp();
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
            await this.bootIntoApp();
        } catch (err) {
            this.error = err instanceof ApiError
                ? (err.body?.errors?.email?.[0] || err.message)
                : this.t('err_connect');
        } finally {
            this.submitting = false;
        }
    },

    async bootIntoApp() {
        this.screen = 'loading';
        this.cacheStale = false;
        try {
            const [fairsResponse, refResponse] = await Promise.all([
                api.activeFairs(),
                api.referenceData(),
            ]);
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
            const [cachedFairs, cachedRef] = await Promise.all([loadFairs(), loadReferenceData()]);
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
        this.applyUserLocale();
        if (this.fairs.length > 0 && !this.selectedFairId) {
            this.selectedFairId = this.fairs[0].id;
        }
        await this.refreshServerCompanies();
        await this.loadCompanies();
        this.screen = 'list';

        if (this.online) this.runSync();
    },

    describeBootError(err) {
        if (err instanceof ApiError) {
            if (err.status === 403) return this.t('boot_err_403');
            if (err.status >= 500) return this.t('boot_err_500', { status: err.status });
            return this.t('boot_err_api', { status: err.status, message: err.message || '' }).trim();
        }
        if (err?.name === 'SyntaxError') return this.t('boot_err_html');
        return err?.message || this.t('err_connect');
    },

    async logout() {
        try { await api.logout(); } catch { /* offline logout is fine */ }
        this.screen = 'login';
        this.loginForm = { email: '', password: '' };
        this.user = null;
        this.companies = [];
    },

    async resetLocalState() {
        auth.clear();
        try {
            await Promise.all([saveFairs([]), saveReferenceData(null)]);
        } catch { /* IDB might not be initialised */ }
        window.location.reload();
    },

    // ─── Companies list ─────────────────────────────────────────

    async loadCompanies() {
        this.companies = await listFairCompanies(this.selectedFairId);
    },

    async refreshServerCompanies() {
        if (!this.online || !this.selectedFairId) return;
        try {
            const response = await api.fairCompanies(this.selectedFairId);
            await mergeServerCompanies(response.data, this.selectedFairId);
        } catch { /* keep local cache */ }
    },

    async onFairChange() {
        await this.refreshServerCompanies();
        await this.loadCompanies();
    },

    pendingCount() {
        return this.companies.filter((c) => companyHasPending(c)).length;
    },

    currentCompany() {
        return this.companies.find((c) => c.localId === this.currentCompanyLocalId) || null;
    },

    openCompany(localId) {
        this.currentCompanyLocalId = localId;
        this.error = null;
        this.screen = 'companyDetail';
    },

    // ─── Company form (new / edit header) ───────────────────────

    newCompany() {
        this.companyDraft = emptyCompany(this.selectedFairId);
        this.currentCompanyLocalId = null;
        this.companyCategoryQuery = '';
        this.showCompanyCatDropdown = false;
        this.error = null;
        this.screen = 'companyForm';
    },

    editCompany() {
        const c = this.currentCompany();
        if (!c) return;
        this.companyDraft = JSON.parse(JSON.stringify({
            name: c.name,
            address_city: c.address_city,
            address_country: c.address_country,
            company_notes: c.company_notes,
            category_ids: c.category_ids || [],
            contact: c.contact || { name: '', email: '', phone: '', wechat: '' },
            existing_photos: c.existing_photos || [],
        }));
        this.companyDraft.company_photos = []; // new uploads only
        this.companyCategoryQuery = '';
        this.showCompanyCatDropdown = false;
        this.error = null;
        this.screen = 'companyForm';
    },

    canSaveCompany() {
        return !!this.companyDraft?.name?.trim();
    },

    async saveCompany() {
        if (!this.canSaveCompany() || this.submitting) return;
        this.submitting = true;
        this.error = null;
        try {
            const base = this.currentCompanyLocalId == null
                ? emptyCompany(this.selectedFairId)
                : this.currentCompany();
            const company = toPlainCompany(base);

            company.name = this.companyDraft.name.trim();
            company.address_city = this.companyDraft.address_city || '';
            company.address_country = this.companyDraft.address_country || 'CN';
            company.company_notes = this.companyDraft.company_notes || '';
            company.category_ids = [...(this.companyDraft.category_ids || [])];
            company.contact = {
                name: this.companyDraft.contact?.name || '',
                email: this.companyDraft.contact?.email || '',
                phone: this.companyDraft.contact?.phone || '',
                wechat: this.companyDraft.contact?.wechat || '',
            };
            company.existing_photos = (this.companyDraft.existing_photos || []).map(plainImage);
            company.company_photos = [
                ...company.company_photos,
                ...(this.companyDraft.company_photos || []).map(plainPhoto).filter(Boolean),
            ];
            company.headerSynced = false;

            await saveFairCompany(company);
            this.currentCompanyLocalId = company.localId;
            await this.loadCompanies();
            this.screen = 'companyDetail';

            requestBackgroundSync();
            if (this.online) this.runSync();
        } catch (err) {
            console.error('[fair-mobile] saveCompany failed', err);
            this.error = err?.message || this.t('err_connect');
        } finally {
            this.submitting = false;
        }
    },

    async discardCompany() {
        const c = this.currentCompany();
        if (!c) return;
        if (!confirm(this.t('confirm_discard_company'))) return;
        await deleteFairCompany(c.localId);
        this.currentCompanyLocalId = null;
        await this.loadCompanies();
        this.screen = 'list';
    },

    // ─── Product form (add / edit / delete) ─────────────────────

    addProduct() {
        this.productDraft = emptyProduct();
        this.editingProductUuid = null;
        this.categoryQuery = '';
        this.showCategoryDropdown = false;
        this.error = null;
        this.screen = 'productForm';
    },

    editProduct(clientUuid) {
        const company = this.currentCompany();
        const product = (company?.products || []).find((p) => p.client_uuid === clientUuid);
        if (!product) return;
        this.productDraft = JSON.parse(JSON.stringify(product));
        this.productDraft.photos = [];
        this.editingProductUuid = clientUuid;
        this.categoryQuery = this.categoryName(product.category_id);
        this.showCategoryDropdown = false;
        this.error = null;
        this.screen = 'productForm';
    },

    canSaveProduct() {
        return !!this.productDraft?.name?.trim() && !!this.productDraft?.category_id;
    },

    async saveProduct() {
        if (!this.canSaveProduct() || this.submitting) return;
        const base = this.currentCompany();
        if (!base) return;
        this.submitting = true;
        this.error = null;
        try {
            const company = toPlainCompany(base);
            const draft = {
                name: this.productDraft.name.trim(),
                category_id: this.productDraft.category_id,
                unit_price: this.productDraft.unit_price,
                currency_code: this.productDraft.currency_code || 'USD',
                moq: this.productDraft.moq,
                existing_images: (this.productDraft.existing_images || []).map(plainImage),
                deleted_image_ids: [...(this.productDraft.deleted_image_ids || [])],
                photos: (this.productDraft.photos || []).map(plainPhoto).filter(Boolean),
            };

            if (this.editingProductUuid) {
                const idx = company.products.findIndex((p) => p.client_uuid === this.editingProductUuid);
                if (idx !== -1) {
                    const existing = company.products[idx];
                    company.products[idx] = {
                        ...existing,
                        ...draft,
                        photos: [...existing.photos, ...draft.photos],
                        synced: false,
                    };
                }
            } else {
                company.products.push({
                    ...emptyProduct(),
                    ...draft,
                    synced: false,
                });
            }

            await saveFairCompany(company);
            await this.loadCompanies();
            this.screen = 'companyDetail';

            requestBackgroundSync();
            if (this.online) this.runSync();
        } catch (err) {
            console.error('[fair-mobile] saveProduct failed', err);
            this.error = err?.message || this.t('err_connect');
        } finally {
            this.submitting = false;
        }
    },

    async deleteProduct(clientUuid) {
        const base = this.currentCompany();
        if (!base) return;
        if (!confirm(this.t('confirm_delete_product'))) return;
        const company = toPlainCompany(base);
        const product = company.products.find((p) => p.client_uuid === clientUuid);
        if (product?.server_id) {
            company.deletedProductIds.push(product.server_id);
        }
        company.products = company.products.filter((p) => p.client_uuid !== clientUuid);
        await saveFairCompany(company);
        await this.loadCompanies();

        requestBackgroundSync();
        if (this.online) this.runSync();
    },

    // ─── Category combobox ──────────────────────────────────────

    filteredCategories() {
        const q = this.categoryQuery.trim().toLowerCase();
        const list = this.reference.categories || [];
        if (!q) return list.slice(0, 50);
        return list.filter((c) => c.name.toLowerCase().includes(q)).slice(0, 50);
    },

    exactCategoryMatch() {
        const q = this.categoryQuery.trim().toLowerCase();
        return (this.reference.categories || []).some((c) => c.name.toLowerCase() === q);
    },

    canCreateCategory() {
        return this.online && this.categoryQuery.trim().length > 0 && !this.exactCategoryMatch();
    },

    selectCategory(c) {
        this.productDraft.category_id = c.id;
        this.categoryQuery = c.name;
        this.showCategoryDropdown = false;
    },

    /** Create a category on the server and add it to the cached reference list. */
    async createCategoryRaw(name) {
        const cat = await api.createCategory(name);
        if (!this.reference.categories.some((c) => c.id === cat.id)) {
            this.reference.categories.push(cat);
            this.reference.categories.sort((a, b) => a.name.localeCompare(b.name));
            await saveReferenceData(this.reference);
        }
        return cat;
    },

    async createCategory() {
        if (!this.canCreateCategory() || this.creatingCategory) return;
        this.creatingCategory = true;
        try {
            const cat = await this.createCategoryRaw(this.categoryQuery.trim());
            this.productDraft.category_id = cat.id;
            this.categoryQuery = cat.name;
            this.showCategoryDropdown = false;
        } catch (err) {
            this.error = err?.message || this.t('err_connect');
        } finally {
            this.creatingCategory = false;
        }
    },

    // ─── Company product-categories combobox (multi-select) ─────

    filteredCompanyCategories() {
        const q = this.companyCategoryQuery.trim().toLowerCase();
        const selected = this.companyDraft?.category_ids || [];
        return (this.reference.categories || [])
            .filter((c) => !selected.includes(c.id))
            .filter((c) => !q || c.name.toLowerCase().includes(q))
            .slice(0, 50);
    },

    companyCategoryNames() {
        return (this.companyDraft?.category_ids || []).map((id) => ({ id, name: this.categoryName(id) }));
    },

    addCompanyCategory(c) {
        if (!this.companyDraft.category_ids.includes(c.id)) {
            this.companyDraft.category_ids.push(c.id);
        }
        this.companyCategoryQuery = '';
        this.showCompanyCatDropdown = false;
    },

    removeCompanyCategory(id) {
        this.companyDraft.category_ids = this.companyDraft.category_ids.filter((x) => x !== id);
    },

    canCreateCompanyCategory() {
        const q = this.companyCategoryQuery.trim().toLowerCase();
        return this.online && q.length > 0
            && !(this.reference.categories || []).some((c) => c.name.toLowerCase() === q);
    },

    async createCompanyCategory() {
        if (!this.canCreateCompanyCategory() || this.creatingCategory) return;
        this.creatingCategory = true;
        try {
            const cat = await this.createCategoryRaw(this.companyCategoryQuery.trim());
            this.addCompanyCategory(cat);
        } catch (err) {
            this.error = err?.message || this.t('err_connect');
        } finally {
            this.creatingCategory = false;
        }
    },

    // ─── Photo handlers ─────────────────────────────────────────

    async onCompanyPhotoChange(event) {
        await this.appendPhotos(event, this.companyDraft.company_photos);
    },

    removeCompanyPhoto(idx) {
        const removed = this.companyDraft.company_photos.splice(idx, 1)[0];
        if (removed?.preview) URL.revokeObjectURL(removed.preview);
    },

    async onProductPhotoChange(event) {
        await this.appendPhotos(event, this.productDraft.photos);
    },

    removeProductPhoto(idx) {
        const removed = this.productDraft.photos.splice(idx, 1)[0];
        if (removed?.preview) URL.revokeObjectURL(removed.preview);
    },

    removeExistingProductImage(image) {
        this.productDraft.existing_images = (this.productDraft.existing_images || []).filter((i) => i.id !== image.id);
        this.productDraft.deleted_image_ids = [...(this.productDraft.deleted_image_ids || []), image.id];
    },

    async appendPhotos(event, target) {
        const files = Array.from(event.target.files || []);
        for (const file of files) {
            if (target.length >= MAX_PHOTOS) break;
            const compressed = await compressImage(file);
            target.push({ ...compressed, preview: URL.createObjectURL(compressed.blob) });
        }
        event.target.value = '';
    },

    // ─── Sync ───────────────────────────────────────────────────

    async runSync() {
        if (this.syncing || !this.online || !auth.token()) return;
        this.syncing = true;
        try {
            await syncFairCompanies(() => {}, this.locale);
            await this.loadCompanies();
        } catch (err) {
            console.warn('Sync failed', err);
        } finally {
            this.syncing = false;
        }
    },

    async handleOnline() {
        if (auth.token()) {
            await this.refreshServerCompanies();
            await this.loadCompanies();
            this.runSync();
        }
    },

    companyHasPending(company) {
        return companyHasPending(company);
    },
}));

Alpine.start();

if ('serviceWorker' in navigator) {
    window.addEventListener('load', () => {
        navigator.serviceWorker.register('/fair-mobile/sw.js').catch((err) => {
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
