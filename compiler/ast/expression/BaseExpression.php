<?php
/**
 * This file is part of the Tea programming language project
 * @copyright 	(c)2019 tealang.org
 * For the full copyright and license information, please view the LICENSE file that was distributed with this source code.
 */

namespace Tea;

abstract class BaseExpression extends Node
{
	// for render
	public $expressed_type;

	public $is_const_value;

	/**
	 * @var bool
	 */
	public $is_calling;

	/**
	 * @var bool
	 */
	public $is_accessing;
}

// end
