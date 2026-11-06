import { Head, router } from "@inertiajs/react";
import { Loader2Icon, PencilIcon, PlusIcon, SlidersHorizontalIcon, Trash2Icon, XIcon } from "lucide-react";
import { useState } from "react";

import { ConfirmAction } from "@/components/confirm-action";
import { EmptyState } from "@/components/empty-state";
import { PageHeader } from "@/components/page-header";
import { Badge } from "@/components/ui/badge";
import { Button } from "@/components/ui/button";
import { Card, CardAction, CardContent, CardDescription, CardHeader, CardTitle } from "@/components/ui/card";
import { Input } from "@/components/ui/input";
import { Label } from "@/components/ui/label";
import { NativeSelect, NativeSelectOption } from "@/components/ui/native-select";
import { Switch } from "@/components/ui/switch";
import { AdminLayout } from "@/layouts/admin-layout";
import { formatPriceDelta } from "@/lib/format";

type OptionDraft = { id?: string; name: string; priceDelta: number; isDefault: boolean; isActive: boolean };
type GroupDraft = {
  name: string;
  selection: "single" | "multiple";
  isRequired: boolean;
  minSelect: number;
  maxSelect: number | null;
  sortOrder: number;
  options: OptionDraft[];
};
type Group = GroupDraft & { id: string; productCount: number };

type Props = {
  brand: { id: string; name: string };
  groups: Group[];
  can: { create: boolean; edit: boolean; delete: boolean };
};

const EMPTY: GroupDraft = {
  name: "",
  selection: "single",
  isRequired: false,
  minSelect: 0,
  maxSelect: 1,
  sortOrder: 0,
  options: [{ name: "", priceDelta: 0, isDefault: false, isActive: true }],
};

/**
 * Template grup sesuai PRD §29–30. Harga hanya diisi bila PRD menyebutnya
 * (Extra Shot +5.000, Hazelnut Syrup +3.000); sisanya Rp 0 untuk diatur admin.
 * Template hanya mengisi form, tidak tersimpan sampai admin menekan Simpan.
 */
const PRESETS: Record<string, GroupDraft> = {
  size: {
    name: "Ukuran", sortOrder: 0, selection: "single", isRequired: true, minSelect: 1, maxSelect: 1,
    options: [
      { name: "Regular", priceDelta: 0, isDefault: true, isActive: true },
      { name: "Large", priceDelta: 0, isDefault: false, isActive: true },
    ],
  },
  temperature: {
    name: "Panas/Dingin", sortOrder: 1, selection: "single", isRequired: true, minSelect: 1, maxSelect: 1,
    options: [
      { name: "Dingin", priceDelta: 0, isDefault: true, isActive: true },
      { name: "Panas", priceDelta: 0, isDefault: false, isActive: true },
    ],
  },
  sugar: {
    name: "Tingkat gula", sortOrder: 2, selection: "single", isRequired: true, minSelect: 1, maxSelect: 1,
    options: [
      { name: "Normal", priceDelta: 0, isDefault: true, isActive: true },
      { name: "Kurang manis", priceDelta: 0, isDefault: false, isActive: true },
      { name: "Tanpa gula", priceDelta: 0, isDefault: false, isActive: true },
    ],
  },
  shot: {
    name: "Tambahan shot", sortOrder: 3, selection: "multiple", isRequired: false, minSelect: 0, maxSelect: 2,
    options: [
      { name: "Extra Shot", priceDelta: 5000, isDefault: false, isActive: true },
      { name: "Decaf", priceDelta: 0, isDefault: false, isActive: true },
    ],
  },
  milk: {
    name: "Pilihan susu", sortOrder: 4, selection: "single", isRequired: false, minSelect: 0, maxSelect: 1,
    options: [
      { name: "Susu segar", priceDelta: 0, isDefault: true, isActive: true },
      { name: "Susu oat", priceDelta: 0, isDefault: false, isActive: true },
    ],
  },
  topping: {
    name: "Topping", sortOrder: 5, selection: "multiple", isRequired: false, minSelect: 0, maxSelect: 3,
    options: [
      { name: "Cream", priceDelta: 0, isDefault: false, isActive: true },
      { name: "Sirup hazelnut", priceDelta: 3000, isDefault: false, isActive: true },
    ],
  },
};

function rule(group: GroupDraft) {
  const required = group.isRequired || group.minSelect > 0;
  if (group.selection === "single") return required ? "Wajib pilih 1" : "Opsional, pilih 1";
  const max = group.maxSelect ? `maks ${group.maxSelect}` : "tanpa batas";
  return required ? `Wajib, min ${group.minSelect}, ${max}` : `Opsional, ${max}`;
}

export default function Modifiers({ brand, groups, can }: Props) {
  const [creating, setCreating] = useState<{ key: number; draft: GroupDraft } | null>(null);
  const [editing, setEditing] = useState<string | null>(null);

  return (
    <AdminLayout>
      <Head title="Varian & tambahan" />
      <PageHeader
        title="Varian & tambahan"
        description={`${brand.name} · Grup pilihan untuk menu: ukuran, suhu, tingkat gula, shot, susu, topping.`}
      />
      {groups.length === 0 && !can.create ? (
        <EmptyState icon={SlidersHorizontalIcon} title="Belum ada grup pilihan" description="Grup pilihan dibuat oleh admin menu." />
      ) : (
        <div className="flex flex-col gap-4">
          {can.create ? (
            creating ? (
              <Card>
                <CardHeader>
                  <CardTitle>Grup pilihan baru</CardTitle>
                  <CardDescription>Selisih harga ditambahkan ke harga produk saat dipilih.</CardDescription>
                </CardHeader>
                <CardContent>
                  <GroupEditor key={creating.key} brandId={brand.id} initial={creating.draft} onDone={() => setCreating(null)} />
                </CardContent>
              </Card>
            ) : (
              <div className="flex flex-wrap items-center gap-2">
                <Button onClick={() => setCreating({ key: Date.now(), draft: { ...EMPTY, sortOrder: groups.length } })}>
                  <PlusIcon /> Grup baru
                </Button>
                <NativeSelect
                  aria-label="Mulai dari template"
                  value=""
                  onChange={(e) => {
                    const preset = PRESETS[e.target.value];
                    if (preset) setCreating({ key: Date.now(), draft: preset });
                  }}
                >
                  <NativeSelectOption value="">Mulai dari template…</NativeSelectOption>
                  <NativeSelectOption value="size">Ukuran</NativeSelectOption>
                  <NativeSelectOption value="temperature">Panas/Dingin</NativeSelectOption>
                  <NativeSelectOption value="sugar">Tingkat gula</NativeSelectOption>
                  <NativeSelectOption value="shot">Tambahan shot</NativeSelectOption>
                  <NativeSelectOption value="milk">Pilihan susu</NativeSelectOption>
                  <NativeSelectOption value="topping">Topping</NativeSelectOption>
                </NativeSelect>
              </div>
            )
          ) : null}

          {groups.map((group) => (
            <Card key={group.id}>
              <CardHeader>
                <CardTitle>{group.name}</CardTitle>
                <CardDescription>
                  {rule(group)} · dipakai {group.productCount} produk
                </CardDescription>
                {can.edit && editing !== group.id ? (
                  <CardAction>
                    <Button variant="outline" size="sm" onClick={() => setEditing(group.id)}>
                      <PencilIcon /> Edit
                    </Button>
                  </CardAction>
                ) : null}
              </CardHeader>
              <CardContent>
                {editing === group.id ? (
                  <GroupEditor
                    brandId={brand.id}
                    groupId={group.id}
                    initial={group}
                    productCount={group.productCount}
                    canDelete={can.delete}
                    onDone={() => setEditing(null)}
                  />
                ) : (
                  <div className="flex flex-wrap gap-1.5">
                    {group.options.map((option) => (
                      <Badge key={option.id} variant={option.isActive ? "secondary" : "outline"}>
                        {option.name}
                        {option.priceDelta ? ` ${formatPriceDelta(option.priceDelta)}` : ""}
                        {option.isDefault ? " · default" : ""}
                        {option.isActive ? "" : " · nonaktif"}
                      </Badge>
                    ))}
                  </div>
                )}
              </CardContent>
            </Card>
          ))}
        </div>
      )}
    </AdminLayout>
  );
}

let draftKey = 0;
const withKeys = (options: OptionDraft[]) => options.map((o) => ({ ...o, key: o.id ?? `new-${draftKey++}` }));

function GroupEditor({
  brandId,
  groupId,
  initial,
  productCount = 0,
  canDelete = false,
  onDone,
}: {
  brandId: string;
  groupId?: string;
  initial: GroupDraft;
  productCount?: number;
  canDelete?: boolean;
  onDone: () => void;
}) {
  const [draft, setDraft] = useState(() => ({ ...initial, options: withKeys(initial.options) }));
  const [pending, setPending] = useState(false);
  const set = <K extends keyof typeof draft>(key: K, value: (typeof draft)[K]) => setDraft((d) => ({ ...d, [key]: value }));
  const setOption = (key: string, patch: Partial<OptionDraft>) =>
    setDraft((d) => ({
      ...d,
      options: d.options.map((o) => {
        if (o.key === key) return { ...o, ...patch };
        // Grup pilihan tunggal: hanya satu default.
        if (patch.isDefault && d.selection === "single") return { ...o, isDefault: false };
        return o;
      }),
    }));
  const options = {
    preserveScroll: true,
    onStart: () => setPending(true),
    onFinish: () => setPending(false),
    onFlash: (flash: unknown) => (flash as { success?: string }).success && onDone(),
  };

  function save() {
    const values = {
      name: draft.name,
      selection: draft.selection,
      isRequired: draft.isRequired,
      minSelect: draft.minSelect,
      maxSelect: draft.selection === "single" ? 1 : draft.maxSelect,
      sortOrder: draft.sortOrder,
      options: draft.options.map((o) => ({
        id: o.id ?? null,
        name: o.name,
        priceDelta: o.priceDelta,
        isDefault: o.isDefault,
        isActive: o.isActive,
      })),
    };
    if (groupId) router.put(`/admin/modifiers/${groupId}`, { values }, options);
    else router.post("/admin/modifiers", { brandId, values }, options);
  }

  return (
    <div className="flex flex-col gap-4">
      <div className="grid gap-3 sm:grid-cols-2 lg:grid-cols-4">
        <div className="grid gap-1.5 sm:col-span-2">
          <Label>Nama grup</Label>
          <Input value={draft.name} onChange={(e) => set("name", e.target.value)} placeholder="mis. Size" aria-label="Nama grup" />
        </div>
        <div className="grid gap-1.5">
          <Label>Jenis pilihan</Label>
          <NativeSelect
            value={draft.selection}
            className="w-full"
            aria-label="Jenis pilihan"
            onChange={(e) => {
              const selection = e.target.value as "single" | "multiple";
              setDraft((d) => ({ ...d, selection, maxSelect: selection === "single" ? 1 : d.maxSelect }));
            }}
          >
            <NativeSelectOption value="single">Pilih satu</NativeSelectOption>
            <NativeSelectOption value="multiple">Pilih beberapa</NativeSelectOption>
          </NativeSelect>
        </div>
        <div className="grid gap-1.5">
          <Label>Urutan tampil</Label>
          <Input type="number" min={0} max={9999} value={draft.sortOrder} onChange={(e) => set("sortOrder", Number(e.target.value))} />
        </div>
        <label className="flex items-center justify-between gap-2 self-end rounded-lg border px-3 py-1.5 text-sm">
          Wajib dipilih
          <Switch
            checked={draft.isRequired}
            onCheckedChange={(isRequired) => setDraft((d) => ({ ...d, isRequired, minSelect: isRequired ? Math.max(d.minSelect, 1) : 0 }))}
          />
        </label>
        {draft.selection === "multiple" ? (
          <>
            <div className="grid gap-1.5">
              <Label>Minimal dipilih</Label>
              <Input type="number" min={0} max={20} value={draft.minSelect} onChange={(e) => set("minSelect", Number(e.target.value))} />
            </div>
            <div className="grid gap-1.5">
              <Label>Maksimal dipilih</Label>
              <Input
                type="number"
                min={1}
                max={20}
                value={draft.maxSelect ?? ""}
                placeholder="Tanpa batas"
                onChange={(e) => set("maxSelect", e.target.value === "" ? null : Number(e.target.value))}
              />
            </div>
          </>
        ) : null}
      </div>

      <div className="flex flex-col gap-2">
        <div className="hidden gap-2 text-xs text-muted-foreground sm:grid sm:grid-cols-[1fr_9rem_auto_auto_auto]">
          <span>Pilihan</span>
          <span>Selisih harga (Rp)</span>
          <span>Default</span>
          <span>Aktif</span>
          <span />
        </div>
        {draft.options.map((option) => (
          <div key={option.key} className="grid items-center gap-2 sm:grid-cols-[1fr_9rem_auto_auto_auto]">
            <Input value={option.name} onChange={(e) => setOption(option.key, { name: e.target.value })} placeholder="Nama pilihan" aria-label="Nama pilihan" />
            <Input
              type="number"
              step={500}
              value={option.priceDelta}
              onChange={(e) => setOption(option.key, { priceDelta: Number(e.target.value) })}
              aria-label="Selisih harga"
              className="tabular-nums"
            />
            <Switch checked={option.isDefault} onCheckedChange={(isDefault) => setOption(option.key, { isDefault })} aria-label={`Default ${option.name}`} />
            <Switch checked={option.isActive} onCheckedChange={(isActive) => setOption(option.key, { isActive })} aria-label={`Aktif ${option.name}`} />
            <Button
              type="button"
              size="icon-sm"
              variant="ghost"
              disabled={draft.options.length <= 1}
              onClick={() => setDraft((d) => ({ ...d, options: d.options.filter((o) => o.key !== option.key) }))}
              aria-label={`Hapus pilihan ${option.name}`}
            >
              <XIcon />
            </Button>
          </div>
        ))}
        <Button
          type="button"
          variant="outline"
          size="sm"
          className="self-start"
          onClick={() =>
            setDraft((d) => ({ ...d, options: [...d.options, ...withKeys([{ name: "", priceDelta: 0, isDefault: false, isActive: true }])] }))
          }
        >
          <PlusIcon /> Tambah pilihan
        </Button>
      </div>

      <div className="flex flex-wrap justify-end gap-2">
        {groupId && canDelete ? (
          <ConfirmAction
            trigger={
              <Button variant="ghost" disabled={pending}>
                <Trash2Icon className="text-destructive" /> Hapus grup
              </Button>
            }
            title="Hapus grup pilihan?"
            description={`${draft.name} akan dilepas dari ${productCount} produk. Histori order tetap menyimpan pilihan lama.`}
            confirmLabel="Hapus grup"
            onConfirm={() => router.delete(`/admin/modifiers/${groupId}`, { preserveScroll: true })}
          />
        ) : null}
        <Button variant="outline" onClick={onDone} disabled={pending}>
          Batal
        </Button>
        <Button onClick={save} disabled={pending}>
          {pending ? <Loader2Icon className="animate-spin" /> : null} Simpan
        </Button>
      </div>
    </div>
  );
}
