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

**Round-trip requirement (added 2026-07-03):** headers are English labels chosen
so the Quick Import (`FlexibleProductImportAction`) auto-maps every editable
column; the exported file can be edited and re-imported on the Products
(Client) tab. Column B carries `product.sku`, which is the product match key
on re-import (import looks up `Product` by `sku`, then `reference_code`, then
exact name). Column order matters: *Product Name* must precede *Model Number*
or the auto-mapper assigns `product_name` to the model column. Guarded by
`test_export_headers_are_auto_mapped_by_quick_import`, which runs
`FlexibleProductImportAction::detectMapping()` (extracted public wrapper around
the wizard's auto-detection) against the exported header row.

| Col | Column | Source | Auto-maps to |
|---|--------|--------|--------------|
| A | Photo | Embedded thumbnail: `pivot.avatar_path` (disk `pivot.avatar_disk`) if set, else product `avatar` (disk `public`); empty cell if neither | (image per row) |
| B | Reference Code (SKU) | `product.sku` | `reference_code` (match key) |
| C | Product Name | `product.name` | `product_name` |
| D | Model Number | `product.model_number` | `model_number` |
| E | Original Description | `product.description` | — |
| F | Category | `product.category.name` | — |
| G | Client Code | `pivot.external_code` | `external_code` |
| H | Client Product Name | `pivot.external_name` | `external_name` |
| I | Invoice Description | `pivot.external_description` | `external_description` |
| J | Selling Price | `pivot.unit_price` (minor -> major) | `unit_price` |
| K | Custom Price (CI) | `pivot.custom_price` (minor -> major, blank when null) | `custom_price` |
| L | Currency | `pivot.currency_code` | — (currency picked in wizard) |

## Dedicated Re-Import (added 2026-07-03)

The Quick Import wizard proved too heavy for round-tripping this report, so a
dedicated importer exists: `ClientProductsReportImporter::import(Company, path)`
plus an **"Importar Relatório"** header action (gated on `edit-companies`) next
to the export button.

- No mapping wizard: columns are fixed; the header row is located by finding
  the `Reference Code (SKU)` label in column B (first 10 rows); anything else
  throws `InvalidArgumentException` ("arquivo inválido" notification).
- Products matched by SKU (column B). Only **existing client links** of the
  owning company are updated — no product or link creation (that remains Quick
  Import's job). Unknown/unlinked SKUs are counted as skipped.
- Updated fields: external_code (G), external_name (H), external_description
  (I), unit_price (J), custom_price (K), currency_code (L).
- **Blank cells clear the stored value** (the sheet is the final state);
  unit_price is NOT NULL so blank resets it to 0.
- Photos are ignored on import (product avatars untouched). Runs in a DB
  transaction; uploaded temp file is deleted afterwards.

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
