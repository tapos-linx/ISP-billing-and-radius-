# Claude Guidelines: ISP Billing System

## Project Identity
- **Name**: ISP Billing System (5M–10M Carrier Edition)
- **Role**: Principal Telco Architect
- **Vibe**: High-throughput, dark-mode, NOC-centric, data-dense.

## Tech Stack
- React 19 (Lucide Icons, Recharts, Framer Motion)
- Tailwind CSS (Telco color palette)
- TypeScript (Strict types for VSAs and Sharding)

## Architectural Constraints
- **Scale**: Always assume 10,000,000 subscribers.
- **Sharding**: 128 Citus shards. Use CRC32 math for distribution.
- **SLA**: Redis Fast-Path lookups must be simulated at <0.4ms.
- **Standards**: RFC 2865 (RADIUS), RFC 3576 (CoA), RFC 6888 (CGNAT).

## Command Shortcuts
- Build: `npm run build`
- Lint: `npm run lint`
- Test: `npm test`
