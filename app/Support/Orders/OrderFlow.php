<?php

namespace App\Support\Orders;

/**
 * State machine order (PRD §107), padanan lib/orders/state.ts. Status produksi
 * (orders.status) terpisah dari status pembayaran (orders.payment_status).
 */
final class OrderFlow
{
    public const STATUSES = ['draft', 'pending_payment', 'paid', 'queued', 'preparing', 'ready', 'completed', 'cancelled', 'refunded'];

    /** Order yang tampil di KDS: sudah dibayar dan belum selesai. */
    public const ACTIVE_QUEUE = ['queued', 'preparing', 'ready'];

    /** Status yang tidak bisa diubah lagi lewat KDS. */
    public const TERMINAL = ['completed', 'cancelled', 'refunded'];

    /** Transisi produksi normal (KDS). Pembayaran dan pembatalan punya alur sendiri. */
    private const FORWARD = ['queued' => 'preparing', 'preparing' => 'ready', 'ready' => 'completed'];

    /** Mundur satu langkah untuk koreksi salah tekan; butuh kds.manage. */
    private const BACKWARD = ['preparing' => 'queued', 'ready' => 'preparing'];

    /** Kolom waktu tiap tahap produksi. */
    public const TIMESTAMPS = ['preparing' => 'preparing_at', 'ready' => 'ready_at', 'completed' => 'completed_at'];

    /** @return 'normal'|'override'|null */
    public static function transitionKind(string $from, string $to): ?string
    {
        if ((self::FORWARD[$from] ?? null) === $to) {
            return 'normal';
        }
        if ((self::BACKWARD[$from] ?? null) === $to) {
            return 'override';
        }

        return null;
    }

    /** 1 → "SDY-001" (PRD §37). */
    public static function orderNumber(string $prefix, int $queueNumber): string
    {
        return $prefix.'-'.str_pad((string) $queueNumber, 3, '0', STR_PAD_LEFT);
    }
}
