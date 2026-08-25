<?php

namespace Tests\Unit\Catalog;

use App\Domain\Catalog\Support\Ncm;
use Tests\TestCase;

/**
 * O banco guarda os 8 dígitos que o despachante enviou (é o que a DI/DUIMP
 * precisa); o documento mostra só a posição de 4 — e recusa a imprimir
 * quando o valor não é claramente uma posição de NCM, porque
 * ClientProductsReportImporter grava o texto da planilha quase sem
 * validação: o que o despachante digitar em texto livre pode chegar aqui.
 *
 * Função pura, sem Eloquent nem container — não precisa de RefreshDatabase
 * nem de reflection para ser testada.
 */
class NcmTest extends TestCase
{
    public function test_ncm_e_formatado_com_quatro_digitos(): void
    {
        $this->assertSame('9506', Ncm::heading('9506.91.00'));
        $this->assertSame('9403', Ncm::heading('9403.20.00'));
        $this->assertSame('9401', Ncm::heading('9401.71.00'));
        $this->assertSame('9506', Ncm::heading('9506'));
        $this->assertNull(Ncm::heading(null));
        $this->assertNull(Ncm::heading(''));
        $this->assertNull(Ncm::heading('sem digitos'));
        $this->assertNull(Ncm::heading('950'));
    }

    public function test_texto_sujo_nao_vira_posicao_inventada(): void
    {
        // Antes, extrair dígitos e truncar os 4 primeiros produzia uma
        // posição com cara de válida a partir de ruído: "1284" e "2026"
        // abaixo nunca foram NCM. Qualquer caractere fora de dígito/
        // separador agora reprova o valor inteiro, em vez de só truncar.
        $this->assertNull(Ncm::heading('Ref 12: 8431.49.00'));
        $this->assertNull(Ncm::heading('NCM a definir 2026'));

        // Dígitos puros continuam aceitos dentro do intervalo 4-8 (mesmo
        // intervalo que ClientNcmInput valida na entrada do formulário) —
        // mesmo sem separador e mesmo com menos de 8 dígitos.
        $this->assertSame('1022', Ncm::heading('102291'));

        // Fora do intervalo 4-8: curto demais continua null.
        $this->assertNull(Ncm::heading('84'));

        // Despachante oferecendo duas classificações na mesma célula: o
        // total de dígitos extrapola o intervalo 4-8, então null — não uma
        // das duas posições escolhida ao acaso.
        $this->assertNull(Ncm::heading('8431.49.00 / 8481.80.99'));
    }

    /**
     * Espaço não quebrável (comum em texto colado do Siscomex ou extraído
     * de PDF) e travessões unicode não podem reprovar um NCM que seria
     * válido com espaço/hífen comuns. Antes de normalizar, um NBSP
     * escondido no meio ou no fim do valor derrubava a checagem de
     * caracteres válidos — e como show_ncm é derivado do valor já
     * formatado, uma planilha inteira contaminada com NBSP não produzia
     * células vazias isoladas: a coluna de NCM inteira sumia do documento.
     */
    public function test_espacos_e_travessoes_unicode_nao_reprovam_o_valor(): void
    {
        $this->assertSame('9506', Ncm::heading("9506\u{00A0}91.00"));
        $this->assertSame('9506', Ncm::heading("9506.91.00\u{00A0}"));
        $this->assertSame('9506', Ncm::heading("9506\u{2013}91.00"));
    }

    /**
     * UTF-8 inválido é tratado explicitamente como "não é NCM" — não deixa
     * o null de preg_replace('/u') vazar sem checagem para o resto da
     * função.
     */
    public function test_utf8_invalido_devolve_null_em_vez_de_vazar(): void
    {
        $this->assertNull(Ncm::heading('9506'.chr(0xB1).chr(0x31)));
    }
}
