import { Head, useForm } from "@inertiajs/react";
import { Loader2Icon, LogInIcon } from "lucide-react";

import { AuthLayout } from "@/components/auth-layout";
import { InstallApp } from "@/components/install-app";
import { LogoutButton } from "@/components/logout-button";
import { PasswordInput } from "@/components/password-input";
import { Button } from "@/components/ui/button";
import { Input } from "@/components/ui/input";
import { Label } from "@/components/ui/label";

type Props = { next?: string | null; notice?: string | null; reason?: string };

export default function Login({ next, notice, reason }: Props) {
  return (
    <AuthLayout>
      <Head title="Login" />
      <div className="flex flex-col gap-1.5">
        <h1 className="text-2xl font-semibold tracking-tight">Masuk ke Sudaya</h1>
        <p className="text-sm text-muted-foreground">Untuk barista, kasir, admin, dan manajemen.</p>
      </div>
      {reason ? (
        // Sudah login tapi tidak bisa masuk: tampilkan alasan, jangan redirect (mencegah loop).
        <div className="flex flex-col gap-4">
          <p role="alert" className="rounded-lg border bg-muted px-3 py-2 text-sm">
            {reason}
          </p>
          <LogoutButton variant="default" label="Keluar & ganti akun" />
        </div>
      ) : (
        <LoginForm next={next ?? undefined} notice={notice ?? undefined} />
      )}
      <InstallApp />
    </AuthLayout>
  );
}

function LoginForm({ next, notice }: { next?: string; notice?: string }) {
  const form = useForm({ email: "", password: "", next: next ?? "" });
  // "form" adalah error umum dari server, di luar field.
  const error = (form.errors as Partial<Record<"form", string>>).form;

  function submit(event: React.FormEvent<HTMLFormElement>) {
    event.preventDefault();
    form.post("/login", { onFinish: () => form.reset("password") });
  }

  return (
    <form onSubmit={submit} className="flex flex-col gap-5" noValidate>
      {notice && !error ? (
        <p role="status" className="rounded-lg border bg-muted px-3 py-2 text-sm">
          {notice}
        </p>
      ) : null}

      {error ? (
        <p
          role="alert"
          className="rounded-lg border border-destructive/30 bg-destructive/10 px-3 py-2 text-sm text-destructive-strong"
        >
          {error}
        </p>
      ) : null}

      <div className="flex flex-col gap-2">
        <Label htmlFor="email">Email</Label>
        <Input
          id="email"
          name="email"
          type="email"
          inputMode="email"
          autoComplete="username"
          placeholder="nama@sudaya.co"
          value={form.data.email}
          onChange={(e) => form.setData("email", e.target.value)}
          aria-invalid={form.errors.email ? true : undefined}
          aria-describedby={form.errors.email ? "email-error" : undefined}
          className="h-10"
          required
          autoFocus
        />
        {form.errors.email ? (
          <p id="email-error" className="text-xs text-destructive">
            {form.errors.email}
          </p>
        ) : null}
      </div>

      <div className="flex flex-col gap-2">
        <Label htmlFor="password">Password</Label>
        <PasswordInput
          id="password"
          name="password"
          autoComplete="current-password"
          value={form.data.password}
          onChange={(e) => form.setData("password", e.target.value)}
          aria-invalid={form.errors.password ? true : undefined}
          aria-describedby={form.errors.password ? "password-error" : undefined}
          className="h-10"
          required
        />
        {form.errors.password ? (
          <p id="password-error" className="text-xs text-destructive">
            {form.errors.password}
          </p>
        ) : null}
      </div>

      <Button type="submit" size="lg" disabled={form.processing}>
        {form.processing ? <Loader2Icon className="animate-spin" /> : <LogInIcon />}
        {form.processing ? "Memproses…" : "Masuk"}
      </Button>
      {/* Reset password mandiri belum ada; admin membuat link reset di menu Staf. */}
      <p className="text-center text-sm text-muted-foreground">Lupa password? Minta link reset ke admin outlet.</p>
    </form>
  );
}
