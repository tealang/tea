<?php
/**
 * This file is part of the Tea programming language project
 *
 * @author 		Benny <benny@meetdreams.com>
 * @copyright 	(c)2019 YJ Technology Ltd. [http://tealang.org]
 * For the full copyright and license information, please view the LICENSE file that was distributed with this source code.
 */

namespace Tea;

// e.g. list[], []list
class SquareAccessing extends BaseExpression implements IAssignable
{
	const KIND = 'square_accessing';

	/**
	 * @var BaseExpression
	 */
	public $expression;

	/**
	 * @var bool
	 */
	public $is_prefix;

	public function __construct(BaseExpression $expression, bool $is_prefix)
	{
		$this->expression = $expression;
		$this->is_prefix = $is_prefix;
	}
}

class KeyAccessing extends BaseExpression implements IAssignable
{
	const KIND = 'key_accessing';

	public $left;
	public $right;

	public function __construct(BaseExpression $left, BaseExpression $right = null)
	{
		$this->left = $left;
		$this->right = $right;
	}
}

// use AccessingIdentifier instead
// class MemberAccessing extends BaseExpression implements IAssignable
// {
// 	const KIND = 'member_accessing';

// 	public $left;
// 	public $right;

// 	public function __construct(BaseExpression $left, BaseExpression $right)
// 	{
// 		$this->left = $left;
// 		$this->right = $right;
// 	}
// }
