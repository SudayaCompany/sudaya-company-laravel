<?php

namespace App\Services\Push;

use App\Services\Auth\AccessLoader;
use App\Services\Orders\OrderEngine;
use App\Support\Access\AccessScope;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Throwable;

use function Illuminate\Support\defer;

/**
 * Notifikasi "Pesanan baru" ke perangkat kasir walau aplikasinya tertutup (Web Push, permintaan owner
 * 17 November 2026). Pemicunya sama dengan alarm di aplikasi: pesanan pelanggan baru (QR meja, web,
 * kiosk), bukan pesanan yang dibuat kasir sendiri. Penerimanya user aktif yang boleh memproses
 * pesanan di outlet itu (order.update_status), diperiksa saat mengirim.
 */
final class NewOrderPush
{
    public function __construct(
        private readonly PushTransport $transport,
        private readonly AccessLoader $access,
    ) {}

    /** Dipanggil di dalam transaksi checkout: dikirim setelah commit dan setelah respons ke pelanggan. */
    public function queue(string $orderId): void
    {
        DB::afterCommit(fn () => defer(fn () => $this->send($orderId)));
    }

    /** @return int jumlah perangkat yang dikirimi */
    public function send(string $orderId): int
    {
        try {
            $order = DB::table('orders')->where('id', $orderId)
                ->first(['id', 'company_id', 'outlet_id', 'order_number', 'customer_name', 'channel', 'type', 'status', 'location_note']);
            if ($order === null || $order->channel === 'pos') {
                return 0;
            }
            $subscriptions = DB::table('push_subscriptions')->where('company_id', $order->company_id)
                ->get(['user_id', 'endpoint', 'p256dh', 'auth', 'content_encoding']);
            if ($subscriptions->isEmpty()) {
                return 0;
            }
            $allowed = [];
            foreach ($subscriptions->pluck('user_id')->unique() as $userId) {
                $user = $this->access->currentUser($userId);
                $allowed[$userId] = $user !== null && $user->isStaffActive() && $user->can('order.update_status', AccessScope::outlet($order->outlet_id));
            }
            $targets = $subscriptions->filter(fn ($subscription) => $allowed[$subscription->user_id])->values()->all();
            if ($targets === []) {
                return 0;
            }
            $expired = $this->transport->send($targets, (string) json_encode(self::payload($order), JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES));
            if ($expired !== []) {
                DB::table('push_subscriptions')->whereIn('endpoint', $expired)->delete();
            }

            return count($targets);
        } catch (Throwable $error) {
            // Notifikasi gagal tidak boleh mengganggu pesanan; alarm di aplikasi tetap jalan.
            Log::warning('web_push_new_order_failed', ['order' => $orderId, 'message' => mb_substr($error->getMessage(), 0, 300)]);

            return 0;
        }
    }

    /**
     * Isi notifikasi, sama dengan banner alarm di aplikasi kasir.
     *
     * @return array{title: string, body: string, url: string, tag: string, orderId: string}
     */
    public static function payload(object $order): array
    {
        $parts = [
            $order->customer_name ?: 'Tanpa nama',
            $order->channel === 'qr_table' && $order->type === 'dine_in' ? 'Antar ke meja' : 'Ambil di counter',
        ];
        if ($order->location_note) {
            $parts[] = $order->location_note;
        }
        if ($order->status === 'pending_payment') {
            $parts[] = 'menunggu bayar';
        }

        return [
            'title' => 'Pesanan baru '.($order->order_number ?? OrderEngine::reference($order->id)),
            'body' => implode(' · ', $parts),
            'url' => '/kds?outlet='.$order->outlet_id,
            'tag' => 'sudaya-pesanan-baru',
            'orderId' => $order->id,
        ];
    }
}
