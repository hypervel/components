CREATE TYPE public.status AS ENUM (
    'active',
    'inactive',
    'archived'
);

CREATE TYPE public.level AS ENUM (
    'low',
    'high'
);

CREATE DOMAIN public.code AS integer;

CREATE TABLE public.events (
    status public.status NOT NULL,
    level public.level NOT NULL,
    code public.code NOT NULL
);
