<?php
/**
 * This file is part of the Tea programming language project
 *
 * @author 		Benny <benny@meetdreams.com>
 * @copyright 	(c)2019 YJ Technology Ltd. [http://tealang.org]
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
	 * @var []BaseExpression
	 */
	public $arguments;

	public $normalized_arguments; // for render to target lang

	public $infered_callee_declaration;

	// public $is_calling;

	public function __construct(BaseExpression $callee, array $arguments)
	{
		if ($callee instanceof PlainIdentifier) {
			$callee->is_calling = true;
		}

		$this->callee = $callee;
		$this->arguments = $arguments;
	}

	public function is_class_new()
	{
		$declar = $this->infered_callee_declaration;
		return $declar instanceof ClassDeclaration
			|| ($declar instanceof IVariableDeclaration && $declar->declared_type instanceof MetaType);
	}
}

class PipeCallExpression extends BaseCallExpression
{
	const KIND = 'pipecall_expression';
}

// the normal call
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
