# 01 — Bricks implementation (exact build steps)

> **Audience:** an AI agent (or a careful human) building the Ampy "Hero 2" offert
> form into WordPress + the Bricks builder.
> **Source of truth:** the shipped component
> `code/ampy-offert-form.html` (mirror of `bricks/ampy-offert-form.html`). Where this
> doc and the spec (`FUNKTIONALITET.md`) disagree, **the code wins**.
> **Customer-facing strings stay Swedish.** Do not translate UI copy. This prose is English.

---

## 0. What you are building (one paragraph)

ONE self-contained component renders the offert form on ~165 pages. It has three
parts that go into three different Bricks slots:

1. a scoped `<style>` block (every selector prefixed `.aof`) → **page/global Custom CSS**
2. a one-line markup div `<div class="aof" data-ampy-path="{{post_url}}"></div>` → a
   **Code element inside the Hero 2 section**
3. a vanilla-JS IIFE `<script>` → **ONE sitewide global Code element**

The script reads the page URL from `data-ampy-path` (Bricks dynamic data), runs an
in-file `resolve(path)` function, and renders the correct form variant per page — no
per-page setup. A single bad edit breaks every form at once, so cache-bust `?v=` per
release and spot-check 4–5 page types before deploying.

**Implement in this order:** Step 1 → 2 → 3 → 4 → 5 → (6 self-host font) → 7 → 8.
Steps 1–5 get a working form. Step 6 is a polish/perf step. Steps 7–8 are rollout discipline.

---

## Step 1 — Create the global CSS class `.aof`

The CSS and the markup both hang off the class `.aof`. Register it as a Bricks **global
class** so the form element carries it and you have one named place to attach styles.

**Do:**
1. Bricks editor → open any page that has the Hero 2 section (e.g. `/elservice/elcentral`).
2. Add (or select) the Code element you will create in Step 3.
3. In the element's **Style → CSS classes** field, type `aof` and press Enter to create
   the global class if it does not exist.

> Note: the form's *root* markup already has `class="aof"` hard-coded (Step 3), so the
> class is applied even if Bricks does not register it as a managed global class. Registering
> it as a global class is the clean way to keep all `.aof` rules in one bucket, but the
> form will still resolve and style correctly as long as the literal `class="aof"` is present.

**Verify:** in the Bricks structure panel the element shows the `aof` class chip; in the
browser DevTools the root `<div>` has `class="aof"`.

---

## Step 2 — Paste the `<style>` block into Custom CSS

The whole style block is scoped under `.aof` (and `.aof-host`, which you do **not** use —
see below). Because every rule is prefixed, it is **safe sitewide** and will not leak into
`:root` or collide with theme styles. The palette tokens are declared on `.aof{…}`, not on
`:root`, on purpose (line 35 of the source: *"Palett-tokens scopade till komponenten (läcker
EJ till :root/sajten)"*).

**Do:**
1. Bricks → **Settings → Custom CSS** (global, applies sitewide) — or the page-level Custom
   CSS if you are piloting on one page first. Global is the production target.
2. Paste the **entire** contents of `code/bricks-form.css` (this is the `<style>`…`</style>`
   body from the source file, lines 31–127, without the `<style>` tags).
3. **Two preview-only rules you must handle:**
   - `.aof-host{…}` (source line 37) is the *review shell* — the dark page background used
     when the file is opened standalone. In production the **Hero 2 section IS the
     background**, so `.aof-host` is harmless (no element uses it) but you may delete it to
     avoid confusion. The source flags it: *"klistra EJ in i Bricks"* (do not paste into Bricks).
   - `@font-face` for Outfit (source line 31) points at a **guessed** path —
     `/wp-content/themes/ampy/fonts/Outfit.woff2`. Leave it for now; you confirm/fix it in
     **Step 6**. Until then the Google Fonts `<link>` (Step, handled in 3/6) supplies Outfit.

**Do NOT paste** the `<link rel="preconnect">` / Google Fonts `<link>` tags here — those are
HTML `<head>` links, handled in Step 6.

**Acceptance check:** after saving, load a page with the form. The card has the dark
midnight→sea gradient (`.aof .card::before`), inputs are white pills with 14px radius, the
primary button is the green neon gradient. If the card is unstyled (plain box), the CSS did
not load on that page — confirm you used **global** Custom CSS, not a page scope that the
target page does not inherit.

---

## Step 3 — Create the form markup (Code element inside Hero 2)

This is the **only** per-page-visible element. It is exactly one line.

**Do:**
1. In the Hero 2 section, add a Bricks **Code** element (or HTML element) where the form
   should appear.
2. Paste exactly this (this is `code/bricks-form.html`):

   ```html
   <div class="aof" data-ampy-path="{{post_url}}"></div>
   ```

3. Bind `data-ampy-path` via **Bricks DYNAMIC DATA** so it resolves to the **full request
   path/URL** of the current page. In Bricks you insert the dynamic tag by typing `{` in the
   attribute value and picking the URL token (commonly rendered as `{{post_url}}` /
   `{post_url}`). Confirm in the rendered HTML that it outputs a path the resolver can read,
   e.g. `data-ampy-path="https://ampy.se/elservice/elcentral"` or `/elservice/elcentral`.

**Why `{{post_url}}` and NOT `post_slug`:** `post_slug` is **only the last URL segment**
(`elcentral`), which would break:
- multi-segment matches — the resolver matches `/elservice/<slug>`, `/laddbox/<ort>`,
  `/elinstallation/<ort>`, `/elektriker/<ort>` (it needs the **prefix**, not just `elcentral`);
- the EXCLUDE rules (`/eljour/*`, `/solcellsbatterier/*`, etc.) which match on the leading
  path segment;
- the bare-root pillar pages (`/elektriker`, `/laddbox`, `/elinstallation`, `/elservice`),
  which have no trailing slug at all.

The resolver normalises whatever it gets — it strips the origin, query, and hash, lowercases,
collapses repeated slashes, and re-anchors to a leading `/` (source lines 179–181):

```js
p=String(p||'').replace(/^https?:\/\/[^/]+/,'').replace(/[?#].*$/,'')
  .toLowerCase().replace(/\/{2,}/g,'/').replace(/^\/+|\/+$/g,'');
p='/'+p;
```

So either a full URL (`https://ampy.se/elservice/elcentral`) **or** a path (`/elservice/elcentral`)
works — both reduce to `/elservice/elcentral`. Give it the **full path/URL**, never the slug.

**Fallback to `location.pathname`:** the script already falls back if the attribute is empty
(source line 210):

```js
var rawPath=(PREVIEW&&qs.get('path'))?qs.get('path'):(root.getAttribute('data-ampy-path')||location.pathname);
```

So if the dynamic-data binding renders empty, the form still resolves from the browser's
`location.pathname`. **This is a safety net, not the plan** — `location.pathname` runs client-side
and on some setups can differ from the canonical path (trailing-slash, language prefixes), so
the dynamic-data binding is mandatory and the fallback is "nödfallback" (emergency only), per the
source comment on line 14.

> `?path=…` override: honoured **only** when `PREVIEW===true` (line 210 + the host-derived
> PREVIEW on line 144). On production (`ampy.se`) it is ignored — you cannot spoof the page
> type via query string. Good.

**Acceptance check:** on `/elservice/elcentral` the form shows a green chip reading
**"Gäller: Elcentral"** with the service locked; on `/elektriker/nacka` it shows the segment
toggle (Privat/BRF/Företag) plus the **"Elektriker i Nacka"** chip; on `/eljour/...` no form
renders (you get the "Akut elfel? Ring oss…" no-form card). If every page shows the same
generic form, the dynamic-data binding is not resolving — inspect the rendered
`data-ampy-path` attribute.

---

## Step 4 — Create ONE sitewide global Code element for the `<script>`

The script is the engine. It must exist on every page that has the form, exactly **once**.
The cleanest way in Bricks is a **global Code element** placed in the footer/template that
loads sitewide (e.g. the global footer template, or a header/footer "code" template applied
to all pages).

**Do:**
1. Create a Bricks **global element** (or a footer template element) of type **Code**.
2. Paste the contents of `code/bricks-form.js` — this is the `<script>`…`</script>` body
   from the source file (lines 136–327), **without** the `<script>` tags (Bricks Code
   elements that execute JS wrap it for you; if your Code element requires raw output, keep
   the `<script>` tags — match your Bricks Code-element mode). The IIFE runs immediately and
   is self-contained.
3. Ensure this element renders on **all** form-bearing pages (footer/global scope), and is
   **not** also dropped per-page (that would duplicate the script — see the duplicate guard,
   Step 7).

**Acceptance check:** open DevTools console on a form page. You should see no errors. If you
deliberately place the markup div twice, you should see the warning
`[aof] dubbel #ampy-form-root dold …` and only one form rendered (the guard, source lines
203–208).

> Note on `#ampy-form-root`: the standalone file's root div uses `id="ampy-form-root"`
> (source line 132) and the script looks it up by that id (line 201). The production
> markup in Step 3 uses `class="aof"` with no id shown. **[VERIFY]** Confirm the production
> root carries `id="ampy-form-root"` — the script's `document.getElementById('ampy-form-root')`
> (line 201) returns null without it and the form will not initialise. If the human lead's
> split (`code/bricks-form.html`) omits the id, add it:
> `<div class="aof" id="ampy-form-root" data-ampy-path="{{post_url}}"></div>`.

---

## Step 5 — Set the dev constants (top of the script)

Five constants live at the top of the IIFE (source lines 138–145). Set them for production
before go-live.

| Constant | Source line | Default in code | Set to | Notes |
|---|---|---|---|---|
| `ENDPOINT` | 138 | `'/wp-json/ampy/v1/lead'` | the real lead route | Must accept the JSON payload (see `05-data-contract.md` / `04-php-backend.md`). |
| `SOURCE_FORM` | 139 | `3` | **[VERIFY]** confirm with dash team | Spec §9.8: 1=Kontakta oss, 3=Multi-steg, possibly a new "hero-offert" value. POSTed as `source_form`. |
| `PREVIEW` | 144 | host-derived | leave host-derived **or** hardcode `false` | Host-derive already forces `false` on `ampy.se`; only `file://`, `localhost`/`127.0.0.1`, `*.github.io` are preview. Safe as-is; you may hardcode `false` in prod. |
| `POLICY_VERSION` | 145 | `'ampy-privacy-2026-06'` | **[VERIFY]** confirm current version | POSTed as `policy_version`; the server stamps `consent_at`. |
| (integritetspolicy URL) | 242 | `https://ampy.se/integritetspolicy` | **[VERIFY]** confirm exact URL | Hard-coded in the consent label, not a constant — edit inline if the URL differs (spec §9.9). |

**Do NOT** set `PREVIEW=true` on production. The whole point of the host-derive (source
comment lines 140–143) is that *"produktionsdomänen ampy.se ALDRIG kan hamna i preview"* — a
forgotten boolean must not silently drop leads on 165 pages.

**Acceptance check:** on a staging host that is NOT `localhost`/`*.github.io`, submitting a
valid form fires a real `POST` to `ENDPOINT` (watch the Network tab) and shows the
"Tack, vi har din förfrågan" card on a 2xx. In preview (`file://`/`localhost`/`*.github.io`)
it shows the JSON payload instead of POSTing (source line 250 + the `if(PREVIEW){st.done=true…}`
branch on line 296).

---

## Step 6 — Self-host Outfit and remove the Google Fonts link

The component renders in `'Outfit', system-ui, sans-serif` (source line 39). In the
standalone file Outfit comes from Google Fonts (lines 26–29) **and** a self-hosted
`@font-face` (line 31). Production should self-host and drop the Google link (source
comment line 22).

**Do:**
1. Confirm the real Outfit `woff2` path on the live Ampy theme. The code's path is a
   **[VERIFY] guess**: `/wp-content/themes/ampy/fonts/Outfit.woff2` (source line 32 flags this
   explicitly: *"Bekräfta woff2-sökvägen på Ampys tema (gissning ovan)"*).
2. The shipped `@font-face` declares a **variable** font (weights 300–600,
   `format('woff2-variations')`). Ship **either** that single variable file **or** one
   `@font-face` per static weight (300/400/500/600) — match whatever file the theme actually has.
3. Fix the path in the `@font-face` rule you pasted in Step 2.
4. **Only after** the self-hosted face renders correctly, remove the Google Fonts tags
   (`<link rel="preconnect">` ×2 and the `css2?family=Outfit…` `<link>`, source lines 26–29).
   These live in the page `<head>`; in Bricks remove them from wherever the human lead placed
   them (theme header, or a head-code snippet). Do not remove them before the self-hosted face
   is confirmed, or the font falls back to `system-ui`.

**Acceptance check:** with the Google link removed and DevTools **Network** filtered to Font,
you see the self-hosted `Outfit.woff2` (or the static weights) load with **200**, and the
form's headings/inputs render in Outfit (rounded geometric sans), not the OS default. The
title "Få kostnadsfri rådgivning!" should look identical to the preview.

---

## Step 7 — Place the element ONCE per page (duplicate guard explained)

The markup div (Step 3) goes in the Hero 2 section **once per page**. The script (Step 4) is
**global/sitewide, placed once**. Do not also add the script per-page.

The component defends itself two ways (source lines 203–208):

```js
if(root.getAttribute('data-aof-init')==='1') return;          // re-run guard
root.setAttribute('data-aof-init','1');
var _dupes=document.querySelectorAll('#ampy-form-root');
if(_dupes.length>1){ /* hide every extra; warn in console */ }
```

- **Re-run guard:** if the script runs twice against the same root, the second run bails.
- **Duplicate-root guard:** if `#ampy-form-root` appears more than once on the page, the
  extras are `display:none` + `aria-hidden` (so you never get a visibly empty broken card),
  and a console warning fires: *"dubbel #ampy-form-root dold — placera det globala elementet
  en gång per sida"*.

This is a safety net, **not** a licence to drop duplicates. A page should have exactly one
markup div and inherit one global script.

**Acceptance check:** view-source / DevTools on a form page shows exactly one element with
`id="ampy-form-root"` (or one `.aof` form root) and one copy of the script. No
`[aof] dubbel …` warning in the console.

---

## Step 8 — Cache-bust `?v=` and roll out in batches

**Blast radius:** the form lives on ~165 pages from one global element. A single bad edit
breaks every form at once (spec §2, source-file header). Treat every release as sitewide.

**Do:**
1. **Cache-bust per release.** Bump a `?v=` query param wherever the asset is referenced
   (the global Code element / any enqueued asset URL) so browsers and any CDN/page cache pull
   the new version. Increment it on **every** change to the CSS or JS.
2. **Spot-check 4–5 representative page types** before deploying wide (run `ampy-syn` on
   desktop + mobile per spec §10 P6):
   - one `/elservice/<slug>` (e.g. `/elservice/elcentral`) → locked service chip, kundtyp privat;
   - one EFX company page (e.g. `/restauranger`) → **no segment toggle**, "Elektriker för
     restaurang" chip, "Vad gäller arbetet?" required;
   - one EFX private page (`/villor`) → no toggle, kundtyp privat;
   - one `/laddbox/<ort>` (e.g. `/laddbox/nacka`) → locked "Laddbox" + "Laddbox i Nacka" chip,
     segment toggle present;
   - one pillar page (`/elektriker`) → segment toggle, asks tjänst, fallback-clean.
3. **Roll out in batches** (spec §10 P7): service-pages first → EFX → ort-pages **last**
   (largest volume). Keep a kill-switch ready.

**Acceptance check:** after bumping `?v=`, a hard-refresh of each spot-check page shows the
expected variant (chips, toggle/no-toggle, exclude pages render no form), and a valid submit
on staging produces a lead row (see `06-qa-and-acceptance.md`).

---

## The three code parts (where they come from)

| Part | Source (in `bricks/ampy-offert-form.html`) | Goes to | Split file |
|---|---|---|---|
| CSS | `<style>` body, lines 31–127 | global Custom CSS (Step 2) | `code/bricks-form.css` |
| Markup | one div, modelled on line 132 | Hero 2 Code element (Step 3) | `code/bricks-form.html` |
| Script | `<script>` body, lines 136–327 | sitewide global Code element (Step 4) | `code/bricks-form.js` |

> The split files (`code/bricks-*.{css,html,js}`) are produced by the human lead, not by an
> agent. If they are not present yet, extract the three regions from
> `code/ampy-offert-form.html` at the line ranges above. Do **not** edit the pinned
> `ampy-offert-form.html` to make the split — split into the `bricks-*` files.

---

## Troubleshooting

| Symptom | Likely cause | Fix |
|---|---|---|
| **Form shows on a page it shouldn't** (`/eljour/*`, `/solcellsbatterier/*`, `/laddboxar/*`, `/batterilagring/*`, `/offert`, `/integritetspolicy`, etc.) | `data-ampy-path` is wrong (e.g. bound to `post_slug`, so only the last segment reaches the resolver and the EXCLUDE prefix match fails), or the page URL is not what you think. | Bind `data-ampy-path` to the **full path/URL** (`{{post_url}}`), not `post_slug` (Step 3). Inspect the rendered attribute. EXCLUDE logic: resolver lines 182–184. |
| **Form is blank / unstyled box / nothing renders** | (a) CSS not loaded on this page; (b) script not present on this page; (c) root has no `id="ampy-form-root"` so `getElementById` returns null (line 201). | (a) Use **global** Custom CSS (Step 2). (b) Ensure the global script element is sitewide (Step 4). (c) Add `id="ampy-form-root"` to the markup div ([VERIFY], Step 4). |
| **Wrong segment / wrong kundtyp** (e.g. a company EFX page shows Privat, or shows a toggle when it shouldn't) | The resolver keys off the URL path. If `data-ampy-path` is wrong the EFX/ORT match fails and you get the privat fallback (line 196). EFX pages intentionally have **no** segment toggle (kundtyp locked from slug, lines 230–231, 258). | Confirm `data-ampy-path` is the full path. Confirm the slug is in the `EFX` map (lines 156–170) or `SERVICE`/ORT maps. To add a slug, see `03-sitemap-and-routing.md`. |
| **Font fell back to system-ui** (form renders but in the OS default sans) | Google Fonts link removed **before** the self-hosted `@font-face` path was confirmed, or the `@font-face` `src` path (line 31) is wrong. | Restore the Google link until self-host is confirmed, fix the `woff2` path (Step 6), then remove the Google link. Verify the font 200s in the Network tab. |
| **Leads not arriving** (form shows "Tack…" but nothing in the CRM) | (a) `PREVIEW` is true (preview branch shows success without POSTing, line 296) — happens on `localhost`/`*.github.io`; (b) `ENDPOINT` wrong/unbuilt; (c) endpoint returns non-2xx (the form shows the error card on `!r.ok`, line 300); (d) honeypot `company_url` filled → server should reject. | (a) Verify host is production (not preview). (b/c) Confirm `ENDPOINT` and that the backend exists and returns 2xx only on a successful two-row write (`04-php-backend.md`, `05-data-contract.md`). (d) Confirm the honeypot stays empty. |
| **Form submits even though required fields look empty** | Working as designed: minimal callable lead = Namn + Telefon + Postnummer + GDPR (+ empty honeypot). Privat additionally requires e-post + adress; org requires orgname+kontakt+telefon+epost+postnr; EFX additionally requires "tj". Everything else is optional enrichment and must never block submit. | Not a bug. Validation logic: lines 269–286. If you need a field mandatory, change `validate()` and document it. |
| **`[aof] dubbel #ampy-form-root dold` in console** | The markup div or the whole component was placed more than once on the page. | Place the markup div once per page and the script once sitewide (Step 7). Remove the duplicate. |
| **Title text overflows / clips on mobile** | CSS regression on `.aof .title` wrap. | The mobile rule sets `white-space:normal;line-height:1.2` at `max-width:600px` (lines 96–105). Keep the 600/601px breakpoint — do not change to 480 (spec §10 is stale on this; the code ships 600). |

---

## Cross-references

- `00-START-HERE.md` — orientation + constraints + implement order.
- `02-the-form-component.md` — the resolver, the maps, the per-page matrix in depth.
- `03-sitemap-and-routing.md` — how to add an ort/service/EFX slug.
- `04-php-backend.md` — the lead endpoint the `ENDPOINT` constant must point at.
- `05-data-contract.md` — the exact JSON payload and ampy-dash-2 mapping.
- `06-qa-and-acceptance.md` — invariants and acceptance tests for the spot-checks in Step 8.
