# 00 — START HERE (AI implementation agent)

You are implementing the **Ampy offertformulär** (quote/lead form) into Ampy's live **WordPress + Bricks** site. Read this file completely before touching anything. It is your map, your constraints, and your launch order. Every other file in this package exists to be read in the order given below — do not freelance.

The component already exists and is **frontend-complete**. Your job is *not* to redesign it. Your job is to **place it correctly in Bricks and build the backend that catches its leads, without regressing a single invariant.** The shipped file is the source of truth wherever any document disagrees with it.

- **The component (source of truth):** `code/ampy-offert-form.html` — one self-contained file: HTML + scoped CSS (every selector prefixed `.aof`) + one vanilla-JS IIFE. No framework, no build step.
- **The spec (PARTLY STALE — do not trust over the code):** `../FUNKTIONALITET.md`. See "Stale spec warnings" below before you read it.

---

## The goal, in one sentence

Get this one lead form live on ~165 pages — rendering correctly per page, never dropping a lead, in Ampy's honest Swedish voice — by **placing it once as a global Bricks element** and **building the PHP/CRM backend it POSTs to**, with zero changes to the working frontend.

---

## Hard constraints (these bite — violating any one is a launch blocker)

1. **24/7/365, never drop a lead.** This is the moat. The form is the front door for ~165 pages; a single bad change breaks every form at once. The resolver is built to *always* return a callable lead (it falls back to `kundtyp:'privat', ask:true, vertical:'oklart'` and never crashes — see `resolve()` in the code). Your backend must be just as safe: it must return **2xx only on a successful CRM write**, so a failed write surfaces the form's error card ("Något gick fel … ring oss") instead of silently swallowing the lead. (This is the exact failure mode that bit Elcentral-kollen's null webhook — do not repeat it.)

2. **One global element, fixed ids → place it EXACTLY ONCE.** The markup uses hardcoded ids (`#ampy-form-root`, `#aof-namn`, `#aof-gdpr`, `#aof-company_url`, etc.). Two copies on one page = duplicate ids = broken DOM. The script has a duplicate guard (`data-aof-init` + it hides extra `#ampy-form-root` nodes and `console.warn`s), but **do not rely on it** — place the element once per page via a single sitewide global, and place the `<script>` once as one sitewide global Code element.

3. **Candour doctrine (Ampy voice).** Swedish UI, du-tilltal. **No "!"** anywhere except the owner-mandated header `Få kostnadsfri rådgivning!` and its subtitle `Vår behöriga elektriker återkommer via telefon!` (both already in the code; both keep their `!` by explicit owner override). No superlatives. Never assert "5.0 på Google", "1000+ kunder", or national / "hela Sverige" coverage. Never invent a number, URL, or value. In these docs, mark anything unconfirmed as `[VERIFY]` or `[GAP]` — never guess.

4. **The component is frozen — do not regress it.** 21 frontend QA fixes are already baked in (host-derived PREVIEW, utility-subpath exclude, Enter-to-submit, in-place disclosure toggle that preserves focus + selected files, `toE164` +46 normalisation, duplicate-element guard, consent `aria-invalid` / `aria-required`, scoped tokens, backdrop-filter fallback, reduced-motion, etc.). Touching the JS or CSS risks undoing these. See `06-qa-and-acceptance.md` for the full invariant list you must not break.

---

## Read order, then implement order

Read these in sequence. Each builds on the last. **Understand before you edit.**

1. **`02-the-form-component.md`** — what the component *is*: the `resolve(path)` resolver, the SERVICE / EFX / ORT maps, the per-page render matrix, the state machine, PREVIEW behaviour. Read this first or nothing else will make sense.
2. **`03-sitemap-and-routing.md`** — the dedicated sitemap → routing section: full page inventory, how the URL path drives which form renders, and how to add a new ort / service / EFX page later.
3. **`01-bricks-implementation.md`** — the exact step-by-step WordPress/Bricks build: where the `<style>` goes (page/global Custom CSS), where the `<div class="aof" data-ampy-path="…">` goes (an element inside the Hero 2 section, with `data-ampy-path` wired via Bricks **dynamic data**), where the `<script>` goes (one sitewide global Code element).
4. **`04-php-backend.md`** — the PHP lead endpoint: what it must validate, sanitise, rate-limit, dedupe, and write; how to deploy it. You write `code/ampy-lead-endpoint.php` and `code/ampy-dash-2-migration.sql`.
5. **`05-data-contract.md`** — the authoritative payload ↔ `ampy-dash-2` field mapping: every field, every enum, the two-row write (customers + deals), the separate multipart image channel. Your PHP must consume *exactly* what `buildPayload()` POSTs.
6. **`06-qa-and-acceptance.md`** — verify against this last: the invariants you must not regress + the acceptance tests that say "this is safe to launch."

Build order mirrors the read order: **understand the component → understand routing → place it in Bricks → build the backend → wire the data contract → prove it with QA.**

---

## Values that MUST be set before go-live

These live at the top of the `<script>` in `code/ampy-offert-form.html` (and one is on the markup `<div>`). Several are flagged `TODO dev` in the code itself. Nothing ships until each is confirmed:

| Value | Where | Current placeholder | What it must become |
|---|---|---|---|
| `ENDPOINT` | script top, `var ENDPOINT` | `'/wp-json/ampy/v1/lead'` | the real lead route you build (`04-php-backend.md`). Endpoint must return 2xx **only** on a successful write. |
| `PREVIEW` | script top, `var PREVIEW` | host-derived (file://, localhost, *.github.io = true; everything else false) | leave host-derive as-is, OR hardcode `false` in production. Production domain ampy.se can **never** be preview — verify this. |
| `SOURCE_FORM` | script top, `var SOURCE_FORM` | `3` (TODO confirm) | the value the dash team confirms for this form. `[VERIFY]` with the dash team — `05-data-contract.md` carries the open question. |
| `POLICY_VERSION` | script top, `var POLICY_VERSION` | `'ampy-privacy-2026-06'` (TODO confirm) | the confirmed privacy-policy version string stamped onto each consent. `[VERIFY]`. |
| Outfit font path | `<style>`, `@font-face … src:url(...)` | `/wp-content/themes/ampy/fonts/Outfit.woff2` `[VERIFY PATH]` | the real self-hosted woff2 path on Ampy's theme. Only after the self-hosted face is confirmed working, remove the Google Fonts `<link>` (it is PREVIEW-ONLY). |
| `data-ampy-path` | markup `<div class="aof" data-ampy-path="…">` | empty in the standalone file | wired via Bricks **dynamic data** to the **full request path/URL** — NOT `post_slug` (slug is only the last segment and would break multi-segment matches like `/elservice/elcentral` and the bare-root pillar matches). `location.pathname` is only the emergency fallback. |

Also confirm before launch (carried in the relevant files, not the script): the **integritetspolicy URL** (the code currently links `https://ampy.se/integritetspolicy` — `[VERIFY]`), the **`consent_at` + `org_name` columns** exist in `ampy-dash-2` (you add them in the migration), and the **multipart image channel** keyed by `lead_id`.

---

## Stale spec warnings (`../FUNKTIONALITET.md`)

`FUNKTIONALITET.md` is useful background but predates the shipped code. **Do NOT repeat these stale points as current truth** — the code wins:

- It mentions **two variants + a review harness.** Production is **ONE** component.
- §9.1 says EFX kundtyp is "öppet / just prefilled." The code now **LOCKS** it: on EFX pages there is **no Privat/BRF/Företag segment toggle** — kundtyp is fixed from the slug and a welcoming "Elektriker för X" chip is shown. (Category-agnostic pages — `/elservice/<slug>`, `/elektriker|/laddbox|/elinstallation/<ort>`, pillar pages — *keep* the toggle, because the visitor type is unknown there.)
- §10 says `@media 480px`. The code ships **600/601px** — keep 600 (480 would leave 2-col rows on real phones).
- It lists `consent_at` as a field the form POSTs. It is **server-stamped**. The form sends `consent:true` + `policy_version`; the backend stamps the timestamp.
- The data-attr token: use the **full path/URL** form, **not** `{post_slug}`.
- §3 / §9 say org.nr is "KRÄVS" on BRF/Företag. **Decided otherwise (see below):** org.nr is **OPTIONAL** and never blocks a callable lead.

---

## Decisions already made (state them as decided)

- **EFX = kundtyp locked from slug + "Elektriker för X" chip (no segment toggle).** Rationale: the page already knows the visitor type; removing the toggle cuts friction, guarantees correct CRM segmentation, and is warmer.
- **org.nr is OPTIONAL**, living in the "Fler detaljer (valfritt)" disclosure; it never blocks a callable lead (Ampy's first-principles moat). The **backend flags a missing org.nr on brf/foretag deals** for booker follow-up. This resolves the stale spec's "KRÄVS" — document `org_number` as nullable. (Owner-flippable: if Ampy later wants it mandatory, `06`/`05` show the one-spot change.)
- **Breakpoint stays 600/601px.**
- **Outfit is self-hosted via @font-face** (path is a `[VERIFY]` guess — confirm on the live theme); remove the Google Fonts link only after the self-hosted face is confirmed.
- **Minimal callable lead = Namn + Telefon + Postnummer + GDPR consent** (+ empty `company_url` honeypot). Everything else is optional enrichment that must never block submit. (Privat additionally requires e-post + adress per the booking team; org segments require orgname + kontakt + telefon + epost + postnr — all enforced in `validate()`.)

---

## DO NOT

- **Do NOT rebuild or restyle the Hero 2 section.** The form is placed *inside* the existing hero; in production the Hero 2 section *is* the background. (`.aof-host` in the file is a PREVIEW-ONLY review shell — never paste it into Bricks.)
- **Do NOT change any Swedish UI copy.** Customer-facing strings are governed by Ampy's voice (`ampy-rost`). Your documentation prose is English; the UI strings stay Swedish, verbatim.
- **Do NOT add "!"** anywhere except the existing header `Få kostnadsfri rådgivning!` and its subtitle `Vår behöriga elektriker återkommer via telefon!` (both owner-mandated).
- **Do NOT make org.nr block submit.** It is optional enrichment by decision.
- **Do NOT place the element twice.** One sitewide global element + one sitewide global script. Fixed ids depend on it.
- **Do NOT use `post_slug` for `data-ampy-path`.** Use the full request path/URL via Bricks dynamic data. `post_slug` is the last segment only and breaks multi-segment + bare-root matches.
- **Do NOT let production hit PREVIEW mode.** PREVIEW shows the payload instead of POSTing and honours `?path`. It is host-derived to keep ampy.se always-false; verify, and prefer hardcoding `false` in production.
- **Do NOT edit the frontend JS/CSS to "improve" it.** 21 QA fixes are baked in; regressing them is a launch blocker. If a real frontend bug is found, fix the one bug and re-run `06`'s acceptance tests.
- **Do NOT invent values.** ENDPOINT, SOURCE_FORM, POLICY_VERSION, the font path, and the policy URL are unknowns to confirm — mark them `[VERIFY]`, don't guess.

---

## File manifest (so your cross-references are correct)

```
for-chris/README.md ................. master human handover (the one doc Chris reads first)
for-chris/GO-LIVE-CHECKLIST.md ...... actionable pre-launch checklist with acceptance criteria
for-chris/ARCHITECTURE.md ........... plain-English architecture + blast-radius / cache-bust discipline
for-ai-agents/00-START-HERE.md ...... (this file) orientation + constraints + implement order
for-ai-agents/01-bricks-implementation.md . exact step-by-step WordPress/Bricks build
for-ai-agents/02-the-form-component.md ..... the component explained (resolver, maps, per-page matrix)
for-ai-agents/03-sitemap-and-routing.md .... sitemap → routing (page inventory, how path drives the form, how to add a page)
for-ai-agents/04-php-backend.md ............ the PHP endpoint explained + how to deploy it
for-ai-agents/05-data-contract.md .......... payload ↔ ampy-dash-2 mapping, every field + enum
for-ai-agents/06-qa-and-acceptance.md ...... invariants not to regress + acceptance tests
code/ampy-offert-form.html .......... the component (assembled by the human lead — do not write)
code/ampy-lead-endpoint.php ......... PHP reference endpoint (you, the backend agent, write this)
code/ampy-dash-2-migration.sql ...... SQL migration (you write this)
code/bricks-*.{css,html,js} ......... split by the human lead — do not write
```

Now go read `02-the-form-component.md`.
