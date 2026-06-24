# 02 — The Form Component (technical reference)

This is the deep reference for the single self-contained component
`bricks/ampy-offert-form.html`. It explains every moving part: the IIFE
structure, the `st` state object, the `resolve()` router walked branch by
branch, the render lifecycle (snap/fill preservation + the in-place disclosure
toggle + segment seeding/EFX lock), `validate()`, `buildPayload()`, the
honeypot, accessibility, and a full per-page behaviour matrix.

**The code is the source of truth.** Where `FUNKTIONALITET.md` disagrees with
the code, the code wins (see `02`/README for the list of stale spec points).
Quotes below are copied verbatim from the shipped file; line numbers refer to
`bricks/ampy-offert-form.html` at the time of writing.

> **Invariant to protect above all:** `resolve()` NEVER throws and ALWAYS
> returns a config. Even the catch-all fallback returns a renderable, callable
> lead form. The component must never leave a visitor staring at a broken box on
> any of the ~165 pages. Every edit you make has to preserve that.

---

## 1. File shape — three parts, one IIFE

The file is one HTML document, but in Bricks it is split into three pieces (see
`01-bricks-implementation.md`):

1. the `<style>` block → Bricks Custom CSS (every selector is prefixed `.aof`,
   so it cannot leak into the theme; the palette tokens are scoped to `.aof`,
   line 35, NOT to `:root`);
2. the `<div class="aof" data-ampy-path="…"></div>` markup → a Bricks element in
   the Hero 2 section;
3. the `<script>` → one sitewide global Bricks Code element.

All JS lives inside a single IIFE:

```js
(function(){
  …
})();
```

Nothing is exported to `window`. There are no globals, no framework, no build
step. The IIFE runs top-to-bottom: dev settings → helpers → taxonomy/maps →
`resolve()` → DOM lookup + dedupe guard → state init → render/validate/submit
functions → event listeners → a final `render()` call (line 326) that paints the
first frame.

### Dev settings (top of the IIFE, lines 137–145)

Four values the backend agent must confirm before go-live:

```js
var ENDPOINT='/wp-json/ampy/v1/lead';      /* TODO dev */
var SOURCE_FORM=3;                          /* TODO dev: bekräfta kod */
var PREVIEW=(location.protocol==='file:')||/^(localhost|127\.0\.0\.1|\[::1\])$/.test(location.hostname)||/\.github\.io$/.test(location.hostname);
var POLICY_VERSION='ampy-privacy-2026-06';  /* TODO dev: bekräfta version */
```

`PREVIEW` is **derived from the host**, deliberately, so production (`ampy.se`)
can never accidentally fall into preview and silently drop leads across 165
pages. Only `file://`, `localhost`/`127.0.0.1`/`[::1]`, and `*.github.io` are
preview. Staging on its own host runs the real POST (intended). In preview the
form shows the JSON payload instead of POSTing and honours `?path=` for testing.

---

## 2. The state object `st` (line 212)

```js
var st={seg:'privat',segTouched:false,open:false,done:false,error:false,sending:false,cfg:null,vals:{},gdpr:false,files:[],lastPayload:null};
```

| key | type | meaning |
|---|---|---|
| `seg` | `'privat'\|'brf'\|'foretag'` | currently selected segment (kundtyp). Seeded from `cfg.kundtyp`. |
| `segTouched` | bool | user manually changed the segment toggle. Once true, render stops re-seeding `seg` from the page default (so a manual choice survives re-renders) — **except** on EFX, where the page lock always wins (see §5). |
| `open` | bool | "Fler detaljer" disclosure expanded? |
| `done` | bool | success card shown. |
| `error` | bool | error card shown. |
| `sending` | bool | a POST is in flight (guards against double-submit). |
| `cfg` | object | the last `resolve()` result. Drives the whole render. |
| `vals` | `{fieldId: value}` | field values snapshotted across re-renders (see `snap`/`fill`). |
| `gdpr` | bool | consent checkbox state, preserved across re-renders. |
| `files` | `File[]` | selected images, preserved across re-renders (FileList can't be restored via `.value`). |
| `lastPayload` | string | pretty-printed JSON of the last build, shown in the preview success card. |

**Why this matters:** the component re-renders the whole card (`root.innerHTML=…`)
on segment change, disclosure toggle, success, and error. State in `st` is the
only thing that survives that wipe. If you add a field, you must add it to the
`FIELDS` array (line 200) so `snap()`/`fill()` preserve it, or the user loses it
on every re-render.

---

## 3. Taxonomy + maps (lines 152–176) — one diffable source

These objects are the entire per-page configuration. To add an ort, a service,
or an EFX vertical you edit ONE of these — no per-page work (see
`03-sitemap-and-routing.md`).

- **`PRIVAT_OPTS`** (line 153) — the "Vad gäller arbetet?" options for private
  customers: `Elinstallation · Belysning · Kök och badrum · Laddbox · Energi & effekt · Elfel · Annat`.
- **`ORG_OPTS`** (line 154) — the default org superset:
  `Större elinstallation · Belysning · Laddbox · Service & underhåll · Elbesiktning · Energi & effekt · Elfel · Annat`.
- **`SERVICE`** (line 155) — **22 slugs**, `slug → locked service label`. Maps
  `/elservice/<slug>` to a locked chip. Keys: `vitvaror, utomhusbelysning,
  strombrytare, ugn-spis, spotlights, smarta-hem, luftvarmepump, lastbalansering,
  kok, koksrenovering, inomhusbelysning, jordfelsbrytare, glodlampa, golvvarme,
  felsokning-av-el, elrenovering, elcentral, elbesiktning, belysning,
  badrumsrenovering, armatur, badrum`.
- **`EFX`** (lines 156–170) — **13 root slugs**, each `{kundtyp, vertical, opts,
  orgLabel?}`. The kundtyp is baked in per slug (see §5 and the matrix in §10).
- **`FORLABEL`** (line 172) — the singular noun for the "Elektriker för <X>" chip
  (e.g. `villor → 'villa'`, `restauranger → 'restaurang'`). Presence of
  `cfg.forLabel` is also the **EFX flag** used throughout the render.
- **`SV_ORT`** (line 173) — pretty names for 17 known orter (e.g.
  `sodertalje → 'Södertälje'`). Unknown orter fall back to a
  title-cased, hyphen-split of the slug via `ortName()` (line 176).
- **`BRADSKA`** (line 174) — Swedish dropdown label → payload enum
  (`Inom 24 timmar → 24h`, etc.).
- **`UTIL`** (line 175) — utility roots that EXCLUDE the form:
  `/offert, /kopvillkor, /integritetspolicy, /cookiepolicy, /thank-you,
  /nyheter, /tillganglighet, /om-oss`.

---

## 4. `resolve(path)` — the router, walked in full (lines 179–197)

This is the heart of the component. Given a URL path it returns a config object.
First match wins; order is load-bearing.

### 4.0 Normalisation (line 180–181)

```js
p=String(p||'').replace(/^https?:\/\/[^/]+/,'').replace(/[?#].*$/,'').toLowerCase().replace(/\/{2,}/g,'/').replace(/^\/+|\/+$/g,'');
p='/'+p;
```

Strips origin, strips query/hash, lowercases, collapses repeated slashes, trims
leading/trailing slashes, then re-adds exactly one leading slash. This is why a
bare root, trailing slashes, and mixed case all resolve the same. **It is also
the XSS guard** — combined with the `[a-z0-9åäö-]+` character whitelist in the
segment regexes, an attacker can't inject markup through the path.

### 4.1 EXCLUDE — eljour (line 182)

```js
if(/^\/eljour(\/|$)/.test(p)) return {render:false,kind:'eljour'};
```

`/eljour` and any subpath → `render:false, kind:'eljour'`. The `(\/|$)`
guarantees subpath matching (`/eljour/nacka` excludes too), and crucially that a
slug merely *starting with* `eljour` does NOT match. `kind:'eljour'` makes
`excludeCard()` render the phone-first block instead of the generic "not shown"
message (line 247).

### 4.2 EXCLUDE — product pages (line 183)

```js
if(/^\/solcellsbatterier(\/|$)/.test(p)||/^\/laddboxar(\/|$)/.test(p)||/^\/batterilagring(\/|$)/.test(p)) return {render:false,kind:'produkt'};
```

`/solcellsbatterier/*`, `/laddboxar/*`, `/batterilagring/*` → `render:false`.
Note `/laddboxar` (product) is distinct from `/laddbox/<ort>` (form) — the
trailing `ar` and the `(\/|$)` boundary keep them apart.

### 4.3 EXCLUDE — utility roots + subpaths (line 184)

```js
if(UTIL.some(function(u){return p===u||p.indexOf(u+'/')===0;})) return {render:false,kind:'utility'};
```

Matches each `UTIL` entry **exactly** OR as a path prefix (`u+'/'`). So both
`/integritetspolicy` and `/integritetspolicy/something` exclude. This subpath
check is one of the QA-fixed items — do not weaken it to an exact-match-only
test.

### 4.4 SERVICE map — 22 exact slugs (lines 186–187)

```js
if((m=p.match(/^\/elservice\/([a-z0-9åäö-]+)$/))&&SERVICE[m[1]]) return {render:true,kundtyp:'privat',tjanst:SERVICE[m[1]],tjanstLocked:true,vertical:'service',source:p};
if(p==='/elservice') return {render:true,kundtyp:'privat',ask:true,vertical:'service',source:p};
```

- `/elservice/<slug>` where `<slug>` is one of the 22 keys → kundtyp `privat`,
  **service LOCKED** to the mapped label (`tjanstLocked:true`), shown as a
  "Gäller: <tjänst>" chip, `vertical:'service'`. An unknown `/elservice/<x>`
  slug does NOT match here (the `&&SERVICE[m[1]]` guard fails) and falls through
  to the catch-all fallback (§4.9).
- `/elservice` (the pillar page) → kundtyp `privat`, `ask:true` (the visitor type
  is unknown on the pillar), `vertical:'service'`. **Keeps the segment toggle.**

### 4.5 EFX map — 13 root slugs, kundtyp baked in (line 189)

```js
var es=p.slice(1);
if(Object.prototype.hasOwnProperty.call(EFX,es)){var efx=EFX[es];return {render:true,kundtyp:efx.kundtyp,ask:true,opts:efx.opts,forLabel:FORLABEL[es],orgLabel:efx.orgLabel,vertical:efx.vertical,source:p};}
```

`es` is the path with the leading slash removed (so a bare root slug like
`villor`). `hasOwnProperty` is used deliberately (not `EFX[es]`) so prototype
keys can't false-positive. On a hit it returns:

- `kundtyp` from the EFX entry (and `forLabel` set → **kundtyp LOCKED**, no
  segment toggle; see §5);
- `ask:true` → the "Vad gäller arbetet?" select is shown (and, because it's EFX,
  it's in the **main view** and **required** — see §6);
- `opts` → the per-vertical option list;
- `vertical` → `'service'` for villor/radhus, `'foretag_brf'` for the rest;
- `orgLabel` → custom org-name label for kommuner/idrottshallar/bostadsrättsförening.

The kundtyp per EFX slug:

| slug | kundtyp | vertical |
|---|---|---|
| `villor` | privat | service |
| `radhus` | privat | service |
| `restauranger` | foretag | foretag_brf |
| `hotell` | foretag | foretag_brf |
| `kontor` | foretag | foretag_brf |
| `butik` | foretag | foretag_brf |
| `kommuner` | foretag | foretag_brf |
| `idrottshallar` | foretag | foretag_brf |
| `foretag` | foretag | foretag_brf |
| `byggforetag` | foretag | foretag_brf |
| `entreprenad` | foretag | foretag_brf |
| `tredjepartsinstallationer` | foretag | foretag_brf |
| `bostadsrattsforening` | brf | foretag_brf |

### 4.6 ORT regexes — 3 patterns (lines 190–192)

```js
if(m=p.match(/^\/laddbox\/([a-z0-9åäö-]+)$/)) return {render:true,kundtyp:'privat',tjanst:'Laddbox',tjanstLocked:true,ort:ortName(m[1]),ortWord:'Laddbox',vertical:'laddbox',source:p};
if(m=p.match(/^\/elinstallation\/([a-z0-9åäö-]+)$/)) return {render:true,kundtyp:'privat',tjanst:'Elinstallation',tjanstLocked:true,ort:ortName(m[1]),ortWord:'Elinstallation',vertical:'service',source:p};
if(m=p.match(/^\/elektriker\/([a-z0-9åäö-]+)$/)) return {render:true,kundtyp:'privat',ask:true,ort:ortName(m[1]),ortWord:'Elektriker',vertical:'service',source:p};
```

| pattern | tjänst | chip | vertical | toggle? |
|---|---|---|---|---|
| `/laddbox/<ort>` | **LOCKED** "Laddbox" | "Laddbox i <Ort>" | `laddbox` | yes (kundtyp unknown) |
| `/elinstallation/<ort>` | **LOCKED** "Elinstallation" | "Elinstallation i <Ort>" | `service` | yes |
| `/elektriker/<ort>` | **ASKED** (broad) | "Elektriker i <Ort>" | `service` | yes |

These 3 regexes cover ~150 ort pages with zero per-ort setup; a brand-new ort
inherits day one. The ort word for the chip is built by `tjanstBlock()` as
`cfg.ortWord+' i '+cfg.ort` (line 231). `ortName()` resolves a pretty name from
`SV_ORT` or title-cases the slug.

The `$` anchors prevent a deeper path (`/laddbox/nacka/extra`) from matching;
such a path falls through to the catch-all (§4.9).

### 4.7 Pillar pages (lines 193–195)

```js
if(p==='/elektriker') return {render:true,kundtyp:'privat',ask:true,vertical:'service',source:p};
if(p==='/laddbox') return {render:true,kundtyp:'privat',tjanst:'Laddbox',tjanstLocked:true,vertical:'laddbox',source:p};
if(p==='/elinstallation') return {render:true,kundtyp:'privat',tjanst:'Elinstallation',tjanstLocked:true,vertical:'service',source:p};
```

- `/elektriker` → broad, asks tjänst, keeps the toggle.
- `/laddbox` → tjänst LOCKED "Laddbox", `vertical:'laddbox'`, keeps the toggle.
- `/elinstallation` → tjänst LOCKED "Elinstallation", `vertical:'service'`, keeps
  the toggle.

(The `/elservice` pillar is handled earlier, in §4.4.)

### 4.8 Fallback — never crash (line 196)

```js
return {render:true,kundtyp:'privat',ask:true,vertical:'oklart',source:p};
```

Any path not matched above → a renderable, callable lead form: kundtyp `privat`,
asks tjänst, `vertical:'oklart'`. This is the invariant safety net. It is why a
typo'd URL, an unmapped service slug, or a brand-new page never breaks — the
visitor always gets a working form, and the booker gets a lead tagged
`oklart` to triage.

### 4.9 The try/catch around resolve (lines 256, 259, 265)

`render()` calls `resolve()` inside a `try`; if it somehow throws, it manufactures
the same fallback config (line 256). The whole render body is wrapped again so a
render error falls back to the error card (line 265). Two belts and braces around
"never show a broken box".

---

## 5. Segment seeding + EFX lock (line 258)

```js
if(cfg.kundtyp&&(cfg.forLabel||!st.segTouched))st.seg=cfg.kundtyp; /* EFX: kundtyp är låst av sidan */
```

This single line governs the segment toggle:

- On a **non-EFX** page (`cfg.forLabel` falsy), `st.seg` is seeded from
  `cfg.kundtyp` only until the user touches the toggle (`!st.segTouched`). After a
  manual change, the user's choice persists across re-renders.
- On an **EFX** page (`cfg.forLabel` truthy), `cfg.forLabel || …` short-circuits
  to true on every render, so `st.seg` is **always forced** back to the page's
  kundtyp. The EFX lock can't be defeated.

The toggle markup itself is only emitted when NOT EFX (line 239):

```js
'<form novalidate>'+(cfg.forLabel?'':'<div class="group">'+seg()+'</div>')+
```

So EFX pages render **no** Privat/BRF/Företag toggle at all — the kundtyp is
known from the slug, which removes friction and guarantees correct CRM
segmentation. This is the decided behaviour (it RESOLVES the stale spec §9.1,
which said EFX kundtyp was "just prefilled / open").

---

## 6. The render lifecycle (lines 214–266)

### 6.1 `render()` (line 253)

Order each call:

1. `snap()` — read current DOM field values into `st.vals` and `st.gdpr` BEFORE
   wiping the DOM.
2. `resolve(rawPath)` → `cfg`; store on `st.cfg`.
3. seed/lock `st.seg` (§5).
4. branch:
   - `!cfg.render` → `excludeCard(cfg)`, return.
   - `st.error` → `errorCard()`, focus its heading, return.
   - `st.done` → `doneCard()`, focus heading, announce success, return.
   - else → `formCard(cfg)` then `fill()`.
5. any exception → `errorCard()`.

### 6.2 `snap()` / `fill()` — field preservation (lines 215–218)

```js
function snap(){FIELDS.forEach(function(id){var e=document.getElementById('aof-'+id);if(e)st.vals[id]=e.value;});var g=document.getElementById('aof-gdpr');if(g)st.gdpr=g.checked;}
function fill(){FIELDS.forEach(function(id){var e=document.getElementById('aof-'+id);if(e&&st.vals[id]!=null)e.value=st.vals[id];});var g=document.getElementById('aof-gdpr');if(g)g.checked=!!st.gdpr;
  var tjEl=document.getElementById('aof-tj');if(tjEl&&st.vals.tj&&tjEl.value!==st.vals.tj)tjEl.value=''; /* val som saknas i nya segmentets lista -> snäpp till platshållaren (dölj ej tyst) */
  var bi=document.getElementById('aof-bilder');if(bi&&st.files&&st.files.length){try{var dt=new DataTransfer();st.files.forEach(function(f){dt.items.add(f);});bi.files=dt.files;}catch(e){}}}
```

`FIELDS` (line 200) is the canonical list of preserved field ids:
`['namn','kontakt','telefon','postnr','epost','beskriv','adress','orgname','orgnr','tj','tid']`.
Anything not in this list is lost on re-render — **add new fields here**.

Two subtle behaviours to preserve:
- **The tj snap-to-placeholder** (line 217): when the user switches segment, the
  new segment's select may not contain their previously chosen tjänst. Rather than
  silently keeping a stale value, `fill()` resets the select to the empty
  placeholder. Don't change this to "hide silently".
- **File restoration** (line 218): a `FileList` cannot be set via `.value`, so it
  is rebuilt from `st.files` via `DataTransfer`. The `try/catch` is intentional —
  some browsers throw on `DataTransfer` construction, and that must not break the
  render. There is also a visible note ("Byter du formulärtyp får du välja
  bilderna igen.") because this path is best-effort.

### 6.3 The in-place disclosure toggle (line 307)

```js
if(act==='toggle'){st.open=!st.open;var d=a.closest('.disc');if(d){d.setAttribute('data-open',st.open);a.setAttribute('aria-expanded',st.open);}else{render();}}
```

This is **deliberately NOT a full re-render**. Toggling "Fler detaljer" flips
`data-open` on the `.disc` wrapper (CSS shows/hides via
`.disc[data-open="false"] .disc-body{display:none}`, line 81) and updates
`aria-expanded` in place. This preserves keyboard focus on the toggle button AND
keeps any selected files / typed enrichment intact. Only if it can't find the
`.disc` wrapper does it fall back to `render()`. **Do not "simplify" this to a
re-render** — you would lose focus and re-trigger the file-restore dance on every
toggle.

### 6.4 Card builders

- `formCard(cfg)` (line 237) — header = title `Få kostnadsfri rådgivning!`
  (font Outfit, weight 400, **27px desktop / 24px mobile**, one line) + subtitle
  `Vår behöriga elektriker återkommer via telefon!` (Outfit, weight 300,
  **14px desktop / 15px base**, opacity .9) — both keep their owner-mandated `!`;
  then the optional segment toggle, `tjanstBlock` + `requiredFields`, the
  disclosure with `enrich`, the consent checkbox, the honeypot, the submit button.
- `tjanstBlock(cfg)` (line 229) — builds the chip and, for EFX, the required
  in-view service select. `isEFX = !!cfg.forLabel`. Chip text: EFX → "Elektriker
  för <forLabel>"; ort → "<ortWord> i <ort>"; locked service → "Gäller:
  <tjänst>"; else none.
- `requiredFields(cfg)` (line 223) — the required field set per segment (see §7).
- `serviceSelect(cfg,req)` (line 228) — the "Vad gäller arbetet?" `<select>`;
  options are `PRIVAT_OPTS` for privat else `cfg.opts || ORG_OPTS`.
- `enrich(cfg)` (line 236) — the optional disclosure body: the asked service
  select (only when `cfg.ask && !cfg.forLabel` — i.e. non-EFX asked pages put the
  select HERE, EFX puts it in the main view), org.nr + address (org segments
  only), free-text beskrivning, tidsram, image upload.
- `excludeCard(cfg)` (line 246) — eljour phone block or the generic "not shown"
  note.
- `doneCard()` (line 250) / `errorCard()` (line 251) — success / failure states.

---

## 7. `validate()` — per-segment required sets + regexes (lines 269–286)

```js
var ids=(st.seg==='privat')?['namn','telefon','epost','adress','postnr']:['orgname','kontakt','telefon','epost','postnr'];
if(st.cfg&&st.cfg.forLabel)ids.push('tj'); /* EFX: "Vad gäller arbetet?" syns i huvudvyn -> obligatorisk */
```

- **privat** required: `namn, telefon, epost, adress, postnr`.
- **brf / foretag** required: `orgname, kontakt, telefon, epost, postnr`.
- **EFX only** (`cfg.forLabel`): additionally `tj` — because on EFX the service
  select is in the main view, so it's required. On non-EFX asked pages the select
  lives in the optional disclosure and is NOT required.

Field rules (lines 275–279):

| field | rule |
|---|---|
| `postnr` | `/^\d{3}\s?\d{2}$/` — five digits, optional single space. |
| `telefon` | strip spaces/dashes/parens, convert leading `+46`→`0`, strip non-digits, require length ≥ 7. |
| `epost` | `/^[^@\s]+@[^@\s]+\.[^@\s]+$/`. |
| `tj` | non-empty (a tjänst must be picked). |
| everything else | `value.length < 2` is invalid. |

For each invalid field: the help `<p>` gets a message from `MSG`, the `.fld` gets
`.err` (which the CSS styles with a teal ring, line 59), and the input gets
`aria-invalid="true"`. The first invalid field is captured and focused by
`submit()` (line 292). Consent is validated separately (lines 282–283): unchecked
→ not ok, `aria-invalid="true"`, the help becomes visible, and it can be the
`first` focus target. On any failure `announce('Kontrollera de markerade
fälten.')` updates the live region.

**The minimal callable lead is still namn + telefon + postnr + GDPR** — but
privat additionally requires e-post + adress (booking-team rule), and org
segments require orgname + kontakt. `org.nr` is NOT in any required set — it is
optional enrichment (see §8 and the decisions doc).

---

## 8. `buildPayload()` — field by field (lines 287–289)

```js
function buildPayload(){var cfg=st.cfg;var tjEl=document.getElementById('aof-tj');var tj=cfg.tjanstLocked?cfg.tjanst:(tjEl?tjEl.value:'');var tid=document.getElementById('aof-tid');var hp=document.getElementById('aof-company_url');var bi=document.getElementById('aof-bilder');
  return {full_name:(st.seg==='privat'?val('namn'):val('kontakt')),phone_e164:toE164(val('telefon')),postal_code:val('postnr').replace(/\D/g,''),email:val('epost'),org_number:(st.seg!=='privat'&&val('orgnr')?val('orgnr').replace(/\D/g,''):null),org_name:(st.seg!=='privat'?val('orgname'):null),kundtyp:st.seg,vertical:cfg.vertical,tjanst_intresse:tj||null,bradska:(tid&&BRADSKA[tid.value])||null,beskrivning:val('beskriv')||null,street_address:val('adress')||null,bilder_count:((bi&&bi.files&&bi.files.length)?bi.files.length:(st.files?st.files.length:0)),kallsida:cfg.source,source:'bricks',source_form:SOURCE_FORM,consent:true,policy_version:POLICY_VERSION,company_url:(hp?hp.value:'')};
}
```

| key | value derivation | notes |
|---|---|---|
| `full_name` | privat → `namn`; org → `kontakt` | the contact person's name in both cases. |
| `phone_e164` | `toE164(telefon)` | normalised to `+46…` (line 149). |
| `postal_code` | `postnr` with non-digits stripped | digits only. |
| `email` | `epost` | not nulled (always sent; required in all segments). |
| `org_number` | org segments only AND non-empty → digits; else `null` | nullable by decision; backend flags missing on brf/foretag. |
| `org_name` | org segments only → `orgname`; privat → `null` | |
| `kundtyp` | `st.seg` | `'privat'\|'brf'\|'foretag'`. |
| `vertical` | `cfg.vertical` | `'service'\|'laddbox'\|'batteri'\|'foretag_brf'\|'oklart'`. |
| `tjanst_intresse` | locked → `cfg.tjanst`; else select value; `null` if empty | |
| `bradska` | `BRADSKA[tid.value]` or `null` | enum `24h\|72h\|1_2v\|flexibel`. |
| `beskrivning` | `beskriv` or `null` | |
| `street_address` | `adress` or `null` | |
| `bilder_count` | live `files.length` else `st.files.length` else 0 | images go via a SEPARATE multipart channel; this is just the count. |
| `kallsida` | `cfg.source` | the normalised full path. |
| `source` | `'bricks'` | constant. |
| `source_form` | `SOURCE_FORM` (currently `3`) | TODO confirm with dash team. |
| `consent` | `true` | the form only builds a payload after consent validates; the server stamps `consent_at`. |
| `policy_version` | `POLICY_VERSION` | TODO confirm version string. |
| `company_url` | honeypot value | MUST be empty; server rejects if filled. |

Note `consent_at` is NOT in the payload — it is server-stamped (this corrects
the stale spec §7, which listed it as a posted field). Images are NOT in the
payload — only `bilder_count`.

---

## 9. The honeypot (lines 243, 293)

Markup (off-screen, hidden from AT, not tab-reachable):

```js
'<input type="text" class="hp" id="aof-company_url" name="company_url" tabindex="-1" autocomplete="off" aria-hidden="true">'
```

Guard in `submit()`:

```js
var hp=document.getElementById('aof-company_url');if(hp&&hp.value)return; /* honeypot */
```

A real user never sees or fills `company_url`; a bot that fills every field trips
it and the submit silently returns. It is also sent in the payload so the
**server must independently reject any request where `company_url` is non-empty**
— the client check is the first line, not the only line.

---

## 10. Per-page BEHAVIOUR MATRIX

`Toggle?` = is the Privat/BRF/Företag segment switch shown. `Tjänst` =
locked/asked/none and where the select lives. `Org.nr` = whether the optional
org-number field renders (org segments only). `kundtyp` = the seeded/locked
segment. `vertical` = the payload `vertical`.

| Page type | Toggle? | Tjänst | Org.nr field | kundtyp | vertical |
|---|---|---|---|---|---|
| `/elservice/<slug>` (22 mapped) | yes | **LOCKED** chip "Gäller: <tjänst>" | only if user picks BRF/Företag | privat (default, switchable) | `service` |
| `/elservice` (pillar) | yes | ASKED (in disclosure) | only if BRF/Företag | privat (default) | `service` |
| EFX `villor`, `radhus` | **no (locked)** | ASKED in **main view**, **required**; "Elektriker för villa/radhus" chip | n/a (privat) | **privat (locked)** | `service` |
| EFX `restauranger, hotell, kontor, butik, kommuner, idrottshallar, foretag, byggforetag, entreprenad, tredjepartsinstallationer` | **no (locked)** | ASKED in **main view**, **required**; "Elektriker för <X>" chip | rendered (org); **optional** | **foretag (locked)** | `foretag_brf` |
| EFX `bostadsrattsforening` | **no (locked)** | ASKED in **main view**, **required**; "Elektriker för bostadsrättsförening" chip | rendered (org); **optional** | **brf (locked)** | `foretag_brf` |
| `/laddbox/<ort>` | yes | **LOCKED** "Laddbox" chip "Laddbox i <Ort>" | only if BRF/Företag | privat (default) | `laddbox` |
| `/elinstallation/<ort>` | yes | **LOCKED** "Elinstallation" chip "Elinstallation i <Ort>" | only if BRF/Företag | privat (default) | `service` |
| `/elektriker/<ort>` | yes | ASKED (in disclosure); chip "Elektriker i <Ort>" | only if BRF/Företag | privat (default) | `service` |
| `/elektriker` (pillar) | yes | ASKED (in disclosure) | only if BRF/Företag | privat (default) | `service` |
| `/laddbox` (pillar) | yes | **LOCKED** "Laddbox" | only if BRF/Företag | privat (default) | `laddbox` |
| `/elinstallation` (pillar) | yes | **LOCKED** "Elinstallation" | only if BRF/Företag | privat (default) | `service` |
| Fallback (any unmatched path) | yes | ASKED (in disclosure) | only if BRF/Företag | privat (default) | `oklart` |
| `/eljour/*` | — | — | — | — | **render:false** (phone block) |
| `/solcellsbatterier/*`, `/laddboxar/*`, `/batterilagring/*` | — | — | — | — | **render:false** (produkt) |
| utility roots + subpaths | — | — | — | — | **render:false** (utility) |

Key cross-cutting rules:
- **org.nr only ever renders for org segments** (`enrich` builds it via
  `isOrg = st.seg!=='privat'`, line 236) and is **always optional** (never in the
  required set). To make it mandatory later, the one-spot change is documented in
  `05-data-contract.md` (add `'orgnr'` to the org `ids` array in `validate()`,
  line 270).
- **The select lives in the main view + required ONLY on EFX**; everywhere else
  an asked select sits in the optional disclosure. This is the
  `cfg.ask && !cfg.forLabel` split (line 236) vs `cfg.ask && isEFX` (line 233).
- **kundtyp is locked (no toggle) ONLY on EFX**; every other page type keeps the
  toggle because the visitor type is unknown there.

---

## 11. Accessibility (do not regress)

- **Segment toggle = ARIA radiogroup** (line 222): `role="radiogroup"`, buttons
  `role="radio"` with `aria-checked`, **roving tabindex** (selected = `0`, others
  = `-1`). Arrow keys / Home / End move selection and focus (lines 315–322),
  wrapping via `(i+d+3)%3`.
- **`aria-required`** on every required input (set in `fld()`, line 221, and on
  the service select, line 228).
- **`aria-invalid`** toggled per field in `validate()` (line 281) and on consent
  (line 283); the `.fld.err` style gives a visible teal ring.
- **Live region** (line 134): `<p id="aof-live" role="status" aria-live="polite">`
  is updated via `announce()` on validation failure and on success.
- **`aria-describedby`** wires each input to its help `<p>` (line 221).
- **Focus management:** on validation failure the first bad field is focused
  (line 292); the success and error cards focus their `<h2 tabindex="-1">`
  (lines 261–262) so screen-reader users land on the new state.
- **Enter-to-submit** (line 314): Enter inside an `input.inp` calls `submit()`.
  Select / textarea / checkbox are excluded (they aren't `input.inp`), so Enter
  behaves naturally there. This gives keyboard/AT users implicit form submission
  without a native submit button (the button is `type="button"`).
- **Honeypot** is `aria-hidden="true"` + `tabindex="-1"` so AT and keyboard users
  never reach it.
- **Reduced motion** (line 127): all transitions are disabled under
  `prefers-reduced-motion: reduce`.
- **Duplicate-element guard** (lines 205–208): if Bricks renders the global
  element twice, only the first initialises; extras are hidden and a console
  warning fires. Prevents a visibly empty, broken second form.

---

## 12. Submit flow (lines 290–302) — for completeness

1. guard `st.sending`.
2. `validate()`; if not ok, focus first bad field and return.
3. honeypot guard; if `company_url` filled, return silently.
4. `buildPayload()`; on throw → error card.
5. store pretty JSON in `st.lastPayload`.
6. if `PREVIEW` → set `st.done`, render (shows payload), return (no POST).
7. else set `sending`, disable+relabel the button ("Skickar…"), POST JSON with a
   **10-second `AbortController` timeout**, `Content-Type: application/json`.
8. on a non-2xx or network/timeout error → error card (with retry). The server
   must therefore return 2xx ONLY on a successful two-row write — a premature 2xx
   would make the form claim success while the lead is lost.

---

## 13. What you must not break (quick checklist)

- `resolve()` never throws and always returns a config; the fallback stays
  callable.
- The EXCLUDE subpath matching (`(\/|$)` and `u+'/'`) stays — don't reduce to
  exact match.
- The EFX lock: no segment toggle on EFX, kundtyp forced every render.
- `FIELDS` includes every preserved field; `snap`/`fill` keep state across the
  full re-render.
- The disclosure toggle stays in-place (focus + files survive).
- The honeypot stays hidden and the server still independently rejects filled
  `company_url`.
- `PREVIEW` stays host-derived so production can't drop into preview.
- Cache-bust `?v=` per release and spot-check 4–5 representative page types — one
  bad edit breaks all ~165 forms at once.
