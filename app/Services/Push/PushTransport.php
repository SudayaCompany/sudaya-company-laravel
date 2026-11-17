<?php

namespace App\Services\Push;

/** Pengirim pesan Web Push; diganti tiruan di test agar tidak menghubungi layanan push sungguhan. */
interface PushTransport
{
    /**
     * @param  list<object{endpoint: string, p256dh: string, auth: string, content_encoding: string}>  $subscriptions
     * @return list<string> endpoint yang sudah tidak berlaku (404/410): langganannya dihapus
     */
    public function send(array $subscriptions, string $payload): array;
}
