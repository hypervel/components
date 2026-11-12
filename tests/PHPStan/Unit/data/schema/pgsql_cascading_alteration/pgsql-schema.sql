CREATE TABLE public.users (
    id integer NOT NULL,
    obsolete text NOT NULL
);

CREATE TABLE public.posts (
    id integer NOT NULL
);

ALTER TABLE public.users DROP COLUMN obsolete CASCADE;
