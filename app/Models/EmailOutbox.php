<?php

declare(strict_types=1);

namespace App\Models;

/**
 * Backward compatibility wrapper for modular architecture.
 *
 * @see \App\Modules\Auth\Models\EmailOutbox
 */
class EmailOutbox extends \App\Modules\Auth\Models\EmailOutbox {}
