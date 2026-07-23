<?php

use App\Domain\CRM\Models\Company;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Chave de deduplicação de empresas: nome normalizado (trim, espaços internos
 * colapsados, maiúsculas). Mantida por hook no model e usada pelo
 * ResolveOrCreateCompanyAction para reusar empresas existentes em vez de criar
 * duplicatas nos fluxos que criam Company fora do formulário do CRM (import por
 * IA, registro em feira, sync offline). Nullable e sem unique por ora — a
 * promoção a unique virá após auditoria/limpeza dos dados de produção.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('companies', function (Blueprint $table) {
            $table->string('name_normalized')->nullable()->after('name')->index();
        });

        Company::withTrashed()->chunkById(200, function ($companies) {
            foreach ($companies as $company) {
                $company->newQuery()->withTrashed()->whereKey($company->id)->update([
                    'name_normalized' => Company::normalizeName((string) $company->name),
                ]);
            }
        });
    }

    public function down(): void
    {
        Schema::table('companies', function (Blueprint $table) {
            $table->dropIndex(['name_normalized']);
            $table->dropColumn('name_normalized');
        });
    }
};
