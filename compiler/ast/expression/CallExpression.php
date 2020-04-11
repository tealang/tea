<?php
/**
 * This file is part of the Tea programming language project
 *
 * @author 		Benny <benny@meetdreams.com>
 * @copyright 	(c)2019 YJ Technology Ltd. [http://tealang.org]
 * For the full copyright and license information, please view the LICENSE file that was distributed with this source code.
 */

namespace Tea;

interface ICallee {}

class CallExpression extends BaseExpression
{
	const KIND = 'call_expression';

	public $callee;
	public $arguments; // array of BaseExpression
	public $callbacks = []; // callback arguments

	public $normalized_arguments; // for render to target lang

	public function __construct(ICallee $callee, array $arguments)
	{
		$callee->is_call_mode = true;

		$this->callee = $callee;
		$this->arguments = $arguments;
	}

	public function set_callbacks(CallbackArgument ...$callbacks)
	{
		$this->callbacks = $callbacks;
	}

	public function is_class_new()
	{
		$symbol = $this->callee->symbol;
		if ($symbol === null) {
			return false;
		}
		elseif ($symbol->declaration instanceof ClassDeclaration) {
			return true;
		}
		elseif ($symbol->declaration instanceof IVariableDeclaration && $symbol->declaration->type instanceof MetaType) {
			return true;
		}

		return false;
	}
}

class CallbackArgument extends Node
{
	const KIND = 'callback_argument';

	public $name;
	public $value;

	public function __construct(?string $name, ICallee $value)
	{
		$this->name = $name;
		$this->value = $value;
	}
}
