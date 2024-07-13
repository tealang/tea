<?php
/**
 * This file is part of the Tea programming language project
 *
 * @author 		Benny <benny@meetdreams.com>
 * @copyright 	(c)2019 YJ Technology Ltd. [http://tealang.org]
 * For the full copyright and license information, please view the LICENSE file that was distributed with this source code.
 */

namespace Tea;

interface IScopeBlock extends IBlock, ICallableDeclaration {}
interface IFunctionDeclaration extends IScopeBlock {}

trait IScopeBlockTrait
{
	use DeclarationTrait, IBlockTrait;

	public $callbacks;

	public $parameters;

	// the function/method is mutating global variables, object properties, resources
	public $is_mutating;

	// set true when checking AST
	public $is_checking;

	public function __construct(?string $modifier, string $name, IType $return_type = null, array $parameters = null)
	{
		if ($modifier === _PUBLIC) {
			$this->is_unit_level = true;
		}

		$this->modifier = $modifier;
		$this->name = $name;
		$this->hinted_type = $return_type;
		$this->parameters = $parameters;
	}

	public function set_body_with_expression(BaseExpression $expression)
	{
		$this->body = $expression;
	}
}

class FunctionDeclaration extends RootDeclaration implements IFunctionDeclaration, IRootDeclaration
{
	use IScopeBlockTrait;

	const KIND = 'function_declaration';

	public $is_static = false;
}

class MethodDeclaration extends Node implements IFunctionDeclaration, IClassMemberDeclaration, IRootDeclaration
{
	use ClassMemberDeclarationTrait, IScopeBlockTrait;

	const KIND = 'method_declaration';
}

// end
