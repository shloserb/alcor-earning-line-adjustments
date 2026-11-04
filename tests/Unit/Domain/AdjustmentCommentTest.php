<?php

declare(strict_types=1);

namespace Payroll\Tests\Unit\Domain;

use Payroll\Domain\AdjustmentComment;
use Payroll\Domain\Exception\InvalidAdjustment;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

final class AdjustmentCommentTest extends TestCase
{
    #[Test]
    #[DataProvider('validComments')]
    public function preservesMeaningfulTextAndTrimsSurroundingAsciiWhitespace(string $input, string $expected): void
    {
        self::assertSame($expected, new AdjustmentComment($input)->value);
    }

    /** @return array<string, array{string, string}> */
    public static function validComments(): array
    {
        return [
            'plan example' => ['  ok  ', 'ok'],
            'already trimmed' => ['Late correction', 'Late correction'],
            'tabs and newlines' => ["\t Reason \r\n", 'Reason'],
            'internal spacing' => ['  Correcting  adjustment #4  ', 'Correcting  adjustment #4'],
            'zero is not empty' => ['0', '0'],
            'Ukrainian text' => ['  Корекція зарплати  ', 'Корекція зарплати'],
            'Japanese text' => ['給与の修正', '給与の修正'],
            'internal Unicode spaces' => ["Bonus\u{00A0}for\u{2009}overtime", "Bonus\u{00A0}for\u{2009}overtime"],
            'Persian text with joiner' => ["اصلاح\u{200C}حقوق", "اصلاح\u{200C}حقوق"],
            'emoji with joiner' => ["\u{1F469}\u{200D}\u{1F4BB} overtime", "\u{1F469}\u{200D}\u{1F4BB} overtime"],
            'private-use character may represent a glyph' => ["\u{E000}", "\u{E000}"],
        ];
    }

    #[Test]
    #[DataProvider('emptyComments')]
    public function rejectsEmptyComment(string $value): void
    {
        $this->expectException(InvalidAdjustment::class);

        new AdjustmentComment($value);
    }

    /** @return array<string, array{string}> */
    public static function emptyComments(): array
    {
        return [
            'empty' => [''],
            'spaces' => ['   '],
            'other whitespace' => ["\t\r\n"],
            'form feed' => ["\f"],
            'ASCII controls' => ["\0\x07\x1B\x7F"],
            'next line' => ["\u{0085}"],
            'non-breaking space' => ["\u{00A0}"],
            'ogham space mark' => ["\u{1680}"],
            'en quad through hair space' => ["\u{2000}\u{2001}\u{2002}\u{2003}\u{2004}\u{2005}\u{2006}\u{2007}\u{2008}\u{2009}\u{200A}"],
            'line separator' => ["\u{2028}"],
            'paragraph separator' => ["\u{2029}"],
            'narrow non-breaking space' => ["\u{202F}"],
            'medium mathematical space' => ["\u{205F}"],
            'ideographic space' => ["\u{3000}"],
            'zero-width space' => ["\u{200B}"],
            'zero-width no-break space' => ["\u{FEFF}"],
            'word joiner' => ["\u{2060}"],
            'joiners without text' => ["\u{200C}\u{200D}"],
            'bidirectional controls without text' => ["\u{061C}\u{200E}\u{200F}\u{202A}\u{202C}\u{2066}\u{2069}"],
            'soft hyphen' => ["\u{00AD}"],
            'mixed whitespace and format characters' => [" \t\u{00A0}\u{2028}\u{200B}\u{FEFF}\u{2060}\n"],
        ];
    }

    #[Test]
    #[DataProvider('invalidUtf8Comments')]
    public function rejectsInvalidUtf8(string $value): void
    {
        $this->expectException(InvalidAdjustment::class);

        new AdjustmentComment($value);
    }

    /** @return array<string, array{string}> */
    public static function invalidUtf8Comments(): array
    {
        return [
            'truncated sequence' => ["\xC3"],
            'orphan continuation byte' => ["\x80"],
            'overlong encoding' => ["\xC0\xAF"],
            'encoded surrogate' => ["\xED\xA0\x80"],
            'code point above Unicode maximum' => ["\xF4\x90\x80\x80"],
            'malformed sequence after text' => ["Correction\xC3\x28"],
        ];
    }
}
