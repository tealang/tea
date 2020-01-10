<?php
#internal
interface IBaseType {
	public function string(): string;
	public function int(): int;
	public function uint(): int;
	public function float(): float;
}

trait IBaseTypeTrait {
	// no any
}

#public
interface IView {
	// no any
}

// program end
