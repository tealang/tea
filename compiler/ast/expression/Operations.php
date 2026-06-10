<?php
/**
 * This file is part of the Tea programming language project
 * @copyright 	(c)2019 tealang.org
 * For the full copyright and license information, please view the LICENSE file that was distributed with this source code.
 */

namespace Tea;

abstract class BaseOperation extends BaseExpression
{
	public Operator $operator;
}

abstract class UnaryOperation extends BaseOperation
{
	public BaseExpression $expression;

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

	public BaseExpression $left;
	public $right; // BaseExpression|BaseType, depends on subclass

	public function __construct(BaseExpression $left, $right, Operator $operator)
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

	public ?string $right_source_name = null;

	public function __construct(BaseExpression $left, BaseType $right)
	{
		parent::__construct($left, $right, OperatorFactory::$as);
	}
}

class CastOperation extends BinaryOperation
{
	const KIND = 'cast_operation';

	public function __construct(BaseExpression $left, BaseType $right)
	{
		parent::__construct($left, $right, OperatorFactory::$as);
	}
}

class IsOperation extends BinaryOperation
{
	const KIND = 'is_operation';

	public bool $not;

	public function __construct(BaseExpression $left, BaseType|BaseExpression $right, bool $not = false)
	{
		parent::__construct($left, $right, OperatorFactory::$is);
		$this->not = $not;
	}
}

class NoneCoalescingOperation extends BinaryOperation
{
	const KIND = 'none_coalescing_operation';

	public function __construct(BaseExpression $left, BaseExpression $right)
	{
		parent::__construct($left, $right, OperatorFactory::$none_coalescing);
	}
}

class TernaryExpression extends MultiOperation
{
	const KIND = 'ternary_expression';

	public BaseExpression $condition;
	public ?BaseExpression $then = null;
	public BaseExpression $else;

	public function __construct(BaseExpression $condition, ?BaseExpression $then, BaseExpression $else)
	{
		$this->condition = $condition;
		$this->then = $then;
		$this->else = $else;
		$this->operator = OperatorFactory::$ternary;
	}
}

// end
