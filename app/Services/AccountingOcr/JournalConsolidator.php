<?php

namespace App\Services\AccountingOcr;

class JournalConsolidator
{
    /** Group matched entries only when account, treatment and debit/credit side agree. */
    public function consolidate(array $lines): array
    {
        $result = [];
        $groups = [];
        foreach ($lines as $index => $line) {
            $line['source_lines'] = [$index + 1];
            $line['audit_support'] = [['source_line' => $index + 1, 'debit' => $line['debit'], 'credit' => $line['credit'], 'reason' => $line['reason'], 'evidence' => $line['evidence'], 'confidence' => $line['confidence']]];
            $key = $line['account_code'] !== null && $line['error_code'] === null
                ? json_encode([$line['account_code'], $line['required_account'], $line['debit'] > 0 ? 'debit' : 'credit'], JSON_THROW_ON_ERROR)
                : null;
            if ($key === null || ! isset($groups[$key])) {
                if ($key !== null) {
                    $groups[$key] = count($result);
                }
                $result[] = $line;

                continue;
            }
            $target = &$result[$groups[$key]];
            $target['debit'] = round($target['debit'] + $line['debit'], 2);
            $target['credit'] = round($target['credit'] + $line['credit'], 2);
            $target['confidence'] = min($target['confidence'], $line['confidence']);
            $target['source_lines'][] = $index + 1;
            $target['audit_support'][] = $line['audit_support'][0];
            $target['reason'] = 'Consolidated entries for the same account and treatment; see audit_support for each source line.';
            $target['evidence'] = 'See audit_support for all source evidence.';
            unset($target);
        }

        return $result;
    }
}
