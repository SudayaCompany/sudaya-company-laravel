<?php

// pg_restore palsu: --list mencetak daftar isi; --data-only --file=- mencetak SQL.
// Keduanya membaca dump dari stdin dan menolak yang bukan PGDMP.
$input = stream_get_contents(STDIN);
if (! str_starts_with((string) $input, 'PGDMP')) {
    fwrite(STDERR, "pg_restore: error: input file does not appear to be a valid archive\n");
    exit(1);
}
if (in_array('--list', $argv, true)) {
    echo ";\n; Archive created\n;\n";
    echo "4021; 0 17001 TABLE DATA public orders postgres\n";
    echo "4022; 0 17002 TABLE DATA public products postgres\n";
    echo "4023; 0 16500 TABLE DATA auth users supabase_auth_admin\n";
    echo "120; 1259 17003 TABLE public orders postgres\n";
    exit(0);
}
echo '-- args: '.implode(' ', array_slice($argv, 1))."\nCOPY public.orders (id) FROM stdin;\n1\n\.\n";
exit(0);
