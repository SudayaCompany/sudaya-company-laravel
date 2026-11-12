<?php

namespace Tests\Unit;

use App\Exceptions\AccessException;
use App\Services\Orders\CheckoutInput;
use App\Services\Orders\CheckoutLimits;
use App\Services\Orders\GuestTokens;
use App\Services\Orders\OrderEngine;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

/** Padanan tests/unit/orders.test.ts (validasi checkout, hash idempotensi, token guest, rate limit). */
class OrdersTest extends TestCase
{
    private const KEY = '9b2f6a52-3f0e-4c1c-9d0b-2a8f7e6c5d41';

    private static function id(string $suffix): string
    {
        return '00000000-0000-4000-8000-'.str_pad($suffix, 12, '0', STR_PAD_LEFT);
    }

    private static function checkout(array $overrides = []): array
    {
        return [
            'idempotencyKey' => self::KEY,
            'type' => 'takeaway',
            'customerName' => 'Budi',
            'paymentMethod' => 'cash',
            'lines' => [['productId' => self::id('1'), 'modifierIds' => [], 'quantity' => 1]],
            ...$overrides,
        ];
    }

    private static function parseError(array $raw): ?string
    {
        try {
            CheckoutInput::parse($raw);

            return null;
        } catch (AccessException $e) {
            return $e->getMessage();
        }
    }

    public function test_parses_minimal_checkout_and_normalizes_fields(): void
    {
        $input = CheckoutInput::parse(self::checkout([
            'idempotencyKey' => strtoupper(self::KEY),
            'type' => 'dine_in',
            'customerName' => '  Budi  ',
            'customerPhone' => '+62 812-3456-7890',
            'tableNumber' => 't03',
            'notes' => '   ',
        ]));

        $this->assertSame(self::KEY, $input['idempotencyKey']);
        $this->assertSame('Budi', $input['customerName']);
        $this->assertSame('081234567890', $input['customerPhone']);
        $this->assertSame('T03', $input['tableNumber']);
        $this->assertNull($input['notes']);
        $this->assertSame([['productId' => self::id('1'), 'modifierIds' => [], 'quantity' => 1, 'notes' => null]], $input['lines']);
    }

    public static function invalidCheckouts(): array
    {
        $line = ['productId' => self::id('1'), 'modifierIds' => [], 'quantity' => 1];

        return [
            'tanpa idempotency key' => [['idempotencyKey' => 'abc'], 'Permintaan tidak valid. Muat ulang halaman.'],
            'tipe tidak dikenal' => [['type' => 'drive_thru'], 'Pilih tipe pesanan.'],
            'HP tidak valid' => [['customerPhone' => '12345'], 'Nomor HP tidak valid (contoh 0812xxxxxxx).'],
            'meja dengan simbol' => [['tableNumber' => 'T 03'], "Nomor meja hanya huruf, angka, dan '-'."],
            'metode bayar asing' => [['paymentMethod' => 'free'], 'Pilih metode pembayaran.'],
            'keranjang kosong' => [['lines' => []], 'Keranjang masih kosong.'],
            'lebih dari 50 baris' => [['lines' => array_fill(0, 51, $line)], 'Maksimal 50 item per pesanan.'],
            'jumlah 0' => [['lines' => [[...$line, 'quantity' => 0]]], 'Input tidak valid.'],
            'jumlah 51' => [['lines' => [[...$line, 'quantity' => 51]]], 'Input tidak valid.'],
            'jumlah pecahan' => [['lines' => [[...$line, 'quantity' => 1.5]]], 'Input tidak valid.'],
            'jumlah string' => [['lines' => [[...$line, 'quantity' => '2']]], 'Input tidak valid.'],
            'product id bukan uuid' => [['lines' => [[...$line, 'productId' => '1']]], 'Input tidak valid.'],
            'modifier id bukan uuid' => [['lines' => [[...$line, 'modifierIds' => ['x']]]], 'Input tidak valid.'],
            'catatan item 141' => [['lines' => [[...$line, 'notes' => str_repeat('a', 141)]]], 'Catatan item maksimal 140 karakter.'],
            'catatan order 301' => [['notes' => str_repeat('a', 301)], 'Catatan pesanan maksimal 300 karakter.'],
            'nama 61' => [['customerName' => str_repeat('a', 61)], 'Nama maksimal 60 karakter.'],
            'delivery tanpa HP' => [['type' => 'delivery', 'deliveryAddress' => 'Jl. Merdeka No. 10'], 'Nomor HP wajib untuk delivery.'],
            'delivery alamat pendek' => [['type' => 'delivery', 'customerPhone' => '081234567890', 'deliveryAddress' => 'Jl. A'], 'Alamat delivery wajib diisi lengkap (min. 10 karakter).'],
            'delivery tanpa nama' => [['type' => 'delivery', 'customerName' => null, 'customerPhone' => '081234567890', 'deliveryAddress' => 'Jl. Merdeka No. 10'], 'Nama wajib untuk delivery (min. 2 karakter).'],
            // Client tidak bisa menyelipkan harga atau status: field asing diabaikan, bukan dipakai.
        ];
    }

    #[DataProvider('invalidCheckouts')]
    public function test_rejects_invalid_checkout(array $overrides, string $message): void
    {
        $this->assertSame($message, self::parseError(self::checkout($overrides)));
    }

    public function test_ignores_client_supplied_price_status_and_total(): void
    {
        $input = CheckoutInput::parse(self::checkout([
            'total' => 1,
            'status' => 'paid',
            'paymentStatus' => 'paid',
            'lines' => [['productId' => self::id('1'), 'modifierIds' => [], 'quantity' => 1, 'price' => 1, 'unitPrice' => 1]],
        ]));

        $this->assertArrayNotHasKey('total', $input);
        $this->assertArrayNotHasKey('status', $input);
        $this->assertArrayNotHasKey('paymentStatus', $input);
        $this->assertSame(['productId', 'modifierIds', 'quantity', 'notes'], array_keys($input['lines'][0]));
    }

    public function test_public_channels_require_customer_name(): void
    {
        $this->assertSame('Nama pemesan wajib diisi (min. 2 karakter).', CheckoutInput::requireCustomerName(CheckoutInput::parse(self::checkout(['customerName' => null]))));
        $this->assertSame('Nama pemesan wajib diisi (min. 2 karakter).', CheckoutInput::requireCustomerName(CheckoutInput::parse(self::checkout(['customerName' => 'B']))));
        $this->assertNull(CheckoutInput::requireCustomerName(CheckoutInput::parse(self::checkout())));
    }

    public function test_department_wajib_dan_lokasi_antar_ke_meja(): void
    {
        // Keputusan owner 12 November 2026: setiap pesanan pelanggan mencantumkan department (ketik bebas);
        // antar ke meja wajib lantai + bagian/area meja, nomor WhatsApp opsional.
        $desk = CheckoutInput::parse(self::checkout(['type' => 'dine_in', 'floor' => '3', 'area' => 'dekat jendela', 'department' => 'Fundraising']));
        $this->assertSame(['Lantai 3 · dekat jendela', 'Fundraising', null], [$desk['locationNote'], $desk['department'], $desk['customerPhone']]);
        $this->assertNull(CheckoutInput::requireDepartment($desk));
        $this->assertNull(CheckoutInput::requireDeskLocation('qr_table', $desk));

        $this->assertSame('Department wajib diisi (mis. Fundraising).', CheckoutInput::requireDepartment(CheckoutInput::parse(self::checkout())));
        $this->assertSame('Department wajib diisi (mis. Fundraising).', CheckoutInput::requireDepartment(CheckoutInput::parse(self::checkout(['department' => ' x ']))));
        $noArea = CheckoutInput::parse(self::checkout(['type' => 'dine_in', 'floor' => '3', 'department' => 'Fundraising']));
        $this->assertSame('Isi lantai dan bagian/area meja agar pesanan bisa diantar.', CheckoutInput::requireDeskLocation('qr_table', $noArea));
        // Ambil di counter atau channel lain: tanpa lantai/area.
        $this->assertNull(CheckoutInput::requireDeskLocation('qr_table', CheckoutInput::parse(self::checkout(['department' => 'Fundraising']))));
        $this->assertNull(CheckoutInput::requireDeskLocation('kiosk', $noArea));
    }

    public function test_request_hash_matches_nextjs_reference(): void
    {
        // Nilai dari algoritma src/lib/orders/request-hash.ts (Next.js) untuk sampel yang sama:
        // modifier tidak terurut, unicode, "/" dan U+2028 tidak di-escape seperti JSON.stringify.
        $input = CheckoutInput::parse([
            'idempotencyKey' => self::KEY,
            'type' => 'dine_in',
            'customerName' => 'Budi Ñandú',
            'customerPhone' => '081234567890',
            'tableNumber' => 'T03',
            'locationNote' => 'teras/samping',
            'notes' => "less ice \u{2028} ☕",
            'paymentMethod' => 'cash',
            'lines' => [
                ['productId' => self::id('1'), 'modifierIds' => [self::id('b'), self::id('a')], 'quantity' => 2, 'notes' => 'tanpa gula'],
                ['productId' => self::id('2'), 'modifierIds' => [], 'quantity' => 1],
            ],
        ]);

        $this->assertSame('3896598de84b0a4b1671678751e68f874956d50ee3920a91562effe56d4f866c', CheckoutInput::requestHash($input));
    }

    public function test_request_hash_ignores_key_and_irrelevant_fields_but_tracks_content(): void
    {
        $base = CheckoutInput::requestHash(CheckoutInput::parse(self::checkout()));

        $this->assertSame($base, CheckoutInput::requestHash(CheckoutInput::parse(self::checkout(['idempotencyKey' => '1b2f6a52-3f0e-4c1c-9d0b-2a8f7e6c5d41']))));
        // Nomor meja tidak disimpan untuk takeaway, jadi tidak mengubah sidik.
        $this->assertSame($base, CheckoutInput::requestHash(CheckoutInput::parse(self::checkout(['tableNumber' => 'T01']))));
        $this->assertNotSame($base, CheckoutInput::requestHash(CheckoutInput::parse(self::checkout(['lines' => [['productId' => self::id('1'), 'modifierIds' => [], 'quantity' => 2]]]))));
        $this->assertNotSame($base, CheckoutInput::requestHash(CheckoutInput::parse(self::checkout(['customerName' => 'Sari']))));
    }

    public function test_guest_tokens_store_only_hash_and_verify_in_constant_time(): void
    {
        $first = GuestTokens::create();
        $second = GuestTokens::create();

        $this->assertMatchesRegularExpression('/^[A-Za-z0-9_-]{43}$/', $first['token']);
        $this->assertNotSame($first['token'], $second['token']);
        $this->assertSame(hash('sha256', $first['token']), $first['hash']);
        $this->assertTrue(GuestTokens::verify($first['token'], $first['hash']));
        $this->assertFalse(GuestTokens::verify($second['token'], $first['hash']));
        $this->assertFalse(GuestTokens::verify(null, $first['hash']));
        $this->assertFalse(GuestTokens::verify('', $first['hash']));
        $this->assertFalse(GuestTokens::verify($first['token'], null));
        $this->assertFalse(GuestTokens::verify(str_repeat('a', 101), $first['hash']));

        $this->assertSame('sdy_order_0000000000004000800000000000000a', GuestTokens::cookieName(self::id('a')));
        $this->assertSame('order:'.substr($first['hash'], 0, 40), GuestTokens::topic($first['hash']));
        $this->assertStringNotContainsString($first['token'], GuestTokens::topic($first['hash']));
    }

    public function test_checkout_limits_per_device_ip_kiosk_and_replay(): void
    {
        $device = CheckoutLimits::newDeviceId();
        $this->assertSame($device, CheckoutLimits::readDeviceId($device));
        $this->assertNull(CheckoutLimits::readDeviceId('short'));
        $this->assertNull(CheckoutLimits::readDeviceId(['array']));

        $this->assertSame([
            ['key' => "checkout:device:{$device}", 'limit' => 6, 'windowSeconds' => 600],
            ['key' => 'checkout:ip:203.0.113.5', 'limit' => 60, 'windowSeconds' => 600],
        ], CheckoutLimits::rules('qr_table', 'skt', $device, '203.0.113.5', false));

        $kiosk = CheckoutLimits::rules('kiosk', 'SKT', $device, null, false);
        $this->assertSame(40, $kiosk[0]['limit']);
        $this->assertSame(['key' => 'checkout:outlet:SKT', 'limit' => 120, 'windowSeconds' => 600], $kiosk[1]);

        $replay = CheckoutLimits::rules('website', 'SKT', $device, '203.0.113.5', true);
        $this->assertSame(["checkout-replay:device:{$device}", 'checkout-replay:ip:203.0.113.5'], array_column($replay, 'key'));
        $this->assertSame([120, 240], array_column($replay, 'limit'));
    }

    public function test_reference_is_first_six_hex_uppercased(): void
    {
        $this->assertSame('9B2F6A', OrderEngine::reference(self::KEY));
        $this->assertSame('outlet-orders:abc', OrderEngine::outletTopic('abc'));
    }
}
