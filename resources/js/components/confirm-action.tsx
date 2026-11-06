import { useState } from "react";

import {
  AlertDialog,
  AlertDialogCancel,
  AlertDialogContent,
  AlertDialogDescription,
  AlertDialogFooter,
  AlertDialogHeader,
  AlertDialogTitle,
  AlertDialogTrigger,
} from "@/components/ui/alert-dialog";
import { Button } from "@/components/ui/button";

type ConfirmProps = {
  title: string;
  description: string;
  /** Label aksi yang jelas, mis. "Nonaktifkan akun", bukan "OK". */
  confirmLabel: string;
  destructive?: boolean;
  onConfirm: () => void;
};

/**
 * Pola konfirmasi tunggal untuk aksi berisiko (docs/UI_UX_DESIGN_SYSTEM.md §4).
 * - Dengan `trigger`: tombol yang membuka dialog.
 * - Tanpa `trigger` (terkontrol): dialog dibuka lewat `open`/`onOpenChange`, mis. dari perubahan switch.
 */
export function ConfirmAction(
  props: ConfirmProps &
    ({ trigger: React.ReactElement; disabled?: boolean } | { trigger?: undefined; open: boolean; onOpenChange: (open: boolean) => void }),
) {
  const [uncontrolledOpen, setUncontrolledOpen] = useState(false);
  const controlled = props.trigger === undefined;
  const open = controlled ? props.open : uncontrolledOpen;
  const setOpen = controlled ? props.onOpenChange : setUncontrolledOpen;
  const { title, description, confirmLabel, destructive = true, onConfirm } = props;

  return (
    <AlertDialog open={open} onOpenChange={setOpen}>
      {controlled ? null : <AlertDialogTrigger render={props.trigger} disabled={props.disabled} />}
      <AlertDialogContent>
        <AlertDialogHeader>
          <AlertDialogTitle>{title}</AlertDialogTitle>
          <AlertDialogDescription>{description}</AlertDialogDescription>
        </AlertDialogHeader>
        <AlertDialogFooter>
          <AlertDialogCancel>Batal</AlertDialogCancel>
          <Button
            variant={destructive ? "destructive" : "default"}
            onClick={() => {
              setOpen(false);
              onConfirm();
            }}
          >
            {confirmLabel}
          </Button>
        </AlertDialogFooter>
      </AlertDialogContent>
    </AlertDialog>
  );
}
