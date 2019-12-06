<?php
/**
 * This file is part of the Tea programming language project
 *
 * @author 		Benny <benny@meetdreams.com>
 * @copyright 	(c)2019 YJ Technology Ltd. [http://tealang.org]
 * For the full copyright and license information, please view the LICENSE file that was distributed with this source code.
 */

namespace Tea;

class UseStatement extends BaseStatement
{
	const KIND = 'use_statement';

	public $ns;
	public $targets;

	public function __construct(NamespaceIdentifier $ns, array $targets = [])
	{
		$this->ns = $ns;
		$this->targets = $targets;
	}
}
