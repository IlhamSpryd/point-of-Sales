<?php

declare(strict_types=1);

namespace App\Support\Dataset;

use RuntimeException;

/** Kegagalan generator dataset dengan konteks aman (tanpa secret). */
class DatasetGenerationException extends RuntimeException {}
