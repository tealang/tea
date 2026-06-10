<?php
namespace tests\syntax;

trait Trait1 {
	protected $prop_in_trait1 = 'abc';
}

class PHPClassInMixed1 {
	use Trait1;

	// const1
	protected const CONST1 = 'user';

	// const2
	public const CONST2 = 123 * 2;

	public int|string|bool $union_val = false;

	/**
	 * comments
	 * @var Plain
	 */
	private string $caller;

	private array $list = [];

	public ?object $object;

	public \Exception $some;

	public function __construct(string $caller, array /*Array*/ $items, ?bool $some = null) {
		$this->caller = $caller;

		list($a, $b) = $items;
		[$c, $d] = $items;
		var_dump($a, $b, $c, $d);

		var_dump($this->prop_in_trait1);
	}

	function get_message(): string {
		if (1) {
			$caller = $this->caller;
		}
		else {
			$caller = 'Unknow caller';
		}

		return "Hei, $caller";
	}
}


