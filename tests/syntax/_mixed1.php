<?php
namespace tea\tests\syntax;

class PHPClassInMixed1 {
	private string $caller;

	public function __construct(string $caller) {
		$this->caller = $caller;
	}

	function get_message(): string {
		return "Hei, $this->caller";
	}
}
