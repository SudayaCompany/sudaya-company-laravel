import { Head, router } from "@inertiajs/react";
import { ArrowDownLeftIcon, ArrowUpRightIcon, Loader2Icon, LockIcon, TriangleAlertIcon, Undo2Icon, WalletIcon } from "lucide-react";
import { useState } from "react";

import { MoneyInput } from "@/components/money-input";
import { Badge } from "@/components/ui/badge";
import { Button } from "@/components/ui/button";
import { Card, CardContent, CardDescription, CardHeader, CardTitle } from "@/components/ui/card";
import { Dialog, DialogContent, DialogDescription, DialogFooter, DialogHeader, DialogTitle } from "@/components/ui/dialog";
import { Label } from "@/components/ui/label";
import { NativeSelect, NativeSelectOption } from "@/components/ui/native-select";
import { Textarea } from "@/components/ui/textarea";
import { PosLayout } from "@/layouts/pos-layout";
import { formatRupiah } from "@/lib/format";
import { amountFromDigits } from "@/lib/money";
import { cn } from "@/lib/utils";

const MOVEMENT_LABELS = { cash_in: "Kas masuk", cash_out: "Kas keluar", refund: "Refund" } as const;
const MIN_REASON = 3;

type Totals = { openingAmount: number; cashSales: number; cashIn: number; cashOut: number; refunds: number; expected: number };
type Current = { id: string; openedAt: string; openedByName: string; fromEarlierDay: boolean; totals: Totals };
type Movement = { id: string; type: keyof typeof MOVEMENT_LABELS; amount: number; reason: string; createdAt: string; createdByName: string | null };
type Closed = { id: string; openedAt: string; closedAt: string; openedByName: string; expected: number; actual: number; reason: string | null };

type Props = {
  outlet: { id: string; name: string; timezone: string };
  outlets: { id: string; name: string }[];
  canOpen: boolean;
  canClose: boolean;
  current: Current | null;
  movements: Movement[];
  history: Closed[];
};

function useAction() {
  const [pending, setPending] = useState(false);
  const run = (url: string, data: Record<string, unknown>, onSuccess?: () => void) =>
    router.post(url, data as never, {
      preserveScroll: true,
      onStart: () => setPending(true),
      onFinish: () => setPending(false),
      onFlash: (flash) => {
        if ((flash as { success?: string }).success) onSuccess?.();
      },
    });
  return { pending, run };
}

/** "Cocok", "Uang lebih Rp …", atau "Uang kurang Rp …" dari selisih uang fisik − saldo sistem. */
function varianceLabel(variance: number) {
  if (variance === 0) return "Cocok";
  return variance > 0 ? `Uang lebih ${formatRupiah(variance)}` : `Uang kurang ${formatRupiah(-variance)}`;
}

function ReasonHint({ value }: { value: string }) {
  const short = value.trim().length < MIN_REASON;
  return <p className={cn("text-xs", short ? "text-muted-foreground" : "text-success-strong")}>{short ? `Wajib diisi, minimal ${MIN_REASON} karakter.` : "Alasan sudah cukup."}</p>;
}

/** Sesi kas. Angka di layar hanya tampilan; saldo dan selisih dihitung ulang di server. */
export default function CashPage({ outlet, outlets, canOpen, canClose, current, movements, history }: Props) {
  const stamp = new Intl.DateTimeFormat("id-ID", { dateStyle: "medium", timeStyle: "short", timeZone: outlet.timezone });
  const format = (iso: string | null) => (iso ? stamp.format(new Date(iso)) : "—");

  return (
    <PosLayout outletId={outlet.id}>
      <Head title="Sesi kas" />
      <main className="mx-auto flex w-full max-w-4xl flex-col gap-4 p-4">
        <div className="flex flex-wrap items-center justify-between gap-3">
          <div>
            <h1 className="flex items-center gap-2 text-xl font-semibold">
              Sesi kas
              <Badge variant={current ? "secondary" : "outline"} className={cn(current && "bg-success/15 text-success-strong")}>
                {current ? "Terbuka" : "Belum dibuka"}
              </Badge>
            </h1>
            <p className="text-sm text-muted-foreground">{outlet.name}</p>
          </div>
          {outlets.length > 1 ? (
            <NativeSelect aria-label="Outlet" value={outlet.id} onChange={(e) => router.visit(`/pos/cash?outlet=${e.target.value}`)}>
              {outlets.map((o) => (
                <NativeSelectOption key={o.id} value={o.id}>
                  {o.name}
                </NativeSelectOption>
              ))}
            </NativeSelect>
          ) : null}
        </div>

        {current ? (
          <OpenSession outletId={outlet.id} canClose={canClose} session={current} movements={movements} format={format} />
        ) : (
          <OpenForm outletId={outlet.id} canOpen={canOpen} />
        )}

        {history.length ? (
          <Card>
            <CardHeader>
              <CardTitle>Riwayat sesi</CardTitle>
              <CardDescription>Selisih = uang fisik − saldo sistem saat sesi ditutup.</CardDescription>
            </CardHeader>
            <CardContent>
              <ul className="flex flex-col divide-y text-sm">
                {history.map((s) => {
                  const variance = s.actual - s.expected;
                  return (
                    <li key={s.id} className="flex flex-col gap-1.5 py-3">
                      <div className="flex flex-wrap items-center justify-between gap-x-3 gap-y-1">
                        <span className="font-medium">
                          {format(s.openedAt)} – {format(s.closedAt)}
                        </span>
                        <Badge
                          variant="secondary"
                          className={cn("tabular-nums", variance === 0 ? "bg-success/15 text-success-strong" : "bg-destructive/10 text-destructive-strong")}
                        >
                          {varianceLabel(variance)}
                        </Badge>
                      </div>
                      <p className="text-muted-foreground tabular-nums">
                        Saldo sistem {formatRupiah(s.expected)} · Uang fisik {formatRupiah(s.actual)} · dibuka {s.openedByName}
                      </p>
                      {s.reason ? <p className="text-muted-foreground">Alasan selisih: {s.reason}</p> : null}
                    </li>
                  );
                })}
              </ul>
            </CardContent>
          </Card>
        ) : null}
      </main>
    </PosLayout>
  );
}

function OpenForm({ outletId, canOpen }: { outletId: string; canOpen: boolean }) {
  const [opening, setOpening] = useState("");
  const { pending, run } = useAction();
  const amount = amountFromDigits(opening);

  return (
    <Card>
      <CardHeader>
        <CardTitle>Kas belum dibuka</CardTitle>
        <CardDescription>Hitung uang modal di laci, lalu buka sesi. Pembayaran tunai baru bisa diterima setelah sesi kas dibuka.</CardDescription>
      </CardHeader>
      {canOpen ? (
        <CardContent>
          <form
            className="flex max-w-sm flex-col gap-3"
            onSubmit={(e) => {
              e.preventDefault();
              run("/pos/cash/open", { outletId, openingAmount: amount }, () => setOpening(""));
            }}
          >
            <div className="flex flex-col gap-1.5">
              <Label htmlFor="opening-amount">Modal awal (uang di laci)</Label>
              <MoneyInput id="opening-amount" value={opening} onValueChange={setOpening} className="h-12 text-lg font-semibold" placeholder="0" />
              <p className="text-xs text-muted-foreground">Isi 0 bila laci kosong.</p>
            </div>
            <Button type="submit" size="xl" disabled={amount === null || pending}>
              {pending ? <Loader2Icon className="animate-spin" /> : <WalletIcon />}
              Buka sesi kas
            </Button>
          </form>
        </CardContent>
      ) : (
        <CardContent>
          <p className="text-sm text-muted-foreground">Hanya kasir atau manager yang dapat membuka sesi kas.</p>
        </CardContent>
      )}
    </Card>
  );
}

function OpenSession({
  outletId,
  canClose,
  session,
  movements,
  format,
}: {
  outletId: string;
  canClose: boolean;
  session: Current;
  movements: Movement[];
  format: (iso: string | null) => string;
}) {
  const { totals } = session;
  const rows = [
    ["Modal awal", totals.openingAmount],
    ["Penjualan tunai", totals.cashSales],
    ["Kas masuk", totals.cashIn],
    ["Kas keluar", -totals.cashOut],
    ["Refund", -totals.refunds],
  ] as const;

  return (
    <>
      {session.fromEarlierDay ? (
        <p role="alert" className="flex items-start gap-2 rounded-lg border border-destructive/40 bg-destructive/10 p-3 text-sm text-destructive-strong">
          <TriangleAlertIcon className="mt-0.5 size-4 shrink-0" aria-hidden />
          Sesi ini dibuka di hari sebelumnya dan belum ditutup. Hitung laci lalu tutup sesi ini.
        </p>
      ) : null}
      <Card>
        <CardHeader>
          <CardTitle>Sesi terbuka</CardTitle>
          <CardDescription>
            Dibuka {format(session.openedAt)} oleh {session.openedByName}
          </CardDescription>
        </CardHeader>
        <CardContent className="flex flex-col gap-5">
          <div className="grid gap-4 sm:grid-cols-[1fr_auto] sm:items-end">
            <dl className="grid grid-cols-[1fr_auto] gap-x-4 gap-y-2 text-sm">
              {rows.map(([label, value]) => (
                <div key={label} className="contents">
                  <dt className="text-muted-foreground">{label}</dt>
                  <dd className="text-right tabular-nums">{formatRupiah(value)}</dd>
                </div>
              ))}
            </dl>
            <div className="rounded-xl bg-espresso px-5 py-4 text-espresso-foreground sm:min-w-64">
              <p className="text-sm text-espresso-foreground/80">Saldo sistem</p>
              <p className="text-3xl font-bold tabular-nums" data-testid="expected">
                {formatRupiah(totals.expected)}
              </p>
              <p className="text-xs text-espresso-foreground/70">Uang yang seharusnya ada di laci</p>
            </div>
          </div>
          {canClose ? (
            <div className="flex flex-wrap items-center gap-2">
              <MovementDialog outletId={outletId} type="cash_in" />
              <MovementDialog outletId={outletId} type="cash_out" maxAmount={totals.expected} />
              {/* Tutup sesi dipisah dari aksi rutin agar tidak tertekan tak sengaja. */}
              <div className="ml-auto">
                <CloseDialog sessionId={session.id} expected={totals.expected} />
              </div>
            </div>
          ) : null}
        </CardContent>
      </Card>

      {movements.length ? (
        <Card>
          <CardHeader>
            <CardTitle>Pergerakan kas</CardTitle>
            <CardDescription>Semua tercatat di log aktivitas dan tidak bisa diubah.</CardDescription>
          </CardHeader>
          <CardContent>
            <ul className="flex flex-col divide-y text-sm">
              {movements.map((m) => {
                const incoming = m.type === "cash_in";
                const Icon = m.type === "refund" ? Undo2Icon : incoming ? ArrowDownLeftIcon : ArrowUpRightIcon;
                return (
                  <li key={m.id} className="flex items-start justify-between gap-3 py-3">
                    <div className="flex min-w-0 gap-3">
                      <span
                        className={cn(
                          "flex size-9 shrink-0 items-center justify-center rounded-full",
                          incoming ? "bg-success/10 text-success-strong" : "bg-destructive/10 text-destructive-strong",
                        )}
                      >
                        <Icon className="size-4" aria-hidden />
                      </span>
                      <div className="min-w-0">
                        <p className="font-medium">{MOVEMENT_LABELS[m.type]}</p>
                        <p className="break-words text-muted-foreground">{m.reason}</p>
                        <p className="text-xs text-muted-foreground">
                          {format(m.createdAt)} · {m.createdByName ?? "—"}
                        </p>
                      </div>
                    </div>
                    <span className={cn("shrink-0 font-semibold tabular-nums", incoming ? "text-success-strong" : "text-destructive-strong")}>
                      {incoming ? "+" : "−"}
                      {formatRupiah(m.amount)}
                    </span>
                  </li>
                );
              })}
            </ul>
          </CardContent>
        </Card>
      ) : null}
    </>
  );
}

function MovementDialog({ outletId, type, maxAmount }: { outletId: string; type: "cash_in" | "cash_out"; maxAmount?: number }) {
  const [open, setOpen] = useState(false);
  const [value, setValue] = useState("");
  const [reason, setReason] = useState("");
  const { pending, run } = useAction();
  const amount = amountFromDigits(value);
  const label = MOVEMENT_LABELS[type];
  const inputId = `movement-${type}`;
  const tooMuch = maxAmount !== undefined && amount !== null && amount > maxAmount;

  return (
    <>
      <Button variant="outline" size="lg" onClick={() => setOpen(true)}>
        {type === "cash_in" ? <ArrowDownLeftIcon /> : <ArrowUpRightIcon />}
        {label}
      </Button>
      <Dialog open={open} onOpenChange={setOpen}>
        <DialogContent>
          <DialogHeader>
            <DialogTitle>{label}</DialogTitle>
            <DialogDescription>Dicatat atas nama Anda dan masuk log aktivitas. Catatan kas tidak bisa diubah setelah disimpan.</DialogDescription>
          </DialogHeader>
          <div className="flex flex-col gap-3">
            <div className="flex flex-col gap-1.5">
              <Label htmlFor={`${inputId}-amount`}>Jumlah</Label>
              <MoneyInput id={`${inputId}-amount`} value={value} onValueChange={setValue} className="h-12 text-lg font-semibold" placeholder="0" />
              {maxAmount !== undefined ? (
                <p className={cn("text-xs", tooMuch ? "text-destructive-strong" : "text-muted-foreground")}>Maksimal saldo sistem {formatRupiah(maxAmount)}.</p>
              ) : null}
            </div>
            <div className="flex flex-col gap-1.5">
              <Label htmlFor={`${inputId}-reason`}>Alasan</Label>
              <Textarea
                id={`${inputId}-reason`}
                value={reason}
                maxLength={200}
                onChange={(e) => setReason(e.target.value)}
                placeholder={type === "cash_in" ? "mis. tambah uang kecil" : "mis. beli es batu"}
              />
              <ReasonHint value={reason} />
            </div>
          </div>
          <DialogFooter>
            <Button variant="outline" onClick={() => setOpen(false)}>
              Batal
            </Button>
            <Button
              disabled={pending || !amount || tooMuch || reason.trim().length < MIN_REASON}
              onClick={() =>
                run("/pos/cash/movements", { outletId, type, amount, reason }, () => {
                  setOpen(false);
                  setValue("");
                  setReason("");
                })
              }
            >
              {pending ? <Loader2Icon className="animate-spin" /> : null}
              Simpan {label.toLowerCase()}
            </Button>
          </DialogFooter>
        </DialogContent>
      </Dialog>
    </>
  );
}

function CloseDialog({ sessionId, expected }: { sessionId: string; expected: number }) {
  const [open, setOpen] = useState(false);
  const [actual, setActual] = useState("");
  const [reason, setReason] = useState("");
  const { pending, run } = useAction();
  const amount = amountFromDigits(actual);
  const variance = amount === null ? null : amount - expected;
  const needsReason = variance !== null && variance !== 0;

  return (
    <>
      <Button variant="espresso" size="lg" onClick={() => setOpen(true)}>
        <LockIcon /> Tutup sesi
      </Button>
      <Dialog open={open} onOpenChange={setOpen}>
        <DialogContent>
          <DialogHeader>
            <DialogTitle>Tutup sesi kas</DialogTitle>
            <DialogDescription>Hitung semua uang di laci, lalu isi jumlahnya. Sesi yang sudah ditutup tidak bisa dibuka lagi.</DialogDescription>
          </DialogHeader>
          <dl className="grid gap-3">
            <div className="flex items-baseline justify-between rounded-lg bg-muted px-4 py-3">
              <dt className="text-sm">
                <span className="font-semibold">Saldo sistem</span>
                <span className="block text-xs text-muted-foreground">Dari transaksi tercatat</span>
              </dt>
              <dd className="text-xl font-bold tabular-nums">{formatRupiah(expected)}</dd>
            </div>
            <div className="flex flex-col gap-1.5">
              <dt>
                <Label htmlFor="actual-amount">Uang fisik (hasil hitung)</Label>
              </dt>
              <dd>
                <MoneyInput id="actual-amount" value={actual} onValueChange={setActual} className="h-12 text-lg font-semibold" placeholder="0" />
              </dd>
            </div>
            <div
              className={cn(
                "flex items-baseline justify-between rounded-lg px-4 py-3",
                variance === null ? "bg-muted text-muted-foreground" : variance === 0 ? "bg-success/10 text-success-strong" : "bg-destructive/10 text-destructive-strong",
              )}
              aria-live="polite"
            >
              <dt className="text-sm font-semibold">Selisih</dt>
              <dd className="text-xl font-bold tabular-nums" data-testid="variance">
                {variance === null ? "—" : varianceLabel(variance)}
              </dd>
            </div>
          </dl>
          {needsReason ? (
            <div className="flex flex-col gap-1.5">
              <Label htmlFor="variance-reason">Alasan selisih</Label>
              <Textarea id="variance-reason" value={reason} maxLength={300} onChange={(e) => setReason(e.target.value)} placeholder="mis. kembalian salah diberikan" />
              <ReasonHint value={reason} />
            </div>
          ) : null}
          <DialogFooter>
            <Button variant="outline" onClick={() => setOpen(false)}>
              Batal
            </Button>
            <Button
              variant="espresso"
              disabled={pending || amount === null || (needsReason && reason.trim().length < MIN_REASON)}
              onClick={() => run("/pos/cash/close", { sessionId, actualAmount: amount, reason: needsReason ? reason : null }, () => setOpen(false))}
            >
              {pending ? <Loader2Icon className="animate-spin" /> : null}
              Tutup sesi
            </Button>
          </DialogFooter>
        </DialogContent>
      </Dialog>
    </>
  );
}
