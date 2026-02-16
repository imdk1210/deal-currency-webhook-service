CREATE TABLE IF NOT EXISTS public.deals (
    id BIGSERIAL PRIMARY KEY,
    bitrix_deal_id BIGINT NOT NULL,
    amount_rub NUMERIC(18, 2) NOT NULL,
    amount_usd NUMERIC(18, 2),
    exchange_rate NUMERIC(18, 8),
    status VARCHAR(64) NOT NULL DEFAULT 'received',
    source_updated_at TIMESTAMPTZ NULL,
    created_at TIMESTAMPTZ NOT NULL DEFAULT NOW()
);

CREATE INDEX IF NOT EXISTS idx_deals_bitrix_deal_id
    ON public.deals (bitrix_deal_id);
