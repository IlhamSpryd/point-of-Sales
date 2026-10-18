<?php

namespace Tests\Support;

use Illuminate\Foundation\Testing\TestCase;

/**
 * RunsAfterSubclassSetUp.
 *
 * Menyediakan hook `afterSubclassSetUp()` yang dijamin berjalan SETELAH
 * `setUp()` subclass test selesai.
 *
 * Cara kerja: `RunsAfterSubclassSetUp::setUp()` didefinisikan `final`, lalu
 * memanggil `setUp()` milik parent deklarasi terdekat secara manual, terus
 * naik sampai `Illuminate\Foundation\Testing\TestCase` (yang menginisialisasi
 * aplikasi + traits + RefreshDatabase). Setelah rantai selesai, hook
 * `afterSubclassSetUp()` dipanggil.
 *
 * Catatan: kelas pemakai trait ini TIDAK boleh meng-override `setUp()`,
 * melainkan `afterSubclassSetUp()`. Jika meng-override `setUp()` langsung,
 * `final` akan mencegahnya (fail loud, bukan silently salah urutan).
 */
trait RunsAfterSubclassSetUp
{
    /**
     * Setup awal test case: jalankan rantai `setUp()` dari base framework ke
     * subclass terdekat, lalu panggil hook `afterSubclassSetUp()`.
     */
    final protected function setUp(): void
    {
        // Kumpulkan rantai deklarasi setUp() dari subclass terdekat hingga base
        // framework (Illuminate\Foundation\Testing\TestCase).
        // PENTING: mulai dari parent class, BUKAN dari kelas ini — kelas ini
        // mendeklarasikan setUp() sebagai final dan memanggilnya akan rekursi.
        $chain = [];
        $class = (new \ReflectionClass($this))->getParentClass();

        while ($class !== false) {
            $uses = class_uses_recursive($class->getName());
            if (in_array(RunsAfterSubclassSetUp::class, $uses, true)) {
                $class = $class->getParentClass();

                continue;
            }

            if ($class->hasMethod('setUp')) {
                $method = $class->getMethod('setUp');

                if ($method->getDeclaringClass()->getName() === $class->getName()) {
                    $chain[] = $method;
                }
            }

            if ($class->getName() === TestCase::class) {
                break;
            }

            $class = $class->getParentClass();
        }

        foreach (array_reverse($chain) as $method) {
            $method->invoke($this);
        }

        $this->afterSubclassSetUp();
    }

    /**
     * Hook yang dipanggil setelah `setUp()` subclass selesai.
     */
    protected function afterSubclassSetUp(): void
    {
        // no-op by default
    }
}
