<?php

declare(strict_types=1);

namespace App\Http\Controllers;

/**
 * Backward compatibility wrapper for modular architecture.
 *
 * @see \App\Modules\Users\Controllers\UserController
 */
class UserController extends \App\Modules\Users\Controllers\UserController {}
