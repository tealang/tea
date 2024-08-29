<?php
/**
 * This file is part of the Tea programming language project
 * @copyright 	(c)2019 tealang.org
 * For the full copyright and license information, please view the LICENSE file that was distributed with this source code.
 */

namespace Tea;

class IncludeExpression extends BaseExpression
{
	const KIND = 'include_expression';

	public $target;

	/**
	 * the including mode
	 * @var int  T_INCLUDE | T_INCLUDE_ONCE | T_REQUIRE | T_REQUIRE_ONCE
	 */
	public $mode;

	/**
	 * @var Symbol[]
	 */
	public $symbols; // use for check

	public function __construct(BaseExpression $target, int $mode)
	{
		$this->target = $target;
		$this->mode = $mode;
	}
}

// end
