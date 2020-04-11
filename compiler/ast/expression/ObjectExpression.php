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
	const KIND = 'object_expression';

	// [key => value] map
	public $items;

	/**
	 * @var bool
	 */
	public $is_vertical_layout = false; // for render target code

	public function __construct(array $items)
	{
		$this->items = $items;
	}
}
