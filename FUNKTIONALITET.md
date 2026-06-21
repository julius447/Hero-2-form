# Hero 2 offertformulär — funktionalitetsspec (design → implementation)

Status: **design klar**, detta dokument tar formuläret till **WordPress/Bricks-implementations-redo**.
Syntetiserat av PO utifrån agentteam (CTO/systemarkitekt · CMO/strateg · copywriter/research · projektledare).
Källa: Ampys sitemap (`document_pdf.pdf`) + CRM-schema (`ampy-dash-2`) + nuvarande build (`build/index.html`).

> **Ärlighetsnotis:** två agenter är oense om ett faktum (se §6 "Energilösningar") och en kritisk
> infrastruktur saknas (se §8 BLOCKER: ingen lead-endpoint finns ännu). Inget av detta är gömt — det
> styr vad som måste beslutas innan bygge.

---

## 1. Scope — var formuläret ska (och inte ska) finnas

**JA (≈165 sidor):**
- **Service-sidor** `/elservice/<slug>` — 22 st (elcentral, golvvarme, spotlights, lastbalansering, belysning, vitvaror, badrum, elbesiktning, jordfelsbrytare, felsokning-av-el …)
- **EFX (Elektriker för X)** root-slugs — 13 st (villor, radhus, restauranger, hotell, kontor, butik, kommuner, idrottshallar, foretag, byggforetag, entreprenad, bostadsrattsforening, tredjepartsinstallationer)
- **`/elektriker/<ort>`**, **`/laddbox/<ort>`**, **`/elinstallation/<ort>`** — ~50 orter vardera
- Pelarsidor `/elektriker/` `/laddbox/` `/elinstallation/` `/elservice/`

**NEJ:** `/eljour/<ort>` (telefon-först-block, inget offertformulär) · produktsidor `/solcellsbatterier/<produkt>` + `/laddboxar/<produkt>` · utility-sidor.

---

## 2. Arkitektur — EN snippet, unik per sida, NOLL manuell per-sida-redigering

**Rekommendation (CTO): en slug/path-baserad config-resolver i ETT Bricks globalt Code-element.**
Formuläret byggs som ETT återanvändbart Bricks globalt element/template i Hero 2-blocket. En liten resolver
läser sidans path (via Bricks dynamic data `data-ampy-path="{post_slug}"` → ingen flash) och slår upp rätt
config. Detta är samma mönster som redan finns i CRM:t (`ampy-dash-2` slug→vertical-mappning med `oklart`-fallback)
och i nuvarande build (`SC`-objektet) — vi flyttar bara logiken till en path-resolver.

**Resolutionsordning (första träff vinner):**
1. **Per-sida-override** (escape hatch): valfritt ACF-fält `ampy_form_override` (JSON) på enstaka bespoke-sidor. ~200 sidor lämnar det tomt.
2. **SERVICE-map** (22 slugs, exakt): `/elservice/<slug>` → `{tjanst:'<låst tjänst>', tjanstLocked:true, kundtyp:'privat'}`.
3. **EFX-map** (13 slugs, exakt): root-slug → `{kundtyp, kundtypLocked, vertical}`.
4. **ORT-mönster** (3 regex täcker ~150 sidor, ingen per-ort-setup):
   `^/laddbox/[^/]+$` · `^/elinstallation/[^/]+$` · `^/elektriker/[^/]+$` → ort härleds ur sista segmentet.
5. **Pelarsidor** → breda defaults.
6. **Fallback** (ingen träff) → `{kundtyp:'privat', askTjanst:true, vertical:'oklart'}` — kraschar aldrig, ger ringbart lead.
7. **EXCLUDE** (utvärderas först): `/eljour/*`, produktsidor, utility → `render:false` (inget formulär injiceras).

**Varför inte ACF-per-sida:** ~200 ort-sidor är mönster-identiska → ett regex konfigurerar alla + en 51:a ort
ärver automatiskt dag ett. ACF skulle betyda ~200 manuella sidor (precis det du vill undvika) + risk för
halv-konfigurerade sidor. ACF behålls bara som override-lucka för enstaka undantag.

**Underhåll:** allt bor i EN diffbar fil (resolver + de 22 + 13 maps + ort-namntabell + taxonomi). Bricks
globalt element ⇒ en design-ändring slår igenom på alla ~200 placeringar automatiskt. Cache-busta `?v=` per release.
**Blast radius:** en dålig edit bryter alla formulär samtidigt → kör `ampy-syn` spot-check på 4–5 representativa
sidtyper + `?v=` före varje release.

---

## 3. Config-matris (per sidgrupp)

| Sidgrupp | URL-mönster | Default kundtyp | Låst? | Org.nr | Tjänste-fält | Förifyllt |
|---|---|---|---|---|---|---|
| Service-sidor | `/elservice/<slug>` | Privat | nej (default) | endast vid BRF/Företag | **LÅST** till sidans tjänst (chip) | tjanst (dold), källsida |
| EFX privat | villor, radhus | **Privat** | **ja** | nej | FRÅGAS | kundtyp, källsida |
| EFX företag/org | restauranger, hotell, kontor, butik, kommuner, idrottshallar, foretag, byggforetag, entreprenad, tredjepartsinstallationer | **Företag** | **ja** | **KRÄVS** + företagsnamn | FRÅGAS | kundtyp, källsida |
| EFX BRF | bostadsrattsforening | **BRF** | **ja** | **KRÄVS** + föreningsnamn | FRÅGAS | kundtyp, källsida |
| Laddbox i ort | `/laddbox/<ort>` | Privat | nej | endast vid BRF/Företag | **LÅST** "Laddbox" | tjanst, ort (ur slug) |
| Elinstallation i ort | `/elinstallation/<ort>` | Privat | nej | endast vid BRF/Företag | **LÅST** "Elinstallation" | tjanst, ort |
| Elektriker i ort | `/elektriker/<ort>` | Privat | nej | endast vid BRF/Företag | **FRÅGAS** (bred) | ort |
| Pelarsidor | `/elektriker/` `/laddbox/` `/elinstallation/` `/elservice/` | Privat | nej | endast vid BRF/Företag | laddbox=LÅST, övriga FRÅGAS | källsida |

**Kärnregel (org.nr):** privat → org.nr-fältet renderas ALDRIG. BRF/Företag → org.nr + förenings-/företagsnamn
är de enda obligatoriska berikningsfälten. Segment-bytet växlar org.nr på/av deterministiskt.

---

## 4. EFX per vertical — kundtyp + relevanta tjänste-alternativ (copy-analys)

`ni`-tilltal mot verksamhet, `du` mot villaägare. Inga superlativ/utropstecken. Chiparna beskriver ARBETET, aldrig bevis/siffror.

| Vertical | Kundtyp | Relevanta alternativ (ordning = prioritet) | "Gäller"-chip |
|---|---|---|---|
| villor | Privat | Elinstallation · Belysning · Energilösningar · Kök och badrum · Elfel · Laddbox · Annat | el till din villa |
| radhus | Privat | Elinstallation · Belysning · Kök och badrum · Elfel · Laddbox · Energilösningar · Annat | el till ditt radhus |
| restauranger | Företag | **Storkök/fläkt/trefas** · Större elinstallation · Belysning · Service & underhåll · Elfel · Laddbox · Annat | el till er restaurang |
| hotell | Företag | Större elinstallation · Belysning · Laddbox · Service & underhåll · Energi & effekt · Elbesiktning · Annat | el till ert hotell |
| kontor | Företag | Större elinstallation · Belysning · Laddbox · Service & underhåll · Elfel · Annat | el till ert kontor |
| butik | Företag | **Belysning** · Större elinstallation · Service & underhåll · Laddbox · Elfel · Annat | el till er butik |
| kommuner | Företag (org) | Större elinstallation · Belysning · Elbesiktning · Laddbox · Service & underhåll · Energi & effekt · Annat | el till er kommun |
| idrottshallar | Företag (org) | Större elinstallation · **Belysning** · Energi & effekt · Service & underhåll · Elbesiktning · Annat | el till er idrottshall |
| foretag | Företag | (hela org-supersetet — bred B2B) | el till ert företag |
| byggforetag | Företag | Större elinstallation · Elbesiktning · Belysning · Service & underhåll · Elfel · Annat | el i era byggprojekt |
| entreprenad | Företag | Större elinstallation · Elbesiktning · Belysning · Laddbox · Service & underhåll · Annat | el i ert entreprenaduppdrag |
| bostadsrattsforening | **BRF** | **Laddbox** · Större elinstallation · Belysning · Elbesiktning · Service & underhåll · Energi & effekt · Annat | el till er förening |
| tredjepartsinstallationer | Företag | Större elinstallation · Laddbox · Belysning · Service & underhåll · Elbesiktning · Annat | tredjepartsinstallation |

**Owner-nyansen bekräftad:** villor/radhus = privatperson (inget org.nr) trots EFX-namnet. Allt annat = org.

---

## 5. Tjänste-taxonomi ("Vad gäller arbetet?")

**Nuvarande:** Elinstallation · Belysning · Energilösningar · Kök och badrum · Elfel · Annat.

**Rekommendation (copywriter):** behåll privat-basen, men inför ett **superset** där varje sida bara visar
sin delmängd. Org-tillägg som saknas idag och som org-kunder faktiskt frågar efter:
- **Laddbox / elbilsladdning** (eget alternativ — har egen finansiering; idag tvingat in i "Energilösningar/Annat")
- **Större elinstallation** (kommersiell skala ≠ hushålls-"Elinstallation")
- **Storkök / fläkt / trefas** (restaurang)
- **Service & underhåll** (fastighet/kommun/kontor/hotell — idag i "Annat")
- **Elbesiktning / kontroll** (BRF/kommun/bygg — idag i "Annat")
- **Energi & effekt (batteri/solel)** (org-variant av "Energilösningar")

---

## 6. ⚠️ VERIFIERA: "Energilösningar" — agenterna är OENSE

- **CTO:** "'Energilösningar' förekommer INGENSTANS i datalagret ([GAP]/troligen påhittat)."
- **CMO:** "Verifierat mot `ampy-dash-2` arbetstyp-enum (`energilosningar`) — exakt match; `lead-mapping.ts`
  routar 'Energilösningar' → `oklart` by design (laddbox vs batteri kan ej avgöras från ett fält)."

✅ **BESLUTAT (taxonomi §5–6): "Dela + org-superset"** — privat får Laddbox + Energi & effekt som egna val,
org-sidor får org-supersetet (Större elinstallation, Service & underhåll, Elbesiktning m.m.). Implementerat i resolvern.
**Kvarstår att VERIFIERA mot `ampy-dash-2`:** är `energilosningar` ett giltigt `arbetstyp`-enum? (påverkar bara hidden-värdets mappning, inte UI:t).
Bakgrund — **dela "Energilösningar" i "Laddbox" + "Energi & effekt (batteri/solel)"**
i selecten. Det minskar `oklart`-andelen och ger bokaren ett rent värde. (Tills bekräftat: behåll inte ett
påhittat enum-värde i `tjanst_intresse`, det är fri-text och bokaren läser det.)

---

## 7. Datamodell + CRM-mapping (verkligt schema, ampy-dash-2)

Ett lead = **två rader**: `customers` + `deals`.

**customers:** `full_name`, `phone_e164` (normalisera → +46), `email` (valfri), `street_address` (valfri "Adress"),
`postal_code` (obligatoriskt postnr), `org_number` (**krävs om kundtyp ∈ {brf, foretag}**, annars NULL).
Dedupe på `phone_e164`.

**deals:** `vertical` (enum `service|laddbox|batteri|foretag_brf|oklart` — från resolvern), `kundtyp`
(`privat|brf|foretag`), `tjanst_intresse` (det dolda tjänste-värdet), `bradska` (`24h|72h|1_2v|flexibel` —
mappa Tidsram-dropdownen), `beskrivning`, `bilder[]`, `lead_magnet_slug` (**återanvänd som källsida = full path**),
`source='bricks'`, `source_form` (se beslut), `lead_id` (returneras → valfri berikning PATCH:ar SAMMA rad).

**Dolda fält formuläret måste POSTa:** `tjanst_intresse`, `kundtyp`, `vertical`, `kallsida` (path),
`source_form`, **consent_at** (server-timestamp + policy-version), **honeypot** (`company_url`, måste vara tom).

**Minsta ringbara lead:** namn + telefon + postnr + GDPR (+ honeypot tom). Allt annat = berikning, blockerar aldrig submit.

---

## 8. 🔴 BLOCKERS (kritisk väg — måste lösas innan go-live)

1. **Ingen lead-endpoint finns.** `ampy-dash-2/supabase/functions` har bara `sms-*`. 'bricks'-källan känns igen
   i UI:t men INGET tar emot en POST. **Utan endpoint droppas varje lead** (samma fel som Elcentral-kollens
   null-webhook). Bygg `wp-json/ampy/v1/lead` ELLER en Supabase edge-function `bricks-lead` som gör två-tabells-skrivningen.
2. **`consent_at` saknar kolumn** i nuvarande migration. Måste läggas till (GDPR-krav: spara samtyckes-tidsstämpel + policy-version).
3. **Taxonomi-verifiering** ("Energilösningar", §6) innan `tjanst_intresse`-värden låses.
4. **(Separat, hero — ej formuläret):** "5.0 ★★★★★ Google" i vänsterspalten är ett förbjudet påstående (candour) → bort/beläggas före live.

---

## 9. Öppna beslut (owner) — med PO-rekommendation

| # | Fråga | PO-rekommendation |
|---|---|---|
| 1 | EFX-segment: låst eller bara förvalt? | ✅ **BESLUTAT: bara förvald (öppet)** — kundtyp förväljs per sida men är fritt bytbar; ingen låsning |
| 2 | "kommuner"/offentlig kundtyp (CRM har bara privat/brf/foretag) | ✅ **BESLUTAT: under foretag**, men namn-fältet får offentlig-anpassad etikett ("Förvaltning eller enhet" för kommun, "Verksamhetens namn" för idrottshall) |
| 3 | Service-sida + byte till BRF/Företag → behåll låst tjänst? | ✅ **BESLUTAT: behåll låst tjänst**; elcentral-chip ändrad till enbart "Elcentral" (ej "Byte av elcentral") |
| 4 | CRM-arbetstyp för golvvarme/felsokning/luftvarmepump (saknar eget enum) | golvvärme→elinstallation, felsökning→elfel, luftvärmepump→elinstallation; chip visar vänlig etikett |
| 5 | "Annat" med underrubriker (nämnt, ej byggt) | "Annat" → fritext i beskrivning; sub-options senare |
| 6 | Ort-fält: redigerbart eller dolt + eyebrow? | Dolt + i eyebrow; **postnr kvar som krav** (en ort = flera postnr) |
| 7 | Laddbox-copy per segment | Privat = grön teknik 50%; BRF/Företag = "Ladda bilen" (max 15 000 kr/laddpunkt) |
| 8 | `source_form`-värde (1=Kontakta oss, 3=Multi-steg) | Stäm av med dash-teamet; ev. **nytt värde** "hero-offert" |
| 9 | integritetspolicy-URL | Bekräfta exakt URL (jag använder `ampy.se/integritetspolicy`) |

---

## 10. Roadmap (design → implementation)

- **P0 — Config-signoff:** lås config-matrisen (§3), EFX-mappningen (§4), org.nr-reglerna. *Klar när: detta dok signerat.*
- **P1 — Copy/taxonomi-signoff:** lås taxonomin (§5–6), candour-copy per sida, consent-text + URL. *Klar när: copy-spec signerad.*
- **P2 — Frontend-produktion:** strippa review-harnessen, byt `SC`-objektet mot resolvern, `@media (max-width:480px)` (ej `.m`-klassen), self-hostad Outfit, honeypot, submit + PATCH-berikning.
- **P3 — Backend (kritisk väg):** bygg endpoint (två-tabells-skrivning, consent_at, dedupe på phone_e164, returnera lead_id). *Klar när: ett lead landar på staging.*
- **P4 — Bricks-integration:** globalt element + global class `.ampy-offert-form` + ett globalt Code-element med resolvern + `data-ampy-path` via dynamic data.
- **P5 — Instrumentering:** consent-gated dataLayer-events (form_view/submit/enrich) med vertical + källsida; KPI = kvalificerade leads / 1000 views.
- **P6 — QA:** `ampy-syn` desktop+mobil på en /laddbox/<ort>, en /elservice/elcentral, en EFX-företag, en EFX-villor, en pelarsida → bekräfta lås/förifyll/org.nr. `ampy-granskning` + `ampy-slutaudit`.
- **P7 — Go-live (batchat):** service-sidor → EFX → ort-sidor sist (störst volym). Kill-switch + `?v=`.

---

## 11. Owner-inputs som krävs för att starta bygget
endpoint-URL · integritetspolicy-URL · source_form-värde · signera taxonomi (§5–6) · EFX låst/öppet (§9.1) · kommun-kundtyp (§9.2) · bekräfta consent_at-kolumn kan läggas till.
