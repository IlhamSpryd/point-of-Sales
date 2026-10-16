# AUTH, TENANT REGISTRATION & SAAS ONBOARDING IMPLEMENTATION PLAN
**Document Reference:** APEIRON-PLAN-AUTH-2026-001  
**Target System:** Apeiron POS Multi-Tenant SaaS Platform (`point-of-Sales` Core & `marketingPageApeironSystem` Acquisition BFF)  
**Specification Source:** `registerAuth.md` (APEIRON-ARCH-AUDIT-AUTH-2026-001) & `ApeironSystems-FinalMaster-PRD.docx`  
**Execution Context:** Production Codebase Implementation & Verification  
**Initial Git Baseline:** Branch `main`, Commit `a19b7a667847efba91f6ac708553624b9e7d875e`, Clean working tree  

---

## 1. Executive Summary & Purpose

This plan establishes the engineering execution path to implement the approved Auth, Tenant Registration, Provisioning, and Guided SaaS Onboarding architecture documented in `registerAuth.md`.

The implementation addresses the critical gap between raw S2S tenant provisioning (Domain 1) and operational register readiness by:
1. Closing the legacy un-scoped `/register` route vulnerability on the POS monolith.
2. Hardening authentication against email enumeration and credential brute force (enforcing server-side 15-minute lockout on 5 consecutive failures for both web login and cashier PINs with `activity_logs` audit records).
3. Upgrading `OwnerOnboardingController` to be email-scanner-safe on GET, enforcing atomic single-use consumption with `SELECT ... FOR UPDATE` on POST, immediately authenticating the owner, regenerating the session, and seamlessly transitioning them into the onboarding flow.
4. Implementing the **2-Step Authenticated Onboarding Wizard** (`/onboarding/business-profile` and `/onboarding/initial-branch`) with save-and-resume capability, persisting critical operational defaults (branch timezone, business-date cutoff, branch code, default sales channel, and currency) before operational POS routes are accessible.
5. Wiring the `EnsureOnboardingCompleted` middleware gate across operational routes (`/kasir`, `/shifts`, `/transaction`, `/dashboard`) to prevent premature transactional operations.
6. Introducing unauthenticated, rate-limited (throttle:3,15), enumeration-safe self-service onboarding token resend (`POST /onboarding/resend-token`).
7. Maintaining strict backward compatibility and isolation for existing multi-tenant test suites and operational invariants.

---

## 2. Complete Requirement Inventory & Traceability Baseline

| Req ID | Specification Statement | Source Section | Priority / Severity | Acceptance Criteria | Existing State | Target Component / Files |
| :--- | :--- | :--- | :--- | :--- | :--- | :--- |
| **REQ-ARCH-01** | Hierarchy: Platform → Tenant → Branch (Store) → User-Branch-Role Pivot (`user_stores`) → Shift. No intermediate Org. | `registerAuth.md` §3 | P0 / Invariant | All operational data bound to `(tenant_id, store_id)`. | IMPLEMENTED | `Tenant.php`, `Store.php`, `user_stores`, `Shift.php` |
| **REQ-ARCH-02** | Tenant owns catalog, recipes, ingredients, customers, roles, permissions, settings, and subscription. | `registerAuth.md` §3 | P0 / Invariant | Enforced via `ScopedToTenant` & composite FKs. | IMPLEMENTED | `ScopedToTenant.php`, migrations `c1-c3` |
| **REQ-ARCH-03** | Branches (Store) have timezone, business-date cutoff, branch code, default channel, address, phone. | `registerAuth.md` §3, §13.2 | P1 / High | `stores` table stores `code`, `timezone`, `business_date_cutoff`, `default_channel`. Unique `(tenant_id, code)`. | PARTIAL | Additive migration, `Store.php`, Wizard Step 2 |
| **REQ-AUTHZ-01** | Operational authorization requires explicit user-branch-role assignment (`user_stores`). | `registerAuth.md` §3 | P0 / Invariant | Verified in `StoreResolver` & `user_stores`. | IMPLEMENTED | `StoreResolver.php`, `user_stores` |
| **REQ-AUTHZ-02** | Sensitive actions authorized server-side by permission code. | `registerAuth.md` §3 | P0 / Invariant | Enforced via RBAC policies and `RoleMiddleware`. | IMPLEMENTED | `app/Policies/*`, `RoleMiddleware.php` |
| **REQ-AUTH-01** | Cashier PIN auth: 6-digit numeric PIN, hashed, temporary lockout 15 mins after 5 consecutive failures. | `registerAuth.md` §3, PRD OD-09 | P0 / Critical | Server-side tracking on `users.failed_login_attempts` & `users.locked_until`, audit `SECURITY_LOGIN_LOCKOUT`. | PARTIAL | Additive migration on `users`, `LoginRequest.php`, PIN auth |
| **REQ-AUTH-02** | Cashier PIN is not valid for Owner web auth; Owner web auth uses Email + Password (min 10 chars). | `registerAuth.md` §3, §9.1 | P0 / Critical | Web login requires `password` with `Password::min(10)`. | IMPLEMENTED | `OwnerOnboardingController.php`, `LoginRequest.php` |
| **REQ-AUTH-03** | Lock down legacy un-scoped `/register` route in POS monolith. | `registerAuth.md` §3, §18 P0-01 | P0 / Critical | GET `/register` redirects safely (to login or marketing); POST `/register` is disabled/404. | PARTIAL | `routes/web.php`, `routes/auth.php` |
| **REQ-PROV-01** | End-to-end Tenant Provisioning & Multi-Step Onboarding Wizard (OD-29). | `registerAuth.md` §3, §18 P1-01 | P1 / High | 2-Step post-verification wizard configures business profile & initial branch before POS register unlocks. | NOT_STARTED | `TenantOnboardingService.php`, Livewire/Controllers, views |
| **REQ-AUTH-04** | Session lifetimes, revocation, and lockout policy (OD-09). | `registerAuth.md` §3, §9.2 | P1 / High | Session invalidated on password reset, user disable, or role change. Web login locked 15m after 5 fails. | PARTIAL | `UserService.php`, `LoginRequest.php`, `NewPasswordController.php` |
| **REQ-COMM-01** | Subscription lifecycle separation from operational access & onboarding. | `registerAuth.md` §3, §8.1 | P1 / High | `tenants.subscription_status` ('trialing') orthogonal to `onboarding_status` and `access_status`. | NOT_STARTED | Additive migration, `Tenant.php` |
| **REQ-DEF-01** | Eliminate un-scoped user creation via legacy registration route. | `registerAuth.md` §4.2 | P0 / Critical | No public un-scoped user registration allowed on monolith. | NOT_STARTED | `routes/web.php`, `routes/auth.php` |
| **REQ-DEF-02** | Prevent unverified database tenant pollution from operating POS. | `registerAuth.md` §4.2 | P0 / High | Tenant starts `onboarding_status = 'pending_verification'`, cannot access register until completed. | NOT_STARTED | `Tenant.php`, `EnsureOnboardingCompleted.php` |
| **REQ-DEF-03** | Post-verification Business & Branch Onboarding Gate. | `registerAuth.md` §4.2 | P1 / High | Mandatory wizard enforces branch timezone, cutoff, default channel before POS register unlocks. | NOT_STARTED | `EnsureOnboardingCompleted.php`, Onboarding Wizard |
| **REQ-DEF-04** | Self-Service Resend & Token Expiry Recovery. | `registerAuth.md` §4.2, §18 P1-03 | P1 / Medium | POST `/onboarding/resend-token` rotates expired token, queues outbox, rate-limited (throttle:3,15). | NOT_STARTED | `OwnerOnboardingController.php`, `routes/web.php` |
| **REQ-DEF-05** | Differentiated login rejection messages & states. | `registerAuth.md` §4.2 | P1 / Medium | Distinct handling for inactive user, unverified email, suspended tenant, and incomplete onboarding. | PARTIAL | `LoginRequest.php`, `HomeController.php`, `SetTenantContext.php` |
| **REQ-STATE-01** | 5 Orthogonal State Dimensions maintained across entities. | `registerAuth.md` §8.1 | P0 / Invariant | Provisioning, User Account, Tenant Onboarding, Access Status, Subscription Status never conflated. | PARTIAL | Additive migrations on `tenants`, `stores`, `users` |
| **REQ-SEC-01** | Token scanner protection: GET `/onboarding/set-password/{token}` must not consume token. | `registerAuth.md` §11 TM-04 | P0 / High | Token only consumed on POST with valid password and CSRF. | IMPLEMENTED | `OwnerOnboardingController.php` |
| **REQ-SEC-02** | Atomic token consumption on POST with `SELECT ... FOR UPDATE` & sibling invalidation. | `registerAuth.md` §11 TM-05 | P0 / Critical | Prevents double-submit races and replays. | PARTIAL | `OwnerOnboardingController.php`, `OwnerOnboardingToken.php` |
| **REQ-SEC-03** | Mass assignment & privilege escalation rejection. | `registerAuth.md` §11 TM-06 | P0 / Critical | `role_id`, `is_admin`, `tenant_id` never accepted from client form input. | IMPLEMENTED | `StoreProvisioningRequest.php`, `User.php` |
| **REQ-SEC-04** | Cross-tenant branch IDOR protection during onboarding and store switching. | `registerAuth.md` §11 TM-07 | P0 / Critical | Store resolved strictly via `TenantContext` & `user_stores`. | IMPLEMENTED | `SetTenantContext.php`, `StoreResolver.php` |
| **REQ-SEC-05** | Idempotent initial branch update (prevents duplicate stores on double submit). | `registerAuth.md` §11 TM-08 | P1 / Medium | Step 2 updates existing initial store via `lockForUpdate()`, never creates secondary duplicate store. | NOT_STARTED | `TenantOnboardingService.php` |
| **REQ-SEC-06** | Operational route lockdown via `EnsureOnboardingCompleted` middleware. | `registerAuth.md` §11 TM-09 | P1 / High | Redirects incomplete tenants to active wizard step. | NOT_STARTED | `EnsureOnboardingCompleted.php`, `bootstrap/app.php` |
| **REQ-SEC-07** | Password complexity: min 10 chars, letters, mixedCase, numbers, uncompromised. | `registerAuth.md` §11 TM-12 | P1 / Medium | Enforced in `OwnerOnboardingController`, `NewPasswordController`, `PasswordController`. | PARTIAL | `OwnerOnboardingController.php` |
| **REQ-SEC-08** | Session regeneration upon onboarding activation & login. | `registerAuth.md` §11 TM-13 | P0 / Critical | `$request->session()->regenerate()` called immediately. | PARTIAL | `OwnerOnboardingController.php` |
| **REQ-SEC-09** | Open redirect prevention. | `registerAuth.md` §11 TM-14 | P1 / Medium | Redirects strictly validated to internal named routes. | IMPLEMENTED | `AuthenticatedSessionController.php` |
| **REQ-SEC-10** | Rate limiting on token resend and password reset (`throttle:3,15`). | `registerAuth.md` §11 TM-16 | P1 / Medium | Throttled by IP and email hash. | NOT_STARTED | `routes/web.php` |
| **REQ-SEC-11** | Password and token redaction in logs and exception dumps. | `registerAuth.md` §11 TM-17 | P0 / High | Passwords, PINs, raw tokens excluded from error/log contexts. | IMPLEMENTED | Outbox encryption, `User::$hidden` |

---

## 3. Current Implementation Findings & Gap Analysis

### 3.1 What Is Already Working & Hardened
- **HMAC Signed Provisioning:** `POST /api/v1/provisioning/registrations` validates HMAC-SHA256 signatures, timestamp window, and nonces (`VerifyProvisioningSignature`).
- **Atomic Tenant & Owner Provisioning:** `TenantProvisioningService::provisionBaseline()` seeds 8 standard roles, default permissions, initial store, and system users atomically inside a database transaction.
- **Outbox Queue & Lease-Locking:** `EmailOutbox` encrypts payloads at rest, enforces worker lease locks (`locked_until`, `worker_id`), and dispatches asynchronously via `ProcessProvisioningOutboxJob`.
- **Token Hashing:** `OwnerOnboardingToken` stores SHA-256 hashes (`token_hash`), never raw tokens.
- **Composite FK Isolation:** Tenant and branch composite foreign keys enforce strict multi-tenant boundaries at the database engine level.

### 3.2 Gaps Identified
1. **Missing Onboarding Wizard & State Machine Columns:**
   - `tenants` table lacks `onboarding_status` (default `'pending_verification'`), `onboarding_completed_at`, `access_status` (default `'provisioning'`), and `subscription_status` (default `'trialing'`).
   - `stores` table lacks `code` (default `'MAIN-01'`), `timezone` (default `'Asia/Jakarta'`), `business_date_cutoff` (default `'00:00'`), and `default_channel` (default `'dine_in'`), with compound unique index `(tenant_id, code)`.
   - `users` table lacks `failed_login_attempts` (default 0) and `locked_until` (timestamp nullable) for persistent server-side 15-minute lockout.
2. **Missing Post-Verification Onboarding Gate (`EnsureOnboardingCompleted`):**
   - After setting password, `OwnerOnboardingController` currently redirects to `/login` rather than auto-authenticating the owner and taking them directly to the Onboarding Wizard.
   - An Owner can log in and directly access `/dashboard`, `/transaction`, `/kasir`, or `/shifts` before branch timezone, business date cutoff, and order channels are configured.
3. **Missing Wizard Controllers & UI:**
   - Step 1: Business Profile (`/onboarding/business-profile`) does not exist.
   - Step 2: Initial Branch Setup (`/onboarding/initial-branch`) does not exist.
4. **Missing Self-Service Token Resend (`POST /onboarding/resend-token`):**
   - If an onboarding token expires (after 60 mins), the user is dead-ended because they cannot log in to request a resend.
5. **Legacy `/register` Route Surface:**
   - `routes/auth.php` commented out `/register`, but `resources/views/auth/register.blade.php` remained and explicit redirect / 405 guards on `GET /register` and `POST /register` were absent in `routes/web.php`.
6. **Lockout Policy Hardening:**
   - Rate limiting in `LoginRequest` defaults to standard 60-second decay rather than the mandated 15-minute (900s) server-side lockout with `activity_logs` audit.

---

## 4. Planned File Modifications & Additions

### 4.1 Database Migrations (Additive & Backward-Compatible)
- `database/migrations/2026_12_05_000001_add_auth_and_onboarding_lifecycle_columns.php`:
  - `tenants`: Add `onboarding_status` (VARCHAR 40, default `'pending_verification'`), `onboarding_completed_at` (TIMESTAMP nullable), `access_status` (VARCHAR 30, default `'provisioning'`), `subscription_status` (VARCHAR 30, default `'trialing'`). Add index on `(id, onboarding_status)`.
  - `stores`: Add `code` (VARCHAR 20, default `'MAIN-01'`), `timezone` (VARCHAR 64, default `'Asia/Jakarta'`), `business_date_cutoff` (CHAR 5, default `'00:00'`), `default_channel` (VARCHAR 30, default `'dine_in'`), `phone` (VARCHAR 30 nullable). Add unique index `(tenant_id, code)` if not already present.
  - `users`: Add `failed_login_attempts` (SMALLINT UNSIGNED, default 0), `locked_until` (TIMESTAMP nullable).
  - One-time idempotent backfill: Set `onboarding_status = 'completed'`, `access_status = 'active'`, `subscription_status = 'active'` for existing seeded tenants with verified owners.

### 4.2 Application Code & Architecture
1. **Models:**
   - `app/Models/Tenant.php`: Add fillables (`onboarding_status`, `onboarding_completed_at`, `access_status`, `subscription_status`), casts, helper methods (`isOnboardingCompleted()`, `isOperational()`, `isSuspended()`).
   - `app/Models/Store.php`: Add fillables (`code`, `timezone`, `business_date_cutoff`, `default_channel`, `phone`).
   - `app/Models/User.php`: Add fillables (`failed_login_attempts`, `locked_until`), casts, methods (`isLockedOut()`, `recordFailedLogin()`, `resetLoginLockout()`).
2. **Services:**
   - `app/Services/TenantOnboardingService.php` (New):
     - `saveBusinessProfile(Tenant $tenant, array $data): Tenant` (Step 1).
     - `saveInitialBranch(Tenant $tenant, array $data): Store` (Step 2, idempotent update of initial store, marks tenant onboarding completed and access active).
     - `getInitialStore(Tenant $tenant): Store`.
     - `resendOnboardingToken(string $email): bool` (rotates token, queues outbox, enumeration-safe).
   - `app/Services/UserService.php`: Add session invalidation on user update/disable/password change (`DB::table('sessions')->where('user_id', $user->id)->delete()`).
3. **Middleware:**
   - `app/Http/Middleware/EnsureOnboardingCompleted.php` (New):
     - Checks if authenticated user is Owner and tenant `onboarding_status !== 'completed'`.
     - If incomplete, redirects to `/onboarding/business-profile` or `/onboarding/initial-branch`.
     - Allows onboarding wizard routes, logout, verification routes.
   - `bootstrap/app.php`: Register alias `'ensure.onboarding.completed'`.
4. **Controllers & Requests:**
   - `app/Http/Controllers/OwnerOnboardingController.php`:
     - Upgraded `show()`: Scanner-safe, sets `Referrer-Policy: no-referrer`.
     - Upgraded `store()`: Consumes token with `SELECT ... FOR UPDATE`, sets password, sets `email_verified_at`, sets `tenants.onboarding_status = 'onboarding_business_profile'`, auto-authenticates owner, regenerates session, redirects to `/onboarding/business-profile`.
     - Add `resend(Request $request)`: Rate-limited (`throttle:3,15`), enumeration-safe self-service resend.
   - `app/Http/Controllers/OnboardingWizardController.php` (New):
     - `businessProfile()`: Renders Step 1 view.
     - `storeBusinessProfile(StoreBusinessProfileRequest $request)`: Validates and saves Step 1, redirects to Step 2.
     - `initialBranch()`: Renders Step 2 view.
     - `storeInitialBranch(StoreInitialBranchRequest $request)`: Validates and saves Step 2, marks completed, redirects to `/dashboard` with celebration flash.
   - `app/Http/Requests/StoreBusinessProfileRequest.php` (New).
   - `app/Http/Requests/StoreInitialBranchRequest.php` (New).
   - `app/Http/Requests/Auth/LoginRequest.php`:
     - Checks server-side persistent `users.locked_until`.
     - Increments `users.failed_login_attempts` on failure; triggers 15-minute lockout and logs `activity_logs` event `SECURITY_LOGIN_LOCKOUT` on 5th failure.
     - Resets attempts on success.
     - Enforces distinct exceptions for unverified owner, inactive user, suspended tenant.
   - `app/Http/Controllers/HomeController.php`:
     - Checks `tenants.onboarding_status`: if Owner and incomplete, redirects to wizard.
5. **Routes:**
   - `routes/web.php`:
     - Add legacy `/register` lockdown: `GET /register` redirects to `/login` with flash info; `POST /register` returns 405/abort.
     - Add public `POST /onboarding/resend-token` with `throttle:3,15`.
     - Add authenticated wizard routes under `middleware(['auth', 'tenant.context', 'enforce.email.verification'])`:
       - `GET /onboarding/business-profile`, `POST /onboarding/business-profile`
       - `GET /onboarding/initial-branch`, `POST /onboarding/initial-branch`
     - Apply `ensure.onboarding.completed` to operational route groups (`/dashboard`, `/reports`, `/transaction`, `/kds`, `/shifts`, `/orders`, `/inventory`, `/settings`).
6. **Views:**
   - `resources/views/auth/onboarding-set-password.blade.php`: Upgraded with password strength meter, clean error handling, resend link trigger.
   - `resources/views/onboarding/business-profile.blade.php` (New): Step 1 UI (Indonesia-first, responsive, accessible, stepper progress).
   - `resources/views/onboarding/initial-branch.blade.php` (New): Step 2 UI (Indonesia-first, responsive, accessible, preset cutoff buttons, save & finish).

---

## 5. Security & Threat Mitigation Plan

| Threat ID | Threat Description | Planned Mitigation | Test Assertion |
| :--- | :--- | :--- | :--- |
| **TM-01** | Un-scoped registration via `/register` | Route locked down; GET redirects, POST 405/disabled | `test_legacy_register_routes_are_locked_down` |
| **TM-02** | Customer email enumeration | Uniform responses; async notifications via outbox | `test_enumeration_safe_handling` |
| **TM-04** | Email link scanner burns token on GET | GET `/onboarding/set-password/{token}` is strictly read-only | `test_get_onboarding_does_not_consume_token` |
| **TM-05** | Token replay & race conditions | `SELECT ... FOR UPDATE` inside `DB::transaction()`; single-use; sibling tokens invalidated | `test_onboarding_token_cannot_be_replayed` |
| **TM-06** | Mass assignment / privilege escalation | Request allowlists; role assigned exclusively by server | `test_mass_assignment_privilege_rejection` |
| **TM-07** | IDOR cross-tenant branch manipulation | Store updated strictly scoped to authenticated `TenantContext::id()` | `test_cross_tenant_branch_idor_prevented` |
| **TM-08** | Duplicate branch on wizard double-submit | Idempotent update on existing initial store via `lockForUpdate()` | `test_wizard_step_two_is_idempotent` |
| **TM-09** | Direct access to POS before onboarding | `EnsureOnboardingCompleted` redirects incomplete tenants | `test_incomplete_tenant_redirected_to_wizard` |
| **TM-11** | Credential stuffing & PIN brute force | 5-failure lockout (15 minutes) on server DB columns + audit | `test_login_lockout_after_five_failures` |
| **TM-12** | Weak/compromised passwords | `Rules\Password::min(10)->letters()->mixedCase()->numbers()->uncompromised()` | `test_weak_password_rejected` |
| **TM-13** | Session fixation across activation | `$request->session()->regenerate()` called immediately | `test_session_regenerated_on_activation` |
| **TM-14** | Open redirect attacks | Redirect only to internal validated named routes | `test_open_redirect_rejected` |
| **TM-16** | Notification flooding via resend | `throttle:3,15` on resend endpoint | `test_resend_rate_limited` |

---

## 6. Execution Order & Verification Gates

1. **Step 1 — Schema & Migrations:**
   - Create additive migration `2026_12_05_000001_add_auth_and_onboarding_lifecycle_columns.php`.
   - Update Eloquent models (`Tenant`, `Store`, `User`).
   - Run migration against test DB (`yovel_pos_test`).
2. **Step 2 — Security & Legacy Route Lockdown (P0):**
   - Lock down `GET /register` and `POST /register` in `routes/web.php` and `routes/auth.php`.
   - Implement persistent 5-failure lockout in `LoginRequest` and `User` model with `activity_logs` logging.
3. **Step 3 — Owner Onboarding & Activation Hardening (P0):**
   - Upgrade `OwnerOnboardingController`: scanner-safe GET, single-use locked POST, auto-login, session regeneration, redirect to Step 1.
   - Implement rate-limited self-service token resend (`POST /onboarding/resend-token`).
4. **Step 4 — 2-Step Onboarding Wizard & Service (P1):**
   - Create `TenantOnboardingService`.
   - Create `OnboardingWizardController` with Step 1 (`businessProfile`) and Step 2 (`initialBranch`).
   - Create Blade views `resources/views/onboarding/business-profile.blade.php` and `initial-branch.blade.php`.
5. **Step 5 — Operational Gate Middleware (P1):**
   - Create `EnsureOnboardingCompleted` middleware and register in `bootstrap/app.php`.
   - Wire middleware to operational routes in `routes/web.php`.
   - Update `HomeController` to redirect incomplete owners to active wizard step.
6. **Step 6 — Automated Test Suite & Regression Verification (P0/P1):**
   - Create comprehensive feature test suite `tests/Feature/Auth/AuthAndOnboardingLifecycleTest.php`.
   - Run focused feature tests.
   - Run full regression suite across Domain 1, 2, 3, 4 tests.
7. **Step 7 — Documentation & Traceability Deliverables:**
   - Produce `auth-registration-implementation-report.md`.
   - Produce `auth-registration-requirement-traceability.md`.
   - Review git status and git diff.
