<?php

declare(strict_types=1);

namespace App\Domain\Infrastructure\Pdf\Support;

/**
 * Normaliza texto para os PDFs (dompdf + DejaVu Sans): a fonte embutida não tem
 * glifos de pontuação CJK/fullwidth — comuns em textos digitados com IME chinês
 * (specs de fornecedores) — e cada um viraria um quadrado "tofu" no documento.
 * Translitera para o equivalente ASCII; caracteres fora do mapa passam intactos.
 */
class PdfText
{
    private const MAP = [
        '、' => ', ',
        '，' => ', ',
        '。' => '. ',
        '：' => ': ',
        '；' => '; ',
        '！' => '!',
        '？' => '?',
        '（' => '(',
        '）' => ')',
        '【' => '[',
        '】' => ']',
        '「' => '"',
        '」' => '"',
        '＜' => '<',
        '＞' => '>',
        '＝' => '=',
        '＋' => '+',
        '－' => '-',
        '～' => '~',
        '％' => '%',
        '＃' => '#',
        '＆' => '&',
        '／' => '/',
        '｜' => '|',
        '　' => ' ', // espaço ideográfico (U+3000)
    ];

    public static function normalize(?string $text): ?string
    {
        if ($text === null || $text === '') {
            return $text;
        }

        return strtr($text, self::MAP);
    }
}
