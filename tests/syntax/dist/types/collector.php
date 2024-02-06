<?php
namespace tests\syntax;

require_once dirname(__DIR__, 2) . '/__public.php';

#internal
class CollectorDemo implements \IView {
	public $subnode;

	public function text($value) {
		return $this;
	}
}

#internal
class CollectorDemoFactory {
	public function new_collector_demo() {
		return new CollectorDemo();
	}
}

function new_collector_demo() {
	return new CollectorDemo();
}

// program end
