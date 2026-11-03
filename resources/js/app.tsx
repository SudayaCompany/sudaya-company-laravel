import { createInertiaApp, router } from "@inertiajs/react";
import { toast } from "sonner";

import { Toaster } from "@/components/ui/sonner";
import type { Flash } from "@/types";

router.on("flash", (event) => {
  const { success, error } = event.detail.flash as Flash;
  if (success) toast.success(success);
  if (error) toast.error(error);
});

createInertiaApp({
  title: (title) => (title ? `${title} · Sudaya Coffee` : "Sudaya Coffee"),
  progress: { color: "oklch(0.7 0.18 50)" },
  withApp: (app) => (
    <>
      {app}
      <Toaster richColors position="top-center" />
    </>
  ),
});
