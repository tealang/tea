<?php
/**
 * This file is part of the Tea programming language project
 * @copyright 	(c)2019 tealang.org
 * For the full copyright and license information, please view the LICENSE file that was distributed with this source code.
 */

namespace Tea;

abstract class BaseCallExpression extends BaseExpression
{
	public BaseExpression $callee;

	/**
	 * @var BaseExpression[]
	 */
	public array $arguments;

	/**
	 * @var NamedArgument[]
	 */
	public array $named_arguments = [];

	public function __construct(BaseExpression $callee, array $arguments, array $named_arguments = [])
	{
		$callee->set_purpose(PURPOSE_INVOKING);
		$this->callee = $callee;
		$this->arguments = $arguments;
		$this->named_arguments = $named_arguments;
	}
}

/**
 * class instance creating expression
 */
class InstancingExpression extends BaseCallExpression
{
	const KIND = 'new_expression';

	/**
	 * @var ClassDeclaration|null
	 */
	public ?ClassDeclaration $anonymous_class = null;

	public function __construct(BaseExpression $callee, array $arguments, array $named_arguments = [])
	{
		$callee->set_purpose(PURPOSE_INVOKING);
		$callee->set_purpose(PURPOSE_INSTANCING);
		$this->callee = $callee;
		$this->arguments = $arguments;
		$this->named_arguments = $named_arguments;
	}
}

class PipeCallExpression extends BaseCallExpression
{
	const KIND = 'pipecall_expression';
}

/**
 * function calling / class instance creating expression
 */
class CallExpression extends BaseCallExpression
{
	const KIND = 'call_expression';

	/**
	 * @var CallbackArgument[]
	 */
	public array $callbacks = []; // callback arguments

	public function set_callbacks(CallbackArgument ...$callbacks)
	{
		$this->callbacks = $callbacks;
	}
}

class FirstClassCallableExpression extends BaseExpression
{
	const KIND = 'first_class_callable_expression';

	public BaseExpression $callee;

	public function __construct(BaseExpression $callee)
	{
		$callee->set_purpose(PURPOSE_INVOKING);
		$this->callee = $callee;
	}
}

class CallbackArgument extends Node
{
	const KIND = 'callback_argument';

	public ?string $name = null;
	public BaseExpression $value;

	public function __construct(?string $name, BaseExpression $value)
	{
		$this->name = $name;
		$this->value = $value;
	}
}

/**
 * Named argument for function calls (PHP 8.0+)
 */
class NamedArgument extends Node
{
	const KIND = 'named_argument';

	public string $name;
	public BaseExpression $value;

	public function __construct(string $name, BaseExpression $value)
	{
		$this->name = $name;
		$this->value = $value;
	}
}

// end
