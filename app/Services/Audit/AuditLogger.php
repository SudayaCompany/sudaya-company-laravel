<?php

namespace App\Services\Audit;

use Illuminate\Support\Facades\DB;

final class AuditLogger
{
    /**
     * @param  array{company_id: string, actor_id: ?string, action: string, brand_id?: ?string, outlet_id?: ?string, entity_type?: ?string, entity_id?: ?string, before?: ?array<string, mixed>, after?: ?array<string, mixed>, reason?: ?string}  $entry
     */
    public function record(array $entry, ?AuditContext $context = null): void
    {
        DB::table('audit_logs')->insert([
            'company_id' => $entry['company_id'],
            'brand_id' => $entry['brand_id'] ?? null,
            'outlet_id' => $entry['outlet_id'] ?? null,
            'actor_id' => $entry['actor_id'],
            'action' => $entry['action'],
            'entity_type' => $entry['entity_type'] ?? null,
            'entity_id' => $entry['entity_id'] ?? null,
            'before' => isset($entry['before']) ? json_encode($entry['before']) : null,
            'after' => isset($entry['after']) ? json_encode($entry['after']) : null,
            'reason' => $entry['reason'] ?? null,
            'ip' => $context?->ip === null ? null : substr($context->ip, 0, 64),
            'user_agent' => $context?->userAgent === null ? null : substr($context->userAgent, 0, 512),
            // Waktu DB saat insert (bukan awal transaksi): beberapa event dalam satu
            // transaksi tetap berurutan.
            'created_at' => DB::raw('clock_timestamp()'),
        ]);
    }
}
