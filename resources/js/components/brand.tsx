import { cn } from "@/lib/utils";

/**
 * Logo resmi Sudaya Coffee (diberikan owner 11 November 2026). `tone="light"` untuk latar gelap
 * (tulisan putih). Tinggi mengikuti ukuran font dari className (mis. `text-lg`), lebar menyesuaikan.
 */
export function Brand({
  className,
  tone = "dark",
}: {
  className?: string;
  tone?: "dark" | "light";
}) {
  return (
    <span className={cn("inline-flex shrink-0 items-center", className)}>
      <img
        src={tone === "light" ? "/images/logo-sudaya-light.png" : "/images/logo-sudaya.png"}
        alt="Sudaya Coffee"
        width={463}
        height={160}
        className="h-[1.9em] w-auto"
      />
    </span>
  );
}
