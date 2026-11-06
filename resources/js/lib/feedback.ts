import { useEffect } from "react";

/*
 * Pola umpan balik (docs/UI_UX_DESIGN_SYSTEM.md §4):
 * - Sukses dan error umum: toast global dari flash server (app.tsx).
 * - Error form: tampil sekali, inline di dekat form. Halaman yang menampilkan error flash
 *   secara inline mendaftar lewat useInlineFlashError agar toast tidak muncul dobel.
 */
let inlineOwners = 0;

/** Selama komponen ini terpasang, error flash ditampilkan inline oleh halaman, bukan sebagai toast. */
export function useInlineFlashError() {
  useEffect(() => {
    inlineOwners += 1;
    return () => {
      inlineOwners -= 1;
    };
  }, []);
}

export function shouldToastFlashError() {
  return inlineOwners === 0;
}
