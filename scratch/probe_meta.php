<?php
require __DIR__.'/../vendor/autoload.php';
$r = new ReflectionMethod(\Tests\TestCase::class, 'setUp');
echo $r->getFileName(), ':', $r->getStartLine(), PHP_EOL;
