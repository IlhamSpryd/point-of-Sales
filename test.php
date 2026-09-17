<?php

use Midtrans\Config;
use Midtrans\Snap;

require 'vendor/autoload.php';
Config::$serverKey = 'SB-Mid-server-xH5B4c9t51qL3jC_47-O8x3p';
Config::$isProduction = false;
try {
    echo Snap::getSnapToken(['transaction_details' => ['order_id' => uniqid(), 'gross_amount' => 10000]]);
} catch (Exception $e) {
    echo 'Error occurred: '.$e->getMessage();
}
