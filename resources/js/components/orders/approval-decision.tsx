import { router } from "@inertiajs/react";
import { CheckIcon, Loader2Icon, XIcon } from "lucide-react";
import { useState } from "react";

import { ConfirmAction } from "@/components/confirm-action";
import { Button } from "@/components/ui/button";
import { formatRupiah } from "@/lib/format";

/**
 * Setujui/tolak pembatalan dari area admin (Owner/Manager). Keduanya dikonfirmasi:
 * menyetujui tidak bisa diulang, menolak membuat pesanan tetap berjalan.
 * Server tetap menolak pemohon yang memutuskan pengajuannya sendiri.
 */
export function ApprovalDecision({ approvalId, total, size = "lg" }: { approvalId: string; total: number; size?: "default" | "lg" }) {
  const [pending, setPending] = useState(false);
  const decide = (approve: boolean) =>
    router.post(`/admin/orders/approvals/${approvalId}/decide`, { approve }, { preserveScroll: true, onStart: () => setPending(true), onFinish: () => setPending(false) });

  return (
    <div className="flex flex-wrap gap-2">
      <ConfirmAction
        trigger={
          <Button variant="success" size={size} disabled={pending}>
            {pending ? <Loader2Icon className="animate-spin" /> : <CheckIcon />} Setujui
          </Button>
        }
        title="Setujui pembatalan?"
        description={`Pesanan dibatalkan dan keluar dari antrean. Uang ${formatRupiah(total)} dikembalikan kasir lewat Catat refund tunai saat diserahkan.`}
        confirmLabel="Setujui pembatalan"
        onConfirm={() => decide(true)}
      />
      <ConfirmAction
        trigger={
          <Button variant="outline" size={size} disabled={pending}>
            <XIcon /> Tolak
          </Button>
        }
        title="Tolak pembatalan?"
        description="Pesanan tetap berjalan seperti biasa dan pengajuan ditutup."
        confirmLabel="Tolak pengajuan"
        destructive={false}
        onConfirm={() => decide(false)}
      />
    </div>
  );
}
