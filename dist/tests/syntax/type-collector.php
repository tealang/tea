<?php
namespace tea\tests\syntax;

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
	$__tmp0->subnode->text('red')->subnode = new_collector_demo();
	$__collects[] = $__tmp0;

	$abc = new CollectorDemo();

	$factory = new CollectorDemoFactory();

	new_collector_demo();
	$factory->new_collector_demo();

	if (1) {
		$__tmp1 = new CollectorDemo();
		$__tmp1->text('red')->subnode->text('hei~');
		$__collects[] = $__tmp1;
	}

	foreach ([1, 2, 3] as $item) {
		$__tmp2 = new CollectorDemo();
		$__tmp2->subnode->text('hello');
		$__collects[] = $__tmp2;
	}

	return $__collects;
}

// program end
