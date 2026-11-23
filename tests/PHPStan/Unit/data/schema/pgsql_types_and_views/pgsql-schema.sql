CREATE TYPE public.status AS ENUM (
    'active',
    'inactive'
);

CREATE TYPE public.level AS ENUM (
    'low',
    'high'
);

CREATE DOMAIN public.code AS text;

CREATE TABLE public.accounts (
    id bigint NOT NULL,
    status public.status NOT NULL
);

CREATE VIEW public.active_accounts AS
 SELECT accounts.id
   FROM public.accounts
  WHERE (accounts.status = 'active'::public.status);
