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

abstract class UnaryOperation extends BaseOperation
{
	public $expression;

	public function __construct(BaseExpression $expression, Operator $operator)
	{
		$this->expression = $expression;
		$this->operator = $operator;
	}
}

abstract class MultiOperation extends BaseOperation {}

class PrefixOperation extends UnaryOperation
{
	const KIND = 'prefix_operation';
}

class PostfixOperation extends UnaryOperation
{
	const KIND = 'postfix_operation';
}

// class ReferenceOperation extends UnaryOperation
// {
// 	const KIND = 'reference_operation';

// 	public function __construct(Identifiable $identifier)
// 	{
// 		parent::__construct($identifier, OperatorFactory::$reference);
// 	}
// }

class BinaryOperation extends MultiOperation
{
	const KIND = 'binary_operation';

	public $type_assertion;  // for type assert in Checker

	public $left;
	public $right;

	public function __construct(BaseExpression $left, BaseExpression $right, Operator $operator)
	{
		$this->left = $left;
		$this->right = $right;
		$this->operator = $operator;
	}
}

class AssignmentOperation extends BinaryOperation
{
	const KIND = 'assignment_operation';
}

class AsOperation extends BinaryOperation
{
	const KIND = 'as_operation';

	public function __construct(BaseExpression $left, IType $right)
	{
		$this->left = $left;
		$this->right = $right;
		$this->operator = OperatorFactory::$as;
	}
}

class IsOperation extends BinaryOperation
{
	const KIND = 'is_operation';

	public $not;

	public function __construct(BaseExpression $left, IType $right, bool $not = false)
	{
		$this->left = $left;
		$this->right = $right;
		$this->not = $not;
		$this->operator = OperatorFactory::$is;
	}
}

class NoneCoalescingOperation extends MultiOperation
{
	const KIND = 'none_coalescing_operation';

	/**
	 * @var BaseExpression[]
	 */
	public $items;

	public function __construct(array $items) {
		$this->items = $items;
		$this->operator = OperatorFactory::$none_coalescing;
	}
}

class TernaryExpression extends MultiOperation
{
	const KIND = 'ternary_expression';

	public $condition;
	public $then;
	public $else;

	public function __construct(BaseExpression $condition, ?BaseExpression $then, BaseExpression $else)
	{
		$this->condition = $condition;
		$this->then = $then;
		$this->else = $else;
		$this->operator = OperatorFactory::$ternary;
	}
}

// end
