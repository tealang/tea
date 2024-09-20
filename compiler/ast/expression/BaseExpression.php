<?php
/**
 * This file is part of the Tea programming language project
 * @copyright 	(c)2019 tealang.org
 * For the full copyright and license information, please view the LICENSE file that was distributed with this source code.
 */

namespace Tea;

const PURPOSE_NORMAL = 0;
const PURPOSE_IN = 1;
const PURPOSE_OUT = 2;
// const PURPOSE_INOUT = PURPOSE_IN | PURPOSE_OUT;
const PURPOSE_ACCESSING = 4;
const PURPOSE_INVOKING = 8;
const PURPOSE_ACCESSING_OR_INVOKING = PURPOSE_ACCESSING | PURPOSE_INVOKING;
// const PURPOSE_MAYBE_REFERING = 16;

abstract class BaseExpression extends Node
{
	// for render
	public $expressed_type;

	public $is_const_value;

	private int $purpose_flags = PURPOSE_NORMAL;

	public function is_assigning()
	{
		return $this->purpose_flags & PURPOSE_IN;
	}

	public function is_accessing()
	{
		return $this->purpose_flags & PURPOSE_ACCESSING;
	}

	public function is_invoking()
	{
		return $this->purpose_flags & PURPOSE_INVOKING;
	}

	public function is_accessing_or_invoking()
	{
		return $this->purpose_flags & PURPOSE_ACCESSING_OR_INVOKING;
	}

	// // for variable thats uses as arguments in call
	// public function is_maybe_refering()
	// {
	// 	return $this->purpose_flags & PURPOSE_MAYBE_REFERING;
	// }

	public function set_purpose(int $flags)
	{
		$this->purpose_flags |= $flags;
	}
}

// end
