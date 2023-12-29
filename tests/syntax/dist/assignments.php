<?php
namespace tests\syntax;

require_once dirname(__DIR__, 1) . '/__public.php';

// ---------
$a = 1;
$a += 1;
$a -= 1;
$a *= 1;
$a /= 1;
$a **= 2;
$a >>= 1;
$a <<= 1;
$a &= 1;
$a |= 1;
$a ^= 1;

$a += 123;
// ---------

// program end
