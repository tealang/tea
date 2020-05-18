<?php
namespace tea\tests\syntax;

require_once dirname(__DIR__, 2) . '/__public.php';

#internal
class CollectorDemo implements \IView {
	public $subnode;

	public function text($value): CollectorDemo {
		return $this;
	}
}

#internal
class CollectorDemoFactory {
	public function new_collector_demo(): CollectorDemo {
		return new CollectorDemo();
	}
}

function new_collector_demo(): CollectorDemo {
	return new CollectorDemo();
}

function collector1(): array {
	$__collects = [];
	$__collects[] = '<div>hei~</div>';
	$__collects[] = new CollectorDemo();

	$__tmp0 = new CollectorDemo();
	$__tmp0->text('red')->subnode = new_collector_demo();
	$__collects[] = $__tmp0;

	$abc = new CollectorDemo();

	$factory = new CollectorDemoFactory();

	$factory->new_collector_demo();
	$__collects[] = new_collector_demo();

	if (1) {
		$__tmp1 = new CollectorDemo();
		$__tmp1->text('red')->text('hei~');
		$__collects[] = $__tmp1;
	}

	foreach ([1, 2, 3] as $item) {
		$__tmp2 = new CollectorDemo();
		$__tmp2->text('hello');
		$__collects[] = $__tmp2;
	}

	return $__collects;
}

// ---------
$result = collector1();
var_dump($result);
// ---------

\Swoole\Event::wait();

// program end
