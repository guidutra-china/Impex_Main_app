<?php

namespace App\Policies;

use App\Domain\ProformaInvoices\Models\ProformaInvoice;
use App\Models\User;

class ProformaInvoicePolicy
{
    public function viewAny(User $user): bool
    {
        return $user->can('view-proforma-invoices') || $user->can('portal:view-proforma-invoices');
    }

    public function view(User $user, ProformaInvoice $proformaInvoice): bool
    {
        return $user->can('view-proforma-invoices') || $user->can('portal:view-proforma-invoices');
    }

    public function create(User $user): bool
    {
        return $user->can('create-proforma-invoices');
    }

    public function update(User $user, ProformaInvoice $proformaInvoice): bool
    {
        return $user->can('edit-proforma-invoices');
    }

    public function delete(User $user, ProformaInvoice $proformaInvoice): bool
    {
        return $user->can('delete-proforma-invoices');
    }

    public function restore(User $user, ProformaInvoice $proformaInvoice): bool
    {
        return $user->can('delete-proforma-invoices');
    }

    public function forceDelete(User $user, ProformaInvoice $proformaInvoice): bool
    {
        return $user->hasRole('admin');
    }
}
