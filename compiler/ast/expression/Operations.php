<?php
/**
 * This file is part of the Tea programming language project
 *
 * @author 		Benny <benny@meetdreams.com>
 * @copyright 	(c)2019 YJ Technology Ltd. [http://tealang.org]
 * For the full copyright and license information, please view the LICENSE file that was distributed with this source code.
 */

namespace Tea;

abstract class BaseBinaryOperation extends Node implements IExpression
{
	public $left;
	public $right;
	public $operator;
}

class AsOperation extends BaseBinaryOperation
{
	const KIND = 'as_operation';

	public function __construct(IExpression $left, IType $right)
	{
		$this->operator = OperatorFactory::$_as;
		$this->left = $left;
		$this->right = $right;
	}
}

class IsOperation extends BaseBinaryOperation
{
	const KIND = 'is_operation';

	public function __construct(IExpression $left, IExpression $right)
	{
		$this->operator = OperatorFactory::$_is;
		$this->left = $left;
		$this->right = $right;
	}
}

class BinaryOperation extends BaseBinaryOperation
{
	const KIND = 'binary_operation';

	public function __construct(OperatorSymbol $operator, IExpression $left, IExpression $right) {
		$this->operator = $operator;
		$this->left = $left;
		$this->right = $right;
	}
}

class PrefixOperation extends Node implements IExpression
{
	const KIND = 'prefix_operation';

	public $operator;
	public $expression;

	public function __construct(OperatorSymbol $operator, IExpression $expression) {
		$this->operator = $operator;
		$this->expression = $expression;
	}
}

class ReferenceOperation extends Node implements IExpression
{
	const KIND = 'reference_operation';

	public $identifier;

	public function __construct(Identifiable $identifier) {
		$this->identifier = $identifier;
	}
}

// class PostfixOperation extends Node implements IExpression
// {
// 	const KIND = 'postfix_operation';

// 	public $operator;
// 	public $expression;

// 	public function __construct(OperatorSymbol $operator, IExpression $expression)
// 	{
// 		$this->operator = $operator;
// 		$this->expression = $expression;
// 	}
// }

// class FunctionalOperation extends Node implements IExpression
// {
// 	const KIND = 'functional_operation';

// 	public $operator;
// 	public $arguments;

// 	public function __construct(OperatorSymbol $operator, IExpression ...$arguments)
// 	{
// 		$this->operator = $operator;
// 		$this->arguments = $arguments;
// 	}
// }
