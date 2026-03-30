# Project Management — Plan 2: Client Portal + Notifications

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:subagent-driven-development (recommended) or superpowers:executing-plans to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking.

**Goal:** Build the Client Portal ProjectResource (milestone progress, approval actions, file upload, messaging) and all Filament/Laravel notifications for the project management module.

**Architecture:** Portal resource scoped by `tenantOwnershipRelationshipName` to the authenticated client's company. Custom Livewire-style actions for approvals. `ProjectNotificationService` dispatches `Filament\Notifications\Notification` (database channel) for in-app badges and Laravel `Mail` notifications for email.

**Prerequisite:** Plan 1 must be fully implemented and all tests passing.

**Tech Stack:** Laravel 12, Filament v4 Portal panel, `Filament\Notifications\Notification::sendToDatabase()`, Laravel `Mailable`.

---

## File Map

### Portal
| File | Responsibility |
|---|---|
| `app/Filament/Portal/Resources/ProjectResource.php` | Portal resource — client's projects only |
| `app/Filament/Portal/Resources/ProjectResource/Pages/ListProjects.php` | Project list |
| `app/Filament/Portal/Resources/ProjectResource/Pages/ViewProject.php` | Project detail — custom layout |

### Notifications
| File | Responsibility |
|---|---|
| `app/Domain/ProjectDevelopment/Services/ProjectNotificationService.php` | Dispatches in-app + email notifications |
| `app/Mail/ProjectMilestoneApprovalRequestedMail.php` | Email: milestone sent for approval |
| `app/Mail/ProjectMessageReceivedMail.php` | Email: new message received |
| `app/Mail/ProjectMilestoneApprovedMail.php` | Email: milestone approved/rejected |

### Modified Actions (wire in notifications)
| File | Change |
|---|---|
| `app/Domain/ProjectDevelopment/Actions/SendMilestoneForApprovalAction.php` | Call notification service after status change |
| `app/Domain/ProjectDevelopment/Actions/ApproveMilestoneAction.php` | Call notification service after approval |
| `app/Domain/ProjectDevelopment/Actions/RequestMilestoneRevisionAction.php` | Call notification service after rejection |
| `app/Domain/ProjectDevelopment/Actions/SendProjectMessageAction.php` | New action: send message + notify other party |

---

## Task 1: ProjectNotificationService (in-app notifications)

**Files:**
- Create: `app/Domain/ProjectDevelopment/Services/ProjectNotificationService.php`

- [ ] **Step 1: Create ProjectNotificationService**

```php
<?php
// app/Domain/ProjectDevelopment/Services/ProjectNotificationService.php
namespace App\Domain\ProjectDevelopment\Services;

use App\Domain\ProjectDevelopment\Models\Project;
use App\Domain\ProjectDevelopment\Models\ProjectMessage;
use App\Domain\ProjectDevelopment\Models\ProjectMilestone;
use App\Models\User;
use Filament\Notifications\Actions\Action;
use Filament\Notifications\Notification;

class ProjectNotificationService
{
    /**
     * Notify all internal team members that a milestone is waiting for client approval.
     * Notify client participants that they need to take action.
     */
    public function notifyMilestoneSentForApproval(ProjectMilestone $milestone): void
    {
        $project = $milestone->project;

        // Notify client contacts (database notification only — they use Portal)
        foreach ($project->participants()->where('side', 'client')->get() as $participant) {
            $contact = $participant->participant;
            if (! $contact) continue;

            // Client notifications go to their User account if linked
            // For now we notify internal team; portal shows the action card directly
        }

        // Notify internal team
        foreach ($this->internalUsers($project) as $user) {
            Notification::make()
                ->title("Milestone sent for approval: {$milestone->name}")
                ->body("Project: {$project->title}. Waiting for client approval.")
                ->icon('heroicon-o-paper-airplane')
                ->warning()
                ->sendToDatabase($user);
        }
    }

    /**
     * Notify internal team that the client approved a milestone.
     */
    public function notifyMilestoneApproved(ProjectMilestone $milestone): void
    {
        $project = $milestone->project;

        foreach ($this->internalUsers($project) as $user) {
            Notification::make()
                ->title("Milestone approved: {$milestone->name}")
                ->body("Project: {$project->title}. Approved by client.")
                ->icon('heroicon-o-check-circle')
                ->success()
                ->sendToDatabase($user);
        }
    }

    /**
     * Notify internal team that the client requested a revision.
     */
    public function notifyMilestoneRevisionRequested(ProjectMilestone $milestone, string $notes): void
    {
        $project = $milestone->project;

        foreach ($this->internalUsers($project) as $user) {
            Notification::make()
                ->title("Revision requested: {$milestone->name}")
                ->body("Project: {$project->title}. Notes: {$notes}")
                ->icon('heroicon-o-exclamation-triangle')
                ->danger()
                ->sendToDatabase($user);
        }
    }

    /**
     * Notify the other party that a new message was sent.
     * If sender is internal User → notify client-side internal users.
     * If sender is Contact (client) → notify internal team.
     */
    public function notifyNewMessage(ProjectMessage $message): void
    {
        $project = $message->project;
        $isFromClient = $message->sender_type !== User::class;

        if ($isFromClient) {
            // Notify internal team
            foreach ($this->internalUsers($project) as $user) {
                Notification::make()
                    ->title("New message on: {$project->title}")
                    ->body(str($message->body)->limit(100))
                    ->icon('heroicon-o-chat-bubble-left')
                    ->info()
                    ->sendToDatabase($user);
            }
        }
        // Client-side notifications via portal's unread badge (read_at field)
    }

    /**
     * Notify all participants that a file was uploaded.
     */
    public function notifyFileUploaded(string $fileName, Project $project): void
    {
        foreach ($this->internalUsers($project) as $user) {
            Notification::make()
                ->title("File uploaded: {$fileName}")
                ->body("Project: {$project->title}")
                ->icon('heroicon-o-paper-clip')
                ->info()
                ->sendToDatabase($user);
        }
    }

    /** @return User[] */
    private function internalUsers(Project $project): array
    {
        $users = [];

        // Always include the project creator
        if ($project->creator) {
            $users[$project->created_by] = $project->creator;
        }

        // Include internal participants
        foreach ($project->participants()->where('side', 'internal')->get() as $participant) {
            $user = $participant->participant;
            if ($user instanceof User) {
                $users[$user->id] = $user;
            }
        }

        return array_values($users);
    }
}
```

- [ ] **Step 2: Wire notifications into SendMilestoneForApprovalAction**

Open `app/Domain/ProjectDevelopment/Actions/SendMilestoneForApprovalAction.php`. Add the notification call:

```php
<?php
// app/Domain/ProjectDevelopment/Actions/SendMilestoneForApprovalAction.php
namespace App\Domain\ProjectDevelopment\Actions;

use App\Domain\ProjectDevelopment\Enums\MilestoneStatus;
use App\Domain\ProjectDevelopment\Models\ProjectMilestone;
use App\Domain\ProjectDevelopment\Services\ProjectNotificationService;

class SendMilestoneForApprovalAction
{
    public function __construct(
        private RecordProjectActivityAction $recordActivity = new RecordProjectActivityAction(),
        private ProjectNotificationService $notifications = new ProjectNotificationService(),
    ) {}

    public function execute(ProjectMilestone $milestone): void
    {
        $milestone->update(['status' => MilestoneStatus::WaitingApproval]);

        $this->recordActivity->execute(
            $milestone->project,
            'milestone.sent_for_approval',
            ['milestone_id' => $milestone->id, 'name' => $milestone->name],
        );

        $this->notifications->notifyMilestoneSentForApproval($milestone);
    }
}
```

- [ ] **Step 3: Wire notifications into ApproveMilestoneAction**

```php
<?php
// app/Domain/ProjectDevelopment/Actions/ApproveMilestoneAction.php
namespace App\Domain\ProjectDevelopment\Actions;

use App\Domain\ProjectDevelopment\Enums\MilestoneStatus;
use App\Domain\ProjectDevelopment\Models\ProjectMilestone;
use App\Domain\ProjectDevelopment\Services\ProjectNotificationService;

class ApproveMilestoneAction
{
    public function __construct(
        private RecordProjectActivityAction $recordActivity = new RecordProjectActivityAction(),
        private ProjectNotificationService $notifications = new ProjectNotificationService(),
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

        $this->notifications->notifyMilestoneApproved($milestone);
    }
}
```

- [ ] **Step 4: Wire notifications into RequestMilestoneRevisionAction**

```php
<?php
// app/Domain/ProjectDevelopment/Actions/RequestMilestoneRevisionAction.php
namespace App\Domain\ProjectDevelopment\Actions;

use App\Domain\ProjectDevelopment\Enums\MilestoneStatus;
use App\Domain\ProjectDevelopment\Models\ProjectMilestone;
use App\Domain\ProjectDevelopment\Services\ProjectNotificationService;

class RequestMilestoneRevisionAction
{
    public function __construct(
        private RecordProjectActivityAction $recordActivity = new RecordProjectActivityAction(),
        private ProjectNotificationService $notifications = new ProjectNotificationService(),
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

        $this->notifications->notifyMilestoneRevisionRequested($milestone, $notes);
    }
}
```

- [ ] **Step 5: Run existing tests to confirm they still pass**

```bash
php artisan test tests/Feature/ProjectDevelopment/MilestoneLifecycleTest.php
```

Expected: 4 tests PASS (notification calls don't affect logic).

- [ ] **Step 6: Commit**

```bash
git add app/Domain/ProjectDevelopment/Services/ProjectNotificationService.php \
        app/Domain/ProjectDevelopment/Actions/SendMilestoneForApprovalAction.php \
        app/Domain/ProjectDevelopment/Actions/ApproveMilestoneAction.php \
        app/Domain/ProjectDevelopment/Actions/RequestMilestoneRevisionAction.php
git commit -m "feat: add ProjectNotificationService and wire in-app notifications"
```

---

## Task 2: SendProjectMessageAction

**Files:**
- Create: `app/Domain/ProjectDevelopment/Actions/SendProjectMessageAction.php`

- [ ] **Step 1: Create SendProjectMessageAction**

```php
<?php
// app/Domain/ProjectDevelopment/Actions/SendProjectMessageAction.php
namespace App\Domain\ProjectDevelopment\Actions;

use App\Domain\ProjectDevelopment\Models\Project;
use App\Domain\ProjectDevelopment\Models\ProjectMessage;
use App\Domain\ProjectDevelopment\Services\ProjectNotificationService;

class SendProjectMessageAction
{
    public function __construct(
        private RecordProjectActivityAction $recordActivity = new RecordProjectActivityAction(),
        private ProjectNotificationService $notifications = new ProjectNotificationService(),
    ) {}

    public function execute(Project $project, string $body, mixed $sender): ProjectMessage
    {
        $message = ProjectMessage::create([
            'project_id'  => $project->id,
            'sender_id'   => $sender->id,
            'sender_type' => get_class($sender),
            'body'        => $body,
            'created_at'  => now(),
        ]);

        $this->recordActivity->execute(
            $project,
            'message.sent',
            ['sender' => $sender->name ?? 'Unknown'],
            $sender,
        );

        $this->notifications->notifyNewMessage($message);

        return $message;
    }
}
```

- [ ] **Step 2: Commit**

```bash
git add app/Domain/ProjectDevelopment/Actions/SendProjectMessageAction.php
git commit -m "feat: add SendProjectMessageAction with notification"
```

---

## Task 3: Email Mailables

**Files:**
- Create: `app/Mail/ProjectMilestoneApprovalRequestedMail.php`
- Create: `app/Mail/ProjectMilestoneApprovedMail.php`
- Create: `app/Mail/ProjectMessageReceivedMail.php`
- Create: `resources/views/emails/project-milestone-approval-requested.blade.php`
- Create: `resources/views/emails/project-milestone-approved.blade.php`
- Create: `resources/views/emails/project-message-received.blade.php`

- [ ] **Step 1: Create milestone approval email**

```php
<?php
// app/Mail/ProjectMilestoneApprovalRequestedMail.php
namespace App\Mail;

use App\Domain\ProjectDevelopment\Models\ProjectMilestone;
use Illuminate\Bus\Queueable;
use Illuminate\Mail\Mailable;
use Illuminate\Mail\Mailables\Content;
use Illuminate\Mail\Mailables\Envelope;
use Illuminate\Queue\SerializesModels;

class ProjectMilestoneApprovalRequestedMail extends Mailable
{
    use Queueable, SerializesModels;

    public function __construct(public ProjectMilestone $milestone) {}

    public function envelope(): Envelope
    {
        return new Envelope(
            subject: "Action Required: Approve milestone "{$this->milestone->name}" — {$this->milestone->project->title}",
        );
    }

    public function content(): Content
    {
        return new Content(
            view: 'emails.project-milestone-approval-requested',
        );
    }
}
```

```blade
{{-- resources/views/emails/project-milestone-approval-requested.blade.php --}}
<p>Dear Client,</p>
<p>The milestone <strong>{{ $milestone->name }}</strong> on project <strong>{{ $milestone->project->title }}</strong> is ready for your review and approval.</p>
<p>Please log in to your portal to review and approve or request a revision.</p>
<p>Thank you.</p>
```

- [ ] **Step 2: Create milestone approved/rejected email**

```php
<?php
// app/Mail/ProjectMilestoneApprovedMail.php
namespace App\Mail;

use App\Domain\ProjectDevelopment\Models\ProjectMilestone;
use Illuminate\Bus\Queueable;
use Illuminate\Mail\Mailable;
use Illuminate\Mail\Mailables\Content;
use Illuminate\Mail\Mailables\Envelope;
use Illuminate\Queue\SerializesModels;

class ProjectMilestoneApprovedMail extends Mailable
{
    use Queueable, SerializesModels;

    public function __construct(
        public ProjectMilestone $milestone,
        public bool $approved,
        public ?string $notes = null,
    ) {}

    public function envelope(): Envelope
    {
        $verb = $this->approved ? 'Approved' : 'Revision Requested';
        return new Envelope(
            subject: "{$verb}: {$this->milestone->name} — {$this->milestone->project->title}",
        );
    }

    public function content(): Content
    {
        return new Content(view: 'emails.project-milestone-approved');
    }
}
```

```blade
{{-- resources/views/emails/project-milestone-approved.blade.php --}}
<p>The milestone <strong>{{ $milestone->name }}</strong> on project <strong>{{ $milestone->project->title }}</strong>
@if($approved)
    has been <strong>approved</strong> by the client.
@else
    has a <strong>revision requested</strong> by the client.
    @if($notes)<br>Notes: {{ $notes }}@endif
@endif
</p>
```

- [ ] **Step 3: Create new message email**

```php
<?php
// app/Mail/ProjectMessageReceivedMail.php
namespace App\Mail;

use App\Domain\ProjectDevelopment\Models\ProjectMessage;
use Illuminate\Bus\Queueable;
use Illuminate\Mail\Mailable;
use Illuminate\Mail\Mailables\Content;
use Illuminate\Mail\Mailables\Envelope;
use Illuminate\Queue\SerializesModels;

class ProjectMessageReceivedMail extends Mailable
{
    use Queueable, SerializesModels;

    public function __construct(public ProjectMessage $message) {}

    public function envelope(): Envelope
    {
        return new Envelope(
            subject: "New message on project: {$this->message->project->title}",
        );
    }

    public function content(): Content
    {
        return new Content(view: 'emails.project-message-received');
    }
}
```

```blade
{{-- resources/views/emails/project-message-received.blade.php --}}
<p>A new message was sent on project <strong>{{ $message->project->title }}</strong>:</p>
<blockquote>{{ $message->body }}</blockquote>
<p>Please log in to reply.</p>
```

- [ ] **Step 4: Wire emails into ProjectNotificationService**

Open `app/Domain/ProjectDevelopment/Services/ProjectNotificationService.php`. Add email dispatch to the relevant methods:

In `notifyMilestoneSentForApproval()` — add after the internal team loop:

```php
// Send email to client contacts that have an email
foreach ($project->participants()->where('side', 'client')->get() as $participant) {
    $contact = $participant->participant;
    if ($contact && $contact->email) {
        \Illuminate\Support\Facades\Mail::to($contact->email)->send(
            new \App\Mail\ProjectMilestoneApprovalRequestedMail($milestone)
        );
    }
}
```

In `notifyMilestoneApproved()` — add after the internal team loop:

```php
// Send email to internal team
foreach ($this->internalUsers($project) as $user) {
    \Illuminate\Support\Facades\Mail::to($user->email)->send(
        new \App\Mail\ProjectMilestoneApprovedMail($milestone, approved: true)
    );
}
```

In `notifyMilestoneRevisionRequested()` — add after the internal team loop:

```php
foreach ($this->internalUsers($project) as $user) {
    \Illuminate\Support\Facades\Mail::to($user->email)->send(
        new \App\Mail\ProjectMilestoneApprovedMail($milestone, approved: false, notes: $notes)
    );
}
```

In `notifyNewMessage()` — add in the `$isFromClient` branch:

```php
foreach ($this->internalUsers($project) as $user) {
    \Illuminate\Support\Facades\Mail::to($user->email)->send(
        new \App\Mail\ProjectMessageReceivedMail($message)
    );
}
```

- [ ] **Step 5: Run tests**

```bash
php artisan test tests/Feature/ProjectDevelopment/
```

Expected: all tests PASS.

- [ ] **Step 6: Commit**

```bash
git add app/Mail/ resources/views/emails/ \
        app/Domain/ProjectDevelopment/Services/ProjectNotificationService.php
git commit -m "feat: add email notifications for milestones and messages"
```

---

## Task 4: Portal ProjectResource — List + View

**Files:**
- Create: `app/Filament/Portal/Resources/ProjectResource.php`
- Create: `app/Filament/Portal/Resources/ProjectResource/Pages/ListProjects.php`
- Create: `app/Filament/Portal/Resources/ProjectResource/Pages/ViewProject.php`

- [ ] **Step 1: Create portal ListProjects page**

```php
<?php
// app/Filament/Portal/Resources/ProjectResource/Pages/ListProjects.php
namespace App\Filament\Portal\Resources\ProjectResource\Pages;

use App\Filament\Portal\Resources\ProjectResource;
use Filament\Resources\Pages\ListRecords;

class ListProjects extends ListRecords
{
    protected static string $resource = ProjectResource::class;
}
```

- [ ] **Step 2: Create portal ViewProject page**

This page renders the custom layout: milestone cards, action card, activity feed, files, messages panel.

```php
<?php
// app/Filament/Portal/Resources/ProjectResource/Pages/ViewProject.php
namespace App\Filament\Portal\Resources\ProjectResource\Pages;

use App\Domain\ProjectDevelopment\Actions\ApproveMilestoneAction;
use App\Domain\ProjectDevelopment\Actions\RequestMilestoneRevisionAction;
use App\Domain\ProjectDevelopment\Actions\SendProjectMessageAction;
use App\Domain\ProjectDevelopment\Enums\MilestoneStatus;
use App\Domain\ProjectDevelopment\Models\ProjectMilestone;
use App\Domain\ProjectDevelopment\Services\ProjectBudgetService;
use App\Filament\Portal\Resources\ProjectResource;
use Filament\Actions\Action;
use Filament\Infolists\Components\TextEntry;
use Filament\Notifications\Notification;
use Filament\Resources\Pages\ViewRecord;
use Filament\Schemas\Schema;
use Filament\Schemas\Components\Section;

class ViewProject extends ViewRecord
{
    protected static string $resource = ProjectResource::class;

    protected function getHeaderActions(): array
    {
        return [
            Action::make('send_message')
                ->label('Send Message')
                ->icon('heroicon-o-chat-bubble-left')
                ->form([
                    \Filament\Forms\Components\Textarea::make('body')
                        ->label('Message')
                        ->required()
                        ->rows(3),
                ])
                ->action(function (array $data): void {
                    (new SendProjectMessageAction())->execute(
                        $this->record,
                        $data['body'],
                        auth()->user(),
                    );
                    Notification::make()->title('Message sent')->success()->send();
                }),
        ];
    }

    protected function getFooterWidgets(): array { return []; }

    public function infolist(Schema $schema): Schema
    {
        $record  = $this->record;
        $budget  = (new ProjectBudgetService());

        return $schema->components([

            Section::make('Progress')->schema([
                TextEntry::make('status')->badge(),
                TextEntry::make('deadline_at')->date()->label('Deadline'),
                TextEntry::make('budget')
                    ->label('Budget')
                    ->formatStateUsing(fn ($state) => 'USD ' . number_format($state / 10000, 2)),
            ])->columns(3),

            Section::make('Milestones')->schema(
                $record->milestones->map(function (ProjectMilestone $milestone) use ($record): \Filament\Schemas\Components\Component {
                    $isPending  = $milestone->status === MilestoneStatus::WaitingApproval;
                    $isApproved = $milestone->status === MilestoneStatus::Approved;

                    $entries = [
                        TextEntry::make("milestone_{$milestone->id}_name")
                            ->label('')
                            ->state($milestone->name),
                        TextEntry::make("milestone_{$milestone->id}_status")
                            ->label('Status')
                            ->state($milestone->status->getLabel())
                            ->badge()
                            ->color($milestone->status->getColor()),
                    ];

                    return Section::make($milestone->name)
                        ->schema($entries)
                        ->collapsible()
                        ->collapsed(! $isPending);
                })->toArray()
            ),

            Section::make('Recent Activity')->schema([
                TextEntry::make('activities_summary')
                    ->label('')
                    ->state(fn () => $record->activities()
                        ->latest('created_at')
                        ->limit(5)
                        ->get()
                        ->map(fn ($a) => "{$a->created_at->diffForHumans()} — " . str_replace('.', ' › ', $a->event_type))
                        ->join("\n")
                    )
                    ->columnSpanFull(),
            ]),

        ]);
    }
}
```

- [ ] **Step 3: Create portal ProjectResource**

```php
<?php
// app/Filament/Portal/Resources/ProjectResource.php
namespace App\Filament\Portal\Resources;

use App\Domain\ProjectDevelopment\Models\Project;
use App\Filament\Portal\Resources\ProjectResource\Pages\ListProjects;
use App\Filament\Portal\Resources\ProjectResource\Pages\ViewProject;
use BackedEnum;
use Filament\Resources\Resource;
use Filament\Schemas\Schema;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Table;

class ProjectResource extends Resource
{
    protected static ?string $model = Project::class;
    protected static BackedEnum|string|null $navigationIcon = 'heroicon-o-briefcase';
    protected static ?int $navigationSort = 30;
    protected static ?string $slug = 'projects';
    protected static ?string $recordTitleAttribute = 'title';
    protected static ?string $tenantOwnershipRelationshipName = 'company';

    public static function canCreate(): bool  { return false; }
    public static function canEdit($record): bool { return false; }
    public static function canDelete($record): bool { return false; }

    public static function canAccess(): bool
    {
        return auth()->user()?->can('portal:view-projects') ?? false;
    }

    public static function table(Table $table): Table
    {
        return $table
            ->columns([
                TextColumn::make('title')->searchable()->sortable()->weight('bold'),
                TextColumn::make('status')->badge()
                    ->color(fn ($state) => $state->getColor()),
                TextColumn::make('milestones_count')
                    ->counts('milestones')
                    ->label('Phases'),
                TextColumn::make('deadline_at')->date()->label('Deadline')->sortable(),
                TextColumn::make('updated_at')->dateTime()->label('Last update')->sortable(),
            ])
            ->defaultSort('updated_at', 'desc');
    }

    public static function getPages(): array
    {
        return [
            'index' => ListProjects::route('/'),
            'view'  => ViewProject::route('/{record}'),
        ];
    }
}
```

- [ ] **Step 4: Register in PortalPanelProvider**

Open `app/Providers/Filament/PortalPanelProvider.php`. Ensure `discoverResources` covers `app/Filament/Portal/Resources` — if already present it will auto-discover. If explicit, add:

```php
->resources([
    // ... existing resources ...
    \App\Filament\Portal\Resources\ProjectResource::class,
])
```

- [ ] **Step 5: Add permission gate**

Open `app/Providers/Filament/PortalPanelProvider.php` or the relevant `AuthServiceProvider`. Add the portal permission check:

```php
\Illuminate\Support\Facades\Gate::define('portal:view-projects', function ($user) {
    return true; // Scope by company tenant — all portal users can see their projects
});
```

- [ ] **Step 6: Verify in browser**

```bash
php artisan serve
```

Log in as a client portal user. Navigate to Projects. Open a project. Verify:
- Milestone cards show correct statuses
- "Send Message" button in header works
- Activity section shows recent events
- Budget is displayed

- [ ] **Step 7: Commit**

```bash
git add app/Filament/Portal/Resources/ProjectResource.php \
        app/Filament/Portal/Resources/ProjectResource/ \
        app/Providers/Filament/PortalPanelProvider.php
git commit -m "feat: add portal ProjectResource with milestone view and messaging"
```

---

## Task 5: Portal Approval Actions

**Files:**
- Modify: `app/Filament/Portal/Resources/ProjectResource/Pages/ViewProject.php`

- [ ] **Step 1: Add Approve and Request Revision actions to ViewProject header**

Open `app/Filament/Portal/Resources/ProjectResource/Pages/ViewProject.php`. Replace `getHeaderActions()` with the following — it includes approval actions for milestones currently in `WaitingApproval` state:

```php
protected function getHeaderActions(): array
{
    $pendingMilestone = $this->record->milestones()
        ->where('status', MilestoneStatus::WaitingApproval->value)
        ->first();

    $actions = [
        Action::make('send_message')
            ->label('Send Message')
            ->icon('heroicon-o-chat-bubble-left')
            ->form([
                \Filament\Forms\Components\Textarea::make('body')
                    ->label('Message')
                    ->required()
                    ->rows(3),
            ])
            ->action(function (array $data): void {
                (new SendProjectMessageAction())->execute(
                    $this->record,
                    $data['body'],
                    auth()->user(),
                );
                Notification::make()->title('Message sent')->success()->send();
            }),
    ];

    if ($pendingMilestone) {
        $actions[] = Action::make('approve_milestone')
            ->label("Approve: {$pendingMilestone->name}")
            ->icon('heroicon-o-check-circle')
            ->color('success')
            ->form([
                \Filament\Forms\Components\Textarea::make('notes')
                    ->label('Notes (optional)')
                    ->rows(2),
            ])
            ->action(function (array $data) use ($pendingMilestone): void {
                (new ApproveMilestoneAction())->execute($pendingMilestone, $data['notes'] ?? null);
                Notification::make()->title('Milestone approved')->success()->send();
                $this->refreshFormData([]);
            });

        $actions[] = Action::make('request_revision')
            ->label('Request Revision')
            ->icon('heroicon-o-arrow-path')
            ->color('danger')
            ->form([
                \Filament\Forms\Components\Textarea::make('notes')
                    ->label('Please describe what needs to be changed')
                    ->required()
                    ->rows(3),
            ])
            ->action(function (array $data) use ($pendingMilestone): void {
                (new RequestMilestoneRevisionAction())->execute($pendingMilestone, $data['notes']);
                Notification::make()->title('Revision requested')->warning()->send();
                $this->refreshFormData([]);
            });
    }

    return $actions;
}
```

- [ ] **Step 2: Verify approval flow end-to-end**

```bash
php artisan serve
```

1. In admin, create a project with a milestone and set its status to "In Progress"
2. In admin, click "Send for Approval" on the milestone
3. Log in as portal user
4. Open the project — confirm the Approve / Request Revision buttons appear
5. Click Approve — confirm milestone status changes to Approved
6. Confirm in-app notification appears for internal team (check the bell icon in admin)

- [ ] **Step 3: Commit**

```bash
git add app/Filament/Portal/Resources/ProjectResource/Pages/ViewProject.php
git commit -m "feat: add portal approval and revision request actions"
```

---

## Task 6: Portal File Upload

**Files:**
- Modify: `app/Filament/Portal/Resources/ProjectResource/Pages/ViewProject.php`

- [ ] **Step 1: Add file upload action to portal header**

Open `app/Filament/Portal/Resources/ProjectResource/Pages/ViewProject.php`. Add to `getHeaderActions()`:

```php
Action::make('upload_file')
    ->label('Upload File')
    ->icon('heroicon-o-paper-clip')
    ->form([
        \Filament\Forms\Components\FileUpload::make('path')
            ->label('File')
            ->required()
            ->disk('local'),
        \Filament\Forms\Components\TextInput::make('name')
            ->label('File name')
            ->maxLength(255),
        \Filament\Forms\Components\Select::make('project_milestone_id')
            ->label('Phase (optional)')
            ->options(fn () => $this->record->milestones()->pluck('name', 'id'))
            ->nullable(),
        \Filament\Forms\Components\Textarea::make('notes')
            ->label('Notes')
            ->rows(2)
            ->nullable(),
    ])
    ->action(function (array $data): void {
        $uploadedFile = $data['path'];

        \App\Domain\ProjectDevelopment\Models\ProjectFile::create([
            'project_id'          => $this->record->id,
            'project_milestone_id'=> $data['project_milestone_id'] ?? null,
            'name'                => $data['name'] ?: ($uploadedFile instanceof \Illuminate\Http\UploadedFile ? $uploadedFile->getClientOriginalName() : 'file'),
            'path'                => $uploadedFile,
            'mime_type'           => $uploadedFile instanceof \Illuminate\Http\UploadedFile ? $uploadedFile->getMimeType() : null,
            'size'                => $uploadedFile instanceof \Illuminate\Http\UploadedFile ? $uploadedFile->getSize() : null,
            'uploader_id'         => auth()->id(),
            'uploader_type'       => \App\Models\User::class,
            'notes'               => $data['notes'] ?? null,
        ]);

        \App\Domain\ProjectDevelopment\Actions\RecordProjectActivityAction::new()->execute(
            $this->record,
            'file.uploaded',
            ['name' => $data['name'] ?? 'file'],
        );

        (new \App\Domain\ProjectDevelopment\Services\ProjectNotificationService())
            ->notifyFileUploaded($data['name'] ?? 'file', $this->record);

        Notification::make()->title('File uploaded')->success()->send();
    }),
```

> **Note:** `RecordProjectActivityAction::new()` requires adding a static `new()` factory. Add this to `RecordProjectActivityAction`:
>
> ```php
> public static function new(): static { return new static(); }
> ```

- [ ] **Step 2: Commit**

```bash
git add app/Filament/Portal/Resources/ProjectResource/Pages/ViewProject.php \
        app/Domain/ProjectDevelopment/Actions/RecordProjectActivityAction.php
git commit -m "feat: add portal file upload with activity recording"
```

---

## Task 7: Unread Message Badge

**Files:**
- Modify: `app/Filament/Portal/Resources/ProjectResource/Pages/ViewProject.php`

The messages panel shows a badge for unread messages. A message is "unread for the client" when `read_at` is null and the sender is internal (User). Mark messages as read when the client opens the project.

- [ ] **Step 1: Mark messages as read on page load**

Open `app/Filament/Portal/Resources/ProjectResource/Pages/ViewProject.php`. Override `mount()`:

```php
public function mount(int|string $record): void
{
    parent::mount($record);

    // Mark all unread internal messages as read for the client
    $this->record->messages()
        ->where('sender_type', \App\Models\User::class)
        ->whereNull('read_at')
        ->update(['read_at' => now()]);
}
```

- [ ] **Step 2: Show unread count on project list table**

Open `app/Filament/Portal/Resources/ProjectResource.php`. Add a column to the table:

```php
TextColumn::make('unread_messages_count')
    ->label('Unread')
    ->state(fn ($record) => $record->messages()
        ->where('sender_type', \App\Models\User::class)
        ->whereNull('read_at')
        ->count()
    )
    ->badge()
    ->color('danger'),
```

- [ ] **Step 3: Commit**

```bash
git add app/Filament/Portal/Resources/ProjectResource.php \
        app/Filament/Portal/Resources/ProjectResource/Pages/ViewProject.php
git commit -m "feat: add unread message badge and auto-mark as read"
```

---

## Task 8: Final Integration Check

- [ ] **Step 1: Run full test suite**

```bash
php artisan test
```

Expected: all tests PASS.

- [ ] **Step 2: End-to-end manual verification checklist**

In the admin:
- [ ] Create a project template with 3 phases
- [ ] Create a project for a client company, using the template
- [ ] Confirm 3 milestones were auto-created
- [ ] Change a milestone status to InProgress, then click "Send for Approval"
- [ ] Add a planned expense and an actual expense
- [ ] Upload a file
- [ ] Add a participant (internal user + client contact)
- [ ] Send a message from admin
- [ ] Confirm activity log shows all events

In the portal:
- [ ] Log in as client portal user
- [ ] See the project in the list with unread message badge
- [ ] Open project — confirm milestone cards show correct statuses
- [ ] See the "Approve" and "Request Revision" buttons for the pending milestone
- [ ] Approve the milestone — confirm status updates, notification appears in admin
- [ ] Upload a file from the portal
- [ ] Send a message — confirm internal team receives in-app notification

- [ ] **Step 3: Final commit**

```bash
git add .
git commit -m "feat: complete project management module — portal, notifications, end-to-end"
```

---

## Done — Plan 2 Complete

The full Project Management module is now implemented:

| Layer | Status |
|---|---|
| Domain models + migrations | ✅ Plan 1 |
| Enums + DTOs + Actions | ✅ Plan 1 |
| Budget service | ✅ Plan 1 |
| Admin panel (all 6 tabs) | ✅ Plan 1 |
| Project templates + seeder | ✅ Plan 1 |
| Portal — list + view | ✅ Plan 2 |
| Portal — approval actions | ✅ Plan 2 |
| Portal — file upload | ✅ Plan 2 |
| Portal — unread badges | ✅ Plan 2 |
| In-app notifications (Filament) | ✅ Plan 2 |
| Email notifications | ✅ Plan 2 |
