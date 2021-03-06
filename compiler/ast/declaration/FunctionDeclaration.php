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

trait IScopeBlockTrait
{
	use DeclarationTrait, IBlockTrait;

	// public $is_static = false;

	public $parameters;

	public $fixed_body;

	// public $auto_declarations = [];

	public $is_checking; // set true when on checking by ASTChecker

	function set_body_with_expression(BaseExpression $expression)
	{
		$this->body = $expression;
	}
}

class FunctionDeclaration extends Node implements IScopeBlock, IClassMemberDeclaration, IRootDeclaration
{
	use IClassMemberDeclarationTrait, IScopeBlockTrait;

	const KIND = 'function_declaration';

	public $ns;

	// public $modifier;

	public $callbacks;

	/**
	 * @var Program
	 */
	public $program;

	function __construct(?string $modifier, string $name, IType $type = null, array $parameters = null)
	{
		if ($modifier !== null && $modifier === _PUBLIC) {
			$this->is_unit_level = true;
		}

		$this->modifier = $modifier;
		$this->name = $name;
		$this->type = $type;
		$this->parameters = $parameters;
	}

	// function set_callbacks(CallbackProtocol ...$callbacks)
	// {
	// 	$this->callbacks = $callbacks;
	// }
}

// end
