<?php

declare(strict_types=1);

namespace App\Core\Auth;

use App\Models\Scopes\TenantScope;
use Illuminate\Auth\EloquentUserProvider;
use Illuminate\Contracts\Hashing\Hasher;

class TenantAwareUserProvider extends EloquentUserProvider
{
    /**
     * Get a new query builder for the model instance with TenantScope bypassed
     * strictly for authentication and session credential resolution.
     *
     * @param  \Illuminate\Database\Eloquent\Model|null  $model
     * @return \Illuminate\Database\Eloquent\Builder<\Illuminate\Database\Eloquent\Model>
     */
    protected function newModelQuery($model = null)
    {
        return parent::newModelQuery($model)->withoutGlobalScope(TenantScope::class);
    }
}
