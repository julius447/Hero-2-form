# Ampy Hero 2 Offertformulär — Go-Live Checklist

This is the actionable pre-launch checklist for the Ampy quote form (`ampy-offert-form.html`). It is one
self-contained component (HTML + scoped CSS + one vanilla-JS IIFE) that renders ~165 lead forms from a
**single** sitewide Bricks Code element, resolving its per-page shape from the URL path.

**How to use this doc:** work top to bottom. Every item is a checkbox with an **OWNER** and a one-line
**ACCEPTANCE CRITERION** ("done when…"). Do not flip an item to done until its acceptance criterion is
literally true. Section (A) BACKEND is the critical path — **without the endpoint, every lead on every page
is silently dropped** (the same failure that hit Elcentral-kollen's null webhook). Do not start the batch
rollout in (E) until (A) through (D) are green.

Owner legend: **BE** = backend agent/dev · **FE** = frontend/Bricks dev (Chris) · **DASH** = ampy-dash-2
data/CRM owner · **OWNER** = Ampy (Julius) for business/policy decisions.

Anything marked `[VERIFY]` or `[GAP]` is unconfirmed from the code and must be resolved, not guessed.

---

## (A) BACKEND — must exist before go-live (critical path)

The form POSTs JSON to `ENDPOINT` (default `/wp-json/ampy/v1/lead`, line 138). On a non-2xx or network
error it shows the "Något gick fel" error card — so a missing or broken endpoint is visible to the visitor,
but a *silently-accepting* endpoint that doesn't write both rows would drop leads invisibly. Build and prove
the endpoint before anything else.

The contract the PHP must consume is the exact JSON from `buildPayload()` (line 287-289). Reference fields:
`full_name, phone_e164, postal_code, email, org_number, org_name, kundtyp, vertical, tjanst_intresse,
bradska, beskrivning, street_address, bilder_count, kallsida, source, source_form, consent, policy_version,
company_url`. See `05-data-contract.md` for the full field-by-field mapping.

- [ ] **A1. Build the lead endpoint** (`POST /wp-json/ampy/v1/lead`). — **BE**
  *Done when:* a `Content-Type: application/json` POST of a valid payload returns **2xx only after both DB
  rows are written**, and any write failure returns a non-2xx (the form's `if(!r.ok)throw` on line 300 then
  correctly shows the error card instead of a false "Tack").

- [ ] **A2. Two-row write to ampy-dash-2** (`customers` + `deals`). — **BE**
  *Done when:* one submit creates/updates a `customers` row (`full_name, phone_e164, email,
  street_address, postal_code, org_number, org_name`) **deduped/UPSERTed on `phone_e164`**, and inserts a
  `deals` row (`vertical, kundtyp, tjanst_intresse, bradska, beskrivning, kallsida, source, source_form,
  consent_at, policy_version` + FK to the customer) — verified by querying both tables after a test lead.

- [ ] **A3. Return `lead_id`** from the deals insert. — **BE**
  *Done when:* the 2xx response body includes the new `lead_id` (needed later for the optional enrichment
  PATCH and for the separate multipart image channel keyed by `lead_id`).

- [ ] **A4. Add `consent_at` + `org_name` columns** (DB migration). — **BE**
  *Done when:* `ampy-dash-2` has a `deals.consent_at` (server-stamped timestamp) and a `customers.org_name`
  column, migration applied on staging; **the form never sends `consent_at`** — it sends `consent:true` +
  `policy_version` and the server stamps the timestamp itself (GDPR record of consent).

- [ ] **A5. Server validation + sanitisation** (do NOT trust the client). — **BE**
  *Done when:* the endpoint re-validates and normalises server-side: `phone_e164` → `+46…` (the client's
  `toE164` on line 149 is a convenience, not a guarantee), `postal_code` → digits only, `email` shape,
  `kundtyp ∈ {privat,brf,foretag}`, `vertical ∈ {service,laddbox,batteri,foretag_brf,oklart}`,
  `bradska ∈ {24h,72h,1_2v,flexibel}` or null, and all free-text (`beskrivning`, `tjanst_intresse`,
  `org_name`, `street_address`, `full_name`) is stored escaped/sanitised. Reject (4xx) on hard violations.

- [ ] **A6. Honeypot reject** (`company_url`). — **BE**
  *Done when:* any request with a **non-empty `company_url`** is rejected/dropped server-side (the client
  also bails on line 293, but the server must enforce it independently — bots skip the client).

- [ ] **A7. Rate-limit + dedupe / idempotency.** — **BE**
  *Done when:* repeated POSTs from one phone are throttled, AND a duplicate submit (same `phone_e164` within a
  short window) does not create a second `deals` row storm — the `customers` UPSERT on `phone_e164` (A2)
  handles person-dedupe; add idempotency so a double-tap or retry does not double-book the deal.
  *Note:* both limiters count **successful leads, not attempts**, so a fat-finger never locks a user out. The
  client has a 10s fetch timeout + `st.sending` guard but no retry, so the main idempotency risk is the user
  pressing again after a timeout.

- [ ] **A7b. Confirm the client-IP source BEFORE enabling the per-IP limiter.** — **BE** ⚠
  *Done when:* you have decided one of: (i) leave `AOF_RL_IP_ENABLED=false` (default) and rely on the per-phone
  limiter + honeypot — safe behind any CDN; or (ii) wire `aof_client_ip()` to the **trusted** forwarded header
  your edge sets, verify it returns the real visitor IP (not the CDN edge IP), THEN set `AOF_RL_IP_ENABLED=true`.
  *Why blocking:* if you enable the per-IP limiter while `aof_client_ip()` returns the shared CDN edge IP, the
  20-leads/10-min cap is hit globally and **every** visitor gets a `429` — a sitewide lead outage. When unsure,
  leave it off.

- [ ] **A8. Multipart image upload channel** (separate from this JSON). — **BE**
  *Done when:* there is a multipart endpoint keyed by `lead_id` that attaches uploaded images to the deal.
  Until it exists, **`bilder_count` (a number) is the only image signal in the JSON** — images are NOT in
  this payload. Acceptance: a lead with images recorded against the correct `lead_id`, or — if deferred —
  `bilder_count` confirmed flowing to the booker so they know to ask for photos.

- [ ] **A9. Flag missing `org_number` on brf/foretag deals.** — **BE**
  *Done when:* the backend flags any `kundtyp ∈ {brf,foretag}` deal that arrives with `org_number:null`
  for booker follow-up. Decided: `org_number` is **OPTIONAL** (it lives in "Fler detaljer" and must never
  block a callable lead — Ampy's first-principles moat). It is **owner-flippable**: if OWNER later wants it
  mandatory, the one-spot change is adding `'orgnr'` to the org `ids` array in `validate()` (line 270) and
  adding a `MSG.orgnr` string — document that exact spot, do not hard-code it now.

---

## (B) FRONTEND CONFIG — the four DEV constants + fonts + data binding

All four constants live at the top of the `<script>` (lines 138-145). They are flagged `/* TODO dev */`
in the code. Confirm each before go-live.

- [ ] **B1. PREVIEW resolves `false` on ampy.se.** — **FE**
  *Done when:* on the live domain the form **POSTs** (does not show the payload). `PREVIEW` is host-derived
  (line 144): true only for `file://`, `localhost`/`127.0.0.1`/`[::1]`, and `*.github.io`; production
  `ampy.se` can never be preview. Verify by submitting a test lead on the live/staging host and confirming a
  real POST fires (not the `.payload` preview box). Optionally hard-code `PREVIEW=false` in production.
  *Security note:* `?path=` is honoured **only when PREVIEW is true** (line 210) — in production the path
  comes from `data-ampy-path` / `location.pathname`, so the query string cannot spoof the form shape.

- [ ] **B2. Confirm the ENDPOINT URL.** — **FE + BE**
  *Done when:* `ENDPOINT` (line 138, default `/wp-json/ampy/v1/lead`) matches the route the backend agent
  actually registered in (A1), confirmed by one successful round-trip from a live page.

- [ ] **B3. Confirm `SOURCE_FORM` with the dash team.** — **FE + DASH**
  *Done when:* DASH confirms the numeric code. Currently `SOURCE_FORM=3` (line 139, `/* TODO dev: bekräfta
  kod */`). Spec §9.8 notes `1=Kontakta oss, 3=Multi-steg` and floats a possible **new** value
  "hero-offert" — `[VERIFY]` which value this single-screen hero form should carry, then set it.

- [ ] **B4. Confirm `POLICY_VERSION` + the integritetspolicy URL.** — **OWNER + FE**
  *Done when:* OWNER confirms the policy version string (line 145 ships `ampy-privacy-2026-06`, `/* TODO
  dev: bekräfta version */`) AND the consent link target. The consent checkbox hard-codes
  `https://ampy.se/integritetspolicy` (line 242); the resolver also treats `/integritetspolicy` as a
  `render:false` utility root (line 175). `[VERIFY]` this is the exact live policy URL (spec §9.9 used the
  same as a guess). If the URL changes, update **both** the consent link and the `UTIL` array.

- [ ] **B5. Self-host Outfit + remove the Google Fonts link.** — **FE**
  *Done when:* the `@font-face` `src` path on line 31
  (`/wp-content/themes/ampy/fonts/Outfit.woff2`, marked `[VERIFY PATH]`) is confirmed against the live
  theme and the font loads from Ampy's own server, THEN the Google Fonts `<link>` (lines 26-29, marked
  "PREVIEW ONLY") is removed. Note line 31 declares a **variable** font (weight 300-600); ship either that
  variable file OR one `@font-face` per static weight. Do not remove the Google link before the self-hosted
  face is confirmed loading (otherwise the form falls back to system-ui).

- [ ] **B6. Wire `data-ampy-path` via Bricks dynamic data — full path/URL.** — **FE**
  *Done when:* the markup `<div class="aof" data-ampy-path="…">` is bound to the **full request path/URL**
  (e.g. `{{post_url}}`), **NOT** `post_slug`. The resolver (line 180) strips origin/query/hash and needs
  the whole path: `post_slug` is only the last segment, which would break multi-segment matches
  (`/elservice/<slug>`, `/laddbox/<ort>`) and the bare-root pillar matches (`/elektriker`, `/laddbox`).
  Verify: on a `/laddbox/nacka` page the rendered form shows the "Laddbox i Nacka" chip, proving the full
  path reached `resolve()`. (`location.pathname` is only an emergency fallback, line 210.)

---

## (C) BRICKS WIRING — three parts, one global element, once per page

The file splits into three pieces when pasted into Bricks (see header comment, lines 11-16, and
`01-bricks-implementation.md` for the exact steps). The CSS is fully scoped — every selector is prefixed
`.aof` and the palette tokens are scoped to `.aof` (line 35), so they do **not** leak to `:root`/the site.

- [ ] **C1. Global class `.aof` registered.** — **FE**
  *Done when:* a Bricks global class `.aof` exists and is applied to the form wrapper element, so the
  scoped CSS targets it.

- [ ] **C2. Custom CSS pasted.** — **FE**
  *Done when:* the entire `<style>` block (lines 30-128) is in Bricks page/global Custom CSS.
  **Do NOT paste** the `.aof-host` rule (lines 36-37) or the `<div class="aof-host">` wrapper — they are
  the PREVIEW review shell only; in production the Hero 2 section itself is the background.

- [ ] **C3. The markup element placed inside Hero 2.** — **FE**
  *Done when:* a Bricks element/template containing `<div class="aof" data-ampy-path="…">` (and the
  `aof-live` aria-live `<p>`, lines 132-134, minus the `aof-host` wrapper) sits inside the Hero 2 section,
  with `data-ampy-path` bound per B6. The element's id must be `ampy-form-root` (the script targets
  `getElementById('ampy-form-root')`, line 201).

- [ ] **C4. The ONE sitewide global Code element.** — **FE**
  *Done when:* the entire `<script>` (lines 135-328) is in a **single** global Bricks Code element that
  loads on all ~165 target pages. The IIFE self-initialises on DOM (`render()` at line 326). The script has
  a built-in duplicate guard (lines 205-208): a second `#ampy-form-root` is hidden and a console warning is
  logged — but that is a safety net, not a license to place it twice.

- [ ] **C5. Element placed exactly ONCE per page.** — **FE**
  *Done when:* every target page renders exactly one visible form. If a page logs
  `[aof] dubbel #ampy-form-root dold` (line 208) you have placed it twice — fix the template, do not rely
  on the guard.

---

## (D) QA SPOT-CHECK — before every deploy

A single bad edit breaks **every** form at once. Before any deploy, spot-check the five representative page
types on **desktop and mobile** and run the acceptance tests. See `06-qa-and-acceptance.md` for the full
invariant list. (The 21 frontend QA items from the multi-agent sweep are already fixed in the shipped code;
these checks confirm no regression in the build/wiring.)

- [ ] **D1. Five representative page types render correctly.** — **FE**
  *Done when:* all five resolve to the right shape:
  1. **`/elservice/elcentral`** → service chip "Gäller: Elcentral", tjänst **locked** (no select in main
     view), kundtyp segment toggle present, vertical `service`.
  2. **EFX företag** (e.g. **`/foretag`**) → "Elektriker för företag" chip, **NO** segment toggle (kundtyp
     locked `foretag`), "Vad gäller arbetet?" select required, vertical `foretag_brf`.
  3. **EFX privat** (e.g. **`/villor`**) → "Elektriker för villa" chip, no segment toggle (locked
     `privat`), select required, vertical `service`.
  4. **`/laddbox/nacka`** → "Laddbox i Nacka" chip, tjänst locked "Laddbox", segment toggle present,
     vertical `laddbox`.
  5. **A pillar page** (e.g. **`/elektriker`**) → no chip, segment toggle present, tjänst asked, vertical
     `service` (or fallback `oklart` for an unmapped path).

- [ ] **D2. EFX locks kundtyp; agnostic pages keep the toggle.** — **FE**
  *Done when:* the 13 EFX root slugs show **no** Privat/BRF/Företag toggle and the correct locked kundtyp
  (`villor`/`radhus`=privat, `bostadsrattsforening`=brf, the other 10=foretag — lines 156-170), while
  `/elservice/<slug>`, the ort pages, and the pillar pages **keep** the toggle (visitor type unknown).

- [ ] **D3. Excluded paths render NO form.** — **FE**
  *Done when:* `/eljour/*` shows the "Akut elfel? Ring oss…" call card (line 247), and
  `/solcellsbatterier/*`, `/laddboxar/*`, `/batterilagring/*`, plus the utility roots and their subpaths
  (`/offert`, `/kopvillkor`, `/integritetspolicy`, `/cookiepolicy`, `/thank-you`, `/nyheter`,
  `/tillganglighet`, `/om-oss` — line 175) show "Formuläret visas inte på den här sidtypen" (lines 182-184,
  248). No form must inject on these.

- [ ] **D4. Minimal callable lead submits; enrichment never blocks.** — **FE**
  *Done when:* a privat lead with only Namn + Telefon + E-post + Adress + Postnr + GDPR submits (privat
  additionally requires e-post + adress per the booking team — `validate()` ids on line 270), and an org
  lead with Orgname + Kontakt + Telefon + E-post + Postnr + GDPR submits. Everything in "Fler detaljer"
  (org.nr, beskrivning, tidsram, bilder) is optional and never blocks (lines 269-286).

- [ ] **D5. Validation + accessibility behaviours hold.** — **FE**
  *Done when:* invalid postnr (not 5 digits), too-short phone, and bad email show inline help and
  `aria-invalid` (lines 275-281); submitting without GDPR blocks and focuses the checkbox (line 283);
  **Enter** in a text field submits (line 314); the "Fler detaljer" toggle flips **in place** preserving
  focus + chosen files (line 307); switching segment preserves entered values and keeps selected images
  (lines 216-218, 325).

- [ ] **D6. Payload is correct (preview check).** — **FE**
  *Done when:* on a preview host (`?path=…`) the rendered payload (line 250 shows it) contains the right
  `kundtyp`, `vertical`, `kallsida` (full path), `tjanst_intresse`, normalised `phone_e164` (`+46…`),
  digits-only `postal_code`, `org_number` null for privat, `consent:true`, `policy_version`, and an **empty**
  `company_url`.

- [ ] **D7. Fallback never crashes.** — **FE**
  *Done when:* an unmapped path (e.g. a brand-new page) still renders a callable privat form with vertical
  `oklart` (line 196) — `resolve()` always returns `render:true` rather than throwing.

---

## (E) DEPLOY DISCIPLINE — cache-bust, batch, kill-switch

Because one global element drives ~165 pages, deploy conservatively.

- [ ] **E1. Cache-bust `?v=` bumped this release.** — **FE**
  *Done when:* the version query string on the global element's source is incremented for this release, so
  caches do not serve the previous form to any of the ~165 pages. Bump it on **every** release.

- [ ] **E2. Batch rollout: service → EFX → ort.** — **FE**
  *Done when:* the form is enabled in batches in this order — service pages first, then EFX, then the ort
  pages last (largest volume) — with a spot-check (D1) between batches, so a regression is caught before it
  reaches the highest-traffic surfaces.

- [ ] **E3. Kill-switch ready.** — **FE**
  *Done when:* there is a one-action way to disable/hide the global element (or point `ENDPOINT` away /
  flip the element off) so a bad form can be pulled from all pages at once without a full redeploy.

- [ ] **E4. First-lead end-to-end proof on production.** — **BE + FE**
  *Done when:* after go-live of the first batch, one real submit on a live page lands as the correct
  `customers` + `deals` rows in ampy-dash-2 with `consent_at` stamped and `source='bricks'` — proving the
  whole chain (form → POST → two-row write) works in production, not just staging.

---

## Quick blocker summary (do NOT go live until all true)

1. **A1-A4** — endpoint exists, writes both rows, returns `lead_id`, `consent_at` + `org_name` columns
   added. (No endpoint = every lead dropped.)
2. **B1-B6** — PREVIEW false on ampy.se, ENDPOINT + SOURCE_FORM + POLICY_VERSION + policy URL confirmed,
   Outfit self-hosted (Google link removed), `data-ampy-path` = full path.
3. **C1-C5** — three parts wired, one global Code element, element once per page.
4. **D1-D7** — five page types + acceptance tests pass on desktop and mobile.
5. **E1-E4** — `?v=` bumped, batched rollout, kill-switch, first-lead proof.

Unresolved `[VERIFY]`: exact `SOURCE_FORM` value (B3), `POLICY_VERSION` string + integritetspolicy URL
(B4), Outfit woff2 theme path (B5). These are owner/dash confirmations, not code changes.
