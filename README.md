# Hero 2 — Ampy offertformulär

Redesign av Ampys mest dominanta hero-formulär (ligger på alla tjänstesidor: elektriker / eljour / laddbox / elinstallation i ort, elcentral, för BRF, för företag). **Endast formuläret** — heron i övrigt är orörd.

## Live
**https://julius447.github.io/Hero-2-form/**

`index.html` är en fristående review-harness. Reglagen högst upp är ställning för granskning, inte en del av produkten:
- **Variant** — växla mellan de två förslagen.
- **Sida** — se hur samma formulär anpassar sig per tjänstesida (förvald kundtyp, förifylld/låst tjänst, eljour = Ring-först).
- **Vy** — desktop / mobil.

## De två varianterna
- **Variant 1 — "Kontakt först" (2 skärmar):** skärm 1 fångar det ringbara leadet på riktigt ("Skicka förfrågan" = sann submit, samtycke på skärm 1, ett `lead_id`). Skärm 2 = frivillig efter-submit-berikning. Löser det vilseledande "submit-sen-fler-steg"-problemet och mapping (en post som PATCH:as, inte två).
- **Variant 2 — "En skärm, ingen wizard":** alla minimala fält + "Fler detaljer (valfritt)"-utfäll + en submit. Enklast, ärligast, noll mapping. **Rekommenderad default för hero.**

## First principles
Minsta ringbara lead = **Namn + Telefon + Postnummer + GDPR** (+ honeypot). Sida och tjänst känns av kontexten. Allt annat (e-post, beskrivning, adress, bilder, tidsram, org.nr) = frivillig berikning som aldrig blockerar submit.

## Bevarad design
Ampys nuvarande 8/10-uttryck behålls: glas-kort (`#090B32 → #5EB1BF`, blur), Outfit, neon-CTA (`#55FF9A → #00FFDA`), vita pill-fält (radie 16). Förbättringarna ligger i UX, spacing, fält-/segment-/fokus-/fel-states, progress (V1), mobil-omlayout och candour-fixad copy (noll `!`).

## Att göra innan skarp drift
1. Responsivitet: harnessen växlar mobil via klass — produktion ska använda `@media (max-width: 480px)`.
2. Self-hosta Outfit (ingen Google Fonts-request).
3. Backend: `POST → lead_id`, `PATCH`-berikning, honeypot, samtyckes-tidsstämpel (mot `deals`/`customers`).
4. [VERIFY] mot ampy-foretagsdata: "behörig elektriker / oftast inom en arbetsdag", eljour-numrets jour-bemanning, `tjanst_intresse` per sida.

## Innehåll
- `index.html` — formuläret (review-harness, det som serveras).
- `BRF/ · Företag/ · Mobil/ · Privatperson/` — designreferens (nuvarande live-formulär, Figma-export).
- `CSS kod Formulär.md` — CSS-dump från nuvarande formulär.
