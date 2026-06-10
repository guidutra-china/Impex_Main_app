import { api, ApiError } from './api.js';
import { companyHasPending, listFairCompanies, saveFairCompany } from './db.js';
import { t } from './i18n.js';

const SYNC_TAG = 'fair-mobile-sync';

/**
 * Build the multipart payload for one company: header + only the products the
 * server has not received yet (matched by client_uuid/id) + deletions. Photos
 * travel as Blobs.
 */
export function buildCompanyFormData(company) {
    const fd = new FormData();
    if (company.client_uuid) fd.append('client_uuid', company.client_uuid);
    if (company.server_id) fd.append('id', company.server_id);
    fd.append('trade_fair_id', company.trade_fair_id);
    fd.append('company_name', company.name || '');
    fd.append('address_city', company.address_city || '');
    fd.append('address_country', company.address_country || '');
    fd.append('company_notes', company.company_notes || '');

    // Authoritative category set (flag lets the server tell "empty" from "absent").
    fd.append('sync_categories', '1');
    (company.category_ids || []).forEach((id, i) => {
        fd.append(`category_ids[${i}]`, id);
    });

    const contact = company.contact || {};
    fd.append('contact_name', contact.name || '');
    fd.append('contact_email', contact.email || '');
    fd.append('contact_phone', contact.phone || '');
    fd.append('contact_wechat', contact.wechat || '');

    (company.company_photos || []).forEach((photo, i) => {
        if (photo?.blob) {
            fd.append(`company_photos[${i}]`, photo.blob, photo.filename || `company-${i}.jpg`);
        }
    });

    // Only send products that changed; untouched synced products stay server-side.
    const unsynced = (company.products || []).filter((p) => !p.synced);
    unsynced.forEach((product, idx) => {
        if (product.server_id) fd.append(`products[${idx}][id]`, product.server_id);
        if (product.client_uuid) fd.append(`products[${idx}][client_uuid]`, product.client_uuid);
        fd.append(`products[${idx}][name]`, product.name || '');
        fd.append(`products[${idx}][category_id]`, product.category_id);
        if (product.unit_price != null && product.unit_price !== '') {
            fd.append(`products[${idx}][unit_price]`, product.unit_price);
        }
        fd.append(`products[${idx}][currency_code]`, product.currency_code || 'USD');
        if (product.moq != null && product.moq !== '') {
            fd.append(`products[${idx}][moq]`, product.moq);
        }
        (product.photos || []).forEach((photo, pi) => {
            if (photo?.blob) {
                fd.append(`products[${idx}][photos][${pi}]`, photo.blob, photo.filename || `product-${pi}.jpg`);
            }
        });
        (product.deleted_image_ids || []).forEach((imgId, di) => {
            fd.append(`products[${idx}][deleted_image_ids][${di}]`, imgId);
        });
    });

    (company.deletedProductUuids || []).forEach((u, i) => {
        fd.append(`deleted_product_uuids[${i}]`, u);
    });
    (company.deletedProductIds || []).forEach((id, i) => {
        fd.append(`deleted_product_ids[${i}]`, id);
    });

    return fd;
}

/**
 * Reconcile a local company with the server snapshot returned by the upsert:
 * record server ids, mark everything synced, refresh existing images, and drop
 * the blobs/deletions that have now been persisted.
 */
function reconcile(company, serverCompany) {
    company.server_id = serverCompany.id;
    company.client_uuid = serverCompany.client_uuid || company.client_uuid;
    company.headerSynced = true;
    company.existing_photos = serverCompany.photos || [];
    company.company_photos = [];
    company.lastError = null;
    company.deletedProductUuids = [];
    company.deletedProductIds = [];

    const byCpId = new Map((serverCompany.products || []).filter((p) => p.company_product_id != null).map((p) => [p.company_product_id, p]));
    const byUuid = new Map((serverCompany.products || []).filter((p) => p.client_uuid).map((p) => [p.client_uuid, p]));

    for (const product of company.products || []) {
        const match = (product.server_id && byCpId.get(product.server_id))
            || (product.client_uuid && byUuid.get(product.client_uuid));
        if (match) {
            product.server_id = match.company_product_id;
            product.synced = true;
            product.existing_images = match.images || [];
            product.photos = [];
            product.deleted_image_ids = [];
        }
    }
}

/**
 * Push every company that has pending work. Each is independent — one failure
 * does not abort the others (except a 401, which stops the run).
 *
 * @returns {Promise<{ ok: number, failed: number }>}
 */
export async function syncFairCompanies(onEvent = () => {}, locale = 'en') {
    const companies = await listFairCompanies();
    const stats = { ok: 0, failed: 0 };

    for (const company of companies) {
        if (!companyHasPending(company)) continue;

        onEvent({ type: 'syncing', company });

        try {
            const result = await api.submitSupplier(buildCompanyFormData(company));
            reconcile(company, result.company);
            await saveFairCompany(company);
            onEvent({ type: 'synced', company, result });
            stats.ok++;
        } catch (err) {
            company.lastError = classifyError(err, locale);
            await saveFairCompany(company);
            onEvent({ type: 'error', company, error: err });
            stats.failed++;

            if (err instanceof ApiError && err.status === 401) {
                break;
            }
        }
    }

    return stats;
}

function classifyError(err, locale = 'en') {
    if (err instanceof ApiError) {
        if (err.status === 409) {
            return err.body?.message || t(locale, 'sync_err_conflict');
        }
        if (err.status === 422) {
            return firstValidationMessage(err.body) || t(locale, 'sync_err_validation');
        }
        if (err.status === 401) {
            return t(locale, 'sync_err_session');
        }
    }
    return err?.message || t(locale, 'sync_err_network');
}

function firstValidationMessage(body) {
    if (!body?.errors) return null;
    const first = Object.values(body.errors)[0];
    return Array.isArray(first) ? first[0] : null;
}

/**
 * Ask the SW to schedule a background sync (Chromium). Falls back silently
 * when unsupported — the SPA retries on online/visibility events instead.
 */
export async function requestBackgroundSync() {
    if (!('serviceWorker' in navigator)) return false;
    try {
        const reg = await navigator.serviceWorker.ready;
        if (!reg.sync) return false;
        await reg.sync.register(SYNC_TAG);
        return true;
    } catch {
        return false;
    }
}

export const SYNC_TAG_NAME = SYNC_TAG;
