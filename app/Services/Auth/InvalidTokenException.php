<?php

namespace App\Services\Auth;

use RuntimeException;

/** JWT ditolak verifikasi. Pesan hanya berisi kategori, tanpa isi token. */
final class InvalidTokenException extends RuntimeException {}
