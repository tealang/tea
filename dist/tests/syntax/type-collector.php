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

	new CollectorDemo();

	(new CollectorDemo())->subnode->text('red')->subnode = new_collector_demo();

	$abc = new CollectorDemo();

	$factory = new CollectorDemoFactory();

	new_collector_demo();
	$factory->new_collector_demo();

	if (1) {
		(new CollectorDemo())->text('red')->subnode->text('hei~');
	}

	foreach ([1, 2, 3] as $item) {
		(new CollectorDemo())->subnode->text('hello');
	}

	return $__collects;
}

// program end
