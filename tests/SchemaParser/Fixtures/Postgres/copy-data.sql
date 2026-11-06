--
-- PostgreSQL database dump
--

\restrict T3pqG3dmuKw7CHTgg1Ym8uj9T1wnwVDna3omxJLN1KVTdmNg3fhRVr0Pm6Gu43C

-- Dumped from database version 17.11 (Debian 17.11-1.pgdg12+2)
-- Dumped by pg_dump version 17.11 (Debian 17.11-1.pgdg12+2)

SET statement_timeout = 0;
SET lock_timeout = 0;
SET idle_in_transaction_session_timeout = 0;
SET transaction_timeout = 0;
SET client_encoding = 'UTF8';
SET standard_conforming_strings = off;
SELECT pg_catalog.set_config('search_path', '', false);
SET check_function_bodies = false;
SET xmloption = content;
SET client_min_messages = warning;
SET escape_string_warning = off;
SET row_security = off;

--
-- Name: parser_literal_probe; Type: TYPE; Schema: public; Owner: -
--

CREATE TYPE public.parser_literal_probe AS ENUM (
    'c\\''d'
);


SET default_tablespace = '';

SET default_table_access_method = heap;

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
-- Name: parser_copy_probe; Type: TABLE; Schema: public; Owner: -
--

CREATE TABLE public.parser_copy_probe (
    migration text,
    label public.parser_literal_probe
);


--
-- Name: TABLE parser_copy_probe; Type: COMMENT; Schema: public; Owner: -
--

COMMENT ON TABLE public.parser_copy_probe IS 'c\\''d';


--
-- Name: migrations id; Type: DEFAULT; Schema: public; Owner: -
--

ALTER TABLE ONLY public.migrations ALTER COLUMN id SET DEFAULT nextval('public.migrations_id_seq'::regclass);


--
-- Data for Name: migrations; Type: TABLE DATA; Schema: public; Owner: -
--

COPY public.migrations (id, migration, batch) FROM stdin;
\.


--
-- Data for Name: parser_copy_probe; Type: TABLE DATA; Schema: public; Owner: -
--

COPY public.parser_copy_probe (migration, label) FROM stdin;
add_o'brien_flag; -- $x$ /*	\N
\N	c\\'d
\.


--
-- Name: migrations_id_seq; Type: SEQUENCE SET; Schema: public; Owner: -
--

SELECT pg_catalog.setval('public.migrations_id_seq', 1, false);


--
-- Name: migrations migrations_pkey; Type: CONSTRAINT; Schema: public; Owner: -
--

ALTER TABLE ONLY public.migrations
    ADD CONSTRAINT migrations_pkey PRIMARY KEY (id);


--
-- Name: parser_copy_index; Type: INDEX; Schema: public; Owner: -
--

CREATE INDEX parser_copy_index ON public.parser_copy_probe USING btree (migration);


--
-- PostgreSQL database dump complete
--

\unrestrict T3pqG3dmuKw7CHTgg1Ym8uj9T1wnwVDna3omxJLN1KVTdmNg3fhRVr0Pm6Gu43C

