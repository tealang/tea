<?php
namespace tea\samples;

use Exception;

require_once __DIR__ . '/__unit.php';

#internal
interface IFib {
	const TITLE = 'Fibonacci sequence';
	public function __construct(int $max);
	public function has_next(): bool;
	public function get_next(): int;
}

trait IFibTrait {
	protected $previous = 0;
	protected $current = 1;

	protected $current_index = 0;
	protected $max;

	public function __construct(int $max) {
		$this->max = $max;
	}

	public function has_next(): bool {
		return $this->current_index <= $this->max;
	}
}

#internal
class Fib implements IFib {
	use IFibTrait;

	public function get_next(): int {
		if ($this->current_index > $this->max) {
			throw new Exception("Out of range");
		}

		$temp = $this->current;
		$this->current = $this->previous + $this->current;
		$this->previous = $temp;
		$this->current_index += 1;

		return $this->previous;
	}
}

// ---------
$fib = new Fib(9);
$list = [];
try {
	while ($fib->has_next()) {
		$list[] = '<li>' . $fib->get_next() . '</li>';
	}
}
catch (\Exception $ex) {
	echo $ex->getMessage(), NL;
	exit;
}

echo '<section>
	<h1>' . htmlspecialchars(Fib::TITLE, ENT_QUOTES) . '</h1>
	<ul>
		' . implode("\n\t\t", $list) . '
	</ul>
</section>', NL;
// ---------

// program end
