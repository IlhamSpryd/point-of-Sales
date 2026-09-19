<?php

use App\Models\Category;
use App\Models\Product;
use App\Models\Role;
use App\Models\User;

foreach (User::all() as $u) {
    $u->update(['employee_id' => 'YVL-EMP-'.date('Y').'-'.str_pad($u->id, 4, '0', STR_PAD_LEFT)]);
}
foreach (Role::all() as $r) {
    $r->update(['role_code' => 'ROL-'.str_pad($r->id, 3, '0', STR_PAD_LEFT)]);
}
foreach (Category::all() as $c) {
    $c->update(['category_code' => 'CAT-'.str_pad($c->id, 3, '0', STR_PAD_LEFT)]);
}
foreach (Product::all() as $p) {
    $p->update(['product_code' => 'PRD-'.str_pad($p->id, 4, '0', STR_PAD_LEFT)]);
}
echo "Data lama berhasil di-patch dengan Business ID.\n";
