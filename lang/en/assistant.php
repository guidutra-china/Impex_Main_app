<?php

return [
    // Chat UI
    'empty_hint' => 'Ask a question — e.g. "Find the company DeepFitness" or "What is the status of client Gencrea?"',
    'thinking' => 'Thinking…',
    'composer_placeholder' => 'Ask the assistant something…',
    'send' => 'Send',
    'clear_conversation' => 'Clear conversation',
    'chat_error' => 'There was an error talking to the assistant. Please try again shortly.',
    'edit_done' => 'Done.',
    'edit_hint' => 'Something off? Ask me here in the chat to fix it (e.g. "set unit to pcs for all").',
    'review_hint' => 'Review and adjust each item and its photo, then confirm.',
    'photo_none' => 'No photo',
    'currency' => 'Currency',
    'notes' => 'Notes',

    // Import — drop zone
    'dropzone_drag' => 'Drag and drop',
    'dropzone_rest' => 'a document here (supplier quotation, client inquiry…), or click to select',
    'dropzone_formats' => 'xlsx, xls or PDF',
    'processing' => 'Processing…',
    'processing_file' => 'Processing :file…',
    'import_button' => 'Import',

    // Import — preview
    'preview_title' => 'Import preview',
    'supplier' => 'Supplier',
    'status_new' => 'new',
    'status_existing' => 'existing',
    'status_ai_suggested' => 'AI suggested',
    'status_ai_suggested_hint' => 'Match suggested by AI — verify the product before importing',
    'summary_counts' => ':total items — :existing existing products, :new new.',
    'items_total' => 'Items total',
    'document_total' => 'Document total',
    'extras_note' => 'Fees/discounts (added to the notes, not imported as items):',
    'divergence_warning' => '⚠️ The sum of items + fees/discounts does not match the document total. Check the values before confirming.',
    'uncategorized_warning' => '⚠️ :count item(s) with no matching category (flagged below). They will be created without a category — classify them later if needed.',
    'no_category' => 'no category',
    'confirm_import' => 'Confirm import',
    'cancel' => 'Cancel',
    'col' => [
        'part_no' => 'Model / Part No.',
        'description' => 'Description (product name)',
        'qty' => 'Qty',
        'unit' => 'Unit',
        'unit_price' => 'Unit price',
        'total' => 'Total',
        'category' => 'Category',
        'status' => 'Status',
        'photo' => 'Photo',
        'product' => 'Product',
    ],

    // Chat / flow messages
    'extracted' => 'I extracted the quotation from the file. Review the summary below and confirm to import.',
    'process_failed' => 'I could not process the file: :error',
    'duplicate_file_warning' => '⚠️ This file was already imported before (:references). Confirm only if you want to import it again.',
    'duplicate_file_banner' => 'This file was already imported before: :references. Confirming will create a duplicate record.',
    'connection_failed' => 'Could not connect to the AI service. Check your connection/VPN and try again.',
    'process_error' => 'Error processing the file. Please try again.',
    'imported_title' => 'Quotation imported: :reference',
    'imported_message' => 'Quotation :reference created with :count items.',
    'invalid_file' => 'Invalid import file.',
    'import_failed' => 'Failed to import the quotation.',

    // Import targets
    'target_supplier_quotation' => 'Supplier quotation',
    'target_inquiry' => 'Inquiry (client request)',

    // Permissions
    'perm_supplier_quotations' => 'You do not have permission to create supplier quotations.',
    'perm_companies' => 'You do not have permission to create companies (new supplier).',
    'perm_products' => 'You do not have permission to create products.',

    // Universal import — inquiry target
    'perm_inquiries_create' => 'You do not have permission to create inquiries.',
    'perm_inquiries_edit' => 'You do not have permission to edit inquiries.',
    'inquiry_not_open' => 'This inquiry is not open (received/quoting) — items cannot be added to it.',
    'client_required' => 'Inform the client to create a new inquiry.',

    // Agentic loop
    'too_many_steps' => 'I could not complete the request (too many steps). Try rephrasing it more simply.',
    'no_answer' => '(no answer)',
    'tool_unknown' => 'Unknown tool.',
    'tool_denied' => 'Permission denied: you do not have access to this data.',
    'tool_error' => 'Error running the tool: :error',

    // Universal import — target chooser
    'suggest_target' => 'This document looks like: :label — :reason',
    'suggest_target_short' => 'This document looks like: :label',
    'suggest_unknown' => "I couldn't identify the document type automatically.",
    'choose_target' => 'Choose the import destination:',
    'import_as' => 'Import as :label',
    'extracted_generic' => 'Data extracted. Review below and confirm the import.',
    'imported_generic' => ':reference imported with :count item(s).',
    'switch_target' => 'Import as another type…',
    'search_inquiry' => 'Search by reference or client…',
    'record_not_found' => 'Referenced record no longer exists. Review the selection and try again.',

    // Universal import — inquiry review card
    'mode_new_inquiry' => 'Create new inquiry',
    'mode_existing_inquiry' => 'Add to existing inquiry',
    'client' => 'Client',
    'deadline' => 'Deadline',
    'inquiry_label' => 'Inquiry',
    'select_inquiry' => 'Select the inquiry…',
    'import_locked_inquiry' => 'Upload a file to import linked to inquiry :reference — after analysis you choose the destination (inquiry items or a supplier quotation).',
    'sq_will_link_inquiry' => 'This supplier quotation will be linked to inquiry :inquiry.',
    'summary_inquiry_counts' => ':total items — :matched matched to products, :unmatched without match (imported with description only).',
    'col_target_price' => 'Target price',
    'import_with_ai' => 'Import with AI',

    // Combined Inquiry + SQ flow
    'create_linked_inquiry' => 'Create a linked Inquiry for a client',
    'create_linked_inquiry_hint' => 'Besides the quotation, creates an Inquiry for the chosen client with the same items and products — ready to generate a Quotation/PI.',
    'search_client' => 'Search client…',
    'select_client' => 'Select the client…',
    'inquiry_client_required' => 'Select the client for the linked Inquiry.',

    // Review — explicit item → product mapping
    'desc_inferred_badge' => 'inferred from photo — review',
    'new_product_hint' => 'A draft product will be created: the description becomes the Name and the Model/Part No. becomes the Model Number. Edit the fields to fix them before importing.',
    'existing_product_hint' => 'Item linked to an existing product — editing the description only changes this item, not the product.',
    'unlink_product' => 'Unlink',
    'link_product' => 'Link…',
    'link_product_title' => 'Link to an existing catalog product',
    'search_product' => 'Search product by name, SKU or model…',
    'search_all_catalog' => 'Search the whole catalog',
    'scope_supplier_hint' => 'Showing only products from supplier :supplier.',
    'scope_all_hint' => 'Showing products from the whole catalog — check the manufacturer before linking.',
    'target_unavailable_permission' => 'Importing as :label requires a permission you do not have. Ask an administrator.',
    'photo_upload' => 'Upload photo',
    'photo_drop_hint' => 'Drag an image here, click to pick from the document, or Ctrl+V with the gallery open.',
    'photo_flip' => 'Flip photo (upside down)',
    'import_as_sq_with_inquiry' => 'Import as Supplier quotation + linked Inquiry',
    'api_billing' => 'The Anthropic API account is out of credits. Top up at console.anthropic.com (Plans & Billing) and try again.',
];
