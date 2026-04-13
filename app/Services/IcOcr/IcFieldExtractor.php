<?php

namespace App\Services\IcOcr;

class IcFieldExtractor
{
    /**
     * Known Malaysian MyKad header / label lines that should never be treated as a name.
     *
     * @var array<int,string>
     */
    private const BLACKLISTED_NAME_LINES = [
        'KAD PENGENALAN',
        'IDENTITY CARD',
        'JABATAN PENDAFTARAN NEGARA',
        'MALAYSIA',
        'WARGANEGARA',
        'CITIZENSHIP',
        'TARIKH LAHIR',
        'DATE OF BIRTH',
        'JANTINA',
        'SEX',
        'AGAMA',
        'RELIGION',
        'BANGSA',
        'RACE',
        'NAMA',
        'NAME',
        'POSKOD',
        'POSTCODE',
        'NEGERI',
        'STATE',
        'KAD PENGENALAN MALAYSIA',
        'PENGENALAN',
        // Physical card brand label — OCR frequently reads "MyKad" and merges it
        // with adjacent text (e.g. "MYKAD C ROWAN SEBASTIAN" where C = © symbol).
        'MYKAD',
        // Gender/religion classification codes printed on the physical card.
        'KHUNSA',
        'LELAKI',
        'PEREMPUAN',
        'ISLAM',
        'BUDDHA',
        'HINDU',
        'KRISTIAN',
        'LAIN-LAIN',
    ];

    /**
     * Patterns that signal the end of the address block (positional stop).
     * MyKad layout: IC → Name → Address lines → Date of birth / Gender / Religion block.
     */
    private const ADDRESS_STOP_PATTERN =
        '/\b(TARIKH\s*LAHIR|DATE\s*OF\s*BIRTH|JANTINA|SEX|AGAMA|RELIGION|BANGSA|RACE|WARGANEGARA|CITIZENSHIP)\b/i';

    /**
     * @param array{full_text:string,lines:array<int,string>} $ocrPayload
     * @return array{ic_number:?string,name:?string,address:?string}
     */
    public function extract(array $ocrPayload): array
    {
        // Gemini (and any future smart client) returns pre-extracted fields directly.
        // Skip all PHP heuristic parsing when this key is present.
        if (isset($ocrPayload['pre_extracted'])) {
            return [
                'ic_number' => $ocrPayload['pre_extracted']['ic_number'] ?? null,
                'name'      => $ocrPayload['pre_extracted']['name']      ?? null,
                'address'   => $ocrPayload['pre_extracted']['address']   ?? null,
            ];
        }

        $lines = $ocrPayload['lines'] ?? [];

        return [
            'ic_number' => $this->extractIcNumber($lines),
            'name'      => $this->extractName($lines),
            'address'   => $this->extractAddress($lines),
        ];
    }

    // -------------------------------------------------------------------------
    // IC Number
    // -------------------------------------------------------------------------

    /**
     * @param array<int,string> $lines
     */
    private function extractIcNumber(array $lines): ?string
    {
        foreach ($lines as $line) {
            // Substitute common OCR look-alike characters (O→0, I/l→1, S→5, B→8, etc.) before matching.
            $cleaned = $this->substituteOcrDigits($line);
            // Accept XXXXXX-XX-XXXX or 12 consecutive digits.
            if (preg_match('/\b(\d{6}-\d{2}-\d{4}|\d{12})\b/u', $cleaned, $matches) === 1) {
                return $matches[1];
            }
        }

        return null;
    }

    // -------------------------------------------------------------------------
    // Name
    // -------------------------------------------------------------------------

    /**
     * @param array<int,string> $lines
     */
    private function extractName(array $lines): ?string
    {
        // Strategy 1 — find a NAMA / NAME label line and use value on same or next line.
        foreach ($lines as $index => $line) {
            // Label pattern also handles "NAMA/" and "NAMA /" (MyKad slash separator)
            if (preg_match('/\bNAMA\b|\bNAME\b/i', $line) !== 1) {
                continue;
            }

            // Try value on the same line after the label.
            $candidate = $this->lineWithoutLabel($line, ['NAMA', 'NAME']);
            if ($candidate !== null && ! $this->isBlacklisted($candidate)) {
                return $this->extendNameWithContinuation($lines, $index, $candidate);
            }

            // Try the next non-empty line.
            for ($offset = 1; $offset <= 3; $offset++) {
                $next = trim($lines[$index + $offset] ?? '');
                if ($next === '') {
                    continue;
                }

                if ($this->definitelyName($next) || (! $this->isBlacklisted($next) && ! $this->looksLikeLabel($next))) {
                    return $this->extendNameWithContinuation($lines, $index + $offset, $next);
                }

                break;
            }
        }

        // Strategy 2 — heuristic: first uppercase Latin line that is not a
        // known label and does not look like an address or IC number.
        foreach ($lines as $index => $line) {
            $upper = mb_strtoupper(trim($line));
            if ($upper === '') {
                continue;
            }

            // Before standard checks, attempt to salvage the name from lines that begin
            // with known card artifact prefixes (e.g. "MYKAD C ROWAN SEBASTIAN").
            // This handles the case where OCR merges the card brand/logo with the actual name.
            $sanitized = $this->stripCardArtifactPrefix($upper);
            if ($sanitized !== $upper && $sanitized !== '') {
                if ($this->definitelyName($sanitized)
                    || preg_match('/^[A-Z][A-Z\s\.\'\-]{2,}$/u', $sanitized) === 1) {
                    return $this->extendNameWithContinuation($lines, $index, $sanitized);
                }
            }

            if ($this->isBlacklisted($upper)) {
                continue;
            }

            if ($this->containsAddressHint($upper)) {
                continue;
            }

            // Lines containing Malaysian name connectors (BIN/BINTI/A/L/A/P) are always names.
            if ($this->definitelyName($upper)) {
                return $this->extendNameWithContinuation($lines, $index, trim($line));
            }

            if ($this->looksLikeLabel($upper)) {
                continue;
            }

            // Must be a plausible name: only letters, spaces, dots, apostrophes, hyphens.
            if (preg_match('/^[A-Z][A-Z\s\.\'\-]{2,}$/u', $upper) === 1) {
                return $this->extendNameWithContinuation($lines, $index, trim($line));
            }
        }

        return null;
    }

    // -------------------------------------------------------------------------
    // Address
    // -------------------------------------------------------------------------

    /**
     * Malaysian MyKad has NO address label.
     * Layout order: card header → IC number → name → address lines → DOB/gender/religion block.
     * Strategy: find the name line index, then collect every non-empty line after it
     * until we hit a known stop pattern (DOB, gender, religion, etc.).
     * A 5-digit postcode line is included and treated as the last address line.
     *
     * @param array<int,string> $lines
     */
    private function extractAddress(array $lines): ?string
    {
        $nameIndex = $this->findNameLineIndex($lines);

        // If name could not be located, fall back to collecting from after the IC number line.
        $startAfter = $nameIndex ?? $this->findIcNumberLineIndex($lines);

        if ($startAfter === null) {
            return null;
        }

        $chunks = [];

        for ($i = $startAfter + 1; $i < count($lines); $i++) {
            $trimmed = trim($lines[$i]);

            if ($trimmed === '') {
                continue;
            }

            // Hard stop: DOB / gender / religion section reached.
            if (preg_match(self::ADDRESS_STOP_PATTERN, $trimmed) === 1) {
                break;
            }

            // Hard stop: line looks like an IC number (should not appear in address).
            if (preg_match('/\b\d{6}-\d{2}-\d{4}\b|\b\d{12}\b/', $trimmed) === 1) {
                break;
            }

            // Postcode line (exactly 5 digits, optionally followed by city/state text).
            if (preg_match('/^\d{5}\b/', $trimmed) === 1) {
                $chunks[] = $trimmed;
                // Peek at the next line — some cards put city/state on a separate line after the postcode.
                for ($j = $i + 1; $j < count($lines); $j++) {
                    $nextLine = trim($lines[$j]);
                    if ($nextLine === '') {
                        continue;
                    }
                    if (preg_match(self::ADDRESS_STOP_PATTERN, $nextLine) === 0
                        && preg_match('/^\d{5}\b/', $nextLine) === 0
                        && preg_match('/\b\d{6}[-\d]/', $nextLine) === 0
                        && ! $this->containsStreetHint($nextLine)
                        && preg_match('/^[A-Z\s,\.]+$/u', mb_strtoupper($nextLine)) === 1) {
                        $chunks[] = $nextLine;
                    }
                    break;
                }
                break;
            }

            $chunks[] = $trimmed;
        }

        if ($chunks === []) {
            return null;
        }

        return implode(', ', $chunks);
    }

    /**
     * @param array<int,string> $lines
     */
    private function findNameLineIndex(array $lines): ?int
    {
        $foundIndex = null;

        // Strategy 1: find a NAMA / NAME label line.
        foreach ($lines as $index => $line) {
            if (preg_match('/\bNAMA\b|\bNAME\b/i', $line) !== 1) {
                continue;
            }

            $candidate = $this->lineWithoutLabel($line, ['NAMA', 'NAME']);
            if ($candidate !== null && ! $this->isBlacklisted($candidate)) {
                $foundIndex = $index;
                break;
            }

            // Name is on the next line.
            for ($offset = 1; $offset <= 3; $offset++) {
                $next = trim($lines[$index + $offset] ?? '');
                if ($next === '') {
                    continue;
                }
                if ($this->definitelyName($next)
                    || (! $this->isBlacklisted($next) && ! $this->looksLikeLabel($next))) {
                    $foundIndex = $index + $offset;
                }
                break;
            }

            if ($foundIndex !== null) {
                break;
            }
        }

        // Strategy 2: positional heuristic — first plausible name line after IC number.
        if ($foundIndex === null) {
            $icIndex = $this->findIcNumberLineIndex($lines);
            if ($icIndex === null) {
                return null;
            }

            for ($i = $icIndex + 1; $i < count($lines); $i++) {
                $trimmed = trim($lines[$i]);
                $upper   = mb_strtoupper($trimmed);

                if ($upper === '' || $this->containsAddressHint($upper)) {
                    continue;
                }

                // Lines that start with card artifact prefixes (MYKAD) are still valid
                // name anchors — the sanitized remainder contains the actual name.
                $sanitized = $this->stripCardArtifactPrefix($upper);
                if ($sanitized !== $upper && $sanitized !== '') {
                    if ($this->definitelyName($sanitized)
                        || preg_match('/^[A-Z][A-Z\s\.\'\-]{2,}$/u', $sanitized) === 1) {
                        $foundIndex = $i;
                        break;
                    }
                }

                if ($this->isBlacklisted($upper)) {
                    continue;
                }

                if ($this->definitelyName($upper)
                    || (preg_match('/^[A-Z][A-Z\s\.\'\-]{2,}$/u', $upper) === 1 && ! $this->looksLikeLabel($upper))) {
                    $foundIndex = $i;
                    break;
                }
            }
        }

        if ($foundIndex === null) {
            return null;
        }

        // Extend to the last line of a multi-line name so address extraction starts after the full name.
        for ($i = $foundIndex + 1; $i < min($foundIndex + 3, count($lines)); $i++) {
            $next = trim($lines[$i] ?? '');
            if ($next !== '' && $this->isNameContinuation($next)) {
                $foundIndex = $i;
            } else {
                break;
            }
        }

        return $foundIndex;
    }

    /**
     * @param array<int,string> $lines
     */
    private function findIcNumberLineIndex(array $lines): ?int
    {
        foreach ($lines as $index => $line) {
            // Apply the same OCR look-alike substitution used in extractIcNumber so that
            // the positional anchor (used by name + address extraction) is consistent.
            if (preg_match('/\b(\d{6}-\d{2}-\d{4}|\d{12})\b/u', $this->substituteOcrDigits($line)) === 1) {
                return $index;
            }
        }

        return null;
    }

    // -------------------------------------------------------------------------
    // Helpers
    // -------------------------------------------------------------------------

    /**
     * Remove a leading label (NAMA, ALAMAT, etc.) from a line.
     *
     * @param array<int,string> $labels
     */
    private function lineWithoutLabel(string $line, array $labels): ?string
    {
        // Pattern also strips trailing slash separators common on MyKad.
        $pattern   = '/^\s*(?:'.implode('|', array_map('preg_quote', $labels)).')\s*[\/:\-]?\s*/iu';
        $candidate = preg_replace($pattern, '', $line);
        $candidate = is_string($candidate) ? trim($candidate) : '';

        return $candidate !== '' ? $candidate : null;
    }

    /**
     * Check if a value is a known blacklisted header / label line.
     */
    private function isBlacklisted(string $value): bool
    {
        $upper = mb_strtoupper(trim($value));

        foreach (self::BLACKLISTED_NAME_LINES as $blocked) {
            if (str_contains($upper, $blocked)) {
                return true;
            }
        }

        return false;
    }

    /**
     * Detect short all-caps label-only lines (e.g. "NAMA", "ALAMAT", "JANTINA").
     *
     * IMPORTANT: All known MyKad labels of 2+ words are already covered by BLACKLISTED_NAME_LINES.
     * This function is a catch-all for unknown single-word labels only.
     * Threshold is kept at 1 word so that real names with 2+ words (e.g. "TAN WEI MING",
     * "LIM KAI", "SITI SARAH") are never incorrectly rejected here.
     */
    private function looksLikeLabel(string $value): bool
    {
        $upper = mb_strtoupper(trim($value));

        return preg_match('/^\s*[A-Z\/\s]{2,20}\s*$/', $upper) === 1
            && preg_match('/\d/', $upper) === 0
            && str_word_count($upper) <= 1;
    }

    /**
     * Detect if a value contains strong address-like tokens.
     * Used to prevent address lines from being misidentified as the person's name.
     * State names are included here because they should not be picked as a name candidate,
     * but they ARE valid content inside an already-collected address chunk.
     */
    private function containsAddressHint(string $value): bool
    {
        return preg_match(
            '/\b(JALAN|JLN|LORONG|LRG|LEBUH|PERSIARAN|TAMAN|TMN|BANDAR|BDR|DESA|SRI|KAMPUNG|KG|PUSAT|NO\.|LOT|BLOK|BLOCK|FLAT|APARTMENT|RESIDENSI|PANGSAPURI|KONDOMINIUM|KOMPLEKS|ALAMAT|ADDRESS|POSKOD|POSTCODE|KUALA|LUMPUR|SELANGOR|PERAK|JOHOR|PENANG|PAHANG|KEDAH|PERLIS|MELAKA|SABAH|SARAWAK|TERENGGANU|KELANTAN)\b/i',
            $value
        ) === 1;
    }

    /**
     * Narrower address-hint check used for the postcode city/state peek.
     * Does NOT include state names because after a postcode, the next line containing
     * only a state name (e.g. "SABAH", "SELANGOR") IS valid city/state content and must
     * be included in the address, not blocked.
     */
    private function containsStreetHint(string $value): bool
    {
        return preg_match(
            '/\b(JALAN|JLN|LORONG|LRG|LEBUH|PERSIARAN|TAMAN|TMN|BANDAR|BDR|DESA|KAMPUNG|KG|PUSAT|NO\.|LOT|BLOK|BLOCK|FLAT|APARTMENT|RESIDENSI|PANGSAPURI|KONDOMINIUM|KOMPLEKS|ALAMAT|ADDRESS|POSKOD|POSTCODE)\b/i',
            $value
        ) === 1;
    }

    /**
     * Returns true if the line definitely contains a name (has a Malaysian name connector).
     * BIN / BINTI / A/L / A/P lines are always names — never labels.
     */
    private function definitelyName(string $value): bool
    {
        return preg_match('/\b(BIN|BINTI|A\/L|A\/P|ANAK|AL)\b/iu', $value) === 1;
    }

    /**
     * Try to extend a found name by appending continuation lines.
     * Handles multi-line names such as:
     *   Line N:   MUHAMMAD
     *   Line N+1: BIN AHMAD SAID
     *
     * @param array<int,string> $lines
     */
    private function extendNameWithContinuation(array $lines, int $foundIndex, string $firstName): string
    {
        $fullName = $firstName;

        for ($i = $foundIndex + 1; $i < min($foundIndex + 3, count($lines)); $i++) {
            $next = trim($lines[$i] ?? '');
            if ($next === '') {
                break;
            }
            if ($this->isNameContinuation($next)) {
                $fullName .= ' ' . $next;
            } else {
                break;
            }
        }

        return $fullName;
    }

    /**
     * Check if a line is a plausible continuation of a name (second / third line of a long name).
     */
    private function isNameContinuation(string $line): bool
    {
        $upper = mb_strtoupper(trim($line));
        if ($upper === '') {
            return false;
        }

        // A line with a Malaysian name connector is unambiguously a name continuation.
        if ($this->definitelyName($upper)) {
            return true;
        }

        // Otherwise it must look like a name fragment and not be a label or address.
        // NOTE: looksLikeLabel() is intentionally NOT applied here. This function only runs
        // after a name anchor has already been found (safe positional context). Single-word
        // surnames (ATKINSON, WONG, TAN, LIM, etc.) are structurally identical to single-word
        // labels — the blacklist and address-hint checks are sufficient discrimination.
        return preg_match('/^[A-Z][A-Z\s\.\'\-]{2,}$/u', $upper) === 1
            && ! $this->isBlacklisted($upper)
            && ! $this->containsAddressHint($upper);
    }

    /**
     * Strip known card artifact prefixes from a line before evaluating it as a name candidate.
     *
     * The physical MyKad card prints "MyKad" as a brand label plus a copyright/logo symbol (©).
     * OCR frequently merges these with the person's name onto a single line, e.g.:
     *   "MYKAD C ROWAN SEBASTIAN"  ← "MYKAD" = brand label, "C" = © symbol, rest = real name
     *
     * We strip the prefix and return the cleaned remainder so the actual name is not lost.
     * This is applied BEFORE the blacklist check so the salvaged name can still be returned.
     *
     * @param string $upper Already uppercased value.
     */
    private function stripCardArtifactPrefix(string $upper): string
    {
        // Strip the "MYKAD" card identifier from the start.
        $stripped = preg_replace('/^MYKAD\s*/u', '', $upper);
        $stripped = is_string($stripped) ? $stripped : $upper;

        if ($stripped === $upper) {
            return $upper; // Nothing was stripped; return unchanged.
        }

        // After stripping MYKAD, remove a stray single-character artifact that immediately
        // follows (e.g. "C" from the © symbol). Only remove it when a word character follows
        // so we don't strip a legitimate single-letter initial like "A".
        $stripped = preg_replace('/^[A-Z]\s+(?=[A-Z])/u', '', $stripped);
        $stripped = is_string($stripped) ? trim($stripped) : '';

        return $stripped;
    }

    /**
     * Substitute common OCR character look-alikes to improve digit sequence recognition.
     * Applied only when trying to detect the IC number pattern; the substituted string is
     * never stored — the matched (digit-only) value is what gets returned.
     */
    private function substituteOcrDigits(string $value): string
    {
        return strtr($value, [
            'O' => '0', 'o' => '0',
            'I' => '1', 'l' => '1',
            'S' => '5',
            'B' => '8',
            'Z' => '2',
            'G' => '6',
        ]);
    }
}
