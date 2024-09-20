<?php
/**
 * This file is part of the Tea programming language project
 * @copyright 	(c)2019 tealang.org
 * For the full copyright and license information, please view the LICENSE file that was distributed with this source code.
 */

namespace Tea;

abstract class BaseCallExpression extends BaseExpression
{
	/**
	 * @var BaseExpression
	 */
	public $callee;

	/**
	 * @var BaseExpression[]
	 */
	public $arguments;

	public $normalized_arguments; // for render to target lang

	public $infered_callee_declaration;

	/**
	 * when creating class instance, set to true
	 * @var bool
	 */
	public $is_instancing;

	public function __construct(BaseExpression $callee, array $arguments)
	{
		$callee->set_purpose(PURPOSE_INVOKING);
		$this->callee = $callee;
		$this->arguments = $arguments;
	}
}

/**
 * class instance creating expression
 */
class InstancingExpression extends BaseCallExpression
{
	const KIND = 'new_expression';
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

	public $callbacks = []; // callback arguments

	public function set_callbacks(CallbackArgument ...$callbacks)
	{
		$this->callbacks = $callbacks;
	}
}

class CallbackArgument extends Node
{
	const KIND = 'callback_argument';

	public $name;
	public $value;

	public function __construct(?string $name, BaseExpression $value)
	{
		$this->name = $name;
		$this->value = $value;
	}
}

// end
