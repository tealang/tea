<?php
namespace tea\tests\syntax;

require_once dirname(__DIR__, 1) . '/__public.php';

function co_test() {
	for ($_ = 1; $_ <= 100; $_ += 1) {
		\Swoole\Coroutine::create(function () {
			for ($_ = 1; $_ <= 100; $_ += 1) {
				usleep(1000);
			}
		});
	}

	echo 'the Coroutine executing...', LF;
}

// ---------
co_test();
// ---------

\Swoole\Event::wait();

// program end
