ALTER TABLE public.deals
    ADD COLUMN IF NOT EXISTS source_updated_at TIMESTAMPTZ;

UPDATE public.deals
SET source_updated_at = COALESCE(source_updated_at, created_at::timestamptz, NOW())
WHERE source_updated_at IS NULL;

ALTER TABLE public.deals
    ALTER COLUMN source_updated_at SET DEFAULT NOW();

ALTER TABLE public.deals
    ALTER COLUMN source_updated_at SET NOT NULL;

CREATE INDEX IF NOT EXISTS idx_deals_source_updated_at
    ON public.deals (source_updated_at);
