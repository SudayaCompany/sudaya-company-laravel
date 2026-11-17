import { router } from "@inertiajs/react";
import { LogOutIcon } from "lucide-react";
import { useState } from "react";

import { Button } from "@/components/ui/button";
import { disablePush } from "@/lib/push";

export function LogoutButton({
  variant = "outline",
  label = "Keluar",
}: {
  variant?: "outline" | "ghost" | "default";
  label?: string;
}) {
  const [pending, setPending] = useState(false);

  return (
    <Button
      type="button"
      variant={variant}
      size="sm"
      disabled={pending}
      onClick={() => {
        setPending(true);
        // Perangkat ini berhenti menerima notifikasi pesanan akun ini sebelum keluar.
        void disablePush().finally(() => router.post("/logout", undefined, { onFinish: () => setPending(false) }));
      }}
    >
      <LogOutIcon />
      {label}
    </Button>
  );
}
