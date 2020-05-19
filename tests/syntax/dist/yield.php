<?php
namespace tea\tests\syntax;

\Swoole\Runtime::enableCoroutine();

require_once dirname(__DIR__, 1) . '/__public.php';

// ---------
foreach (demo_range(0, 9) as $v) {
	echo $v, LF;
}
// ---------

\Swoole\Event::wait();

// program end
