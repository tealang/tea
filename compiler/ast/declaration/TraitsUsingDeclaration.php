<?php
/**
 * This file is part of the Tea programming language project
 * @copyright 	(c)2019 tealang.org
 * For the full copyright and license information, please view the LICENSE file that was distributed with this source code.
 */

namespace Tea;

class TraitsUsingStatement extends BaseClassMemberDeclaration
{
	const KIND = 'traits_using_statement';

	/**
	 * @var array
	 */
	public array $items;

	/**
	 * @var array
	 */
	public array $options;

	public function __construct(array $items, ?array $options = [])
	{
		parent::__construct(null, 'trait_use_' . spl_object_id($this));
		$this->items = $items;
		$this->options = $options;
	}
}

// end
