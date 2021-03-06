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

	public function is_accessable_for_object(BaseExpression $expr) {
		if ($this->modifier === _PRIVATE) {
			return $expr instanceof PlainIdentifier && $expr->symbol === $this->super_block->this_object_symbol;
		}
		elseif ($this->modifier === _PROTECTED) {
			return $expr instanceof PlainIdentifier && ($expr->name === _THIS || $expr->name === _SUPER);
		}

		return true;
	}

	public function is_accessable_for_class(BaseExpression $expr) {
		if ($this->modifier === _PRIVATE) {
			return $expr instanceof PlainIdentifier && $expr->symbol === $this->super_block->this_class_symbol;
		}
		elseif ($this->modifier === _PROTECTED) {
			return $expr instanceof PlainIdentifier && ($expr->name === _THIS || $expr->name === _SUPER);
		}

		return true;
	}
}

class PropertyDeclaration extends BaseVariableDeclaration implements IClassMemberDeclaration, IVariableDeclaration
{
	use IClassMemberDeclarationTrait;

	const KIND = 'property_declaration';

	public function __construct(?string $modifier, string $name, IType $type = null, BaseExpression $value = null)
	{
		$this->modifier = $modifier;
		$this->name = $name;
		$this->value = $value;
		$this->type = $type;
	}
}
