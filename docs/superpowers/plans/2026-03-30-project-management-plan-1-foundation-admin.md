# Project Management — Plan 1: Domain Foundation + Admin Panel

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:subagent-driven-development (recommended) or superpowers:executing-plans to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking.

**Goal:** Build the `ProjectDevelopment` bounded context (models, migrations, enums, actions, budget service) and the full Admin Filament resource with all tabs.

**Architecture:** New DDD bounded context at `app/Domain/ProjectDevelopment/`. Ten Eloquent models with a custom immutable activity log for event sourcing. Admin panel uses Filament v4 `ResourceResource` with tabbed `RelationManager`s, following the same pattern as `CompanyExpenseResource`.

**Tech Stack:** Laravel 12, Filament v4, PHPUnit, `Filament\Notifications\Notification` for in-app alerts.

---

## File Map

### Domain
| File | Responsibility |
|---|---|
| `app/Domain/ProjectDevelopment/Enums/ProjectStatus.php` | Draft\|Active\|OnHold\|Completed\|Cancelled |
| `app/Domain/ProjectDevelopment/Enums/MilestoneStatus.php` | Pending\|InProgress\|WaitingApproval\|Approved\|Rejected\|Skipped |
| `app/Domain/ProjectDevelopment/Enums/ExpenseType.php` | Planned\|Actual |
| `app/Domain/ProjectDevelopment/Enums/ExpenseCategory.php` | Design\|Tooling\|Sample\|Testing\|Logistics\|Other |
| `app/Domain/ProjectDevelopment/Enums/ParticipantSide.php` | Internal\|Client |
| `app/Domain/ProjectDevelopment/Models/Project.php` | Aggregate root |
| `app/Domain/ProjectDevelopment/Models/ProjectTemplate.php` | Reusable milestone template |
| `app/Domain/ProjectDevelopment/Models/MilestoneTemplate.php` | Phase within a template |
| `app/Domain/ProjectDevelopment/Models/ProjectMilestone.php` | Actual phase of a project |
| `app/Domain/ProjectDevelopment/Models/MilestoneTask.php` | Task within a phase |
| `app/Domain/ProjectDevelopment/Models/ProjectExpense.php` | Planned/actual expense |
| `app/Domain/ProjectDevelopment/Models/ProjectFile.php` | File attachment |
| `app/Domain/ProjectDevelopment/Models/ProjectActivity.php` | Immutable event log |
| `app/Domain/ProjectDevelopment/Models/ProjectMessage.php` | Chat message |
| `app/Domain/ProjectDevelopment/Models/ProjectParticipant.php` | Participant pivot |
| `app/Domain/ProjectDevelopment/DataTransferObjects/CreateProjectData.php` | DTO for project creation |
| `app/Domain/ProjectDevelopment/DataTransferObjects/CreateMilestoneData.php` | DTO for milestone creation |
| `app/Domain/ProjectDevelopment/DataTransferObjects/CreateExpenseData.php` | DTO for expense creation |
| `app/Domain/ProjectDevelopment/Actions/CreateProjectAction.php` | Creates project + applies template |
| `app/Domain/ProjectDevelopment/Actions/RecordProjectActivityAction.php` | Writes immutable activity entry |
| `app/Domain/ProjectDevelopment/Actions/SendMilestoneForApprovalAction.php` | Transitions milestone to WaitingApproval |
| `app/Domain/ProjectDevelopment/Actions/ApproveMilestoneAction.php` | Client approves milestone |
| `app/Domain/ProjectDevelopment/Actions/RequestMilestoneRevisionAction.php` | Client requests revision |
| `app/Domain/ProjectDevelopment/Services/ProjectBudgetService.php` | Budget totals, deviations |

### Migrations
| File | Table |
|---|---|
| `database/migrations/2026_03_30_100000_create_project_tables.php` | All 9 project tables in one migration |

### Filament Admin
| File | Responsibility |
|---|---|
| `app/Filament/Resources/Projects/ProjectResource.php` | Resource definition |
| `app/Filament/Resources/Projects/ProjectResource/Pages/ListProjects.php` | List page |
| `app/Filament/Resources/Projects/ProjectResource/Pages/CreateProject.php` | Create page |
| `app/Filament/Resources/Projects/ProjectResource/Pages/ViewProject.php` | View/detail page |
| `app/Filament/Resources/Projects/ProjectResource/Pages/EditProject.php` | Edit page |
| `app/Filament/Resources/Projects/ProjectResource/Schemas/ProjectForm.php` | Create/edit form |
| `app/Filament/Resources/Projects/ProjectResource/Schemas/ProjectInfolist.php` | View infolist |
| `app/Filament/Resources/Projects/ProjectResource/Tables/ProjectsTable.php` | List table |
| `app/Filament/Resources/Projects/ProjectResource/RelationManagers/MilestonesRelationManager.php` | Milestones tab |
| `app/Filament/Resources/Projects/ProjectResource/RelationManagers/ExpensesRelationManager.php` | Gastos tab |
| `app/Filament/Resources/Projects/ProjectResource/RelationManagers/FilesRelationManager.php` | Arquivos tab |
| `app/Filament/Resources/Projects/ProjectResource/RelationManagers/MessagesRelationManager.php` | Mensagens tab |
| `app/Filament/Resources/Projects/ProjectResource/RelationManagers/ParticipantsRelationManager.php` | Participantes tab |
| `app/Filament/Resources/Projects/ProjectResource/RelationManagers/ActivityRelationManager.php` | Atividade tab (read-only) |
| `app/Filament/Resources/Projects/ProjectTemplateResource.php` | Template CRUD |
| `app/Filament/Resources/Projects/ProjectTemplateResource/Pages/ListProjectTemplates.php` | List page |
| `app/Filament/Resources/Projects/ProjectTemplateResource/Pages/CreateProjectTemplate.php` | Create page |
| `app/Filament/Resources/Projects/ProjectTemplateResource/Pages/EditProjectTemplate.php` | Edit page |
| `app/Filament/Resources/Projects/ProjectTemplateResource/RelationManagers/MilestoneTemplatesRelationManager.php` | Milestone phases within template |

### Tests
| File | What it tests |
|---|---|
| `tests/Unit/ProjectDevelopment/ProjectBudgetServiceTest.php` | Budget calculations |
| `tests/Feature/ProjectDevelopment/CreateProjectActionTest.php` | Project creation with template |
| `tests/Feature/ProjectDevelopment/MilestoneLifecycleTest.php` | Approve / request revision / send for approval |

---

## Task 1: Enums

**Files:**
- Create: `app/Domain/ProjectDevelopment/Enums/ProjectStatus.php`
- Create: `app/Domain/ProjectDevelopment/Enums/MilestoneStatus.php`
- Create: `app/Domain/ProjectDevelopment/Enums/ExpenseType.php`
- Create: `app/Domain/ProjectDevelopment/Enums/ExpenseCategory.php`
- Create: `app/Domain/ProjectDevelopment/Enums/ParticipantSide.php`

- [ ] **Step 1: Create ProjectStatus enum**

```php
<?php
// app/Domain/ProjectDevelopment/Enums/ProjectStatus.php
namespace App\Domain\ProjectDevelopment\Enums;

enum ProjectStatus: string
{
    case Draft = 'draft';
    case Active = 'active';
    case OnHold = 'on_hold';
    case Completed = 'completed';
    case Cancelled = 'cancelled';

    public function getLabel(): string
    {
        return match($this) {
            self::Draft     => 'Draft',
            self::Active    => 'Active',
            self::OnHold    => 'On Hold',
            self::Completed => 'Completed',
            self::Cancelled => 'Cancelled',
        };
    }

    public function getColor(): string
    {
        return match($this) {
            self::Draft     => 'gray',
            self::Active    => 'primary',
            self::OnHold    => 'warning',
            self::Completed => 'success',
            self::Cancelled => 'danger',
        };
    }
}
```

- [ ] **Step 2: Create MilestoneStatus enum**

```php
<?php
// app/Domain/ProjectDevelopment/Enums/MilestoneStatus.php
namespace App\Domain\ProjectDevelopment\Enums;

enum MilestoneStatus: string
{
    case Pending         = 'pending';
    case InProgress      = 'in_progress';
    case WaitingApproval = 'waiting_approval';
    case Approved        = 'approved';
    case Rejected        = 'rejected';
    case Skipped         = 'skipped';

    public function getLabel(): string
    {
        return match($this) {
            self::Pending         => 'Pending',
            self::InProgress      => 'In Progress',
            self::WaitingApproval => 'Waiting Approval',
            self::Approved        => 'Approved',
            self::Rejected        => 'Rejected',
            self::Skipped         => 'Skipped',
        };
    }

    public function getColor(): string
    {
        return match($this) {
            self::Pending         => 'gray',
            self::InProgress      => 'primary',
            self::WaitingApproval => 'warning',
            self::Approved        => 'success',
            self::Rejected        => 'danger',
            self::Skipped         => 'gray',
        };
    }
}
```

- [ ] **Step 3: Create remaining enums**

```php
<?php
// app/Domain/ProjectDevelopment/Enums/ExpenseType.php
namespace App\Domain\ProjectDevelopment\Enums;

enum ExpenseType: string
{
    case Planned = 'planned';
    case Actual  = 'actual';

    public function getLabel(): string
    {
        return match($this) {
            self::Planned => 'Planned',
            self::Actual  => 'Actual',
        };
    }
}
```

```php
<?php
// app/Domain/ProjectDevelopment/Enums/ExpenseCategory.php
namespace App\Domain\ProjectDevelopment\Enums;

enum ExpenseCategory: string
{
    case Design    = 'design';
    case Tooling   = 'tooling';
    case Sample    = 'sample';
    case Testing   = 'testing';
    case Logistics = 'logistics';
    case Other     = 'other';

    public function getLabel(): string
    {
        return match($this) {
            self::Design    => 'Design',
            self::Tooling   => 'Tooling',
            self::Sample    => 'Sample',
            self::Testing   => 'Testing',
            self::Logistics => 'Logistics',
            self::Other     => 'Other',
        };
    }
}
```

```php
<?php
// app/Domain/ProjectDevelopment/Enums/ParticipantSide.php
namespace App\Domain\ProjectDevelopment\Enums;

enum ParticipantSide: string
{
    case Internal = 'internal';
    case Client   = 'client';

    public function getLabel(): string
    {
        return match($this) {
            self::Internal => 'Internal (Impex)',
            self::Client   => 'Client',
        };
    }
}
```

- [ ] **Step 4: Commit**

```bash
git add app/Domain/ProjectDevelopment/Enums/
git commit -m "feat: add ProjectDevelopment enums"
```

---

## Task 2: Migration

**Files:**
- Create: `database/migrations/2026_03_30_100000_create_project_tables.php`

- [ ] **Step 1: Create the migration**

```php
<?php
// database/migrations/2026_03_30_100000_create_project_tables.php
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('project_templates', function (Blueprint $table) {
            $table->id();
            $table->string('name');
            $table->text('description')->nullable();
            $table->boolean('is_default')->default(false);
            $table->timestamps();
        });

        Schema::create('milestone_templates', function (Blueprint $table) {
            $table->id();
            $table->foreignId('project_template_id')->constrained()->cascadeOnDelete();
            $table->string('name');
            $table->text('description')->nullable();
            $table->unsignedInteger('order');
            $table->unsignedInteger('estimated_days')->nullable();
            $table->timestamps();
        });

        Schema::create('projects', function (Blueprint $table) {
            $table->id();
            $table->foreignId('company_id')->constrained()->cascadeOnDelete();
            $table->foreignId('product_id')->nullable()->constrained()->nullOnDelete();
            $table->foreignId('project_template_id')->nullable()->constrained('project_templates')->nullOnDelete();
            $table->string('title');
            $table->text('description')->nullable();
            $table->string('status')->default('draft');
            $table->bigInteger('budget')->default(0); // stored as integer x10000 (4 decimal places)
            $table->string('currency', 3)->default('USD');
            $table->date('started_at')->nullable();
            $table->date('deadline_at')->nullable();
            $table->date('completed_at')->nullable();
            $table->foreignId('created_by')->constrained('users');
            $table->timestamps();
            $table->softDeletes();
        });

        Schema::create('project_milestones', function (Blueprint $table) {
            $table->id();
            $table->foreignId('project_id')->constrained()->cascadeOnDelete();
            $table->foreignId('milestone_template_id')->nullable()->constrained()->nullOnDelete();
            $table->string('name');
            $table->text('description')->nullable();
            $table->unsignedInteger('order')->default(0);
            $table->string('status')->default('pending');
            $table->bigInteger('budget')->nullable(); // x10000
            $table->date('due_at')->nullable();
            $table->date('completed_at')->nullable();
            $table->foreignId('approved_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('approved_at')->nullable();
            $table->text('client_notes')->nullable();
            $table->timestamps();
        });

        Schema::create('milestone_tasks', function (Blueprint $table) {
            $table->id();
            $table->foreignId('project_milestone_id')->constrained()->cascadeOnDelete();
            $table->string('title');
            $table->text('description')->nullable();
            $table->string('status')->default('pending');
            $table->foreignId('assigned_to')->nullable()->constrained('users')->nullOnDelete();
            $table->date('due_at')->nullable();
            $table->timestamp('completed_at')->nullable();
            $table->timestamps();
        });

        Schema::create('project_expenses', function (Blueprint $table) {
            $table->id();
            $table->foreignId('project_id')->constrained()->cascadeOnDelete();
            $table->foreignId('project_milestone_id')->nullable()->constrained()->nullOnDelete();
            $table->string('category');
            $table->string('description');
            $table->string('type');
            $table->bigInteger('amount'); // x10000
            $table->string('currency', 3)->default('USD');
            $table->date('date');
            $table->string('receipt_path')->nullable();
            $table->foreignId('created_by')->constrained('users');
            $table->timestamps();
        });

        Schema::create('project_files', function (Blueprint $table) {
            $table->id();
            $table->foreignId('project_id')->constrained()->cascadeOnDelete();
            $table->foreignId('project_milestone_id')->nullable()->constrained()->nullOnDelete();
            $table->string('name');
            $table->string('path');
            $table->string('mime_type')->nullable();
            $table->unsignedInteger('size')->nullable();
            $table->morphs('uploader'); // uploader_id + uploader_type
            $table->text('notes')->nullable();
            $table->timestamps();
        });

        Schema::create('project_activities', function (Blueprint $table) {
            $table->id();
            $table->foreignId('project_id')->constrained()->cascadeOnDelete();
            $table->string('event_type');
            $table->json('payload');
            $table->nullableMorphs('causer'); // causer_id + causer_type
            $table->timestamp('created_at');
            // No updated_at — immutable
        });

        Schema::create('project_messages', function (Blueprint $table) {
            $table->id();
            $table->foreignId('project_id')->constrained()->cascadeOnDelete();
            $table->morphs('sender'); // sender_id + sender_type
            $table->text('body');
            $table->timestamp('read_at')->nullable();
            $table->timestamp('created_at');
            // No updated_at — messages are immutable
        });

        Schema::create('project_participants', function (Blueprint $table) {
            $table->id();
            $table->foreignId('project_id')->constrained()->cascadeOnDelete();
            $table->morphs('participant'); // participant_id + participant_type
            $table->string('side');
            $table->timestamp('created_at');
            $table->unique(['project_id', 'participant_id', 'participant_type']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('project_participants');
        Schema::dropIfExists('project_messages');
        Schema::dropIfExists('project_activities');
        Schema::dropIfExists('project_files');
        Schema::dropIfExists('project_expenses');
        Schema::dropIfExists('milestone_tasks');
        Schema::dropIfExists('project_milestones');
        Schema::dropIfExists('projects');
        Schema::dropIfExists('milestone_templates');
        Schema::dropIfExists('project_templates');
    }
};
```

- [ ] **Step 2: Run the migration**

```bash
php artisan migrate
```

Expected: all 10 tables created with no errors.

- [ ] **Step 3: Commit**

```bash
git add database/migrations/2026_03_30_100000_create_project_tables.php
git commit -m "feat: add project management migrations"
```

---

## Task 3: Domain Models

**Files:**
- Create: all 10 model files in `app/Domain/ProjectDevelopment/Models/`

- [ ] **Step 1: Project model**

```php
<?php
// app/Domain/ProjectDevelopment/Models/Project.php
namespace App\Domain\ProjectDevelopment\Models;

use App\Domain\CRM\Models\Company;
use App\Domain\Catalog\Models\Product;
use App\Domain\ProjectDevelopment\Enums\ProjectStatus;
use App\Models\User;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\SoftDeletes;

class Project extends Model
{
    use SoftDeletes;

    protected $fillable = [
        'company_id', 'product_id', 'project_template_id',
        'title', 'description', 'status',
        'budget', 'currency',
        'started_at', 'deadline_at', 'completed_at',
        'created_by',
    ];

    protected function casts(): array
    {
        return [
            'status'       => ProjectStatus::class,
            'budget'       => 'integer',
            'started_at'   => 'date',
            'deadline_at'  => 'date',
            'completed_at' => 'date',
        ];
    }

    protected static function booted(): void
    {
        static::creating(function (Project $project) {
            if (empty($project->created_by)) {
                $project->created_by = auth()->id();
            }
        });
    }

    public function company(): BelongsTo     { return $this->belongsTo(Company::class); }
    public function product(): BelongsTo     { return $this->belongsTo(Product::class); }
    public function template(): BelongsTo    { return $this->belongsTo(ProjectTemplate::class, 'project_template_id'); }
    public function creator(): BelongsTo     { return $this->belongsTo(User::class, 'created_by'); }
    public function milestones(): HasMany    { return $this->hasMany(ProjectMilestone::class)->orderBy('order'); }
    public function expenses(): HasMany      { return $this->hasMany(ProjectExpense::class); }
    public function files(): HasMany         { return $this->hasMany(ProjectFile::class); }
    public function activities(): HasMany    { return $this->hasMany(ProjectActivity::class)->latest('created_at'); }
    public function messages(): HasMany      { return $this->hasMany(ProjectMessage::class)->latest('created_at'); }
    public function participants(): HasMany  { return $this->hasMany(ProjectParticipant::class); }
}
```

- [ ] **Step 2: ProjectTemplate and MilestoneTemplate models**

```php
<?php
// app/Domain/ProjectDevelopment/Models/ProjectTemplate.php
namespace App\Domain\ProjectDevelopment\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

class ProjectTemplate extends Model
{
    protected $fillable = ['name', 'description', 'is_default'];

    protected function casts(): array
    {
        return ['is_default' => 'boolean'];
    }

    public function milestoneTemplates(): HasMany
    {
        return $this->hasMany(MilestoneTemplate::class)->orderBy('order');
    }
}
```

```php
<?php
// app/Domain/ProjectDevelopment/Models/MilestoneTemplate.php
namespace App\Domain\ProjectDevelopment\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class MilestoneTemplate extends Model
{
    protected $fillable = [
        'project_template_id', 'name', 'description', 'order', 'estimated_days',
    ];

    protected function casts(): array
    {
        return ['order' => 'integer', 'estimated_days' => 'integer'];
    }

    public function template(): BelongsTo
    {
        return $this->belongsTo(ProjectTemplate::class, 'project_template_id');
    }
}
```

- [ ] **Step 3: ProjectMilestone model**

```php
<?php
// app/Domain/ProjectDevelopment/Models/ProjectMilestone.php
namespace App\Domain\ProjectDevelopment\Models;

use App\Domain\ProjectDevelopment\Enums\MilestoneStatus;
use App\Models\User;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class ProjectMilestone extends Model
{
    protected $fillable = [
        'project_id', 'milestone_template_id', 'name', 'description',
        'order', 'status', 'budget', 'due_at', 'completed_at',
        'approved_by', 'approved_at', 'client_notes',
    ];

    protected function casts(): array
    {
        return [
            'status'      => MilestoneStatus::class,
            'budget'      => 'integer',
            'due_at'      => 'date',
            'completed_at'=> 'date',
            'approved_at' => 'datetime',
        ];
    }

    public function project(): BelongsTo          { return $this->belongsTo(Project::class); }
    public function template(): BelongsTo         { return $this->belongsTo(MilestoneTemplate::class, 'milestone_template_id'); }
    public function approver(): BelongsTo         { return $this->belongsTo(User::class, 'approved_by'); }
    public function tasks(): HasMany              { return $this->hasMany(MilestoneTask::class); }
    public function expenses(): HasMany           { return $this->hasMany(ProjectExpense::class); }
    public function files(): HasMany              { return $this->hasMany(ProjectFile::class); }
}
```

- [ ] **Step 4: Remaining models**

```php
<?php
// app/Domain/ProjectDevelopment/Models/MilestoneTask.php
namespace App\Domain\ProjectDevelopment\Models;

use App\Models\User;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class MilestoneTask extends Model
{
    protected $fillable = [
        'project_milestone_id', 'title', 'description',
        'status', 'assigned_to', 'due_at', 'completed_at',
    ];

    protected function casts(): array
    {
        return ['due_at' => 'date', 'completed_at' => 'datetime'];
    }

    public function milestone(): BelongsTo { return $this->belongsTo(ProjectMilestone::class, 'project_milestone_id'); }
    public function assignee(): BelongsTo  { return $this->belongsTo(User::class, 'assigned_to'); }
}
```

```php
<?php
// app/Domain/ProjectDevelopment/Models/ProjectExpense.php
namespace App\Domain\ProjectDevelopment\Models;

use App\Domain\ProjectDevelopment\Enums\ExpenseCategory;
use App\Domain\ProjectDevelopment\Enums\ExpenseType;
use App\Models\User;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class ProjectExpense extends Model
{
    protected $fillable = [
        'project_id', 'project_milestone_id', 'category',
        'description', 'type', 'amount', 'currency', 'date',
        'receipt_path', 'created_by',
    ];

    protected function casts(): array
    {
        return [
            'category' => ExpenseCategory::class,
            'type'     => ExpenseType::class,
            'amount'   => 'integer',
            'date'     => 'date',
        ];
    }

    protected static function booted(): void
    {
        static::creating(function (ProjectExpense $expense) {
            if (empty($expense->created_by)) {
                $expense->created_by = auth()->id();
            }
        });
    }

    public function project(): BelongsTo   { return $this->belongsTo(Project::class); }
    public function milestone(): BelongsTo { return $this->belongsTo(ProjectMilestone::class, 'project_milestone_id'); }
    public function creator(): BelongsTo   { return $this->belongsTo(User::class, 'created_by'); }
}
```

```php
<?php
// app/Domain/ProjectDevelopment/Models/ProjectFile.php
namespace App\Domain\ProjectDevelopment\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\MorphTo;

class ProjectFile extends Model
{
    protected $fillable = [
        'project_id', 'project_milestone_id', 'name', 'path',
        'mime_type', 'size', 'uploader_id', 'uploader_type', 'notes',
    ];

    protected function casts(): array
    {
        return ['size' => 'integer'];
    }

    public function project(): BelongsTo   { return $this->belongsTo(Project::class); }
    public function milestone(): BelongsTo { return $this->belongsTo(ProjectMilestone::class, 'project_milestone_id'); }
    public function uploader(): MorphTo    { return $this->morphTo(); }
}
```

```php
<?php
// app/Domain/ProjectDevelopment/Models/ProjectActivity.php
namespace App\Domain\ProjectDevelopment\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\MorphTo;

class ProjectActivity extends Model
{
    public $timestamps = false;

    protected $fillable = [
        'project_id', 'event_type', 'payload', 'causer_id', 'causer_type', 'created_at',
    ];

    protected function casts(): array
    {
        return [
            'payload'    => 'array',
            'created_at' => 'datetime',
        ];
    }

    public function project(): BelongsTo { return $this->belongsTo(Project::class); }
    public function causer(): MorphTo    { return $this->morphTo(); }
}
```

```php
<?php
// app/Domain/ProjectDevelopment/Models/ProjectMessage.php
namespace App\Domain\ProjectDevelopment\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\MorphTo;

class ProjectMessage extends Model
{
    public $timestamps = false;

    protected $fillable = [
        'project_id', 'sender_id', 'sender_type', 'body', 'read_at', 'created_at',
    ];

    protected function casts(): array
    {
        return [
            'read_at'    => 'datetime',
            'created_at' => 'datetime',
        ];
    }

    public function project(): BelongsTo { return $this->belongsTo(Project::class); }
    public function sender(): MorphTo    { return $this->morphTo(); }
}
```

```php
<?php
// app/Domain/ProjectDevelopment/Models/ProjectParticipant.php
namespace App\Domain\ProjectDevelopment\Models;

use App\Domain\ProjectDevelopment\Enums\ParticipantSide;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\MorphTo;

class ProjectParticipant extends Model
{
    public $timestamps = false;

    protected $fillable = [
        'project_id', 'participant_id', 'participant_type', 'side', 'created_at',
    ];

    protected function casts(): array
    {
        return [
            'side'       => ParticipantSide::class,
            'created_at' => 'datetime',
        ];
    }

    public function project(): BelongsTo   { return $this->belongsTo(Project::class); }
    public function participant(): MorphTo { return $this->morphTo(); }
}
```

- [ ] **Step 5: Commit**

```bash
git add app/Domain/ProjectDevelopment/Models/
git commit -m "feat: add ProjectDevelopment domain models"
```

---

## Task 4: DTOs and RecordProjectActivityAction

**Files:**
- Create: `app/Domain/ProjectDevelopment/DataTransferObjects/CreateProjectData.php`
- Create: `app/Domain/ProjectDevelopment/DataTransferObjects/CreateMilestoneData.php`
- Create: `app/Domain/ProjectDevelopment/DataTransferObjects/CreateExpenseData.php`
- Create: `app/Domain/ProjectDevelopment/Actions/RecordProjectActivityAction.php`

- [ ] **Step 1: Create DTOs**

```php
<?php
// app/Domain/ProjectDevelopment/DataTransferObjects/CreateProjectData.php
namespace App\Domain\ProjectDevelopment\DataTransferObjects;

use App\Domain\ProjectDevelopment\Enums\ProjectStatus;

readonly class CreateProjectData
{
    public function __construct(
        public int $company_id,
        public string $title,
        public ?string $description = null,
        public ?int $product_id = null,
        public ?int $project_template_id = null,
        public ProjectStatus $status = ProjectStatus::Draft,
        public int $budget = 0,
        public string $currency = 'USD',
        public ?string $started_at = null,
        public ?string $deadline_at = null,
    ) {}
}
```

```php
<?php
// app/Domain/ProjectDevelopment/DataTransferObjects/CreateMilestoneData.php
namespace App\Domain\ProjectDevelopment\DataTransferObjects;

readonly class CreateMilestoneData
{
    public function __construct(
        public string $name,
        public int $order,
        public ?string $description = null,
        public ?int $milestone_template_id = null,
        public int $budget = 0,
        public ?string $due_at = null,
    ) {}
}
```

```php
<?php
// app/Domain/ProjectDevelopment/DataTransferObjects/CreateExpenseData.php
namespace App\Domain\ProjectDevelopment\DataTransferObjects;

use App\Domain\ProjectDevelopment\Enums\ExpenseCategory;
use App\Domain\ProjectDevelopment\Enums\ExpenseType;

readonly class CreateExpenseData
{
    public function __construct(
        public string $description,
        public ExpenseCategory $category,
        public ExpenseType $type,
        public int $amount,
        public string $date,
        public string $currency = 'USD',
        public ?int $project_milestone_id = null,
        public ?string $receipt_path = null,
    ) {}
}
```

- [ ] **Step 2: Create RecordProjectActivityAction**

```php
<?php
// app/Domain/ProjectDevelopment/Actions/RecordProjectActivityAction.php
namespace App\Domain\ProjectDevelopment\Actions;

use App\Domain\ProjectDevelopment\Models\Project;
use App\Domain\ProjectDevelopment\Models\ProjectActivity;

class RecordProjectActivityAction
{
    public function execute(
        Project $project,
        string $eventType,
        array $payload = [],
        mixed $causer = null,
    ): ProjectActivity {
        $causer ??= auth()->user();

        return ProjectActivity::create([
            'project_id'   => $project->id,
            'event_type'   => $eventType,
            'payload'      => $payload,
            'causer_id'    => $causer?->id,
            'causer_type'  => $causer ? get_class($causer) : null,
            'created_at'   => now(),
        ]);
    }
}
```

- [ ] **Step 3: Commit**

```bash
git add app/Domain/ProjectDevelopment/DataTransferObjects/ app/Domain/ProjectDevelopment/Actions/RecordProjectActivityAction.php
git commit -m "feat: add ProjectDevelopment DTOs and RecordProjectActivityAction"
```

---

## Task 5: CreateProjectAction (with template support)

**Files:**
- Create: `app/Domain/ProjectDevelopment/Actions/CreateProjectAction.php`
- Test: `tests/Feature/ProjectDevelopment/CreateProjectActionTest.php`

- [ ] **Step 1: Write the failing test**

```php
<?php
// tests/Feature/ProjectDevelopment/CreateProjectActionTest.php
namespace Tests\Feature\ProjectDevelopment;

use App\Domain\CRM\Models\Company;
use App\Domain\ProjectDevelopment\Actions\CreateProjectAction;
use App\Domain\ProjectDevelopment\DataTransferObjects\CreateProjectData;
use App\Domain\ProjectDevelopment\Enums\ProjectStatus;
use App\Domain\ProjectDevelopment\Models\MilestoneTemplate;
use App\Domain\ProjectDevelopment\Models\Project;
use App\Domain\ProjectDevelopment\Models\ProjectTemplate;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class CreateProjectActionTest extends TestCase
{
    use RefreshDatabase;

    private CreateProjectAction $action;
    private Company $company;
    private User $user;

    protected function setUp(): void
    {
        parent::setUp();
        $this->action = new CreateProjectAction();
        $this->company = Company::create(['name' => 'Test Client', 'status' => 'active']);
        $this->user = User::factory()->create();
        $this->actingAs($this->user);
    }

    public function test_creates_project_without_template(): void
    {
        $data = new CreateProjectData(
            company_id: $this->company->id,
            title: 'Test Project',
            budget: 450000000, // USD 45,000.00 in x10000
        );

        $project = $this->action->execute($data);

        $this->assertInstanceOf(Project::class, $project);
        $this->assertEquals('Test Project', $project->title);
        $this->assertEquals(ProjectStatus::Draft, $project->status);
        $this->assertEquals(0, $project->milestones()->count());
    }

    public function test_creates_project_and_copies_milestones_from_template(): void
    {
        $template = ProjectTemplate::create(['name' => 'Full Dev', 'description' => null, 'is_default' => false]);
        MilestoneTemplate::create(['project_template_id' => $template->id, 'name' => 'Briefing', 'order' => 1]);
        MilestoneTemplate::create(['project_template_id' => $template->id, 'name' => 'Design', 'order' => 2]);

        $data = new CreateProjectData(
            company_id: $this->company->id,
            title: 'Project With Template',
            project_template_id: $template->id,
        );

        $project = $this->action->execute($data);

        $this->assertEquals(2, $project->milestones()->count());
        $this->assertEquals('Briefing', $project->milestones()->first()->name);
        $this->assertEquals('Design', $project->milestones()->skip(1)->first()->name);
    }

    public function test_records_project_created_activity(): void
    {
        $data = new CreateProjectData(
            company_id: $this->company->id,
            title: 'Activity Test',
        );

        $project = $this->action->execute($data);

        $this->assertEquals(1, $project->activities()->count());
        $this->assertEquals('project.created', $project->activities()->first()->event_type);
    }
}
```

- [ ] **Step 2: Run test to verify it fails**

```bash
php artisan test tests/Feature/ProjectDevelopment/CreateProjectActionTest.php
```

Expected: FAIL — `CreateProjectAction` class not found.

- [ ] **Step 3: Implement CreateProjectAction**

```php
<?php
// app/Domain/ProjectDevelopment/Actions/CreateProjectAction.php
namespace App\Domain\ProjectDevelopment\Actions;

use App\Domain\ProjectDevelopment\DataTransferObjects\CreateProjectData;
use App\Domain\ProjectDevelopment\Models\Project;
use App\Domain\ProjectDevelopment\Models\ProjectTemplate;

class CreateProjectAction
{
    public function __construct(
        private RecordProjectActivityAction $recordActivity = new RecordProjectActivityAction(),
    ) {}

    public function execute(CreateProjectData $data): Project
    {
        $project = Project::create([
            'company_id'          => $data->company_id,
            'product_id'          => $data->product_id,
            'project_template_id' => $data->project_template_id,
            'title'               => $data->title,
            'description'         => $data->description,
            'status'              => $data->status,
            'budget'              => $data->budget,
            'currency'            => $data->currency,
            'started_at'          => $data->started_at,
            'deadline_at'         => $data->deadline_at,
        ]);

        if ($data->project_template_id) {
            $this->applyTemplate($project, $data->project_template_id);
        }

        $this->recordActivity->execute($project, 'project.created', [
            'title' => $project->title,
        ]);

        return $project;
    }

    private function applyTemplate(Project $project, int $templateId): void
    {
        $template = ProjectTemplate::with('milestoneTemplates')->find($templateId);

        if (! $template) {
            return;
        }

        foreach ($template->milestoneTemplates as $milestoneTemplate) {
            $project->milestones()->create([
                'milestone_template_id' => $milestoneTemplate->id,
                'name'                  => $milestoneTemplate->name,
                'description'           => $milestoneTemplate->description,
                'order'                 => $milestoneTemplate->order,
            ]);
        }
    }
}
```

- [ ] **Step 4: Run tests to verify they pass**

```bash
php artisan test tests/Feature/ProjectDevelopment/CreateProjectActionTest.php
```

Expected: 3 tests PASS.

- [ ] **Step 5: Commit**

```bash
git add app/Domain/ProjectDevelopment/Actions/CreateProjectAction.php tests/Feature/ProjectDevelopment/CreateProjectActionTest.php
git commit -m "feat: add CreateProjectAction with template support"
```

---

## Task 6: Milestone Lifecycle Actions

**Files:**
- Create: `app/Domain/ProjectDevelopment/Actions/SendMilestoneForApprovalAction.php`
- Create: `app/Domain/ProjectDevelopment/Actions/ApproveMilestoneAction.php`
- Create: `app/Domain/ProjectDevelopment/Actions/RequestMilestoneRevisionAction.php`
- Test: `tests/Feature/ProjectDevelopment/MilestoneLifecycleTest.php`

- [ ] **Step 1: Write failing tests**

```php
<?php
// tests/Feature/ProjectDevelopment/MilestoneLifecycleTest.php
namespace Tests\Feature\ProjectDevelopment;

use App\Domain\CRM\Models\Company;
use App\Domain\ProjectDevelopment\Actions\ApproveMilestoneAction;
use App\Domain\ProjectDevelopment\Actions\RequestMilestoneRevisionAction;
use App\Domain\ProjectDevelopment\Actions\SendMilestoneForApprovalAction;
use App\Domain\ProjectDevelopment\Enums\MilestoneStatus;
use App\Domain\ProjectDevelopment\Models\Project;
use App\Domain\ProjectDevelopment\Models\ProjectMilestone;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class MilestoneLifecycleTest extends TestCase
{
    use RefreshDatabase;

    private Project $project;
    private ProjectMilestone $milestone;
    private User $user;

    protected function setUp(): void
    {
        parent::setUp();
        $this->user = User::factory()->create();
        $this->actingAs($this->user);

        $company = Company::create(['name' => 'Test Client', 'status' => 'active']);
        $this->project = Project::create([
            'company_id' => $company->id,
            'title'      => 'Test Project',
            'status'     => 'draft',
            'budget'     => 0,
            'currency'   => 'USD',
            'created_by' => $this->user->id,
        ]);
        $this->milestone = $this->project->milestones()->create([
            'name'   => 'Design',
            'order'  => 1,
            'status' => MilestoneStatus::InProgress,
        ]);
    }

    public function test_send_milestone_for_approval_transitions_status(): void
    {
        (new SendMilestoneForApprovalAction())->execute($this->milestone);

        $this->milestone->refresh();
        $this->assertEquals(MilestoneStatus::WaitingApproval, $this->milestone->status);
    }

    public function test_send_for_approval_records_activity(): void
    {
        (new SendMilestoneForApprovalAction())->execute($this->milestone);

        $this->assertEquals(
            'milestone.sent_for_approval',
            $this->project->activities()->latest('created_at')->first()->event_type
        );
    }

    public function test_approve_milestone_sets_status_and_timestamps(): void
    {
        $this->milestone->update(['status' => MilestoneStatus::WaitingApproval]);

        (new ApproveMilestoneAction())->execute($this->milestone, notes: 'Looks great');

        $this->milestone->refresh();
        $this->assertEquals(MilestoneStatus::Approved, $this->milestone->status);
        $this->assertEquals('Looks great', $this->milestone->client_notes);
        $this->assertNotNull($this->milestone->approved_at);
        $this->assertEquals($this->user->id, $this->milestone->approved_by);
    }

    public function test_request_revision_sets_status_to_rejected(): void
    {
        $this->milestone->update(['status' => MilestoneStatus::WaitingApproval]);

        (new RequestMilestoneRevisionAction())->execute($this->milestone, notes: 'Wrong color');

        $this->milestone->refresh();
        $this->assertEquals(MilestoneStatus::Rejected, $this->milestone->status);
        $this->assertEquals('Wrong color', $this->milestone->client_notes);
    }
}
```

- [ ] **Step 2: Run tests to verify they fail**

```bash
php artisan test tests/Feature/ProjectDevelopment/MilestoneLifecycleTest.php
```

Expected: FAIL — action classes not found.

- [ ] **Step 3: Implement the three actions**

```php
<?php
// app/Domain/ProjectDevelopment/Actions/SendMilestoneForApprovalAction.php
namespace App\Domain\ProjectDevelopment\Actions;

use App\Domain\ProjectDevelopment\Enums\MilestoneStatus;
use App\Domain\ProjectDevelopment\Models\ProjectMilestone;

class SendMilestoneForApprovalAction
{
    public function __construct(
        private RecordProjectActivityAction $recordActivity = new RecordProjectActivityAction(),
    ) {}

    public function execute(ProjectMilestone $milestone): void
    {
        $milestone->update(['status' => MilestoneStatus::WaitingApproval]);

        $this->recordActivity->execute(
            $milestone->project,
            'milestone.sent_for_approval',
            ['milestone_id' => $milestone->id, 'name' => $milestone->name],
        );
    }
}
```

```php
<?php
// app/Domain/ProjectDevelopment/Actions/ApproveMilestoneAction.php
namespace App\Domain\ProjectDevelopment\Actions;

use App\Domain\ProjectDevelopment\Enums\MilestoneStatus;
use App\Domain\ProjectDevelopment\Models\ProjectMilestone;

class ApproveMilestoneAction
{
    public function __construct(
        private RecordProjectActivityAction $recordActivity = new RecordProjectActivityAction(),
    ) {}

    public function execute(ProjectMilestone $milestone, ?string $notes = null): void
    {
        $milestone->update([
            'status'       => MilestoneStatus::Approved,
            'approved_by'  => auth()->id(),
            'approved_at'  => now(),
            'client_notes' => $notes,
            'completed_at' => now()->toDateString(),
        ]);

        $this->recordActivity->execute(
            $milestone->project,
            'milestone.approved',
            ['milestone_id' => $milestone->id, 'name' => $milestone->name],
        );
    }
}
```

```php
<?php
// app/Domain/ProjectDevelopment/Actions/RequestMilestoneRevisionAction.php
namespace App\Domain\ProjectDevelopment\Actions;

use App\Domain\ProjectDevelopment\Enums\MilestoneStatus;
use App\Domain\ProjectDevelopment\Models\ProjectMilestone;

class RequestMilestoneRevisionAction
{
    public function __construct(
        private RecordProjectActivityAction $recordActivity = new RecordProjectActivityAction(),
    ) {}

    public function execute(ProjectMilestone $milestone, string $notes): void
    {
        $milestone->update([
            'status'       => MilestoneStatus::Rejected,
            'client_notes' => $notes,
        ]);

        $this->recordActivity->execute(
            $milestone->project,
            'milestone.revision_requested',
            ['milestone_id' => $milestone->id, 'name' => $milestone->name, 'notes' => $notes],
        );
    }
}
```

- [ ] **Step 4: Run tests to verify they pass**

```bash
php artisan test tests/Feature/ProjectDevelopment/MilestoneLifecycleTest.php
```

Expected: 4 tests PASS.

- [ ] **Step 5: Commit**

```bash
git add app/Domain/ProjectDevelopment/Actions/ tests/Feature/ProjectDevelopment/MilestoneLifecycleTest.php
git commit -m "feat: add milestone lifecycle actions (send for approval, approve, request revision)"
```

---

## Task 7: ProjectBudgetService

**Files:**
- Create: `app/Domain/ProjectDevelopment/Services/ProjectBudgetService.php`
- Test: `tests/Unit/ProjectDevelopment/ProjectBudgetServiceTest.php`

- [ ] **Step 1: Write failing tests**

```php
<?php
// tests/Unit/ProjectDevelopment/ProjectBudgetServiceTest.php
namespace Tests\Unit\ProjectDevelopment;

use App\Domain\CRM\Models\Company;
use App\Domain\ProjectDevelopment\Enums\ExpenseCategory;
use App\Domain\ProjectDevelopment\Enums\ExpenseType;
use App\Domain\ProjectDevelopment\Models\Project;
use App\Domain\ProjectDevelopment\Models\ProjectMilestone;
use App\Domain\ProjectDevelopment\Services\ProjectBudgetService;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class ProjectBudgetServiceTest extends TestCase
{
    use RefreshDatabase;

    private ProjectBudgetService $service;
    private Project $project;
    private User $user;

    protected function setUp(): void
    {
        parent::setUp();
        $this->service = new ProjectBudgetService();
        $this->user = User::factory()->create();

        $company = Company::create(['name' => 'Test Client', 'status' => 'active']);
        $this->project = Project::create([
            'company_id' => $company->id,
            'title'      => 'Budget Test Project',
            'status'     => 'active',
            'budget'     => 500000000, // 50,000.00
            'currency'   => 'USD',
            'created_by' => $this->user->id,
        ]);
    }

    private function addExpense(int $amount, ExpenseType $type, ?ProjectMilestone $milestone = null): void
    {
        $this->project->expenses()->create([
            'category'             => ExpenseCategory::Design,
            'description'          => 'Test expense',
            'type'                 => $type,
            'amount'               => $amount,
            'currency'             => 'USD',
            'date'                 => now()->toDateString(),
            'project_milestone_id' => $milestone?->id,
            'created_by'           => $this->user->id,
        ]);
    }

    public function test_total_planned_sums_planned_expenses(): void
    {
        $this->addExpense(100000000, ExpenseType::Planned); // 10,000
        $this->addExpense(50000000, ExpenseType::Planned);  // 5,000
        $this->addExpense(30000000, ExpenseType::Actual);   // should be ignored

        $this->assertEquals(150000000, $this->service->totalPlanned($this->project));
    }

    public function test_total_actual_sums_actual_expenses(): void
    {
        $this->addExpense(100000000, ExpenseType::Actual);
        $this->addExpense(20000000, ExpenseType::Planned); // should be ignored

        $this->assertEquals(100000000, $this->service->totalActual($this->project));
    }

    public function test_deviation_is_actual_minus_planned(): void
    {
        $this->addExpense(100000000, ExpenseType::Planned);
        $this->addExpense(120000000, ExpenseType::Actual);

        $this->assertEquals(20000000, $this->service->deviation($this->project)); // over budget
    }

    public function test_milestone_budget_utilization(): void
    {
        $milestone = $this->project->milestones()->create([
            'name'   => 'Design',
            'order'  => 1,
            'status' => 'in_progress',
            'budget' => 40000000, // 4,000
        ]);
        $this->addExpense(30000000, ExpenseType::Actual, $milestone); // 3,000

        $this->assertEquals(0.75, $this->service->milestoneBudgetUtilization($milestone));
    }
}
```

- [ ] **Step 2: Run tests to verify they fail**

```bash
php artisan test tests/Unit/ProjectDevelopment/ProjectBudgetServiceTest.php
```

Expected: FAIL — `ProjectBudgetService` not found.

- [ ] **Step 3: Implement ProjectBudgetService**

```php
<?php
// app/Domain/ProjectDevelopment/Services/ProjectBudgetService.php
namespace App\Domain\ProjectDevelopment\Services;

use App\Domain\ProjectDevelopment\Enums\ExpenseType;
use App\Domain\ProjectDevelopment\Models\Project;
use App\Domain\ProjectDevelopment\Models\ProjectMilestone;

class ProjectBudgetService
{
    public function totalPlanned(Project $project): int
    {
        return (int) $project->expenses()
            ->where('type', ExpenseType::Planned)
            ->sum('amount');
    }

    public function totalActual(Project $project): int
    {
        return (int) $project->expenses()
            ->where('type', ExpenseType::Actual)
            ->sum('amount');
    }

    public function totalPlannedForMilestone(ProjectMilestone $milestone): int
    {
        return (int) $milestone->expenses()
            ->where('type', ExpenseType::Planned)
            ->sum('amount');
    }

    public function totalActualForMilestone(ProjectMilestone $milestone): int
    {
        return (int) $milestone->expenses()
            ->where('type', ExpenseType::Actual)
            ->sum('amount');
    }

    public function deviation(Project $project): int
    {
        return $this->totalActual($project) - $this->totalPlanned($project);
    }

    public function milestoneBudgetUtilization(ProjectMilestone $milestone): float
    {
        if (! $milestone->budget || $milestone->budget === 0) {
            return 0.0;
        }

        return $this->totalActualForMilestone($milestone) / $milestone->budget;
    }
}
```

- [ ] **Step 4: Run tests to verify they pass**

```bash
php artisan test tests/Unit/ProjectDevelopment/ProjectBudgetServiceTest.php
```

Expected: 4 tests PASS.

- [ ] **Step 5: Run all project tests**

```bash
php artisan test tests/Unit/ProjectDevelopment/ tests/Feature/ProjectDevelopment/
```

Expected: all 11 tests PASS.

- [ ] **Step 6: Commit**

```bash
git add app/Domain/ProjectDevelopment/Services/ProjectBudgetService.php tests/Unit/ProjectDevelopment/ProjectBudgetServiceTest.php
git commit -m "feat: add ProjectBudgetService with planned/actual tracking"
```

---

## Task 8: Admin — ProjectTemplateResource

**Files:**
- Create: `app/Filament/Resources/Projects/ProjectTemplateResource.php`
- Create: `app/Filament/Resources/Projects/ProjectTemplateResource/Pages/ListProjectTemplates.php`
- Create: `app/Filament/Resources/Projects/ProjectTemplateResource/Pages/CreateProjectTemplate.php`
- Create: `app/Filament/Resources/Projects/ProjectTemplateResource/Pages/EditProjectTemplate.php`
- Create: `app/Filament/Resources/Projects/ProjectTemplateResource/RelationManagers/MilestoneTemplatesRelationManager.php`
- Modify: `app/Providers/Filament/AdminPanelProvider.php` — register resource

- [ ] **Step 1: Create MilestoneTemplatesRelationManager**

```php
<?php
// app/Filament/Resources/Projects/ProjectTemplateResource/RelationManagers/MilestoneTemplatesRelationManager.php
namespace App\Filament\Resources\Projects\ProjectTemplateResource\RelationManagers;

use BackedEnum;
use Filament\Forms\Components\Textarea;
use Filament\Forms\Components\TextInput;
use Filament\Resources\RelationManagers\RelationManager;
use Filament\Schemas\Schema;
use Filament\Tables\Actions\CreateAction;
use Filament\Tables\Actions\DeleteAction;
use Filament\Tables\Actions\EditAction;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Table;

class MilestoneTemplatesRelationManager extends RelationManager
{
    protected static string $relationship = 'milestoneTemplates';
    protected static ?string $title = 'Milestone Templates';
    protected static BackedEnum|string|null $icon = 'heroicon-o-flag';

    public function form(Schema $schema): Schema
    {
        return $schema->components([
            TextInput::make('name')->required()->maxLength(255),
            TextInput::make('order')->numeric()->required()->default(1),
            TextInput::make('estimated_days')->numeric()->nullable()->label('Estimated Days'),
            Textarea::make('description')->nullable()->rows(2),
        ]);
    }

    public function table(Table $table): Table
    {
        return $table
            ->reorderable('order')
            ->defaultSort('order')
            ->columns([
                TextColumn::make('order')->sortable()->width(60),
                TextColumn::make('name')->searchable(),
                TextColumn::make('estimated_days')->label('Est. Days')->placeholder('—'),
            ])
            ->headerActions([CreateAction::make()])
            ->actions([EditAction::make(), DeleteAction::make()]);
    }
}
```

- [ ] **Step 2: Create ProjectTemplateResource**

```php
<?php
// app/Filament/Resources/Projects/ProjectTemplateResource.php
namespace App\Filament\Resources\Projects;

use App\Domain\ProjectDevelopment\Models\ProjectTemplate;
use App\Filament\Resources\Projects\ProjectTemplateResource\Pages\CreateProjectTemplate;
use App\Filament\Resources\Projects\ProjectTemplateResource\Pages\EditProjectTemplate;
use App\Filament\Resources\Projects\ProjectTemplateResource\Pages\ListProjectTemplates;
use App\Filament\Resources\Projects\ProjectTemplateResource\RelationManagers\MilestoneTemplatesRelationManager;
use BackedEnum;
use Filament\Forms\Components\Textarea;
use Filament\Forms\Components\TextInput;
use Filament\Forms\Components\Toggle;
use Filament\Resources\Resource;
use Filament\Schemas\Schema;
use Filament\Tables\Columns\IconColumn;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Table;

class ProjectTemplateResource extends Resource
{
    protected static ?string $model = ProjectTemplate::class;
    protected static BackedEnum|string|null $navigationIcon = 'heroicon-o-rectangle-stack';
    protected static ?int $navigationSort = 72;
    protected static ?string $slug = 'project-templates';
    protected static ?string $recordTitleAttribute = 'name';

    public static function canAccess(): bool
    {
        return auth()->user()?->can('manage-project-templates') ?? false;
    }

    public static function getNavigationGroup(): ?string { return 'Projects'; }

    public static function form(Schema $schema): Schema
    {
        return $schema->components([
            TextInput::make('name')->required()->maxLength(255)->columnSpanFull(),
            Textarea::make('description')->nullable()->rows(3)->columnSpanFull(),
            Toggle::make('is_default')->label('Set as default template'),
        ]);
    }

    public static function table(Table $table): Table
    {
        return $table
            ->columns([
                TextColumn::make('name')->searchable()->sortable(),
                TextColumn::make('milestoneTemplates_count')
                    ->counts('milestoneTemplates')
                    ->label('Phases'),
                IconColumn::make('is_default')->boolean()->label('Default'),
                TextColumn::make('updated_at')->dateTime()->sortable(),
            ]);
    }

    public static function getRelations(): array
    {
        return [MilestoneTemplatesRelationManager::class];
    }

    public static function getPages(): array
    {
        return [
            'index'  => ListProjectTemplates::route('/'),
            'create' => CreateProjectTemplate::route('/create'),
            'edit'   => EditProjectTemplate::route('/{record}/edit'),
        ];
    }
}
```

- [ ] **Step 3: Create page classes**

```php
<?php
// app/Filament/Resources/Projects/ProjectTemplateResource/Pages/ListProjectTemplates.php
namespace App\Filament\Resources\Projects\ProjectTemplateResource\Pages;

use App\Filament\Resources\Projects\ProjectTemplateResource;
use Filament\Resources\Pages\ListRecords;

class ListProjectTemplates extends ListRecords
{
    protected static string $resource = ProjectTemplateResource::class;
}
```

```php
<?php
// app/Filament\Resources\Projects\ProjectTemplateResource\Pages\CreateProjectTemplate.php
namespace App\Filament\Resources\Projects\ProjectTemplateResource\Pages;

use App\Filament\Resources\Projects\ProjectTemplateResource;
use Filament\Resources\Pages\CreateRecord;

class CreateProjectTemplate extends CreateRecord
{
    protected static string $resource = ProjectTemplateResource::class;
}
```

```php
<?php
// app/Filament/Resources/Projects/ProjectTemplateResource/Pages/EditProjectTemplate.php
namespace App\Filament\Resources\Projects\ProjectTemplateResource\Pages;

use App\Filament\Resources\Projects\ProjectTemplateResource;
use Filament\Resources\Pages\EditRecord;

class EditProjectTemplate extends EditRecord
{
    protected static string $resource = ProjectTemplateResource::class;
}
```

- [ ] **Step 4: Register in AdminPanelProvider**

Open `app/Providers/Filament/AdminPanelProvider.php`. In the `panel()` method, add to the `->discoverResources()` call or explicitly register. If the provider uses `->discoverResources(in: app_path('Filament/Resources'), for: 'App\\Filament\\Resources')`, it will auto-discover. If not, add:

```php
->resources([
    // ... existing resources ...
    \App\Filament\Resources\Projects\ProjectTemplateResource::class,
])
```

- [ ] **Step 5: Verify in browser**

```bash
php artisan serve
```

Navigate to Admin → Projects → Project Templates. Verify you can create a template, add milestone phases via the relation manager, and reorder them.

- [ ] **Step 6: Commit**

```bash
git add app/Filament/Resources/Projects/ProjectTemplateResource.php \
        app/Filament/Resources/Projects/ProjectTemplateResource/ \
        app/Providers/Filament/AdminPanelProvider.php
git commit -m "feat: add ProjectTemplateResource with milestone template management"
```

---

## Task 9: Admin — ProjectResource (List + Form + Infolist)

**Files:**
- Create: `app/Filament/Resources/Projects/ProjectResource.php`
- Create: `app/Filament/Resources/Projects/ProjectResource/Schemas/ProjectForm.php`
- Create: `app/Filament/Resources/Projects/ProjectResource/Schemas/ProjectInfolist.php`
- Create: `app/Filament/Resources/Projects/ProjectResource/Tables/ProjectsTable.php`
- Create: all 4 page classes

- [ ] **Step 1: Create ProjectForm schema**

```php
<?php
// app/Filament/Resources/Projects/ProjectResource/Schemas/ProjectForm.php
namespace App\Filament\Resources\Projects\ProjectResource\Schemas;

use App\Domain\CRM\Models\Company;
use App\Domain\ProjectDevelopment\Enums\ProjectStatus;
use App\Domain\ProjectDevelopment\Models\ProjectTemplate;
use Filament\Forms\Components\DatePicker;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\Textarea;
use Filament\Forms\Components\TextInput;
use Filament\Schemas\Schema;

class ProjectForm
{
    public static function configure(Schema $schema): Schema
    {
        return $schema->components([
            TextInput::make('title')
                ->required()
                ->maxLength(255)
                ->columnSpanFull(),

            Select::make('company_id')
                ->label('Client')
                ->options(fn () => Company::whereHas('companyRoles', fn ($q) => $q->where('role', 'client'))
                    ->orderBy('name')
                    ->pluck('name', 'id'))
                ->searchable()
                ->required(),

            Select::make('project_template_id')
                ->label('Template')
                ->options(fn () => ProjectTemplate::orderBy('name')->pluck('name', 'id'))
                ->nullable()
                ->placeholder('No template')
                ->helperText('Milestones will be copied from this template on creation.'),

            Select::make('status')
                ->options(collect(ProjectStatus::cases())->mapWithKeys(
                    fn ($case) => [$case->value => $case->getLabel()]
                ))
                ->required()
                ->default(ProjectStatus::Draft->value),

            TextInput::make('budget')
                ->numeric()
                ->prefix('USD')
                ->helperText('Enter amount in USD (e.g. 45000)')
                ->formatStateUsing(fn ($state) => $state ? $state / 10000 : null)
                ->dehydrateStateUsing(fn ($state) => $state ? (int) ($state * 10000) : 0),

            Select::make('currency')
                ->options(['USD' => 'USD', 'EUR' => 'EUR', 'CNY' => 'CNY'])
                ->default('USD')
                ->required(),

            DatePicker::make('started_at')->label('Start Date'),
            DatePicker::make('deadline_at')->label('Deadline'),

            Textarea::make('description')->rows(3)->columnSpanFull(),
        ]);
    }
}
```

- [ ] **Step 2: Create ProjectInfolist schema**

```php
<?php
// app/Filament/Resources/Projects/ProjectResource/Schemas/ProjectInfolist.php
namespace App\Filament\Resources\Projects\ProjectResource\Schemas;

use Filament\Infolists\Components\TextEntry;
use Filament\Schemas\Schema;
use Filament\Schemas\Components\Section;

class ProjectInfolist
{
    public static function configure(Schema $schema): Schema
    {
        return $schema->components([
            Section::make('Project Details')->schema([
                TextEntry::make('title')->columnSpanFull(),
                TextEntry::make('company.name')->label('Client'),
                TextEntry::make('status')->badge(),
                TextEntry::make('budget')
                    ->label('Budget')
                    ->formatStateUsing(fn ($state) => 'USD ' . number_format($state / 10000, 2)),
                TextEntry::make('currency'),
                TextEntry::make('started_at')->date(),
                TextEntry::make('deadline_at')->date()->label('Deadline'),
                TextEntry::make('description')->columnSpanFull(),
            ])->columns(2),

            Section::make('Product & Template')->schema([
                TextEntry::make('product.name')->label('Product')->placeholder('Not linked'),
                TextEntry::make('template.name')->label('Template')->placeholder('None'),
            ])->columns(2),
        ]);
    }
}
```

- [ ] **Step 3: Create ProjectsTable**

```php
<?php
// app/Filament/Resources/Projects/ProjectResource/Tables/ProjectsTable.php
namespace App\Filament\Resources\Projects\ProjectResource\Tables;

use App\Domain\ProjectDevelopment\Enums\ProjectStatus;
use Filament\Tables\Actions\DeleteAction;
use Filament\Tables\Actions\EditAction;
use Filament\Tables\Actions\ViewAction;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Filters\SelectFilter;
use Filament\Tables\Table;

class ProjectsTable
{
    public static function configure(Table $table): Table
    {
        return $table
            ->columns([
                TextColumn::make('title')
                    ->searchable()
                    ->sortable()
                    ->weight('bold'),
                TextColumn::make('company.name')
                    ->label('Client')
                    ->searchable()
                    ->sortable(),
                TextColumn::make('status')
                    ->badge()
                    ->color(fn ($state) => $state->getColor()),
                TextColumn::make('budget')
                    ->label('Budget')
                    ->formatStateUsing(fn ($state) => 'USD ' . number_format($state / 10000, 2))
                    ->sortable(),
                TextColumn::make('deadline_at')
                    ->label('Deadline')
                    ->date()
                    ->sortable(),
                TextColumn::make('milestones_count')
                    ->counts('milestones')
                    ->label('Phases'),
                TextColumn::make('updated_at')->dateTime()->sortable()->toggleable(isToggledHiddenByDefault: true),
            ])
            ->filters([
                SelectFilter::make('status')
                    ->options(collect(ProjectStatus::cases())
                        ->mapWithKeys(fn ($c) => [$c->value => $c->getLabel()])),
            ])
            ->actions([ViewAction::make(), EditAction::make(), DeleteAction::make()])
            ->defaultSort('updated_at', 'desc');
    }
}
```

- [ ] **Step 4: Create ProjectResource and pages**

```php
<?php
// app/Filament/Resources/Projects/ProjectResource.php
namespace App\Filament\Resources\Projects;

use App\Domain\ProjectDevelopment\Actions\CreateProjectAction;
use App\Domain\ProjectDevelopment\DataTransferObjects\CreateProjectData;
use App\Domain\ProjectDevelopment\Enums\ProjectStatus;
use App\Domain\ProjectDevelopment\Models\Project;
use App\Filament\Resources\Projects\ProjectResource\Pages\CreateProject;
use App\Filament\Resources\Projects\ProjectResource\Pages\EditProject;
use App\Filament\Resources\Projects\ProjectResource\Pages\ListProjects;
use App\Filament\Resources\Projects\ProjectResource\Pages\ViewProject;
use App\Filament\Resources\Projects\ProjectResource\RelationManagers\ActivityRelationManager;
use App\Filament\Resources\Projects\ProjectResource\RelationManagers\ExpensesRelationManager;
use App\Filament\Resources\Projects\ProjectResource\RelationManagers\FilesRelationManager;
use App\Filament\Resources\Projects\ProjectResource\RelationManagers\MessagesRelationManager;
use App\Filament\Resources\Projects\ProjectResource\RelationManagers\MilestonesRelationManager;
use App\Filament\Resources\Projects\ProjectResource\RelationManagers\ParticipantsRelationManager;
use App\Filament\Resources\Projects\ProjectResource\Schemas\ProjectForm;
use App\Filament\Resources\Projects\ProjectResource\Schemas\ProjectInfolist;
use App\Filament\Resources\Projects\ProjectResource\Tables\ProjectsTable;
use BackedEnum;
use Filament\Resources\Resource;
use Filament\Schemas\Schema;
use Filament\Tables\Table;

class ProjectResource extends Resource
{
    protected static ?string $model = Project::class;
    protected static BackedEnum|string|null $navigationIcon = 'heroicon-o-briefcase';
    protected static ?int $navigationSort = 70;
    protected static ?string $slug = 'projects';
    protected static ?string $recordTitleAttribute = 'title';

    public static function canAccess(): bool
    {
        return auth()->user()?->can('view-projects') ?? false;
    }

    public static function getNavigationGroup(): ?string { return 'Projects'; }

    public static function form(Schema $schema): Schema
    {
        return ProjectForm::configure($schema);
    }

    public static function infolist(Schema $schema): Schema
    {
        return ProjectInfolist::configure($schema);
    }

    public static function table(Table $table): Table
    {
        return ProjectsTable::configure($table);
    }

    public static function getRelations(): array
    {
        return [
            MilestonesRelationManager::class,
            ExpensesRelationManager::class,
            FilesRelationManager::class,
            MessagesRelationManager::class,
            ParticipantsRelationManager::class,
            ActivityRelationManager::class,
        ];
    }

    public static function getPages(): array
    {
        return [
            'index'  => ListProjects::route('/'),
            'create' => CreateProject::route('/create'),
            'view'   => ViewProject::route('/{record}'),
            'edit'   => EditProject::route('/{record}/edit'),
        ];
    }
}
```

```php
<?php
// app/Filament/Resources/Projects/ProjectResource/Pages/ListProjects.php
namespace App\Filament\Resources\Projects\ProjectResource\Pages;

use App\Filament\Resources\Projects\ProjectResource;
use Filament\Resources\Pages\ListRecords;

class ListProjects extends ListRecords
{
    protected static string $resource = ProjectResource::class;
}
```

```php
<?php
// app/Filament/Resources/Projects/ProjectResource/Pages/CreateProject.php
namespace App\Filament\Resources\Projects\ProjectResource\Pages;

use App\Domain\ProjectDevelopment\Actions\CreateProjectAction;
use App\Domain\ProjectDevelopment\DataTransferObjects\CreateProjectData;
use App\Domain\ProjectDevelopment\Enums\ProjectStatus;
use App\Filament\Resources\Projects\ProjectResource;
use Filament\Resources\Pages\CreateRecord;

class CreateProject extends CreateRecord
{
    protected static string $resource = ProjectResource::class;

    protected function handleRecordCreation(array $data): \Illuminate\Database\Eloquent\Model
    {
        return (new CreateProjectAction())->execute(new CreateProjectData(
            company_id:          $data['company_id'],
            title:               $data['title'],
            description:         $data['description'] ?? null,
            product_id:          $data['product_id'] ?? null,
            project_template_id: $data['project_template_id'] ?? null,
            status:              ProjectStatus::from($data['status']),
            budget:              $data['budget'] ?? 0,
            currency:            $data['currency'] ?? 'USD',
            started_at:          $data['started_at'] ?? null,
            deadline_at:         $data['deadline_at'] ?? null,
        ));
    }
}
```

```php
<?php
// app/Filament/Resources/Projects/ProjectResource/Pages/ViewProject.php
namespace App\Filament\Resources\Projects\ProjectResource\Pages;

use App\Filament\Resources\Projects\ProjectResource;
use Filament\Resources\Pages\ViewRecord;

class ViewProject extends ViewRecord
{
    protected static string $resource = ProjectResource::class;
}
```

```php
<?php
// app/Filament/Resources/Projects/ProjectResource/Pages/EditProject.php
namespace App\Filament\Resources\Projects\ProjectResource\Pages;

use App\Filament\Resources\Projects\ProjectResource;
use Filament\Resources\Pages\EditRecord;

class EditProject extends EditRecord
{
    protected static string $resource = ProjectResource::class;
}
```

- [ ] **Step 5: Commit**

```bash
git add app/Filament/Resources/Projects/ProjectResource.php \
        app/Filament/Resources/Projects/ProjectResource/
git commit -m "feat: add ProjectResource admin panel (list, create, view, edit)"
```

---

## Task 10: Admin — Relation Managers

**Files:**
- Create: all 6 RelationManagers under `app/Filament/Resources/Projects/ProjectResource/RelationManagers/`

- [ ] **Step 1: MilestonesRelationManager**

```php
<?php
// app/Filament/Resources/Projects/ProjectResource/RelationManagers/MilestonesRelationManager.php
namespace App\Filament\Resources\Projects\ProjectResource\RelationManagers;

use App\Domain\ProjectDevelopment\Actions\SendMilestoneForApprovalAction;
use App\Domain\ProjectDevelopment\Enums\MilestoneStatus;
use BackedEnum;
use Filament\Forms\Components\DatePicker;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\Textarea;
use Filament\Forms\Components\TextInput;
use Filament\Resources\RelationManagers\RelationManager;
use Filament\Schemas\Schema;
use Filament\Tables\Actions\Action;
use Filament\Tables\Actions\CreateAction;
use Filament\Tables\Actions\DeleteAction;
use Filament\Tables\Actions\EditAction;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Table;

class MilestonesRelationManager extends RelationManager
{
    protected static string $relationship = 'milestones';
    protected static ?string $title = 'Milestones';
    protected static BackedEnum|string|null $icon = 'heroicon-o-flag';

    public function form(Schema $schema): Schema
    {
        return $schema->components([
            TextInput::make('name')->required()->maxLength(255),
            TextInput::make('order')->numeric()->required()->default(1),
            Select::make('status')
                ->options(collect(MilestoneStatus::cases())
                    ->mapWithKeys(fn ($c) => [$c->value => $c->getLabel()]))
                ->required()
                ->default(MilestoneStatus::Pending->value),
            TextInput::make('budget')
                ->numeric()
                ->prefix('USD')
                ->nullable()
                ->formatStateUsing(fn ($state) => $state ? $state / 10000 : null)
                ->dehydrateStateUsing(fn ($state) => $state ? (int) ($state * 10000) : null),
            DatePicker::make('due_at')->label('Due Date'),
            Textarea::make('description')->rows(2)->nullable()->columnSpanFull(),
        ]);
    }

    public function table(Table $table): Table
    {
        return $table
            ->reorderable('order')
            ->defaultSort('order')
            ->columns([
                TextColumn::make('order')->width(50),
                TextColumn::make('name'),
                TextColumn::make('status')->badge()
                    ->color(fn ($state) => $state->getColor()),
                TextColumn::make('budget')
                    ->formatStateUsing(fn ($state) => $state ? 'USD ' . number_format($state / 10000, 2) : '—'),
                TextColumn::make('due_at')->date()->label('Due'),
            ])
            ->headerActions([CreateAction::make()])
            ->actions([
                EditAction::make(),
                Action::make('send_for_approval')
                    ->label('Send for Approval')
                    ->icon('heroicon-o-paper-airplane')
                    ->color('warning')
                    ->requiresConfirmation()
                    ->visible(fn ($record) => $record->status === MilestoneStatus::InProgress)
                    ->action(fn ($record) => (new SendMilestoneForApprovalAction())->execute($record)),
                DeleteAction::make(),
            ]);
    }
}
```

- [ ] **Step 2: ExpensesRelationManager**

```php
<?php
// app/Filament/Resources/Projects/ProjectResource/RelationManagers/ExpensesRelationManager.php
namespace App\Filament\Resources\Projects\ProjectResource\RelationManagers;

use App\Domain\ProjectDevelopment\Enums\ExpenseCategory;
use App\Domain\ProjectDevelopment\Enums\ExpenseType;
use BackedEnum;
use Filament\Forms\Components\DatePicker;
use Filament\Forms\Components\FileUpload;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\TextInput;
use Filament\Resources\RelationManagers\RelationManager;
use Filament\Schemas\Schema;
use Filament\Tables\Actions\CreateAction;
use Filament\Tables\Actions\DeleteAction;
use Filament\Tables\Actions\EditAction;
use Filament\Tables\Columns\Summarizers\Sum;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Filters\SelectFilter;
use Filament\Tables\Table;

class ExpensesRelationManager extends RelationManager
{
    protected static string $relationship = 'expenses';
    protected static ?string $title = 'Expenses';
    protected static BackedEnum|string|null $icon = 'heroicon-o-currency-dollar';

    public function form(Schema $schema): Schema
    {
        return $schema->components([
            Select::make('type')
                ->options(collect(ExpenseType::cases())
                    ->mapWithKeys(fn ($c) => [$c->value => $c->getLabel()]))
                ->required(),
            Select::make('category')
                ->options(collect(ExpenseCategory::cases())
                    ->mapWithKeys(fn ($c) => [$c->value => $c->getLabel()]))
                ->required(),
            TextInput::make('description')->required()->maxLength(255)->columnSpanFull(),
            TextInput::make('amount')
                ->numeric()->required()->prefix('USD')
                ->formatStateUsing(fn ($state) => $state ? $state / 10000 : null)
                ->dehydrateStateUsing(fn ($state) => (int) ($state * 10000)),
            DatePicker::make('date')->required()->default(now()->toDateString()),
            Select::make('project_milestone_id')
                ->label('Phase')
                ->options(fn () => $this->getOwnerRecord()->milestones()->pluck('name', 'id'))
                ->nullable()
                ->placeholder('Project-level (no phase)'),
            FileUpload::make('receipt_path')->label('Receipt')->nullable()->disk('local'),
        ]);
    }

    public function table(Table $table): Table
    {
        return $table
            ->columns([
                TextColumn::make('type')->badge()
                    ->color(fn ($state) => $state === ExpenseType::Planned ? 'primary' : 'success'),
                TextColumn::make('category')->badge(),
                TextColumn::make('description'),
                TextColumn::make('amount')
                    ->formatStateUsing(fn ($state) => 'USD ' . number_format($state / 10000, 2))
                    ->summarize(Sum::make()
                        ->formatStateUsing(fn ($state) => 'USD ' . number_format($state / 10000, 2))
                    ),
                TextColumn::make('milestone.name')->label('Phase')->placeholder('Project-level'),
                TextColumn::make('date')->date()->sortable(),
            ])
            ->filters([
                SelectFilter::make('type')
                    ->options(collect(ExpenseType::cases())
                        ->mapWithKeys(fn ($c) => [$c->value => $c->getLabel()])),
            ])
            ->headerActions([CreateAction::make()])
            ->actions([EditAction::make(), DeleteAction::make()]);
    }
}
```

- [ ] **Step 3: FilesRelationManager**

```php
<?php
// app/Filament/Resources/Projects/ProjectResource/RelationManagers/FilesRelationManager.php
namespace App\Filament\Resources\Projects\ProjectResource\RelationManagers;

use BackedEnum;
use Filament\Forms\Components\FileUpload;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\Textarea;
use Filament\Forms\Components\TextInput;
use Filament\Resources\RelationManagers\RelationManager;
use Filament\Schemas\Schema;
use Filament\Tables\Actions\Action;
use Filament\Tables\Actions\CreateAction;
use Filament\Tables\Actions\DeleteAction;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Table;

class FilesRelationManager extends RelationManager
{
    protected static string $relationship = 'files';
    protected static ?string $title = 'Files';
    protected static BackedEnum|string|null $icon = 'heroicon-o-paper-clip';

    public function form(Schema $schema): Schema
    {
        return $schema->components([
            FileUpload::make('path')
                ->label('File')
                ->required()
                ->disk('local')
                ->afterStateUpdated(function ($state, $set) {
                    if ($state) {
                        $set('name', $state->getClientOriginalName());
                        $set('mime_type', $state->getMimeType());
                        $set('size', $state->getSize());
                    }
                }),
            TextInput::make('name')->required()->maxLength(255),
            Select::make('project_milestone_id')
                ->label('Phase')
                ->options(fn () => $this->getOwnerRecord()->milestones()->pluck('name', 'id'))
                ->nullable()
                ->placeholder('Project-level'),
            Textarea::make('notes')->nullable()->rows(2),
        ]);
    }

    protected function mutateFormDataBeforeCreate(array $data): array
    {
        $data['uploader_id']   = auth()->id();
        $data['uploader_type'] = \App\Models\User::class;
        return $data;
    }

    public function table(Table $table): Table
    {
        return $table
            ->columns([
                TextColumn::make('name')->searchable(),
                TextColumn::make('milestone.name')->label('Phase')->placeholder('Project-level'),
                TextColumn::make('size')
                    ->formatStateUsing(fn ($state) => $state ? number_format($state / 1024, 1) . ' KB' : '—'),
                TextColumn::make('created_at')->dateTime()->label('Uploaded')->sortable(),
            ])
            ->headerActions([CreateAction::make()])
            ->actions([
                Action::make('download')
                    ->icon('heroicon-o-arrow-down-tray')
                    ->url(fn ($record) => route('files.download', $record))
                    ->openUrlInNewTab(),
                DeleteAction::make(),
            ]);
    }
}
```

- [ ] **Step 4: MessagesRelationManager**

```php
<?php
// app/Filament/Resources/Projects/ProjectResource/RelationManagers/MessagesRelationManager.php
namespace App\Filament\Resources\Projects\ProjectResource\RelationManagers;

use BackedEnum;
use Filament\Forms\Components\Textarea;
use Filament\Resources\RelationManagers\RelationManager;
use Filament\Schemas\Schema;
use Filament\Tables\Actions\CreateAction;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Table;

class MessagesRelationManager extends RelationManager
{
    protected static string $relationship = 'messages';
    protected static ?string $title = 'Messages';
    protected static BackedEnum|string|null $icon = 'heroicon-o-chat-bubble-left-right';

    public function form(Schema $schema): Schema
    {
        return $schema->components([
            Textarea::make('body')->required()->rows(3)->columnSpanFull(),
        ]);
    }

    protected function mutateFormDataBeforeCreate(array $data): array
    {
        $data['sender_id']   = auth()->id();
        $data['sender_type'] = \App\Models\User::class;
        $data['created_at']  = now();
        return $data;
    }

    public function table(Table $table): Table
    {
        return $table
            ->defaultSort('created_at', 'desc')
            ->columns([
                TextColumn::make('sender.name')->label('From'),
                TextColumn::make('body')->limit(80),
                TextColumn::make('created_at')->dateTime()->label('Sent')->sortable(),
                TextColumn::make('read_at')->dateTime()->label('Read')->placeholder('Unread'),
            ])
            ->headerActions([CreateAction::make()->label('Send Message')])
            ->paginated([10, 25]);
    }
}
```

- [ ] **Step 5: ParticipantsRelationManager**

```php
<?php
// app/Filament/Resources/Projects/ProjectResource/RelationManagers/ParticipantsRelationManager.php
namespace App\Filament\Resources\Projects\ProjectResource\RelationManagers;

use App\Domain\CRM\Models\Contact;
use App\Domain\ProjectDevelopment\Enums\ParticipantSide;
use App\Models\User;
use BackedEnum;
use Filament\Forms\Components\Select;
use Filament\Resources\RelationManagers\RelationManager;
use Filament\Schemas\Schema;
use Filament\Tables\Actions\CreateAction;
use Filament\Tables\Actions\DeleteAction;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Table;

class ParticipantsRelationManager extends RelationManager
{
    protected static string $relationship = 'participants';
    protected static ?string $title = 'Participants';
    protected static BackedEnum|string|null $icon = 'heroicon-o-users';

    public function form(Schema $schema): Schema
    {
        return $schema->components([
            Select::make('side')
                ->options(collect(ParticipantSide::cases())
                    ->mapWithKeys(fn ($c) => [$c->value => $c->getLabel()]))
                ->required()
                ->live(),
            Select::make('participant_id')
                ->label('Person')
                ->options(function ($get) {
                    if ($get('side') === ParticipantSide::Internal->value) {
                        return User::orderBy('name')->pluck('name', 'id');
                    }
                    $companyId = $this->getOwnerRecord()->company_id;
                    return Contact::where('company_id', $companyId)->orderBy('name')->pluck('name', 'id');
                })
                ->searchable()
                ->required(),
        ]);
    }

    protected function mutateFormDataBeforeCreate(array $data): array
    {
        $data['participant_type'] = $data['side'] === ParticipantSide::Internal->value
            ? User::class
            : Contact::class;
        $data['created_at'] = now();
        return $data;
    }

    public function table(Table $table): Table
    {
        return $table
            ->columns([
                TextColumn::make('side')->badge()
                    ->color(fn ($state) => $state === ParticipantSide::Internal ? 'primary' : 'success'),
                TextColumn::make('participant.name')->label('Name'),
            ])
            ->headerActions([CreateAction::make()->label('Add Participant')])
            ->actions([DeleteAction::make()->label('Remove')]);
    }
}
```

- [ ] **Step 6: ActivityRelationManager**

```php
<?php
// app/Filament/Resources/Projects/ProjectResource/RelationManagers/ActivityRelationManager.php
namespace App\Filament\Resources\Projects\ProjectResource\RelationManagers;

use BackedEnum;
use Filament\Resources\RelationManagers\RelationManager;
use Filament\Schemas\Schema;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Table;

class ActivityRelationManager extends RelationManager
{
    protected static string $relationship = 'activities';
    protected static ?string $title = 'Activity Log';
    protected static BackedEnum|string|null $icon = 'heroicon-o-clock';

    public function isReadOnly(): bool { return true; }

    public function form(Schema $schema): Schema { return $schema; }

    public function table(Table $table): Table
    {
        return $table
            ->defaultSort('created_at', 'desc')
            ->columns([
                TextColumn::make('event_type')
                    ->label('Event')
                    ->badge()
                    ->formatStateUsing(fn ($state) => str_replace('.', ' › ', $state)),
                TextColumn::make('causer.name')->label('By')->placeholder('System'),
                TextColumn::make('created_at')->dateTime()->label('When')->sortable(),
            ])
            ->paginated([15, 50]);
    }
}
```

- [ ] **Step 7: Verify all tabs appear in admin**

```bash
php artisan serve
```

Navigate to Admin → Projects. Create a project, open the detail view. Verify all 6 tabs (Milestones, Expenses, Files, Messages, Participants, Activity) are visible and functional.

- [ ] **Step 8: Commit**

```bash
git add app/Filament/Resources/Projects/ProjectResource/RelationManagers/
git commit -m "feat: add all ProjectResource relation managers (milestones, expenses, files, messages, participants, activity)"
```

---

## Task 11: Register in AdminPanelProvider + Seed Default Templates

**Files:**
- Modify: `app/Providers/Filament/AdminPanelProvider.php`
- Create: `database/seeders/ProjectTemplateSeeder.php`

- [ ] **Step 1: Verify resource auto-discovery covers the Projects namespace**

Open `app/Providers/Filament/AdminPanelProvider.php`. Confirm `->discoverResources(in: app_path('Filament/Resources'), for: 'App\\Filament\\Resources')` is present. If the provider uses explicit `->resources([...])`, add both `ProjectResource::class` and `ProjectTemplateResource::class`.

- [ ] **Step 2: Create ProjectTemplateSeeder**

```php
<?php
// database/seeders/ProjectTemplateSeeder.php
namespace Database\Seeders;

use App\Domain\ProjectDevelopment\Models\MilestoneTemplate;
use App\Domain\ProjectDevelopment\Models\ProjectTemplate;
use Illuminate\Database\Seeder;

class ProjectTemplateSeeder extends Seeder
{
    public function run(): void
    {
        $templates = [
            [
                'name'        => 'Desenvolvimento Completo',
                'description' => 'Full product development from briefing to shipment.',
                'is_default'  => true,
                'milestones'  => [
                    ['name' => 'Briefing',                    'order' => 1, 'estimated_days' => 5],
                    ['name' => 'Design',                      'order' => 2, 'estimated_days' => 14],
                    ['name' => 'Desenvolvimento Fornecedores','order' => 3, 'estimated_days' => 30],
                    ['name' => 'Amostra',                     'order' => 4, 'estimated_days' => 21],
                    ['name' => 'Aprovação de Amostra',        'order' => 5, 'estimated_days' => 7],
                    ['name' => 'Produção',                    'order' => 6, 'estimated_days' => 45],
                    ['name' => 'Embalagem',                   'order' => 7, 'estimated_days' => 10],
                    ['name' => 'Envio',                       'order' => 8, 'estimated_days' => 30],
                ],
            ],
            [
                'name'        => 'Busca de Fábrica',
                'description' => 'Find and qualify a supplier.',
                'is_default'  => false,
                'milestones'  => [
                    ['name' => 'Briefing',                  'order' => 1, 'estimated_days' => 3],
                    ['name' => 'Pesquisa de Fornecedores',  'order' => 2, 'estimated_days' => 14],
                    ['name' => 'Cotações',                  'order' => 3, 'estimated_days' => 7],
                    ['name' => 'Seleção',                   'order' => 4, 'estimated_days' => 5],
                    ['name' => 'Amostra Inicial',           'order' => 5, 'estimated_days' => 21],
                ],
            ],
            [
                'name'        => 'Sourcing + Amostra',
                'description' => 'Sourcing, sampling, and approval.',
                'is_default'  => false,
                'milestones'  => [
                    ['name' => 'Briefing',   'order' => 1, 'estimated_days' => 3],
                    ['name' => 'Sourcing',   'order' => 2, 'estimated_days' => 14],
                    ['name' => 'Amostra',    'order' => 3, 'estimated_days' => 21],
                    ['name' => 'Aprovação',  'order' => 4, 'estimated_days' => 7],
                ],
            ],
        ];

        foreach ($templates as $templateData) {
            $milestones = $templateData['milestones'];
            unset($templateData['milestones']);

            $template = ProjectTemplate::firstOrCreate(['name' => $templateData['name']], $templateData);

            foreach ($milestones as $m) {
                MilestoneTemplate::firstOrCreate(
                    ['project_template_id' => $template->id, 'order' => $m['order']],
                    $m,
                );
            }
        }
    }
}
```

- [ ] **Step 3: Run the seeder**

```bash
php artisan db:seed --class=ProjectTemplateSeeder
```

Expected: 3 templates created with their phases.

- [ ] **Step 4: Run all tests to ensure nothing is broken**

```bash
php artisan test
```

Expected: all tests PASS.

- [ ] **Step 5: Commit**

```bash
git add database/seeders/ProjectTemplateSeeder.php app/Providers/Filament/AdminPanelProvider.php
git commit -m "feat: seed default project templates and register admin resources"
```

---

## Done — Plan 1 Complete

The domain layer and admin panel are now fully functional. Continue with **Plan 2** for the Client Portal and Notifications.

Run the full test suite one final time:

```bash
php artisan test
```
