<?php
/**
 * This file is part of the Tea programming language project
 *
 * @author 		Benny <benny@meetdreams.com>
 * @copyright 	(c)2019 YJ Technology Ltd. [http://tealang.org]
 * For the full copyright and license information, please view the LICENSE file that was distributed with this source code.
 */

namespace Tea;

class NamespaceDeclaration extends Node implements IRootDeclaration, IMemberDeclaration
{
	const KIND = 'namespace_declaration';

	public $name;

	public $members = [];

	public $symbols = [];

	public $belong_block;

	public function __construct(string $name)
	{
		$this->name = $name;
	}
}
