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
 *
 * Limitação aceita: dentro do intervalo 4-8, o comprimento sozinho não
 * decide se o valor faz sentido. Um bruto de 6 dígitos sem separador
 * ("102291") é aceito e truncado para os 4 primeiros — mas é ambíguo:
 * pode ser uma subposição do SH, caso em que os 4 primeiros dígitos já são
 * a posição correta, ou pode ser um NCM de 8 dígitos que perdeu o zero à
 * esquerda no Excel ("01022910" virando "102291"), caso em que truncar os
 * 4 primeiros está errado. 5 e 7 dígitos não são um comprimento de NCM
 * válido de jeito nenhum e também são aceitos hoje. O risco prático é
 * baixíssimo para este catálogo (capítulos 84, 94 e 95, sem zero à
 * esquerda), então a decisão é documentar, não apertar: reduzir o
 * intervalo aceito para {4, 6, 8} mudaria também o que
 * {@see \App\Filament\Support\ClientNcmInput} aceita na entrada do
 * formulário, e essa mudança fica fora do escopo desta unidade.
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
        $normalized = self::normalizeSeparators((string) $ncm);

        if ($normalized === null) {
            return null;
        }

        $trimmed = trim($normalized);

        if ($trimmed === '' || ! preg_match('/^[\d.\-\/\s]+$/', $trimmed)) {
            return null;
        }

        $digits = preg_replace('/\D/', '', $trimmed);

        return preg_match(self::DIGIT_COUNT_PATTERN, $digits) ? substr($digits, 0, 4) : null;
    }

    /**
     * Espaço não quebrável (comum em texto colado do Siscomex ou extraído de
     * PDF) e travessões unicode (en dash, em dash) viram espaço ASCII antes
     * de qualquer outra checagem. Sem isso, um NBSP no meio ou no fim do
     * valor reprovava a checagem de caracteres válidos — e como show_ncm é
     * derivado do valor já formatado, uma planilha inteira contaminada com
     * NBSP não produzia células vazias isoladas: a coluna de NCM inteira
     * sumia do documento, sem nenhum aviso.
     *
     * preg_replace com /u devolve null quando a entrada não é UTF-8 válido.
     * Tratado aqui explicitamente como "não é NCM" — propagar esse null
     * como se fosse o valor normalizado, em vez de checar, faria a função
     * inteira falhar silenciosamente para qualquer byte inválido perdido no
     * meio de uma planilha grande.
     */
    private static function normalizeSeparators(string $value): ?string
    {
        return preg_replace('/[\p{Zs}\p{Pd}]/u', ' ', $value);
    }
}
