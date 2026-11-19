# Contributing to ISP Billing & FreeRADIUS Automation

1. **Zero-Risk Isolated Testing:** Never commit production NAS IPs, RADIUS secrets, or MFS keys. Keep all secrets in `.env` (git-ignored).
2. **Pull Requests:** Every PR must pass `.github/workflows/ci.yml` (`php -l`, `phpunit`, `bash -n`, and `docker compose config`).
3. **Database Changes:** All schema changes must include Citus distribution compatibility and NBR/BTRC audit compliance where applicable.
