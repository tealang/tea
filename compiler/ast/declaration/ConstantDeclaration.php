<?php
/**
 * This file is part of the Tea programming language project
 * @copyright 	(c)2019 tealang.org
 * For the full copyright and license information, please view the LICENSE file that was distributed with this source code.
 */

namespace Tea;

interface IConstantDeclaration extends IValuedDeclaration {}

class ConstantDeclaration extends BaseDeclaration implements IConstantDeclaration, IRootDeclaration
{
	use IRootDeclarationTrait;

	const KIND = 'constant_declaration';

	public $is_static = true;

	public $value;

	public function __construct(?string $modifier, string $name, ?BaseType $type = null, ?BaseExpression $value = null)
	{
		if ($modifier === _PUBLIC) {
			$this->is_unit_level = true;
		}

		$this->modifier = $modifier;
		$this->name = $name;
		$this->declared_type = $type;
		$this->value = $value;
	}
}

// end
