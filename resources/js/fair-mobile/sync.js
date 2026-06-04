import { api, ApiError } from './api.js';
import {
    SubmissionStatus,
    deletePendingSubmission,
    listPendingSubmissions,
    updatePendingSubmission,
} from './db.js';

const SYNC_TAG = 'fair-mobile-sync';

/**
 * Build a FormData payload for the API from a queued submission.
 * Photos are stored in IndexedDB as { blob, filename, type } objects.
 */
export function buildFormData(payload) {
    const fd = new FormData();
    fd.append('trade_fair_id', payload.trade_fair_id);
    if (payload.existing_company_id) {
        fd.append('existing_company_id', payload.existing_company_id);
    }
    fd.append('company_name', payload.company_name || '');
    fd.append('address_city', payload.address_city || '');
    fd.append('address_country', payload.address_country || '');
    fd.append('company_notes', payload.company_notes || '');
    fd.append('contact_name', payload.contact_name || '');
    fd.append('contact_email', payload.contact_email || '');
    fd.append('contact_phone', payload.contact_phone || '');
    fd.append('contact_wechat', payload.contact_wechat || '');

    // Current shape: an array of company photos. Fall back to the legacy single
    // `business_card` for items queued before multi-photo support.
    const companyPhotos = Array.isArray(payload.company_photos)
        ? payload.company_photos
        : (payload.business_card ? [payload.business_card] : []);
    companyPhotos.forEach((photo, i) => {
        if (photo?.blob) {
            fd.append(`company_photos[${i}]`, photo.blob, photo.filename || `company-${i}.jpg`);
        }
    });

    let idx = 0;
    for (const product of payload.products || []) {
        if (!product.name) continue;
        fd.append(`products[${idx}][name]`, product.name);
        fd.append(`products[${idx}][category_id]`, product.category_id);
        if (product.unit_price != null && product.unit_price !== '') {
            fd.append(`products[${idx}][unit_price]`, product.unit_price);
        }
        fd.append(`products[${idx}][currency_code]`, product.currency_code || 'USD');
        if (product.moq != null && product.moq !== '') {
            fd.append(`products[${idx}][moq]`, product.moq);
        }
        // Current shape: an array of photos. Fall back to the legacy single
        // `photo` for items queued before multi-photo support.
        const photos = Array.isArray(product.photos)
            ? product.photos
            : (product.photo ? [product.photo] : []);
        photos.forEach((photo, photoIdx) => {
            if (photo?.blob) {
                fd.append(
                    `products[${idx}][photos][${photoIdx}]`,
                    photo.blob,
                    photo.filename || `product-${photoIdx}.jpg`,
                );
            }
        });
        idx++;
    }
    return fd;
}

/**
 * Drain the IndexedDB queue, attempting to sync each pending item.
 * Failures are recorded; the loop does not abort on a single failure.
 *
 * @param {(event: { type: string, item?: any, error?: any }) => void} [onEvent]
 * @returns {Promise<{ ok: number, failed: number, needsReview: number }>}
 */
export async function drainQueue(onEvent = () => {}) {
    const items = await listPendingSubmissions();
    const stats = { ok: 0, failed: 0, needsReview: 0 };

    for (const item of items) {
        // Skip items that already need user attention until they are edited.
        if (item.status === SubmissionStatus.NEEDS_REVIEW) {
            stats.needsReview++;
            continue;
        }

        await updatePendingSubmission(item.id, { status: SubmissionStatus.SYNCING, lastError: null });
        onEvent({ type: 'syncing', item });

        try {
            const fd = buildFormData(item.payload);
            const result = await api.submitSupplier(fd);
            await deletePendingSubmission(item.id);
            onEvent({ type: 'synced', item, result });
            stats.ok++;
        } catch (err) {
            const patch = classifyError(err);
            if (patch.status === SubmissionStatus.NEEDS_REVIEW) {
                stats.needsReview++;
            } else {
                stats.failed++;
            }
            await updatePendingSubmission(item.id, patch);
            onEvent({ type: 'error', item, error: err });

            // 401 invalidates session — stop draining; the SPA will surface login.
            if (err instanceof ApiError && err.status === 401) {
                break;
            }
        }
    }

    return stats;
}

function classifyError(err) {
    if (err instanceof ApiError) {
        if (err.status === 409) {
            return {
                status: SubmissionStatus.NEEDS_REVIEW,
                lastError: err.body?.message || 'Conflict — existing company detected.',
                conflict: err.body || null,
            };
        }
        if (err.status === 422) {
            return {
                status: SubmissionStatus.FAILED,
                lastError: firstValidationMessage(err.body) || 'Validation error.',
            };
        }
        if (err.status === 401) {
            return {
                status: SubmissionStatus.PENDING,
                lastError: 'Session expired — please log in again.',
            };
        }
    }
    return {
        status: SubmissionStatus.PENDING,
        lastError: err?.message || 'Network error — will retry.',
    };
}

function firstValidationMessage(body) {
    if (!body?.errors) return null;
    const first = Object.values(body.errors)[0];
    return Array.isArray(first) ? first[0] : null;
}

/**
 * Ask the SW to schedule a background sync (Chromium). Falls back silently
 * when unsupported (iOS Safari, Firefox) — the SPA retries on online/visibility
 * events instead.
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
