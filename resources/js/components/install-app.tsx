import { DownloadIcon, ShareIcon, SmartphoneIcon, XIcon } from "lucide-react";
import { useEffect, useState } from "react";

import { Button } from "@/components/ui/button";
import { isIos, isStandalone, useInstallPrompt } from "@/lib/install-prompt";
import { cn } from "@/lib/utils";

const DISMISS_KEY = "sudaya.installApp.dismissedAt";
const DISMISS_DAYS = 14;

function dismissedRecently(): boolean {
  try {
    const at = Number(window.localStorage.getItem(DISMISS_KEY));
    return Number.isFinite(at) && at > 0 && Date.now() - at < DISMISS_DAYS * 86_400_000;
  } catch {
    return false;
  }
}

/**
 * Ajakan memasang aplikasi staf Sudaya di HP (absen, kasir, barista). Tidak tampil bila sudah
 * dibuka sebagai aplikasi, browser tidak mendukung, atau baru saja ditutup pengguna.
 */
export function InstallApp({ className }: { className?: string }) {
  const { canPrompt, installed, prompt } = useInstallPrompt();
  const [env, setEnv] = useState<{ standalone: boolean; ios: boolean; dismissed: boolean } | null>(null);

  useEffect(() => {
    setEnv({ standalone: isStandalone(), ios: isIos(), dismissed: dismissedRecently() });
  }, []);

  if (env === null || env.standalone || env.dismissed || installed) return null;

  const dismiss = () => {
    try {
      window.localStorage.setItem(DISMISS_KEY, String(Date.now()));
    } catch {
      // Penyimpanan diblokir (mode privat): cukup sembunyikan untuk sesi ini.
    }
    setEnv({ ...env, dismissed: true });
  };

  let icon: React.ReactNode;
  let message: React.ReactNode;
  if (canPrompt) {
    icon = <SmartphoneIcon className="size-5 shrink-0 text-brand-strong" aria-hidden />;
    message = "Pasang aplikasi Sudaya di HP agar absen & kerja lebih cepat.";
  } else if (env.ios) {
    icon = <ShareIcon className="size-5 shrink-0 text-brand-strong" aria-hidden />;
    message = (
      <>
        Pasang di iPhone: ketuk <strong>Bagikan</strong>, lalu pilih <strong>Tambahkan ke Layar Utama</strong>.
      </>
    );
  } else {
    return null;
  }

  return (
    <div
      className={cn("flex flex-col gap-2 rounded-xl border bg-card p-3 text-card-foreground", className)}
      data-testid="install-app"
    >
      <div className="flex items-center gap-3">
        {icon}
        <p className="flex-1 text-sm leading-snug">{message}</p>
        <button
          type="button"
          onClick={dismiss}
          aria-label="Tutup ajakan pasang aplikasi"
          className="-mr-1 inline-flex size-11 shrink-0 items-center justify-center rounded-lg text-muted-foreground hover:bg-muted hover:text-foreground focus-visible:ring-3 focus-visible:ring-ring/50 focus-visible:outline-none"
        >
          <XIcon className="size-4" aria-hidden />
        </button>
      </div>
      {canPrompt ? (
        <Button type="button" className="min-h-11 w-full" onClick={() => void prompt()}>
          <DownloadIcon aria-hidden /> Pasang aplikasi
        </Button>
      ) : null}
    </div>
  );
}
