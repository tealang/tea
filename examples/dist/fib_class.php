<?php
namespace tea\examples;

require_once dirname(__DIR__, 1) . '/__public.php';

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
		$this->current += $this->previous;
		$this->previous = $temp;
		$this->current_index += 1;

		return $temp;
	}
}

// ---------
$fib = new Fib(9);
try {
	while ($fib->has_next()) {
		echo $fib->get_next(), LF;
	}
}
catch (\Exception $ex) {
	echo $ex->getMessage(), LF;
}
// ---------

// program end
