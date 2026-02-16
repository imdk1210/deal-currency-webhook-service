DO $$
BEGIN
    IF EXISTS (
        SELECT 1
        FROM public.deals
        GROUP BY bitrix_deal_id
        HAVING COUNT(*) > 1
    ) THEN
        RAISE EXCEPTION 'Cannot add unique_bitrix_deal: duplicate bitrix_deal_id values exist.';
    END IF;

    IF NOT EXISTS (
        SELECT 1
        FROM pg_constraint
        WHERE conname = 'unique_bitrix_deal'
          AND conrelid = 'public.deals'::regclass
    ) THEN
        ALTER TABLE public.deals
            ADD CONSTRAINT unique_bitrix_deal UNIQUE (bitrix_deal_id);
    END IF;
END
$$;
