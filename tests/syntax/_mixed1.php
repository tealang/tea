<?php
namespace tea\tests\syntax;

class PHPClassInMixed1 {
	protected const PREFIX = 'user';

	private string $caller;

	public function __construct(string $caller, array $items, bool $some = null) {
		$this->caller = $caller;
	}

	function get_message(): string {
		return "Hei, $this->caller";
	}
}


// class Exception extends \Exception {
// 	//
// }

