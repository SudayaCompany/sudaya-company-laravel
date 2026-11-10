import { useEffect, useReducer } from "react";

/**
 * Aplikasi staf (PWA): tangkap event "beforeinstallprompt" sedini mungkin agar tombol
 * "Pasang aplikasi" bisa dipakai, dan daftarkan service worker. Hanya aktif di halaman yang
 * memasang manifest (area staf); halaman pelanggan tidak tersentuh.
 */
type InstallPromptEvent = Event & {
  prompt: () => Promise<void>;
  userChoice: Promise<{ outcome: "accepted" | "dismissed" }>;
};

let deferred: InstallPromptEvent | null = null;
let installed = false;
const listeners = new Set<() => void>();
const notify = () => listeners.forEach((listener) => listener());

export function setupStaffApp(): void {
  if (typeof window === "undefined" || !document.querySelector('link[rel="manifest"]')) return;

  window.addEventListener("beforeinstallprompt", (event) => {
    // Tampilkan tombol kita sendiri di tempat yang tepat, bukan banner otomatis browser.
    event.preventDefault();
    deferred = event as InstallPromptEvent;
    notify();
  });
  window.addEventListener("appinstalled", () => {
    deferred = null;
    installed = true;
    notify();
  });

  if ("serviceWorker" in navigator && import.meta.env.PROD) {
    const register = () => void navigator.serviceWorker.register("/sw.js").catch(() => undefined);
    if (document.readyState === "complete") register();
    else window.addEventListener("load", register, { once: true });
  }
}

export function isStandalone(): boolean {
  return (
    window.matchMedia("(display-mode: standalone)").matches ||
    (navigator as Navigator & { standalone?: boolean }).standalone === true
  );
}

export function isIos(): boolean {
  return /iphone|ipad|ipod/i.test(navigator.userAgent) || (/macintosh/i.test(navigator.userAgent) && navigator.maxTouchPoints > 1);
}

export function useInstallPrompt() {
  const [, rerender] = useReducer((n: number) => n + 1, 0);
  useEffect(() => {
    listeners.add(rerender);
    return () => {
      listeners.delete(rerender);
    };
  }, []);

  return {
    canPrompt: deferred !== null,
    installed,
    async prompt(): Promise<boolean> {
      const event = deferred;
      if (event === null) return false;
      deferred = null;
      notify();
      await event.prompt();
      return (await event.userChoice).outcome === "accepted";
    },
  };
}
