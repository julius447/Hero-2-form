# 06 — QA & Acceptance

This is the regression guard and the pre-deploy spot-check for `ampy-offert-form.html`.
It has two parts:

- **Part 1 — Invariants not to regress.** The 21 frontend fixes that are already in the shipped
  code. A previous multi-agent QA sweep found these and they are now fixed. **Do not undo them.**
  If your change touches the relevant line, re-check the invariant still holds.
- **Part 2 — Acceptance tests.** A runnable checklist that maps each critical behaviour to a
  concrete test and an expected result, with values pulled from the real code. Run this before
  every deploy. Because one global element drives ~165 pages, a single bad edit breaks every form
  at once — so spot-check 4–5 representative page types and bump the `?v=` cache-bust each release.

All line references are to the shipped `bricks/ampy-offert-form.html`. The customer-facing strings
are Swedish by design and must stay Swedish.

---

## Part 1 — Invariants not to regress (the 21 frontend fixes)

Each is stated as **invariant: what must stay true**. If you change the related code, verify the
invariant by hand.

1. **invariant: PREVIEW is host-derived, so `ampy.se` can NEVER enter preview mode.**
   `PREVIEW` is computed from `location` — true only for `file:`, `localhost`/`127.0.0.1`/`[::1]`,
   and `*.github.io`. It is never a hand-set boolean.
   Code: `var PREVIEW=(location.protocol==='file:')||/^(localhost|127\.0\.0\.1|\[::1\])$/.test(location.hostname)||/\.github\.io$/.test(location.hostname);`
   Why it matters: a forgotten `PREVIEW=true` on production would silently stop POSTing and drop
   leads on all ~165 pages. Production may additionally hardcode `false`, but never `true`.

2. **invariant: `?path=` is honoured ONLY when `PREVIEW` is true.**
   Code: `var rawPath=(PREVIEW&&qs.get('path'))?qs.get('path'):(root.getAttribute('data-ampy-path')||location.pathname);`
   Why: in production an attacker must not be able to repaint the form by appending a query string.

3. **invariant: utility roots exclude their subpaths too (not just exact match).**
   The exclude test is `p===u || p.indexOf(u+'/')===0`, so `/nyheter/min-artikel` and
   `/offert/tack` are excluded, not only `/nyheter` and `/offert`.
   Code: `if(UTIL.some(function(u){return p===u||p.indexOf(u+'/')===0;})) return {render:false,kind:'utility'};`
   `UTIL=['/offert','/kopvillkor','/integritetspolicy','/cookiepolicy','/thank-you','/nyheter','/tillganglighet','/om-oss']`.
   Note: `/eljour/*`, `/solcellsbatterier/*`, `/laddboxar/*`, `/batterilagring/*` are excluded by
   their own regexes above the UTIL check (each tests `(\/|$)`).

4. **invariant: Enter inside a text input submits the form (keyboard/SR implicit submission).**
   Only `input.inp` triggers it — selects, textarea, and the consent checkbox are intentionally
   excluded so Enter there does not fire a submit.
   Code: `if(e.key==='Enter'&&t&&t.matches&&t.matches('input.inp')){e.preventDefault();submit();return;}`

5. **invariant: the "Fler detaljer" disclosure toggles IN PLACE — it does not re-render the form.**
   Toggling flips `data-open`/`aria-expanded` and lets CSS show/hide; it does NOT call `render()`.
   This is what preserves focus and any chosen files.
   Code (toggle branch): sets `d.setAttribute('data-open',st.open)` and `a.setAttribute('aria-expanded',st.open)` — no `render()` on the happy path.
   CSS: `.aof .disc[data-open="false"] .disc-body{display:none}`.

6. **invariant: chosen image files survive a re-render.**
   Files are captured into `st.files` on `change`, and `fill()` rehydrates the `<input type=file>`
   via `DataTransfer` after any render.
   Code: change handler `st.files=Array.prototype.slice.call(f.files);` and in `fill()` the
   `DataTransfer` rebuild guarded by `try/catch`. A help note tells the user they re-pick files only
   on a form-type switch: "Byter du formulärtyp får du välja bilderna igen."

7. **invariant: text-field values survive a re-render (segment switch, error/retry).**
   `snap()` reads all `FIELDS` into `st.vals` before every render; `fill()` writes them back after.
   `FIELDS=['namn','kontakt','telefon','postnr','epost','beskriv','adress','orgname','orgnr','tj','tid']`.

8. **invariant: a tjänst value that does not exist in the newly selected segment snaps to the
   placeholder — it is never silently kept.**
   Code in `fill()`: `if(tjEl&&st.vals.tj&&tjEl.value!==st.vals.tj)tjEl.value='';`

9. **invariant: phone is normalised to E.164 `+46…`, including the no-leading-zero case.**
   `toE164`: `00…`→`+…`; `0…`→`+46…`; `46…`→`+46…`; any other leading digit→`+46…`.
   So `0701234567`→`+46701234567` and a bare `701234567`→`+46701234567`.
   Code: `function toE164(s){…if(/^00/.test(s))s='+'+s.slice(2);else if(/^0/.test(s))s='+46'+s.slice(1);else if(/^46/.test(s))s='+'+s;else if(/^\d/.test(s))s='+46'+s;return s;}`

10. **invariant: duplicate `#ampy-form-root` elements are guarded — only the first initialises, the
    rest are hidden.**
    First, `data-aof-init==='1'` short-circuits re-init. Then any extra `#ampy-form-root` nodes get
    `aria-hidden="true"`, `display:none`, and `data-aof-init=1`, plus a `console.warn`.
    Why: the global element must be placed once per page; a second copy would otherwise render a
    visibly empty/broken form box.

11. **invariant: the consent checkbox sets `aria-invalid` and exposes its error to screen readers.**
    On failed validation `gd.setAttribute('aria-invalid','true')` and its help `#aof-gdpr-h` is
    shown (`style.display='block'`); on success both are cleared. The checkbox carries
    `aria-describedby="aof-gdpr-h"`.

12. **invariant: every required field carries `aria-required="true"`.**
    `fld(...,req)` and `serviceSelect(...,req)` emit `aria-required="true"` when required. The
    privat set, the org set, and (on EFX) the `tj` select are rendered required.

13. **invariant: failed validation moves focus to the first invalid field, and announces a summary.**
    `validate()` returns `{ok,first}`; `submit()` does `if(v.first)v.first.focus()`. It also calls
    `announce('Kontrollera de markerade fälten.')` into the `aria-live="polite"` region `#aof-live`.

14. **invariant: palette tokens are scoped to `.aof` and must NOT leak to `:root`/the site.**
    Code: `.aof{--mid:#090b32;--sea:#5eb1bf; … --teal:#00a991;--ink:#1e1e1e; …}`.
    Why: the form lives on the live theme; theme variables must not be clobbered.

15. **invariant: the card has a solid base colour so it stays legible if `backdrop-filter` is
    unsupported.**
    Code: `.aof .card{… background:var(--mid); backdrop-filter:blur(30px); …}` — `--mid` (#090b32)
    is the solid fallback under the blur. Do not replace it with a transparent value.

16. **invariant: the title wraps (no forced single line) so it never overflows on narrow screens.**
    Mobile rule: `.aof .title{font-size:24px;white-space:normal;line-height:1.2}`. Keep
    `white-space:normal`.

17. **invariant: all transitions are disabled under `prefers-reduced-motion`.**
    Code: `@media (prefers-reduced-motion:reduce){.aof *,.aof *::before,.aof *::after{transition:none!important}}`.

18. **invariant: the honeypot `company_url` is present, visually hidden, and submit aborts if it is
    filled.**
    Markup: `<input type="text" class="hp" id="aof-company_url" name="company_url" tabindex="-1" autocomplete="off" aria-hidden="true">`;
    CSS `.aof .hp{position:absolute;left:-9999px; …}`; `submit()` does
    `if(hp&&hp.value)return;`. It is also carried in the payload so the server can reject too.

19. **invariant: the responsive breakpoint is 600/601px — not 480.**
    `@media (max-width:600px)` and `@media (min-width:601px)` both ship. 480 would leave two-column
    rows on real phones. The spec's §10 mention of 480 is stale.

20. **invariant: the form never crashes — render and resolve are wrapped, and any failure falls back
    to a callable lead / error card.**
    `render()` wraps `resolve()` in try/catch (falls back to a privat/oklart config), and the inner
    build is wrapped to show `errorCard()` on throw. The resolver's final `return` is a privat/ask
    fallback so an unknown path still renders a working form.

21. **invariant: double-submit is guarded.**
    `submit()` opens with `if(st.sending)return;`, sets `st.sending=true`, disables the button and
    changes its label to "Skickar…", and a 10s `AbortController` timeout resets state on failure.

Additional structural guards that must also survive (do not regress):

- **Idempotent init:** `data-aof-init` set before any DOM work so a re-fired script does not
  double-initialise.
- **XSS-safe output:** dynamic strings pass through `esc()`; the resolver lowercases, strips
  origin/query/hash, collapses slashes, and matches against a character whitelist
  `[a-z0-9åäö-]` in the slug regexes.
- **Self-hosted Outfit `@font-face`** is present; the Google Fonts `<link>` is PREVIEW-ONLY and must
  be removed only after the self-hosted path is confirmed (`[VERIFY]` the woff2 path on the live
  theme — see decisions doc).

---

## Part 2 — Acceptance tests

Run these before every deploy. In **preview** (file://, localhost, *.github.io) you can drive any
page type with `?path=…` and the form will show the JSON payload instead of POSTing — that is the
fastest way to run the resolver and payload tables below. On production, spot-check the live URLs.

### 2.1 Resolver matrix — one row per page type

For each input path, load `?path=<path>` in preview and confirm the rendered form matches. "Segment
toggle" = the Privat/BRF/Företag radio group is visible. "Chip" = the green confirmation chip text.
"kundtyp" = the segment the form starts in.

| # | Input path | Segment toggle? | Chip shown | tjänst field | Locked? | Start kundtyp | vertical |
|---|---|---|---|---|---|---|---|
| 1 | `/elservice/elcentral` | yes | `Gäller: Elcentral` | hidden (locked) | locked tjänst | privat | service |
| 2 | `/elservice/golvvarme` | yes | `Gäller: Golvvärme` | hidden (locked) | locked tjänst | privat | service |
| 3 | `/elservice` (pillar) | yes | none | "Vad gäller arbetet?" select (enrich) | ask | privat | service |
| 4 | `/villor` (EFX) | **no** | `Elektriker för villa` | "Vad gäller arbetet?" in main view | kundtyp locked | privat | service |
| 5 | `/radhus` (EFX) | **no** | `Elektriker för radhus` | main view | kundtyp locked | privat | service |
| 6 | `/restauranger` (EFX) | **no** | `Elektriker för restaurang` | main view | kundtyp locked | foretag | foretag_brf |
| 7 | `/bostadsrattsforening` (EFX) | **no** | `Elektriker för bostadsrättsförening` | main view | kundtyp locked | brf | foretag_brf |
| 8 | `/kommuner` (EFX) | **no** | `Elektriker för kommun` | main view | kundtyp locked | foretag | foretag_brf |
| 9 | `/laddbox/nacka` | yes | `Laddbox i Nacka` | hidden (locked) | locked tjänst | privat | laddbox |
| 10 | `/elinstallation/taby` | yes | `Elinstallation i Täby` | hidden (locked) | locked tjänst | privat | service |
| 11 | `/elektriker/sodertalje` | yes | none (ort in eyebrow only) | "Vad gäller arbetet?" (enrich) | ask | privat | service |
| 12 | `/elektriker` (pillar) | yes | none | ask (enrich) | privat | service |
| 13 | `/laddbox` (pillar) | yes | none | hidden (locked "Laddbox") | locked tjänst | privat | laddbox |
| 14 | `/elinstallation` (pillar) | yes | none | hidden (locked "Elinstallation") | locked tjänst | privat | service |
| 15 | `/nagot-helt-okant` (fallback) | yes | none | ask (enrich) | privat | oklart |

Expected-value sources:
- Chip text is built in `tjanstBlock()`: EFX → `'Elektriker för '+cfg.forLabel`; ort → `cfg.ortWord+' i '+cfg.ort`; locked service → `'Gäller: '+cfg.tjanst`.
- `forLabel` values come from `FORLABEL` (e.g. `villor:'villa'`, `restauranger:'restaurang'`, `kommuner:'kommun'`, `bostadsrattsforening:'bostadsrättsförening'`).
- Ort display names come from `SV_ORT` (e.g. `nacka:'Nacka'`, `taby:'Täby'`, `sodertalje:'Södertälje'`); unknown orts are title-cased by `ortName`.
- EFX kundtyp comes from `EFX[<slug>].kundtyp`; all 13 verticals are `foretag_brf` except none — note `villor`/`radhus` use `vertical:'service'` while staying kundtyp `privat`.

Case/slash robustness (run a few): `/ELSERVICE/Elcentral`, `//villor//`, `/villor?utm=x#frag` must
all resolve identically to their clean form (resolver lowercases, collapses `//`, trims slashes,
strips query/hash).

### 2.2 Page-exclusion tests

For each, confirm NO form renders. Excluded pages render either the eljour phone card or the generic
"Formuläret visas inte på den här sidtypen." card.

| Input path | Expected | Card |
|---|---|---|
| `/eljour/stockholm` | excluded | eljour card: "Akut elfel? Ring oss direkt på 010-265 79 79." |
| `/solcellsbatterier/nagon-produkt` | excluded | generic noform card |
| `/laddboxar/nagon-produkt` | excluded | generic noform card |
| `/batterilagring` | excluded | generic noform card |
| `/nyheter/min-artikel` | excluded (subpath) | generic noform card |
| `/offert/tack` | excluded (subpath) | generic noform card |
| `/integritetspolicy` | excluded | generic noform card |
| `/om-oss` | excluded | generic noform card |

Must-still-render counter-cases (these are NOT excluded — confirm a form appears):
- `/offertkalkyl` — renders (it is not in UTIL and `/offert` only excludes `/offert` exact + `/offert/…`; `offertkalkyl` ≠ `offert` and does not start with `offert/`). Resolves to the fallback privat/oklart form.
- `/nyheterbrev` (hypothetical) — would render for the same reason; only `/nyheter` and `/nyheter/…` are excluded.

### 2.3 Accessibility tests

| Test | Steps | Expected |
|---|---|---|
| Enter submits | Focus the Namn input, type a value, press Enter | Form attempts submit (validation runs); focus does not leave to nowhere |
| Enter in select/textarea does NOT submit | Focus the Tidsram select or Beskriv textarea, press Enter | No submit fires |
| Consent SR error | Leave consent unchecked, submit | `#aof-gdpr` gets `aria-invalid="true"`; `#aof-gdpr-h` ("Vi behöver ditt godkännande för att få kontakta dig.") becomes visible; live region announces "Kontrollera de markerade fälten." |
| Focus first invalid | Submit empty privat form | Focus lands on the first invalid field (Namn); each invalid `.fld` gets `.err` and `aria-invalid="true"` |
| Segment radiogroup keyboard | Focus a segment radio (privat/brf/foretag), press Arrow keys / Home / End | Selection moves, `aria-checked` and `tabindex` update, focus follows the checked radio |
| Reduced motion | OS "reduce motion" on, load form | No transitions animate |
| Required semantics | Inspect required inputs | Each carries `aria-required="true"`; each input has `aria-describedby` pointing to its `-h` help |
| Success focus | Complete and submit | `.done h2` ("Tack, vi har din förfrågan") receives focus; live region announces the success line |

### 2.4 Resilience tests

| Test | Steps | Expected |
|---|---|---|
| Network failure | On a non-preview host, force the POST to fail (offline / 500) | Error card renders: "Något gick fel … ring oss på 010-265 79 79." with a "Försök igen" button; `st.sending` reset |
| Timeout | Make endpoint hang >10s | `AbortController` fires at 10000ms → error card |
| Double-submit | Click "Boka rådgivning" twice fast | Second click is a no-op (`if(st.sending)return;`); button shows "Skickar…" and is disabled |
| Retry | On error card, click "Försök igen" | `st.error=false`, form re-renders with values preserved |
| Duplicate element | Place two `#ampy-form-root` on one page | Only the first renders; extras hidden (`display:none`, `aria-hidden`); `console.warn` logged |
| Honeypot filled | Set `#aof-company_url` to any value, submit | `submit()` returns early; no POST |
| Resolver throw | (Defensive) any resolver exception | Falls back to a privat/oklart callable form, never a blank/crash |

### 2.5 Payload tests — per page type

In preview, complete the form and read the shown JSON (`buildPayload()` output). Confirm the
load-bearing fields. `full_name` = Namn on privat, **Kontaktperson on org**. `phone_e164` always
`+46…`. `postal_code` digits only. `company_url` empty.

| Page (`?path=`) | kundtyp | vertical | tjanst_intresse | org_number | org_name | full_name source |
|---|---|---|---|---|---|---|
| `/elservice/elcentral` | privat | service | `"Elcentral"` (locked) | null | null | Namn |
| `/villor` | privat | service | selected value or null | null | null | Namn |
| `/laddbox/nacka` | privat | laddbox | `"Laddbox"` (locked) | null | null | Namn |
| `/restauranger` | foretag | foretag_brf | selected value or null | digits or **null (optional)** | Företagsnamn | Kontaktperson |
| `/bostadsrattsforening` | brf | foretag_brf | selected value or null | digits or **null (optional)** | Föreningens namn | Kontaktperson |
| `/kommuner` (org-label) | foretag | foretag_brf | selected or null | digits or null | "Förvaltning eller enhet" value | Kontaktperson |
| `/elektriker` (fallback-ish pillar) | privat | service | selected or null | null | null | Namn |
| `/heltokant` | privat | oklart | selected or null | null | null | Namn |

Notes on expected payload values (from `buildPayload()`):
- `full_name` = `st.seg==='privat' ? val('namn') : val('kontakt')`.
- `tjanst_intresse` = locked → `cfg.tjanst`; else the select value; empty → `null`.
- `org_number` = only when `st.seg!=='privat'` AND filled → digits; otherwise `null`. **org.nr is
  OPTIONAL and never blocks submit** — the backend flags a missing org.nr on brf/foretag deals for
  booker follow-up (owner-flippable; see data-contract / decisions docs).
- `org_name` = `st.seg!=='privat' ? val('orgname') : null`.
- `bradska` = the Tidsram dropdown mapped via `BRADSKA` (`Inom 24 timmar`→`24h`, `Inom 72 timmar`→`72h`, `Om 1-2 veckor`→`1_2v`, `Flexibelt`→`flexibel`); unset → `null`.
- `kallsida` = the normalised resolver `source` path.
- `source` = `'bricks'`; `source_form` = `SOURCE_FORM` (currently `3` — `[VERIFY]` with the dash team).
- `consent` = `true`; `policy_version` = `POLICY_VERSION` (currently `'ampy-privacy-2026-06'` — `[VERIFY]`). `consent_at` is NOT in the payload; the server stamps it.
- `bilder_count` = number of selected files (images go via a separate multipart channel; this JSON only carries the count).

### 2.6 Required-field rules (validation) per segment

Confirm which fields block submit. From `validate()`:

- **Privat** required: `namn`, `telefon`, `epost`, `adress`, `postnr` (+ consent).
- **BRF / Företag** required: `orgname`, `kontakt`, `telefon`, `epost`, `postnr` (+ consent).
- **EFX (any)** additionally requires `tj` ("Vad gäller arbetet?") because it shows in the main view: `if(st.cfg&&st.cfg.forLabel)ids.push('tj');`
- **Everything else** (org.nr, beskrivning, adress on org, bilder, tidsram) is optional enrichment — must NEVER block submit.

Field-level validation expected:
- `postnr`: must match `^\d{3}\s?\d{2}$` (five digits, optional space) — error "Postnummer ska vara fem siffror."
- `telefon`: digit count after stripping must be ≥7 — error "Skriv ett telefonnummer vi kan nå dig på."
- `epost`: must match `^[^@\s]+@[^@\s]+\.[^@\s]+$` — error "Skriv en giltig e-postadress."
- `tj` (EFX): non-empty — error "Välj vad det gäller."
- others: length ≥2.

### 2.7 Pre-deploy spot-check (the minimum before flipping `?v=`)

Because the global element drives every page, run §2.1 rows on at least these 5 representative
live URLs after deploy, on **desktop and mobile**:

1. one `/elservice/<slug>` (e.g. `/elservice/elcentral`) — locked tjänst chip
2. one EFX företag (e.g. `/restauranger`) — no segment toggle, org fields, "Elektriker för …" chip
3. one EFX privat (e.g. `/villor`) — no segment toggle, privat fields
4. one `/laddbox/<ort>` (e.g. `/laddbox/nacka`) — locked "Laddbox" + ort chip
5. one pillar/fallback (e.g. `/elektriker`) — segment toggle + ask

Then confirm: cache-bust `?v=` was bumped; on production `PREVIEW` resolves to `false` (no payload
panel, real POST); a test lead lands in `ampy-dash-2` (two rows) with `consent_at` stamped server-side.
