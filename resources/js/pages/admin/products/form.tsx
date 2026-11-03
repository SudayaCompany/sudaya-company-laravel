import { Head, Link, router } from "@inertiajs/react";
import { ImagePlusIcon, Loader2Icon, TagsIcon, Trash2Icon } from "lucide-react";
import { useEffect, useRef, useState } from "react";
import { toast } from "sonner";

import { EmptyState } from "@/components/empty-state";
import { PageHeader } from "@/components/page-header";
import { Button, buttonVariants } from "@/components/ui/button";
import { Input } from "@/components/ui/input";
import { Label } from "@/components/ui/label";
import { NativeSelect, NativeSelectOption } from "@/components/ui/native-select";
import { Switch } from "@/components/ui/switch";
import { Textarea } from "@/components/ui/textarea";
import { AdminLayout } from "@/layouts/admin-layout";
import { slugify } from "@/lib/catalog/slug";
import { formatRupiah } from "@/lib/format";

// Sama dengan server (MenuImages); di sini hanya untuk umpan balik cepat.
const MAX_IMAGE_BYTES = 2 * 1024 * 1024;
const IMAGE_TYPES = ["image/jpeg", "image/png", "image/webp"];

type Product = {
  id: string;
  name: string;
  slug: string;
  sku: string | null;
  categoryId: string;
  description: string | null;
  basePrice: number;
  isActive: boolean;
  modifierGroupIds: string[];
  imageUrl: string | null;
};

type Props = {
  brand: { id: string; name?: string };
  product: Product | null;
  categories: { id: string; name: string }[];
  modifierGroups: { id: string; name: string; summary: string }[];
};

export default function ProductFormPage({ brand, product, categories, modifierGroups }: Props) {
  const title = product ? `Edit ${product.name}` : "Produk baru";
  return (
    <AdminLayout>
      <Head title={product ? "Edit produk" : "Produk baru"} />
      <PageHeader title={title} description={product ? "Perubahan harga tercatat di audit log." : brand.name} />
      {categories.length === 0 ? (
        <div className="flex flex-col items-center gap-4">
          <EmptyState icon={TagsIcon} title="Belum ada kategori" description="Setiap produk wajib masuk satu kategori." className="w-full" />
          <Link href="/admin/categories" className={buttonVariants()}>
            Buat kategori
          </Link>
        </div>
      ) : (
        <ProductForm brandId={brand.id} product={product} categories={categories} modifierGroups={modifierGroups} />
      )}
    </AdminLayout>
  );
}

function ProductForm({ brandId, product, categories, modifierGroups }: Omit<Props, "brand"> & { brandId: string }) {
  const [name, setName] = useState(product?.name ?? "");
  const [slug, setSlug] = useState(product?.slug ?? "");
  const [slugTouched, setSlugTouched] = useState(Boolean(product));
  const [price, setPrice] = useState(product ? String(product.basePrice) : "");
  const [isActive, setIsActive] = useState(product?.isActive ?? true);
  const [preview, setPreview] = useState<string | null>(product?.imageUrl ?? null);
  const [removeImage, setRemoveImage] = useState(false);
  const [error, setError] = useState<string | null>(null);
  const [pending, setPending] = useState(false);
  const fileInput = useRef<HTMLInputElement>(null);

  // Lepas object URL preview lama agar tidak bocor memori.
  useEffect(() => {
    return () => {
      if (preview?.startsWith("blob:")) URL.revokeObjectURL(preview);
    };
  }, [preview]);

  function onPickImage(event: React.ChangeEvent<HTMLInputElement>) {
    const file = event.target.files?.[0];
    if (!file) return;
    if (!IMAGE_TYPES.includes(file.type)) {
      toast.error("Format foto harus JPG, PNG, atau WebP.");
      event.target.value = "";
      return;
    }
    if (file.size > MAX_IMAGE_BYTES) {
      toast.error("Ukuran foto maksimal 2 MB.");
      event.target.value = "";
      return;
    }
    setPreview(URL.createObjectURL(file));
    setRemoveImage(false);
  }

  function onRemoveImage() {
    if (fileInput.current) fileInput.current.value = "";
    setPreview(null);
    setRemoveImage(true);
  }

  const priceNumber = Number(price);

  return (
    <form
      // Isian form tetap ada bila validasi server gagal (preserveState).
      onSubmit={(event) => {
        event.preventDefault();
        const data = new FormData(event.currentTarget);
        setError(null);
        router.post(product ? `/admin/products/${product.id}` : "/admin/products", data, {
          forceFormData: true,
          preserveState: true,
          preserveScroll: true,
          onStart: () => setPending(true),
          onFinish: () => setPending(false),
          onFlash: (flash) => setError((flash as { error?: string }).error ?? null),
        });
      }}
      className="grid gap-6 lg:grid-cols-[1fr_20rem]"
    >
      <input type="hidden" name="brandId" value={brandId} />
      <input type="hidden" name="removeImage" value={removeImage ? "1" : ""} />
      <input type="hidden" name="isActive" value={isActive ? "true" : "false"} />

      <div className="flex flex-col gap-5">
        {error ? (
          <p role="alert" className="rounded-lg border border-destructive/30 bg-destructive/10 px-3 py-2 text-sm text-destructive">
            {error}
          </p>
        ) : null}

        <div className="flex flex-col gap-2">
          <Label htmlFor="name">Nama produk</Label>
          <Input
            id="name"
            name="name"
            value={name}
            required
            minLength={2}
            maxLength={120}
            onChange={(e) => {
              setName(e.target.value);
              if (!slugTouched) setSlug(slugify(e.target.value));
            }}
          />
        </div>

        <div className="grid gap-4 sm:grid-cols-2">
          <div className="flex flex-col gap-2">
            <Label htmlFor="slug">Slug</Label>
            <Input
              id="slug"
              name="slug"
              value={slug}
              maxLength={80}
              className="font-mono text-xs"
              onChange={(e) => {
                setSlug(e.target.value.toLowerCase());
                setSlugTouched(true);
              }}
            />
            <p className="text-xs text-muted-foreground">Dibuat otomatis dari nama.</p>
          </div>
          <div className="flex flex-col gap-2">
            <Label htmlFor="sku">SKU (opsional)</Label>
            <Input id="sku" name="sku" defaultValue={product?.sku ?? ""} maxLength={32} className="font-mono text-xs uppercase" placeholder="CF-LATTE" />
          </div>
        </div>

        <div className="grid gap-4 sm:grid-cols-2">
          <div className="flex flex-col gap-2">
            <Label htmlFor="categoryId">Kategori</Label>
            <NativeSelect id="categoryId" name="categoryId" defaultValue={product?.categoryId ?? categories[0].id} required className="w-full">
              {categories.map((c) => (
                <NativeSelectOption key={c.id} value={c.id}>
                  {c.name}
                </NativeSelectOption>
              ))}
            </NativeSelect>
          </div>
          <div className="flex flex-col gap-2">
            <Label htmlFor="basePrice">Harga (Rp)</Label>
            <Input
              id="basePrice"
              name="basePrice"
              type="number"
              inputMode="numeric"
              step={1}
              required
              value={price}
              onChange={(e) => setPrice(e.target.value)}
              className="tabular-nums"
            />
            <p className="text-xs text-muted-foreground">
              {Number.isFinite(priceNumber) && price !== "" ? formatRupiah(priceNumber) : "Harga dasar di semua outlet."}
            </p>
          </div>
        </div>

        <div className="flex flex-col gap-2">
          <Label htmlFor="description">Deskripsi</Label>
          <Textarea
            id="description"
            name="description"
            defaultValue={product?.description ?? ""}
            maxLength={1000}
            rows={3}
            placeholder="Komposisi, rasa, atau catatan untuk customer."
          />
        </div>

        <fieldset className="grid gap-2">
          <legend className="mb-1 text-sm font-medium">Grup modifier</legend>
          {modifierGroups.length === 0 ? (
            <p className="text-sm text-muted-foreground">
              Belum ada grup modifier.{" "}
              <Link href="/admin/modifiers" className="underline">
                Buat di menu Modifier
              </Link>
              .
            </p>
          ) : (
            <div className="grid gap-2 sm:grid-cols-2">
              {modifierGroups.map((group) => (
                <label key={group.id} className="flex cursor-pointer items-start gap-2 rounded-lg border p-3 text-sm has-checked:border-primary">
                  <input
                    type="checkbox"
                    name="modifierGroupIds[]"
                    value={group.id}
                    defaultChecked={product?.modifierGroupIds.includes(group.id)}
                    className="mt-0.5 accent-primary"
                  />
                  <span>
                    <span className="font-medium">{group.name}</span>
                    <span className="block text-xs text-muted-foreground">{group.summary}</span>
                  </span>
                </label>
              ))}
            </div>
          )}
        </fieldset>
      </div>

      <aside className="flex flex-col gap-5">
        <div className="flex flex-col gap-2">
          <Label>Foto menu</Label>
          <div className="relative aspect-square overflow-hidden rounded-xl border bg-muted">
            {preview ? (
              <img src={preview} alt="Preview foto menu" className="size-full object-cover" />
            ) : (
              <div className="flex size-full flex-col items-center justify-center gap-2 text-muted-foreground">
                <ImagePlusIcon className="size-8" aria-hidden />
                <span className="text-xs">JPG, PNG, WebP · maks 2 MB</span>
              </div>
            )}
          </div>
          <input ref={fileInput} id="image" name="image" type="file" accept={IMAGE_TYPES.join(",")} className="sr-only" onChange={onPickImage} />
          <div className="flex gap-2">
            <Button type="button" variant="outline" className="flex-1" onClick={() => fileInput.current?.click()}>
              <ImagePlusIcon /> {preview ? "Ganti foto" : "Unggah foto"}
            </Button>
            {preview ? (
              <Button type="button" variant="ghost" onClick={onRemoveImage} aria-label="Hapus foto">
                <Trash2Icon />
              </Button>
            ) : null}
          </div>
        </div>

        <label className="flex items-center justify-between gap-3 rounded-lg border p-3 text-sm">
          <span>
            <span className="font-medium">Aktif di menu</span>
            <span className="block text-xs text-muted-foreground">Nonaktif = disembunyikan dari customer.</span>
          </span>
          <Switch checked={isActive} onCheckedChange={setIsActive} aria-label="Aktif di menu" />
        </label>

        <div className="flex gap-2">
          <Link href="/admin/products" className={buttonVariants({ variant: "outline", className: "flex-1" })}>
            Batal
          </Link>
          <Button type="submit" className="flex-1" disabled={pending}>
            {pending ? <Loader2Icon className="animate-spin" /> : null}
            {pending ? "Menyimpan…" : "Simpan"}
          </Button>
        </div>
      </aside>
    </form>
  );
}
