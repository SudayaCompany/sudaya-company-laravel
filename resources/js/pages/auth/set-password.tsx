import { Head, useForm } from "@inertiajs/react";
import { Loader2Icon } from "lucide-react";
import { useEffect } from "react";
import { toast } from "sonner";

import { AuthLayout } from "@/components/auth-layout";
import { PasswordInput } from "@/components/password-input";
import { Button } from "@/components/ui/button";
import { Label } from "@/components/ui/label";

export default function SetPassword({ fullName }: { fullName: string }) {
  const form = useForm({ password: "", password_confirmation: "" });
  const error = (form.errors as Partial<Record<"form", string>>).form ?? form.errors.password ?? form.errors.password_confirmation;

  useEffect(() => {
    if (error) toast.error(error);
  }, [error]);

  function submit(event: React.FormEvent<HTMLFormElement>) {
    event.preventDefault();
    form.post("/auth/set-password", { onError: () => form.reset() });
  }

  return (
    <AuthLayout>
      <Head title="Buat password" />
      <div className="flex flex-col gap-1.5">
        <h1 className="text-2xl font-semibold tracking-tight">Buat password</h1>
        <p className="text-sm text-muted-foreground">
          Halo {fullName}, buat password untuk akun Sudaya Anda.
        </p>
      </div>
      <form onSubmit={submit} className="flex flex-col gap-5">
        {error ? (
          <p
            role="alert"
            className="rounded-lg border border-destructive/30 bg-destructive/10 px-3 py-2 text-sm text-destructive"
          >
            {error}
          </p>
        ) : null}
        <div className="flex flex-col gap-2">
          <Label htmlFor="password">Password baru</Label>
          <PasswordInput
            id="password"
            name="password"
            autoComplete="new-password"
            minLength={12}
            value={form.data.password}
            onChange={(e) => form.setData("password", e.target.value)}
            className="h-10"
            required
            autoFocus
          />
          <p className="text-xs text-muted-foreground">Minimal 12 karakter.</p>
        </div>
        <div className="flex flex-col gap-2">
          <Label htmlFor="password_confirmation">Ulangi password</Label>
          <PasswordInput
            id="password_confirmation"
            name="password_confirmation"
            autoComplete="new-password"
            value={form.data.password_confirmation}
            onChange={(e) => form.setData("password_confirmation", e.target.value)}
            className="h-10"
            required
          />
        </div>
        <Button type="submit" size="lg" className="h-10" disabled={form.processing}>
          {form.processing ? <Loader2Icon className="animate-spin" /> : null}
          {form.processing ? "Menyimpan…" : "Simpan password"}
        </Button>
      </form>
    </AuthLayout>
  );
}
