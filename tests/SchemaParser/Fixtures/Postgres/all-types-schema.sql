--
-- PostgreSQL database dump
--

\restrict s1X5eBnp9GZih8H1JUGZ92etxPeecclvmSf0eG6rRs1BLbzbvs6mmZQZJL6D9Hp

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

--
-- Name: reporting; Type: SCHEMA; Schema: -; Owner: -
--

CREATE SCHEMA reporting;


--
-- Name: citext; Type: EXTENSION; Schema: -; Owner: -
--

CREATE EXTENSION IF NOT EXISTS citext WITH SCHEMA public;


--
-- Name: EXTENSION citext; Type: COMMENT; Schema: -; Owner: -
--

COMMENT ON EXTENSION citext IS 'data type for case-insensitive character strings';


--
-- Name: hstore; Type: EXTENSION; Schema: -; Owner: -
--

CREATE EXTENSION IF NOT EXISTS hstore WITH SCHEMA public;


--
-- Name: EXTENSION hstore; Type: COMMENT; Schema: -; Owner: -
--

COMMENT ON EXTENSION hstore IS 'data type for storing sets of (key, value) pairs';


--
-- Name: ltree; Type: EXTENSION; Schema: -; Owner: -
--

CREATE EXTENSION IF NOT EXISTS ltree WITH SCHEMA public;


--
-- Name: EXTENSION ltree; Type: COMMENT; Schema: -; Owner: -
--

COMMENT ON EXTENSION ltree IS 'data type for hierarchical tree-like structures';


--
-- Name: complex; Type: TYPE; Schema: public; Owner: -
--

CREATE TYPE public.complex AS (
	r double precision,
	i double precision
);


--
-- Name: float8_range; Type: TYPE; Schema: public; Owner: -
--

CREATE TYPE public.float8_range AS RANGE (
    subtype = double precision,
    multirange_type_name = public.float8_multirange
);


--
-- Name: mood; Type: TYPE; Schema: public; Owner: -
--

CREATE TYPE public.mood AS ENUM (
    'sad',
    'ok',
    'happy'
);


--
-- Name: positive_int; Type: DOMAIN; Schema: public; Owner: -
--

CREATE DOMAIN public.positive_int AS integer
	CONSTRAINT positive_int_check CHECK ((VALUE > 0));


--
-- Name: touch_updated_at(); Type: FUNCTION; Schema: public; Owner: -
--

CREATE FUNCTION public.touch_updated_at() RETURNS trigger
    LANGUAGE plpgsql
    AS $$
BEGIN
    NEW.updated_at = now();
    RETURN NEW;
END;
$$;


SET default_tablespace = '';

SET default_table_access_method = heap;

--
-- Name: all_types; Type: TABLE; Schema: public; Owner: -
--

CREATE TABLE public.all_types (
    id bigint NOT NULL,
    small_id smallint NOT NULL,
    plain_serial integer NOT NULL,
    identity_col integer NOT NULL,
    identity_always bigint NOT NULL,
    c_smallint smallint,
    c_int2 smallint,
    c_integer integer,
    c_int integer,
    c_int4 integer,
    c_bigint bigint NOT NULL,
    c_int8 bigint,
    c_decimal numeric,
    c_decimal_p numeric(10,2),
    c_numeric numeric,
    c_numeric_p numeric(14,4),
    c_real real,
    c_float4 real,
    c_double double precision,
    c_float8 double precision,
    c_float double precision,
    c_float_p real,
    c_money money,
    c_varchar character varying(255),
    c_varchar_short character varying(64),
    c_varchar_nolen character varying,
    c_char character(10),
    c_char_short character(2),
    c_bpchar bpchar,
    c_text text NOT NULL,
    c_citext public.citext,
    c_name name,
    c_bytea bytea,
    c_timestamp timestamp without time zone,
    c_timestamp_p timestamp(3) without time zone,
    c_timestamp_wo timestamp without time zone,
    c_timestamp_wo_p timestamp(0) without time zone,
    c_timestamptz timestamp with time zone,
    c_timestamp_tz timestamp with time zone,
    c_timestamp_tz_p timestamp(6) with time zone,
    c_date date,
    c_time time without time zone,
    c_time_p time(2) without time zone,
    c_time_wo time without time zone,
    c_timetz time with time zone,
    c_time_tz time with time zone,
    c_interval interval,
    c_interval_f interval day to second(3),
    c_interval_y interval year to month,
    c_boolean boolean DEFAULT false NOT NULL,
    c_bool boolean,
    c_mood public.mood,
    c_complex public.complex,
    c_positive public.positive_int,
    c_point point,
    c_line line,
    c_lseg lseg,
    c_box box,
    c_path path,
    c_polygon polygon,
    c_circle circle,
    c_cidr cidr,
    c_inet inet,
    c_macaddr macaddr,
    c_macaddr8 macaddr8,
    c_bit bit(3),
    c_bit1 bit(1),
    c_varbit bit varying(5),
    c_varbit_nolen bit varying,
    c_tsvector tsvector,
    c_tsquery tsquery,
    c_uuid uuid,
    c_xml xml,
    c_json json,
    c_jsonb jsonb,
    c_jsonpath jsonpath,
    c_int_array integer[],
    c_int_array_sized integer[],
    c_text_array text[],
    c_varchar_array character varying(255)[],
    c_int_matrix integer[],
    c_array_kw integer[],
    c_mood_array public.mood[],
    c_int4range int4range,
    c_int8range int8range,
    c_numrange numrange,
    c_tsrange tsrange,
    c_tstzrange tstzrange,
    c_daterange daterange,
    c_int4multirange int4multirange,
    c_datemultirange datemultirange,
    c_custom_range public.float8_range,
    c_hstore public.hstore,
    c_ltree public.ltree,
    c_oid oid,
    c_regclass regclass,
    c_pg_lsn pg_lsn,
    c_pg_snapshot pg_snapshot,
    c_generated integer GENERATED ALWAYS AS ((c_integer * 2)) STORED,
    created_at timestamp(0) without time zone,
    updated_at timestamp(0) without time zone,
    deleted_at timestamp(0) without time zone,
    CONSTRAINT all_types_check CHECK ((c_integer > 0))
);


--
-- Name: TABLE all_types; Type: COMMENT; Schema: public; Owner: -
--

COMMENT ON TABLE public.all_types IS 'Every PostgreSQL data type';


--
-- Name: COLUMN all_types.c_text; Type: COMMENT; Schema: public; Owner: -
--

COMMENT ON COLUMN public.all_types.c_text IS 'a text column';


--
-- Name: active_types; Type: VIEW; Schema: public; Owner: -
--

CREATE VIEW public.active_types AS
 SELECT id,
    c_text
   FROM public.all_types
  WHERE (deleted_at IS NULL);


--
-- Name: all_types_id_seq; Type: SEQUENCE; Schema: public; Owner: -
--

CREATE SEQUENCE public.all_types_id_seq
    START WITH 1
    INCREMENT BY 1
    NO MINVALUE
    NO MAXVALUE
    CACHE 1;


--
-- Name: all_types_id_seq; Type: SEQUENCE OWNED BY; Schema: public; Owner: -
--

ALTER SEQUENCE public.all_types_id_seq OWNED BY public.all_types.id;


--
-- Name: all_types_identity_always_seq; Type: SEQUENCE; Schema: public; Owner: -
--

ALTER TABLE public.all_types ALTER COLUMN identity_always ADD GENERATED ALWAYS AS IDENTITY (
    SEQUENCE NAME public.all_types_identity_always_seq
    START WITH 1
    INCREMENT BY 1
    NO MINVALUE
    NO MAXVALUE
    CACHE 1
);


--
-- Name: all_types_identity_col_seq; Type: SEQUENCE; Schema: public; Owner: -
--

ALTER TABLE public.all_types ALTER COLUMN identity_col ADD GENERATED BY DEFAULT AS IDENTITY (
    SEQUENCE NAME public.all_types_identity_col_seq
    START WITH 1
    INCREMENT BY 1
    NO MINVALUE
    NO MAXVALUE
    CACHE 1
);


--
-- Name: all_types_plain_serial_seq; Type: SEQUENCE; Schema: public; Owner: -
--

CREATE SEQUENCE public.all_types_plain_serial_seq
    AS integer
    START WITH 1
    INCREMENT BY 1
    NO MINVALUE
    NO MAXVALUE
    CACHE 1;


--
-- Name: all_types_plain_serial_seq; Type: SEQUENCE OWNED BY; Schema: public; Owner: -
--

ALTER SEQUENCE public.all_types_plain_serial_seq OWNED BY public.all_types.plain_serial;


--
-- Name: all_types_small_id_seq; Type: SEQUENCE; Schema: public; Owner: -
--

CREATE SEQUENCE public.all_types_small_id_seq
    AS smallint
    START WITH 1
    INCREMENT BY 1
    NO MINVALUE
    NO MAXVALUE
    CACHE 1;


--
-- Name: all_types_small_id_seq; Type: SEQUENCE OWNED BY; Schema: public; Owner: -
--

ALTER SEQUENCE public.all_types_small_id_seq OWNED BY public.all_types.small_id;


--
-- Name: partitioned; Type: TABLE; Schema: public; Owner: -
--

CREATE TABLE public.partitioned (
    id bigint NOT NULL,
    created_at timestamp(0) without time zone NOT NULL
)
PARTITION BY RANGE (created_at);


--
-- Name: partitioned_id_seq; Type: SEQUENCE; Schema: public; Owner: -
--

CREATE SEQUENCE public.partitioned_id_seq
    START WITH 1
    INCREMENT BY 1
    NO MINVALUE
    NO MAXVALUE
    CACHE 1;


--
-- Name: partitioned_id_seq; Type: SEQUENCE OWNED BY; Schema: public; Owner: -
--

ALTER SEQUENCE public.partitioned_id_seq OWNED BY public.partitioned.id;


--
-- Name: related; Type: TABLE; Schema: public; Owner: -
--

CREATE TABLE public.related (
    id bigint NOT NULL,
    all_type_id bigint NOT NULL,
    label character varying(100) NOT NULL
);


--
-- Name: related_id_seq; Type: SEQUENCE; Schema: public; Owner: -
--

CREATE SEQUENCE public.related_id_seq
    START WITH 1
    INCREMENT BY 1
    NO MINVALUE
    NO MAXVALUE
    CACHE 1;


--
-- Name: related_id_seq; Type: SEQUENCE OWNED BY; Schema: public; Owner: -
--

ALTER SEQUENCE public.related_id_seq OWNED BY public.related.id;


--
-- Name: summaries; Type: TABLE; Schema: reporting; Owner: -
--

CREATE TABLE reporting.summaries (
    id bigint NOT NULL,
    total numeric(12,2) DEFAULT 0 NOT NULL
);


--
-- Name: summaries_id_seq; Type: SEQUENCE; Schema: reporting; Owner: -
--

CREATE SEQUENCE reporting.summaries_id_seq
    START WITH 1
    INCREMENT BY 1
    NO MINVALUE
    NO MAXVALUE
    CACHE 1;


--
-- Name: summaries_id_seq; Type: SEQUENCE OWNED BY; Schema: reporting; Owner: -
--

ALTER SEQUENCE reporting.summaries_id_seq OWNED BY reporting.summaries.id;


--
-- Name: all_types id; Type: DEFAULT; Schema: public; Owner: -
--

ALTER TABLE ONLY public.all_types ALTER COLUMN id SET DEFAULT nextval('public.all_types_id_seq'::regclass);


--
-- Name: all_types small_id; Type: DEFAULT; Schema: public; Owner: -
--

ALTER TABLE ONLY public.all_types ALTER COLUMN small_id SET DEFAULT nextval('public.all_types_small_id_seq'::regclass);


--
-- Name: all_types plain_serial; Type: DEFAULT; Schema: public; Owner: -
--

ALTER TABLE ONLY public.all_types ALTER COLUMN plain_serial SET DEFAULT nextval('public.all_types_plain_serial_seq'::regclass);


--
-- Name: partitioned id; Type: DEFAULT; Schema: public; Owner: -
--

ALTER TABLE ONLY public.partitioned ALTER COLUMN id SET DEFAULT nextval('public.partitioned_id_seq'::regclass);


--
-- Name: related id; Type: DEFAULT; Schema: public; Owner: -
--

ALTER TABLE ONLY public.related ALTER COLUMN id SET DEFAULT nextval('public.related_id_seq'::regclass);


--
-- Name: summaries id; Type: DEFAULT; Schema: reporting; Owner: -
--

ALTER TABLE ONLY reporting.summaries ALTER COLUMN id SET DEFAULT nextval('reporting.summaries_id_seq'::regclass);


--
-- Name: all_types all_types_pkey; Type: CONSTRAINT; Schema: public; Owner: -
--

ALTER TABLE ONLY public.all_types
    ADD CONSTRAINT all_types_pkey PRIMARY KEY (id);


--
-- Name: related related_pkey; Type: CONSTRAINT; Schema: public; Owner: -
--

ALTER TABLE ONLY public.related
    ADD CONSTRAINT related_pkey PRIMARY KEY (id);


--
-- Name: summaries summaries_pkey; Type: CONSTRAINT; Schema: reporting; Owner: -
--

ALTER TABLE ONLY reporting.summaries
    ADD CONSTRAINT summaries_pkey PRIMARY KEY (id);


--
-- Name: all_types_c_text_idx; Type: INDEX; Schema: public; Owner: -
--

CREATE INDEX all_types_c_text_idx ON public.all_types USING btree (c_text);


--
-- Name: all_types_jsonb_idx; Type: INDEX; Schema: public; Owner: -
--

CREATE INDEX all_types_jsonb_idx ON public.all_types USING gin (c_jsonb);


--
-- Name: all_types_uuid_idx; Type: INDEX; Schema: public; Owner: -
--

CREATE UNIQUE INDEX all_types_uuid_idx ON public.all_types USING btree (c_uuid) WHERE (c_uuid IS NOT NULL);


--
-- Name: all_types all_types_touch; Type: TRIGGER; Schema: public; Owner: -
--

CREATE TRIGGER all_types_touch BEFORE UPDATE ON public.all_types FOR EACH ROW EXECUTE FUNCTION public.touch_updated_at();


--
-- Name: related related_all_type_id_fkey; Type: FK CONSTRAINT; Schema: public; Owner: -
--

ALTER TABLE ONLY public.related
    ADD CONSTRAINT related_all_type_id_fkey FOREIGN KEY (all_type_id) REFERENCES public.all_types(id) ON DELETE CASCADE;


--
-- PostgreSQL database dump complete
--

\unrestrict s1X5eBnp9GZih8H1JUGZ92etxPeecclvmSf0eG6rRs1BLbzbvs6mmZQZJL6D9Hp

