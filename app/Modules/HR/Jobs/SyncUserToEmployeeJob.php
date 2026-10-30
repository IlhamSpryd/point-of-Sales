<?php

declare(strict_types=1);

namespace App\Modules\HR\Jobs;

use App\Models\User;
use App\Modules\HR\Enums\EmploymentStatusEnum;
use App\Modules\HR\Models\Employee;
use App\Services\Context\TenantContext;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;

class SyncUserToEmployeeJob implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public function __construct(
        public int $userId
    ) {}

    public function handle(): void
    {
        $user = User::withoutGlobalScopes()->find($this->userId);
        if (! $user || ! $user->tenant_id) {
            return;
        }

        app(TenantContext::class)->runAs((int) $user->tenant_id, null, function () use ($user) {
            $employee = Employee::where('user_id', $user->id)->first();
            if (! $employee) {
                return;
            }

            $employee->full_name = $user->name;
            $employee->email = $user->email;
            if ($user->phone_number) {
                $employee->phone_number = $user->phone_number;
            }

            if (! $user->is_active && $employee->employment_status === EmploymentStatusEnum::ACTIVE) {
                $employee->employment_status = EmploymentStatusEnum::SUSPENDED;
            }

            $employee->save();
        });
    }
}
