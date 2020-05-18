<?php
namespace tea\tests\syntax;

require_once dirname(__DIR__, 1) . '/__public.php';

// ---------
echo LF;

$str = ' a string';

echo 'abc', 'efg', '123', LF;

echo 'abc', $str;
// ---------

\Swoole\Event::wait();

// program end
