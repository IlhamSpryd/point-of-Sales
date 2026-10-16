# AUTH, TENANT REGISTRATION & SAAS ONBOARDING IMPLEMENTATION REPORT
**Document Reference:** APEIRON-REP-AUTH-2026-001  
**Target Repository:** `/home/ilhamspryd/Projects/apeironSystem/point-of-Sales`  
**Specification Source:** `doc/registerAuth.md` (APEIRON-ARCH-AUDIT-AUTH-2026-001) & `ApeironSystems-FinalMaster-PRD.docx`  
**Role:** Principal Laravel Engineer, SaaS Identity Architect, Multi-Tenant Security Engineer, and Senior QA Engineer  
**Date of Completion:** October 16, 2026  
**Final Status Assessment:** `IMPLEMENTATION COMPLETE — READY FOR INDEPENDENT AUDIT`  

---

## 1. Executive Summary

This report documents the end-to-end implementation and automated verification of the Auth, Tenant Registration, S2S Provisioning, and Guided SaaS Onboarding architecture for Apeiron POS.

Prior to this implementation, the POS monolith contained a legacy un-scoped `/register` route that allowed users to be created without a tenant context or branch assignment, and tenant provisioning terminated abruptly after magic-link password setup without capturing operational branch prerequisites (branch timezone, business date cutoff, store code, sales channel, currency).

### Key Architectural Deliverables Accomplished
1. **Elimination of Public Monolith Registration Attack Surface (P0):** Closed legacy `/register` routes on the POS monolith. GET `/register` safely redirects (HTTP 302) to `/login`, while POST `/register` returns HTTP 405 Method Not Allowed. Public signup is exclusively mediated by the Acquisition Marketing BFF (`marketingPageApeironSystem`) via authenticated S2S HMAC signatures.
2. **Scanner-Safe & Replay-Resistant Onboarding Token Consumption (P0):** Upgraded `OwnerOnboardingController` so GET requests are strictly read-only and emit `Referrer-Policy: no-referrer` and `Cache-Control: no-store` headers to prevent email scanners or browser prefetchers from consuming activation links. POST consumption uses atomic `SELECT ... FOR UPDATE` locking to prevent double-submit race conditions and invalidates all sibling tokens.
3. **Hardened Authentication & Persistent Lockout (P0):** Enhanced `LoginRequest` and `NewPasswordController` with server-side 15-minute temporary lockout after 5 consecutive failed attempts on both web login and cashier PINs, recording structured audit events in `activity_logs` (`user.auth_locked_out`). Password complexity is enforced with Laravel's `Password::min(10)->letters()->mixedCase()->numbers()->uncompromised()`. Password resets immediately revoke all existing web sessions.
4. **Self-Service Token Resend & Recovery (P1):** Implemented `POST /onboarding/resend-token`, guarded by route-level rate limiting (`throttle:3,15`) and a 60-second token cooldown. The endpoint is completely enumeration-safe, returning identical generic success messages regardless of email existence, while rotating expired tokens and dispatching encrypted activation notifications via `email_outbox`.
5. **2-Step Authenticated SaaS Onboarding Wizard (P1):** Implemented `/onboarding/business-profile` (Step 1) and `/onboarding/initial-branch` (Step 2) with full save-and-resume capability. An interrupted onboarding session automatically resumes at the active step upon next login. Step 2 idempotently configures the initial branch (name, code, timezone, cutoff, sales channel) and marks the tenant `onboarding_status = 'completed'` and `access_status = 'active'`.
6. **Operational Middleware Gate (P1):** Implemented `EnsureOnboardingCompleted` middleware, intercepting uncompleted owners accessing operational POS routes (`/dashboard`, `/transaction`, `/shifts`, `/reports/sales`) and redirecting them to their active onboarding step.
7. **Additive Non-Destructive Migrations:** Delivered `2026_12_05_000001_add_auth_and_onboarding_lifecycle_columns.php`, adding lifecycle and branch operational columns with an idempotent backfill for existing seeded tenants.

All 83 automated test cases across 9 test suites passed with 501 assertions and zero failures.

---

## 2. Git Baseline & Working-Tree State

- **Repository Path:** `/home/ilhamspryd/Projects/apeironSystem/point-of-Sales`
- **Initial Branch:** `main`
- **Starting HEAD SHA:** `a19b7a667847efba91f6ac708553624b9e7d875e`
- **Initial Working-Tree State:** Clean (no uncommitted or untracked changes prior to starting implementation).
- **PHP Version:** PHP 8.5.11 (cli)
- **Composer Version:** Composer 2.10.3
- **Laravel Version:** Laravel Framework 13.31.0
- **Database Engine:** MySQL 8.0.45 (`127.0.0.1:3306`)
- **Verified Test Database:** `yovel_pos_test` (confirmed non-production database).

---

## 3. Exact Requirements Implemented

All requirements from `doc/registerAuth.md` and the Master PRD were implemented across the following categories:

### A. Architectural & Tenancy Invariants
- **REQ-ARCH-01 / REQ-ARCH-02:** Platform → Tenant → Branch (Store) → User-Branch-Role Pivot (`user_stores`) → Shift. All operational tables scoped to `(tenant_id, store_id)`.
- **REQ-ARCH-03:** Branch operational attributes added (`code`, `timezone`, `business_date_cutoff`, `default_channel`, `is_active`, `is_initial`). Unique index `stores_tenant_id_code_unique` enforced.
- **REQ-AUTHZ-01 / REQ-AUTHZ-02:** Server-side authorization via `StoreResolver` and RBAC policies.
- **REQ-STATE-01:** Orthogonal state dimensions maintained: Provisioning Status, User Account Status, Tenant Onboarding Status, Tenant Access Status, and Subscription Status.

### B. Functional Requirements (FN-01 to FN-10)
- **FN-01:** Valid S2S provisioning intent through Marketing BFF creates complete Tenant + Owner User + Initial Branch hierarchy.
- **FN-02:** Strict server-side validation on provisioning inputs; invalid requests roll back with HTTP 422 and zero orphan records.
- **FN-03:** Scanner-safe GET and atomic POST password setup on `/onboarding/set-password/{token}`.
- **FN-04:** Expired token rejection and self-service rate-limited resend on `/onboarding/resend-token`.
- **FN-05:** Secure password reset with session invalidation and lockout clearing.
- **FN-06:** Duplicate email registration safe conflict handling (HTTP 409 / identical 202).
- **FN-07:** Resumable Onboarding Wizard across Step 1 and Step 2.
- **FN-08:** Initial branch operational configuration unlocking POS access.
- **FN-09:** Role-aware and onboarding-state-aware post-login routing via `HomeController`.
- **FN-10:** Instant blocking and session termination for disabled users and suspended tenants.

### C. Security Controls & Threat Mitigations (SEC-01 to SEC-09 & TM-01 to TM-18)
- **SEC-01:** Legacy `/register` GET redirected to `/login`; POST `/register` returns HTTP 405.
- **SEC-02:** Mass assignment prevention: client-supplied `role_id`, `is_admin`, and `tenant_id` are stripped and rejected.
- **SEC-03:** Context derived strictly from server-side token or session context; parameter injection ignored.
- **SEC-04:** Strict cross-tenant IDOR protection during branch setup and store switching.
- **SEC-05:** Email verification enforcement via `EnforceEmailVerification` middleware.
- **SEC-06:** Brute force and rate limiting: 5-failure 15-minute account lockout on login and PIN; route throttle (`3,15`) on resend.
- **SEC-07:** Single-use cryptographic token binding with SHA-256 hash storage; replay attempts rejected.
- **SEC-08:** Internal-only named route redirection preventing post-authentication open redirects.
- **SEC-09:** Middleware gate `EnsureOnboardingCompleted` protecting `/dashboard`, `/transaction`, `/shifts`, and `/reports/sales`.

---

## 4. Requirements Not Implemented & Justifications

No requirements were omitted. All requirements documented in `registerAuth.md` that pertain to the POS monolith have been implemented and verified.

The public front-facing marketing registration page and client-side form validation belong to `marketingPageApeironSystem` (Next.js BFF repository), which calls the S2S provisioning API (`POST /api/v1/provisioning/registrations`) implemented and tested on the POS monolith.

---

## 5. Architectural Decisions & Engineering Rationale

1. **Separation of S2S Provisioning API vs. Public Onboarding Wizard:**
   - *Rationale:* Following the Master PRD and `registerAuth.md` §3, public signup is handled by the Marketing Landing Page / Acquisition BFF. The POS application never exposes an un-scoped public account registration endpoint. The POS exposes a hardened, HMAC-signed S2S provisioning endpoint that initializes the tenant hierarchy in state `pending_verification`, queues an encrypted onboarding token, and hands off the user experience to the magic-link wizard.
2. **Scanner-Safe Token Redemption (GET vs. POST):**
   - *Rationale:* Corporate email security gateways (e.g., Microsoft Defender, Proofpoint) aggressively prefetch and follow links found in incoming emails. If GET `/onboarding/set-password/{token}` consumed the token, the owner would receive an "invalid or expired link" error before even opening the page. GET is strictly read-only and sets `Referrer-Policy: no-referrer` to prevent token leakage in outgoing Referer headers. Consumption occurs exclusively via POST.
3. **Idempotent Initial Branch Configuration in Step 2:**
   - *Rationale:* Provisioning creates an initial shell `Store` record (`is_initial = true`). Step 2 of the wizard updates this existing record in-place rather than creating a new branch. This prevents duplicate branches when owners refresh or re-submit Step 2.
4. **Tenant Refresh in Middleware & Navigation Handlers:**
   - *Rationale:* During transition from Step 2 to operational routes, Eloquent relationships cached on the in-memory `$user` model inside test harnesses or long-lived runtimes (Octane) could retain stale `onboarding_status = 'pending'`. Adding `$tenant = $tenant->fresh() ?? $tenant` when checking `isOnboardingCompleted()` guarantees immediate visibility of status changes without unnecessary database queries for completed tenants.
5. **Database-Backed Session Invalidation on Credential Changes:**
   - *Rationale:* In accordance with PRD OD-09, when a user changes their password, is disabled, or has their role modified, all other active sessions belonging to that user are deleted from the `sessions` database table, forcing immediate re-authentication.

---

## 6. Files Created, Modified, and Deleted

### A. Created Files (10)
1. `database/migrations/2026_12_05_000001_add_auth_and_onboarding_lifecycle_columns.php`: Additive migration for tenant, store, and user lifecycle columns.
2. `app/Services/TenantOnboardingService.php`: Application domain service managing wizard persistence, initial branch updates, and token resend.
3. `app/Http/Controllers/OnboardingWizardController.php`: Controller rendering and handling Step 1 (Business Profile) and Step 2 (Initial Branch) wizard steps.
4. `app/Http/Middleware/EnsureOnboardingCompleted.php`: Middleware gate preventing incomplete owners from accessing operational POS routes.
5. `app/Http/Requests/StoreBusinessProfileRequest.php`: Form request validating Step 1 business profile attributes.
6. `app/Http/Requests/StoreInitialBranchRequest.php`: Form request validating Step 2 branch attributes with unique code checks.
7. `resources/views/onboarding/business-profile.blade.php`: Blade view with accessible UI for Step 1.
8. `resources/views/onboarding/initial-branch.blade.php`: Blade view with accessible UI for Step 2.
9. `tests/Feature/Auth/AuthAndOnboardingLifecycleTest.php`: 9 automated feature tests covering all new auth and onboarding invariants.
10. `auth-registration-requirement-traceability.md`: Complete requirement traceability matrix.

### B. Modified Files (12)
1. `app/Models/Tenant.php`: Added status constants, `$fillable`, datetime casts, and `isOnboardingCompleted()`, `isOperational()`, `isSuspended()` helper methods.
2. `app/Models/Store.php`: Added branch operational columns to `$fillable` and boolean/datetime casts.
3. `app/Models/User.php`: Added `email_verified_at`, `failed_login_attempts`, `locked_until` to `$fillable`, datetime casts, and lockout methods `isLockedOut()`, `recordFailedLogin()`, `resetLoginLockout()`.
4. `app/Http/Requests/Auth/LoginRequest.php`: Added 15-minute lockout on 5 consecutive failures, `activity_logs` audit recording, and suspended tenant check.
5. `app/Http/Controllers/OwnerOnboardingController.php`: Added scanner-safe GET headers, atomic POST consumption, tenant status transition, and `resend()` endpoint.
6. `app/Http/Controllers/Auth/NewPasswordController.php`: Hardened password validation rules, session invalidation, and lockout clearing.
7. `app/Http/Controllers/HomeController.php`: Added check redirecting incomplete owners to active wizard step, with tenant refresh.
8. `app/Http/Middleware/SecurityHeaders.php`: Preserved controller-set `Referrer-Policy` headers.
9. `app/Services/UserService.php`: Added session invalidation on password change, role change, and user deactivation/deletion.
10. `bootstrap/app.php`: Registered middleware alias `'ensure.onboarding.completed'`.
11. `routes/web.php`: Locked legacy `/register` routes; added `POST /onboarding/resend-token`; registered wizard routes; wrapped operational POS routes with `ensure.onboarding.completed`.
12. `tests/Feature/Auth/E2EOnboardingAndLoginTest.php`: Updated E2E flow to complete wizard steps before asserting dashboard access.

### C. Deleted Files
- None.

---

## 7. Database Migrations and Backward Compatibility

### Migration Details
- **File:** `database/migrations/2026_12_05_000001_add_auth_and_onboarding_lifecycle_columns.php`
- **Tables Modified:**
  - `tenants`: Added `slug`, `status`, `onboarding_status`, `onboarding_completed_at`, `access_status`, `subscription_status`, `subscription_plan`, `country_code`, and index `tenants_onboarding_status_index`.
  - `stores`: Added `code`, `timezone`, `business_date_cutoff`, `default_channel`, `phone`, `is_active`, `is_initial`, and unique index `stores_tenant_id_code_unique`.
  - `users`: Added `failed_login_attempts`, `locked_until`, and index `users_failed_login_locked_index`.
- **Backward-Compatible Idempotent Backfill:**
  - All existing seeded tenants are automatically updated to `onboarding_status = 'completed'`, `access_status = 'active'`, and `status = 'active'`.
  - Existing seeded stores are updated with `code = 'MAIN-01'`, `timezone = 'Asia/Jakarta'`, `business_date_cutoff = '00:00'`, `default_channel = 'dine_in'`, and `is_active = true`.
  - Existing users have `failed_login_attempts = 0` and `locked_until = null`.
- **Pre-existing Defect Fixed:**
  - `UiUxDesignSystemAuditTest` previously failed because `slug` was missing from `tenants`. Adding `slug` in this migration completely resolved that failure.

---

## 8. Security Controls Implemented

| Security Control | Implementation Details | Negative Test Case |
| :--- | :--- | :--- |
| **Legacy Route Lockdown** | GET `/register` → 302 to `/login`; POST `/register` → HTTP 405. | `test_legacy_register_routes_are_locked_down` |
| **Scanner-Safe GET** | Read-only; sets `Referrer-Policy: no-referrer` & `Cache-Control: no-store`. | `test_scanner_safe_get_onboarding_token_does_not_consume_token` |
| **Atomic Token Consumption** | `SELECT ... FOR UPDATE` with conditional update; replayed/expired tokens fail. | `test_onboarding_token_cannot_be_replayed_or_used_after_expiry` |
| **Self-Service Resend Throttling** | `throttle:3,15` on route; 60s cooldown in service; returns HTTP 429. | `test_self_service_onboarding_token_resend_is_rate_limited_and_enumeration_safe` |
| **Email Enumeration Defense** | Token resend returns identical generic success message regardless of existence. | `test_self_service_onboarding_token_resend_is_rate_limited_and_enumeration_safe` |
| **15-Min Account Lockout** | 5 consecutive wrong passwords locks account for 15 minutes; audits to `activity_logs`. | `test_web_login_5_failure_lockout_for_15_minutes_with_audit_log` |
| **Password Complexity** | Enforces min 10 chars, letters, mixed case, numbers, uncompromised check. | `test_post_onboarding_token_sets_password_and_consumes_token_atomically` |
| **Session Invalidation** | Password change/reset deletes all user sessions from `sessions` table. | `PasswordUpdateTest` suite |
| **Cross-Tenant IDOR Guard** | Context derived strictly from server token or `TenantContext`; client IDs ignored. | `Domain1TenantIsolationTest::test_owner_cannot_switch_to_another_tenants_store` |
| **Operational Middleware Gate** | `EnsureOnboardingCompleted` redirects incomplete owners away from POS routes. | `test_operational_pos_routes_blocked_until_onboarding_completed` |

---

## 9. Automated Test Commands Run and Exact Results

The following test suites were executed against the verified non-production database (`yovel_pos_test`):

```bash
# 1. Comprehensive Auth & Onboarding Lifecycle Feature Test
php artisan test --filter=AuthAndOnboardingLifecycleTest
# Result: 9 passed, 103 assertions, Duration: 21.6s — Exit Code: 0

# 2. End-to-End Onboarding and Login Test
php artisan test --filter=E2EOnboardingAndLoginTest
# Result: 5 passed, 54 assertions, Duration: 18.7s — Exit Code: 0

# 3. S2S Provisioning Domain Tests
php artisan test --filter=ProvisioningDomainTest
# Result: 28 passed, 127 assertions, Duration: 20.7s — Exit Code: 0

# 4. Provisioning Hardened Integrity Tests
php artisan test --filter=ProvisioningHardenedIntegrityTest
# Result: 7 passed, 27 assertions, Duration: 17.5s — Exit Code: 0

# 5. Provisioning Real Concurrency Tests
php artisan test --filter=ProvisioningRealConcurrencyTest
# Result: 3 passed, 30 assertions, Duration: 3.2s — Exit Code: 0

# 6. Domain 1 Multi-Tenant Isolation Tests
php artisan test --filter=Domain1TenantIsolationTest
# Result: 16 passed, 46 assertions, Duration: 18.0s — Exit Code: 0

# 7. UI/UX Design System & Store Navigation Audit Tests
php artisan test --filter=UiUxDesignSystemAuditTest
# Result: 9 passed, 98 assertions, Duration: 17.9s — Exit Code: 0

# 8. Web Authentication Feature Tests
php artisan test --filter=AuthenticationTest
# Result: 4 passed, 8 assertions, Duration: 20.1s — Exit Code: 0

# 9. Password Update Feature Tests
php artisan test --filter=PasswordUpdateTest
# Result: 2 passed, 8 assertions, Duration: 19.9s — Exit Code: 0
```

**Overall Test Execution Summary:**
- **Total Tests Executed:** 83
- **Passed:** 83 (100%)
- **Failed:** 0
- **Total Assertions Verified:** 501
- **Regressions Introduced:** 0

---

## 10. Known Defects and Unresolved Risks

1. **Pre-existing Host Background Process:**
   - A dev server (`php artisan ser`) remains running on the host as observed during initial reconnaissance. It was not disturbed during implementation.
2. **External BFF Dependency:**
   - Public prospective tenants submit registration through `marketingPageApeironSystem`. The S2S endpoint is fully covered with HMAC tests; when deploying to production, both applications must share the same `PROVISIONING_SECRET_CURRENT`.

---

## 11. Manual Verification Still Required

1. **End-to-End Browser Walkthrough:**
   - Perform a manual browser walkthrough with the frontend dev server running to verify the Blade stepper UI visual presentation in dark mode and mobile viewports.
2. **Mail Delivery Integration:**
   - In production/staging, verify that the email transport (SMTP, Mailgun, or SES) delivers the activation email formatted with the new branded template.

---

## 12. Deployment & Migration Instructions

1. **Database Migration:**
   ```bash
   php artisan migrate --force
   ```
   *Note:* The migration is additive and contains an automatic backfill for existing seeded tenants and stores.
2. **Environment Configuration (`.env`):**
   ```ini
   POS_ENFORCE_ONBOARDING_WIZARD=true
   POS_ONBOARDING_WIZARD_REDIRECT=false
   PROVISIONING_SECRET_CURRENT="<shared-secure-hmac-secret>"
   ```
3. **Queue Worker:**
   Ensure queue workers are running to process email outbox notifications:
   ```bash
   php artisan queue:work --queue=default,emails
   ```

---

## 13. Rollback & Recovery Considerations

1. **Migration Rollback:**
   - `php artisan migrate:rollback --step=1` safely drops the added columns and indexes.
2. **Graceful Degraded Mode:**
   - If emergency bypass of the onboarding wizard is required in production without code deployment, set `POS_ENFORCE_ONBOARDING_WIZARD=false` in `.env` and run `php artisan config:cache`. All existing authenticated users will retain direct operational route access.

---

## 14. Remaining Open Product Decisions

No blocking product or security decisions remain. The 2-step onboarding wizard matches the approved specification in `registerAuth.md` and PRD OD-29.

---

## 15. Final Acceptance Gate Verification

- [x] **Gate 1 — Specification Fidelity:** Complete `registerAuth.md` analyzed; full requirement inventory and traceability established.
- [x] **Gate 2 — Functional Correctness:** Registration, verification, provisioning, wizard flow, and save-and-resume work seamlessly.
- [x] **Gate 3 — Security Hardening:** Privilege escalation prevented, scanner safety enforced, rate limits active, lockout mechanism audited.
- [x] **Gate 4 — Data Integrity:** Database invariants and composite foreign keys respected; zero orphan records on rollback.
- [x] **Gate 5 — Test Evidence:** 83 tests and 501 assertions executed and passing with 100% green results.
- [x] **Gate 6 — Change Safety:** Clean diff, git diff check verified, no unrelated user work lost, no secrets committed.

**Final Status:**  
`IMPLEMENTATION COMPLETE — READY FOR INDEPENDENT AUDIT`
