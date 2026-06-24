-- ===========================================================================
-- AMPY-DASH-2 MIGRATION — Hero 2 offertformulär lead endpoint
-- ===========================================================================
-- Adds the columns + indexes the lead endpoint (code/ampy-lead-endpoint.php)
-- needs to perform the two-row write, GDPR consent storage, dedupe UPSERT, and
-- the booker review queue.
--
-- IDEMPOTENT: every statement uses IF NOT EXISTS so it can be re-run safely.
--
-- TWO DIALECTS are provided because ampy-dash-2's engine is not confirmed here:
--   • SECTION A = PostgreSQL / Supabase  (FUNKTIONALITET.md §8 describes the CRM
--                 as a Supabase project — this is the LIKELY target). [VERIFY]
--   • SECTION B = MySQL / MariaDB        (use this if the endpoint runs against
--                 the same WP/MySQL DB via $wpdb).
-- Run ONLY the section matching your engine. Delete the other.
--
-- [VERIFY] EVERY table name (customers, deals) and the exact existing column
-- names against the live ampy-dash-2 schema before running. Names below follow
-- FUNKTIONALITET.md §7 and the payload contract.
-- ===========================================================================


-- ###########################################################################
-- SECTION A — PostgreSQL / Supabase  ([VERIFY] likely target)
-- ###########################################################################

-- --- deals: GDPR consent timestamp + policy version --------------------------
-- consent_at is STAMPED BY THE SERVER (current_time UTC), never by the client.
ALTER TABLE deals
    ADD COLUMN IF NOT EXISTS consent_at      timestamptz NULL;   -- [VERIFY] table name
ALTER TABLE deals
    ADD COLUMN IF NOT EXISTS policy_version  text        NULL;   -- server's published version
-- kallsida = the full request path the lead came from (resolver source / attribution).
-- [VERIFY] If the live `deals` table already records this as `lead_magnet_slug`
-- (FUNKTIONALITET.md §7 reuses that column), do NOT add this column — instead
-- rename the insert key in aof_insert_deal() from 'kallsida' to 'lead_magnet_slug'.
-- One of the two MUST be true or every deal INSERT 500s on an unknown column.
ALTER TABLE deals
    ADD COLUMN IF NOT EXISTS kallsida        text        NULL;   -- [VERIFY] vs lead_magnet_slug
ALTER TABLE deals
    ADD COLUMN IF NOT EXISTS bilder_count    integer     NOT NULL DEFAULT 0;
-- review_flags: persist-and-flag queue for the booker (orgnr missing, non-SE
-- phone, unknown enum, etc.). jsonb so it can be queried/filtered.
ALTER TABLE deals
    ADD COLUMN IF NOT EXISTS review_flags    jsonb       NOT NULL DEFAULT '[]'::jsonb;

-- --- customers: org_name + ensure org_number is NULLABLE ---------------------
ALTER TABLE customers
    ADD COLUMN IF NOT EXISTS org_name        text        NULL;   -- [VERIFY] table name
-- org_number MUST be nullable (privat leads have none; company leads may omit
-- it and are FLAGGED, not rejected — never drop a callable lead).
ALTER TABLE customers
    ALTER COLUMN org_number DROP NOT NULL;                       -- [VERIFY] column exists

-- --- index for the dedupe UPSERT on phone_e164 -------------------------------
-- A UNIQUE index makes phone_e164 the dedupe key AND lets the endpoint use an
-- atomic UPSERT (ON CONFLICT) if you move the two-row write into an RPC.
-- NOTE: if duplicate phone_e164 rows already exist, dedupe them BEFORE creating
-- the unique index or it will error. Use a NON-unique index instead if dupes
-- are expected and dedupe is done in app code.
CREATE UNIQUE INDEX IF NOT EXISTS idx_customers_phone_e164
    ON customers (phone_e164);                                   -- [VERIFY]

-- --- index for the review queue ---------------------------------------------
-- Lets the booker view filter "deals needing follow-up" cheaply.
CREATE INDEX IF NOT EXISTS idx_deals_review_flags
    ON deals USING gin (review_flags);

-- --- (optional) atomic two-row write as an RPC ------------------------------
-- If you set AOF_DB_MODE='supabase' in the endpoint, this function lets the PHP
-- do the whole write in ONE round trip and return lead_id. [VERIFY] arg names
-- must match aof_supabase_insert_deal()'s payload keys; column names must match
-- the live schema. Left commented — uncomment + adapt if you go the RPC route.
--
-- CREATE OR REPLACE FUNCTION ampy_create_lead(
--     full_name text, phone_e164 text, email text, street_address text,
--     postal_code text, org_number text, org_name text,
--     vertical text, kundtyp text, tjanst_intresse text, bradska text,
--     beskrivning text, kallsida text, source text, source_form int,
--     consent_at timestamptz, policy_version text, bilder_count int,
--     review_flags jsonb
-- ) RETURNS bigint LANGUAGE plpgsql AS $$
-- DECLARE cid bigint; did bigint;
-- BEGIN
--     INSERT INTO customers (full_name, phone_e164, email, street_address,
--                            postal_code, org_number, org_name)
--     VALUES (full_name, phone_e164, NULLIF(email,''), NULLIF(street_address,''),
--             postal_code, NULLIF(org_number,''), NULLIF(org_name,''))
--     ON CONFLICT (phone_e164) DO UPDATE SET
--         full_name      = EXCLUDED.full_name,
--         email          = COALESCE(EXCLUDED.email, customers.email),
--         street_address = COALESCE(EXCLUDED.street_address, customers.street_address),
--         postal_code    = COALESCE(EXCLUDED.postal_code, customers.postal_code),
--         org_number     = COALESCE(EXCLUDED.org_number, customers.org_number),
--         org_name       = COALESCE(EXCLUDED.org_name, customers.org_name)
--     RETURNING id INTO cid;
--
--     INSERT INTO deals (customer_id, vertical, kundtyp, tjanst_intresse, bradska,
--                        beskrivning, kallsida, source, source_form, consent_at,
--                        policy_version, bilder_count, review_flags)
--     VALUES (cid, vertical, kundtyp, NULLIF(tjanst_intresse,''), NULLIF(bradska,''),
--             NULLIF(beskrivning,''), kallsida, source, source_form, consent_at,
--             policy_version, bilder_count, review_flags)
--     RETURNING id INTO did;
--     RETURN did;
-- END $$;


-- ###########################################################################
-- SECTION B — MySQL / MariaDB  (use if the endpoint writes via $wpdb)
-- ###########################################################################
-- MySQL note: ADD COLUMN IF NOT EXISTS is supported on MariaDB 10.0+ and
-- MySQL 8.0.29+. On older MySQL, wrap each ALTER in a stored procedure that
-- checks information_schema, or run them once manually. Table names below
-- assume NO WP prefix; if these live in WP tables, prefix them (e.g. wp_deals).
-- [VERIFY] table + column names.
--
-- ALTER TABLE deals
--     ADD COLUMN IF NOT EXISTS consent_at     DATETIME NULL,
--     ADD COLUMN IF NOT EXISTS policy_version VARCHAR(64) NULL,
--     ADD COLUMN IF NOT EXISTS kallsida       TEXT NULL,        -- [VERIFY] vs lead_magnet_slug (see Section A note)
--     ADD COLUMN IF NOT EXISTS bilder_count   INT NOT NULL DEFAULT 0,
--     ADD COLUMN IF NOT EXISTS review_flags   JSON NULL;   -- TEXT on MySQL < 5.7
--
-- ALTER TABLE customers
--     ADD COLUMN IF NOT EXISTS org_name VARCHAR(255) NULL,
--     MODIFY COLUMN org_number VARCHAR(20) NULL;           -- make nullable [VERIFY] type
--
-- -- Dedupe key for the phone_e164 UPSERT. Dedupe existing rows first if needed.
-- CREATE UNIQUE INDEX idx_customers_phone_e164 ON customers (phone_e164);
--
-- -- Review-queue lookups (JSON GIN-equivalent is a generated column on MySQL;
-- -- a plain index on a flag-count column is simpler if you add one). [VERIFY]
-- ===========================================================================
