<?php

declare(strict_types=1);

namespace Tests\Feature\Ui;

use Illuminate\Support\Facades\File;
use Tests\TestCase;

class GsapMotionInfrastructureTest extends TestCase
{
    protected bool $optOutFromDefaultTenant = true;

    public function test_erp_motion_file_exists_with_required_directives_and_lifecycle(): void
    {
        $filePath = resource_path('js/motion/erp-motion.js');
        $this->assertFileExists($filePath, 'resources/js/motion/erp-motion.js must exist.');

        $content = File::get($filePath);

        // Required reduced-motion guard
        $this->assertStringContainsString('prefers-reduced-motion: reduce', $content);
        $this->assertStringContainsString('prefersReducedMotion', $content);

        // Required Livewire SPA cleanup
        $this->assertStringContainsString('livewire:navigating', $content);
        $this->assertStringContainsString('cleanupMotionContext', $content);

        // Required Alpine directives
        $this->assertStringContainsString("directive('gsap-modal'", $content);
        $this->assertStringContainsString("directive('gsap-drawer'", $content);
        $this->assertStringContainsString("directive('gsap-collapse'", $content);
        $this->assertStringContainsString("directive('gsap-stagger'", $content);
        $this->assertStringContainsString("directive('gsap-counter'", $content);
    }

    public function test_app_js_integrates_and_exposes_erp_motion(): void
    {
        $filePath = resource_path('js/app.js');
        $this->assertFileExists($filePath);

        $content = File::get($filePath);

        $this->assertStringContainsString("from './motion/erp-motion'", $content);
        $this->assertStringContainsString('initErpMotion()', $content);
        $this->assertStringContainsString('window.erpMotion = erpMotion', $content);
        $this->assertStringContainsString('window.ApeironMotion = erpMotion', $content);
        $this->assertStringContainsString('window.gsap = gsap', $content);
    }

    public function test_guest_layout_has_no_external_gsap_cdn(): void
    {
        $filePath = resource_path('views/layouts/guest.blade.php');
        $this->assertFileExists($filePath);

        $content = File::get($filePath);

        $this->assertStringNotContainsString('cdnjs.cloudflare.com', $content);
        $this->assertStringNotContainsString('gsap.min.js', $content);
    }

    public function test_auth_login_view_integrates_with_erp_motion(): void
    {
        $filePath = resource_path('views/auth/login.blade.php');
        $this->assertFileExists($filePath);

        $content = File::get($filePath);

        $this->assertStringContainsString('animateAuthScreen', $content);
        $this->assertStringContainsString('shakeElement', $content);
    }

    public function test_all_views_have_zero_dead_animate_in_classes(): void
    {
        $viewsDir = resource_path('views');
        $allBladeFiles = File::allFiles($viewsDir);

        $violations = [];
        foreach ($allBladeFiles as $file) {
            if ($file->getExtension() === 'php' && str_contains($file->getFilename(), '.blade.php')) {
                $content = $file->getContents();
                if (preg_match('/\banimate-in\b/', $content)) {
                    $violations[] = $file->getRelativePathname();
                }
            }
        }

        $this->assertEmpty(
            $violations,
            'Dead animate-in class found in the following views: '.implode(', ', $violations)
        );
    }

    public function test_ui_modal_and_drawer_have_gsap_directives(): void
    {
        $modalContent = File::get(resource_path('views/components/ui/modal/index.blade.php'));
        $this->assertStringContainsString('x-gsap-modal', $modalContent);

        $drawerContent = File::get(resource_path('views/components/ui/drawer/index.blade.php'));
        $this->assertStringContainsString('x-gsap-drawer', $drawerContent);
    }
}
