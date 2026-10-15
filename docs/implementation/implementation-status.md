# Apeiron POS — Implementation Status & Architecture Summary

## Overview
Apeiron POS is a high-security multi-tenant point-of-sale platform built on Laravel 11/12, Livewire 3, MySQL 8.0+ / MariaDB 10.6+.
This document tracks the hardening and implementation status across Domain 1 (Tenant Isolation & Multi-Tenancy Architecture).

## Architecture Tiers
- **Tier 1 (System Root):** Platform-level metadata, system migrations, global provisioning logs.
- **Tier 2 (Tenant Scoped):** `tenants`, `stores`, `users`, `roles`, `customers`, `loyalty_*`, `activity_logs`, `export_tasks`, `settings`. Every entity strictly belongs to a single tenant (`tenant_id`).
- **Tier 3A (Store Scoped within Tenant):** `categories`, `products`, `modifier_groups`, `modifiers`, `ingredients`, `stock_movements`, `ingredient_stock_movements`, `ingredient_restock_forecasts`, `tables`, `shifts`, `orders`, `order_items`, `payments`, `cash_drawer_movements`, `channel_product_mappings`, `channel_order_logs`. Entities are scoped to `(tenant_id, store_id)` and protected by composite foreign keys.
- **Tier 3B (Hybrid Tenant/Store):** `discounts`. Global tenant discounts have `store_id IS NULL`, while store-specific discounts have `store_id = branch_id`. Scoped via `ScopedToStoreForDiscount`.

## Fail-Closed Tenancy Model
- `TenantScope` and `StoreScope` operate in fail-closed mode: queries executed without an active tenant or store context return zero rows (`whereRaw('1 = 0')`), preventing data leakage across background workers, webhooks, self-order endpoints, and console commands.
- Global scopes can be explicitly set via `TenantContext` and `StoreResolver` within strict `try ... finally` lifecycle blocks.
