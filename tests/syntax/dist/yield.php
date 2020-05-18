<?php
namespace tea\tests\syntax;

require_once dirname(__DIR__, 1) . '/__public.php';

// ---------
foreach (xrange(0, 10) as $v) {
	echo $v, LF;
}
// ---------

\Swoole\Event::wait();

// program end
