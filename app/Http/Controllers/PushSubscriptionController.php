<?php

namespace App\Http\Controllers;

use App\Services\Push\PushSubscriptions;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/** Perangkat kasir mendaftar/berhenti menerima notifikasi pesanan baru (Web Push). */
final class PushSubscriptionController
{
    public function __construct(private readonly PushSubscriptions $subscriptions) {}

    public function store(Request $request): JsonResponse
    {
        $data = $request->validate([
            'endpoint' => ['required', 'string', 'max:2048'],
            'keys.p256dh' => ['required', 'string', 'regex:/^[A-Za-z0-9_-]{80,100}={0,2}$/'],
            'keys.auth' => ['required', 'string', 'regex:/^[A-Za-z0-9_-]{16,30}={0,2}$/'],
            'contentEncoding' => ['nullable', 'string', 'in:aes128gcm,aesgcm'],
        ]);
        $this->subscriptions->store($request->user(), [
            'endpoint' => $data['endpoint'],
            'p256dh' => rtrim($data['keys']['p256dh'], '='),
            'auth' => rtrim($data['keys']['auth'], '='),
            'contentEncoding' => $data['contentEncoding'] ?? 'aes128gcm',
        ], $request->userAgent());

        return response()->json(['ok' => true]);
    }

    public function destroy(Request $request): JsonResponse
    {
        $data = $request->validate(['endpoint' => ['required', 'string', 'max:2048']]);
        $this->subscriptions->delete($request->user(), $data['endpoint']);

        return response()->json(['ok' => true]);
    }
}
