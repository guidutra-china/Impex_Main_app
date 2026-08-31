<?php

namespace Tests\Feature\Documents;

use App\Domain\Infrastructure\Models\Document;
use App\Domain\Infrastructure\Pdf\Templates\AbstractPdfTemplate;
use Illuminate\Support\Str;
use ReflectionClass;
use Tests\TestCase;

/**
 * documents.type é varchar de tamanho fixo e os testes rodam em SQLite, que não
 * aplica esse limite — um tipo comprido passa verde aqui e explode no MySQL de
 * produção com "Data too long for column 'type'". Foi o que aconteceu com
 * shipment_financial_statement_pdf (32 caracteres numa coluna de 30).
 *
 * Este teste lê o tamanho real declarado no schema e confere todo template.
 */
class DocumentTypeFitsColumnTest extends TestCase
{
    public function test_every_pdf_template_document_type_fits_the_documents_column(): void
    {
        $limit = Document::TYPE_MAX_LENGTH;
        $tooLong = [];

        foreach ($this->pdfTemplateClasses() as $class) {
            $type = (new ReflectionClass($class))
                ->newInstanceWithoutConstructor()
                ->getDocumentType();

            if (mb_strlen($type) > $limit) {
                $tooLong[] = "{$class}: '{$type}' tem ".mb_strlen($type).' caracteres';
            }
        }

        $this->assertSame(
            [],
            $tooLong,
            "Tipos de documento maiores que os {$limit} caracteres de documents.type:\n".implode("\n", $tooLong),
        );
    }

    /**
     * @return array<int, class-string<AbstractPdfTemplate>>
     */
    private function pdfTemplateClasses(): array
    {
        $directory = app_path('Domain/Infrastructure/Pdf/Templates');

        return collect(scandir($directory))
            ->filter(fn (string $file) => Str::endsWith($file, 'PdfTemplate.php'))
            ->map(fn (string $file) => 'App\\Domain\\Infrastructure\\Pdf\\Templates\\'.Str::before($file, '.php'))
            ->filter(fn (string $class) => class_exists($class)
                && is_subclass_of($class, AbstractPdfTemplate::class)
                && ! (new ReflectionClass($class))->isAbstract())
            ->values()
            ->all();
    }
}
