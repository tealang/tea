<?php
/**
 * This file is part of the Tea programming language project
 *
 * @author 		Benny <benny@meetdreams.com>
 * @copyright 	(c)2019 YJ Technology Ltd. [http://tealang.org]
 * For the full copyright and license information, please view the LICENSE file that was distributed with this source code.
 */

namespace Tea;

class UseDeclaration extends Node implements IMemberDeclaration
{
	const KIND = 'use_declaration';

	public $ns;

	public $name;

	public $target_name;

	public $source_name;

	public $source_declaration;

	public $is_checked = false; // set true when checked by ASTChecker

	public function __construct(NSIdentifier $ns, string $target_name = null, string $source_name = null)
	{
		$this->ns = $ns;
		$this->name = $target_name;
		$this->target_name = $target_name;
		$this->source_name = $source_name;
	}
}
