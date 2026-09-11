<?php

namespace App\Services\Directory;

use Mpdf\Cache;
use Mpdf\Fonts\FontCache;
use Mpdf\Mpdf;
use Mpdf\Otl;
use Mpdf\TTFontFile;

/** Physical column allocation and conservative local Cairo text measurement. */
class ReportLayout
{
    private static ?string $glyphWidths = null;

    private static ?Mpdf $measure = null;

    private static ?Otl $shaper = null;

    private static array $wordWidths = [];

    private static function shapedWidth(string $word, float $size): float
    {
        if (! preg_match('/\p{Arabic}/u', $word)) {
            return self::wordWidth($word, $size);
        }
        if (self::$measure === null) {
            self::$measure = new Mpdf([
                'fontDir' => [resource_path('fonts/cairo')],
                'fontdata' => ['cairo' => ['R' => 'Cairo-Regular.ttf', 'useOTL' => 0xFF]],
                'default_font' => 'cairo', 'default_font_size' => 10.5,
                'tempDir' => storage_path('framework/cache/directory-pdf'),
            ]);
            self::$measure->SetFont('cairo', '', 10.5);
            self::$shaper = new Otl(self::$measure, new FontCache(new Cache(storage_path('framework/cache/directory-pdf/mpdf/ttfontdata'))));
        }
        if (! isset(self::$wordWidths[$word])) {
            if (count(self::$wordWidths) > 2048) {
                self::$wordWidths = [];
            }
            $shaped = self::$shaper->applyOTL($word, 0xFF);
            self::$wordWidths[$word] = self::$measure->GetStringWidth($shaped, false, self::$shaper->OTLdata) * 72 / 25.4 / 10.5;
        }

        return self::$wordWidths[$word] * $size;
    }

    private static function wordWidth(string $word, float $size): float
    {
        if (self::$glyphWidths === null) {
            $font = new TTFontFile(new FontCache(new Cache(storage_path('framework/cache/directory-pdf'))), 'win');
            $font->getMetrics(resource_path('fonts/cairo/Cairo-Regular.ttf'), 'cairo-layout');
            self::$glyphWidths = $font->charWidths;
        }
        $width = 0;
        foreach (mb_str_split($word) as $character) {
            $offset = mb_ord($character) * 2;
            $advance = isset(self::$glyphWidths[$offset + 1]) ? (ord(self::$glyphWidths[$offset]) << 8) + ord(self::$glyphWidths[$offset + 1]) : 0;
            // mPDF encodes a zero-advance mark as 65535, not as a missing glyph.
            $width += $advance === 65535 ? 0 : ($advance ?: 600);
        }

        return $width * $size / 1000;
    }

    public static function widths(array $columns): array
    {
        $available = count($columns) > 4 ? 273 : 186;
        $fixed = ['number' => 8, 'code' => 25, 'clinic_count' => 24, 'doctor_count' => 24, 'patient_count' => 24, 'is_active' => 18];
        $flex = ['name' => 1.2, 'name_ar' => 1.2, 'specialties' => 1, 'description' => 1.7, 'clinics' => 1.5, 'doctors' => 1.5];
        $remaining = $available;
        $weight = 0;
        foreach ($columns as $key) {
            if (isset($fixed[$key])) {
                $remaining -= $fixed[$key];
            } else {
                $weight += $flex[$key] ?? 1;
            }
        }
        $widths = [];
        foreach ($columns as $key) {
            $widths[$key] = $fixed[$key] ?? ($remaining * ($flex[$key] ?? 1) / max(1, $weight));
        }
        if ($weight === 0) {
            $scale = $available / array_sum($widths);
            $widths = array_map(fn ($width) => $width * $scale, $widths);
        }

        return $widths;
    }

    public static function numeric(string $key): bool
    {
        return in_array($key, ['number', 'doctor_count', 'clinic_count', 'patient_count'], true);
    }

    public static function excelWidth(float $millimetres): float
    {
        // SpreadsheetML widths use the Normal font's maximum digit width at
        // 96 dpi. PhpSpreadsheet's mm conversion assumes Calibri, not Cairo.
        // https://learn.microsoft.com/en-us/dotnet/api/documentformat.openxml.spreadsheet.column
        $digitPixels = max(1, round(max(array_map(fn ($digit) => self::wordWidth((string) $digit, 10.5), range(0, 9))) * 96 / 72));

        return floor($millimetres * 96 / 25.4 / $digitPixels * 256) / 256;
    }

    public static function lines(string $text, float $widthMm, float $size = 10.5): int
    {
        $limit = max(8, $widthMm * 72 / 25.4);
        $lines = 0;
        foreach (preg_split('/\R/u', $text) as $paragraph) {
            $used = 0;
            $lines++;
            foreach (preg_split('/\s+/u', $paragraph) as $word) {
                $width = self::shapedWidth($word.' ', $size);
                if ($used > 0 && $used + $width > $limit) {
                    $lines++;
                    $used = 0;
                }
                $lines += max(0, (int) ceil($width / $limit) - 1);
                $used += fmod($width, $limit) ?: min($width, $limit);
            }
        }

        return max(1, $lines);
    }

    /** Print continuation rows stay below Excel's 409-point row limit. */
    public static function printParts(string $text, float $widthMm): array
    {
        $parts = [];
        $part = '';
        foreach (preg_split('/(\s+)/u', $text, -1, PREG_SPLIT_DELIM_CAPTURE) as $word) {
            foreach (mb_str_split($word, 800) as $piece) {
                if ($part !== '' && self::lines($part.$piece, $widthMm) > 14) {
                    $parts[] = $part;
                    $part = '';
                }
                $part .= $piece;
            }
        }
        if ($part !== '') {
            $parts[] = $part;
        }

        return $parts;
    }

    /** Excel's 32767 UTF-16 units, without splitting supplementary characters. */
    public static function cellParts(string $text): array
    {
        $parts = [];
        $part = '';
        $length = 0;
        foreach (mb_str_split($text) as $character) {
            $units = strlen(mb_convert_encoding($character, 'UTF-16LE', 'UTF-8')) / 2;
            if ($length + $units > 32767) {
                $parts[] = $part;
                $part = '';
                $length = 0;
            }
            $part .= $character;
            $length += $units;
        }
        $parts[] = $part;

        return $parts;
    }
}
