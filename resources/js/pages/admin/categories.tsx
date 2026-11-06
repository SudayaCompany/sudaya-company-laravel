import { Head, router } from "@inertiajs/react";
import { Loader2Icon, PlusIcon, SaveIcon, TagsIcon, Trash2Icon } from "lucide-react";
import { useState } from "react";

import { ConfirmAction } from "@/components/confirm-action";
import { EmptyState } from "@/components/empty-state";
import { PageHeader } from "@/components/page-header";
import { Button } from "@/components/ui/button";
import { Card, CardContent, CardDescription, CardHeader, CardTitle } from "@/components/ui/card";
import { Input } from "@/components/ui/input";
import { Switch } from "@/components/ui/switch";
import { AdminLayout } from "@/layouts/admin-layout";
import { slugify } from "@/lib/catalog/slug";

type Category = {
  id: string;
  name: string;
  slug: string;
  sortOrder: number;
  isActive: boolean;
  productCount: number;
};

type Props = {
  brand: { id: string; name: string };
  categories: Category[];
  can: { create: boolean; edit: boolean; delete: boolean };
};

/** Mutasi Inertia; hasilnya tampil sebagai toast lewat flash. */
function useMutation() {
  const [pending, setPending] = useState(false);
  const options = { preserveScroll: true, onStart: () => setPending(true), onFinish: () => setPending(false) };
  return { pending, options };
}

export default function Categories({ brand, categories, can }: Props) {
  return (
    <AdminLayout>
      <Head title="Kategori" />
      <PageHeader title="Kategori" description={`${brand.name} · Urutan menentukan posisi kategori di menu customer.`} />
      {can.create ? (
        <Card>
          <CardHeader>
            <CardTitle>Kategori baru</CardTitle>
            <CardDescription>Contoh: Coffee, Non Coffee, Tea, Food, Snack.</CardDescription>
          </CardHeader>
          <CardContent>
            <NewCategoryForm brandId={brand.id} nextSortOrder={(categories.at(-1)?.sortOrder ?? -1) + 1} />
          </CardContent>
        </Card>
      ) : null}

      {categories.length === 0 ? (
        <EmptyState icon={TagsIcon} title="Belum ada kategori" description="Kategori mengelompokkan menu di halaman customer." />
      ) : (
        <Card>
          <CardContent>
            <div className="hidden gap-2 pb-2 text-xs text-muted-foreground sm:grid sm:grid-cols-[1fr_1fr_5rem_8rem_4.5rem]">
              <span>Nama</span>
              <span>Slug</span>
              <span>Urutan</span>
              <span>Aktif</span>
              <span />
            </div>
            {categories.map((category) => (
              <CategoryRow
                key={`${category.id}:${category.name}:${category.slug}:${category.sortOrder}`}
                category={category}
                canEdit={can.edit}
                canDelete={can.delete}
              />
            ))}
          </CardContent>
        </Card>
      )}
    </AdminLayout>
  );
}

function NewCategoryForm({ brandId, nextSortOrder }: { brandId: string; nextSortOrder: number }) {
  const [name, setName] = useState("");
  const { pending, options } = useMutation();

  return (
    <form
      className="flex flex-col gap-2 sm:flex-row"
      onSubmit={(event) => {
        event.preventDefault();
        router.post(
          "/admin/categories",
          { brandId, values: { name, slug: slugify(name), sortOrder: nextSortOrder, isActive: true } },
          { ...options, onFlash: (flash) => (flash as { success?: string }).success && setName("") },
        );
      }}
    >
      <Input
        value={name}
        onChange={(e) => setName(e.target.value)}
        placeholder="Nama kategori, mis. Coffee"
        aria-label="Nama kategori baru"
        minLength={2}
        maxLength={60}
        required
      />
      <Button type="submit" disabled={pending || name.trim().length < 2}>
        {pending ? <Loader2Icon className="animate-spin" /> : <PlusIcon />} Tambah
      </Button>
    </form>
  );
}

function CategoryRow({ category, canEdit, canDelete }: { category: Category; canEdit: boolean; canDelete: boolean }) {
  const [name, setName] = useState(category.name);
  const [slug, setSlug] = useState(category.slug);
  const [sortOrder, setSortOrder] = useState(String(category.sortOrder));
  const { pending, options } = useMutation();

  const dirty = name !== category.name || slug !== category.slug || sortOrder !== String(category.sortOrder);
  const save = () => router.put(`/admin/categories/${category.id}`, { values: { name, slug, sortOrder, isActive: category.isActive } }, options);
  // Switch aktif hanya mengubah status; nama/slug/urutan yang belum disimpan tidak ikut terkirim.
  const setActive = (isActive: boolean) =>
    router.put(
      `/admin/categories/${category.id}`,
      { values: { name: category.name, slug: category.slug, sortOrder: String(category.sortOrder), isActive } },
      options,
    );

  return (
    <div className="grid items-center gap-2 border-b py-3 last:border-b-0 sm:grid-cols-[1fr_1fr_5rem_8rem_4.5rem]">
      <Input value={name} onChange={(e) => setName(e.target.value)} disabled={!canEdit} aria-label="Nama kategori" />
      <Input
        value={slug}
        onChange={(e) => setSlug(e.target.value.toLowerCase())}
        disabled={!canEdit}
        aria-label="Slug kategori"
        className="font-mono text-xs"
      />
      <Input
        type="number"
        min={0}
        value={sortOrder}
        onChange={(e) => setSortOrder(e.target.value)}
        disabled={!canEdit}
        aria-label="Urutan"
      />
      <label className="flex items-center gap-2 text-xs text-muted-foreground">
        <Switch
          checked={category.isActive}
          disabled={!canEdit || pending}
          onCheckedChange={setActive}
          aria-label={`${category.name} tampil di menu`}
        />
        {category.productCount} produk
      </label>
      <div className="flex justify-end gap-1">
        {canEdit ? (
          <Button size="icon-sm" variant="outline" disabled={!dirty || pending} onClick={() => save()} aria-label="Simpan kategori">
            {pending ? <Loader2Icon className="animate-spin" /> : <SaveIcon />}
          </Button>
        ) : null}
        {canDelete && category.productCount > 0 ? (
          // Server juga menolak; tombol nonaktif menjelaskan alasannya sebelum diklik.
          <Button
            size="icon-sm"
            variant="ghost"
            disabled
            aria-label={`${category.name} tidak bisa dihapus: masih berisi ${category.productCount} produk`}
            title="Pindahkan produknya dulu sebelum menghapus kategori"
          >
            <Trash2Icon />
          </Button>
        ) : canDelete ? (
          <ConfirmAction
            trigger={
              <Button size="icon-sm" variant="ghost" disabled={pending} aria-label={`Hapus ${category.name}`}>
                <Trash2Icon className="text-destructive" />
              </Button>
            }
            title="Hapus kategori?"
            description={`${category.name} dihapus dari menu.`}
            confirmLabel="Hapus kategori"
            onConfirm={() => router.delete(`/admin/categories/${category.id}`, options)}
          />
        ) : null}
      </div>
    </div>
  );
}
