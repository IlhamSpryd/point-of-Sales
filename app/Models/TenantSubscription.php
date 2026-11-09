<?php

declare(strict_types=1);

namespace App\Models;

/**
 * Backward compatibility wrapper for modular architecture.
 *
 * @deprecated Use canonical modular class instead. Maintained for backward compatibility.
 *
 * @see \App\Modules\Settings\Models\TenantSubscription
 */
class TenantSubscription extends \App\Modules\Settings\Models\TenantSubscription {}
