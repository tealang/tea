<?php
/**
 * This file is part of the Tea programming language project
 * @copyright 	(c)2019 tealang.org
 * For the full copyright and license information, please view the LICENSE file that was distributed with this source code.
 */

namespace Tea;

class EnumDeclaration extends ClassKindredDeclaration
{
	const KIND = 'enum_declaration';

	public ?IType $value_type;

}

class EnumCaseDeclaration extends BaseClassMemberDeclaration implements IValuableDeclaration
{
	const KIND = 'enum_member_declaration';

	public $is_static = true;

	public function __construct(string $name)
	{
		$this->name = $name;
	}
}


// end
