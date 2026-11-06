import { Input } from "@/components/ui/input";
import { digitsOnly, groupThousands } from "@/lib/money";
import { cn } from "@/lib/utils";

/**
 * Input rupiah dengan pemisah ribuan ("50.000"). Nilai yang disimpan berupa string angka
 * tanpa pemisah; angka sah tetap divalidasi server.
 */
export function MoneyInput({
  value,
  onValueChange,
  className,
  ...props
}: Omit<React.ComponentProps<"input">, "value" | "onChange" | "type"> & { value: string; onValueChange: (digits: string) => void }) {
  return (
    <div className="relative">
      <span className="pointer-events-none absolute top-1/2 left-3 -translate-y-1/2 text-muted-foreground" aria-hidden>
        Rp
      </span>
      <Input
        {...props}
        type="text"
        inputMode="numeric"
        autoComplete="off"
        value={groupThousands(value)}
        onChange={(event) => onValueChange(digitsOnly(event.target.value))}
        className={cn("pl-10 tabular-nums", className)}
      />
    </div>
  );
}
