<?php
/**
 * This file is part of the Tea programming language project
 *
 * @author 		Benny <benny@meetdreams.com>
 * @copyright 	(c)2019 YJ Technology Ltd. [http://tealang.org]
 * For the full copyright and license information, please view the LICENSE file that was distributed with this source code.
 */

namespace Tea;

class ObjectExpression extends BaseExpression
{
	use MemberContainerTrait;

	const KIND = 'object_expression';

	public $class_declaration;
}

class ObjectMember extends PropertyDeclaration
{
	const KIND = 'object_member';

	public $key_quote_mark;

	public function __construct(string $name, string $key_quote_mark = null)
	{
		$this->name = $name;
		$this->key_quote_mark = $key_quote_mark;
	}
}
