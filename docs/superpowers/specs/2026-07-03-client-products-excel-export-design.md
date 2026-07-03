# Client Products Excel Export — Design

**Date:** 2026-07-03
**Status:** Approved by Gui

## Goal

Allow exporting an Excel report of all products linked to a client, showing the
original product data side by side with the client-specific data (e.g. original
code -> client code), including embedded product photos and client prices.

## Entry Point

Header action **"Exportar Excel"** on the `ClientProductsRelationManager`
(`app/Filament/Resources/CRM/Companies/RelationManagers/ClientProductsRelationManager.php`),
i.e. the *Products (Client)* tab of a company in the CRM module.

- Visible to any user who can view the company page (same visibility as the tab itself).
- Returns `response()->download($path)->deleteFileAfterSend()`.
- Filename: `produtos-{client-slug}-{YYYY-MM-DD}.xlsx`.

## Exporter

New class: `app/Domain/Catalog/Reports/ClientProductsExcelExporter.php`

- Public API: `export(Company $client): string` — generates the `.xlsx` into
  `storage_path('app/temp/')` and returns the absolute path.
- Uses **PhpSpreadsheet** (already installed). The existing OpenSpout-based
  `AbstractExcelTemplate` pattern cannot embed images, which is why this report
  gets a dedicated exporter, following the shape of
  `app/Domain/Financial/Reports/PaymentsSummaryExcelExporter.php`.

## Data

- Source: `Company::clientProducts()` (many-to-many via `company_product` pivot,
  `role = 'client'`), eager-loading `category`.
- Ordered by product `name`.
- Prices on the pivot are stored in minor units — convert with
  `App\Domain\Infrastructure\Support\Money::toMajor()` and write as numeric
  cells with 4-decimal format (matching the UI, which uses 4 decimals).

## Columns

| # | Column | Source |
|---|--------|--------|
| 1 | Foto | Embedded thumbnail: `pivot.avatar_path` (disk `pivot.avatar_disk`) if set, else product `avatar` (disk `public`); empty cell if neither |
| 2 | SKU | `product.sku` |
| 3 | Model No. | `product.model_number` |
| 4 | Nome (original) | `product.name` |
| 5 | Descrição (original) | `product.description` |
| 6 | Categoria | `product.category.name` |
| 7 | Código do Cliente | `pivot.external_code` |
| 8 | Nome (Cliente) | `pivot.external_name` |
| 9 | Descrição (Cliente) | `pivot.external_description` |
| 10 | Preço de Venda | `pivot.unit_price` (minor -> major) |
| 11 | Preço CI | `pivot.custom_price` (minor -> major, blank when null) |
| 12 | Moeda | `pivot.currency_code` |

MOQ, lead time, incoterm, notes and is_preferred are intentionally out of scope
(user chose identification + prices + photos only).

## Layout & Styling

- Row 1: title — `Produtos — {client name}` (font size 14, bold).
- Row 2: generation date (`d/m/Y`), gray.
- Row 3: group header row spanning the column sections: *Produto (Original)*
  (cols 2–6), *Dados do Cliente* (cols 7–9), *Preços* (cols 10–12) — merged
  cells, bold.
- Row 4: column headers — bold, white text on blue background `#4472C4`
  (matches existing exports).
- Data rows: fixed row height (~48pt) to fit thumbnails; thin borders.
- Column widths sized for content; description columns wrap text.

## Photos

- Embedded with `PhpOffice\PhpSpreadsheet\Worksheet\Drawing` pointing directly
  at the file on disk (no base64/in-memory image manipulation) — avatars are
  already resized to 400×400 at upload time.
- Thumbnail rendered at ~60px height, anchored to the Foto cell with a small
  offset.
- Missing file on disk (stale path) is treated the same as no photo: skip
  silently, leave the cell empty.

## Error Handling

- Client with zero linked products: still generates a valid file with title +
  headers and no data rows (user gets an empty report rather than an error).
- Unreadable/missing image files never abort the export.

## Testing

Feature test `tests/Feature/Catalog/ClientProductsExcelExporterTest.php`:

- Client with two products — one with full pivot data + photo, one with null
  pivot fields and no photo.
- Assert the generated file exists, opens with PhpSpreadsheet, and contains the
  expected cell values (original vs client codes, prices converted from minor
  units, blank CI price when null).
- Assert the empty-client case produces a valid file with headers only.

## Out of Scope

- No stored/versioned Document (on-the-fly download only).
- No export on the companies list page.
- No supplier-side variant (can be added later reusing the exporter with the
  supplier relation if needed).
