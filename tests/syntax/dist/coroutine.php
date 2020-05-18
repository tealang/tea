<?php
namespace tea\tests\syntax;

\Swoole\Runtime::enableCoroutine();

require_once dirname(__DIR__, 1) . '/__public.php';

function co_test() {
	for ($i = 1; $i <= 100; $i += 1) {
		echo 'run usleep: ';
		\Swoole\Coroutine::create(function () use($i) {
			for ($k = 1; $k <= 100; $k += 1) {
				usleep(1000);
				echo '(' . $i . ',' . $k . ') ';
			}
		});
		echo LF;
	}

	echo 'the Coroutine executing...', LF;
}

// ---------
co_test();
// ---------

\Swoole\Event::wait();

// program end
