import { createInertiaApp, router } from "@inertiajs/react";
import { toast } from "sonner";

import { Toaster } from "@/components/ui/sonner";
import { shouldToastFlashError } from "@/lib/feedback";
import { setupStaffApp } from "@/lib/install-prompt";
import type { Flash } from "@/types";

// Aplikasi staf (PWA): tombol pasang + service worker, hanya di area staf.
setupStaffApp();

router.on("flash", (event) => {
  const { success, error } = event.detail.flash as Flash;
  if (success) toast.success(success);
  if (error && shouldToastFlashError()) toast.error(error);
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
