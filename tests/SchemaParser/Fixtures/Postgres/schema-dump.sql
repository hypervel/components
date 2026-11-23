--
-- PostgreSQL database dump
--

\restrict 9WB28qKP5fHlnTV7zccGyzZkqMTPTz09dJt5cso63gKDAa3JbHo3egl6VqGwA7H

-- Dumped from database version 18.6 (Debian 18.6-1.pgdg13+2)
-- Dumped by pg_dump version 18.6 (Debian 18.6-1.pgdg13+2)

SET statement_timeout = 0;
SET lock_timeout = 0;
SET idle_in_transaction_session_timeout = 0;
SET transaction_timeout = 0;
SET client_encoding = 'UTF8';
SET standard_conforming_strings = on;
SELECT pg_catalog.set_config('search_path', '', false);
SET check_function_bodies = false;
SET xmloption = content;
SET client_min_messages = warning;
SET row_security = off;

DROP TRIGGER IF EXISTS t_replica ON public.replica;
DROP TRIGGER IF EXISTS t_disabled ON public.replica;
DROP TRIGGER IF EXISTS t_always ON public.replica;
DROP RULE IF EXISTS r_disabled ON public.replica_full;
DROP INDEX IF EXISTS public.replica_id;
DROP INDEX IF EXISTS public.order_totals_id;
DROP INDEX IF EXISTS public.defaults_bucket;
ALTER TABLE IF EXISTS ONLY public.orders DROP CONSTRAINT IF EXISTS orders_pkey;
ALTER TABLE IF EXISTS ONLY public.defaults DROP CONSTRAINT IF EXISTS defaults_pkey;
ALTER TABLE IF EXISTS public.order_view ALTER COLUMN total DROP DEFAULT;
ALTER FOREIGN TABLE public.child_remote ALTER COLUMN label DROP DEFAULT;
DROP TABLE IF EXISTS public.replica_full;
DROP TABLE IF EXISTS public.replica;
DROP VIEW IF EXISTS public.order_view;
DROP MATERIALIZED VIEW IF EXISTS public.order_totals;
DROP TABLE IF EXISTS public.orders;
DROP FOREIGN TABLE IF EXISTS public.measurements_remote;
DROP TABLE IF EXISTS public.measurements;
DROP FOREIGN TABLE IF EXISTS public.foreign_records;
DROP TABLE IF EXISTS public.defaults;
DROP FOREIGN TABLE IF EXISTS public.child_remote;
DROP TABLE IF EXISTS public.base;
DROP SERVER IF EXISTS remote_server;
DROP FOREIGN DATA WRAPPER IF EXISTS dummy;
DROP FUNCTION IF EXISTS public.noop();
DROP TYPE IF EXISTS storage.buckettype;
DROP TYPE IF EXISTS public.compression;
DROP DOMAIN IF EXISTS public.cased;
DROP SCHEMA IF EXISTS storage;
--
-- Name: storage; Type: SCHEMA; Schema: -; Owner: -
--

CREATE SCHEMA storage;


--
-- Name: cased; Type: DOMAIN; Schema: public; Owner: -
--

CREATE DOMAIN public.cased AS integer NOT NULL DEFAULT 
CASE
    WHEN (random() > (0.5)::double precision) THEN NULL::integer
    ELSE 1
END;


--
-- Name: compression; Type: TYPE; Schema: public; Owner: -
--

CREATE TYPE public.compression AS ENUM (
    'gzip',
    'zstd'
);


--
-- Name: buckettype; Type: TYPE; Schema: storage; Owner: -
--

CREATE TYPE storage.buckettype AS ENUM (
    'STANDARD',
    'ANALYTICS'
);


--
-- Name: noop(); Type: FUNCTION; Schema: public; Owner: -
--

CREATE FUNCTION public.noop() RETURNS trigger
    LANGUAGE plpgsql
    AS $$ BEGIN RETURN NEW; END $$;


--
-- Name: dummy; Type: FOREIGN DATA WRAPPER; Schema: -; Owner: -
--

CREATE FOREIGN DATA WRAPPER dummy;


--
-- Name: remote_server; Type: SERVER; Schema: -; Owner: -
--

CREATE SERVER remote_server FOREIGN DATA WRAPPER dummy;


SET default_tablespace = '';

SET default_table_access_method = heap;

--
-- Name: base; Type: TABLE; Schema: public; Owner: -
--

CREATE TABLE public.base (
    id integer NOT NULL,
    label text DEFAULT 'b'::text
);


--
-- Name: child_remote; Type: FOREIGN TABLE; Schema: public; Owner: -
--

CREATE FOREIGN TABLE public.child_remote (
    extra integer
)
INHERITS (public.base)
SERVER remote_server;


--
-- Name: defaults; Type: TABLE; Schema: public; Owner: -
--

CREATE TABLE public.defaults (
    id integer NOT NULL,
    nested integer DEFAULT 
CASE
    WHEN (random() > (0.5)::double precision) THEN
    CASE
        WHEN (random() > (0.2)::double precision) THEN NULL::integer
        ELSE 2
    END
    ELSE 1
END NOT NULL,
    simple integer DEFAULT 
CASE (floor((random() * (3)::double precision)))::integer
    WHEN 1 THEN NULL::integer
    ELSE 3
END,
    collated text DEFAULT ('a'::text COLLATE "C"),
    nulltest boolean DEFAULT (now() IS NOT NULL),
    negated boolean DEFAULT (NOT (random() > (0.5)::double precision)),
    bucket storage.buckettype DEFAULT 'STANDARD'::storage.buckettype NOT NULL,
    algorithm public.compression DEFAULT 'gzip'::public.compression NOT NULL,
    exclude integer,
    cased public.cased
);


--
-- Name: foreign_records; Type: FOREIGN TABLE; Schema: public; Owner: -
--

CREATE FOREIGN TABLE public.foreign_records (
    id integer NOT NULL,
    name text DEFAULT 'x'::text COLLATE pg_catalog."C",
    kind public.compression,
    CONSTRAINT foreign_records_name_check CHECK ((name <> ''::text))
)
SERVER remote_server
OPTIONS (
    schema_name 'public',
    table_name 'records'
);
ALTER FOREIGN TABLE ONLY public.foreign_records ALTER COLUMN id OPTIONS (
    column_name 'remote_id'
);


--
-- Name: FOREIGN TABLE foreign_records; Type: COMMENT; Schema: public; Owner: -
--

COMMENT ON FOREIGN TABLE public.foreign_records IS 'remote records';


--
-- Name: measurements; Type: TABLE; Schema: public; Owner: -
--

CREATE TABLE public.measurements (
    id integer NOT NULL,
    at date NOT NULL
)
PARTITION BY RANGE (at);


--
-- Name: measurements_remote; Type: FOREIGN TABLE; Schema: public; Owner: -
--

CREATE FOREIGN TABLE public.measurements_remote (
    id integer CONSTRAINT measurements_id_not_null NOT NULL,
    at date CONSTRAINT measurements_at_not_null NOT NULL
)
SERVER remote_server;


--
-- Name: orders; Type: TABLE; Schema: public; Owner: -
--

CREATE TABLE public.orders (
    id integer NOT NULL,
    total integer NOT NULL
);


--
-- Name: order_totals; Type: MATERIALIZED VIEW; Schema: public; Owner: -
--

CREATE MATERIALIZED VIEW public.order_totals AS
 SELECT id,
    total
   FROM public.orders
  WITH NO DATA;
ALTER TABLE ONLY public.order_totals ALTER COLUMN total SET STATISTICS 200;


--
-- Name: MATERIALIZED VIEW order_totals; Type: COMMENT; Schema: public; Owner: -
--

COMMENT ON MATERIALIZED VIEW public.order_totals IS 'totals';


--
-- Name: COLUMN order_totals.total; Type: COMMENT; Schema: public; Owner: -
--

COMMENT ON COLUMN public.order_totals.total IS 'sum';


--
-- Name: order_view; Type: VIEW; Schema: public; Owner: -
--

CREATE VIEW public.order_view AS
 SELECT id,
    total
   FROM public.orders;


--
-- Name: VIEW order_view; Type: COMMENT; Schema: public; Owner: -
--

COMMENT ON VIEW public.order_view IS 'orders';


--
-- Name: replica; Type: TABLE; Schema: public; Owner: -
--

CREATE TABLE public.replica (
    id integer NOT NULL,
    name text
);


--
-- Name: replica_full; Type: TABLE; Schema: public; Owner: -
--

CREATE TABLE public.replica_full (
    id integer
);

ALTER TABLE ONLY public.replica_full REPLICA IDENTITY FULL;


--
-- Name: measurements_remote; Type: TABLE ATTACH; Schema: public; Owner: -
--

ALTER TABLE ONLY public.measurements ATTACH PARTITION public.measurements_remote FOR VALUES FROM ('2020-01-01') TO ('2021-01-01');


--
-- Name: child_remote label; Type: DEFAULT; Schema: public; Owner: -
--

ALTER FOREIGN TABLE ONLY public.child_remote ALTER COLUMN label SET DEFAULT 'b'::text;


--
-- Name: order_view total; Type: DEFAULT; Schema: public; Owner: -
--

ALTER TABLE ONLY public.order_view ALTER COLUMN total SET DEFAULT 0;


--
-- Name: defaults defaults_pkey; Type: CONSTRAINT; Schema: public; Owner: -
--

ALTER TABLE ONLY public.defaults
    ADD CONSTRAINT defaults_pkey PRIMARY KEY (id);


--
-- Name: orders orders_pkey; Type: CONSTRAINT; Schema: public; Owner: -
--

ALTER TABLE ONLY public.orders
    ADD CONSTRAINT orders_pkey PRIMARY KEY (id);


--
-- Name: defaults_bucket; Type: INDEX; Schema: public; Owner: -
--

CREATE INDEX defaults_bucket ON public.defaults USING btree (bucket);

ALTER TABLE public.defaults CLUSTER ON defaults_bucket;


--
-- Name: order_totals_id; Type: INDEX; Schema: public; Owner: -
--

CREATE UNIQUE INDEX order_totals_id ON public.order_totals USING btree (id);

ALTER TABLE public.order_totals CLUSTER ON order_totals_id;


--
-- Name: replica_id; Type: INDEX; Schema: public; Owner: -
--

CREATE UNIQUE INDEX replica_id ON public.replica USING btree (id);

ALTER TABLE ONLY public.replica REPLICA IDENTITY USING INDEX replica_id;


--
-- Name: replica_full r_disabled; Type: RULE; Schema: public; Owner: -
--

CREATE RULE r_disabled AS
    ON UPDATE TO public.replica_full DO NOTHING;
ALTER TABLE public.replica_full DISABLE RULE r_disabled;


--
-- Name: replica t_always; Type: TRIGGER; Schema: public; Owner: -
--

CREATE TRIGGER t_always BEFORE INSERT ON public.replica FOR EACH ROW EXECUTE FUNCTION public.noop();

ALTER TABLE public.replica ENABLE ALWAYS TRIGGER t_always;


--
-- Name: replica t_disabled; Type: TRIGGER; Schema: public; Owner: -
--

CREATE TRIGGER t_disabled BEFORE INSERT ON public.replica FOR EACH ROW EXECUTE FUNCTION public.noop();

ALTER TABLE public.replica DISABLE TRIGGER t_disabled;


--
-- Name: replica t_replica; Type: TRIGGER; Schema: public; Owner: -
--

CREATE TRIGGER t_replica BEFORE INSERT ON public.replica FOR EACH ROW EXECUTE FUNCTION public.noop();

ALTER TABLE public.replica ENABLE REPLICA TRIGGER t_replica;


--
-- PostgreSQL database dump complete
--

\unrestrict 9WB28qKP5fHlnTV7zccGyzZkqMTPTz09dJt5cso63gKDAa3JbHo3egl6VqGwA7H

