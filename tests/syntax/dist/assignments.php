<?php
namespace tea\tests\syntax;

\Swoole\Runtime::enableCoroutine();

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

\Swoole\Event::wait();

// program end
