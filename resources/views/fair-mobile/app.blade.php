<!DOCTYPE html>
<html lang="pt-BR">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1, maximum-scale=1, viewport-fit=cover">
    <meta name="theme-color" content="#ea580c">
    <meta name="mobile-web-app-capable" content="yes">
    <meta name="apple-mobile-web-app-capable" content="yes">
    <meta name="apple-mobile-web-app-status-bar-style" content="black-translucent">
    <meta name="apple-mobile-web-app-title" content="Impex Fair">
    <link rel="manifest" href="/fair-mobile/manifest.json">
    <link rel="apple-touch-icon" href="/pwa/icons/fair/icon-180.png">
    <title>Impex Fair</title>
    @vite(['resources/css/fair-mobile/app.css', 'resources/js/fair-mobile/app.js'])
</head>
<body class="bg-gray-100 text-gray-900 min-h-screen">

<div x-data="fairApp" x-cloak class="min-h-screen flex flex-col">

    {{-- ── Loading ─────────────────────────────────────── --}}
    <template x-if="screen === 'loading'">
        <div class="flex-1 flex items-center justify-center">
            <div class="text-center">
                <div class="inline-block w-10 h-10 border-4 border-gray-300 border-t-orange-600 rounded-full animate-spin"></div>
                <p class="mt-3 text-sm text-gray-600" x-text="t('loading')"></p>
            </div>
        </div>
    </template>

    {{-- ── Login ───────────────────────────────────────── --}}
    <template x-if="screen === 'login'">
        <div class="flex-1 flex flex-col justify-center px-6 py-10 max-w-md mx-auto w-full">
            <div class="text-center mb-8">
                <h1 class="text-2xl font-bold text-gray-900">Impex Fair</h1>
                <p class="text-sm text-gray-600 mt-1" x-text="t('login_subtitle')"></p>
            </div>

            <form @submit.prevent="login" class="space-y-4">
                <div>
                    <label class="block text-sm font-medium text-gray-700" x-text="t('email')"></label>
                    <input type="email" x-model="loginForm.email" required autocomplete="email"
                           class="mt-1 block w-full rounded-lg border border-gray-300 px-3 py-3 focus:border-orange-600 focus:ring-orange-600">
                </div>
                <div>
                    <label class="block text-sm font-medium text-gray-700" x-text="t('password')"></label>
                    <input type="password" x-model="loginForm.password" required autocomplete="current-password"
                           class="mt-1 block w-full rounded-lg border border-gray-300 px-3 py-3 focus:border-orange-600 focus:ring-orange-600">
                </div>
                <template x-if="error">
                    <div class="rounded-lg bg-red-50 border border-red-200 p-3 text-sm text-red-800" x-text="error"></div>
                </template>
                <button type="submit" :disabled="submitting"
                        class="w-full bg-orange-600 hover:bg-orange-700 active:bg-orange-800 text-white font-semibold py-3 px-4 rounded-lg disabled:opacity-50 transition">
                    <span x-show="!submitting" x-text="t('sign_in')"></span>
                    <span x-show="submitting" x-text="t('signing_in')"></span>
                </button>
            </form>

            <button type="button" @click="resetLocalState"
                    class="mt-6 mx-auto block text-xs text-gray-400 hover:text-gray-600 underline" x-text="t('reset_local')">
            </button>
        </div>
    </template>

    {{-- ── List (fair companies) ───────────────────────── --}}
    <template x-if="screen === 'list'">
        <div class="flex-1">
            <header class="sticky top-0 z-10 bg-white border-b border-gray-200 px-4 py-3 flex items-center justify-between gap-3">
                @if(! empty($companyLogo ?? null))
                    <img src="{{ $companyLogo }}" alt="{{ $companyName ?? '' }}" class="h-7 w-auto max-w-[96px] object-contain shrink-0">
                @endif
                <div class="min-w-0 flex-1">
                    <h1 class="text-base font-semibold text-gray-900 truncate" x-text="t('companies_title')"></h1>
                    <p class="text-xs text-gray-500 truncate flex items-center gap-2">
                        <span class="inline-block w-1.5 h-1.5 rounded-full" :class="online ? 'bg-green-500' : 'bg-gray-400'"></span>
                        <span x-text="online ? (syncing ? t('syncing') : t('online')) : t('offline')"></span>
                        <template x-if="user"><span class="truncate">· <span x-text="user.name"></span></span></template>
                    </p>
                </div>
                <button @click="logout" class="text-xs text-gray-500 hover:text-gray-700 px-2 py-1" x-text="t('logout')"></button>
            </header>

            <template x-if="cacheStale">
                <div class="mx-4 mt-3 rounded-lg bg-amber-50 border border-amber-200 px-3 py-2 text-xs text-amber-800" x-text="t('cache_stale')"></div>
            </template>

            <main class="px-4 py-4 space-y-4 pb-28">
                {{-- Fair selector --}}
                <section class="bg-white rounded-lg border border-gray-200 p-4">
                    <label class="block text-sm font-medium text-gray-700" x-text="t('fair')"></label>
                    <template x-if="fairs.length === 0">
                        <div class="mt-2 space-y-2">
                            <p class="text-sm text-amber-700" x-text="t('no_active_fairs')"></p>
                            <p class="text-xs text-gray-600" x-text="t('create_in_admin')"></p>
                            <a href="/fair" target="_blank"
                               class="inline-block text-xs font-semibold border border-orange-600 text-orange-700 hover:bg-orange-50 px-3 py-2 rounded" x-text="t('open_fair')"></a>
                        </div>
                    </template>
                    <template x-if="fairs.length > 0">
                        <select x-model.number="selectedFairId" @change="onFairChange()"
                                class="mt-1 block w-full rounded-lg border border-gray-300 px-3 py-3 bg-white">
                            <template x-for="fair in fairs" :key="fair.id">
                                <option :value="fair.id" x-text="fair.name + (fair.location ? ' — ' + fair.location : '')"></option>
                            </template>
                        </select>
                    </template>
                </section>

                {{-- Companies --}}
                <template x-if="companies.length === 0">
                    <div class="text-center py-12 text-sm text-gray-500" x-text="t('no_companies')"></div>
                </template>

                <template x-for="company in companies" :key="company.localId">
                    <button @click="openCompany(company.localId)"
                            class="w-full text-left bg-white rounded-lg border border-gray-200 p-3 flex items-center justify-between gap-3 hover:bg-gray-50">
                        <div class="min-w-0 flex-1">
                            <p class="font-semibold text-sm text-gray-900 truncate" x-text="company.name || '—'"></p>
                            <p class="text-xs text-gray-500 truncate">
                                <span x-text="[company.address_city, company.address_country].filter(Boolean).join(', ')"></span>
                                <span x-text="' · ' + t('products_count', { count: company.products?.length || 0 })"></span>
                            </p>
                        </div>
                        <template x-if="companyHasPending(company)">
                            <span class="text-[10px] font-semibold px-2 py-1 rounded-full bg-amber-100 text-amber-800 whitespace-nowrap" x-text="t('not_synced')"></span>
                        </template>
                    </button>
                </template>
            </main>

            <div class="fixed bottom-0 inset-x-0 bg-white border-t border-gray-200 px-4 py-3 pb-[max(env(safe-area-inset-bottom),0.75rem)]">
                <button @click="newCompany" :disabled="fairs.length === 0"
                        class="w-full bg-orange-600 hover:bg-orange-700 active:bg-orange-800 text-white font-semibold py-3 px-4 rounded-lg disabled:bg-gray-300 transition" x-text="t('new_company')"></button>
            </div>
        </div>
    </template>

    {{-- ── Company form (new / edit header) ────────────── --}}
    <template x-if="screen === 'companyForm' && companyDraft">
        <div class="flex-1">
            <header class="sticky top-0 z-10 bg-white border-b border-gray-200 px-4 py-3 flex items-center justify-between gap-3">
                <button @click="screen = currentCompanyLocalId == null ? 'list' : 'companyDetail'" class="text-orange-700 font-semibold text-sm" x-text="t('back')"></button>
                <h1 class="text-base font-semibold text-gray-900" x-text="currentCompanyLocalId == null ? t('new_company').replace('+ ','') : t('edit_company')"></h1>
                <span class="w-12"></span>
            </header>

            <main class="px-4 py-4 space-y-4 pb-28">
                {{-- Company photos --}}
                <section class="bg-white rounded-lg border border-gray-200 p-4 space-y-3">
                    <label class="block text-sm font-medium text-gray-700">
                        <span x-text="t('company_photos')"></span>
                        <span class="text-gray-400" x-text="`(${companyDraft.company_photos.length}/8)`"></span>
                    </label>
                    <input type="file" accept="image/*" capture="environment" multiple
                           @change="onCompanyPhotoChange($event)"
                           :disabled="companyDraft.company_photos.length >= 8"
                           class="block w-full text-sm text-gray-600 file:mr-3 file:py-2 file:px-4 file:rounded-md file:border-0 file:bg-orange-50 file:text-orange-700 file:font-semibold disabled:opacity-50">
                    <p class="text-[11px] text-gray-400" x-text="t('company_cover_hint')"></p>
                    <div class="flex flex-wrap gap-2">
                        <template x-for="img in (companyDraft.existing_photos || [])" :key="'e'+img.id">
                            <img :src="img.url" :alt="t('company_photos')" class="rounded-md h-20 w-20 object-cover border opacity-90">
                        </template>
                        <template x-for="(photo, pi) in companyDraft.company_photos" :key="pi">
                            <div class="relative">
                                <img :src="photo.preview" :alt="t('company_photos')" class="rounded-md h-20 w-20 object-cover border">
                                <button type="button" @click="removeCompanyPhoto(pi)"
                                        class="absolute -top-1.5 -right-1.5 bg-red-600 text-white rounded-full w-5 h-5 flex items-center justify-center text-xs leading-none shadow">&times;</button>
                            </div>
                        </template>
                    </div>
                </section>

                {{-- Company --}}
                <section class="bg-white rounded-lg border border-gray-200 p-4 space-y-3">
                    <h2 class="text-sm font-semibold text-gray-900" x-text="t('company')"></h2>
                    <div>
                        <label class="block text-xs font-medium text-gray-700" x-text="t('name_required')"></label>
                        <input type="text" x-model="companyDraft.name" required
                               class="mt-1 block w-full rounded-lg border border-gray-300 px-3 py-3">
                    </div>
                    <div class="grid grid-cols-2 gap-3">
                        <div>
                            <label class="block text-xs font-medium text-gray-700" x-text="t('city')"></label>
                            <input type="text" x-model="companyDraft.address_city"
                                   class="mt-1 block w-full rounded-lg border border-gray-300 px-3 py-3">
                        </div>
                        <div>
                            <label class="block text-xs font-medium text-gray-700" x-text="t('country')"></label>
                            <select x-model="companyDraft.address_country"
                                    class="mt-1 block w-full rounded-lg border border-gray-300 px-3 py-3">
                                <template x-for="c in reference.countries" :key="c.code">
                                    <option :value="c.code" x-text="countryLabel(c)"></option>
                                </template>
                            </select>
                        </div>
                    </div>
                    <div>
                        <label class="block text-xs font-medium text-gray-700" x-text="t('notes')"></label>
                        <textarea x-model="companyDraft.company_notes" rows="2"
                                  class="mt-1 block w-full rounded-lg border border-gray-300 px-3 py-2"></textarea>
                    </div>
                </section>

                {{-- Contact (optional) --}}
                <section class="bg-white rounded-lg border border-gray-200 p-4 space-y-3">
                    <h2 class="text-sm font-semibold text-gray-900" x-text="t('contact_optional')"></h2>
                    <div>
                        <label class="block text-xs font-medium text-gray-700" x-text="t('name')"></label>
                        <input type="text" x-model="companyDraft.contact.name"
                               class="mt-1 block w-full rounded-lg border border-gray-300 px-3 py-3">
                    </div>
                    <div>
                        <label class="block text-xs font-medium text-gray-700" x-text="t('email')"></label>
                        <input type="email" x-model="companyDraft.contact.email"
                               class="mt-1 block w-full rounded-lg border border-gray-300 px-3 py-3">
                    </div>
                    <div class="grid grid-cols-2 gap-3">
                        <div>
                            <label class="block text-xs font-medium text-gray-700" x-text="t('phone')"></label>
                            <input type="tel" x-model="companyDraft.contact.phone"
                                   class="mt-1 block w-full rounded-lg border border-gray-300 px-3 py-3">
                        </div>
                        <div>
                            <label class="block text-xs font-medium text-gray-700" x-text="t('wechat')"></label>
                            <input type="text" x-model="companyDraft.contact.wechat"
                                   class="mt-1 block w-full rounded-lg border border-gray-300 px-3 py-3">
                        </div>
                    </div>
                </section>

                <template x-if="error">
                    <div class="rounded-lg bg-red-50 border border-red-200 p-3 text-sm text-red-800" x-text="error"></div>
                </template>
            </main>

            <div class="fixed bottom-0 inset-x-0 bg-white border-t border-gray-200 px-4 py-3 pb-[max(env(safe-area-inset-bottom),0.75rem)]">
                <button @click="saveCompany" :disabled="!canSaveCompany() || submitting"
                        class="w-full bg-orange-600 hover:bg-orange-700 active:bg-orange-800 text-white font-semibold py-3 px-4 rounded-lg disabled:bg-gray-300 transition">
                    <span x-show="!submitting" x-text="t('save_company')"></span>
                    <span x-show="submitting" x-text="t('saving')"></span>
                </button>
            </div>
        </div>
    </template>

    {{-- ── Company detail (products CRUD) ──────────────── --}}
    <template x-if="screen === 'companyDetail' && currentCompany()">
        <div class="flex-1">
            <header class="sticky top-0 z-10 bg-white border-b border-gray-200 px-4 py-3 flex items-center justify-between gap-3">
                <button @click="screen = 'list'" class="text-orange-700 font-semibold text-sm" x-text="t('back_companies')"></button>
                <h1 class="text-base font-semibold text-gray-900 truncate min-w-0 flex-1 text-center" x-text="currentCompany().name || '—'"></h1>
                <button @click="editCompany" class="text-xs text-orange-700 font-semibold px-2 py-1" x-text="t('edit_company')"></button>
            </header>

            <main class="px-4 py-4 space-y-4 pb-28">
                <section class="bg-white rounded-lg border border-gray-200 p-4 space-y-1">
                    <p class="text-sm text-gray-600" x-text="[currentCompany().address_city, currentCompany().address_country].filter(Boolean).join(', ')"></p>
                    <template x-if="currentCompany().contact && currentCompany().contact.name">
                        <p class="text-xs text-gray-500" x-text="currentCompany().contact.name"></p>
                    </template>
                    <template x-if="companyHasPending(currentCompany())">
                        <p class="text-[11px] text-amber-700 font-medium pt-1" x-text="t('not_synced')"></p>
                    </template>
                    <template x-if="currentCompany().lastError">
                        <p class="text-xs text-red-700 bg-red-50 border border-red-100 rounded p-2 mt-1" x-text="currentCompany().lastError"></p>
                    </template>
                </section>

                {{-- Products --}}
                <section class="space-y-3">
                    <div class="flex items-center justify-between">
                        <h2 class="text-sm font-semibold text-gray-900" x-text="t('products')"></h2>
                    </div>

                    <template x-if="(currentCompany().products || []).length === 0">
                        <p class="text-sm text-gray-500 py-4 text-center" x-text="t('no_products')"></p>
                    </template>

                    <template x-for="product in currentCompany().products" :key="product.client_uuid">
                        <div class="bg-white rounded-lg border border-gray-200 p-3 flex items-center justify-between gap-3">
                            <button @click="editProduct(product.client_uuid)" class="min-w-0 flex-1 text-left">
                                <p class="font-semibold text-sm text-gray-900 truncate" x-text="product.name || '—'"></p>
                                <p class="text-xs text-gray-500 truncate">
                                    <span x-text="categoryName(product.category_id)"></span>
                                    <span x-show="product.unit_price !== '' && product.unit_price != null" x-text="' · ' + product.unit_price + ' ' + (product.currency_code || '')"></span>
                                    <span x-show="!product.synced" class="text-amber-600" x-text="' · ' + t('not_synced')"></span>
                                </p>
                            </button>
                            <button @click="deleteProduct(product.client_uuid)"
                                    class="text-xs text-gray-400 hover:text-red-600 px-2 py-1" x-text="t('delete')"></button>
                        </div>
                    </template>

                    <button @click="addProduct"
                            class="w-full text-sm font-semibold border border-orange-600 text-orange-700 hover:bg-orange-50 py-2.5 rounded-lg" x-text="t('add_product')"></button>
                </section>

                <button @click="discardCompany"
                        class="mt-4 mx-auto block text-xs text-gray-400 hover:text-red-600 underline" x-text="t('discard_company')"></button>
            </main>
        </div>
    </template>

    {{-- ── Product form (add / edit) ───────────────────── --}}
    <template x-if="screen === 'productForm' && productDraft">
        <div class="flex-1">
            <header class="sticky top-0 z-10 bg-white border-b border-gray-200 px-4 py-3 flex items-center justify-between gap-3">
                <button @click="screen = 'companyDetail'" class="text-orange-700 font-semibold text-sm" x-text="t('back')"></button>
                <h1 class="text-base font-semibold text-gray-900" x-text="editingProductUuid ? t('edit_product') : t('new_product')"></h1>
                <span class="w-12"></span>
            </header>

            <main class="px-4 py-4 space-y-4 pb-28">
                <section class="bg-white rounded-lg border border-gray-200 p-4 space-y-3">
                    <div>
                        <label class="block text-xs font-medium text-gray-700" x-text="t('name_required')"></label>
                        <input type="text" x-model="productDraft.name"
                               class="mt-1 block w-full rounded-lg border border-gray-300 px-3 py-3">
                    </div>

                    {{-- Searchable category + quick-add --}}
                    <div class="relative">
                        <label class="block text-xs font-medium text-gray-700" x-text="t('category_required')"></label>
                        <input type="text" x-model="categoryQuery"
                               @focus="showCategoryDropdown = true" @input="showCategoryDropdown = true"
                               :placeholder="t('search_category')"
                               class="mt-1 block w-full rounded-lg border border-gray-300 px-3 py-3">
                        <div x-show="showCategoryDropdown" @click.away="showCategoryDropdown = false"
                             class="absolute z-20 mt-1 w-full bg-white border border-gray-200 rounded-lg shadow-lg max-h-52 overflow-auto">
                            <template x-for="c in filteredCategories()" :key="c.id">
                                <button type="button" @click="selectCategory(c)"
                                        class="block w-full text-left px-3 py-2 text-sm hover:bg-orange-50"
                                        :class="productDraft.category_id === c.id ? 'bg-orange-50 font-semibold' : ''"
                                        x-text="c.name"></button>
                            </template>
                            <template x-if="canCreateCategory()">
                                <button type="button" @click="createCategory()" :disabled="creatingCategory"
                                        class="block w-full text-left px-3 py-2 text-sm text-orange-700 font-semibold border-t border-gray-100 disabled:opacity-50">
                                    <span x-show="!creatingCategory" x-text="t('create_category', { name: categoryQuery.trim() })"></span>
                                    <span x-show="creatingCategory" x-text="t('creating_category')"></span>
                                </button>
                            </template>
                            <template x-if="!online && categoryQuery.trim() && !exactCategoryMatch()">
                                <p class="px-3 py-2 text-xs text-gray-400" x-text="t('category_offline')"></p>
                            </template>
                        </div>
                    </div>

                    <div class="grid grid-cols-3 gap-2">
                        <div class="col-span-1">
                            <label class="block text-xs font-medium text-gray-700" x-text="t('price')"></label>
                            <input type="number" step="0.01" min="0" x-model="productDraft.unit_price"
                                   class="mt-1 block w-full rounded-lg border border-gray-300 px-2 py-3">
                        </div>
                        <div class="col-span-1">
                            <label class="block text-xs font-medium text-gray-700" x-text="t('currency')"></label>
                            <select x-model="productDraft.currency_code"
                                    class="mt-1 block w-full rounded-lg border border-gray-300 px-2 py-3">
                                <template x-for="cur in reference.currencies" :key="cur">
                                    <option :value="cur" x-text="cur"></option>
                                </template>
                            </select>
                        </div>
                        <div class="col-span-1">
                            <label class="block text-xs font-medium text-gray-700" x-text="t('moq')"></label>
                            <input type="number" min="0" x-model="productDraft.moq"
                                   class="mt-1 block w-full rounded-lg border border-gray-300 px-2 py-3">
                        </div>
                    </div>

                    {{-- Photos --}}
                    <div>
                        <label class="block text-xs font-medium text-gray-700">
                            <span x-text="t('photos')"></span>
                            <span class="text-gray-400" x-text="`(${productDraft.photos.length}/8)`"></span>
                        </label>
                        <input type="file" accept="image/*" capture="environment" multiple
                               @change="onProductPhotoChange($event)"
                               :disabled="productDraft.photos.length >= 8"
                               class="mt-1 block w-full text-sm text-gray-600 file:mr-2 file:py-2 file:px-3 file:rounded-md file:border-0 file:bg-orange-50 file:text-orange-700 file:text-xs file:font-semibold disabled:opacity-50">
                        <p class="mt-1 text-[11px] text-gray-400" x-text="t('product_cover_hint')"></p>
                        <div class="mt-2 flex flex-wrap gap-2">
                            <template x-for="img in (productDraft.existing_images || [])" :key="'e'+img.id">
                                <div class="relative">
                                    <img :src="img.url" :alt="t('photos')" class="rounded-md h-20 w-20 object-cover border opacity-90">
                                    <button type="button" @click="removeExistingProductImage(img)"
                                            class="absolute -top-1.5 -right-1.5 bg-red-600 text-white rounded-full w-5 h-5 flex items-center justify-center text-xs leading-none shadow">&times;</button>
                                </div>
                            </template>
                            <template x-for="(photo, pi) in productDraft.photos" :key="pi">
                                <div class="relative">
                                    <img :src="photo.preview" :alt="t('photos')" class="rounded-md h-20 w-20 object-cover border">
                                    <button type="button" @click="removeProductPhoto(pi)"
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
                <button @click="saveProduct" :disabled="!canSaveProduct() || submitting"
                        class="w-full bg-orange-600 hover:bg-orange-700 active:bg-orange-800 text-white font-semibold py-3 px-4 rounded-lg disabled:bg-gray-300 transition">
                    <span x-show="!submitting" x-text="editingProductUuid ? t('save_changes') : t('save_product')"></span>
                    <span x-show="submitting" x-text="t('saving')"></span>
                </button>
            </div>
        </div>
    </template>

</div>

<style>[x-cloak] { display: none !important; }</style>
</body>
</html>
