import { api, ApiError } from './api.js';
import { listTrips, saveTrip } from './db.js';
import { t } from './i18n.js';

const SYNC_TAG = 'trips-mobile-sync';

/**
 * A trip needs a push when its header was never synced, it has expenses the
 * server has not received yet, or the user asked to finalize it (end of trip).
 */
export function tripHasPending(trip) {
    if (!trip) return false;
    if (!trip.headerSynced) return true;
    if ((trip.expenses || []).some((e) => !e.synced)) return true;
    if ((trip.deletedExpenseUuids || []).length > 0) return true;
    if (trip.finalizeRequested && trip.status !== 'submitted') return true;
    return false;
}

/**
 * Build the multipart payload for one trip: header + only the expenses the
 * server does not have yet + the finalize flag. Receipt photos are Blobs.
 */
export function buildTripFormData(trip, unsyncedExpenses) {
    const fd = new FormData();
    fd.append('client_uuid', trip.client_uuid);
    fd.append('title', trip.title || '');
    fd.append('is_internal', trip.is_internal ? '1' : '0');
    if (!trip.is_internal && trip.company_id) {
        fd.append('company_id', trip.company_id);
    }
    fd.append('start_date', trip.start_date || '');
    if (trip.end_date) {
        fd.append('end_date', trip.end_date);
    }
    fd.append('destination_city', trip.destination_city || '');
    fd.append('destination_country', trip.destination_country || '');
    fd.append('notes', trip.notes || '');
    if (trip.finalizeRequested) {
        fd.append('finalize', '1');
    }

    (trip.deletedExpenseUuids || []).forEach((u, i) => {
        fd.append(`deleted_expense_uuids[${i}]`, u);
    });

    unsyncedExpenses.forEach((expense, idx) => {
        fd.append(`expenses[${idx}][client_uuid]`, expense.client_uuid);
        fd.append(`expenses[${idx}][category]`, expense.category);
        fd.append(`expenses[${idx}][description]`, expense.description || '');
        fd.append(`expenses[${idx}][amount]`, expense.amount ?? '');
        fd.append(`expenses[${idx}][currency_code]`, expense.currency_code || 'USD');
        fd.append(`expenses[${idx}][expense_date]`, expense.expense_date || '');

        (expense.photos || []).forEach((photo, photoIdx) => {
            if (photo?.blob) {
                fd.append(
                    `expenses[${idx}][photos][${photoIdx}]`,
                    photo.blob,
                    photo.filename || `receipt-${photoIdx}.jpg`,
                );
            }
        });
    });

    return fd;
}

/**
 * Push every trip that has pending work. Each trip is independent — one failure
 * does not abort the others (except a 401, which stops the run).
 *
 * @returns {Promise<{ ok: number, failed: number }>}
 */
export async function syncAllTrips(onEvent = () => {}, locale = 'en') {
    const trips = await listTrips();
    const stats = { ok: 0, failed: 0 };

    for (const trip of trips) {
        if (!tripHasPending(trip)) continue;

        const unsynced = (trip.expenses || []).filter((e) => !e.synced);
        onEvent({ type: 'syncing', trip });

        try {
            const fd = buildTripFormData(trip, unsynced);
            const result = await api.submitTrip(fd);

            trip.headerSynced = true;
            const sent = new Set(unsynced.map((e) => e.client_uuid));
            (trip.expenses || []).forEach((e) => {
                if (sent.has(e.client_uuid)) e.synced = true;
            });
            if (trip.finalizeRequested) {
                trip.status = 'submitted';
            }
            trip.deletedExpenseUuids = [];
            trip.serverId = result?.trip?.id ?? trip.serverId ?? null;
            trip.serverStatus = result?.trip?.status ?? trip.serverStatus ?? null;
            trip.lastError = null;
            trip.updatedAt = Date.now();
            await saveTrip(trip);
            onEvent({ type: 'synced', trip, result });
            stats.ok++;
        } catch (err) {
            trip.lastError = classifyError(err, locale);
            trip.updatedAt = Date.now();
            await saveTrip(trip);
            onEvent({ type: 'error', trip, error: err });
            stats.failed++;

            if (err instanceof ApiError && err.status === 401) {
                break;
            }
        }
    }

    return stats;
}

function classifyError(err, locale) {
    if (err instanceof ApiError) {
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
