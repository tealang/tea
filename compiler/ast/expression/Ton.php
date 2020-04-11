<?php
/**
 * This file is part of the Tea programming language project
 *
 * @author 		Benny <benny@meetdreams.com>
 * @copyright 	(c)2019 YJ Technology Ltd. [http://tealang.org]
 * For the full copyright and license information, please view the LICENSE file that was distributed with this source code.
 */

namespace Tea;

class Ton extends BaseExpression
{
	const KIND = 'ton';

	public $name;
	public $attributes;
	public $elements;

	public function __construct(?string $name, array $attributes = [], array $elements = null)
	{
		$this->name = $name;
		$this->attributes = $attributes;
		$this->elements = $elements;
	}
}
