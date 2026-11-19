# Google AI Studio — 1-Click Import & Build Instructions

1. Open **Google AI Studio** -> Create a new **Build Mode** project (`ISP-billing-and-radius`).
2. Upload **`ISP_Billing_AI_Studio_Import_Pack.zip`** (or import from your Google Drive folder `AI_Studio_OneClick_Import_Pack`).
3. Paste this 1-click prompt into AI Studio:

> "Read `docs/PRD.md`, `docs/STAGE_3_UX_AND_FLOWS.md`, `docs/STAGE_4_HLD_AND_ADR.md`, and `docs/openapi.yaml`. Use `src/NocAndPortalApp.tsx` as the primary React 19 + TypeScript + Tailwind frontend and wire it to a runnable Express/TypeScript API matching `docs/openapi.yaml` (with an in-memory/mock adapter for live AI Studio preview alongside the production `backend/` PHP/Citus/Redis/FreeRADIUS and `infra/` Cloud Run files). Never commit `.env`; keep all secrets in environment variables."
