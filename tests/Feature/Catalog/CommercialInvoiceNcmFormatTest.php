<?php

namespace Tests\Feature\Catalog;

use App\Domain\Infrastructure\Pdf\Templates\CommercialInvoicePdfTemplate;
use Tests\TestCase;

/**
 * O banco guarda os 8 dígitos que o despachante enviou, porque é o que a
 * DI/DUIMP precisa. O documento mostra a posição de 4 — e recusa a imprimir
 * quando o valor não é claramente uma posição de NCM, porque
 * ClientProductsReportImporter grava o texto da planilha quase sem
 * validação: o que o despachante digitar em texto livre pode chegar aqui.
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

    public function test_texto_sujo_nao_vira_posicao_inventada(): void
    {
        $format = new \ReflectionMethod(CommercialInvoicePdfTemplate::class, 'formatNcm');
        $format->setAccessible(true);

        // Antes, extrair dígitos e truncar os 4 primeiros produzia uma
        // posição com cara de válida a partir de ruído: "1284" e "2026"
        // abaixo nunca foram NCM. Qualquer caractere fora de dígito/
        // separador agora reprova o valor inteiro, em vez de só truncar.
        $this->assertNull($format->invoke(null, 'Ref 12: 8431.49.00'));
        $this->assertNull($format->invoke(null, 'NCM a definir 2026'));

        // Dígitos puros continuam aceitos dentro do intervalo 4-8 (mesmo
        // intervalo que ClientNcmInput valida na entrada do formulário) —
        // mesmo sem separador e mesmo com menos de 8 dígitos.
        $this->assertSame('1022', $format->invoke(null, '102291'));

        // Fora do intervalo 4-8: curto demais continua null.
        $this->assertNull($format->invoke(null, '84'));
    }
}
