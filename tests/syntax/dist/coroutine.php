<?php
namespace tea\tests\syntax;

\Swoole\Runtime::enableCoroutine();

require_once dirname(__DIR__, 1) . '/__public.php';

function co_test() {
	$count = null;
	foreach (\xrange(1, 5) as $i) {
		\Swoole\Coroutine::create(function () use(&$count, &$i) {
			foreach (\xrange(1, 5) as $j) {
				usleep(10000);

				$count += 1;

				$str = "No.{$count}\t{$i}\t{$j}";
			}
		});
	}

	echo 'the Coroutine running...', LF;
}

// ---------
co_test();
// ---------

\Swoole\Event::wait();

// program end
