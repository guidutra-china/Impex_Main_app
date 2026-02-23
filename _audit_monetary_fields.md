# Monetary Fields Audit — Changing from /100 to /10000

## SCALE CONSTANT
- OLD: 100 (2 decimal places)
- NEW: 10000 (4 decimal places)
- FORMAT: number_format($val / 10000, 4) for display, or 2 where appropriate

## Files to change (/ 100 → / 10000, * 100 → * 10000):

### Domain Models (accessors/mutators)
1. app/Domain/ProformaInvoices/Models/ProformaInvoice.php
2. app/Domain/ProformaInvoices/Models/ProformaInvoiceItem.php
3. app/Domain/Quotations/Models/Quotation.php
4. app/Domain/Quotations/Models/QuotationItem.php
5. app/Domain/Quotations/Models/QuotationItemSupplier.php
6. app/Domain/PurchaseOrders/Models/PurchaseOrderItem.php (new, already /100)

### PDF Templates (formatMoney)
7. app/Domain/Infrastructure/Pdf/Templates/AbstractPdfTemplate.php (formatMoney method)
8. app/Domain/Infrastructure/Pdf/Templates/ProformaInvoicePdfTemplate.php
9. app/Domain/Infrastructure/Pdf/Templates/PurchaseOrderPdfTemplate.php
10. app/Domain/Infrastructure/Pdf/Templates/QuotationPdfTemplate.php
11. app/Domain/Infrastructure/Pdf/Templates/RfqPdfTemplate.php

### Filament RelationManagers (form mutators + table formatters)
12. app/Filament/Resources/ProformaInvoices/RelationManagers/ItemsRelationManager.php
13. app/Filament/Resources/PurchaseOrders/RelationManagers/ItemsRelationManager.php
14. app/Filament/Resources/Quotations/RelationManagers/ItemsRelationManager.php
15. app/Filament/Resources/SupplierQuotations/RelationManagers/ItemsRelationManager.php
16. app/Filament/Resources/Inquiries/RelationManagers/ItemsRelationManager.php
17. app/Filament/Resources/CRM/Companies/RelationManagers/ClientProductsRelationManager.php
18. app/Filament/Resources/CRM/Companies/RelationManagers/SupplierProductsRelationManager.php
19. app/Filament/Resources/Catalog/Products/RelationManagers/ClientsRelationManager.php
20. app/Filament/Resources/Catalog/Products/RelationManagers/SuppliersRelationManager.php

### Filament Schemas/Tables (display formatters)
21. app/Filament/Resources/ProformaInvoices/Schemas/ProformaInvoiceInfolist.php
22. app/Filament/Resources/PurchaseOrders/Schemas/PurchaseOrderInfolist.php
23. app/Filament/Resources/PurchaseOrders/Tables/PurchaseOrdersTable.php
24. app/Filament/Resources/Quotations/Schemas/QuotationInfolist.php
25. app/Filament/Resources/Catalog/Products/Schemas/ProductInfolist.php

### Filament Pages (commission calculation)
26. app/Filament/Resources/Inquiries/Pages/EditInquiry.php
