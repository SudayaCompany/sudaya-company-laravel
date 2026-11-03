import { Head, router } from "@inertiajs/react";
import { Loader2Icon, LockIcon, TriangleAlertIcon, WalletIcon } from "lucide-react";
import { useState } from "react";

import { Button } from "@/components/ui/button";
import { Card, CardContent, CardDescription, CardHeader, CardTitle } from "@/components/ui/card";
import { Dialog, DialogContent, DialogDescription, DialogFooter, DialogHeader, DialogTitle } from "@/components/ui/dialog";
import { Input } from "@/components/ui/input";
import { Label } from "@/components/ui/label";
import { NativeSelect, NativeSelectOption } from "@/components/ui/native-select";
import { Textarea } from "@/components/ui/textarea";
import { PosLayout } from "@/layouts/pos-layout";
import { formatRupiah } from "@/lib/format";
import { cn } from "@/lib/utils";

const MOVEMENT_LABELS = { cash_in: "Kas masuk", cash_out: "Kas keluar", refund: "Refund" } as const;

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

function amountOf(value: string) {
  const amount = Number(value);
  return value.trim() !== "" && Number.isInteger(amount) && amount >= 0 ? amount : null;
}

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
            <h1 className="text-xl font-semibold">Sesi kas</h1>
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
            </CardHeader>
            <CardContent>
              <ul className="flex flex-col divide-y text-sm">
                {history.map((s) => {
                  const variance = s.actual - s.expected;
                  return (
                    <li key={s.id} className="flex flex-col gap-1 py-2">
                      <div className="flex flex-wrap items-center justify-between gap-x-3">
                        <span className="font-medium">
                          {format(s.openedAt)} – {format(s.closedAt)}
                        </span>
                        <span className={cn("shrink-0 tabular-nums", variance !== 0 && "font-medium text-destructive")}>{variance === 0 ? "Cocok" : `Selisih ${formatRupiah(variance)}`}</span>
                      </div>
                      <p className="text-muted-foreground">
                        {s.openedByName} · seharusnya {formatRupiah(s.expected)} · fisik {formatRupiah(s.actual)}
                      </p>
                      {s.reason ? <p className="text-muted-foreground">Alasan: {s.reason}</p> : null}
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
  const amount = amountOf(opening);

  return (
    <Card>
      <CardHeader>
        <CardTitle>Laci belum dibuka</CardTitle>
        <CardDescription>Pembayaran tunai baru bisa diterima setelah sesi kas dibuka.</CardDescription>
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
              <Label htmlFor="opening-amount">Modal awal</Label>
              <Input id="opening-amount" type="number" inputMode="numeric" min={0} value={opening} onChange={(e) => setOpening(e.target.value)} className="h-11 tabular-nums" />
            </div>
            <Button type="submit" size="lg" className="h-12" disabled={amount === null || pending}>
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
        <p className="flex items-start gap-2 rounded-lg border border-destructive/40 bg-destructive/5 p-3 text-sm text-destructive">
          <TriangleAlertIcon className="mt-0.5 size-4 shrink-0" />
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
        <CardContent className="flex flex-col gap-4">
          <dl className="grid grid-cols-[1fr_auto] gap-x-4 gap-y-2 text-sm">
            {rows.map(([label, value]) => (
              <div key={label} className="contents">
                <dt className="text-muted-foreground">{label}</dt>
                <dd className="text-right tabular-nums">{formatRupiah(value)}</dd>
              </div>
            ))}
            <dt className="border-t pt-2 text-base font-semibold">Saldo seharusnya</dt>
            <dd className="border-t pt-2 text-right text-base font-semibold tabular-nums" data-testid="expected">
              {formatRupiah(totals.expected)}
            </dd>
          </dl>
          {canClose ? (
            <div className="flex flex-wrap gap-2">
              <MovementDialog outletId={outletId} type="cash_in" />
              <MovementDialog outletId={outletId} type="cash_out" />
              <CloseDialog sessionId={session.id} expected={totals.expected} />
            </div>
          ) : null}
        </CardContent>
      </Card>

      {movements.length ? (
        <Card>
          <CardHeader>
            <CardTitle>Pergerakan kas</CardTitle>
          </CardHeader>
          <CardContent>
            <ul className="flex flex-col divide-y text-sm">
              {movements.map((m) => (
                <li key={m.id} className="flex items-start justify-between gap-3 py-2">
                  <div className="min-w-0">
                    <p className="font-medium">{MOVEMENT_LABELS[m.type]}</p>
                    <p className="break-words text-muted-foreground">{m.reason}</p>
                    <p className="text-xs text-muted-foreground">
                      {format(m.createdAt)} · {m.createdByName ?? "—"}
                    </p>
                  </div>
                  <span className="shrink-0 tabular-nums">{formatRupiah(m.type === "cash_in" ? m.amount : -m.amount)}</span>
                </li>
              ))}
            </ul>
          </CardContent>
        </Card>
      ) : null}
    </>
  );
}

function MovementDialog({ outletId, type }: { outletId: string; type: "cash_in" | "cash_out" }) {
  const [open, setOpen] = useState(false);
  const [value, setValue] = useState("");
  const [reason, setReason] = useState("");
  const { pending, run } = useAction();
  const amount = amountOf(value);
  const label = MOVEMENT_LABELS[type];
  const inputId = `movement-${type}`;

  return (
    <>
      <Button variant="outline" onClick={() => setOpen(true)}>
        {label}
      </Button>
      <Dialog open={open} onOpenChange={setOpen}>
        <DialogContent>
          <DialogHeader>
            <DialogTitle>{label}</DialogTitle>
            <DialogDescription>Dicatat atas nama Anda dan masuk audit log.</DialogDescription>
          </DialogHeader>
          <div className="flex flex-col gap-3">
            <div className="flex flex-col gap-1.5">
              <Label htmlFor={`${inputId}-amount`}>Jumlah</Label>
              <Input id={`${inputId}-amount`} type="number" inputMode="numeric" min={1} value={value} onChange={(e) => setValue(e.target.value)} className="h-11 tabular-nums" />
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
            </div>
          </div>
          <DialogFooter>
            <Button variant="outline" onClick={() => setOpen(false)}>
              Batal
            </Button>
            <Button
              disabled={pending || !amount || reason.trim().length < 3}
              onClick={() =>
                run("/pos/cash/movements", { outletId, type, amount, reason }, () => {
                  setOpen(false);
                  setValue("");
                  setReason("");
                })
              }
            >
              {pending ? <Loader2Icon className="animate-spin" /> : null}
              Simpan
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
  const amount = amountOf(actual);
  const variance = amount === null ? null : amount - expected;
  const needsReason = variance !== null && variance !== 0;

  return (
    <>
      <Button onClick={() => setOpen(true)}>
        <LockIcon /> Tutup sesi
      </Button>
      <Dialog open={open} onOpenChange={setOpen}>
        <DialogContent>
          <DialogHeader>
            <DialogTitle>Tutup sesi kas</DialogTitle>
            <DialogDescription>Hitung uang di laci. Saldo seharusnya {formatRupiah(expected)}.</DialogDescription>
          </DialogHeader>
          <div className="flex flex-col gap-3">
            <div className="flex flex-col gap-1.5">
              <Label htmlFor="actual-amount">Uang fisik</Label>
              <Input id="actual-amount" type="number" inputMode="numeric" min={0} value={actual} onChange={(e) => setActual(e.target.value)} className="h-11 tabular-nums" />
            </div>
            <p className="text-sm text-muted-foreground">
              Selisih: <span className={cn("font-medium tabular-nums", needsReason ? "text-destructive" : "text-foreground")}>{variance === null ? "—" : formatRupiah(variance)}</span>
            </p>
            {needsReason ? (
              <div className="flex flex-col gap-1.5">
                <Label htmlFor="variance-reason">Alasan selisih</Label>
                <Textarea id="variance-reason" value={reason} maxLength={300} onChange={(e) => setReason(e.target.value)} />
              </div>
            ) : null}
          </div>
          <DialogFooter>
            <Button variant="outline" onClick={() => setOpen(false)}>
              Batal
            </Button>
            <Button
              disabled={pending || amount === null || (needsReason && reason.trim().length < 3)}
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
