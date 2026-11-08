--
-- PostgreSQL database dump
--

\restrict i3eguSRgnSpVEZl4E6MsUaDevCdGzxGIoYXBoXv1oxVObu55wf4J2I3tAZCUgsD

-- Dumped from database version 17.11 (Debian 17.11-1.pgdg12+2)
-- Dumped by pg_dump version 18.6 (Ubuntu 18.6-1.pgdg24.04+2)

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

SET default_tablespace = '';

SET default_table_access_method = heap;

--
-- Name: accounts; Type: TABLE; Schema: public; Owner: -
--

CREATE TABLE public.accounts (
    id bigint NOT NULL,
    name character varying(255) NOT NULL,
    active character varying(1) NOT NULL,
    created_at timestamp(0) without time zone,
    updated_at timestamp(0) without time zone
);


--
-- Name: accounts_id_seq; Type: SEQUENCE; Schema: public; Owner: -
--

CREATE SEQUENCE public.accounts_id_seq
    START WITH 1
    INCREMENT BY 1
    NO MINVALUE
    NO MAXVALUE
    CACHE 1;


--
-- Name: accounts_id_seq; Type: SEQUENCE OWNED BY; Schema: public; Owner: -
--

ALTER SEQUENCE public.accounts_id_seq OWNED BY public.accounts.id;


--
-- Name: addresses; Type: TABLE; Schema: public; Owner: -
--

CREATE TABLE public.addresses (
    id bigint NOT NULL,
    uuid uuid NOT NULL,
    user_id bigint NOT NULL,
    custom_foreign_id_for_name bigint NOT NULL,
    address_id uuid NOT NULL,
    nullable_address_id uuid,
    foreign_id_constrained bigint NOT NULL,
    nullable_foreign_id_constrained bigint,
    foreign_ulid character(26) NOT NULL,
    nullable_foreign_ulid character(26),
    foreign_uuid uuid NOT NULL,
    nullable_foreign_uuid uuid
);


--
-- Name: addresses_id_seq; Type: SEQUENCE; Schema: public; Owner: -
--

CREATE SEQUENCE public.addresses_id_seq
    START WITH 1
    INCREMENT BY 1
    NO MINVALUE
    NO MAXVALUE
    CACHE 1;


--
-- Name: addresses_id_seq; Type: SEQUENCE OWNED BY; Schema: public; Owner: -
--

ALTER SEQUENCE public.addresses_id_seq OWNED BY public.addresses.id;


--
-- Name: guarded_models; Type: TABLE; Schema: public; Owner: -
--

CREATE TABLE public.guarded_models (
    id bigint NOT NULL,
    name character varying(255) NOT NULL,
    text character varying(255) NOT NULL,
    created_at timestamp(0) without time zone,
    updated_at timestamp(0) without time zone
);


--
-- Name: guarded_models_id_seq; Type: SEQUENCE; Schema: public; Owner: -
--

CREATE SEQUENCE public.guarded_models_id_seq
    START WITH 1
    INCREMENT BY 1
    NO MINVALUE
    NO MAXVALUE
    CACHE 1;


--
-- Name: guarded_models_id_seq; Type: SEQUENCE OWNED BY; Schema: public; Owner: -
--

ALTER SEQUENCE public.guarded_models_id_seq OWNED BY public.guarded_models.id;


--
-- Name: migrations; Type: TABLE; Schema: public; Owner: -
--

CREATE TABLE public.migrations (
    id integer NOT NULL,
    migration character varying(255) NOT NULL,
    batch integer NOT NULL
);


--
-- Name: migrations_id_seq; Type: SEQUENCE; Schema: public; Owner: -
--

CREATE SEQUENCE public.migrations_id_seq
    AS integer
    START WITH 1
    INCREMENT BY 1
    NO MINVALUE
    NO MAXVALUE
    CACHE 1;


--
-- Name: migrations_id_seq; Type: SEQUENCE OWNED BY; Schema: public; Owner: -
--

ALTER SEQUENCE public.migrations_id_seq OWNED BY public.migrations.id;


--
-- Name: model_with_casts; Type: TABLE; Schema: public; Owner: -
--

CREATE TABLE public.model_with_casts (
    id bigint NOT NULL,
    "int" integer NOT NULL,
    "integer" integer NOT NULL,
    "real" double precision NOT NULL,
    "float" double precision NOT NULL,
    double double precision NOT NULL,
    "decimal" numeric(8,2) NOT NULL,
    string character varying(255) NOT NULL,
    bool boolean NOT NULL,
    "boolean" boolean NOT NULL,
    object json NOT NULL,
    "array" json NOT NULL,
    "json" json NOT NULL,
    collection json NOT NULL,
    nullable_collection json,
    date date NOT NULL,
    datetime timestamp(0) without time zone NOT NULL,
    immutable_date date NOT NULL,
    immutable_datetime timestamp(0) without time zone NOT NULL,
    "timestamp" timestamp(0) without time zone NOT NULL,
    created_at timestamp(0) without time zone,
    updated_at timestamp(0) without time zone,
    deleted_at timestamp(0) without time zone
);


--
-- Name: model_with_casts_id_seq; Type: SEQUENCE; Schema: public; Owner: -
--

CREATE SEQUENCE public.model_with_casts_id_seq
    START WITH 1
    INCREMENT BY 1
    NO MINVALUE
    NO MAXVALUE
    CACHE 1;


--
-- Name: model_with_casts_id_seq; Type: SEQUENCE OWNED BY; Schema: public; Owner: -
--

ALTER SEQUENCE public.model_with_casts_id_seq OWNED BY public.model_with_casts.id;


--
-- Name: posts; Type: TABLE; Schema: public; Owner: -
--

CREATE TABLE public.posts (
    id bigint NOT NULL,
    name character varying(255) NOT NULL,
    created_at timestamp(0) without time zone,
    updated_at timestamp(0) without time zone
);


--
-- Name: posts_id_seq; Type: SEQUENCE; Schema: public; Owner: -
--

CREATE SEQUENCE public.posts_id_seq
    START WITH 1
    INCREMENT BY 1
    NO MINVALUE
    NO MAXVALUE
    CACHE 1;


--
-- Name: posts_id_seq; Type: SEQUENCE OWNED BY; Schema: public; Owner: -
--

ALTER SEQUENCE public.posts_id_seq OWNED BY public.posts.id;


--
-- Name: posts_users; Type: TABLE; Schema: public; Owner: -
--

CREATE TABLE public.posts_users (
    user_id character varying(255) NOT NULL,
    post_id character varying(255) NOT NULL,
    created_at timestamp(0) without time zone,
    updated_at timestamp(0) without time zone
);


--
-- Name: role_user; Type: TABLE; Schema: public; Owner: -
--

CREATE TABLE public.role_user (
    role_id uuid NOT NULL,
    user_id bigint NOT NULL
);


--
-- Name: roles; Type: TABLE; Schema: public; Owner: -
--

CREATE TABLE public.roles (
    id uuid NOT NULL,
    name character varying(255) NOT NULL,
    created_at timestamp(0) without time zone,
    updated_at timestamp(0) without time zone
);


--
-- Name: ulid_models; Type: TABLE; Schema: public; Owner: -
--

CREATE TABLE public.ulid_models (
    id character(26) NOT NULL,
    created_at timestamp(0) without time zone,
    updated_at timestamp(0) without time zone
);


--
-- Name: users; Type: TABLE; Schema: public; Owner: -
--

CREATE TABLE public.users (
    id bigint NOT NULL,
    name character varying(255) NOT NULL,
    email character varying(255) NOT NULL,
    email_verified_at timestamp(0) without time zone,
    password character varying(255) NOT NULL,
    "stringButInt" character varying(255) NOT NULL,
    "floatButRoundedDecimalString" double precision NOT NULL,
    allowed_ips json NOT NULL,
    meta json NOT NULL,
    options json NOT NULL,
    properties json NOT NULL,
    enum_collection json NOT NULL,
    enum_array_object json NOT NULL,
    favorites json NOT NULL,
    secret character varying(255) NOT NULL,
    blocked boolean NOT NULL,
    unknown_column text NOT NULL,
    remember_token character varying(100),
    enum_status character varying(255) NOT NULL,
    basic_enum character varying(255) NOT NULL,
    backed_enum character varying(255) NOT NULL,
    castable_with_argument character varying(255) NOT NULL,
    "int" integer NOT NULL,
    "integer" integer NOT NULL,
    unsigned_integer integer NOT NULL,
    unsigned_integer_method integer NOT NULL,
    "real" double precision NOT NULL,
    "float" double precision NOT NULL,
    double double precision NOT NULL,
    "decimal" numeric(8,2) NOT NULL,
    nullable_decimal numeric(8,2),
    string character varying(255) NOT NULL,
    bool boolean NOT NULL,
    "boolean" boolean NOT NULL,
    object json NOT NULL,
    "array" json NOT NULL,
    "json" json NOT NULL,
    collection json NOT NULL,
    nullable_collection json,
    date date NOT NULL,
    datetime timestamp(0) without time zone NOT NULL,
    immutable_date date NOT NULL,
    immutable_datetime timestamp(0) without time zone NOT NULL,
    "timestamp" timestamp(0) without time zone NOT NULL,
    created_at timestamp(0) without time zone,
    updated_at timestamp(0) without time zone,
    deleted_at timestamp(0) without time zone,
    CONSTRAINT users_enum_status_check CHECK (((enum_status)::text = ANY ((ARRAY['active'::character varying, 'inactive'::character varying, 'don''t know'::character varying])::text[])))
);


--
-- Name: users_id_seq; Type: SEQUENCE; Schema: public; Owner: -
--

CREATE SEQUENCE public.users_id_seq
    START WITH 1
    INCREMENT BY 1
    NO MINVALUE
    NO MAXVALUE
    CACHE 1;


--
-- Name: users_id_seq; Type: SEQUENCE OWNED BY; Schema: public; Owner: -
--

ALTER SEQUENCE public.users_id_seq OWNED BY public.users.id;


--
-- Name: uuid_models; Type: TABLE; Schema: public; Owner: -
--

CREATE TABLE public.uuid_models (
    id uuid NOT NULL,
    created_at timestamp(0) without time zone,
    updated_at timestamp(0) without time zone
);


--
-- Name: accounts id; Type: DEFAULT; Schema: public; Owner: -
--

ALTER TABLE ONLY public.accounts ALTER COLUMN id SET DEFAULT nextval('public.accounts_id_seq'::regclass);


--
-- Name: addresses id; Type: DEFAULT; Schema: public; Owner: -
--

ALTER TABLE ONLY public.addresses ALTER COLUMN id SET DEFAULT nextval('public.addresses_id_seq'::regclass);


--
-- Name: guarded_models id; Type: DEFAULT; Schema: public; Owner: -
--

ALTER TABLE ONLY public.guarded_models ALTER COLUMN id SET DEFAULT nextval('public.guarded_models_id_seq'::regclass);


--
-- Name: migrations id; Type: DEFAULT; Schema: public; Owner: -
--

ALTER TABLE ONLY public.migrations ALTER COLUMN id SET DEFAULT nextval('public.migrations_id_seq'::regclass);


--
-- Name: model_with_casts id; Type: DEFAULT; Schema: public; Owner: -
--

ALTER TABLE ONLY public.model_with_casts ALTER COLUMN id SET DEFAULT nextval('public.model_with_casts_id_seq'::regclass);


--
-- Name: posts id; Type: DEFAULT; Schema: public; Owner: -
--

ALTER TABLE ONLY public.posts ALTER COLUMN id SET DEFAULT nextval('public.posts_id_seq'::regclass);


--
-- Name: users id; Type: DEFAULT; Schema: public; Owner: -
--

ALTER TABLE ONLY public.users ALTER COLUMN id SET DEFAULT nextval('public.users_id_seq'::regclass);


--
-- Name: accounts accounts_pkey; Type: CONSTRAINT; Schema: public; Owner: -
--

ALTER TABLE ONLY public.accounts
    ADD CONSTRAINT accounts_pkey PRIMARY KEY (id);


--
-- Name: addresses addresses_pkey; Type: CONSTRAINT; Schema: public; Owner: -
--

ALTER TABLE ONLY public.addresses
    ADD CONSTRAINT addresses_pkey PRIMARY KEY (id);


--
-- Name: guarded_models guarded_models_pkey; Type: CONSTRAINT; Schema: public; Owner: -
--

ALTER TABLE ONLY public.guarded_models
    ADD CONSTRAINT guarded_models_pkey PRIMARY KEY (id);


--
-- Name: migrations migrations_pkey; Type: CONSTRAINT; Schema: public; Owner: -
--

ALTER TABLE ONLY public.migrations
    ADD CONSTRAINT migrations_pkey PRIMARY KEY (id);


--
-- Name: model_with_casts model_with_casts_pkey; Type: CONSTRAINT; Schema: public; Owner: -
--

ALTER TABLE ONLY public.model_with_casts
    ADD CONSTRAINT model_with_casts_pkey PRIMARY KEY (id);


--
-- Name: posts posts_pkey; Type: CONSTRAINT; Schema: public; Owner: -
--

ALTER TABLE ONLY public.posts
    ADD CONSTRAINT posts_pkey PRIMARY KEY (id);


--
-- Name: users users_email_unique; Type: CONSTRAINT; Schema: public; Owner: -
--

ALTER TABLE ONLY public.users
    ADD CONSTRAINT users_email_unique UNIQUE (email);


--
-- Name: users users_pkey; Type: CONSTRAINT; Schema: public; Owner: -
--

ALTER TABLE ONLY public.users
    ADD CONSTRAINT users_pkey PRIMARY KEY (id);


--
-- Name: addresses addresses_foreign_id_constrained_foreign; Type: FK CONSTRAINT; Schema: public; Owner: -
--

ALTER TABLE ONLY public.addresses
    ADD CONSTRAINT addresses_foreign_id_constrained_foreign FOREIGN KEY (foreign_id_constrained) REFERENCES public.users(id);


--
-- Name: addresses addresses_nullable_foreign_id_constrained_foreign; Type: FK CONSTRAINT; Schema: public; Owner: -
--

ALTER TABLE ONLY public.addresses
    ADD CONSTRAINT addresses_nullable_foreign_id_constrained_foreign FOREIGN KEY (nullable_foreign_id_constrained) REFERENCES public.users(id);


--
-- PostgreSQL database dump complete
--

\unrestrict i3eguSRgnSpVEZl4E6MsUaDevCdGzxGIoYXBoXv1oxVObu55wf4J2I3tAZCUgsD

--
-- PostgreSQL database dump
--

\restrict jKYvYjRCwriL8Tj5TCIHcNKbcT3suCT9HHsdg6g3auNmHcwnKJyXRc2WmnSNQa0

-- Dumped from database version 17.11 (Debian 17.11-1.pgdg12+2)
-- Dumped by pg_dump version 18.6 (Ubuntu 18.6-1.pgdg24.04+2)

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
-- Data for Name: migrations; Type: TABLE DATA; Schema: public; Owner: -
--

COPY public.migrations (id, migration, batch) FROM stdin;
1	2020_01_30_000000_create_model_with_casts_table.php	1
2	2020_01_30_000000_create_users_table	1
3	2020_03_20_000000_create_accounts_table	1
4	2020_03_25_000000_create_roles_table	1
5	2020_09_25_190200_create_guarded_models_table	1
6	2020_10_04_000000_create_posts_table	1
7	2021_09_10_000000_create_addresses_table	1
8	2023_06_12_000000_create_role_user_table	1
9	2025_02_12_000000_create_ulid_models_table	1
10	2025_02_12_000000_create_uuid_models_table	1
\.


--
-- Name: migrations_id_seq; Type: SEQUENCE SET; Schema: public; Owner: -
--

SELECT pg_catalog.setval('public.migrations_id_seq', 10, true);


--
-- PostgreSQL database dump complete
--

\unrestrict jKYvYjRCwriL8Tj5TCIHcNKbcT3suCT9HHsdg6g3auNmHcwnKJyXRc2WmnSNQa0

