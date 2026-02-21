# TradingApp

Import/Export Management System built with **Laravel 12** and **Filament v4**.

## Architecture

This project follows a **Domain-Driven Design (DDD) lite** approach. Business logic is organized by domain, not by technical layer.

### Domain Structure

```
app/Domain/
├── Auth/           # Users, Roles, Permissions
├── Catalog/        # Products, Categories, Tags, BOM
├── CRM/            # Clients, Suppliers, Contacts
├── Documents/      # Documents, Generated Documents
├── Finance/        # Financial Transactions, Payments
├── Logistics/      # Shipments, Packing, Commercial Invoices
├── Purchasing/     # Purchase Orders
├── Quotations/     # Orders (RFQ), Supplier Quotes, Customer Quotes
└── Settings/       # Company Settings, Currencies, Payment Methods, Bank Accounts
```

Each domain contains:

| Directory | Purpose |
|:---|:---|
| `Models/` | Eloquent models (lean, focused on state and relationships) |
| `Enums/` | Status enums and typed constants |
| `Services/` | Business logic and complex operations |
| `Actions/` | Single-purpose, invocable action classes |
| `DataTransferObjects/` | DTOs for structured data transfer between layers |

### Filament Layer

Filament Resources live in `app/Filament/Resources/` and serve strictly as the **presentation layer**. They consume domain Actions and Services but contain no business logic.

### Navigation Groups

| Group | Domain |
|:---|:---|
| Sales & Quotations | Quotations |
| Purchasing | Purchasing |
| Logistics & Shipping | Logistics |
| Finance | Finance |
| Documents | Documents |
| Contacts | CRM |
| Inventory | Catalog |
| Settings | Settings |
| Security | Auth |

## Setup

```bash
composer install
cp .env.example .env
php artisan key:generate
php artisan migrate
php artisan db:seed
npm install && npm run build
```

## Development

```bash
composer dev
```

This starts the Laravel server, queue worker, log viewer, and Vite dev server concurrently.

## Tech Stack

- **PHP** 8.2+
- **Laravel** 12
- **Filament** v4
- **Filament Shield** (Roles & Permissions)
- **MySQL** 8.0+
