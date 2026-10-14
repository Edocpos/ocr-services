<?php

namespace App\Services\AccountingOcr;

class AccountingOcrResponse
{
    /** Keep accounting diagnostics internal; expose only the proposal and actionable validation. */
    public function format(array $result): array
    {
        $lines = $result['data']['journal_entry']['lines'];
        $sourceMap = [];
        $publicLines = [];
        foreach ($lines as $index => $line) {
            foreach ($line['source_lines'] as $sourceIndex) {
                $sourceMap[$sourceIndex] = $index;
            }
            $recommendation = $line['recommendation'];
            $publicLines[] = [
                'account_code' => $line['account_code'],
                'account_name' => $this->accountName($line, $result['data']['extracted']['counterparty']),
                'description' => count($line['audit_support']) > 1 ? ($result['data']['extracted']['description'] ?? $line['description']) : $line['description'],
                'debit' => number_format($line['debit'], 2, '.', ''),
                'credit' => number_format($line['credit'], 2, '.', ''),
                'confidence' => $line['confidence'],
                'reason' => implode('; ', array_unique(array_column($line['audit_support'], 'reason'))),
                'evidence' => implode('; ', array_unique(array_column($line['audit_support'], 'evidence'))),
                'recommendation' => $recommendation === null ? null : [
                    'parent_definition_key' => $recommendation['parent_definition_key'],
                    'parent_code' => $recommendation['parent_code'],
                    'account_type' => $recommendation['account_type'],
                    'account_subtype' => $recommendation['account_subtype'],
                ],
            ];
        }

        return [
            'data' => [
                'extracted' => array_intersect_key($result['data']['extracted'], array_flip(['transaction_date', 'reference_number', 'counterparty', 'description', 'currency'])),
                'classification' => ['voucher_type' => $result['data']['classification']['voucher_type']],
                'journal_entry' => ['lines' => $publicLines],
            ],
            'validation' => [
                'status' => $result['validation']['status'],
                'is_postable' => $result['validation']['is_postable'],
                'requires_manual_review' => $result['validation']['requires_manual_review'],
                'warnings' => $this->issues($result['validation']['warnings'], $sourceMap),
                'errors' => $this->issues($result['validation']['errors'], $sourceMap),
            ],
        ];
    }

    private function accountName(array $line, ?string $counterparty): ?string
    {
        if ($line['account_name'] !== null) {
            return $line['account_name'];
        }
        $recommendation = $line['recommendation'];
        if ($recommendation !== null && ($recommendation['requires_control_account'] ?? false) === false
            && in_array($recommendation['role'], ['trade_payable', 'other_payable'], true)
            && trim($counterparty ?? '') !== '') {
            return trim($counterparty);
        }

        return $recommendation['suggested_name'] ?? null;
    }

    private function issues(array $issues, array $sourceMap): array
    {
        $result = [];
        foreach ($issues as $issue) {
            if (preg_match('/^journal_entry\.source_lines\.(\d+)$/', $issue['field'], $match)) {
                $issue['field'] = isset($sourceMap[(int) $match[1]]) ? 'journal_entry.lines['.$sourceMap[(int) $match[1]].']' : 'journal_entry';
            }
            $result[json_encode($issue, JSON_THROW_ON_ERROR)] = $issue;
        }

        return array_values($result);
    }
}
