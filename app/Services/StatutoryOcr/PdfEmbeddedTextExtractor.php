<?php

namespace App\Services\StatutoryOcr;

use Smalot\PdfParser\Config as PdfParserConfig;
use Smalot\PdfParser\Parser as PdfParser;
use Throwable;

class PdfEmbeddedTextExtractor
{
    public function extract(string $pdfContent): ?string
    {
        if (! str_starts_with($pdfContent, '%PDF')) {
            return null;
        }

        $smalot = $this->extractWithSmalot($pdfContent);
        if ($this->isReliable($smalot)) {
            return $smalot;
        }

        $simple = $this->extractSimple($pdfContent);
        if ($this->isReliable($simple)) {
            return $simple;
        }

        foreach ([$smalot, $simple] as $candidate) {
            if ($candidate !== null && trim($candidate) !== '') {
                return $candidate;
            }
        }

        return null;
    }

    public function isReliable(?string $text): bool
    {
        return $this->reliabilityScore($text) >= 2;
    }

    public function reliabilityScore(?string $text): int
    {
        if ($text === null) {
            return 0;
        }

        $normalized = trim($text);
        if (mb_strlen($normalized) < 40) {
            return 0;
        }

        $score = 0;
        foreach ([
            'Resit', 'Majikan', 'Bayaran', 'Jumlah', 'Receipt', 'Employer', 'Amount',
            'LHDN', 'HASIL', 'e-PCB', 'POTONGAN CUKAI', 'PCB Account', 'TIN',
        ] as $label) {
            if (stripos($normalized, $label) !== false) {
                $score++;
            }
        }

        if (preg_match('/\b(?:ACR|ECR)[A-Z0-9]+/i', $normalized) === 1) {
            $score += 2;
        }

        return $score;
    }

    private function extractWithSmalot(string $pdfContent): ?string
    {
        try {
            $config = new PdfParserConfig;
            $config->setRetainImageContent(false);
            $config->setDecodeMemoryLimit(2 * 1024 * 1024);

            $text = (new PdfParser([], $config))->parseContent($pdfContent)->getText();
        } catch (Throwable) {
            return null;
        }

        $text = trim(preg_replace("/[ \t]+/u", ' ', str_replace("\u{00A0}", ' ', $text)) ?? $text);

        return $text !== '' ? $text : null;
    }

    private function extractSimple(string $pdfContent): ?string
    {
        $tokens = [];

        foreach ($this->inflateStreams($pdfContent) as $stream) {
            foreach ($this->extractTokens($stream) as $token) {
                $tokens[] = $token;
            }
        }

        if ($tokens === []) {
            return null;
        }

        $text = $this->compose($tokens);

        return $text !== '' ? $text : null;
    }

    /**
     * @return list<string>
     */
    private function inflateStreams(string $pdfContent): array
    {
        $streams = [];

        if (preg_match_all('/stream\r?\n(.*?)\r?\nendstream/s', $pdfContent, $matches) < 1) {
            return $streams;
        }

        foreach ($matches[1] as $raw) {
            if (strlen($raw) > 64 * 1024) {
                continue;
            }
            $decoded = @gzuncompress($raw);

            if ($decoded === false) {
                $decoded = @gzinflate($raw);
            }

            if (is_string($decoded) && $decoded !== '') {
                $streams[] = $decoded;
            }
        }

        return $streams;
    }

    /**
     * @return list<array{x:float,y:float,text:string}>
     */
    private function extractTokens(string $stream): array
    {
        $tokens = [];
        $x = 0.0;
        $y = 0.0;

        if (preg_match_all('/(?:([-+]?\d*\.?\d+)\s+([-+]?\d*\.?\d+)\s+Td)|(?:([-+]?\d*\.?\d+)\s+([-+]?\d*\.?\d+)\s+([-+]?\d*\.?\d+)\s+([-+]?\d*\.?\d+)\s+([-+]?\d*\.?\d+)\s+([-+]?\d*\.?\d+)\s+Tm)|\((?:\\\\.|[^\\\\)])*\)\s*Tj|\[(?:\((?:\\\\.|[^\\\\)])*\)\s*(?:[-+]?\d*\.?\d+\s*)?)+\]\s*TJ/s', $stream, $matches, PREG_SET_ORDER) === false) {
            return $tokens;
        }

        foreach ($matches as $match) {
            $token = $match[0];

            if (str_ends_with($token, ' Td')) {
                $x = (float) $match[1];
                $y = (float) $match[2];

                continue;
            }

            if (str_ends_with($token, ' Tm')) {
                $x = (float) $match[7];
                $y = (float) $match[8];

                continue;
            }

            $text = $this->pdfStringToText($token);
            if ($text === '') {
                continue;
            }

            $tokens[] = [
                'x' => $x,
                'y' => $y,
                'text' => $text,
            ];
        }

        return $tokens;
    }

    /**
     * @param  list<array{x:float,y:float,text:string}>  $tokens
     */
    private function compose(array $tokens): string
    {
        usort($tokens, static function (array $left, array $right): int {
            $yCompare = $right['y'] <=> $left['y'];

            return $yCompare !== 0 ? $yCompare : ($left['x'] <=> $right['x']);
        });

        $lines = [];
        $currentY = null;
        $current = '';

        foreach ($tokens as $token) {
            if ($currentY !== null && abs($token['y'] - $currentY) > 2.0) {
                $lines[] = trim($current);
                $current = '';
            }

            $currentY = $token['y'];
            $current .= $token['text'];
        }

        if (trim($current) !== '') {
            $lines[] = trim($current);
        }

        $lines = array_values(array_filter($lines, static fn (string $line): bool => $line !== ''));

        return implode("\n", $lines);
    }

    private function pdfStringToText(string $token): string
    {
        $parts = [];

        if (preg_match_all('/\((?:\\\\.|[^\\\\)])*\)/', $token, $matches) > 0) {
            foreach ($matches[0] as $part) {
                $inner = substr($part, 1, -1);
                $inner = str_replace(['\\(', '\\)', '\\\\', '\\n', '\\r', '\\t'], ['(', ')', '\\', "\n", "\r", "\t"], $inner);
                $inner = preg_replace_callback('/\\\\([0-7]{1,3})/', static fn (array $octal): string => chr(octdec($octal[1])), $inner) ?? $inner;
                $parts[] = $inner;
            }
        }

        return implode('', $parts);
    }
}
