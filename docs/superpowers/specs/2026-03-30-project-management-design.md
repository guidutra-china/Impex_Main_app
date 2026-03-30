# Project Management Module — Design Spec

**Date:** 2026-03-30
**Status:** Approved
**Context:** Impex — Laravel + Filament v3, DDD architecture

---

## 1. Overview

A project in Impex represents the **full product development lifecycle for a client** — from finding a factory, through design, component supplier development, sampling, assembly, packaging, and shipping.

Projects are linked to a **client** (Company in CRM) and optionally to a **product** in the catalog (or will create one at the end of the development cycle).

The module must be visible in both the **Admin Panel** (full management) and the **Client Portal** (progress tracking, approvals, communication).

---

## 2. Bounded Context

**Location:** `app/Domain/ProjectDevelopment/`

```
app/Domain/ProjectDevelopment/
├── Models/
│   ├── Project.php               ← aggregate root
│   ├── ProjectTemplate.php       ← reusable milestone templates
│   ├── MilestoneTemplate.php     ← phases within a template
│   ├── ProjectMilestone.php      ← actual phases of a project
│   ├── MilestoneTask.php         ← tasks within a phase
│   ├── ProjectExpense.php        ← planned and actual expenses
│   ├── ProjectFile.php           ← attachments per project or phase
│   ├── ProjectActivity.php       ← event log (event sourcing)
│   ├── ProjectMessage.php        ← client/internal chat thread
│   └── ProjectParticipant.php    ← pivot: who is involved
├── Actions/
│   ├── CreateProjectAction.php
│   ├── ApproveMilestoneAction.php
│   ├── RequestMilestoneRevisionAction.php
│   ├── SendMilestoneForApprovalAction.php
│   ├── AddProjectExpenseAction.php
│   ├── SendProjectMessageAction.php
│   └── RecordProjectActivityAction.php
├── Enums/
│   ├── ProjectStatus.php
│   ├── MilestoneStatus.php
│   ├── ExpenseType.php
│   └── ParticipantSide.php
├── DataTransferObjects/
│   ├── CreateProjectData.php
│   ├── CreateMilestoneData.php
│   └── CreateExpenseData.php
└── Services/
    ├── ProjectBudgetService.php   ← computes totals, deviations
    └── ProjectNotificationService.php
```

---

## 3. Data Models

### 3.1 Project (Aggregate Root)

```
projects
  id
  company_id          → companies (CRM) — required
  product_id          → products (Catalog) — nullable
  project_template_id → project_templates — nullable
  title               string
  description         text nullable
  status              enum: Draft|Active|OnHold|Completed|Cancelled
  budget              decimal(15,4)
  currency            string(3) default 'USD'
  started_at          date nullable
  deadline_at         date nullable
  completed_at        date nullable
  created_by          → users
  timestamps
```

Relationships: `milestones`, `expenses`, `files`, `activities`, `messages`, `participants`, `company`, `product`, `template`.

### 3.2 ProjectTemplate

```
project_templates
  id
  name                string
  description         text nullable
  is_default          boolean default false
  timestamps
```

### 3.3 MilestoneTemplate

```
milestone_templates
  id
  project_template_id → project_templates
  name                string
  description         text nullable
  order               integer
  estimated_days      integer nullable
  timestamps
```

### 3.4 ProjectMilestone

```
project_milestones
  id
  project_id          → projects
  milestone_template_id → milestone_templates — nullable
  name                string
  description         text nullable
  order               integer
  status              enum: Pending|InProgress|WaitingApproval|Approved|Rejected|Skipped
  budget              decimal(15,4) nullable   ← phase budget
  due_at              date nullable
  completed_at        date nullable
  approved_by         → users — nullable
  approved_at         datetime nullable
  client_notes        text nullable            ← filled by client on approval/revision
  timestamps
```

### 3.5 MilestoneTask

```
milestone_tasks
  id
  project_milestone_id → project_milestones
  title               string
  description         text nullable
  status              enum: Pending|InProgress|Completed
  assigned_to         → users — nullable
  due_at              date nullable
  completed_at        datetime nullable
  timestamps
```

### 3.6 ProjectExpense

```
project_expenses
  id
  project_id          → projects
  project_milestone_id → project_milestones — nullable
  category            enum: Design|Tooling|Sample|Testing|Logistics|Other
  description         string
  type                enum: Planned|Actual
  amount              decimal(15,4)
  currency            string(3) default 'USD'
  date                date
  receipt_path        string nullable
  created_by          → users
  timestamps
```

### 3.7 ProjectFile

```
project_files
  id
  project_id          → projects
  project_milestone_id → project_milestones — nullable
  name                string
  path                string
  mime_type           string nullable
  size                integer nullable        ← bytes
  uploader_id         unsignedBigInteger
  uploader_type       string                  ← polymorphic: User (internal) | Contact (client)
  notes               text nullable
  timestamps
```

### 3.8 ProjectActivity (Event Log)

```
project_activities
  id
  project_id          → projects
  event_type          string                  ← see Section 5
  payload             json                    ← event data
  causer_id           → users — nullable
  causer_type         string nullable         ← polymorphic
  created_at          datetime
```

No `updated_at` — activity records are immutable.

### 3.9 ProjectMessage

```
project_messages
  id
  project_id          → projects
  sender_id           unsignedBigInteger
  sender_type         string                  ← App\Domain\Users\Models\User | App\Domain\CRM\Models\Contact
  body                text
  read_at             datetime nullable
  created_at          datetime
```

### 3.10 ProjectParticipant (Pivot)

```
project_participants
  id
  project_id          → projects
  participant_id      unsignedBigInteger
  participant_type    string                  ← User (internal) | Contact (client)
  side                enum: Internal|Client
  created_at          datetime
```

---

## 4. Enums

### ProjectStatus
`Draft` → `Active` → `OnHold` ↔ `Active` → `Completed` | `Cancelled`

### MilestoneStatus
`Pending` → `InProgress` → `WaitingApproval` → `Approved` | (`Rejected` → `InProgress`) | `Skipped`

### ExpenseType
`Planned` | `Actual`

### ParticipantSide
`Internal` | `Client`

---

## 5. Event Sourcing

All state changes record an immutable entry in `project_activities`. The activity log powers the portal's activity feed.

| Event Type | Trigger |
|---|---|
| `project.created` | Project created |
| `project.status_changed` | Status transitions |
| `project.completed` | Project marked complete |
| `milestone.started` | Milestone moved to InProgress |
| `milestone.sent_for_approval` | Sent to client for approval |
| `milestone.approved` | Client approves milestone |
| `milestone.revision_requested` | Client requests revision |
| `expense.added` | New expense entry |
| `file.uploaded` | File attached |
| `message.sent` | Chat message sent |
| `participant.added` | Person added to project |
| `participant.removed` | Person removed from project |

---

## 6. Filament Resources

### 6.1 Admin Panel (`app/Filament/Resources/`)

**`ProjectResource`** — full CRUD + management view

Detail page uses tabbed layout (Filament native RelationManagers):

| Tab | Content |
|---|---|
| Visão Geral | Stats (budget, spent, deadline, status), milestone progress bars, recent activity |
| Milestones | RelationManager — create/edit phases, change status, assign budget |
| Gastos | RelationManager — planned vs actual table, totals per category |
| Arquivos | RelationManager — upload, download, link to milestone |
| Mensagens | RelationManager — chat thread, send on behalf of team |
| Participantes | RelationManager — add/remove Users and Contacts |
| Atividade | Read-only log from `project_activities` |

**`ProjectTemplateResource`** — manage templates and their milestone sets

### 6.2 Client Portal (`app/Filament/Portal/Resources/`)

**`ProjectResource`** — scoped to the authenticated client's company

Layout:
- Stats bar (overall progress, budget utilization, deadline)
- Milestone cards grid with status indicators
- Highlighted action card when a milestone awaits the client's approval (Approve / Request Revision buttons)
- Two-column lower section: Activity feed (left) + Files list with upload (right)
- Right-side messages panel — threaded chat with the Impex team, unread badge on project header

Client actions:
- Approve a milestone (with optional note)
- Request revision on a milestone (note required)
- Upload files
- Send and receive messages

---

## 7. Notifications

Implemented via **Laravel Notifications** with two channels: `mail` and `database`.

| Event | Who is notified | Channels |
|---|---|---|
| New message received | The other party (Impex team or client) | mail + database |
| Milestone sent for approval | Client participants | mail + database |
| Milestone approved | Impex team (created_by + internal participants) | mail + database |
| Milestone revision requested | Impex team | mail + database |
| New file uploaded | All participants | database only |

**In-app badge:** unread `database` notifications surface as a bell/badge in both Admin and Portal headers, using Filament's native notification panel.

---

## 8. Budget Service

`ProjectBudgetService` computes on demand (no denormalized totals):

- `totalPlanned(Project)` — sum of `Planned` expenses
- `totalActual(Project)` — sum of `Actual` expenses
- `totalPlannedForMilestone(ProjectMilestone)` — scoped to phase
- `totalActualForMilestone(ProjectMilestone)` — scoped to phase
- `deviation(Project)` — actual − planned (positive = over budget)
- `milestoneBudgetUtilization(ProjectMilestone)` — actual / milestone.budget

---

## 9. Template System

Pre-defined templates can be seeded. When a project is created from a template, its `MilestoneTemplate` records are copied into `ProjectMilestone` records for that project (order, name, description preserved; dates left null for the manager to fill in).

Suggested default templates:

| Template | Phases |
|---|---|
| Desenvolvimento Completo | Briefing → Design → Dev. Fornecedores → Amostra → Aprovação → Produção → Embalagem → Envio |
| Busca de Fábrica | Briefing → Pesquisa de Fornecedores → Cotações → Seleção → Amostra Inicial |
| Sourcing + Amostra | Briefing → Sourcing → Amostra → Aprovação |

---

## 10. External Links

| Entity | Link | Notes |
|---|---|---|
| Company (CRM) | `company_id` on Project | Required |
| Product (Catalog) | `product_id` on Project | Optional — may be created at end of project |
| Contact (CRM) | `participant_type` on ProjectParticipant | Client-side participants |
| PI / PO | Future — reference fields to be added when project reaches production phase | Not in scope for v1 |

---

## 11. Out of Scope (v1)

- Participant roles and permissions (planned for v2)
- Gantt chart / timeline view (planned for v2)
- Links to PI/PO/Shipment records (planned for v2)
- Budget approval workflow
- Recurring project templates with auto-scheduling
