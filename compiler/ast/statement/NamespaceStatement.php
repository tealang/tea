<?php
/**
 * This file is part of the Tea programming language project
 * @copyright 	(c)2019 tealang.org
 * For the full copyright and license information, please view the LICENSE file that was distributed with this source code.
 */

namespace Tea;

class NamespaceStatement extends BaseStatement
{
	const KIND = 'namespace_statement';

	public $ns;

	public function __construct(NamespaceIdentifier $ns)
	{
		$this->ns = $ns;
	}
}
