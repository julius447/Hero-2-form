# ARCHITECTURE — Ampy Hero 2 Offertformulär

Plain-English architecture for the developer. This explains *why* the form is built the way it is, what the moving parts are, and the discipline that keeps a single edit from breaking ~165 live pages. No heavy code — concepts and small snippets only. For the exact build steps, see `for-ai-agents/01-bricks-implementation.md`; for the resolver internals, `for-ai-agents/02-the-form-component.md`; for routing/sitemap, `03-sitemap-and-routing.md`; for the backend, `04-php-backend.md`.

---

## 1. The single-component model (and why)

The entire form — markup, styling, and behaviour — lives in **one self-contained file**: `bricks/ampy-offert-form.html`. It is plain HTML + scoped CSS + one vanilla-JavaScript IIFE. **No framework, no build step, no dependencies.** You can open it in a browser and it runs.

This form appears on roughly **165 pages** (service pages, EFX pages, ort pages, pillar pages — see `03-sitemap-and-routing.md` for the full inventory). It does **not** appear as 165 separate copies. It is placed **once**, as a single sitewide Bricks global element, and renders differently on each page by reading the page's URL path at runtime.

Why this matters:

- **One diffable file.** All the per-page logic — the resolver, the 22-entry service map, the 13-entry EFX map, the ort name table, the taxonomy lists — lives in one place you can read top to bottom and diff in one review.
- **A design change propagates everywhere.** Change the card styling or fix a label once, and it lands on all ~165 placements at the next deploy. There is no per-page editing to keep in sync, and a 51st ort page inherits the correct form on day one with zero setup.
- **The trade-off is blast radius.** The same property that makes one edit propagate everywhere means one *bad* edit breaks every form at once. Section 4 covers the discipline that contains this.

The component never tries to be clever about *which* pages it is on at build time. It is dumb-by-design at deploy: it ships to every page, and an in-file function (`resolve()`) decides at runtime whether to render, and if so, in what shape.

---

## 2. The three Bricks parts (and where each lives)

When the single file is pasted into WordPress/Bricks it is split into three parts. The split is mechanical — the human lead does it — but you need to know the shape because each part lives in a different place and has a different blast radius.

| Part | Source in the file | Goes into Bricks as | Scope |
|---|---|---|---|
| **(1) CSS** | the `<style>` block (lines ~30–128) | page/global **Custom CSS** | every selector is prefixed `.aof`, so it cannot leak to `:root` or the rest of the site |
| **(2) Markup** | `<div class="aof" data-ampy-path="{{post_url}}"></div>` | a Bricks element/template placed **inside the Hero 2 section** | one per page (placed via the global element / template) |
| **(3) Script** | the `<script>` IIFE | **ONE sitewide global Bricks Code element** | runs once per page load, sitewide |

Two things in part (2) are load-bearing:

- **`data-ampy-path` must be wired via Bricks DYNAMIC DATA to the full request path/URL** — not `post_slug`. `post_slug` is only the last URL segment; the resolver needs the *whole* path because it matches multi-segment routes (`/elservice/elcentral`, `/laddbox/nacka`) and the bare root. A slug alone would break those matches. The code falls back to `location.pathname` only if the attribute is empty (a safety net, not the intended wiring):

  ```js
  var rawPath = (PREVIEW && qs.get('path')) ? qs.get('path')
              : (root.getAttribute('data-ampy-path') || location.pathname);
  ```

- **The script is a single global element placed once per page.** The code defends against accidental double-placement: if it sees more than one `#ampy-form-root`, it initialises only the first and hides the rest (otherwise you'd get a visibly empty broken form box). But that is a guard, not a license — place it once.

The `<link>` to Google Fonts and the `.aof-host` wrapper in the file are **preview-only scaffolding** — do not paste them into Bricks. In production the Hero 2 section *is* the background, and Outfit is self-hosted via `@font-face` (the woff2 path is a `[VERIFY]` guess — confirm it on the live theme, then remove the Google Fonts link).

---

## 3. The resolver — the heart of the component

Everything per-page flows from one function: **`resolve(path)`**. URL path goes in; a per-page config object comes out. The rest of the script just renders whatever that config describes. If you understand `resolve()`, you understand the form.

It first **normalises** the path defensively — strips the origin, query, and hash; lowercases; collapses repeated slashes; trims leading/trailing slashes; then re-adds a single leading slash:

```js
p = String(p||'').replace(/^https?:\/\/[^/]+/,'').replace(/[?#].*$/,'')
     .toLowerCase().replace(/\/{2,}/g,'/').replace(/^\/+|\/+$/g,'');
p = '/' + p;
```

Then it walks a **fixed resolution order — first match wins.** The order is deliberate: exclusions are checked before anything renders, exact maps before regex patterns, and a never-crash fallback sits at the bottom.

### Resolution order (first match wins)

```
URL path
   │
   ▼
[normalise]  strip origin/query/hash → lowercase → collapse slashes
   │
   ▼
┌─────────────────────────────────────────────────────────────┐
│ 1. EXCLUDE  → render:false  (no form injected)               │
│    /eljour/*            → "Akut elfel?" phone card           │
│    /solcellsbatterier/* /laddboxar/* /batterilagring/*       │
│    utility roots + subpaths: /offert /kopvillkor             │
│       /integritetspolicy /cookiepolicy /thank-you            │
│       /nyheter /tillganglighet /om-oss                       │
└─────────────────────────────────────────────────────────────┘
   │ (not excluded)
   ▼
┌─────────────────────────────────────────────────────────────┐
│ 2. SERVICE map (exact)  /elservice/<slug>  — 22 slugs        │
│    → tjanst LOCKED to that service, kundtyp privat,          │
│      vertical service, shows "Gäller: <service>" chip        │
│    /elservice (bare)    → asks the service, kundtyp privat   │
└─────────────────────────────────────────────────────────────┘
   │ (no match)
   ▼
┌─────────────────────────────────────────────────────────────┐
│ 3. EFX map  <root-slug>  — 13 slugs                          │
│    → kundtyp LOCKED from slug (no segment toggle),           │
│      "Elektriker för <X>" chip, asks the service             │
│    villor/radhus = privat · bostadsrattsforening = brf       │
│    other 10 = foretag                                        │
└─────────────────────────────────────────────────────────────┘
   │ (no match)
   ▼
┌─────────────────────────────────────────────────────────────┐
│ 4. ORT regexes (one segment each)                            │
│    /laddbox/<ort>        → tjanst LOCKED "Laddbox",          │
│                            vertical laddbox                  │
│    /elinstallation/<ort> → tjanst LOCKED "Elinstallation",  │
│                            vertical service                  │
│    /elektriker/<ort>     → asks the service, vertical service│
└─────────────────────────────────────────────────────────────┘
   │ (no match)
   ▼
┌─────────────────────────────────────────────────────────────┐
│ 5. PILLAR pages (bare roots)                                 │
│    /elektriker /elinstallation → as above without an ort     │
│    /laddbox → tjanst LOCKED "Laddbox"                        │
└─────────────────────────────────────────────────────────────┘
   │ (no match)
   ▼
┌─────────────────────────────────────────────────────────────┐
│ 6. FALLBACK  (never crashes)                                 │
│    → render:true, kundtyp privat, asks the service,          │
│      vertical 'oklart' — always a callable lead              │
└─────────────────────────────────────────────────────────────┘
```

Two structural points the order encodes:

- **EXCLUDE runs first.** Product, eljour, and utility pages must *never* show the form even if a later pattern could coincidentally match. The eljour exclusion returns a small "Akut elfel? Ring oss" card with the phone number rather than nothing.
- **The fallback is a feature, not a failure.** An unrecognised path does not crash and does not render an empty box — it renders a working, callable lead form (`kundtyp privat`, asks the service, `vertical: 'oklart'`). Even a brand-new page the team forgot about still captures a lead the booker can act on. The CRM's `oklart` vertical exists precisely to receive these.

### The config the resolver emits

The config drives three things in the UI:

- **`kundtyp` + whether it is locked.** On EFX pages the page already knows the visitor type (a `bostadsrattsforening` page is a BRF), so kundtyp is **locked from the slug and there is no Privat/BRF/Företag segment toggle** — instead a warm "Elektriker för <X>" chip is shown. On category-agnostic pages (service pages, ort pages, pillar pages) the visitor type is unknown, so the segment toggle stays.
- **`tjanst` + whether it is locked.** A `/elservice/elcentral` or `/laddbox/<ort>` page knows the service, so the service is locked and shown as a chip. An `/elektriker/<ort>` or EFX page does not, so the form asks "Vad gäller arbetet?".
- **`vertical`** (`service` | `laddbox` | `batteri` | `foretag_brf` | `oklart`) — carried straight into the payload for CRM segmentation.

> **One stale-spec correction:** `FUNKTIONALITET.md` §9.1 says EFX kundtyp is "öppet/just prefilled". The shipped code **locks it** (no segment toggle on EFX). The code is the source of truth. Rationale: the page already knows the visitor type, so removing the toggle cuts friction, guarantees correct segmentation, and reads warmer. This is owner-flippable — if Ampy later wants it editable, see `02-the-form-component.md` for the one-spot change.

---

## 4. Blast radius — the reality and the discipline that contains it

**The reality:** one global element on ~165 pages means **one bad edit breaks every form at once.** There is no per-page isolation. A typo in the resolver, a broken selector, or a bad endpoint takes down lead capture sitewide simultaneously. This is the price of the single-component model, and it is paid by discipline, not by architecture.

**The discipline (do all of these on every release):**

1. **Cache-bust `?v=` per release.** Browsers and the CDN cache the global script aggressively. Bump the version query on every deploy so visitors actually get the new code — otherwise you ship a fix nobody receives, or worse, a mix of old and new. (This is the exact discipline used on Ampy's other live assets; a stale cache has bitten releases before.)
2. **Spot-check 4–5 representative page types before deploy.** Not one page — one of *each shape*, because each exercises a different resolver branch:
   - a service page (`/elservice/elcentral`) — locked tjanst + chip
   - an EFX company page (e.g. `/restauranger`) — locked kundtyp, no segment toggle
   - an EFX private page (`/villor`) — locked kundtyp privat
   - an ort page (`/laddbox/nacka`) — locked "Laddbox" + ort chip
   - a pillar or fallback page — segment toggle + asks the service
   Confirm each renders the right lock/prefill and that submit produces the right payload.
3. **Batch the rollout.** Deploy in order of increasing blast radius: **service pages → EFX pages → ort pages last** (ort pages are the largest group by volume). If something is wrong, you catch it on the smaller batch before it reaches the bulk of traffic.
4. **Keep a kill-switch.** Be able to disable or revert the global element fast. Because everything routes through one element, disabling it is also one action — pulling the global Code element (or reverting `?v=` to the last good build) restores the previous state everywhere at once.

The same single-point property is both the risk (one edit breaks all) and the mitigation (one revert fixes all). The job of the discipline above is to make sure you *notice* before the bad build is the live one.

---

## 5. Data flow — end to end

The guiding principle is the **minimal callable lead**: the smallest amount of information that lets Ampy phone the visitor back. Everything beyond that is optional enrichment that must **never** block submit.

- **Minimal callable lead** = Namn + Telefon + Postnummer + GDPR consent (+ an empty `company_url` honeypot).
- Privat additionally requires e-post + adress (booking-team requirement). Org segments (BRF/Företag) require orgname + kontaktperson + telefon + e-post + postnr. **org.nr is optional** even for orgs — it lives in the "Fler detaljer" disclosure and never blocks a callable lead; the backend flags a missing org.nr on brf/foretag deals for booker follow-up. (Owner-flippable: see the decisions list in `05-data-contract.md` for the one-spot change to make it mandatory.)
- Everything else (beskrivning, adress where optional, bilder, tidsram) is enrichment.

### Request / data flow

```
 VISITOR on a page (e.g. /laddbox/nacka)
        │  fills the minimal callable lead
        │  (Namn + Telefon + Postnummer + GDPR)
        ▼
 buildPayload()  → ONE JSON object
        │  full_name, phone_e164 (→ +46…), postal_code (digits),
        │  email, org_number|null, org_name|null,
        │  kundtyp, vertical, tjanst_intresse|null, bradska|null,
        │  beskrivning|null, street_address|null, bilder_count,
        │  kallsida (full path), source:'bricks', source_form,
        │  consent:true, policy_version, company_url (honeypot, MUST be empty)
        ▼
 POST  →  lead endpoint  (/wp-json/ampy/v1/lead — TODO confirm)
        │  rejects if honeypot filled; validates/sanitises;
        │  rate-limits + dedupes; stamps consent_at server-side
        ▼
 ampy-dash-2  — TWO-ROW write
   ┌──────────────────────────────┐   ┌───────────────────────────────────┐
   │ customers row                │   │ deals row                         │
   │ full_name, phone_e164,       │   │ vertical, kundtyp, tjanst_intresse│
   │ email, street_address,       │   │ bradska, beskrivning, kallsida,   │
   │ postal_code, org_number,     │◄──┤ source, source_form,              │
   │ org_name                     │FK │ consent_at (server ts),           │
   │ UPSERT/dedupe on phone_e164  │   │ policy_version → returns lead_id  │
   └──────────────────────────────┘   └───────────────────────────────────┘
        ▼
 OPTIONAL ENRICHMENT  — PATCH the SAME deal by lead_id
        │  (later detail the visitor adds)
        ▼
 IMAGES — SEPARATE multipart channel, keyed by lead_id
          (NOT in this JSON; until built, bilder_count is the only signal)
```

Key contract points:

- **`buildPayload()` is authoritative.** The PHP endpoint must consume exactly these fields and enums (`kundtyp` ∈ privat/brf/foretag; `vertical` ∈ service/laddbox/batteri/foretag_brf/oklart; `bradska` ∈ 24h/72h/1_2v/flexibel|null). See `05-data-contract.md` for the field-by-field mapping.
- **`consent_at` is server-stamped.** The form sends `consent: true` + `policy_version`; the *timestamp* is set by the server (a stale-spec note: §7 lists consent_at as something the form POSTs — it does not). This needs a new column in the migration.
- **Two rows, one lead.** A `customers` row deduped/UPSERTed on `phone_e164`, and a `deals` row linked by FK that returns a `lead_id`. The `lead_id` is what makes optional enrichment and image upload possible after the first POST.
- **Images are a separate channel.** They are not in the JSON. Until the multipart channel is built, `bilder_count` is the only signal that images existed.
- `source_form` currently defaults to `3` — **confirm with the dash team** before launch.

---

## 6. Degradation guarantees — never a silent success

The form is built so that the visitor always ends in an honest, recoverable state. Three guarantees:

1. **The root render is wrapped in try/catch.** If anything throws while building the form, the catch renders the error card instead of leaving a blank or half-built box:

   ```js
   root.innerHTML = formCard(cfg);
   fill();
   } catch(e){ root.innerHTML = errorCard(); }
   ```
   The resolver itself is also wrapped — if `resolve()` somehow throws, it falls back to a working privat/`oklart` config rather than crashing.

2. **The error card is honest and gives a phone number.** A failed POST (or a thrown error) shows "Något gick fel" with a real fallback action — a "Försök igen" retry button **and** the phone number `010-265 79 79`. The visitor is never stranded with no path forward.

3. **Never a silent success.** The done card ("Tack, vi har din förfrågan") only renders after a confirmed success: in production, only when the POST resolves with an `r.ok` response; otherwise the catch routes to the error card. A non-2xx, a network failure, or a 10-second timeout (via `AbortController`) all land on the error card, not the success card.

   ```js
   .then(function(r){ if(!r.ok) throw new Error('http '+r.status);
                      st.done = true; render(); })
   .catch(function(){ st.error = true; render(); });
   ```
   This places a hard requirement on the backend: **return 2xx only after the two-row write actually succeeds.** If the endpoint returns 200 before persisting, the visitor sees success while the lead is lost — the exact silent-drop failure this design exists to prevent.

Additional safety nets that reinforce the above: the **PREVIEW flag is derived from the host** (only `file://`, localhost, and `*.github.io`), so production (`ampy.se`) can never accidentally fall into preview-mode and show the payload instead of POSTing — a forgotten boolean must not drop leads on 165 pages. And `?path=` is honoured **only** in preview, so it cannot be used to spoof routing in production.

---

## Cross-references

- `for-chris/README.md` — master human handover (read first)
- `for-chris/GO-LIVE-CHECKLIST.md` — pre-launch checklist + acceptance criteria
- `for-ai-agents/02-the-form-component.md` — resolver/maps internals + per-page matrix
- `for-ai-agents/03-sitemap-and-routing.md` — page inventory + how to add an ort/service/EFX
- `for-ai-agents/04-php-backend.md` — the lead endpoint
- `for-ai-agents/05-data-contract.md` — payload ↔ ampy-dash-2 field mapping
- `for-ai-agents/06-qa-and-acceptance.md` — invariants not to regress
