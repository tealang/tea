<?php
namespace tests\syntax;

class PHPClassInMixed1 {
	// const1
	protected const CONST1 = 'user';

	// const2
	public const CONST2 = 123 * 2;

	public int|string|bool $union_val = false;

	/**
	 * comments
	 * @var type
	 */
	private string $caller;

	private array $list = [];

	public ?object $object;

	public \Exception $some;

	public function __construct(string $caller, array /*Array*/ $items, bool $some = null) {
		$this->caller = $caller;
	}

	function get_message(): string {
		return "Hei, $this->caller";
	}
}


