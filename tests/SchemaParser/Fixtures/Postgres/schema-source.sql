-- Generate schema-dump.sql with pg_dump --schema-only --clean --if-exists --no-owner.
CREATE SCHEMA storage;
CREATE TYPE storage.buckettype AS ENUM ('STANDARD', 'ANALYTICS');
CREATE TYPE public.compression AS ENUM ('gzip', 'zstd');
CREATE DOMAIN public.cased AS integer DEFAULT CASE WHEN random() > 0.5 THEN NULL ELSE 1 END NOT NULL;
CREATE TABLE public.defaults (
    id integer PRIMARY KEY,
    nested integer DEFAULT CASE WHEN random() > 0.5 THEN CASE WHEN random() > 0.2 THEN NULL ELSE 2 END ELSE 1 END NOT NULL,
    simple integer DEFAULT CASE floor(random() * 3)::int WHEN 1 THEN NULL ELSE 3 END,
    collated text DEFAULT ('a' COLLATE "C"),
    nulltest boolean DEFAULT (now() IS NOT NULL),
    negated boolean DEFAULT (NOT (random() > 0.5)),
    bucket storage.buckettype DEFAULT 'STANDARD'::storage.buckettype NOT NULL,
    algorithm public.compression DEFAULT 'gzip'::public.compression NOT NULL,
    exclude integer,
    cased public.cased
);
CREATE INDEX defaults_bucket ON public.defaults (bucket);
ALTER TABLE public.defaults CLUSTER ON defaults_bucket;
CREATE TABLE public.replica (id integer NOT NULL, name text);
CREATE UNIQUE INDEX replica_id ON public.replica (id);
ALTER TABLE public.replica REPLICA IDENTITY USING INDEX replica_id;
CREATE TABLE public.replica_full (id integer);
ALTER TABLE public.replica_full REPLICA IDENTITY FULL;
CREATE FUNCTION public.noop() RETURNS trigger LANGUAGE plpgsql AS $$ BEGIN RETURN NEW; END $$;
CREATE TRIGGER t_always BEFORE INSERT ON public.replica FOR EACH ROW EXECUTE FUNCTION public.noop();
ALTER TABLE public.replica ENABLE ALWAYS TRIGGER t_always;
CREATE TRIGGER t_replica BEFORE INSERT ON public.replica FOR EACH ROW EXECUTE FUNCTION public.noop();
ALTER TABLE public.replica ENABLE REPLICA TRIGGER t_replica;
CREATE TRIGGER t_disabled BEFORE INSERT ON public.replica FOR EACH ROW EXECUTE FUNCTION public.noop();
ALTER TABLE public.replica DISABLE TRIGGER t_disabled;
CREATE RULE r_disabled AS ON UPDATE TO public.replica_full DO ALSO NOTHING;
ALTER TABLE public.replica_full DISABLE RULE r_disabled;
CREATE FOREIGN DATA WRAPPER dummy;
CREATE SERVER remote_server FOREIGN DATA WRAPPER dummy;
CREATE FOREIGN TABLE public.foreign_records (
    id integer OPTIONS (column_name 'remote_id') NOT NULL,
    name text COLLATE "C" DEFAULT 'x' CHECK (name <> ''),
    kind public.compression
) SERVER remote_server OPTIONS (schema_name 'public', table_name 'records');
CREATE TABLE public.measurements (id integer NOT NULL, at date NOT NULL) PARTITION BY RANGE (at);
CREATE FOREIGN TABLE public.measurements_remote PARTITION OF public.measurements FOR VALUES FROM ('2020-01-01') TO ('2021-01-01') SERVER remote_server;
CREATE TABLE public.base (id integer NOT NULL, label text DEFAULT 'b');
CREATE FOREIGN TABLE public.child_remote (extra integer) INHERITS (public.base) SERVER remote_server;
COMMENT ON FOREIGN TABLE public.foreign_records IS 'remote records';
CREATE TABLE public.orders (id integer PRIMARY KEY, total integer NOT NULL);
CREATE MATERIALIZED VIEW public.order_totals AS SELECT id, total FROM public.orders WITH NO DATA;
CREATE UNIQUE INDEX order_totals_id ON public.order_totals (id);
ALTER MATERIALIZED VIEW public.order_totals CLUSTER ON order_totals_id;
ALTER MATERIALIZED VIEW public.order_totals ALTER COLUMN total SET STATISTICS 200;
CREATE VIEW public.order_view AS SELECT id, total FROM public.orders;
ALTER VIEW public.order_view ALTER COLUMN total SET DEFAULT 0;
COMMENT ON VIEW public.order_view IS 'orders';
COMMENT ON MATERIALIZED VIEW public.order_totals IS 'totals';
COMMENT ON COLUMN public.order_totals.total IS 'sum';
