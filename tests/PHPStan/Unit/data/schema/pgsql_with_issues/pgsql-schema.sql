CREATE TYPE public.status AS ENUM (
    'active',
    'inactive'
);

CREATE TABLE public.accounts (
    id bigint NOT NULL,
    status public.status NOT NULL
);

ALTER DOMAIN public.missing SET NOT NULL;
