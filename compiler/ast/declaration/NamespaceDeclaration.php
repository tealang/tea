<?php
/**
 * This file is part of the Tea programming language project
 * @copyright 	(c)2019 tealang.org
 * For the full copyright and license information, please view the LICENSE file that was distributed with this source code.
 */

namespace Tea;

class NamespaceDeclaration extends BaseDeclaration implements IRootDeclaration
{
	const KIND = 'namespace_declaration';

	public $name;

	/**
	 * The sub namespaces
	 * @var array <string: NamespaceDeclaration>
	 */
	public $namespaces = [];

	public $symbols = [];

	public function __construct(string $name)
	{
		$this->name = $name;
	}
}

// end
