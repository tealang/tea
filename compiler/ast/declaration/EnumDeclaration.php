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

	public ?BaseType $value_type = null;

}

class EnumCaseDeclaration extends BaseClassMemberDeclaration implements IValuableDeclaration
{
	const KIND = 'enum_member_declaration';

	// is_static is always true for enum cases
	
	public function __construct(string $name)
	{
		parent::__construct(null, $name, null);
		$this->is_static = true;
	}
}


// end
