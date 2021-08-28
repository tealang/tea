<?php
/**
 * This file is part of the Tea programming language project
 *
 * @author 		Benny <benny@meetdreams.com>
 * @copyright 	(c)2019 YJ Technology Ltd. [http://tealang.org]
 * For the full copyright and license information, please view the LICENSE file that was distributed with this source code.
 */

namespace Tea;

abstract class BaseOperation extends BaseExpression
{
	public $operator;
}

abstract class UnaryOperation extends BaseOperation {}
abstract class MultiOperation extends BaseOperation {}

class BinaryOperation extends MultiOperation
{
	const KIND = 'binary_operation';

	public $type_assertion;  // for type assert in Checker

	public $left;
	public $right;

	public function __construct(Operator $operator, BaseExpression $left, BaseExpression $right) {
		$this->operator = $operator;
		$this->left = $left;
		$this->right = $right;
	}
}

class CastOperation extends BinaryOperation
{
	const KIND = 'cast_operation';

	public function __construct(BaseExpression $left, IType $right)
	{
		$this->operator = OperatorFactory::$_cast;
		$this->left = $left;
		$this->right = $right;
	}
}

class IsOperation extends BinaryOperation
{
	const KIND = 'is_operation';

	public $is_not;

	public function __construct(BaseExpression $left, IType $right, bool $is_not = false)
	{
		$this->operator = OperatorFactory::$_is;
		$this->left = $left;
		$this->right = $right;
		$this->is_not = $is_not;
	}
}

class NoneCoalescingOperation extends MultiOperation
{
	const KIND = 'none_coalescing_operation';

	/**
	 * @var BaseExpression[]
	 */
	public $items;

	public function __construct(BaseExpression ...$items) {
		$this->operator = OperatorFactory::$_none_coalescing;
		$this->items = $items;
	}
}

class ConditionalExpression extends MultiOperation
{
	const KIND = 'conditional_expression';

	public $condition;
	public $then;
	public $else;

	public function __construct(BaseExpression $condition, ?BaseExpression $then, BaseExpression $else)
	{
		$this->operator = OperatorFactory::$_conditional;
		$this->condition = $condition;
		$this->then = $then;
		$this->else = $else;
	}
}

class PrefixOperation extends UnaryOperation
{
	const KIND = 'prefix_operation';

	public $expression;

	public function __construct(Operator $operator, BaseExpression $expression) {
		$this->operator = $operator;
		$this->expression = $expression;
	}
}

// class ReferenceOperation extends UnaryOperation
// {
// 	const KIND = 'reference_operation';

// 	public $identifier;

// 	public function __construct(Identifiable $identifier) {
// 		$this->identifier = $identifier;
// 	}
// }

// class PostfixOperation extends UnaryOperation
// {
// 	const KIND = 'postfix_operation';

// 	public $expression;

// 	public function __construct(Operator $operator, BaseExpression $expression)
// 	{
// 		$this->operator = $operator;
// 		$this->expression = $expression;
// 	}
// }

// class FunctionalOperation extends MultiOperation
// {
// 	const KIND = 'functional_operation';

// 	public $items;

// 	public function __construct(Operator $operator, BaseExpression ...$items)
// 	{
// 		$this->operator = $operator;
// 		$this->items = $items;
// 	}
// }
