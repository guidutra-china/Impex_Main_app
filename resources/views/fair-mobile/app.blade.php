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
    <link rel="apple-touch-icon" href="/fair-mobile/icons/icon-192.png">
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
                <p class="mt-3 text-sm text-gray-600">Carregando…</p>
            </div>
        </div>
    </template>

    {{-- ── Login ───────────────────────────────────────── --}}
    <template x-if="screen === 'login'">
        <div class="flex-1 flex flex-col justify-center px-6 py-10 max-w-md mx-auto w-full">
            <div class="text-center mb-8">
                <h1 class="text-2xl font-bold text-gray-900">Impex Fair</h1>
                <p class="text-sm text-gray-600 mt-1">Cadastro de fornecedores em feira</p>
            </div>

            <form @submit.prevent="login" class="space-y-4">
                <div>
                    <label class="block text-sm font-medium text-gray-700">Email</label>
                    <input type="email" x-model="loginForm.email" required autocomplete="email"
                           class="mt-1 block w-full rounded-lg border border-gray-300 px-3 py-3 focus:border-orange-600 focus:ring-orange-600">
                </div>
                <div>
                    <label class="block text-sm font-medium text-gray-700">Senha</label>
                    <input type="password" x-model="loginForm.password" required autocomplete="current-password"
                           class="mt-1 block w-full rounded-lg border border-gray-300 px-3 py-3 focus:border-orange-600 focus:ring-orange-600">
                </div>
                <template x-if="error">
                    <div class="rounded-lg bg-red-50 border border-red-200 p-3 text-sm text-red-800" x-text="error"></div>
                </template>
                <button type="submit" :disabled="submitting"
                        class="w-full bg-orange-600 hover:bg-orange-700 active:bg-orange-800 text-white font-semibold py-3 px-4 rounded-lg disabled:opacity-50 transition">
                    <span x-show="!submitting">Entrar</span>
                    <span x-show="submitting">Entrando…</span>
                </button>
            </form>
        </div>
    </template>

    {{-- ── Capture ─────────────────────────────────────── --}}
    <template x-if="screen === 'capture'">
        <div class="flex-1">
            {{-- Header --}}
            <header class="sticky top-0 z-10 bg-white border-b border-gray-200 px-4 py-3 flex items-center justify-between gap-3">
                <div class="min-w-0 flex-1">
                    <h1 class="text-base font-semibold text-gray-900 truncate">Novo fornecedor</h1>
                    <p class="text-xs text-gray-500 truncate flex items-center gap-2">
                        <span class="inline-block w-1.5 h-1.5 rounded-full" :class="online ? 'bg-green-500' : 'bg-gray-400'"></span>
                        <span x-text="online ? (syncing ? 'Sincronizando…' : 'Online') : 'Offline'"></span>
                        <template x-if="user"><span class="truncate">· <span x-text="user.name"></span></span></template>
                    </p>
                </div>
                <button @click="openPending" class="relative text-xs text-gray-600 hover:text-gray-900 px-2 py-1 border border-gray-300 rounded-md">
                    Pendentes
                    <template x-if="pendingCount > 0">
                        <span class="absolute -top-1 -right-1 min-w-[1.25rem] h-5 px-1 rounded-full bg-orange-600 text-white text-[10px] font-bold flex items-center justify-center" x-text="pendingCount"></span>
                    </template>
                </button>
                <button @click="logout" class="text-xs text-gray-500 hover:text-gray-700 px-2 py-1">Sair</button>
            </header>

            <template x-if="cacheStale">
                <div class="mx-4 mt-3 rounded-lg bg-amber-50 border border-amber-200 px-3 py-2 text-xs text-amber-800">
                    Sem conexão — usando dados em cache. Os cadastros ficarão na fila até sincronizar.
                </div>
            </template>

            <main class="px-4 py-4 space-y-4 pb-32">
                {{-- Fair selector --}}
                <section class="bg-white rounded-lg border border-gray-200 p-4">
                    <label class="block text-sm font-medium text-gray-700">Feira</label>
                    <template x-if="fairs.length === 0">
                        <p class="mt-2 text-sm text-amber-700">Nenhuma feira ativa no momento.</p>
                    </template>
                    <template x-if="fairs.length > 0">
                        <select x-model.number="selectedFairId"
                                class="mt-1 block w-full rounded-lg border border-gray-300 px-3 py-3">
                            <template x-for="fair in fairs" :key="fair.id">
                                <option :value="fair.id" x-text="fair.name + (fair.location ? ' — ' + fair.location : '')"></option>
                            </template>
                        </select>
                    </template>
                </section>

                {{-- Business card --}}
                <section class="bg-white rounded-lg border border-gray-200 p-4 space-y-3">
                    <label class="block text-sm font-medium text-gray-700">Cartão de visita (opcional)</label>
                    <input type="file" accept="image/*" capture="environment" @change="onBusinessCardChange"
                           class="block w-full text-sm text-gray-600 file:mr-3 file:py-2 file:px-4 file:rounded-md file:border-0 file:bg-orange-50 file:text-orange-700 file:font-semibold">
                    <template x-if="draft.business_card_preview">
                        <img :src="draft.business_card_preview" alt="Cartão" class="rounded-md max-h-48 w-auto border">
                    </template>
                </section>

                {{-- Company --}}
                <section class="bg-white rounded-lg border border-gray-200 p-4 space-y-3">
                    <h2 class="text-sm font-semibold text-gray-900">Empresa</h2>
                    <div>
                        <label class="block text-xs font-medium text-gray-700">Nome *</label>
                        <input type="text" x-model="draft.company_name" required
                               class="mt-1 block w-full rounded-lg border border-gray-300 px-3 py-3">
                    </div>
                    <div class="grid grid-cols-2 gap-3">
                        <div>
                            <label class="block text-xs font-medium text-gray-700">Cidade</label>
                            <input type="text" x-model="draft.address_city"
                                   class="mt-1 block w-full rounded-lg border border-gray-300 px-3 py-3">
                        </div>
                        <div>
                            <label class="block text-xs font-medium text-gray-700">País</label>
                            <select x-model="draft.address_country"
                                    class="mt-1 block w-full rounded-lg border border-gray-300 px-3 py-3">
                                <template x-for="c in reference.countries" :key="c.code">
                                    <option :value="c.code" x-text="c.code + ' — ' + c.name"></option>
                                </template>
                            </select>
                        </div>
                    </div>
                    <div>
                        <label class="block text-xs font-medium text-gray-700">Anotações</label>
                        <textarea x-model="draft.company_notes" rows="2"
                                  class="mt-1 block w-full rounded-lg border border-gray-300 px-3 py-2"></textarea>
                    </div>
                </section>

                {{-- Contact --}}
                <section class="bg-white rounded-lg border border-gray-200 p-4 space-y-3">
                    <h2 class="text-sm font-semibold text-gray-900">Contato principal</h2>
                    <div>
                        <label class="block text-xs font-medium text-gray-700">Nome *</label>
                        <input type="text" x-model="draft.contact_name" required
                               class="mt-1 block w-full rounded-lg border border-gray-300 px-3 py-3">
                    </div>
                    <div>
                        <label class="block text-xs font-medium text-gray-700">Email</label>
                        <input type="email" x-model="draft.contact_email"
                               class="mt-1 block w-full rounded-lg border border-gray-300 px-3 py-3">
                    </div>
                    <div class="grid grid-cols-2 gap-3">
                        <div>
                            <label class="block text-xs font-medium text-gray-700">Telefone</label>
                            <input type="tel" x-model="draft.contact_phone"
                                   class="mt-1 block w-full rounded-lg border border-gray-300 px-3 py-3">
                        </div>
                        <div>
                            <label class="block text-xs font-medium text-gray-700">WeChat</label>
                            <input type="text" x-model="draft.contact_wechat"
                                   class="mt-1 block w-full rounded-lg border border-gray-300 px-3 py-3">
                        </div>
                    </div>
                </section>

                {{-- Products --}}
                <section class="bg-white rounded-lg border border-gray-200 p-4 space-y-4">
                    <div class="flex items-center justify-between">
                        <h2 class="text-sm font-semibold text-gray-900">Produtos</h2>
                        <button type="button" @click="addProduct"
                                class="text-xs font-semibold text-orange-700 hover:text-orange-800 px-2 py-1">
                            + Adicionar
                        </button>
                    </div>

                    <template x-for="(product, idx) in draft.products" :key="idx">
                        <div class="border border-gray-200 rounded-lg p-3 space-y-3">
                            <div class="flex items-center justify-between">
                                <span class="text-xs font-semibold text-gray-600" x-text="'Produto ' + (idx + 1)"></span>
                                <button type="button" @click="removeProduct(idx)"
                                        class="text-xs text-gray-500 hover:text-red-600">Remover</button>
                            </div>
                            <div>
                                <label class="block text-xs font-medium text-gray-700">Nome *</label>
                                <input type="text" x-model="product.name"
                                       class="mt-1 block w-full rounded-lg border border-gray-300 px-3 py-3">
                            </div>
                            <div>
                                <label class="block text-xs font-medium text-gray-700">Categoria *</label>
                                <select x-model.number="product.category_id"
                                        class="mt-1 block w-full rounded-lg border border-gray-300 px-3 py-3">
                                    <option value="">Selecionar…</option>
                                    <template x-for="c in reference.categories" :key="c.id">
                                        <option :value="c.id" x-text="c.name"></option>
                                    </template>
                                </select>
                            </div>
                            <div class="grid grid-cols-3 gap-2">
                                <div class="col-span-1">
                                    <label class="block text-xs font-medium text-gray-700">Preço</label>
                                    <input type="number" step="0.01" min="0" x-model="product.unit_price"
                                           class="mt-1 block w-full rounded-lg border border-gray-300 px-2 py-3">
                                </div>
                                <div class="col-span-1">
                                    <label class="block text-xs font-medium text-gray-700">Moeda</label>
                                    <select x-model="product.currency_code"
                                            class="mt-1 block w-full rounded-lg border border-gray-300 px-2 py-3">
                                        <template x-for="cur in reference.currencies" :key="cur">
                                            <option :value="cur" x-text="cur"></option>
                                        </template>
                                    </select>
                                </div>
                                <div class="col-span-1">
                                    <label class="block text-xs font-medium text-gray-700">MOQ</label>
                                    <input type="number" min="0" x-model="product.moq"
                                           class="mt-1 block w-full rounded-lg border border-gray-300 px-2 py-3">
                                </div>
                            </div>
                            <div>
                                <label class="block text-xs font-medium text-gray-700">Foto</label>
                                <input type="file" accept="image/*" capture="environment"
                                       @change="onProductPhotoChange($event, idx)"
                                       class="mt-1 block w-full text-sm text-gray-600 file:mr-2 file:py-2 file:px-3 file:rounded-md file:border-0 file:bg-orange-50 file:text-orange-700 file:text-xs file:font-semibold">
                                <template x-if="product.photoPreview">
                                    <img :src="product.photoPreview" alt="Produto" class="mt-2 rounded-md max-h-32 w-auto border">
                                </template>
                            </div>
                        </div>
                    </template>
                </section>

                <template x-if="error">
                    <div class="rounded-lg bg-red-50 border border-red-200 p-3 text-sm text-red-800" x-text="error"></div>
                </template>
            </main>

            {{-- Sticky submit --}}
            <div class="fixed bottom-0 inset-x-0 bg-white border-t border-gray-200 px-4 py-3 pb-[max(env(safe-area-inset-bottom),0.75rem)]">
                <button @click="submit" :disabled="!canSubmit() || submitting"
                        class="w-full bg-orange-600 hover:bg-orange-700 active:bg-orange-800 text-white font-semibold py-3 px-4 rounded-lg disabled:bg-gray-300 transition">
                    <span x-show="!submitting">Salvar fornecedor</span>
                    <span x-show="submitting">Enviando…</span>
                </button>
            </div>
        </div>
    </template>

    {{-- ── Pending list ────────────────────────────────── --}}
    <template x-if="screen === 'pending'">
        <div class="flex-1">
            <header class="sticky top-0 z-10 bg-white border-b border-gray-200 px-4 py-3 flex items-center justify-between gap-3">
                <button @click="screen = 'capture'" class="text-orange-700 font-semibold text-sm">← Voltar</button>
                <h1 class="text-base font-semibold text-gray-900">Cadastros na fila</h1>
                <button @click="runSync" :disabled="syncing || !online"
                        class="text-xs text-orange-700 font-semibold disabled:text-gray-400">
                    <span x-show="!syncing">Sincronizar</span>
                    <span x-show="syncing">…</span>
                </button>
            </header>

            <main class="px-4 py-4 space-y-3 pb-10">
                <template x-if="pendingItems.length === 0">
                    <div class="text-center py-16 text-sm text-gray-500">
                        Nenhum cadastro pendente.
                    </div>
                </template>

                <template x-for="item in pendingItems" :key="item.id">
                    <div class="bg-white rounded-lg border border-gray-200 p-3 space-y-2">
                        <div class="flex items-start justify-between gap-3">
                            <div class="min-w-0 flex-1">
                                <p class="font-semibold text-sm text-gray-900 truncate" x-text="item.payload.company_name"></p>
                                <p class="text-xs text-gray-500 truncate" x-text="item.payload.contact_name + ' · ' + (item.payload.products?.length || 0) + ' produto(s)'"></p>
                            </div>
                            <span class="text-[10px] font-semibold px-2 py-1 rounded-full whitespace-nowrap"
                                  :class="statusClass(item.status)" x-text="statusLabel(item.status)"></span>
                        </div>
                        <template x-if="item.lastError">
                            <p class="text-xs text-red-700 bg-red-50 border border-red-100 rounded p-2" x-text="item.lastError"></p>
                        </template>
                        <div class="flex gap-2">
                            <button @click="retryPending(item.id)"
                                    :disabled="syncing || !online"
                                    class="flex-1 text-xs font-semibold border border-orange-600 text-orange-700 hover:bg-orange-50 disabled:opacity-40 disabled:cursor-not-allowed py-2 rounded">
                                Tentar de novo
                            </button>
                            <button @click="deletePending(item.id)"
                                    class="flex-1 text-xs font-semibold border border-gray-300 text-gray-600 hover:bg-gray-50 py-2 rounded">
                                Descartar
                            </button>
                        </div>
                    </div>
                </template>
            </main>
        </div>
    </template>

    {{-- ── Success ─────────────────────────────────────── --}}
    <template x-if="screen === 'success'">
        <div class="flex-1 flex flex-col items-center justify-center px-6 py-10">
            <div class="w-16 h-16 rounded-full bg-green-100 flex items-center justify-center">
                <svg class="w-8 h-8 text-green-600" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                    <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M5 13l4 4L19 7"></path>
                </svg>
            </div>
            <h2 class="mt-4 text-lg font-semibold text-gray-900">Cadastro salvo no celular</h2>
            <p class="mt-2 text-sm text-gray-600 text-center" x-text="lastResult?.company?.name"></p>
            <p class="text-xs text-gray-500 mt-1" x-text="(lastResult?.product_names?.length || 0) + ' produto(s)'"></p>
            <p class="text-xs text-gray-400 mt-2 text-center px-6" x-text="online ? 'Sincronizando com o servidor…' : 'Será enviado automaticamente quando voltar a conexão.'"></p>
            <button @click="captureAnother"
                    class="mt-8 bg-orange-600 hover:bg-orange-700 text-white font-semibold py-3 px-8 rounded-lg">
                Cadastrar próximo
            </button>
        </div>
    </template>

</div>

<style>[x-cloak] { display: none !important; }</style>
</body>
</html>
