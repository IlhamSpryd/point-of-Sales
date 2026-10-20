<?php

declare(strict_types=1);

namespace App\Http\Controllers;

/**
 * Backward compatibility wrapper for modular architecture.
 *
 * @see \App\Modules\Users\Controllers\ProfileController
 */
class ProfileController extends \App\Modules\Users\Controllers\ProfileController {}
