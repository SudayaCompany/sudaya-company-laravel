import { router } from "@inertiajs/react";
import { useState } from "react";

import type { Flash } from "@/types";

/** Mengirim mutasi ke server. Hasilnya tampil sebagai toast lewat flash di app.tsx. */
export function usePost() {
  const [pending, setPending] = useState(false);

  function post(url: string, data: Record<string, string | boolean | null>, onFlash?: (flash: Flash) => void) {
    router.post(url, data, {
      preserveScroll: true,
      onStart: () => setPending(true),
      onFinish: () => setPending(false),
      onFlash: (flash) => onFlash?.(flash as Flash),
    });
  }

  return { pending, post };
}
