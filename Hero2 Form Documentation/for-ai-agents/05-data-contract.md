# 05 — Data Contract: payload ↔ ampy-dash-2

This is the single authoritative mapping between **what the form POSTs** and **what the
backend must write into the `ampy-dash-2` CRM**. The PHP endpoint
(`code/ampy-lead-endpoint.php`) must consume *exactly* this JSON shape and produce the
two-row write described below.

**Source of truth = the shipped component**, `bricks/ampy-offert-form.html`. Where the spec
(`FUNKTIONALITET.md`) disagrees with the code, the code wins. Anything not confirmable from
the code is marked `[VERIFY]` or `[GAP]`.

---

## 0. The one function that defines the contract

Everything here is anchored in `buildPayload()` from the shipped code. This is the literal
object that gets `JSON.stringify`'d and POSTed:

```js
function buildPayload(){
  var cfg=st.cfg;
  var tjEl=document.getElementById('aof-tj');
  var tj=cfg.tjanstLocked?cfg.tjanst:(tjEl?tjEl.value:'');
  var tid=document.getElementById('aof-tid');
  var hp=document.getElementById('aof-company_url');
  var bi=document.getElementById('aof-bilder');
  return {
    full_name:(st.seg==='privat'?val('namn'):val('kontakt')),
    phone_e164:toE164(val('telefon')),
    postal_code:val('postnr').replace(/\D/g,''),
    email:val('epost'),
    org_number:(st.seg!=='privat'&&val('orgnr')?val('orgnr').replace(/\D/g,''):null),
    org_name:(st.seg!=='privat'?val('orgname'):null),
    kundtyp:st.seg,
    vertical:cfg.vertical,
    tjanst_intresse:tj||null,
    bradska:(tid&&BRADSKA[tid.value])||null,
    beskrivning:val('beskriv')||null,
    street_address:val('adress')||null,
    bilder_count:((bi&&bi.files&&bi.files.length)?bi.files.length:(st.files?st.files.length:0)),
    kallsida:cfg.source,
    source:'bricks',
    source_form:SOURCE_FORM,
    consent:true,
    policy_version:POLICY_VERSION,
    company_url:(hp?hp.value:'')
  };
}
```

Note three normalisations the form performs **before** POSTing — the backend should not
re-apply them but must tolerate them:
- `phone_e164` is run through `toE164()` → already `+46…` form (see §5).
- `postal_code` and `org_number` are stripped to digits only (`.replace(/\D/g,'')`).
- Optional text fields collapse to `null` (not `""`) via the `val(...)||null` pattern.

The form sends `Content-Type: application/json` and expects a **2xx only on a successful
write** (the JS treats any non-`r.ok` as an error and shows the retry card).

---

## 1. POST JSON — every field

`Req?` = whether the form **guarantees** the field is populated by the time it POSTs (the
form blocks submit until its required set is valid — see §6). "Optional" fields may arrive as
`null`/`""`/`0` and **must never** be treated as missing-data errors by the backend.

| Field | Type | Example | Req? (in payload) | Source in the form |
|---|---|---|---|---|
| `full_name` | string | `"Anna Svensson"` | **Always present** | `namn` field if `kundtyp==='privat'`, else the `kontakt` (contact person) field |
| `phone_e164` | string | `"+46701234567"` | **Always present** | `telefon` field → `toE164()` normalised (§5) |
| `postal_code` | string (digits) | `"11122"` | **Always present** | `postnr` field, `.replace(/\D/g,'')` (space stripped) |
| `email` | string | `"anna@example.se"` | Privat: always; org: always (both require it — §6). Sent as `""` only if validation were bypassed | `epost` field, raw `val()` (NOT coerced to null) |
| `org_number` | string (digits) \| `null` | `"5566778899"` \| `null` | Nullable — **always `null` for privat**; optional even for brf/foretag | `orgnr` field if `kundtyp!=='privat'` and non-empty, digits only; else `null` |
| `org_name` | string \| `null` | `"BRF Solgläntan"` | Nullable — **always `null` for privat**; required for brf/foretag (§6) | `orgname` field if `kundtyp!=='privat'`; else `null` |
| `kundtyp` | enum | `"privat"` | **Always present** | `st.seg` — the segment state (§3) |
| `vertical` | enum | `"laddbox"` | **Always present** | `cfg.vertical` from the resolver (§3) |
| `tjanst_intresse` | string \| `null` | `"Laddbox"` | Nullable | Locked pages: `cfg.tjanst` (the chip's service). Ask pages: the `tj` `<select>` value. Empty → `null` |
| `bradska` | enum \| `null` | `"72h"` | Nullable | "Tidsram" `<select>` → mapped via `BRADSKA{}` (§4). Unselected → `null` |
| `beskrivning` | string \| `null` | `"Vill ha offert på…"` | Nullable | `beskriv` textarea. Empty → `null` |
| `street_address` | string \| `null` | `"Storgatan 1"` | Privat: always (required §6); org: optional/nullable | `adress` field. Empty → `null` |
| `bilder_count` | number | `2` | **Always present** (0 if none) | count of files in the `bilder` input / preserved `st.files` (§7) |
| `kallsida` | string (path) | `"/laddbox/nacka"` | **Always present** | `cfg.source` — the normalised request path the resolver matched on |
| `source` | string const | `"bricks"` | **Always present** | hardcoded `'bricks'` |
| `source_form` | number | `3` | **Always present** | the `SOURCE_FORM` JS constant — **currently `3`, `[VERIFY]` with dash team** (§9, decision #8) |
| `consent` | boolean | `true` | **Always `true`** | hardcoded `true`; submit is blocked unless the GDPR box is checked (§6) |
| `policy_version` | string | `"ampy-privacy-2026-06"` | **Always present** | the `POLICY_VERSION` JS constant — `[VERIFY]` exact version with owner (§9) |
| `company_url` | string (honeypot) | `""` | **Always present, MUST be `""`** | the hidden honeypot input; backend rejects if non-empty (§8) |

> **Not in this JSON:** `consent_at` (server-stamped — §2), the customer↔deal foreign key
> (server-generated), `lead_id` (returned by the backend — §2), and the actual image files
> (separate multipart channel — §7). The form sends `consent:true` + `policy_version`; it does
> **not** send a `consent_at` timestamp. The stale spec (§9.x of FUNKTIONALITET.md) listing
> `consent_at` as a posted field is wrong — it is server-stamped.

---

## 2. CRM write: two rows in `ampy-dash-2`

One lead = **two rows**: a `customers` row and a `deals` row, linked by a foreign key. The
backend:
1. **UPSERTs** the `customers` row, deduped on `phone_e164` (§2.3).
2. **INSERTs** a `deals` row referencing that customer, stamping `consent_at` server-side.
3. **Returns** the new `deals.lead_id` so later enrichment (description, images) can PATCH the
   *same* deal row.

### 2.1 `customers` table

| Column | Source payload field | Nullable? | Notes |
|---|---|---|---|
| `full_name` | `full_name` | no | privat = person; org = the contact person (`kontakt`) |
| `phone_e164` | `phone_e164` | no | **dedupe / UPSERT key** (§2.3) |
| `email` | `email` | yes (nullable column) | always populated in practice (form requires it both segments) |
| `street_address` | `street_address` | yes | privat fills it; org may leave `null` |
| `postal_code` | `postal_code` | no | digits only |
| `org_number` | `org_number` | yes | `null` for privat; nullable even for org (decision below) |
| `org_name` | `org_name` | yes | **column to ADD** — see §2.4. `null` for privat |

### 2.2 `deals` table

| Column | Source | Server-stamped? | Nullable? | Notes |
|---|---|---|---|---|
| `lead_id` | (generated) | yes | no | PK; **returned to the form** for the optional enrichment PATCH |
| *(customer FK)* | (generated) | yes | no | FK → the upserted `customers` row `[VERIFY]` exact column name with schema |
| `vertical` | `vertical` | no | no | enum (§3) |
| `kundtyp` | `kundtyp` | no | no | enum (§3) |
| `tjanst_intresse` | `tjanst_intresse` | no | yes | free-text-ish service label; booker reads it |
| `bradska` | `bradska` | no | yes | enum (§4) |
| `beskrivning` | `beskrivning` | no | yes | free text |
| `kallsida` | `kallsida` | no | no | full matched path. Maps to the deal's source-page column (`lead_magnet_slug` is reused for this per the spec) `[VERIFY]` exact column name |
| `source` | `source` (`"bricks"`) | no | no | channel constant |
| `source_form` | `source_form` | no | no | numeric form id `[VERIFY]` value (§9) |
| `consent` | `consent` (`true`) | no | no | the boolean the form sends |
| `consent_at` | **server timestamp** | **YES** | no | **NOT in the payload** — the server stamps the time of write. **Column to ADD** (§2.4) |
| `policy_version` | `policy_version` | no | no | the policy string the user consented to; pair it with `consent_at` for the GDPR record |
| `bilder_count` | `bilder_count` | no | no | integer signal only; real files arrive via multipart (§7) |

**Consent record authority:** the legally meaningful timestamp is **`consent_at`, stamped by
the server at write time** — never trust a client clock. `policy_version` comes from the
client (`POLICY_VERSION`) so the record captures *which* policy text the user agreed to.
Store both together.

### 2.3 Dedupe key

**`phone_e164`.** The `customers` row is UPSERTed on `phone_e164` (already normalised to
`+46…` by the form — see §5, which guarantees the dedupe key is canonical). The same person
re-submitting updates their customer row and gets a new `deals` row. The backend should also
apply rate-limiting / idempotency at the request level (a deferred backend item — see
`06-qa-and-acceptance.md` and `04-php-backend.md`).

### 2.4 Columns to ADD before go-live

These do not exist in the current migration and are blockers (`ampy-dash-2-migration.sql`):
- **`deals.consent_at`** — server-stamped timestamp; GDPR requirement.
- **`customers.org_name`** — the form posts `org_name`, but the spec's schema did not list a
  column for it. Add a nullable text column.

---

## 3. Enums — spelled out exactly

### `kundtyp` — `"privat" | "brf" | "foretag"`
From `st.seg`. Derived from the resolver (`cfg.kundtyp`) and, on ask-pages with the segment
toggle, from the visitor's choice. EFX pages **lock** it (no toggle). Mapping of EFX slug →
`kundtyp` (from the `EFX{}` map in code):
- `privat`: `villor`, `radhus`
- `brf`: `bostadsrattsforening`
- `foretag`: `restauranger`, `hotell`, `kontor`, `butik`, `kommuner`, `idrottshallar`,
  `foretag`, `byggforetag`, `entreprenad`, `tredjepartsinstallationer`

### `vertical` — `"service" | "laddbox" | "batteri" | "foretag_brf" | "oklart"`
From `cfg.vertical` in the resolver:
- `service` — `/elservice/<slug>`, `/elinstallation/<ort>`, `/elektriker/<ort>`,
  `/elektriker`, `/elinstallation`, EFX `villor`/`radhus`
- `laddbox` — `/laddbox/<ort>`, `/laddbox` (locked "Laddbox")
- `foretag_brf` — **all EFX org/brf pages** (every EFX slug except villor/radhus)
- `oklart` — the fallback (no rule matched)
- `batteri` — **a valid enum value the form never emits.** The shipped resolver maps no page
  to `batteri` (battery/solar product pages are in the EXCLUDE set → `render:false`). Keep the
  column able to store it (CRM-wide enum), but expect no `bricks` lead to carry it. `[VERIFY]`
  with dash team that the shared enum still lists it.

### `bradska` — `"24h" | "72h" | "1_2v" | "flexibel"` (nullable)
The form shows Swedish UI labels in the "Tidsram" select and maps them via `BRADSKA{}`:

| UI label (Swedish, customer-facing) | Posted enum |
|---|---|
| `Inom 24 timmar` | `24h` |
| `Inom 72 timmar` | `72h` |
| `Om 1-2 veckor` | `1_2v` |
| `Flexibelt` | `flexibel` |
| *(nothing selected)* | `null` |

### `source` — const `"bricks"`
Single allowed value from this form.

---

## 4. Minimal callable lead (the floor)

The Ampy first-principles moat: **a lead is callable with the bare minimum, and enrichment
never blocks submit.** The absolute floor the form always guarantees:

> **Namn + Telefon + Postnummer + GDPR-samtycke** (+ an **empty** `company_url` honeypot).

Everything else — `email`, `street_address`, `org_number`, `beskrivning`, images, `bradska`,
`tjanst_intresse` — is optional enrichment that must **never** cause a backend rejection on
its own.

Two segment-specific additions the form enforces on top of the floor (the booking team's
requirements — see `validate()` in code), but these are still *front-end gating*, not extra
backend hard-requirements:
- **Privat** additionally requires `email` + `street_address`.
- **Org (brf/foretag)** additionally requires `org_name` + `kontakt` (→ `full_name`) +
  `telefon` + `email` + `postnr`.

**`org_number` is OPTIONAL** (in the "Fler detaljer" disclosure), even for brf/foretag —
decided, see below.

---

## 5. Phone normalisation (`toE164`) — what the backend receives

The form normalises before POSTing, so `phone_e164` is already `+46…`:

```js
function toE164(s){
  s=String(s||'').replace(/[\s\-()]/g,'');
  if(/^00/.test(s))s='+'+s.slice(2);       // 0046… → +46…
  else if(/^0/.test(s))s='+46'+s.slice(1); // 0701… → +46701…
  else if(/^46/.test(s))s='+'+s;           // 46701… → +46701…
  else if(/^\d/.test(s))s='+46'+s;         // 701… (no leading 0) → +46701…
  return s;
}
```

The backend should **validate** the result (and may re-normalise defensively) but the dedupe
key is this canonical string. Note the form's *validation* gate only checks digit-length ≥ 7
(`validate()`), so the backend should not assume strict E.164 length — treat malformed
numbers as a soft data-quality flag, not a drop, to honour the callable-lead floor. `[VERIFY]`
backend's exact phone validation policy.

---

## 6. Required-set logic (what the form blocks on)

From `validate()`:
- **Privat** required ids: `namn`, `telefon`, `epost`, `adress`, `postnr` + GDPR.
- **Org (brf/foretag)** required ids: `orgname`, `kontakt`, `telefon`, `epost`, `postnr` + GDPR.
- **EFX ask-pages** additionally require `tj` ("Vad gäller arbetet?") because the select is
  surfaced in the main view (`if(st.cfg&&st.cfg.forLabel)ids.push('tj')`).
- GDPR consent is always required; submit is blocked (with `aria-invalid`) until checked.

`org_number` (`orgnr`) is **not** in any required set → confirms it is optional in the
payload.

---

## 7. `bilder_count` vs the real image upload

- The JSON carries **`bilder_count` only** — an integer signalling how many images the user
  attached. It is **not** the images themselves.
- Real image bytes travel on a **separate multipart channel keyed by `lead_id`** (built after
  the lead row exists). Until that channel is built, `bilder_count` is the only signal that a
  user had images to share, so the booker can ask for them.
- The form preserves selected files across re-renders in `st.files` and reflects the count
  even after a segment switch (see `change` handler + `fill()` in code), so `bilder_count` is
  reliable at submit time.

`[GAP]` the multipart upload endpoint is a deferred backend item — see `04-php-backend.md`.

---

## 8. Honeypot — `company_url`

- A hidden field (`.hp`, off-screen, `aria-hidden`, `tabindex="-1"`) named `company_url`.
- The form **already** refuses to submit if it is filled: in `submit()`,
  `if(hp&&hp.value)return;` and it is always posted.
- The backend **MUST reject** (silently 2xx-drop or 4xx — `[VERIFY]` preferred behaviour with
  dash team) any request where `company_url` is non-empty. A real user never fills it.

---

## 9. Owner-flippable & to-confirm items

- **`org_number` = OPTIONAL (decided).** Kept in the "Fler detaljer" disclosure; never blocks
  a callable lead. The **backend flags a missing `org_number` on brf/foretag deals** for booker
  follow-up. This resolves the stale spec's "KRÄVS". `org_number` is documented as **nullable**.
  - *Owner-flippable:* to make it mandatory, the change is in **one spot** — add `'orgnr'` to
    the org branch of the `ids` array in `validate()` (the line
    `var ids=(st.seg==='privat')?[...]:['orgname','kontakt','telefon','epost','postnr'];`) and
    add a `MSG.orgnr` string. No payload/contract change needed (the field already posts).
- **`source_form` value** — currently `3`. `[VERIFY]` with the dash team (FUNKTIONALITET §9
  decision #8 suggests possibly a new value "hero-offert").
- **`policy_version`** — currently `"ampy-privacy-2026-06"`. `[VERIFY]` exact version with
  owner.
- **Integritetspolicy URL** — the consent text links to `https://ampy.se/integritetspolicy`.
  `[VERIFY]` exact URL.
- **Customer/deal FK column name** and the **`kallsida` → deals column** (`lead_magnet_slug`
  reuse) `[VERIFY]` against the live `ampy-dash-2` schema.

---

## Cross-references

- **`code/ampy-lead-endpoint.php`** — the PHP endpoint that must consume this exact JSON and
  perform the two-row write. It owns: honeypot rejection (§8), server-stamping `consent_at`
  (§2), UPSERT-on-`phone_e164` dedupe (§2.3), enum validation (§3), and returning `lead_id`.
- **`code/ampy-dash-2-migration.sql`** — must ADD `deals.consent_at` and `customers.org_name`
  (§2.4).
- **`for-ai-agents/04-php-backend.md`** — endpoint build + deploy.
- **`for-ai-agents/06-qa-and-acceptance.md`** — the invariants (callable-lead floor, honeypot,
  enum integrity) that must not regress.
- **`for-ai-agents/02-the-form-component.md`** / **`03-sitemap-and-routing.md`** — how
  `kundtyp`, `vertical`, `tjanst_intresse`, and `kallsida` are resolved from the URL.
