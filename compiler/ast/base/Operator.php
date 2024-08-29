<?php
/**
 * This file is part of the Tea programming language project
 * @copyright 	(c)2019 tealang.org
 * For the full copyright and license information, please view the LICENSE file that was distributed with this source code.
 */

namespace Tea;

class Operator
{
	private $id;

	public $type;

	public $tea_sign;
	public $tea_prec;	// precedence
	public $tea_assoc; 	// associativity

	public $php_sign;
	public $php_prec;
	public $php_assoc;

	public function __construct(int $id)
	{
		$this->id = $id;
	}

	public function is(int $id)
	{
		return $this->id === $id;
	}

	public function is_type(int $type)
	{
		return $this->type === $type;
	}

	public function get_debug_sign()
	{
		return $this->tea_sign ?? $this->php_sign ?? $this->id;
	}
}

// end
