<?php

declare(strict_types=1);

namespace App\Modules\Restaurant\Http\Controllers;

use App\Modules\Customers\Controllers\TableController as CustomerTableController;

/**
 * Module compatibility wrapper for Restaurant domain.
 *
 * @see \App\Modules\Customers\Controllers\TableController
 */
class TableController extends CustomerTableController
{
}
