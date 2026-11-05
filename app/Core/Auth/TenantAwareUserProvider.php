<?php

declare(strict_types=1);

namespace App\Core\Auth;

use App\Models\Scopes\TenantScope;
use Illuminate\Auth\EloquentUserProvider;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;

class TenantAwareUserProvider extends EloquentUserProvider
{
    /**
     * Get a new query builder for the model instance with TenantScope bypassed
     * strictly for authentication and session credential resolution.
     *
     * @param  Model|null  $model
     * @return Builder<Model>
     */
    protected function newModelQuery($model = null)
    {
        return parent::newModelQuery($model)->withoutGlobalScope(TenantScope::class);
    }
}
