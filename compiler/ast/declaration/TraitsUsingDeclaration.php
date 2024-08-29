<?php
/**
 * This file is part of the Tea programming language project
 * @copyright 	(c)2019 tealang.org
 * For the full copyright and license information, please view the LICENSE file that was distributed with this source code.
 */

namespace Tea;

class TraitsUsingStatement extends BaseStatement
{
	const KIND = 'traits_using_statement';

	public $items;

	public $options;

	public function __construct(array $items, ?array $options = [])
	{
		$this->items = $items;
		$this->options = $options;
	}
}

// end
