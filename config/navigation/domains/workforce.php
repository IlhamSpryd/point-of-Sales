<?php

declare(strict_types=1);

/**
 * D7 — WORKFORCE
 *
 * Employees, scheduling, attendance, statutory Indonesian payroll (PPh 21 TER
 * per PMK 168/2023 & PP 58/2023, Lembur PP 35/2021, BPJS).
 *
 * Collapses the 6 duplicated L1 rows into one hub with 6 identical L2 tabs.
 *
 * @see docs/architecture/APEIRON_ENTERPRISE_NAVIGATION_ARCHITECTURE_BLUEPRINT.md §9.2, ADR-NAV-004
 */
return [
    'key' => 'workforce',
    'label' => 'Workforce',
    'order' => 70,
    'icon' => 'badge',
    'hubs' => [
        [
            'id' => 'nav.workforce.people',
            'label' => 'Workforce & Payroll',
            'route' => 'hr.employees.index',
            'active' => ['hr.*'],
            'roles' => ['Owner', 'Manager'],
            'abilities' => ['hr.employees.view'],
            'scope' => 'tenant',
            'icon' => 'badge',
            'order' => 130,
            'landing' => true,
            'aliases' => ['sdm', 'karyawan', 'payroll', 'gaji', 'hr', 'workforce'],
            'pages' => [
                [
                    'id' => 'nav.workforce.people.employees',
                    'label' => 'Employees',
                    'route' => 'hr.employees.index',
                    'active' => ['hr.employees.*'],
                    'roles' => ['Owner', 'Manager'],
                    'abilities' => ['hr.employees.view'],
                    'scope' => 'tenant',
                    'order' => 131,
                    'landing' => true,
                    'aliases' => ['karyawan', 'pegawai', 'staff', 'employee'],
                ],
                [
                    'id' => 'nav.workforce.people.schedules',
                    'label' => 'Schedules & Shifts',
                    'route' => 'hr.schedules.index',
                    'active' => ['hr.schedules.*'],
                    'roles' => ['Owner', 'Manager'],
                    'abilities' => ['hr.schedules.manage'],
                    'scope' => 'store',
                    'order' => 132,
                    'aliases' => ['jadwal', 'shift', 'scheduling', 'roster'],
                ],
                [
                    'id' => 'nav.workforce.people.attendance',
                    'label' => 'Attendance & Overtime',
                    'route' => 'hr.attendance.index',
                    'active' => ['hr.attendance.*'],
                    'roles' => ['Owner', 'Manager'],
                    'abilities' => ['hr.attendance.manage'],
                    'scope' => 'store',
                    'order' => 133,
                    'aliases' => ['absensi', 'kehadiran', 'lembur', 'overtime', 'presensi'],
                ],
                [
                    'id' => 'nav.workforce.people.leaves',
                    'label' => 'Leave Management',
                    'route' => 'hr.leaves.index',
                    'active' => ['hr.leaves.*'],
                    'roles' => ['Owner', 'Manager'],
                    'abilities' => ['hr.leaves.manage'],
                    'scope' => 'tenant',
                    'order' => 134,
                    'aliases' => ['cuti', 'izin', 'leave', 'saldo cuti'],
                ],
                [
                    'id' => 'nav.workforce.people.commissions',
                    'label' => 'Sales Commissions',
                    'route' => 'hr.commissions.index',
                    'active' => ['hr.commissions.*'],
                    'roles' => ['Owner', 'Manager'],
                    'abilities' => ['hr.commissions.manage'],
                    'scope' => 'store',
                    'order' => 135,
                    'aliases' => ['komisi', 'commission', 'bonus penjualan', 'insentif'],
                ],
                [
                    'id' => 'nav.workforce.people.payroll',
                    'label' => 'Payroll',
                    'route' => 'hr.payroll.index',
                    'active' => ['hr.payroll.*'],
                    'roles' => ['Owner', 'Manager'],
                    'abilities' => ['hr.payroll.manage'],
                    'scope' => 'tenant',
                    'order' => 136,
                    'aliases' => ['gaji', 'penggajian', 'payroll', 'pph 21', 'slip gaji'],
                ],
            ],
        ],
    ],
];
