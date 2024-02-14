<?php
namespace tests\syntax;

use tests\phpdemo\{ BaseInterface };

require_once dirname(__DIR__, 1) . '/__public.php';

#internal
interface IDemo extends BaseInterface {
	const CONST1 = 'const value';
	public function __construct(string $some);
	public function get_class_name(string $caller = 'caller1'): string;
}

trait IDemoTrait {
	public $prop;

	public function __construct(string $some) {
		$this->prop = $some;
	}

	public function get_class_name(string $caller = 'caller1'): string {
		return __CLASS__;
	}
}

#internal
class IterableObject implements \Iterator {
	public $pos;
	public $items = ['item1', 'item2', 'item3'];

	public function current(): string {
		return $this->items[$this->pos];
	}

	public function key(): int {
		return $this->pos;
	}

	public function next(): void {
		$this->pos += 1;
	}

	public function valid(): bool {
		return isset($this->items[$this->pos]);
	}

	public function rewind(): void {
		$this->pos = 0;
	}
}

#internal
class Test1 extends IterableObject implements IDemo {
	use IDemoTrait;

	public function __construct(string $some) {
		// no any
	}

	public static function static_method() {
		echo Test1::CONST1, LF;
	}

	public static function get_closure() {
		return function () {
			return Test1::CONST1;
		};
	}
}

#internal
class Test2 {
	public $t1;

	public function set_t1(BaseInterface $t1) {
		$this->t1 = $t1;
	}

	public function call_t1_fn(): string {
		return $this->t1->get_class_name('class.tea');
	}
}

#internal
interface ITest {
	public function f1(): string;
}

#internal
class Test3 implements ITest {
	public function f1(): string {
		return "hi";
	}
}

#internal
class Test4 {
	public static function fx(ITest $it) {
		echo $it->f1(), LF;
	}
}

#internal
class Test5 {
	const C_1 = '123';

	public $a_10 = [];
	public $a_11 = ["f_1" => 123, "f_2" => "string"];
	public $a_20;
	public $a_22 = 123;
	public $a_30;
	public $a_32 = "hi";
	public $a_40;
	public $a_41 = true;
	public $a_42 = false;
	public $a_50;
	public $a_51 = [];
	public $a_52 = [1, 2, "str"];
	public $a_53 = [1, 2, [4, 5, 6]];

	public static $sa_10 = [];
	public static $sa_11 = ["f_1" => 123, "f_2" => "string"];
	public static $sa_20;
	public static $sa_22 = 123;
	public static $sa_30;
	public static $sa_32 = "hi";
	public static $sa_41 = true;
	public static $sa_51 = [];
	public static $sa_53 = [1, 2, [4, 5, 6]];

	public static function sm3() {
		// no any
	}

	public static function sm_30($arg): string {
		return '';
	}

	public static function sm_31($arg_1, $arg_2): string {
		$str = "sth.";

		return $str;
	}

	public static function sm_33(array $arg = []): int {
		return 1;
	}

	public static function sm_34(array $arg = [1, 2, 3]): float {
		return 1.23;
	}

	public static function sm_35(array $arg_1, $arg_2): bool {
		return true;
	}

	public static function sm_360(array $arg_1, string $arg_2 = "str"): array {
		return [];
	}

	public static function sm_361(array $arg_1, string $arg_2 = "str"): array {
		return [1, 2, 3];
	}

	public static function sm_362(array $arg_1, string $arg_2 = "str"): array {
		return $arg_1;
	}

	public static function sm_370(array $arg_1, string $arg_2 = "str"): array {
		return [];
	}

	public static function sm_371(array $arg_1, string $arg_2 = "str"): array {
		return $arg_1;
	}

	public static function sm_372(array $arg_1, string $arg_2 = "str"): array {
		return ["a" => 123];
	}

	public function m3() {
		// no any
	}

	public function m_30($arg): string {
		return '';
	}

	public function m_31($arg_1, $arg_2): string {
		$str = "sth.";

		return $str;
	}

	public function m_33(array $arg = []): int {
		return 1;
	}

	public function m_34(array $arg = [1, 2, 3]): float {
		return 1.23;
	}

	public function m_35(array $arg_1, $arg_2): bool {
		return true;
	}

	public function m_360(array $arg_1, string $arg_2 = "str"): array {
		return [];
	}

	public function m_361(array $arg_1, string $arg_2 = "str"): array {
		return [1, 2, 3];
	}

	public function m_362(array $arg_1, string $arg_2 = "str"): array {
		return $arg_1;
	}

	public function m_363(array $arg_1, string $arg_2 = "str"): array {
		return $this->a_53;
	}

	public function m_370(array $arg_1, string $arg_2 = "str") {
		return [];
	}

	public function m_371(array $arg_1, string $arg_2 = "str"): array {
		return $arg_1;
	}

	public function m_372(array $arg_1, string $arg_2 = "str"): array {
		return ["a" => 123];
	}

	public function m_373(array $arg_1, string $arg_2 = "str"): array {
		return $this->a_10;
	}
}

// ---------
foreach (new IterableObject() as $k => $v) {
	echo 'iterable item: ' . $k . ' => ' . $v, LF;
}

echo "# start to run closure", LF;
var_dump(Test1::get_closure()());
echo "# finished run closure", LF;

$t1 = new Test1('some');
echo $t1->get_class_name('Unknow'), LF;

$t2 = new Test2();
$t2->set_t1($t1);
echo $t2->call_t1_fn(), LF;

$a = null;
$a = new Test3();

Test4::fx(new Test3());
// ---------

// program end
