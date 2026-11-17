<?php

namespace App\Services\Push;

use Illuminate\Support\Facades\Log;
use Minishlink\WebPush\Subscription;
use Minishlink\WebPush\WebPush;

/** Web Push sungguhan (RFC 8030/8291/8292) lewat minishlink/web-push. */
final class WebPushTransport implements PushTransport
{
    public function __construct(private readonly PushKeys $keys) {}

    public function send(array $subscriptions, string $payload): array
    {
        $keys = $this->keys->keys();
        $push = new WebPush(
            ['VAPID' => ['subject' => $this->keys->subject(), 'publicKey' => $keys['publicKey'], 'privateKey' => $keys['privateKey']]],
            // Pesanan baru: kirim segera (HP hemat daya tetap dibangunkan); tidak berguna setelah 1 jam.
            ['TTL' => 3600, 'urgency' => 'high'],
        );
        foreach ($subscriptions as $subscription) {
            $push->queueNotification(new Subscription($subscription->endpoint, $subscription->p256dh, $subscription->auth, $subscription->content_encoding), $payload);
        }

        $expired = [];
        foreach ($push->flush() as $report) {
            if ($report->isSubscriptionExpired()) {
                $expired[] = $report->getEndpoint();
            } elseif (! $report->isSuccess()) {
                // Tanpa isi pesan dan tanpa endpoint lengkap (endpoint = alamat perangkat).
                Log::warning('web_push_failed', [
                    'host' => parse_url($report->getEndpoint(), PHP_URL_HOST),
                    'status' => $report->getResponse()?->getStatusCode(),
                    'reason' => mb_substr($report->getReason(), 0, 200),
                ]);
            }
        }

        return $expired;
    }
}
