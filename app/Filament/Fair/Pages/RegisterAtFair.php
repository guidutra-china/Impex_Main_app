<?php

namespace App\Filament\Fair\Pages;

use App\Domain\Catalog\Enums\ProductStatus;
use App\Domain\Catalog\Models\Category;
use App\Domain\Catalog\Models\CompanyProduct;
use App\Domain\Catalog\Models\Product;
use App\Domain\CRM\Enums\CompanyRole;
use App\Domain\CRM\Enums\CompanyStatus;
use App\Domain\CRM\Models\Company;
use App\Domain\CRM\Models\CompanyRoleAssignment;
use App\Domain\CRM\Models\Contact;
use App\Domain\TradeFairs\Models\TradeFair;
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
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Blade;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\HtmlString;
use Illuminate\Support\Str;
use UnitEnum;

class RegisterAtFair extends Page implements HasForms
{
    use InteractsWithForms;

    protected static BackedEnum|string|null $navigationIcon = 'heroicon-o-plus-circle';

    protected static ?string $navigationLabel = 'New Registration';

    protected static ?int $navigationSort = 1;

    protected static string $view = 'filament.fair.pages.register-at-fair';

    public ?array $data = [];

    public function getTitle(): string
    {
        return 'Register at Fair';
    }

    public function mount(): void
    {
        $activeFairId = session('active_trade_fair_id');

        $this->form->fill([
            'trade_fair_id' => $activeFairId,
            'address_country' => 'CN',
            'products' => [
                ['currency_code' => 'USD'],
            ],
            'email_subject' => '',
            'email_message' => '',
        ]);
    }

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
                            Send Inquiry & Save
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
                            ])
                            ->createOptionUsing(function (array $data): int {
                                $category = Category::create([
                                    'name' => $data['name'],
                                    'slug' => Str::slug($data['name']),
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
                                    ])
                                    ->createOptionUsing(function (array $data): int {
                                        $category = Category::create([
                                            'name' => $data['name'],
                                            'slug' => Str::slug($data['name']),
                                            'is_active' => true,
                                        ]);

                                        return $category->id;
                                    }),

                                TextInput::make('unit_price')
                                    ->label('Estimated Price')
                                    ->numeric()
                                    ->prefix('$')
                                    ->placeholder('e.g. 5.50')
                                    ->helperText('Approximate unit price in USD'),

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

                                FileUpload::make('photo')
                                    ->label('Product Photo')
                                    ->image()
                                    ->directory('fair-products')
                                    ->disk('public')
                                    ->maxSize(5120)
                                    ->imageEditor()
                                    ->extraInputAttributes([
                                        'accept' => 'image/*',
                                        'capture' => 'environment',
                                    ])
                                    ->columnSpanFull(),
                            ])
                            ->columns(1)
                            ->defaultItems(1)
                            ->addActionLabel('+ Add Another Product')
                            ->reorderable(false)
                            ->collapsible()
                            ->itemLabel(fn (array $state): ?string => $state['name'] ?? 'New Product'),
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
                    ->description('Send a simple inquiry email to the supplier requesting product information and pricing.')
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
                            ->placeholder('Add any additional message to the supplier...')
                            ->rows(4)
                            ->helperText('Optional. A standard inquiry template will be used if left blank.'),
                    ]),
            ]);
    }

    // ─── Submit ──────────────────────────────────────────────────────

    public function submit(): void
    {
        $this->validate();

        try {
            DB::transaction(function () {
                // 1. Create Company
                $company = Company::create([
                    'name' => $this->data['company_name'],
                    'address_city' => $this->data['address_city'],
                    'address_country' => $this->data['address_country'],
                    'status' => CompanyStatus::PROSPECT,
                    'notes' => $this->data['company_notes'] ?? null,
                    'trade_fair_id' => $this->data['trade_fair_id'],
                ]);

                // 2. Assign supplier role
                CompanyRoleAssignment::create([
                    'company_id' => $company->id,
                    'role' => CompanyRole::SUPPLIER,
                ]);

                // 3. Attach categories
                $categoryIds = $this->data['company_categories'] ?? [];
                if (! empty($categoryIds)) {
                    $company->categories()->attach($categoryIds);
                }

                // 4. Create primary contact
                $contact = Contact::create([
                    'company_id' => $company->id,
                    'name' => $this->data['contact_name'],
                    'email' => $this->data['contact_email'],
                    'phone' => $this->data['contact_phone'] ?? null,
                    'wechat' => $this->data['contact_wechat'] ?? null,
                    'is_primary' => true,
                ]);

                // 5. Create products and link to supplier
                $productNames = [];
                foreach ($this->data['products'] ?? [] as $productData) {
                    if (empty($productData['name'])) {
                        continue;
                    }

                    // Create the product
                    $product = Product::create([
                        'name' => $productData['name'],
                        'category_id' => $productData['category_id'],
                        'description' => $productData['description'] ?? null,
                        'status' => ProductStatus::DRAFT,
                    ]);

                    // Convert price to minor units (cents)
                    $unitPrice = 0;
                    if (! empty($productData['unit_price'])) {
                        $unitPrice = (int) round((float) $productData['unit_price'] * 100);
                    }

                    // Create the company_product pivot
                    CompanyProduct::create([
                        'company_id' => $company->id,
                        'product_id' => $product->id,
                        'role' => 'supplier',
                        'unit_price' => $unitPrice,
                        'currency_code' => $productData['currency_code'] ?? 'USD',
                        'moq' => $productData['moq'] ?? null,
                        'avatar_path' => $productData['photo'] ?? null,
                        'avatar_disk' => 'public',
                    ]);

                    $productNames[] = $productData['name'];
                }

                // 6. Send inquiry email
                $recipientEmail = $this->data['contact_email'];
                $recipientName = $this->data['contact_name'];
                $companyName = $this->data['company_name'];

                $tradeFair = TradeFair::find($this->data['trade_fair_id']);
                $tradeFairName = $tradeFair?->name ?? 'Trade Fair';

                $subject = $this->data['email_subject'];
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
            });

            Notification::make()
                ->title('Registration Complete!')
                ->body('Supplier, products, and inquiry email have been saved and sent successfully.')
                ->success()
                ->send();

            // Redirect to dashboard
            $this->redirect(FairDashboard::getUrl());

        } catch (\Throwable $e) {
            report($e);

            Notification::make()
                ->title('Registration Failed')
                ->body('Error: ' . $e->getMessage())
                ->danger()
                ->send();
        }
    }
}
