<?php
namespace Tests;
use Illuminate\Foundation\Testing\TestCase as BaseTestCase;
use Tests\Support\RunsAfterSubclassSetUp;
use Tests\Support\WithDefaultTenant;
abstract class TestCase extends BaseTestCase
{
    use WithDefaultTenant;
    use RunsAfterSubclassSetUp;

    /**
     * Tenant/store default di-inject SETELAH setUp() subclass selesai.
     *
     * Alasan: banyak test membuat tenant-nya sendiri di dalam setUp() dan
     * memanggil TenantContext::clear() lalu setTenantId(tenant baru). Jika
     * context default di-inject lebih dulu (di dalam parent::setUp()), maka
     * tenant/store buatan test akan ditolak oleh AssignsTenant ("Cannot create
     * record for tenant X while in context of tenant Y").
     */
    protected function afterSubclassSetUp(): void
    {
        if (method_exists($this, 'setUpWithDefaultTenant')) {
            $this->setUpWithDefaultTenant();
        }
    }
}
