<?php

namespace App\Domain\Catalog\Support;

/**
 * Regra de formatação/validação do NCM impresso em documento.
 *
 * O banco (company_product.external_ncm) guarda os 8 dígitos que o
 * despachante enviou, porque é o que a DI/DUIMP precisa. O documento mostra
 * só a posição de 4 — formatação é do documento, não do dado.
 *
 * external_ncm chega de uma planilha reimportada sem validação
 * (ClientProductsReportImporter grava a célula quase crua: só trim()), então
 * o texto que o despachante digitar em uma célula pode conter qualquer
 * coisa. "Extrair os dígitos e truncar os 4 primeiros" transformava ruído em
 * algo com cara de posição válida: "Ref 12: 8431.49.00" virava "1284", "NCM
 * a definir 2026" virava "2026" — nenhum dos dois é NCM, e nada no documento
 * denunciava.
 *
 * Por isso a validação acontece ANTES de extrair dígitos: se sobrar
 * qualquer caractere que não seja dígito ou separador comum (ponto, barra,
 * hífen, espaço), o valor inteiro é rejeitado — não só truncado. Só depois
 * disso os dígitos restantes são contados: 4 a 8, o mesmo intervalo que
 * {@see \App\Filament\Support\ClientNcmInput} já valida na entrada do
 * formulário (mesmo fato de domínio, uma regra só).
 *
 * Um valor fora do intervalo (curto demais ou comprido demais) devolve
 * null: um fragmento de posição num documento aduaneiro é pior do que campo
 * vazio.
 */
class Ncm
{
    /**
     * Quantidade de dígitos aceita para um NCM: 4 é a posição, 8 é o NCM
     * completo. Compartilhado com ClientNcmInput para as duas camadas não
     * divergirem de novo.
     */
    public const DIGIT_COUNT_PATTERN = '/^\d{4,8}$/';

    /**
     * Devolve a posição de 4 dígitos do NCM, ou null quando o valor não é
     * claramente um NCM (vazio, curto/comprido demais, ou contém qualquer
     * coisa além de dígitos e separadores).
     */
    public static function heading(?string $ncm): ?string
    {
        $trimmed = trim((string) $ncm);

        if ($trimmed === '' || ! preg_match('/^[\d.\-\/\s]+$/', $trimmed)) {
            return null;
        }

        $digits = preg_replace('/\D/', '', $trimmed);

        return preg_match(self::DIGIT_COUNT_PATTERN, $digits) ? substr($digits, 0, 4) : null;
    }
}
