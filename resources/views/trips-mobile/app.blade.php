<!DOCTYPE html>
<html lang="pt-BR">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1, maximum-scale=1, viewport-fit=cover">
    <meta name="theme-color" content="#0284c7">
    <meta name="mobile-web-app-capable" content="yes">
    <meta name="apple-mobile-web-app-capable" content="yes">
    <meta name="apple-mobile-web-app-status-bar-style" content="black-translucent">
    <meta name="apple-mobile-web-app-title" content="Impex Viagens">
    <link rel="manifest" href="/trips-mobile/manifest.json">
    <link rel="apple-touch-icon" href="/pwa/icons/icon-192.png">
    <title>Impex Viagens</title>
    @vite(['resources/css/trips-mobile/app.css', 'resources/js/trips-mobile/app.js'])
</head>
<body class="bg-gray-100 text-gray-900 min-h-screen">

<div x-data="tripsApp" x-cloak class="min-h-screen flex flex-col">

    {{-- ── Loading ─────────────────────────────────────── --}}
    <template x-if="screen === 'loading'">
        <div class="flex-1 flex items-center justify-center">
            <div class="text-center">
                <div class="inline-block w-10 h-10 border-4 border-gray-300 border-t-sky-600 rounded-full animate-spin"></div>
                <p class="mt-3 text-sm text-gray-600" x-text="t('loading')"></p>
            </div>
        </div>
    </template>

    {{-- ── Login ───────────────────────────────────────── --}}
    <template x-if="screen === 'login'">
        <div class="flex-1 flex flex-col justify-center px-6 py-10 max-w-md mx-auto w-full">
            <div class="text-center mb-8">
                <h1 class="text-2xl font-bold text-gray-900" x-text="t('app_title')"></h1>
                <p class="text-sm text-gray-600 mt-1" x-text="t('app_subtitle')"></p>
            </div>
            <form @submit.prevent="login" class="space-y-4">
                <div>
                    <label class="block text-sm font-medium text-gray-700" x-text="t('email')"></label>
                    <input type="email" x-model="loginForm.email" required autocomplete="email"
                           class="mt-1 block w-full rounded-lg border border-gray-300 px-3 py-3 focus:border-sky-600 focus:ring-sky-600">
                </div>
                <div>
                    <label class="block text-sm font-medium text-gray-700" x-text="t('password')"></label>
                    <input type="password" x-model="loginForm.password" required autocomplete="current-password"
                           class="mt-1 block w-full rounded-lg border border-gray-300 px-3 py-3 focus:border-sky-600 focus:ring-sky-600">
                </div>
                <template x-if="error">
                    <div class="rounded-lg bg-red-50 border border-red-200 p-3 text-sm text-red-800" x-text="error"></div>
                </template>
                <button type="submit" :disabled="submitting"
                        class="w-full bg-sky-600 hover:bg-sky-700 active:bg-sky-800 text-white font-semibold py-3 px-4 rounded-lg disabled:opacity-50 transition">
                    <span x-show="!submitting" x-text="t('sign_in')"></span>
                    <span x-show="submitting" x-text="t('signing_in')"></span>
                </button>
            </form>
            <button type="button" @click="resetLocalState"
                    class="mt-6 mx-auto block text-xs text-gray-400 hover:text-gray-600 underline" x-text="t('reset_local')"></button>
        </div>
    </template>

    {{-- ── Trips list ──────────────────────────────────── --}}
    <template x-if="screen === 'list'">
        <div class="flex-1">
            <header class="sticky top-0 z-10 bg-white border-b border-gray-200 px-4 py-3 flex items-center justify-between gap-3">
                <div class="min-w-0 flex-1">
                    <h1 class="text-base font-semibold text-gray-900 truncate" x-text="t('my_trips')"></h1>
                    <p class="text-xs text-gray-500 truncate flex items-center gap-2">
                        <span class="inline-block w-1.5 h-1.5 rounded-full" :class="online ? 'bg-green-500' : 'bg-gray-400'"></span>
                        <span x-text="online ? (syncing ? t('syncing') : t('online')) : t('offline')"></span>
                        <template x-if="user"><span class="truncate">· <span x-text="user.name"></span></span></template>
                    </p>
                </div>
                <button @click="runSync" :disabled="syncing || !online"
                        class="text-xs text-sky-700 font-semibold disabled:text-gray-400 px-2 py-1" x-text="t('sync')"></button>
                <button @click="logout" class="text-xs text-gray-500 hover:text-gray-700 px-2 py-1" x-text="t('logout')"></button>
            </header>

            <template x-if="cacheStale">
                <div class="mx-4 mt-3 rounded-lg bg-amber-50 border border-amber-200 px-3 py-2 text-xs text-amber-800" x-text="t('cache_stale')"></div>
            </template>

            <main class="px-4 py-4 space-y-3 pb-28">
                <template x-if="trips.length === 0">
                    <div class="text-center py-16 text-sm text-gray-500" x-text="t('no_trips')"></div>
                </template>

                <template x-for="trip in trips" :key="trip.localId">
                    <button @click="openTrip(trip.localId)"
                            class="w-full text-left bg-white rounded-lg border border-gray-200 p-3 space-y-1">
                        <div class="flex items-start justify-between gap-3">
                            <p class="font-semibold text-sm text-gray-900 truncate flex-1" x-text="trip.title"></p>
                            <span class="text-[10px] font-semibold px-2 py-1 rounded-full whitespace-nowrap"
                                  :class="tripStatusClass(trip)" x-text="tripStatusLabel(trip)"></span>
                        </div>
                        <p class="text-xs text-gray-500 truncate" x-text="trip.company_name || t('internal_name')"></p>
                        <div class="flex items-center justify-between text-xs">
                            <span class="text-gray-400" x-text="t('expenses_count', { count: trip.expenses?.length || 0 })"></span>
                            <span class="font-medium text-gray-700" x-text="tripTotals(trip)"></span>
                        </div>
                    </button>
                </template>
            </main>

            <div class="fixed bottom-0 inset-x-0 bg-white border-t border-gray-200 px-4 py-3 pb-[max(env(safe-area-inset-bottom),0.75rem)]">
                <button @click="newTrip"
                        class="w-full bg-sky-600 hover:bg-sky-700 active:bg-sky-800 text-white font-semibold py-3 px-4 rounded-lg transition" x-text="t('new_trip')"></button>
            </div>
        </div>
    </template>

    {{-- ── New trip header ─────────────────────────────── --}}
    <template x-if="screen === 'tripForm'">
        <div class="flex-1">
            <header class="sticky top-0 z-10 bg-white border-b border-gray-200 px-4 py-3 flex items-center justify-between gap-3">
                <button @click="backToList" class="text-sky-700 font-semibold text-sm" x-text="t('back')"></button>
                <h1 class="text-base font-semibold text-gray-900" x-text="t('new_trip_title')"></h1>
                <span class="w-12"></span>
            </header>

            <main class="px-4 py-4 space-y-4 pb-28">
                <section class="bg-white rounded-lg border border-gray-200 p-4 space-y-3">
                    <div>
                        <label class="block text-xs font-medium text-gray-700" x-text="t('title_label')"></label>
                        <input type="text" x-model="tripDraft.title" :placeholder="t('title_placeholder')"
                               class="mt-1 block w-full rounded-lg border border-gray-300 px-3 py-3">
                    </div>

                    <label class="flex items-center gap-2 text-sm text-gray-800 select-none">
                        <input type="checkbox" :checked="tripDraft.is_internal" @change="toggleInternal"
                               class="rounded border-gray-300 text-sky-600 focus:ring-sky-600">
                        <span x-text="t('internal_expense')"></span>
                    </label>

                    <template x-if="!tripDraft.is_internal">
                        <div>
                            <label class="block text-xs font-medium text-gray-700" x-text="t('company_label')"></label>
                            <template x-if="tripDraft.company">
                                <div class="mt-1 flex items-center justify-between gap-2 rounded-lg border border-sky-200 bg-sky-50 px-3 py-2">
                                    <span class="text-sm font-medium text-sky-900 truncate" x-text="tripDraft.company.name"></span>
                                    <button type="button" @click="clearCompany" class="text-xs text-sky-700 underline" x-text="t('change')"></button>
                                </div>
                            </template>
                            <template x-if="!tripDraft.company">
                                <div class="mt-1 space-y-2">
                                    <input type="search" x-model="companyQuery" @input.debounce.350ms="searchCompanies"
                                           :placeholder="t('company_search_ph')"
                                           class="block w-full rounded-lg border border-gray-300 px-3 py-3">
                                    <template x-if="searchingCompany"><p class="text-xs text-gray-400" x-text="t('searching')"></p></template>
                                    <template x-if="companyResults.length > 0">
                                        <div class="border border-gray-200 rounded-lg divide-y divide-gray-100 overflow-hidden">
                                            <template x-for="c in companyResults" :key="c.id">
                                                <button type="button" @click="selectCompany(c)" class="w-full text-left px-3 py-2 hover:bg-gray-50">
                                                    <span class="block text-sm text-gray-900 truncate" x-text="c.name"></span>
                                                    <span class="block text-[11px] text-gray-500 truncate" x-text="[c.city, c.country].filter(Boolean).join(', ')"></span>
                                                </button>
                                            </template>
                                        </div>
                                    </template>
                                    <template x-if="!online"><p class="text-[11px] text-amber-700" x-text="t('offline_company_hint')"></p></template>
                                </div>
                            </template>
                        </div>
                    </template>

                    <div class="grid grid-cols-2 gap-3">
                        <div>
                            <label class="block text-xs font-medium text-gray-700" x-text="t('dest_city')"></label>
                            <input type="text" x-model="tripDraft.destination_city" class="mt-1 block w-full rounded-lg border border-gray-300 px-3 py-3">
                        </div>
                        <div>
                            <label class="block text-xs font-medium text-gray-700" x-text="t('dest_country')"></label>
                            <select x-model="tripDraft.destination_country" class="mt-1 block w-full rounded-lg border border-gray-300 px-3 py-3">
                                <template x-for="c in reference.countries" :key="c.code">
                                    <option :value="c.code" x-text="c.code + ' — ' + c.name"></option>
                                </template>
                            </select>
                        </div>
                    </div>
                    <div class="grid grid-cols-2 gap-3">
                        <div>
                            <label class="block text-xs font-medium text-gray-700" x-text="t('start_date')"></label>
                            <input type="date" x-model="tripDraft.start_date" class="mt-1 block w-full rounded-lg border border-gray-300 px-3 py-3">
                        </div>
                        <div>
                            <label class="block text-xs font-medium text-gray-700" x-text="t('end_date')"></label>
                            <input type="date" x-model="tripDraft.end_date" :min="tripDraft.start_date" class="mt-1 block w-full rounded-lg border border-gray-300 px-3 py-3">
                        </div>
                    </div>
                    <div>
                        <label class="block text-xs font-medium text-gray-700" x-text="t('notes')"></label>
                        <textarea x-model="tripDraft.notes" rows="2" class="mt-1 block w-full rounded-lg border border-gray-300 px-3 py-2"></textarea>
                    </div>
                </section>

                <template x-if="error">
                    <div class="rounded-lg bg-red-50 border border-red-200 p-3 text-sm text-red-800" x-text="error"></div>
                </template>
            </main>

            <div class="fixed bottom-0 inset-x-0 bg-white border-t border-gray-200 px-4 py-3 pb-[max(env(safe-area-inset-bottom),0.75rem)]">
                <button @click="createTrip" :disabled="!canCreateTrip() || submitting"
                        class="w-full bg-sky-600 hover:bg-sky-700 text-white font-semibold py-3 px-4 rounded-lg disabled:bg-gray-300 transition">
                    <span x-show="!submitting" x-text="t('create_trip')"></span>
                    <span x-show="submitting" x-text="t('creating')"></span>
                </button>
            </div>
        </div>
    </template>

    {{-- ── Trip detail ─────────────────────────────────── --}}
    <template x-if="screen === 'trip' && currentTrip()">
        <div class="flex-1" x-data="{ get trip() { return currentTrip(); } }">
            <header class="sticky top-0 z-10 bg-white border-b border-gray-200 px-4 py-3 flex items-center justify-between gap-3">
                <button @click="backToList" class="text-sky-700 font-semibold text-sm" x-text="t('back_trips')"></button>
                <h1 class="text-base font-semibold text-gray-900 truncate flex-1 text-center" x-text="trip.title"></h1>
                <span class="text-[10px] font-semibold px-2 py-1 rounded-full whitespace-nowrap"
                      :class="tripStatusClass(trip)" x-text="tripStatusLabel(trip)"></span>
            </header>

            <main class="px-4 py-4 space-y-4 pb-32">
                <section class="bg-white rounded-lg border border-gray-200 p-4 space-y-1">
                    <p class="text-sm font-medium text-gray-900" x-text="trip.company_name || t('internal_name')"></p>
                    <p class="text-xs text-gray-500" x-text="[trip.destination_city, trip.destination_country].filter(Boolean).join(', ')"></p>
                    <p class="text-xs text-gray-500" x-text="trip.start_date + (trip.end_date ? ' — ' + trip.end_date : '')"></p>
                    <p class="text-sm font-semibold text-gray-800 pt-1" x-text="t('total') + ': ' + tripTotals(trip)"></p>
                </section>

                <template x-if="trip.lastError">
                    <div class="rounded-lg bg-red-50 border border-red-200 p-3 text-xs text-red-800"><span x-text="trip.lastError"></span></div>
                </template>

                <section class="space-y-2">
                    <h2 class="text-sm font-semibold text-gray-900 px-1" x-text="t('expenses')"></h2>
                    <template x-if="(trip.expenses?.length || 0) === 0">
                        <p class="text-center text-sm text-gray-400 py-6" x-text="t('no_expenses_yet')"></p>
                    </template>
                    <template x-for="(e, i) in trip.expenses" :key="e.client_uuid">
                        <div class="bg-white rounded-lg border border-gray-200 p-3 flex items-start justify-between gap-3">
                            <button type="button" class="min-w-0 flex-1 text-left disabled:cursor-default"
                                    :disabled="!isDraft(trip)"
                                    @click="editExpense(e.client_uuid)">
                                <p class="text-sm font-medium text-gray-900" x-text="categoryLabel(e.category)"></p>
                                <p class="text-xs text-gray-500 truncate" x-text="e.description || '—'"></p>
                                <p class="text-[11px] text-gray-400 mt-0.5">
                                    <span x-text="formatDateTime(e.expense_date)"></span>
                                    <span x-show="(e.photos?.length || 0) > 0" x-text="' · ' + t('receipts_count', { count: e.photos?.length || 0 })"></span>
                                    <span x-show="!e.synced" class="text-amber-600"> · <span x-text="t('not_synced')"></span></span>
                                    <span x-show="isDraft(trip)" class="text-sky-600"> · <span x-text="t('tap_to_edit')"></span></span>
                                </p>
                            </button>
                            <div class="flex flex-col items-end gap-1 whitespace-nowrap">
                                <span class="text-sm font-semibold text-gray-800" x-text="money(e.amount, e.currency_code)"></span>
                                <template x-if="isDraft(trip)">
                                    <button @click.stop="deleteExpense(e.client_uuid)"
                                            class="text-[11px] text-red-500 hover:text-red-700" x-text="t('delete')"></button>
                                </template>
                            </div>
                        </div>
                    </template>
                </section>
            </main>

            <div class="fixed bottom-0 inset-x-0 bg-white border-t border-gray-200 px-4 py-3 pb-[max(env(safe-area-inset-bottom),0.75rem)] space-y-2">
                <template x-if="isDraft(trip)">
                    <button @click="addExpense"
                            class="w-full bg-sky-600 hover:bg-sky-700 text-white font-semibold py-3 px-4 rounded-lg transition" x-text="t('add_expense')"></button>
                </template>
                <template x-if="isDraft(trip) && (trip.expenses?.length || 0) > 0">
                    <button @click="finalizeTrip"
                            class="w-full border border-sky-600 text-sky-700 hover:bg-sky-50 font-semibold py-3 px-4 rounded-lg transition" x-text="t('finalize_trip')"></button>
                </template>
                <template x-if="!isDraft(trip)">
                    <p class="text-center text-xs text-gray-500 py-1" x-text="t('submitted_locked')"></p>
                </template>
                <button @click="removeTrip(trip.localId)"
                        class="w-full text-center text-xs text-red-600 hover:text-red-700 py-1" x-text="t('discard_trip')"></button>
            </div>
        </div>
    </template>

    {{-- ── Expense form ────────────────────────────────── --}}
    <template x-if="screen === 'expenseForm'">
        <div class="flex-1">
            <header class="sticky top-0 z-10 bg-white border-b border-gray-200 px-4 py-3 flex items-center justify-between gap-3">
                <button @click="screen = 'trip'" class="text-sky-700 font-semibold text-sm" x-text="t('back')"></button>
                <h1 class="text-base font-semibold text-gray-900" x-text="editingExpenseUuid ? t('expense_edit') : t('expense_new')"></h1>
                <span class="w-12"></span>
            </header>

            <main class="px-4 py-4 space-y-4 pb-28">
                <section class="bg-white rounded-lg border border-gray-200 p-4 space-y-3">
                    <div>
                        <label class="block text-xs font-medium text-gray-700" x-text="t('category')"></label>
                        <select x-model="expenseDraft.category" class="mt-1 block w-full rounded-lg border border-gray-300 px-3 py-3">
                            <option value="" x-text="t('select')"></option>
                            <template x-for="c in reference.expense_categories" :key="c.value">
                                <option :value="c.value" x-text="c.label"></option>
                            </template>
                        </select>
                    </div>
                    <div>
                        <label class="block text-xs font-medium text-gray-700" x-text="t('description')"></label>
                        <input type="text" x-model="expenseDraft.description" class="mt-1 block w-full rounded-lg border border-gray-300 px-3 py-3">
                    </div>
                    <div class="grid grid-cols-2 gap-3">
                        <div>
                            <label class="block text-xs font-medium text-gray-700" x-text="t('amount')"></label>
                            <input type="number" step="0.01" min="0" inputmode="decimal" x-model="expenseDraft.amount"
                                   class="mt-1 block w-full rounded-lg border border-gray-300 px-3 py-3">
                        </div>
                        <div>
                            <label class="block text-xs font-medium text-gray-700" x-text="t('currency')"></label>
                            <select x-model="expenseDraft.currency_code" class="mt-1 block w-full rounded-lg border border-gray-300 px-3 py-3">
                                <template x-for="cur in reference.currencies" :key="cur">
                                    <option :value="cur" x-text="cur"></option>
                                </template>
                            </select>
                        </div>
                    </div>
                    <div>
                        <label class="block text-xs font-medium text-gray-700" x-text="t('datetime')"></label>
                        <input type="datetime-local" x-model="expenseDraft.expense_date"
                               class="mt-1 block w-full rounded-lg border border-gray-300 px-3 py-3">
                        <p class="mt-1 text-[11px] text-gray-400" x-text="t('datetime_hint')"></p>
                    </div>
                    <div>
                        <label class="block text-xs font-medium text-gray-700">
                            <span x-text="t('receipt')"></span> <span class="text-gray-400" x-text="`(${expenseDraft.photos.length}/8)`"></span>
                        </label>
                        <input type="file" accept="image/*" multiple
                               @change="onReceiptChange($event)"
                               :disabled="expenseDraft.photos.length >= 8"
                               class="mt-1 block w-full text-sm text-gray-600 file:mr-2 file:py-2 file:px-3 file:rounded-md file:border-0 file:bg-sky-50 file:text-sky-700 file:text-xs file:font-semibold disabled:opacity-50">
                        <div class="mt-2 flex flex-wrap gap-2">
                            <template x-for="(photo, pi) in expenseDraft.photos" :key="pi">
                                <div class="relative">
                                    <img :src="photo.preview" alt="" class="rounded-md h-20 w-20 object-cover border">
                                    <button type="button" @click="removeReceipt(pi)"
                                            class="absolute -top-1.5 -right-1.5 bg-red-600 text-white rounded-full w-5 h-5 flex items-center justify-center text-xs leading-none shadow">&times;</button>
                                </div>
                            </template>
                        </div>
                    </div>
                </section>

                <template x-if="error">
                    <div class="rounded-lg bg-red-50 border border-red-200 p-3 text-sm text-red-800" x-text="error"></div>
                </template>
            </main>

            <div class="fixed bottom-0 inset-x-0 bg-white border-t border-gray-200 px-4 py-3 pb-[max(env(safe-area-inset-bottom),0.75rem)]">
                <button @click="saveExpense" :disabled="!canSaveExpense() || submitting"
                        class="w-full bg-sky-600 hover:bg-sky-700 text-white font-semibold py-3 px-4 rounded-lg disabled:bg-gray-300 transition">
                    <span x-show="!submitting" x-text="editingExpenseUuid ? t('save_changes') : t('save_expense')"></span>
                    <span x-show="submitting" x-text="t('saving')"></span>
                </button>
            </div>
        </div>
    </template>

</div>

<style>[x-cloak] { display: none !important; }</style>
</body>
</html>
