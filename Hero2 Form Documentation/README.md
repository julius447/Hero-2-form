# Ampy "Hero 2" offertformulär — implementation package

**Start here → [`for-chris/README.md`](for-chris/README.md)** (the master handover).

This package hands the production-ready Ampy Hero 2 lead form to the developer (Chris) and the AI
agents who will implement it in WordPress + the Bricks builder. The frontend is finished and
production-hardened; the remaining work is the PHP backend (the lead endpoint) and the Bricks wiring.

```
Hero2 Form Documentation/
├── README.md  ............................ this file
├── for-chris/  ........................... read these first (human handover)
│   ├── README.md  ........................ master orientation — START HERE
│   ├── ARCHITECTURE.md  .................. plain-English architecture + blast-radius discipline
│   └── GO-LIVE-CHECKLIST.md  ............. the actionable launch gate (acceptance criteria)
├── for-ai-agents/  ....................... hand these to the AI implementer, in order
│   ├── 00-START-HERE.md  ................. constraints + implement order
│   ├── 01-bricks-implementation.md  ..... exact step-by-step WordPress/Bricks build
│   ├── 02-the-form-component.md  ........ the component explained (resolver, maps, matrix)
│   ├── 03-sitemap-and-routing.md  ....... how the sitemap drives the form + how to add pages
│   ├── 04-php-backend.md  ............... the PHP endpoint explained + deploy + test plan
│   ├── 05-data-contract.md  ............. payload ↔ ampy-dash-2 field/enum mapping
│   └── 06-qa-and-acceptance.md  ......... invariants not to regress + acceptance tests
└── code/  ............................... the artifacts
    ├── ampy-offert-form.html  ........... the full self-contained component
    ├── bricks-css.css  .................. Bricks part 1 of 3 (Custom CSS)
    ├── bricks-markup.html  .............. Bricks part 2 of 3 (Hero 2 element)
    ├── bricks-script.js  ................ Bricks part 3 of 3 (global Code element)
    ├── ampy-lead-endpoint.php  .......... reference PHP lead endpoint (WP REST route)
    └── ampy-dash-2-migration.sql  ....... SQL migration (consent_at, org_name, indexes)
```

**Language:** the documentation is English; the form's UI strings and code comments are Swedish on
purpose (customer-facing copy in Ampy's voice — do not translate them).

**Honesty markers:** anything that could not be confirmed from the code is tagged `[VERIFY]` or `[GAP]`.
Treat those as open questions, not facts.
