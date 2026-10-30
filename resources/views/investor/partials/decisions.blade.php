<div class="space-y-8" data-gsap="page-enter">
    <!-- Header -->
    <div>
        <h2 class="text-2xl font-bold text-apeiron-ink dark:text-zinc-100 tracking-tight">
            Two Open Product Decisions
        </h2>
        <p class="text-xs md:text-sm text-zinc-500 mt-1">
            Technical analysis and architectural recommendations for two open product decisions identified during the Apeiron POS system audit.
        </p>
    </div>

    <div class="grid grid-cols-1 lg:grid-cols-2 gap-8">
        <!-- Decision A Card -->
        <div class="card-surface p-6 md:p-8 rounded-xl border border-zinc-200 dark:border-zinc-800 space-y-6 flex flex-col justify-between">
            <div class="space-y-4">
                <div class="flex items-center justify-between">
                    <span class="inline-flex px-2.5 py-1 rounded-md text-xs font-semibold bg-amber-50 text-amber-800 border border-amber-200/60 dark:bg-amber-950/40 dark:text-amber-300">
                        DECISION A: DINE-IN TABLE NUMBER
                    </span>
                    <span class="text-xs font-mono text-zinc-400">PRD Ref: OD-37</span>
                </div>

                <h3 class="text-lg font-bold text-apeiron-ink dark:text-zinc-100">
                    Handling Table Numbers in the POS MVP Cashier Interface
                </h3>

                <div class="space-y-3 text-xs text-zinc-600 dark:text-zinc-300 leading-relaxed">
                    <p>
                        <strong>Background:</strong> The Master PRD establishes that full visual floor plan management, real-time table occupancy, persistent open tabs, and split/merge table operations are allocated to Phase <strong>F3 (OD-37)</strong>. However, fast-casual cafe and counter-service dining operations require table number identification for order delivery.
                    </p>
                    <p>
                        <strong>Current Safe Implementation:</strong>
                    </p>
                    <ul class="list-disc pl-5 space-y-1 text-zinc-500">
                        <li>Dine-In is supported operationally as a counter-service order type.</li>
                        <li>The table property remains <em>optional (nullable)</em> in Livewire component state and the Order model.</li>
                        <li>Enforces no mandatory table number validation during cashier checkout.</li>
                        <li>Introduces no blocking runtime dependencies on table occupancy or physical floor plan records.</li>
                    </ul>
                </div>
            </div>

            <div class="p-4 rounded-lg bg-zinc-50 dark:bg-zinc-800/40 border border-zinc-200 dark:border-zinc-700/80 space-y-2">
                <div class="flex items-center gap-2 text-xs font-bold text-apeiron-ink dark:text-zinc-200">
                    <span class="material-symbols-rounded text-emerald-600 text-base">check_circle</span>
                    Architect Recommendation & Execution Status:
                </div>
                <p class="text-xs text-zinc-500">
                    Implemented as a non-blocking optional field in POS. Visual floor plan prototypes for Phase F3 remain isolated at <code class="font-mono text-primary-600">/tables</code> without compromising MVP cashier stability. Awaiting Product Owner confirmation for final policy standardization.
                </p>
            </div>
        </div>

        <!-- Decision B Card -->
        <div class="card-surface p-6 md:p-8 rounded-xl border border-zinc-200 dark:border-zinc-800 space-y-6 flex flex-col justify-between">
            <div class="space-y-4">
                <div class="flex items-center justify-between">
                    <span class="inline-flex px-2.5 py-1 rounded-md text-xs font-semibold bg-blue-50 text-blue-800 border border-blue-200/60 dark:bg-blue-950/40 dark:text-blue-300">
                        DECISION B: STORE ASSORTMENTS
                    </span>
                    <span class="text-xs font-mono text-zinc-400">PRD Ref: OD-18</span>
                </div>

                <h3 class="text-lg font-bold text-apeiron-ink dark:text-zinc-100">
                    Navigation Placement for the Store Assortment Matrix
                </h3>

                <div class="space-y-3 text-xs text-zinc-600 dark:text-zinc-300 leading-relaxed">
                    <p>
                        <strong>Background:</strong> The Store Assortment feature (OD-18) controls product availability per branch (active/inactive per store). Two placement options were evaluated: embedding it within the product catalog sub-navigation tabs or retaining it as a dedicated primary navigation destination.
                    </p>
                    <p>
                        <strong>Current Safe Implementation:</strong>
                    </p>
                    <ul class="list-disc pl-5 space-y-1 text-zinc-500">
                        <li>Assortments is retained as a primary menu item under Pillar 3 (Catalog & Recipes) in operational ERP navigation.</li>
                        <li>Maintains dedicated route <code class="font-mono text-[11px] text-primary-600">assortments.index</code> supporting rapid branch assortment matrix selection.</li>
                        <li>Centrally registered in <code class="font-mono text-[11px] text-primary-600">config/navigation.php</code>, allowing flexible re-scoping into sub-tabs if desired in the future.</li>
                    </ul>
                </div>
            </div>

            <div class="p-4 rounded-lg bg-zinc-50 dark:bg-zinc-800/40 border border-zinc-200 dark:border-zinc-700/80 space-y-2">
                <div class="flex items-center gap-2 text-xs font-bold text-apeiron-ink dark:text-zinc-200">
                    <span class="material-symbols-rounded text-emerald-600 text-base">check_circle</span>
                    Architect Recommendation & Execution Status:
                </div>
                <p class="text-xs text-zinc-500">
                    Retained as a primary destination under Catalog & Recipes. RBAC permissions for Owner, Manager, and Inventory roles are 100% verified in the sidebar test suite.
                </p>
            </div>
        </div>
    </div>
</div>
