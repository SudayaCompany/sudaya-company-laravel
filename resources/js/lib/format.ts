/** Label scope grant, mis. "Semua outlet" atau nama outlet. */
export function scopeLabel(outletId: string | null, outletNames: ReadonlyMap<string, string>) {
  if (outletId === null) return "Semua outlet";
  return outletNames.get(outletId) ?? "Outlet lain";
}
