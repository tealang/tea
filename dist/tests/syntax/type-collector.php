<?php
namespace tea\tests\syntax;

#internal
class CollectorDemo implements \IView
{
	public $subnode;

	public function text($value): CollectorDemo
	{
		return $this;
	}
}

#internal
class CollectorDemoFactory
{
	public function new_collector_demo(): CollectorDemo
	{
		return new CollectorDemo();
	}
}

// program end
