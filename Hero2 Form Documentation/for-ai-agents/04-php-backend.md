# 04 — The PHP Backend (the lead endpoint)

Reference implementation: [`code/ampy-lead-endpoint.php`](../code/ampy-lead-endpoint.php)
Schema migration: [`code/ampy-dash-2-migration.sql`](../code/ampy-dash-2-migration.sql)

This is the **only piece that does not exist yet**. The frontend component
(`bricks/ampy-offert-form.html`) already builds and POSTs a complete JSON
payload, but as of this handover there is no route that receives it. **Until
this endpoint is live and writing rows, every lead is dropped** — the same
failure mode the team hit once before with a null webhook. Building this is the
critical path to go-live.

> **Prime directive: NEVER DROP A CALLABLE LEAD.**
> A callable lead = `full_name` + `phone` + `postal_code` + `consent`. If those
> four are present and the honeypot is empty, the lead MUST be persisted.
> Everything else — missing org.nr, a non-Swedish phone, an unknown enum value —
> is **persisted and flagged for a human**, never rejected. The only refusals
> are: missing callable-minimum, missing consent, rate-limit throttle, and a
> tripped bot honeypot.

---

## 1. What the endpoint does (request lifecycle)

The route is `POST /wp-json/ampy/v1/lead` (matches `ENDPOINT` at the top of the
frontend script). One request runs through these stages, in order:

1. **Parse JSON** — the form sends `Content-Type: application/json`. A
   non-JSON body returns `400 bad_request`.
2. **Whitelist** — only the exactly 19 known keys from `buildPayload()` are kept;
   any extra key is dropped. The whitelist in PHP (`aof_field_whitelist()`) is the
   mirror of `buildPayload()` in the frontend — keep them in lockstep.
3. **Honeypot** — if `company_url` is non-empty, return `200 OK` with a **fake**
   `lead_id` and write **nothing**. The bot sees success and stops; no row is
   created. (The field is hidden off-screen in the form; a human never fills it.)
4. **Rate-limit by IP — CHECK only** — counts **successful leads, not attempts**
   (incremented after the write), so a fat-finger or a shared NAT/CDN egress IP
   never locks anyone out. **Disabled by default** (`AOF_RL_IP_ENABLED=false`)
   until `aof_client_ip()` is wired to the real visitor IP — behind a CDN
   `REMOTE_ADDR` is the shared edge IP and a per-IP cap would throttle ALL
   traffic. Over the limit returns `429`.
5. **Sanitise everything** — `sanitize_text_field` / `sanitize_textarea_field` /
   `sanitize_email`; phone re-normalised to E.164; postal code and org.nr
   reduced to digits.
6. **Consent gate** — `consent` must be strict-ish boolean true, else `422
   consent_required`. GDPR: no consent, no storage.
7. **Callable-minimum gate** — `full_name` (≥2 chars) + a phone matching generic
   E.164 `^\+\d{8,15}$` + a 5-digit `postal_code`. Missing any → `422
   missing_required` with a `fields` map. **This is the only content-based
   rejection.**
8. **Rate-limit by phone — CHECK only** — second counter keyed on the normalised
   phone, also counting **successful leads** (incremented after the write). Checked
   after the callable-minimum, so a stuck retry that keeps failing validation never
   locks the number out; `429` only after N persisted leads from one number.
9. **Build review flags** — persist-and-flag conditions (see §4).
10. **Stamp `consent_at`** — `current_time('mysql', true)` (UTC). The server is
    the legal record of *when* consent was given; the client never sends this.
11. **Two-row write** — UPSERT `customers` (dedupe on `phone_e164`) → get
    `customer_id` → INSERT `deals` with the FK. Returns `lead_id`.
12. **Respond** — `201 { ok:true, lead_id }` **only after a successful deal
    write**. The frontend treats any non-2xx as an error and shows the "ring
    oss" fallback, so a 2xx always means a persisted, callable lead.

---

## 2. Why these rules (grounding in the frontend)

The endpoint must consume **exactly** what `buildPayload()` produces
(`bricks/ampy-offert-form.html`, ~line 287). Key facts the PHP relies on:

- **`full_name`** is the contact name in both cases: privat sends the person's
  name (`val('namn')`), org sends the contact person (`val('kontakt')`). There
  is no separate contact-person column — `full_name` carries it.
- **`phone_e164`** is already run through the client `toE164()` (no leading 0 →
  `+46…`). The PHP re-runs the same normalisation defensively — never trust the
  client. A non-`+46` number is **accepted and flagged** (`phone_non_se`), not
  rejected, so an international customer is not lost.
- **`postal_code`** arrives as digits (the client does `.replace(/\D/g,'')`),
  but the PHP strips non-digits again before the 5-digit check.
- **`org_number`** is `digits or null`. The form keeps it OPTIONAL in the "Fler
  detaljer" disclosure — it never blocks submit. On `brf`/`foretag` leads a
  missing/malformed org.nr is flagged `orgnr_missing_or_malformed` (see §5).
- **`consent`** is always `true` when sent (the form blocks submit until the box
  is checked) and **`consent_at` is NOT in the payload** — the server stamps it.
- **`policy_version`** is sent by the client but treated as **advisory**: the
  endpoint persists its OWN `AOF_POLICY_VERSION` and logs a mismatch.
- **`bilder_count`** is only a count — the image *files* travel on a separate
  multipart channel keyed by `lead_id` (see §7), not in this JSON.
- **`source_form`** is currently `3` and still `[VERIFY]` with the dash team
  (FUNKTIONALITET.md §9.8). The PHP validates it against an allowlist and
  defaults + flags an unknown value rather than trusting it.

---

## 3. How to install it (recommendation)

Three options; **recommended is a mu-plugin.**

| Option | When | Trade-off |
|---|---|---|
| **mu-plugin** (recommended) | `wp-content/mu-plugins/ampy-lead-endpoint.php` | Always loaded, can't be deactivated by accident, survives theme switches. Cleanest separation from the Bricks/theme layer. |
| `functions.php` | Quick test on staging | Tied to the active theme; lost on theme switch; clutters the theme. Fine to prototype, not to ship. |
| **Supabase edge function** | If you'd rather keep ALL CRM logic in `ampy-dash-2` and not in WP | Then the frontend `ENDPOINT` points at the Supabase function URL instead of `/wp-json/...`, and this PHP becomes unnecessary. The two-row write logic is identical; port the validation + honeypot + rate-limit into the edge function. |

**Recommended path:** mu-plugin in WP, writing to `ampy-dash-2`. Set
`AOF_DB_MODE` in the PHP:
- `'wpdb'` if the `customers`/`deals` tables live in the same WP/MySQL database
  (uses `$wpdb` prepared statements — the default reference path).
- `'supabase'` if `ampy-dash-2` is a separate Supabase/Postgres project. Then
  implement the two `aof_supabase_*` stubs — ideally as a single atomic Postgres
  RPC (`ampy_create_lead`, sketched in the migration) so the whole two-row write
  + `lead_id` return happens in one round trip. **The stubs return `false` on
  purpose** so a half-configured Supabase mode fails loudly instead of silently
  dropping leads.

After installing, run the migration (§ below) and confirm the four `TODO dev`
constants at the top of the **frontend** script are set: real `ENDPOINT`,
confirmed `SOURCE_FORM`, `PREVIEW=false` (or rely on the host-derive),
confirmed `POLICY_VERSION`.

### Run the migration
Apply [`code/ampy-dash-2-migration.sql`](../code/ampy-dash-2-migration.sql)
against `ampy-dash-2`. It is **idempotent** (`IF NOT EXISTS`) and has two
sections — run **only** the one matching your engine (Postgres/Supabase = §A,
MySQL = §B). It adds `consent_at`, `policy_version`, `bilder_count`,
`review_flags` to `deals`; adds `org_name` and makes `org_number` nullable on
`customers`; and creates the unique index on `customers.phone_e164` that backs
the dedupe UPSERT. **Verify table/column names against the live schema first** —
they are all marked `[VERIFY]`.

---

## 4. Security model

- **Honeypot (primary bot filter).** `company_url` is a hidden field. Non-empty
  ⇒ silent fake-success, no write. Bots can't tell they failed.
- **Rate-limit (defence in depth).** Per-IP and per-phone WP transients return
  `429` on bursts. Both count **successful leads, not attempts** (incremented
  only after a persisted write), so failed tries never lock out a real user.
  The **per-IP limiter is OFF by default** (`AOF_RL_IP_ENABLED=false`): behind a
  CDN, `REMOTE_ADDR` is the shared edge IP, so a per-IP cap would throttle ALL
  traffic. Enable it only after wiring `aof_client_ip()` to the *trusted*
  forwarded header — do **not** blindly trust `X-Forwarded-For` (spoofable). The
  per-phone limiter is always safe (phone is per-person) and stays on.
- **No nonce, by design.** The component renders on ~165 pages that are
  page-cached; a per-request nonce would be stale and break submits. The
  endpoint is public (`permission_callback => __return_true`) and defended by
  the honeypot + rate-limit + sanitisation + callable-minimum instead. If you
  later add a nonce, it must be injected fresh per page-load (not cached).
- **Input sanitisation.** Every free-text field goes through
  `sanitize_text_field` / `sanitize_textarea_field`; identifiers are reduced to
  digits; enums are checked against allowlists. The **whitelist** means unknown
  keys can't sneak into the DB.
- **Output escaping (reminder for the booker view).** Sanitising on the way IN
  is not enough. **Whoever renders the leads to the booker MUST escape on the
  way OUT** — `esc_html()` for any stored string shown in WP admin, equivalent
  escaping in the dash UI. Never echo `beskrivning`, `full_name`, etc. as raw
  HTML. Stored data is **not** trusted-safe HTML.
- **Prepared statements.** All `$wpdb` access uses `$wpdb->prepare()` /
  `$wpdb->insert()` (which prepares each value). No string-concatenated SQL.
- **Secrets.** In Supabase mode, the service-role key is read from an env var /
  `wp-config` constant, never hardcoded in a theme file.

---

## 5. The org.nr decision (owner-flippable)

`org_number` is **kept OPTIONAL** and **nullable**. Rationale: the prime
directive — a company that fills name + phone + postnr + consent is a callable
lead, and dropping it to enforce an org.nr field would violate the moat. So the
endpoint **persists and flags** instead:

- `brf`/`foretag` lead with null/malformed org.nr → flag
  `orgnr_missing_or_malformed`; the booker chases it.
- Missing `org_name` on an org lead → flag `orgname_missing`.

**To make org.nr MANDATORY later** (if the owner flips this), there is exactly
**one** change: uncomment the `[ORG_NR_POLICY]` block in
`code/ampy-lead-endpoint.php` (it returns `422 orgnr_required`). That is the
only edit — documented inline at the marker.

Other flags the endpoint raises (all persist-and-flag, never reject):
`kundtyp_unknown`, `vertical_unknown`, `bradska_unknown`, `phone_non_se`,
`email_missing`, `address_missing_privat`, `source_form_unknown`,
`policy_version_mismatch`, and `customer_upsert_failed` (the customer write
failed but we still wrote the deal so the lead is recoverable). They land in
`deals.review_flags` (JSON) for the booker queue.

---

## 6. Consent / GDPR handling

- The form posts `consent:true` + an advisory `policy_version`. **It does not
  post `consent_at`.**
- The endpoint **rejects** a lead without affirmative consent (`422
  consent_required`) — no consent, no storage.
- On accept, the endpoint **server-stamps** `consent_at = current_time('mysql',
  true)` (UTC) and persists **its own** `AOF_POLICY_VERSION`. The client's
  version is logged-on-mismatch only — the server's published version is the
  record of what the user actually agreed to.
- Confirm the live **integritetspolicy URL** (the form links
  `https://ampy.se/integritetspolicy`) and that `AOF_POLICY_VERSION` matches the
  currently-published policy. Both are `[VERIFY]`.

---

## 7. `lead_id` → enrichment PATCH + future image upload

The success response returns `lead_id`. This unlocks two follow-on flows:

- **Optional enrichment PATCH.** After the minimal callable lead is saved, any
  later-supplied detail (description, timeframe, etc.) can update the **same**
  deal row by `lead_id` rather than creating a duplicate. (The current frontend
  submits everything in one POST, but the contract is designed so a future
  "save early, enrich later" flow PATCHes the same row.) If you build it, add a
  `PATCH /wp-json/ampy/v1/lead/{id}` route that whitelists only the
  enrichment-safe columns and never re-opens the callable-minimum or consent.
- **Future multipart image upload.** Images are **not** in the JSON — only
  `bilder_count` signals how many the user attached. The real files will travel
  on a separate `multipart/form-data` channel keyed by `lead_id` (e.g. `POST
  /wp-json/ampy/v1/lead/{id}/images`). Until that channel exists, `bilder_count`
  is the only signal a booker has that photos were offered, so they can ask for
  them by phone. **Validate file type/size + scan on that route when built** —
  it is a separate attack surface from this JSON endpoint.

---

## 8. Test plan (curl)

Point `$BASE` at staging. The frontend only switches to live POST when
`PREVIEW=false` (host-derived: `ampy.se` is always live; `file://`,
`localhost`, `*.github.io` are preview). So test the endpoint **directly** with
curl, independent of the form. Expected statuses are in each comment.

```bash
BASE="https://staging.ampy.se"   # [VERIFY] your staging host
LEAD="$BASE/wp-json/ampy/v1/lead"

# 1) VALID PRIVAT LEAD  -> 201 { ok:true, lead_id }
curl -i -X POST "$LEAD" -H 'Content-Type: application/json' -d '{
  "full_name":"Anna Andersson","phone_e164":"+46701234567","postal_code":"11122",
  "email":"anna@example.se","org_number":null,"org_name":null,
  "kundtyp":"privat","vertical":"laddbox","tjanst_intresse":"Laddbox",
  "bradska":"72h","beskrivning":"Vill installera laddbox i garaget.",
  "street_address":"Storgatan 1","bilder_count":0,"kallsida":"/laddbox/nacka",
  "source":"bricks","source_form":3,"consent":true,
  "policy_version":"ampy-privacy-2026-06","company_url":""
}'

# 2) VALID ORG LEAD (no org.nr -> persisted + flagged, NOT rejected) -> 201
curl -i -X POST "$LEAD" -H 'Content-Type: application/json' -d '{
  "full_name":"Erik Eriksson (kontakt)","phone_e164":"+46812345678",
  "postal_code":"16440","email":"info@brf-solen.se",
  "org_number":null,"org_name":"BRF Solen","kundtyp":"brf",
  "vertical":"foretag_brf","tjanst_intresse":"Laddbox","bradska":null,
  "beskrivning":null,"street_address":null,"bilder_count":2,
  "kallsida":"/bostadsrattsforening","source":"bricks","source_form":3,
  "consent":true,"policy_version":"ampy-privacy-2026-06","company_url":""
}'
# -> deal row has review_flags including "orgnr_missing_or_malformed"

# 3) HONEYPOT FILLED -> 200 { ok:true, lead_id:"lead_..." } but NO row written
curl -i -X POST "$LEAD" -H 'Content-Type: application/json' -d '{
  "full_name":"Bot Botsson","phone_e164":"+46700000000","postal_code":"11122",
  "kundtyp":"privat","vertical":"oklart","consent":true,
  "policy_version":"ampy-privacy-2026-06","company_url":"http://spam.example"
}'
# -> verify the DB did NOT gain a row for +46700000000

# 4) MISSING PHONE -> 422 { ok:false, code:"missing_required", fields:{...} }
curl -i -X POST "$LEAD" -H 'Content-Type: application/json' -d '{
  "full_name":"Ingen Telefon","phone_e164":"","postal_code":"11122",
  "kundtyp":"privat","vertical":"oklart","consent":true,
  "policy_version":"ampy-privacy-2026-06","company_url":""
}'

# 4b) MISSING CONSENT -> 422 { code:"consent_required" }
curl -i -X POST "$LEAD" -H 'Content-Type: application/json' -d '{
  "full_name":"Utan Samtycke","phone_e164":"+46701234567","postal_code":"11122",
  "kundtyp":"privat","vertical":"oklart","consent":false,
  "policy_version":"ampy-privacy-2026-06","company_url":""
}'

# 5) THROTTLE -> first N return 201, then 429 { code:"rate_limited" }
for i in $(seq 1 7); do
  curl -s -o /dev/null -w "%{http_code}\n" -X POST "$LEAD" \
    -H 'Content-Type: application/json' -d '{
    "full_name":"Repeat Caller","phone_e164":"+46709999999","postal_code":"11122",
    "kundtyp":"privat","vertical":"oklart","consent":true,
    "policy_version":"ampy-privacy-2026-06","company_url":""
  }'
done
# -> per-phone limit (AOF_RL_PHONE_MAX=5) trips 429 after the 5th in the window
```

**Acceptance:** test 1 returns `201` and a real row pair exists; test 2 returns
`201` with the org.nr flag set (NOT a 4xx); test 3 returns `200` and **no** row
exists; tests 4/4b return `422` with the right `code`; test 5 starts `201` then
flips to `429`. Also confirm `deals.consent_at` is populated (server UTC) and
`deals.policy_version` equals the server's value even when the client sends a
different one.

---

## 9. Outstanding `[VERIFY]` before go-live

- Real `ampy-dash-2` table + column names (`customers`, `deals`, and whether the
  source-page column is `kallsida` or `lead_magnet_slug` — FUNKTIONALITET.md §7
  says the form reuses `lead_magnet_slug`).
- DB mode: `$wpdb`/MySQL vs Supabase/Postgres (sets which migration section runs
  and whether the `aof_supabase_*` stubs need implementing).
- Confirmed `source_form` code (currently `3`; allowlist `1,3`).
- Published `policy_version` + exact integritetspolicy URL.
- CDN/proxy → correct client-IP source for rate-limiting.
- Existing duplicate `phone_e164` rows must be deduped before the UNIQUE index
  is created (else the migration errors).
