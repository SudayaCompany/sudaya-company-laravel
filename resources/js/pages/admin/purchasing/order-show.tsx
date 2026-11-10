import { Head, router } from "@inertiajs/react";
import { PackageCheckIcon } from "lucide-react";
import { useState } from "react";

import { ConfirmAction } from "@/components/confirm-action";
import { PageHeader } from "@/components/page-header";
import { Badge } from "@/components/ui/badge";
import { Button } from "@/components/ui/button";
import { Card, CardContent } from "@/components/ui/card";
import { Input } from "@/components/ui/input";
import { Table, TableBody, TableCell, TableHead, TableHeader, TableRow } from "@/components/ui/table";
import { AdminLayout } from "@/layouts/admin-layout";
import { formatRupiah } from "@/lib/format";
import { PO_BADGE, type PoStatus } from "@/pages/admin/purchasing/orders";

type Line = { itemId: string; name: string; sku: string; unit: string; quantityText: string; unitCost: number; lineTotal: number; received: string | null; receiveDefault: string };
type Props = {
  outlet: { id: string; name: string; timezone: string };
  order: {
    id: string;
    number: string;
    status: PoStatus;
    statusLabel: string;
    supplier: string;
    expectedDate: string | null;
    notes: string | null;
    total: number;
    createdAt: string | null;
    receivedAt: string | null;
    createdBy: string | null;
    decidedBy: string | null;
    decisionNote: string | null;
    canReceive: boolean;
    canCancel: boolean;
    lines: Line[];
  };
};

export default function PurchaseOrderShow({ outlet, order }: Props) {
  const stamp = new Intl.DateTimeFormat("id-ID", { dateStyle: "medium", timeStyle: "short", timeZone: outlet.timezone });
  const [received, setReceived] = useState<Record<string, string>>(Object.fromEntries(order.lines.map((l) => [l.itemId, l.receiveDefault])));
  const [pending, setPending] = useState(false);

  return (
    <AdminLayout breadcrumbs={[{ label: "Purchase order", href: `/admin/purchase-orders?outlet=${outlet.id}` }, { label: order.number }]}>
      <Head title={order.number} />
      <PageHeader title={order.number} description={`${order.supplier} · ${outlet.name}`}>
        <Badge variant="outline" className={`text-sm ${PO_BADGE[order.status]}`}>
          {order.statusLabel}
        </Badge>
      </PageHeader>

      <Card className="max-w-4xl">
        <CardContent className="grid gap-3 text-sm sm:grid-cols-2">
          <p>
            <span className="text-muted-foreground">Diajukan</span> {order.createdAt ? stamp.format(new Date(order.createdAt)) : "—"}
            {order.createdBy ? ` oleh ${order.createdBy}` : ""}
          </p>
          <p>
            <span className="text-muted-foreground">Total</span> <span className="font-semibold tabular-nums">{formatRupiah(order.total)}</span>
          </p>
          {order.expectedDate ? (
            <p>
              <span className="text-muted-foreground">Perkiraan datang</span> {order.expectedDate}
            </p>
          ) : null}
          {order.decidedBy ? (
            <p>
              <span className="text-muted-foreground">{order.status === "rejected" ? "Ditolak" : "Disetujui"} oleh</span> {order.decidedBy}
              {order.decisionNote ? ` — ${order.decisionNote}` : ""}
            </p>
          ) : null}
          {order.receivedAt ? (
            <p>
              <span className="text-muted-foreground">Diterima</span> {stamp.format(new Date(order.receivedAt))}
            </p>
          ) : null}
          {order.notes ? <p className="sm:col-span-2">Catatan: {order.notes}</p> : null}
        </CardContent>
      </Card>

      <Card className="max-w-4xl">
        <CardContent className="overflow-x-auto">
          <Table>
            <TableHeader>
              <TableRow>
                <TableHead>Item</TableHead>
                <TableHead className="text-right">Dipesan</TableHead>
                <TableHead className="text-right">Subtotal</TableHead>
                <TableHead className="text-right">{order.canReceive ? "Diterima (satuan item)" : "Diterima"}</TableHead>
              </TableRow>
            </TableHeader>
            <TableBody>
              {order.lines.map((l) => (
                <TableRow key={l.itemId}>
                  <TableCell>
                    <span className="block font-medium">{l.name}</span>
                    <span className="block text-xs text-muted-foreground">{l.sku}</span>
                  </TableCell>
                  <TableCell className="text-right tabular-nums">{l.quantityText}</TableCell>
                  <TableCell className="text-right tabular-nums">{formatRupiah(l.lineTotal)}</TableCell>
                  <TableCell className="text-right">
                    {order.canReceive ? (
                      <div className="flex items-center justify-end gap-2">
                        <Input
                          aria-label={`Diterima ${l.name}`}
                          inputMode="decimal"
                          className="w-28 text-right"
                          value={received[l.itemId] ?? ""}
                          onChange={(e) => setReceived((s) => ({ ...s, [l.itemId]: e.target.value }))}
                        />
                        <span className="text-sm text-muted-foreground">{l.unit}</span>
                      </div>
                    ) : (
                      <span className="tabular-nums">{l.received ?? "—"}</span>
                    )}
                  </TableCell>
                </TableRow>
              ))}
            </TableBody>
          </Table>
        </CardContent>
      </Card>

      <div className="flex flex-wrap gap-2">
        {order.canReceive ? (
          <ConfirmAction
            trigger={
              <Button size="lg" disabled={pending}>
                <PackageCheckIcon aria-hidden /> Terima barang
              </Button>
            }
            title="Terima barang PO ini?"
            description="Jumlah yang diterima langsung masuk ke stok sebagai Pembelian dan harga beli item diperbarui. Isi 0 untuk barang yang tidak datang."
            confirmLabel="Terima & masukkan stok"
            destructive={false}
            onConfirm={() =>
              router.post(`/admin/purchase-orders/${order.id}/receive`, { lines: received }, { preserveScroll: true, onStart: () => setPending(true), onFinish: () => setPending(false) })
            }
          />
        ) : null}
        {order.canCancel ? (
          <ConfirmAction
            trigger={
              <Button variant="outline" size="lg">
                Batalkan PO
              </Button>
            }
            title="Batalkan purchase order?"
            description="PO yang belum diputuskan dibatalkan dan hilang dari kotak Persetujuan."
            confirmLabel="Batalkan PO"
            onConfirm={() => router.post(`/admin/purchase-orders/${order.id}/cancel`, {}, { preserveScroll: true })}
          />
        ) : null}
      </div>
    </AdminLayout>
  );
}
