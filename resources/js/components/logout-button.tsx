import { router } from "@inertiajs/react";
import { LogOutIcon } from "lucide-react";
import { useState } from "react";

import { Button } from "@/components/ui/button";

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
      onClick={() =>
        router.post("/logout", undefined, {
          onStart: () => setPending(true),
          onFinish: () => setPending(false),
        })
      }
    >
      <LogOutIcon />
      {label}
    </Button>
  );
}
