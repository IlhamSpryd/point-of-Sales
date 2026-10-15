# APEIRON POS — ARCHITECTURE CONTRACT & FOUNDATIONS

This document establishes the architectural principles of Apeiron POS:
1. **Target Database Engine:** MySQL 8.0+ / MariaDB 10.6+ exclusively. All triggers, generated columns, and foreign keys rely on standard MySQL/MariaDB syntax.
2. **Multi-Tenancy Model:** Shared database, shared schema, multi-tenant with hard relational boundaries (Composite Foreign Keys, IMMUTABLE triggers, Fail-closed Global Scopes).
3. **Branch Ownership:** Branch-specific transactional data isolated via `store_id NOT NULL` and `FOREIGN KEY (tenant_id, store_id) REFERENCES stores (tenant_id, id)`.
4. **Zero-Trust Asynchronous Processing:** Background jobs, queue workers, and webhooks must initialize tenant context before model operations and release it cleanly in `finally` blocks.
