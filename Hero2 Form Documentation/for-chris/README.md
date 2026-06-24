# Ampy "Hero 2" offertformulär — handover for Chris

This is the master handover. Read this first. Everything else in the package hangs off the map at the bottom.

**Audience:** you (Chris) and the AI agents you use to implement it in WordPress + Bricks.
**Language note:** this documentation is in English. The form's UI text and code comments are Swedish on purpose — they are customer-facing copy governed by Ampy's voice. **Do not translate the Swedish strings.**

---

## 1. What this is, in one paragraph

It is the single quote-request ("offert") form that appears on roughly **165 of Ampy's pages** — every service page, every "Elektriker för X" page, and every town (ort) page. It replaces a leaky 5-step wizard. The whole thing is **one self-contained file**: HTML markup + scoped CSS + one vanilla-JavaScript function. No framework, no build step, no dependencies beyond a web font. It renders *differently per page* — the right heading, the right "what kind of job" options, the right customer type — but you only build and maintain it **once**. The page's URL is what tells the form which variant to show.

The file: `code/ampy-offert-form.html` (the human lead assembles the final version of this; the original lives at `bricks/ampy-offert-form.html`).

### Why it exists

The old form was a 5-step wizard. Multi-step forms leak — every step is a place to drop off. Ampy's first-principles decision was: **a callable lead needs only four things** — name, phone, postcode, and GDPR consent. Everything else (email, address, job description, photos, timeframe, org. number) is *optional enrichment that must never block submit*. One screen, minimal required fields, everything else tucked behind an optional "Fler detaljer" (more details) disclosure. That is the whole philosophy, and it is the thing not to regress.

---

## 2. The architecture in plain English

```
  ONE source file
        |
        +--(1) <style> block ........ goes into Bricks Custom CSS
        +--(2) <div class="aof"> .... goes into the Hero 2 section as a Bricks element
        +--(3) <script> ............. goes into ONE sitewide Bricks Code element
        |
   At runtime the script reads the page URL ----> resolve() ----> picks the variant
```

Three ideas carry the whole design:

1. **One file, three Bricks parts.** When you paste it into Bricks it splits into three: the CSS goes to Custom CSS, the `<div>` markup goes inside the Hero 2 section, and the `<script>` goes into one global Code element that runs on every page. Full step-by-step instructions are in `for-ai-agents/01-bricks-implementation.md`.

2. **A URL-path resolver = zero per-page editing.** Inside the script there is a function called `resolve(path)`. It takes the current page's path (e.g. `/laddbox/nacka` or `/elservice/elcentral` or `/villor`) and returns the config for that page — which heading chip to show, which customer type, which job options, whether to lock the service. Because one regular expression matches *all* town pages, a 51st town inherits the correct form on day one with no setup. Add a new town/service/EFX page and it just works. How the routing is wired, and how to add pages, is in `for-ai-agents/03-sitemap-and-routing.md`.

3. **It never crashes, and it never shows on the wrong page.** Excluded sections (`/eljour/*`, the battery/laddbox/solar *product* pages, and utility pages) render no form. Anything unrecognised falls through to a safe default that still produces a callable lead. The component explained end to end is in `for-ai-agents/02-the-form-component.md`.

**Blast radius — read this before any edit.** Because one global element drives ~165 pages, a single bad edit breaks *every* form at once. So: bump the cache-bust `?v=` on every release, and spot-check 4–5 representative page types (one town page, one `/elservice/<slug>`, one company EFX, one private EFX, one pillar page) before you deploy. This discipline is spelled out in `for-chris/ARCHITECTURE.md`.

---

## 3. What is DONE

The **frontend is finished and production-hardened.**

- **Design is locked.** The glass-card design (the teal/midnight Hero 2 card) is signed off. Do not redesign it.
- **The resolver is complete** — 22 service slugs, 13 EFX root slugs, the three town regexes, the pillar pages, the exclude list, and a safe fallback. All grounded in Ampy's real sitemap.
- **A multi-agent QA sweep found 38 issues; all 21 frontend items are fixed in the shipped code.** Among them: the PREVIEW flag is derived from the host so production can never accidentally fall into preview mode; utility *subpaths* are excluded; Enter-to-submit works for keyboard/screen-reader users; the "more details" toggle flips in place so focus and already-chosen files survive; phone numbers are normalised to `+46`; a duplicate-element guard hides accidental second copies; consent has proper `aria-invalid`/`aria-required`; the card has a solid base colour so it still looks right where `backdrop-filter` is unsupported; reduced-motion is honoured.

The remaining 17 of the 38 issues are **backend** work — see the next section.

---

## 4. What YOU / the dev still must do before go-live

These are the launch gates. The actionable, acceptance-criteria version is `for-chris/GO-LIVE-CHECKLIST.md` — treat that as the checklist; this is the orientation.

1. **Build the PHP lead endpoint FIRST.** This is the critical path. Right now `'bricks'` is recognised as a source but **nothing receives the POST** — until the endpoint exists, every submitted lead is dropped. Build it before anything else flips live. The endpoint is explained in `for-ai-agents/04-php-backend.md`; the reference implementation the backend agent writes goes to `code/ampy-lead-endpoint.php`. It must:
   - do the two-row write to `ampy-dash-2` (a `customers` row deduped/UPSERTed on `phone_e164`, and a `deals` row linked to it),
   - server-stamp `consent_at` and store `policy_version`,
   - verify the honeypot (`company_url`) is empty and reject if filled,
   - validate/sanitise input, rate-limit, and return 2xx **only** on a successful write.
   - The exact payload it must consume, field by field, is in `for-ai-agents/05-data-contract.md`.

2. **Flip PREVIEW to false only after the endpoint is live.** In preview mode the form shows the payload instead of POSTing. Production (`ampy.se`) is already forced to `false` by host detection, but confirm it on the live page.

3. **Self-host the Outfit font.** The file currently loads Outfit from Google Fonts for preview convenience and has a self-hosted `@font-face` whose path is a **[VERIFY] guess** (`/wp-content/themes/ampy/fonts/Outfit.woff2`). Confirm the real path on the live theme, then remove the Google Fonts `<link>`.

4. **Wire `data-ampy-path` via Bricks dynamic data.** The form's `<div>` carries `data-ampy-path`, which must be set to the **full request path/URL** through Bricks dynamic data — *not* the post slug (slug is only the last segment and would break multi-segment and bare-root matches). `location.pathname` is only an emergency fallback. Details in `for-ai-agents/01-bricks-implementation.md`.

5. **Add `consent_at` and `org_name` columns** to `ampy-dash-2`. The SQL migration the backend agent writes goes to `code/ampy-dash-2-migration.sql`.

6. **Confirm three open values** (currently placeholders in the code, marked TODO):
   - `SOURCE_FORM` — currently `3`; confirm the right code with the dashboard team.
   - `POLICY_VERSION` — currently `ampy-privacy-2026-06`; confirm.
   - The integritetspolicy URL — currently `https://ampy.se/integritetspolicy`; confirm it is exact.

7. **(Not the form, noted for completeness.)** Image upload is a *separate* multipart channel keyed by `lead_id`; it is not part of this JSON. Until it is built, the payload only carries `bilder_count` as a signal. See `for-ai-agents/04-php-backend.md`.

---

## 5. The org. number decision (stated plainly)

**org. number is OPTIONAL.** It lives in the optional "Fler detaljer" disclosure and **never blocks a callable lead** — that is the whole first-principles point of the form. For BRF and company leads where org. number is missing, the **backend flags the deal** so a booker can follow up. This supersedes the old spec, which said org. number was required ("KRÄVS"); document `org_number` as **nullable**.

**This is owner-flippable.** If Ampy later decides org. number must be mandatory for BRF/company, the change is a single-spot edit. The exact one place to change it is documented in `for-ai-agents/02-the-form-component.md` (validation) and `for-ai-agents/05-data-contract.md` (the field). Do not scatter the requirement across multiple files.

---

## 6. A note on honesty (it applies to these docs too)

Ampy's voice is candour: plain Swedish, no exclamation marks (the one owner-mandated exception is the header "Få kostnadsfri rådgivning!" and its subtitle), no superlatives, and never asserting unverified claims like "5.0 på Google", "1000+ kunder", or national/"hela Sverige" coverage. The same honesty applies to this documentation: where something could not be confirmed from the code it is marked **[VERIFY]** or **[GAP]** rather than guessed. If you see those tags, treat them as open questions, not facts.

---

## 7. How to use this package (the file map)

**If you are reading to understand and plan — start here, then:**

| File | What it gives you |
|---|---|
| `for-chris/README.md` | This file — the orientation. |
| `for-chris/ARCHITECTURE.md` | Plain-English architecture + the blast-radius / cache-bust discipline. |
| `for-chris/GO-LIVE-CHECKLIST.md` | The actionable pre-launch checklist with acceptance criteria. This is the launch gate. |

**If you are handing the build to an AI agent — point it at `for-ai-agents/`:**

| File | What it covers |
|---|---|
| `for-ai-agents/00-START-HERE.md` | Orientation, hard constraints, and the order to implement in. The agent reads this first. |
| `for-ai-agents/01-bricks-implementation.md` | Exact step-by-step WordPress/Bricks build (the three parts, dynamic data wiring). |
| `for-ai-agents/02-the-form-component.md` | The component explained: the resolver, the maps, the per-page matrix. |
| `for-ai-agents/03-sitemap-and-routing.md` | The sitemap → routing section: page inventory, how the path drives the form, how to add an ort/service/EFX. |
| `for-ai-agents/04-php-backend.md` | The PHP endpoint explained and how to deploy it. |
| `for-ai-agents/05-data-contract.md` | Payload ↔ `ampy-dash-2` mapping — every field and enum. |
| `for-ai-agents/06-qa-and-acceptance.md` | Invariants not to regress + acceptance tests. |

**The artifacts (the code itself) live in `code/`:**

| File | Who writes it |
|---|---|
| `code/ampy-offert-form.html` | Assembled by the human lead — do not write it. |
| `code/bricks-*.{css,html,js}` | The three split parts, also split by the human lead. |
| `code/ampy-lead-endpoint.php` | The backend agent writes this (the PHP endpoint). |
| `code/ampy-dash-2-migration.sql` | The backend agent writes this (the SQL migration). |

**Suggested path through it:** this README → `ARCHITECTURE.md` → `GO-LIVE-CHECKLIST.md`. Then for the build, hand `for-ai-agents/00-START-HERE.md` to your AI implementer and let it work through `01`–`06` in order, backend (`04`/`05`) first since the endpoint is the critical path.

---

## 8. Glossary

- **Bricks global element** — a Bricks builder element defined once and reused across many pages. Edit it in one place and the change propagates everywhere. The form's `<script>` lives in one such global Code element; this is why a single edit touches all ~165 pages (the blast radius).
- **resolver** — the in-file `resolve(path)` function that reads the page URL and returns the correct form configuration (heading, customer type, job options, locks). It is what makes "one file, many variants, zero per-page editing" possible.
- **EFX (Elektriker För X)** — Ampy's "Electrician for X" pages (villor, radhus, restauranger, hotell, kontor, etc.). On these pages the customer type is already known from the URL, so the form **locks** it (no Privat/BRF/Företag toggle) and shows a welcoming "Elektriker för X" chip.
- **candour** — Ampy's house voice: honest, plain Swedish; no exclamation marks (bar the one mandated header), no superlatives, no unverifiable claims. The moat — it governs every customer-facing string.
- **ampy-dash-2** — Ampy's CRM and the destination for every lead. One submission becomes two rows: a `customers` row (deduped on phone) and a linked `deals` row. The PHP endpoint writes both.
