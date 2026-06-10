<?php
/**
 * This file is part of the Tea programming language project
 * @copyright 	(c)2019 tealang.org
 * For the full copyright and license information, please view the LICENSE file that was distributed with this source code.
 */

namespace Tea;

class UseStatement extends BaseStatement
{
	const KIND = 'use_statement';

	public NamespaceIdentifier $ns;
	public array $targets;
	public array $attributes = [];

	public function __construct(NamespaceIdentifier $ns, array $targets = [], array $attributes = [])
	{
		$this->ns = $ns;
		$this->targets = $targets;
		$this->attributes = $attributes;
	}

	public function append_target(UseDeclaration $target)
	{
		if (!isset($this->targets[$target->name])) {
			$this->targets[$target->name] = $target;
		}
	}
}
