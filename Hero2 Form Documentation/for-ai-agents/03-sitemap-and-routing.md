# 03 — Sitemap & Routing

**This is the authoritative routing playbook.** It explains how Ampy's sitemap drives the offer form: which page types render it, how a URL path becomes a per-page configuration, why no per-page editing is ever required, and the exact step-by-step procedure to add a new ort, service, EFX vertical, or exclusion.

Everything here is read from the shipped code: `bricks/ampy-offert-form.html`, the `resolve()` function and its maps (`SERVICE`, `EFX`, `FORLABEL`, `SV_ORT`, `UTIL`). Where a claim cannot be confirmed from the code, it is tagged `[VERIFY]` or `[GAP]`.

> Note on the old spec: `FUNKTIONALITET.md` §2 describes a per-page ACF override (`ampy_form_override`) and a "per-sida-override" as resolution step 1. **That override does not exist in the shipped code** — there is no ACF read, no override branch. Treat the code's `resolve()` order below as the single source of truth.

---

## 1. The core idea — one component, ~165 pages, zero per-page config

The form is **one** self-contained component placed **once** as a sitewide global Bricks element. It renders differently on every page. The difference is computed at runtime from the page's URL path — nothing is configured per page.

The flow is:

```
page URL path
   │   (Bricks dynamic data injects the full path into the markup)
   ▼
data-ampy-path="…the path…"     ← on <div class="aof" …>
   │   (the script reads this attribute)
   ▼
resolve(path)                   ← the in-file router; returns a config object
   │
   ▼
config object  { render, kundtyp, vertical, tjanst, tjanstLocked, ort, ortWord, ask, opts, forLabel, orgLabel, source }
   │
   ▼
render()                        ← builds the right form (or an exclude card) from the config
```

Because the path *is* the configuration source, adding a 51st ort page — or the 100th — requires **no form change at all**. The ort regexes already match it, and it inherits the correct chip, vertical, and locked service on day one (see §6).

---

## 2. Full page inventory — what renders the form, what does not

### Renders the form (`render:true`)

| Page type | URL pattern | Count | What the visitor sees |
|---|---|---|---|
| Service pages | `/elservice/<slug>` | 22 exact slugs | Segment toggle + **locked** service chip ("Gäller: …"), kundtyp defaults Privat |
| EFX (Elektriker För X) | `/<root-slug>` | 13 root slugs | **No** segment toggle (kundtyp locked from slug) + "Elektriker för X" chip + "Vad gäller arbetet?" |
| Laddbox in an ort | `/laddbox/<ort>` | ~50 orter | Segment toggle + **locked** "Laddbox i <Ort>" chip, vertical `laddbox` |
| Elinstallation in an ort | `/elinstallation/<ort>` | ~50 orter | Segment toggle + **locked** "Elinstallation i <Ort>" chip, vertical `service` |
| Elektriker in an ort | `/elektriker/<ort>` | ~50 orter | Segment toggle + "Elektriker i <Ort>" chip, service **asked** (broad), vertical `service` |
| Pillar pages | `/elservice`, `/elektriker`, `/laddbox`, `/elinstallation` | 4 | Broad defaults — see §5 |
| Fallback (anything else that renders) | any unmatched path | — | Segment toggle, service asked, vertical `oklart` — never crashes, always a callable lead |

The ~165-page figure is the sum of 22 service + 13 EFX + ~150 ort pages + 4 pillar pages. The exact ort count is owner data; the regexes do not care how many there are.

### Does NOT render the form (`render:false`)

These are excluded *first* in `resolve()`, before any match — so an excluded prefix always wins:

| Excluded | Pattern in code | Why |
|---|---|---|
| Eljour pages | `/^\/eljour(\/|$)/` | Telephone-first emergency block; shows a "Ring oss" card instead (`010-265 79 79`), not a form |
| Product pages | `/^\/solcellsbatterier(\/|$)/`, `/^\/laddboxar(\/|$)/`, `/^\/batterilagring(\/|$)/` | Product catalog pages, not lead-capture surfaces |
| Utility pages | `UTIL` list + any subpath: `/offert`, `/kopvillkor`, `/integritetspolicy`, `/cookiepolicy`, `/thank-you`, `/nyheter`, `/tillganglighet`, `/om-oss` | Legal/utility/editorial roots and everything under them |

For eljour, `excludeCard()` renders the phone card; for product and utility it renders "Formuläret visas inte på den här sidtypen." In all three cases `render:false` means no form, no POST.

---

## 3. How `resolve(path)` works — line by line

`resolve(p)` (in the `<script>`) is the entire router. Order matters: **first match wins**.

### Step 0 — normalise

```js
p=String(p||'').replace(/^https?:\/\/[^/]+/,'')   // strip scheme+host if a full URL was passed
              .replace(/[?#].*$/,'')               // strip query + hash
              .toLowerCase()                       // case-insensitive
              .replace(/\/{2,}/g,'/')              // collapse double slashes
              .replace(/^\/+|\/+$/g,'');           // trim leading/trailing slashes
p='/'+p;                                            // re-add one leading slash
```

So `https://ampy.se/Elektriker/Nacka/?utm=x#top` and `/elektriker/nacka` both normalise to `/elektriker/nacka`. This is also why `data-ampy-path` may carry the full URL — the resolver strips origin and query itself.

### Step 1 — EXCLUDE (evaluated first)

```js
if(/^\/eljour(\/|$)/.test(p)) return {render:false,kind:'eljour'};
if(/^\/solcellsbatterier(\/|$)/.test(p)||/^\/laddboxar(\/|$)/.test(p)||/^\/batterilagring(\/|$)/.test(p)) return {render:false,kind:'produkt'};
if(UTIL.some(function(u){return p===u||p.indexOf(u+'/')===0;})) return {render:false,kind:'utility'};
```

### Step 2 — SERVICE map (22 exact slugs)

```js
if((m=p.match(/^\/elservice\/([a-z0-9åäö-]+)$/))&&SERVICE[m[1]])
  return {render:true,kundtyp:'privat',tjanst:SERVICE[m[1]],tjanstLocked:true,vertical:'service',source:p};
if(p==='/elservice')
  return {render:true,kundtyp:'privat',ask:true,vertical:'service',source:p};
```

The slug must be in the `SERVICE` map; an `/elservice/<unknown-slug>` falls through to the fallback (still renders, kundtyp privat, vertical `oklart`).

### Step 3 — EFX map (13 root slugs)

```js
var es=p.slice(1);   // drop the leading slash → bare slug, e.g. "restauranger"
if(Object.prototype.hasOwnProperty.call(EFX,es)){
  var efx=EFX[es];
  return {render:true,kundtyp:efx.kundtyp,ask:true,opts:efx.opts,
          forLabel:FORLABEL[es],orgLabel:efx.orgLabel,vertical:efx.vertical,source:p};
}
```

EFX slugs are **bare root slugs** (`/restauranger`, `/villor`) — no path prefix. The presence of `forLabel` is the signal downstream that this is an EFX page: it suppresses the segment toggle and shows the "Elektriker för X" chip.

### Step 4 — ORT regexes

```js
if(m=p.match(/^\/laddbox\/([a-z0-9åäö-]+)$/))       return {render:true,kundtyp:'privat',tjanst:'Laddbox',tjanstLocked:true,ort:ortName(m[1]),ortWord:'Laddbox',vertical:'laddbox',source:p};
if(m=p.match(/^\/elinstallation\/([a-z0-9åäö-]+)$/)) return {render:true,kundtyp:'privat',tjanst:'Elinstallation',tjanstLocked:true,ort:ortName(m[1]),ortWord:'Elinstallation',vertical:'service',source:p};
if(m=p.match(/^\/elektriker\/([a-z0-9åäö-]+)$/))     return {render:true,kundtyp:'privat',ask:true,ort:ortName(m[1]),ortWord:'Elektriker',vertical:'service',source:p};
```

`laddbox` and `elinstallation` orts **lock** the service; `elektriker` orts **ask** ("bred"). All three derive the ort display name via `ortName()` (see §4).

### Step 5 — pillar pages

```js
if(p==='/elektriker')      return {render:true,kundtyp:'privat',ask:true,vertical:'service',source:p};
if(p==='/laddbox')         return {render:true,kundtyp:'privat',tjanst:'Laddbox',tjanstLocked:true,vertical:'laddbox',source:p};
if(p==='/elinstallation')  return {render:true,kundtyp:'privat',tjanst:'Elinstallation',tjanstLocked:true,vertical:'service',source:p};
```

(`/elservice` is handled in Step 2.)

### Step 6 — fallback

```js
return {render:true,kundtyp:'privat',ask:true,vertical:'oklart',source:p};
```

Never crashes; always produces a callable lead with `vertical:'oklart'` so the booking team can triage.

---

## 4. The maps, in full (read from the code)

### SERVICE — 22 exact `/elservice/<slug>` slugs

Each maps a URL slug → the locked chip label. The chip reads "Gäller: <label>".

| Slug | Locked chip label |
|---|---|
| `vitvaror` | Vitvaror |
| `utomhusbelysning` | Utomhusbelysning |
| `strombrytare` | Strömbrytare |
| `ugn-spis` | Ugn & spis |
| `spotlights` | Spotlights |
| `smarta-hem` | Smarta hem |
| `luftvarmepump` | Luftvärmepump |
| `lastbalansering` | Lastbalansering |
| `kok` | El i kök |
| `koksrenovering` | Köksrenovering |
| `inomhusbelysning` | Inomhusbelysning |
| `jordfelsbrytare` | Jordfelsbrytare |
| `glodlampa` | Glödlampor |
| `golvvarme` | Golvvärme |
| `felsokning-av-el` | Felsökning av el |
| `elrenovering` | Elrenovering |
| `elcentral` | Elcentral |
| `elbesiktning` | Elbesiktning |
| `belysning` | Belysning |
| `badrumsrenovering` | Badrumsrenovering |
| `armatur` | Armatur |
| `badrum` | El i badrum |

Note the slug → label is not always 1:1 wording: `kok` → "El i kök", `badrum` → "El i badrum", `glodlampa` → "Glödlampor", `ugn-spis` → "Ugn & spis". The label is whatever the booking team should read in the chip; the slug stays as the live URL.

### EFX — 13 root slugs

Each maps a bare root slug → `{kundtyp, vertical, opts, orgLabel?}`. All 13 share `vertical:'foretag_brf'`. `kundtyp` is locked from the slug.

| Slug | kundtyp | orgLabel (org name field) | "Elektriker för X" chip word (FORLABEL) |
|---|---|---|---|
| `villor` | privat | — | villa |
| `radhus` | privat | — | radhus |
| `restauranger` | foretag | (default "Företagsnamn") | restaurang |
| `hotell` | foretag | (default) | hotell |
| `kontor` | foretag | (default) | kontor |
| `butik` | foretag | (default) | butik |
| `kommuner` | foretag | **Förvaltning eller enhet** | kommun |
| `idrottshallar` | foretag | **Verksamhetens namn** | idrottshall |
| `foretag` | foretag | (default) | företag |
| `byggforetag` | foretag | (default) | byggföretag |
| `entreprenad` | foretag | (default) | entreprenad |
| `bostadsrattsforening` | **brf** | **Föreningens namn** | bostadsrättsförening |
| `tredjepartsinstallationer` | foretag | (default) | tredjepartsinstallation |

Each EFX entry also carries an `opts` array (the per-vertical "Vad gäller arbetet?" choices). `villor`/`radhus` use the shared `PRIVAT_OPTS`; `foretag` uses the shared `ORG_OPTS`; the rest carry a tailored list (e.g. `restauranger` leads with "Storkök / fläkt / trefas"). The exact `opts` are in the `EFX` object in the code; they are taxonomy, not routing, so they live in `02-the-form-component.md` / `05-data-contract.md`.

> Decided behaviour (stated as final): **EFX has no Privat/BRF/Företag segment toggle** — kundtyp is locked from the slug, and a welcoming "Elektriker för <X>" chip is shown. Rationale: the page already knows the visitor type; removing the toggle cuts friction and guarantees correct CRM segmentation. This supersedes `FUNKTIONALITET.md` §9.1 which (stale) says EFX is "just förvald / öppet".

### SV_ORT — display-name casing table (17 entries)

`ortName(slug)` resolves the ort display name:

```js
function ortName(s){return SV_ORT[s]||s.split('-').map(cap).join(' ');}
```

- If the slug is in `SV_ORT`, the table value is used (correct casing + å/ä/ö).
- Otherwise it falls back to title-casing the slug, splitting on `-`. So `upplands-vasby` would fall back to "Upplands Vasby" (no ä) — which is why such orts belong in the table.

Current `SV_ORT` entries:

| Slug | Display name |
|---|---|
| `nacka` | Nacka |
| `sodertalje` | Södertälje |
| `sollentuna` | Sollentuna |
| `taby` | Täby |
| `jarfalla` | Järfälla |
| `vaxholm` | Vaxholm |
| `varmdo` | Värmdö |
| `vallingby` | Vällingby |
| `huddinge` | Huddinge |
| `solna` | Solna |
| `stockholm` | Stockholm |
| `upplands-vasby` | Upplands Väsby |
| `tyreso` | Tyresö |
| `lidingo` | Lidingö |
| `osteraker` | Österåker |
| `bromma` | Bromma |
| `sundbyberg` | Sundbyberg |

**The table is for display casing only — it is NOT a gate.** An ort missing from `SV_ORT` still renders the form (the fallback title-cases the slug). The table only fixes casing and diacritics. So an ort slug with no å/ä/ö and natural title-casing (e.g. `solna`) does not strictly need a table entry, but adding it is harmless and recommended for consistency.

### UTIL — utility-root exclusion list

```js
var UTIL=['/offert','/kopvillkor','/integritetspolicy','/cookiepolicy','/thank-you','/nyheter','/tillganglighet','/om-oss'];
```

Matched as exact root **or** any subpath (`p===u || p.indexOf(u+'/')===0`), so `/om-oss/teamet` is excluded too.

---

## 5. Pillar pages

The four pillar (hub) pages render the form with broad defaults:

| Pillar | kundtyp | Service field | vertical |
|---|---|---|---|
| `/elservice` | privat | asked | `service` |
| `/elektriker` | privat | asked | `service` |
| `/laddbox` | privat | **locked "Laddbox"** | `laddbox` |
| `/elinstallation` | privat | **locked "Elinstallation"** | `service` |

They keep the segment toggle (visitor type unknown on a hub page).

---

## 6. Why this means zero per-page editing

- **Ort pages (~150 of the ~165):** three regexes — `/laddbox/<ort>`, `/elinstallation/<ort>`, `/elektriker/<ort>` — match *any* single segment after the prefix (`[a-z0-9åäö-]+`). A brand-new ort page inherits the correct chip, lock state, and vertical **automatically on day one**. There is nothing to add to the form to support it. (Optional: add the ort to `SV_ORT` for correct casing — see §7.1.)
- **One global element:** the form lives once, sitewide. A design or copy change ships to all ~165 placements at once. That is the upside; the downside is blast radius — see §8.
- **Everything routes in one diffable file:** the resolver + the 22 SERVICE entries + the 13 EFX entries + `FORLABEL` + `SV_ORT` + `UTIL` are all in `ampy-offert-form.html`. Adding a page type is a one-line map edit, reviewed in one diff.

---

## 7. HOW TO ADD A … (playbooks)

All edits are in `bricks/ampy-offert-form.html` inside the `<script>`. After any edit: **bump the `?v=` cache-bust and spot-check 4–5 representative page types** (one `/laddbox/<ort>`, one `/elservice/elcentral`, one EFX-foretag, one EFX-villor, one pillar) before deploy. A bad edit breaks every form at once.

### 7.1 Add a new ort

**Nothing to do — it just works.** The moment the new ort page exists at `/elektriker/<ort>`, `/laddbox/<ort>`, or `/elinstallation/<ort>`, the regex matches it and the form renders correctly.

**Optional (recommended) — fix the display casing.** If the ort slug contains å/ä/ö or needs non-trivial casing (e.g. "Upplands Väsby"), add one line to the `SV_ORT` table:

```js
var SV_ORT={…, jonkoping:'Jönköping'};
```

Without the entry, `ortName('jonkoping')` would render "Jonkoping". With it, "Jönköping". This is purely cosmetic for the chip — it never affects whether the form renders.

### 7.2 Add a new `/elservice/<slug>` service page

Add **one line** to the `SERVICE` map — `slug:'<Locked chip label>'`:

```js
var SERVICE={…, elbil:'Elbilsladdning'};
```

Now `/elservice/elbil` renders with the locked chip "Gäller: Elbilsladdning", kundtyp privat (toggle still shown), vertical `service`. The slug must match the live URL segment exactly (lowercase, `[a-z0-9åäö-]`). Without the map entry, the page falls through to the fallback (renders, but `oklart`, no locked chip).

### 7.3 Add a new EFX vertical

Add **one line to `EFX`** and **one line to `FORLABEL`** (both keyed by the bare root slug):

```js
// 1) routing + kundtyp + service options
var EFX={…,
  vardcentraler:{kundtyp:'foretag',vertical:'foretag_brf',orgLabel:'Verksamhetens namn',
                 opts:['Större elinstallation','Belysning','Service & underhåll','Elbesiktning','Elfel','Annat']}
};
// 2) the "Elektriker för X" chip word
var FORLABEL={…, vardcentraler:'vårdcentral'};
```

Decisions to make per new EFX vertical:
- **kundtyp** — `privat` (no org.nr field, no org name), `brf`, or `foretag`. This locks the segment.
- **vertical** — currently every EFX uses `foretag_brf`; keep that unless the data team says otherwise `[VERIFY]`.
- **orgLabel** — optional; overrides the org-name field label (e.g. "Verksamhetens namn" for institutions). Omit for the default ("Företagsnamn" / "Föreningens namn" by kundtyp).
- **opts** — the "Vad gäller arbetet?" choices, in priority order.
- **FORLABEL** — the singular noun for the chip ("Elektriker för vårdcentral"). Copy nuance (singular/plural, en/ett) is handled in the copy pass.

If you forget the `FORLABEL` entry, `forLabel` is `undefined` → the component would treat the page as non-EFX (segment toggle reappears). So **both lines are required.**

### 7.4 Add a new exclusion

Two cases:

**(a) A whole URL prefix (like a new product line).** Add a regex test in the EXCLUDE block at the top of `resolve()`, before any match:

```js
if(/^\/varmepumpar(\/|$)/.test(p)) return {render:false,kind:'produkt'};
```

`kind:'produkt'` (or `'utility'`) shows the generic "visas inte" card; `kind:'eljour'` shows the phone card. Pick the `kind` whose card text fits.

**(b) A utility root + its subpaths.** Add the path to the `UTIL` array — that is all:

```js
var UTIL=[…,'/karriar'];
```

This excludes `/karriar` and everything under it.

---

## 8. The `data-ampy-path` dynamic-data requirement (do not skip)

The component reads its path from, in order:

```js
var rawPath=(PREVIEW&&qs.get('path'))?qs.get('path'):(root.getAttribute('data-ampy-path')||location.pathname);
```

- In **production** (`PREVIEW=false`), `?path` is ignored. The path comes from `data-ampy-path`, with `location.pathname` as a last-resort fallback.
- **`data-ampy-path` MUST be wired via Bricks dynamic data to the full request path/URL** — not `{post_slug}`. `post_slug` is only the **last** URL segment; it would break multi-segment matches (`/elservice/elcentral`, `/laddbox/nacka`) and the bare-root EFX matches (`/restauranger`), and it would not let the resolver strip origin/query. Use a dynamic-data token that yields the full path or URL (e.g. `{{post_url}}` / the page's request URI). `[VERIFY]` the exact Bricks token name on the live theme.
- The `location.pathname` fallback only fires if `data-ampy-path` is empty. Relying on it risks a flash/mismatch (and on some Bricks/caching setups `pathname` may not reflect the canonical route), so wiring the attribute is mandatory, not optional.

The resolver normalises whatever it gets (strips scheme+host, query, hash; lowercases; trims slashes), so passing the full URL is safe and preferred.

`[VERIFY]` There is also a one-time init guard and duplicate-element guard in the code (`data-aof-init`, hiding extra `#ampy-form-root` nodes). The implication for routing: place the global element **once per page**; a second instance is auto-hidden, not double-rendered.

---

## 9. Quick reference — path → outcome (worked examples)

| URL path | resolve() result | What renders |
|---|---|---|
| `/elektriker/nacka` | ort regex; `ortWord:'Elektriker'`, `ort:'Nacka'`, ask, vertical `service` | Chip "Elektriker i Nacka", segment toggle shown, service asked |
| `/elservice/elcentral` | SERVICE map; `tjanst:'Elcentral'`, `tjanstLocked:true` | Locked chip "Gäller: Elcentral", segment toggle shown |
| `/restauranger` | EFX map; `kundtyp:'foretag'`, `forLabel:'restaurang'`, vertical `foretag_brf` | **No** toggle; chip "Elektriker för restaurang"; "Vad gäller arbetet?" asked |
| `/villor` | EFX map; `kundtyp:'privat'`, `forLabel:'villa'` | **No** toggle; chip "Elektriker för villa"; privat enrichment (no org.nr) |
| `/laddbox/taby` | ort regex; `tjanst:'Laddbox'` locked, `ort:'Täby'` (from SV_ORT), vertical `laddbox` | Locked chip "Laddbox i Täby", segment toggle shown |
| `/elinstallation/solna` | ort regex; `tjanst:'Elinstallation'` locked, `ort:'Solna'`, vertical `service` | Locked chip "Elinstallation i Solna" |
| `/laddbox` | pillar; `tjanst:'Laddbox'` locked, vertical `laddbox` | Locked "Laddbox", broad defaults, toggle shown |
| `/eljour/jarfalla` | EXCLUDE `kind:'eljour'`, `render:false` | Phone card "Ring oss direkt på 010-265 79 79" — no form |
| `/solcellsbatterier/nordic-10` | EXCLUDE `kind:'produkt'`, `render:false` | "Formuläret visas inte på den här sidtypen." |
| `/integritetspolicy` | EXCLUDE (UTIL), `render:false` | "Formuläret visas inte på den här sidtypen." |
| `/nagot-annat` | fallback; `render:true`, vertical `oklart`, ask | Form renders, callable lead, triage vertical `oklart` |

---

## 10. Cross-references

- `02-the-form-component.md` — the component internals (state, the `opts`/taxonomy arrays, per-page render matrix).
- `05-data-contract.md` — how `kundtyp`/`vertical`/`tjanst_intresse`/`kallsida` from the resolver map into `ampy-dash-2`.
- `01-bricks-implementation.md` — wiring `data-ampy-path` via Bricks dynamic data; placing the one global element.
- `06-qa-and-acceptance.md` — the 4–5 representative spot-checks to run after any map edit.
