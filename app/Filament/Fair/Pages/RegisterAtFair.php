<?php

namespace App\Filament\Fair\Pages;

use App\Domain\Catalog\Models\Category;
use App\Domain\CRM\Enums\CompanyRole;
use App\Domain\CRM\Models\Company;
use App\Domain\TradeFairs\Actions\RegisterFairSupplierAction;
use App\Domain\TradeFairs\DataTransferObjects\FairProductData;
use App\Domain\TradeFairs\DataTransferObjects\FairSupplierData;
use App\Domain\TradeFairs\Models\TradeFair;
use App\Domain\Users\Enums\UserType;
use App\Mail\FairInquiryMail;
use BackedEnum;
use Filament\Forms\Components\DatePicker;
use Filament\Forms\Components\FileUpload;
use Filament\Forms\Components\Placeholder;
use Filament\Forms\Components\Repeater;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\Textarea;
use Filament\Forms\Components\TextInput;
use Filament\Forms\Concerns\InteractsWithForms;
use Filament\Forms\Contracts\HasForms;
use Filament\Notifications\Notification;
use Filament\Pages\Page;
use Filament\Schemas\Components\Section;
use Filament\Schemas\Components\Wizard;
use Filament\Schemas\Components\Wizard\Step;
use Filament\Schemas\Schema;
use Illuminate\Support\Facades\Blade;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\HtmlString;
use Illuminate\Support\Str;

class RegisterAtFair extends Page implements HasForms
{
    use InteractsWithForms;

    protected static BackedEnum|string|null $navigationIcon = 'heroicon-o-plus-circle';

    protected static ?string $navigationLabel = 'New Registration';

    protected static ?int $navigationSort = 1;

    protected string $view = 'filament.fair.pages.register-at-fair';

    public ?array $data = [];

    /** Whether the business card scan is currently in progress */
    public bool $scanning = false;

    /** Status message shown below the business card upload */
    public string $scanStatus = '';

    // ─── Access Control ──────────────────────────────────────────────

    public static function canAccess(): bool
    {
        $user = auth()->user();

        if (! $user) {
            return false;
        }

        // Only internal users may access the fair panel
        return $user->type === UserType::INTERNAL && $user->status === 'active';
    }

    // ─── Mount ───────────────────────────────────────────────────────

    public function getTitle(): string
    {
        return 'Register at Fair';
    }

    public function mount(): void
    {
        // Redirect non-internal users immediately
        if (! static::canAccess()) {
            $this->redirect('/');

            return;
        }

        $activeFairId = session('active_trade_fair_id');

        $this->form->fill([
            'trade_fair_id' => $activeFairId,
            'existing_company_id' => null,
            'use_existing_company' => false,
            'address_country' => 'CN',
            'products' => [
                ['currency_code' => 'USD'],
            ],
            'email_subject' => '',
            'email_message' => '',
        ]);
    }

    // ─── Business Card Scanner ───────────────────────────────────────

    /**
     * Called by the company photos FileUpload afterStateUpdated. Sends the FIRST
     * photo (treated as the cover / business card) to OpenAI GPT-4 Vision and
     * auto-fills the supplier form fields.
     *
     * The FileUpload stores state in $this->data['company_photos'] as an array
     * keyed by UUID (Filepond default). We take the first entry, read the file
     * from the 'public' disk, base64-encode it, and send it to the OpenAI API.
     */
    public function scanBusinessCard(): void
    {
        $this->scanning = true;
        $this->scanStatus = 'Scanning business card...';

        try {
            // ── 1. Resolve the TemporaryUploadedFile object ──────────────
            //
            // Filament FileUpload stores state in $this->data as an associative
            // array keyed by UUID: ['<uuid>' => TemporaryUploadedFile, ...]
            // We use reset() to get the first (and only) TemporaryUploadedFile.
            // Then getRealPath() returns the absolute filesystem path to the
            // file in Livewire's temporary upload directory.
            //
            // Reference: https://www.clearhat.org/post/get-filename-and-path-after-
            //            a-file-is-uploaded-using-Laravel-Filament-afterStateUpdated
            $rawState = $this->data['company_photos'] ?? null;

            if (empty($rawState) || ! is_array($rawState)) {
                $this->scanStatus = '';
                $this->scanning = false;

                return;
            }

            $tempFile = reset($rawState);

            // $tempFile may be a TemporaryUploadedFile object or a stored path string
            // (if the form was re-hydrated after a previous submit)
            if ($tempFile instanceof \Livewire\Features\SupportFileUploads\TemporaryUploadedFile) {
                $realPath = $tempFile->getRealPath();
                $mimeType = $tempFile->getMimeType() ?: 'image/jpeg';
            } elseif (is_string($tempFile) && $tempFile !== '') {
                // Already stored — read from public disk
                $realPath = Storage::disk('public')->path($tempFile);
                $mimeType = mime_content_type($realPath) ?: 'image/jpeg';
            } else {
                Log::warning('[FairPanel] Unexpected company_photos state type', [
                    'type' => gettype($tempFile),
                    'value' => is_object($tempFile) ? get_class($tempFile) : $tempFile,
                ]);
                $this->scanStatus = 'Could not read the uploaded image. Please try again.';
                $this->scanning = false;

                return;
            }

            if (! $realPath || ! file_exists($realPath)) {
                $this->scanStatus = 'Could not read the uploaded image. Please try again.';
                $this->scanning = false;

                return;
            }

            // ── 2. Read the file and base64-encode it ────────────────
            $imageData = base64_encode(file_get_contents($realPath));

            // ── 3. Call OpenAI GPT-4.1-mini with vision ──────────────
            $apiKey = config('services.openai.key');
            if (! $apiKey) {
                $this->scanStatus = 'OpenAI API key is not configured.';
                $this->scanning = false;

                return;
            }

            $prompt = <<<'PROMPT'
You are a business card data extraction assistant.
Analyse the provided business card image and extract the following fields.
Return ONLY a valid JSON object with these exact keys (use null for missing fields):
{
  "company_name": "...",
  "contact_name": "...",
  "email": "...",
  "phone": "...",
  "wechat": "...",
  "city": "...",
  "country_code": "CN",
  "website": "..."
}
Rules:
- country_code must be a 2-letter ISO 3166-1 alpha-2 code (e.g. CN, US, DE, VN)
- If the card is in Chinese, translate company and contact names to English if possible, but keep the original in parentheses
- Extract WeChat ID from any field labelled 微信, WeChat, or similar
- Do not include any explanation or markdown — return only the raw JSON object
PROMPT;

            $response = Http::withToken($apiKey)
                ->timeout(30)
                ->post('https://api.openai.com/v1/chat/completions', [
                    'model' => 'gpt-4.1-mini',
                    'max_tokens' => 512,
                    'messages' => [
                        [
                            'role' => 'user',
                            'content' => [
                                [
                                    'type' => 'text',
                                    'text' => $prompt,
                                ],
                                [
                                    'type' => 'image_url',
                                    'image_url' => [
                                        'url' => 'data:'.$mimeType.';base64,'.$imageData,
                                        'detail' => 'high',
                                    ],
                                ],
                            ],
                        ],
                    ],
                ]);

            if (! $response->successful()) {
                Log::warning('[FairPanel] OpenAI scan failed', [
                    'status' => $response->status(),
                    'body' => $response->body(),
                ]);
                $this->scanStatus = 'OpenAI API error (HTTP '.$response->status().'). Please fill in the form manually.';
                $this->scanning = false;

                return;
            }

            // ── 4. Parse the JSON response ───────────────────────────
            $content = $response->json('choices.0.message.content', '');

            // Strip markdown code fences if present
            $content = preg_replace('/^```(?:json)?\s*/i', '', trim($content));
            $content = preg_replace('/\s*```$/', '', $content);

            $extracted = json_decode($content, true);

            if (! is_array($extracted)) {
                Log::warning('[FairPanel] OpenAI returned non-JSON', ['content' => $content]);
                $this->scanStatus = 'Could not parse the card data. Please fill in the form manually.';
                $this->scanning = false;

                return;
            }

            // ── 5. Auto-fill the supplier form fields ────────────────
            $filled = [];

            if (! empty($extracted['company_name'])) {
                $this->data['company_name'] = $extracted['company_name'];
                $filled[] = 'company name';
            }
            if (! empty($extracted['contact_name'])) {
                $this->data['contact_name'] = $extracted['contact_name'];
                $filled[] = 'contact name';
            }
            if (! empty($extracted['email'])) {
                $this->data['contact_email'] = $extracted['email'];
                $filled[] = 'email';
            }
            if (! empty($extracted['phone'])) {
                $this->data['contact_phone'] = $extracted['phone'];
                $filled[] = 'phone';
            }
            if (! empty($extracted['wechat'])) {
                $this->data['contact_wechat'] = $extracted['wechat'];
                $filled[] = 'WeChat';
            }
            if (! empty($extracted['city'])) {
                $this->data['address_city'] = $extracted['city'];
                $filled[] = 'city';
            }
            if (! empty($extracted['country_code'])) {
                // Ensure it's a valid 2-letter code
                $code = strtoupper(substr(preg_replace('/[^A-Za-z]/', '', $extracted['country_code']), 0, 2));
                if (strlen($code) === 2) {
                    $this->data['address_country'] = $code;
                    $filled[] = 'country';
                }
            }
            if (! empty($extracted['website'])) {
                // Store website in notes if no dedicated field exists
                $existing = $this->data['company_notes'] ?? '';
                $websiteNote = 'Website: '.$extracted['website'];
                $this->data['company_notes'] = $existing
                    ? $existing."\n".$websiteNote
                    : $websiteNote;
                $filled[] = 'website (in notes)';
            }

            if (empty($filled)) {
                $this->scanStatus = 'Scan complete but no data could be extracted. Please fill in the form manually.';
            } else {
                $this->scanStatus = 'Scan complete. Auto-filled: '.implode(', ', $filled).'. Please review and adjust.';
            }

            Log::info('[FairPanel] Business card scanned successfully', [
                'extracted' => $extracted,
                'filled' => $filled,
            ]);

        } catch (\Throwable $e) {
            Log::error('[FairPanel] Business card scan exception', [
                'error' => $e->getMessage(),
                'trace' => $e->getTraceAsString(),
            ]);
            $this->scanStatus = 'Scan failed: '.$e->getMessage().'. Please fill in the form manually.';
        } finally {
            $this->scanning = false;
        }
    }

    // ─── Form ────────────────────────────────────────────────────────

    public function form(Schema $schema): Schema
    {
        return $schema
            ->schema([
                Wizard::make([
                    $this->fairStep(),
                    $this->supplierStep(),
                    $this->productsStep(),
                    $this->emailStep(),
                ])
                    ->submitAction(new HtmlString(Blade::render(<<<'BLADE'
                        <x-filament::button
                            type="submit"
                            size="lg"
                            icon="heroicon-o-paper-airplane"
                            class="w-full"
                        >
                            Save & Send Inquiry
                        </x-filament::button>
                    BLADE)))
                    ->persistStepInQueryString('step')
                    ->columnSpanFull(),
            ])
            ->statePath('data');
    }

    // ─── Step 1: Trade Fair ──────────────────────────────────────────

    protected function fairStep(): Step
    {
        return Step::make('Trade Fair')
            ->icon('heroicon-o-flag')
            ->description('Select or create the exhibition')
            ->schema([
                Section::make('Exhibition / Trade Fair')
                    ->description('Select an existing fair or create a new one. This will apply to all suppliers registered in this session.')
                    ->schema([
                        Select::make('trade_fair_id')
                            ->label('Trade Fair')
                            ->options(
                                fn () => TradeFair::query()
                                    ->orderByDesc('created_at')
                                    ->limit(50)
                                    ->get()
                                    ->mapWithKeys(fn (TradeFair $fair) => [
                                        $fair->id => $fair->display_name,
                                    ])
                            )
                            ->searchable()
                            ->required()
                            ->createOptionForm([
                                TextInput::make('name')
                                    ->label('Fair Name')
                                    ->required()
                                    ->maxLength(255)
                                    ->placeholder('e.g. Canton Fair 136th'),
                                TextInput::make('location')
                                    ->label('Location')
                                    ->maxLength(255)
                                    ->placeholder('e.g. Guangzhou, China'),
                                DatePicker::make('start_date')
                                    ->label('Start Date'),
                                DatePicker::make('end_date')
                                    ->label('End Date'),
                            ])
                            ->createOptionUsing(function (array $data): int {
                                $fair = TradeFair::create([
                                    'name' => $data['name'],
                                    'location' => $data['location'] ?? null,
                                    'start_date' => $data['start_date'] ?? null,
                                    'end_date' => $data['end_date'] ?? null,
                                    'created_by' => auth()->id(),
                                ]);

                                return $fair->id;
                            })
                            ->afterStateUpdated(function ($state) {
                                if ($state) {
                                    session(['active_trade_fair_id' => $state]);
                                }
                            })
                            ->live()
                            ->helperText('This fair will be linked to all suppliers you register in this session.'),
                    ]),
            ])
            ->afterValidation(function () {
                $fairId = $this->data['trade_fair_id'] ?? null;
                if ($fairId) {
                    session(['active_trade_fair_id' => $fairId]);
                }
            });
    }

    // ─── Step 2: Supplier ────────────────────────────────────────────

    protected function supplierStep(): Step
    {
        return Step::make('Supplier')
            ->icon('heroicon-o-building-office')
            ->description('Company & contact details')
            ->schema([

                // ── Company Photos + Card Scanner ───────────────────
                // FileUpload is a top-level field (NOT inside a Repeater) so that
                // Filepond state is preserved. The first photo is treated as the
                // primary/cover and is sent to OpenAI GPT-4 Vision (afterStateUpdated
                // fires scanBusinessCard()) to auto-fill the form fields below.
                Section::make('Fotos da empresa')
                    ->description('Opcional. A primeira foto é a capa e é escaneada por IA para preencher o formulário (ex.: cartão de visitas). Você pode adicionar várias fotos.')
                    ->icon('heroicon-o-camera')
                    ->schema([
                        FileUpload::make('company_photos')
                            ->label('Fotos da empresa')
                            ->image()
                            ->multiple()
                            ->reorderable()
                            ->maxFiles(8)
                            ->directory('company-photos')
                            ->disk('public')
                            ->maxSize(8192)
                            ->imageResizeTargetWidth(1200)
                            ->imageResizeTargetHeight(1200)
                            ->imageResizeMode('contain')
                            ->storeFiles(false)
                            ->extraInputAttributes([
                                'accept' => 'image/*',
                                'capture' => 'environment',
                            ])
                            ->helperText('Tire fotos com a câmera ou faça upload. A primeira foto é escaneada para auto-preencher o formulário.')
                            ->live()
                            ->afterStateUpdated(function ($state) {
                                if (! empty($state)) {
                                    $this->scanBusinessCard();
                                }
                            })
                            ->columnSpanFull(),

                        Placeholder::make('scan_status_display')
                            ->label('')
                            ->content(function () {
                                if ($this->scanning) {
                                    return new HtmlString(
                                        '<div class="flex items-center gap-2 rounded-lg bg-blue-50 dark:bg-blue-900/20 border border-blue-200 dark:border-blue-700 p-3 text-sm text-blue-800 dark:text-blue-200">'
                                        .'<svg class="animate-spin h-4 w-4 text-blue-600" xmlns="http://www.w3.org/2000/svg" fill="none" viewBox="0 0 24 24"><circle class="opacity-25" cx="12" cy="12" r="10" stroke="currentColor" stroke-width="4"></circle><path class="opacity-75" fill="currentColor" d="M4 12a8 8 0 018-8V0C5.373 0 0 5.373 0 12h4zm2 5.291A7.962 7.962 0 014 12H0c0 3.042 1.135 5.824 3 7.938l3-2.647z"></path></svg>'
                                        .'<span>Scanning business card with AI... Please wait.</span>'
                                        .'</div>'
                                    );
                                }

                                if (! empty($this->scanStatus)) {
                                    $isError = str_contains($this->scanStatus, 'failed')
                                        || str_contains($this->scanStatus, 'error')
                                        || str_contains($this->scanStatus, 'Could not');

                                    $isSuccess = str_contains($this->scanStatus, 'complete');

                                    if ($isError) {
                                        $colorClass = 'bg-red-50 dark:bg-red-900/20 border-red-200 dark:border-red-700 text-red-800 dark:text-red-200';
                                        $icon = '⚠️';
                                    } elseif ($isSuccess) {
                                        $colorClass = 'bg-green-50 dark:bg-green-900/20 border-green-200 dark:border-green-700 text-green-800 dark:text-green-200';
                                        $icon = '✅';
                                    } else {
                                        $colorClass = 'bg-blue-50 dark:bg-blue-900/20 border-blue-200 dark:border-blue-700 text-blue-800 dark:text-blue-200';
                                        $icon = 'ℹ️';
                                    }

                                    return new HtmlString(
                                        '<div class="rounded-lg border p-3 text-sm '.$colorClass.'">'
                                        .$icon.' '.e($this->scanStatus)
                                        .'</div>'
                                    );
                                }

                                return new HtmlString('');
                            })
                            ->visible(fn () => $this->scanning || ! empty($this->scanStatus)),
                    ])
                    ->collapsible()
                    ->collapsed(false),

                // ── Duplicate Detection ────────────────────────────
                Section::make('Search Existing Suppliers')
                    ->description('Type a company name to check if this supplier already exists before creating a new record.')
                    ->schema([
                        Select::make('existing_company_id')
                            ->label('Search Existing Companies')
                            ->placeholder('Type to search by name...')
                            ->searchable()
                            ->live()
                            ->getSearchResultsUsing(function (string $search): array {
                                return Company::query()
                                    ->where('name', 'like', '%'.$search.'%')
                                    ->where(function ($q) {
                                        // Include companies with supplier role OR companies
                                        // registered via the fair panel (trade_fair_id set)
                                        // to catch records before role assignment completes
                                        $q->whereHas('companyRoles', fn ($r) => $r->where('role', CompanyRole::SUPPLIER->value))
                                            ->orWhereNotNull('trade_fair_id');
                                    })
                                    ->orderBy('name')
                                    ->limit(15)
                                    ->get()
                                    ->mapWithKeys(fn (Company $c) => [
                                        $c->id => $c->name.($c->address_city ? ' — '.$c->address_city : ''),
                                    ])
                                    ->toArray();
                            })
                            ->getOptionLabelUsing(fn ($value): ?string => Company::find($value)?->name)
                            ->afterStateUpdated(function ($state, callable $set) {
                                if ($state) {
                                    $company = Company::with('contacts')->find($state);
                                    if ($company) {
                                        $set('use_existing_company', true);
                                        $set('company_name', $company->name);
                                        $set('address_city', $company->address_city ?? '');
                                        $set('address_country', $company->address_country ?? 'CN');
                                        $set('company_notes', $company->notes ?? '');

                                        $primary = $company->contacts->firstWhere('is_primary', true)
                                            ?? $company->contacts->first();

                                        if ($primary) {
                                            $set('contact_name', $primary->name ?? '');
                                            $set('contact_email', $primary->email ?? '');
                                            $set('contact_phone', $primary->phone ?? '');
                                            $set('contact_wechat', $primary->wechat ?? '');
                                        }
                                    }
                                } else {
                                    $set('use_existing_company', false);
                                }
                            })
                            ->helperText('If found, the form below will be pre-filled. You can still edit the details.'),

                        Placeholder::make('existing_company_notice')
                            ->label('')
                            ->content(function () {
                                $id = $this->data['existing_company_id'] ?? null;
                                if (! $id) {
                                    return new HtmlString('');
                                }

                                $company = Company::find($id);
                                if (! $company) {
                                    return new HtmlString('');
                                }

                                return new HtmlString(
                                    '<div class="rounded-lg bg-amber-50 dark:bg-amber-900/20 border border-amber-200 dark:border-amber-700 p-3 text-sm text-amber-800 dark:text-amber-200">'
                                    .'<strong>⚠ Existing supplier found:</strong> '.e($company->name)
                                    .'. The form has been pre-filled. Submitting will <strong>add new products</strong> to this existing supplier — it will NOT create a duplicate.'
                                    .'</div>'
                                );
                            })
                            ->visible(fn () => ! empty($this->data['existing_company_id'])),
                    ]),

                // ── Company Information ──────────────────────────────
                Section::make('Company Information')
                    ->schema([
                        TextInput::make('company_name')
                            ->label('Company Name')
                            ->required()
                            ->maxLength(255)
                            ->placeholder('e.g. Shenzhen ABC Trading Co., Ltd.'),

                        Select::make('company_categories')
                            ->label('Categories')
                            ->multiple()
                            ->options(
                                fn () => Category::query()
                                    ->where('is_active', true)
                                    ->orderBy('name')
                                    ->pluck('name', 'id')
                            )
                            ->searchable()
                            ->createOptionForm([
                                TextInput::make('name')
                                    ->label('Category Name')
                                    ->required()
                                    ->maxLength(255),
                                TextInput::make('sku_prefix')
                                    ->label('SKU Prefix')
                                    ->maxLength(10)
                                    ->placeholder('e.g. LED, OFC')
                                    ->helperText('Optional. Used for product SKU generation.'),
                                Select::make('parent_id')
                                    ->label('Parent Category')
                                    ->options(
                                        fn () => Category::query()
                                            ->where('is_active', true)
                                            ->orderBy('name')
                                            ->get()
                                            ->mapWithKeys(fn (Category $cat) => [$cat->id => $cat->full_path])
                                    )
                                    ->searchable()
                                    ->placeholder('None (root category)')
                                    ->helperText('Optional. Link this category under an existing parent.'),
                            ])
                            ->createOptionUsing(function (array $data): int {
                                $category = Category::create([
                                    'name' => $data['name'],
                                    'slug' => Str::slug($data['name']),
                                    'sku_prefix' => $data['sku_prefix'] ?? null,
                                    'parent_id' => $data['parent_id'] ?? null,
                                    'is_active' => true,
                                ]);

                                return $category->id;
                            })
                            ->helperText('Select or create product categories for this supplier.'),

                        TextInput::make('address_city')
                            ->label('City')
                            ->required()
                            ->maxLength(255)
                            ->placeholder('e.g. Shenzhen'),

                        TextInput::make('address_country')
                            ->label('Country Code')
                            ->required()
                            ->maxLength(2)
                            ->placeholder('e.g. CN')
                            ->helperText('ISO 3166-1 alpha-2 code (CN, VN, IN, etc.)'),

                        Textarea::make('company_notes')
                            ->label('Notes')
                            ->placeholder('Quick observations about the supplier...')
                            ->rows(2),
                    ])
                    ->columns(1),

                // ── Primary Contact ──────────────────────────────────
                Section::make('Primary Contact')
                    ->schema([
                        TextInput::make('contact_name')
                            ->label('Contact Name')
                            ->required()
                            ->maxLength(255)
                            ->placeholder('e.g. Wang Wei'),

                        TextInput::make('contact_email')
                            ->label('Email')
                            ->email()
                            ->required()
                            ->maxLength(255)
                            ->placeholder('e.g. wang@supplier.com'),

                        TextInput::make('contact_phone')
                            ->label('Phone')
                            ->tel()
                            ->maxLength(50)
                            ->placeholder('e.g. +86 138 0000 0000'),

                        TextInput::make('contact_wechat')
                            ->label('WeChat ID')
                            ->maxLength(255)
                            ->placeholder('e.g. wangwei_trade'),
                    ])
                    ->columns(1),
            ]);
    }

    // ─── Step 3: Products ────────────────────────────────────────────

    protected function productsStep(): Step
    {
        return Step::make('Products')
            ->icon('heroicon-o-cube')
            ->description('Products seen at the fair')
            ->schema([
                Section::make('Products')
                    ->description('Add the products you saw at this supplier\'s booth.')
                    ->schema([
                        Repeater::make('products')
                            ->label('')
                            ->schema([
                                TextInput::make('name')
                                    ->label('Product Name')
                                    ->required()
                                    ->maxLength(255)
                                    ->placeholder('e.g. Stainless Steel Water Bottle'),

                                Select::make('category_id')
                                    ->label('Category')
                                    ->options(
                                        fn () => Category::query()
                                            ->where('is_active', true)
                                            ->orderBy('name')
                                            ->pluck('name', 'id')
                                    )
                                    ->searchable()
                                    ->required()
                                    ->createOptionForm([
                                        TextInput::make('name')
                                            ->label('Category Name')
                                            ->required()
                                            ->maxLength(255),
                                        TextInput::make('sku_prefix')
                                            ->label('SKU Prefix')
                                            ->maxLength(10)
                                            ->placeholder('e.g. LED, OFC')
                                            ->helperText('Optional. Used for product SKU generation.'),
                                        Select::make('parent_id')
                                            ->label('Parent Category')
                                            ->options(
                                                fn () => Category::query()
                                                    ->where('is_active', true)
                                                    ->orderBy('name')
                                                    ->get()
                                                    ->mapWithKeys(fn (Category $cat) => [$cat->id => $cat->full_path])
                                            )
                                            ->searchable()
                                            ->placeholder('None (root category)')
                                            ->helperText('Optional. Link this category under an existing parent.'),
                                    ])
                                    ->createOptionUsing(function (array $data): int {
                                        $category = Category::create([
                                            'name' => $data['name'],
                                            'slug' => Str::slug($data['name']),
                                            'sku_prefix' => $data['sku_prefix'] ?? null,
                                            'parent_id' => $data['parent_id'] ?? null,
                                            'is_active' => true,
                                        ]);

                                        return $category->id;
                                    }),

                                TextInput::make('unit_price')
                                    ->label('Estimated Price')
                                    ->numeric()
                                    ->prefix('$')
                                    ->placeholder('e.g. 5.50')
                                    ->helperText('Approximate unit price'),

                                Select::make('currency_code')
                                    ->label('Currency')
                                    ->options([
                                        'USD' => 'USD',
                                        'CNY' => 'CNY',
                                        'EUR' => 'EUR',
                                        'GBP' => 'GBP',
                                    ])
                                    ->default('USD'),

                                TextInput::make('moq')
                                    ->label('MOQ')
                                    ->numeric()
                                    ->integer()
                                    ->placeholder('e.g. 500')
                                    ->helperText('Minimum Order Quantity'),

                                TextInput::make('description')
                                    ->label('Notes / Description')
                                    ->maxLength(500)
                                    ->placeholder('Material, size, color, etc.'),
                            ])
                            ->columns(1)
                            ->defaultItems(1)
                            ->addActionLabel('+ Add Another Product')
                            ->reorderable(false)
                            ->collapsible()
                            ->itemLabel(fn (array $state): ?string => $state['name'] ?? 'New Product'),
                    ]),

                // ── Product Photos ───────────────────────────────────
                // FileUpload is NOT supported inside a Repeater in Filament v4
                // (confirmed by Filament maintainer, issue #13636, Dec 2024).
                // Instead, we use a fixed set of top-level FileUpload fields.
                // The user adds up to 5 products; each gets its own photo slot.
                // The key 'product_photo_0' ... 'product_photo_4' maps to the
                // products array index in submit().
                Section::make('Product Photos')
                    ->description('Optional. Take one or more photos for each product using your phone camera (up to 8 each). Match the photos to the product number above. The first photo is the primary/cover image.')
                    ->schema([
                        FileUpload::make('product_photo_0')
                            ->label('Photos — Product 1')
                            ->image()
                            ->multiple()
                            ->reorderable()
                            ->maxFiles(8)
                            ->directory('fair-products')
                            ->disk('public')
                            ->maxSize(5120)
                            ->imageResizeTargetWidth(1200)
                            ->imageResizeTargetHeight(1200)
                            ->imageResizeMode('contain')
                            ->storeFiles(false)
                            ->extraInputAttributes(['accept' => 'image/*', 'capture' => 'environment'])
                            ->visible(fn () => count($this->data['products'] ?? []) >= 1),

                        FileUpload::make('product_photo_1')
                            ->label('Photos — Product 2')
                            ->image()
                            ->multiple()
                            ->reorderable()
                            ->maxFiles(8)
                            ->directory('fair-products')
                            ->disk('public')
                            ->maxSize(5120)
                            ->imageResizeTargetWidth(1200)
                            ->imageResizeTargetHeight(1200)
                            ->imageResizeMode('contain')
                            ->storeFiles(false)
                            ->extraInputAttributes(['accept' => 'image/*', 'capture' => 'environment'])
                            ->visible(fn () => count($this->data['products'] ?? []) >= 2),

                        FileUpload::make('product_photo_2')
                            ->label('Photos — Product 3')
                            ->image()
                            ->multiple()
                            ->reorderable()
                            ->maxFiles(8)
                            ->directory('fair-products')
                            ->disk('public')
                            ->maxSize(5120)
                            ->imageResizeTargetWidth(1200)
                            ->imageResizeTargetHeight(1200)
                            ->imageResizeMode('contain')
                            ->storeFiles(false)
                            ->extraInputAttributes(['accept' => 'image/*', 'capture' => 'environment'])
                            ->visible(fn () => count($this->data['products'] ?? []) >= 3),

                        FileUpload::make('product_photo_3')
                            ->label('Photos — Product 4')
                            ->image()
                            ->multiple()
                            ->reorderable()
                            ->maxFiles(8)
                            ->directory('fair-products')
                            ->disk('public')
                            ->maxSize(5120)
                            ->imageResizeTargetWidth(1200)
                            ->imageResizeTargetHeight(1200)
                            ->imageResizeMode('contain')
                            ->storeFiles(false)
                            ->extraInputAttributes(['accept' => 'image/*', 'capture' => 'environment'])
                            ->visible(fn () => count($this->data['products'] ?? []) >= 4),

                        FileUpload::make('product_photo_4')
                            ->label('Photos — Product 5')
                            ->image()
                            ->multiple()
                            ->reorderable()
                            ->maxFiles(8)
                            ->directory('fair-products')
                            ->disk('public')
                            ->maxSize(5120)
                            ->imageResizeTargetWidth(1200)
                            ->imageResizeTargetHeight(1200)
                            ->imageResizeMode('contain')
                            ->storeFiles(false)
                            ->extraInputAttributes(['accept' => 'image/*', 'capture' => 'environment'])
                            ->visible(fn () => count($this->data['products'] ?? []) >= 5),
                    ]),
            ]);
    }

    // ─── Step 4: Send Email ──────────────────────────────────────────

    protected function emailStep(): Step
    {
        return Step::make('Send Inquiry')
            ->icon('heroicon-o-envelope')
            ->description('Email the supplier')
            ->schema([
                Section::make('Inquiry Email')
                    ->description('A simple inquiry email will be sent to the supplier after saving. If email fails, the registration is still saved.')
                    ->schema([
                        Placeholder::make('email_preview_to')
                            ->label('To')
                            ->content(fn () => $this->data['contact_email'] ?? '—'),

                        Placeholder::make('email_preview_company')
                            ->label('Supplier')
                            ->content(fn () => $this->data['company_name'] ?? '—'),

                        Placeholder::make('email_preview_products')
                            ->label('Products')
                            ->content(function () {
                                $products = $this->data['products'] ?? [];
                                $names = collect($products)
                                    ->pluck('name')
                                    ->filter()
                                    ->implode(', ');

                                return $names ?: '—';
                            }),

                        TextInput::make('email_subject')
                            ->label('Email Subject')
                            ->default('')
                            ->placeholder('Leave blank for auto-generated subject')
                            ->helperText('Optional. If left blank, a default subject will be used.'),

                        Textarea::make('email_message')
                            ->label('Custom Message')
                            ->default(function () {
                                $settings = app(\App\Domain\Settings\DataTransferObjects\CompanySettings::class);
                                $template = $settings->email_default_message_fair_inquiry;

                                if ($template === null || $template === '') {
                                    return '';
                                }

                                $tradeFair = ! empty($this->data['trade_fair_id'])
                                    ? TradeFair::find($this->data['trade_fair_id'])
                                    : null;

                                $productNames = collect($this->data['products'] ?? [])
                                    ->pluck('name')
                                    ->filter()
                                    ->implode(', ');

                                $context = [
                                    'recipient_name' => $this->data['contact_name'] ?? '',
                                    'company_name' => $this->data['company_name'] ?? '',
                                    'trade_fair_name' => $tradeFair?->name ?? '',
                                    'product_names' => $productNames,
                                ];

                                return app(\App\Domain\Infrastructure\Services\EmailMessagePlaceholderResolver::class)
                                    ->resolve($template, $context);
                            })
                            ->placeholder('Add any additional message to the supplier...')
                            ->rows(6)
                            ->helperText('Available placeholders (already resolved above): {recipient_name}, {company_name}, {trade_fair_name}, {product_names}'),
                    ]),
            ]);
    }

    // ─── Submit ──────────────────────────────────────────────────────

    /**
     * Build the FairSupplierData DTO from the wizard form state, normalising
     * Filament FileUpload's array-keyed-by-UUID shape into single UploadedFile
     * (or pre-stored path) entries the domain Action understands.
     */
    private function buildFairSupplierData(): FairSupplierData
    {
        $products = [];
        foreach ($this->data['products'] ?? [] as $idx => $productData) {
            if (empty($productData['name'])) {
                continue;
            }

            // Photos live on top-level product_photo_0..4 because FileUpload
            // inside a Repeater is unsupported in Filament v4 (issue #13636).
            $products[] = new FairProductData(
                name: $productData['name'],
                categoryId: (int) $productData['category_id'],
                description: $productData['description'] ?? null,
                unitPrice: filled($productData['unit_price'] ?? null)
                    ? (float) $productData['unit_price']
                    : null,
                currencyCode: $productData['currency_code'] ?? 'USD',
                moq: filled($productData['moq'] ?? null) ? (int) $productData['moq'] : null,
                photos: $this->extractUploadedFiles($this->data['product_photo_'.$idx] ?? null),
            );
        }

        return new FairSupplierData(
            tradeFairId: (int) $this->data['trade_fair_id'],
            existingCompanyId: $this->data['existing_company_id'] ?? null,
            companyName: $this->data['company_name'] ?? '',
            addressCity: $this->data['address_city'] ?? null,
            addressCountry: $this->data['address_country'] ?? null,
            companyNotes: $this->data['company_notes'] ?? null,
            categoryIds: $this->data['company_categories'] ?? [],
            contactName: $this->data['contact_name'] ?? '',
            contactEmail: $this->data['contact_email'] ?? null,
            contactPhone: $this->data['contact_phone'] ?? null,
            contactWechat: $this->data['contact_wechat'] ?? null,
            companyPhotos: $this->extractUploadedFiles($this->data['company_photos'] ?? null),
            products: $products,
        );
    }

    /**
     * Extract uploaded files from a Filament FileUpload state. A multiple FileUpload stores
     * its state as ['<uuid>' => TemporaryUploadedFile|string, ...]. Returns an
     * ordered array of UploadedFile|string entries the domain Action understands.
     *
     * @return array<int, \Symfony\Component\HttpFoundation\File\UploadedFile|string>
     */
    private function extractUploadedFiles(mixed $raw): array
    {
        if (empty($raw)) {
            return [];
        }

        if (is_string($raw)) {
            return [$raw];
        }

        if (! is_array($raw)) {
            return [];
        }

        $files = [];
        foreach ($raw as $entry) {
            if ($entry instanceof \Symfony\Component\HttpFoundation\File\UploadedFile) {
                $files[] = $entry;
            } elseif (is_string($entry) && $entry !== '') {
                $files[] = $entry;
            }
        }

        return $files;
    }

    public function submit(): void
    {
        // ── DIAGNOSTIC: log the full form state at the very start ────────────
        // This logs at 'error' level so it always appears regardless of
        // the LOG_LEVEL environment variable setting.
        Log::error('[FairPanel] submit() called — FULL FORM DATA', [
            'data' => $this->data,
        ]);

        $this->validate();

        $company = null;
        $productNames = [];

        // ── 1. Save supplier + products via domain Action ────────────
        try {
            $supplierData = $this->buildFairSupplierData();

            $result = app(RegisterFairSupplierAction::class)->execute($supplierData);

            $company = $result->company;
            $productNames = $result->productNames;
        } catch (\Throwable $e) {
            report($e);

            Notification::make()
                ->title('Registration Failed')
                ->body('Could not save supplier data: '.$e->getMessage())
                ->danger()
                ->send();

            return; // Stop here — do not attempt email
        }

        // ── 2. Send email OUTSIDE the transaction ────────────────────
        // Registration is already saved. Email failure is non-fatal.
        $emailSent = false;
        $emailError = null;

        try {
            $recipientEmail = $this->data['contact_email'];
            $recipientName = $this->data['contact_name'];
            $companyName = $this->data['company_name'];

            $tradeFair = TradeFair::find($this->data['trade_fair_id']);
            $tradeFairName = $tradeFair?->name ?? 'Trade Fair';

            $subject = $this->data['email_subject'] ?? '';
            if (empty($subject)) {
                $subject = "Product Inquiry — {$companyName} — {$tradeFairName}";
            }

            $customMessage = $this->data['email_message'] ?? '';

            Mail::to($recipientEmail)
                ->send(new FairInquiryMail(
                    recipientName: $recipientName,
                    companyName: $companyName,
                    tradeFairName: $tradeFairName,
                    productNames: $productNames,
                    customMessage: $customMessage,
                    subject: $subject,
                    senderName: auth()->user()->name,
                ));

            $emailSent = true;
        } catch (\Throwable $e) {
            $emailError = $e->getMessage();
            Log::warning('Fair inquiry email failed', [
                'company_id' => $company?->id,
                'company_name' => $this->data['company_name'] ?? null,
                'recipient' => $this->data['contact_email'] ?? null,
                'error' => $emailError,
            ]);
        }

        // ── 3. Notify user of outcome ────────────────────────────────
        if ($emailSent) {
            Notification::make()
                ->title('Registration Complete!')
                ->body('Supplier and products saved. Inquiry email sent successfully.')
                ->success()
                ->send();
        } else {
            Notification::make()
                ->title('Registration Saved — Email Failed')
                ->body(
                    'Supplier and products were saved successfully. '
                    .'However, the inquiry email could not be sent. '
                    .'Error: '.($emailError ?? 'Unknown error')
                    .'. You can resend the email from the admin panel.'
                )
                ->warning()
                ->persistent()
                ->send();
        }

        $this->redirect(FairDashboard::getUrl());
    }
}
