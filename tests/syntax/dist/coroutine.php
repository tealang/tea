<?php
namespace tea\tests\syntax;

\Swoole\Runtime::enableCoroutine();

require_once dirname(__DIR__, 1) . '/__public.php';

#internal
const LOOP_TIMES = 3;

function co_test() {
	$chan = new \Swoole\Coroutine\Channel();

	$count = 0;
	\Swoole\Coroutine::create(function () use(&$count, &$chan) {
		foreach (\xrange(1, LOOP_TIMES) as $i) {
			foreach (\xrange(1, LOOP_TIMES) as $j) {
				usleep(10);

				$message = [$count, $i, $j];

				$chan->push($message);

				$count += 1;
			}
		}
	});
	\Swoole\Coroutine::create(function () use(&$chan) {
		while (true) {
			usleep(200000);

			$received = $chan->pop();

			var_dump($received);
		}
	});
	echo 'the Coroutine running...', LF;
}

// ---------
co_test();
// ---------

\Swoole\Event::wait();

// program end
