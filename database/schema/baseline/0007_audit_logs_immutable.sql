-- Audit log append-only (PRD §71, §81).
-- UPDATE/DELETE/TRUNCATE ditolak. Pengecualian HANYA untuk pembersihan data
-- test terisolasi: set_config('sudaya.audit_maintenance', 'on', true) di
-- transaksi yang sama. Aplikasi tidak pernah memakainya.
CREATE OR REPLACE FUNCTION public.audit_logs_immutable()
RETURNS trigger
LANGUAGE plpgsql
SET search_path = ''
AS $$
BEGIN
  IF tg_op = 'DELETE' AND current_setting('sudaya.audit_maintenance', true) = 'on' THEN
    RETURN old;
  END IF;
  RAISE EXCEPTION 'audit_logs bersifat append-only (%)', tg_op
    USING errcode = 'P0001';
END;
$$;--> statement-breakpoint
CREATE TRIGGER "audit_logs_immutable"
  BEFORE UPDATE OR DELETE ON "public"."audit_logs"
  FOR EACH ROW EXECUTE FUNCTION public.audit_logs_immutable();--> statement-breakpoint
CREATE TRIGGER "audit_logs_no_truncate"
  BEFORE TRUNCATE ON "public"."audit_logs"
  FOR EACH STATEMENT EXECUTE FUNCTION public.audit_logs_immutable();--> statement-breakpoint
REVOKE ALL ON FUNCTION public.audit_logs_immutable() FROM PUBLIC, anon, authenticated;
