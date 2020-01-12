<?php
/**
 * This file is part of the Tea programming language project
 *
 * @author 		Benny <benny@meetdreams.com>
 * @copyright 	(c)2019 YJ Technology Ltd. [http://tealang.org]
 * For the full copyright and license information, please view the LICENSE file that was distributed with this source code.
 */

namespace Tea;

interface IVariableDeclaration extends IDeclaration {}

trait IVariableDeclarationTrait
{
	use DeclarationTrait;

	public $value;

	public $is_assignable = true;

	public $is_mutable = true;

	public function __construct(string $name, IType $type = null, IExpression $value = null, bool $is_assignable = false)
	{
		$this->name = $name;
		$this->type = $type;
		$this->value = $value;
		$this->is_assignable = $is_assignable;
	}
}

class VariableDeclaration extends Node implements IVariableDeclaration, IStatement
{
	use IVariableDeclarationTrait;

	const KIND = 'variable_declaration';

	public $block; // defined in which block?
}

class SuperVariableDeclaration extends VariableDeclaration implements IRootDeclaration
{
	const KIND = 'super_variable_declaration';

	public $is_assignable = false;
}

class ParameterDeclaration extends Node implements IVariableDeclaration
{
	use IVariableDeclarationTrait;
	const KIND = 'parameter_declaration';

	public $is_referenced;
}

