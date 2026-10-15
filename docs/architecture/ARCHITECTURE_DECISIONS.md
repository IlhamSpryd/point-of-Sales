# APEIRON POS — ARCHITECTURAL DECISIONS RECORD (ADR)

- **ADR-001 (MySQL/MariaDB Target Engine):** MySQL 8.0+ / MariaDB 10.6+ is the sole targeted database engine. No Postgres-specific triggers or polyglot compromises.
- **ADR-002 (3-Tier Entity Ownership):** Strict separation into Tier 1 (Platform), Tier 2 (Tenant Cross-Branch), Tier 3A (Store-Owned), and Tier 3B (Hybrid).
- **ADR-003 (Fail-Closed Context):** Absence of tenant context defaults to `1 = 0` (empty result), never failing open.
- **ADR-004 (Append-Only Ledgers):** Ledgers (`stock_movements`, `ingredient_stock_movements`, `loyalty_ledger`, `cash_drawer_movements`, `payments`) are append-only; `UPDATE` and `DELETE` are forbidden by database triggers.
- **ADR-005 (Transactional Email Outbox):** Onboarding emails and provisioning notifications are recorded in `email_outbox` inside the main business transaction and processed asynchronously by scheduled worker.
