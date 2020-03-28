<?php
/**
 * This file is part of the Tea programming language project
 *
 * @author 		Benny <benny@meetdreams.com>
 * @copyright 	(c)2019 YJ Technology Ltd. [http://tealang.org]
 * For the full copyright and license information, please view the LICENSE file that was distributed with this source code.
 */

namespace Tea;

interface IClassMemberDeclaration extends IMemberDeclaration {}

trait IClassMemberDeclarationTrait
{
	public $modifier;

	public $is_static = false;

	public $super_block;

	public function is_accessable(IExpression $expr) {
		return ($this->modifier !== _PRIVATE && $this->modifier !== _INTERNAL)
			|| $expr instanceof PlainIdentifier && $expr->symbol === $this->super_block->this_object_symbol;
	}
}

class PropertyDeclaration extends Node implements IClassMemberDeclaration, IVariableDeclaration
{
	use IClassMemberDeclarationTrait, IVariableDeclarationTrait, DeferChecksTrait;

	const KIND = 'property_declaration';

	public function __construct(?string $modifier, string $name, IType $type = null, IExpression $value = null)
	{
		$this->modifier = $modifier;
		$this->name = $name;
		$this->value = $value;
		$this->type = $type;
	}
}
