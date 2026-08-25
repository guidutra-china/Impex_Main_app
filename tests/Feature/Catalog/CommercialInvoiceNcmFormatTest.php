<?php

namespace Tests\Feature\Catalog;

use App\Domain\Infrastructure\Pdf\Templates\CommercialInvoicePdfTemplate;
use Tests\TestCase;

/**
 * O banco guarda os 8 dígitos que o despachante enviou, porque é o que a
 * DI/DUIMP precisa. O documento mostra a posição de 4.
 */
class CommercialInvoiceNcmFormatTest extends TestCase
{
    public function test_ncm_e_impresso_com_quatro_digitos(): void
    {
        $format = new \ReflectionMethod(CommercialInvoicePdfTemplate::class, 'formatNcm');
        $format->setAccessible(true);

        $this->assertSame('9506', $format->invoke(null, '9506.91.00'));
        $this->assertSame('9403', $format->invoke(null, '9403.20.00'));
        $this->assertSame('9401', $format->invoke(null, '9401.71.00'));
        $this->assertSame('9506', $format->invoke(null, '9506'));
        $this->assertNull($format->invoke(null, null));
        $this->assertNull($format->invoke(null, ''));
        $this->assertNull($format->invoke(null, 'sem digitos'));
        $this->assertNull($format->invoke(null, '950'));
    }
}
