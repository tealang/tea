<?php
/**
 * This file is part of the Tea programming language project
 * @copyright 	(c)2019 tealang.org
 * For the full copyright and license information, please view the LICENSE file that was distributed with this source code.
 */

namespace Tea;

interface IFunctionDeclaration extends IBlock, ICallableDeclaration {
	public function set_parameters(array $parameters): void;
	public function set_declared_type(?BaseType $type): void;
}

trait FunctionTrait
{
	use IBlockTrait;

	/**
	 * @var CallbackArgument[]
	 */
	public array $callbacks = [];

	/**
	 * @var ParameterDeclaration[]
	 */
	public array $parameters = [];

	// the function/method is mutating global variables, object properties, resources
	public bool $is_mutating = false;

	public function set_body_with_expression(BaseExpression $expression)
	{
		$this->body = $expression;
	}

	public function set_parameters(array $parameters): void
	{
		$this->parameters = $parameters;
	}

	public function set_declared_type(?BaseType $type): void
	{
		$this->declared_type = $type;
	}
}

class FunctionDeclaration extends RootDeclaration implements IFunctionDeclaration
{
	use FunctionTrait;

	const KIND = 'function_declaration';

	public bool $is_static = false;

	public function __construct(?string $modifier, string $name, ?BaseType $return_type = null)
	{
		if ($modifier === _PUBLIC) {
			$this->is_unit_level = true;
		}

		$this->modifier = $modifier;
		$this->name = $name;
		$this->declared_type = $return_type;
	}
}

// end
