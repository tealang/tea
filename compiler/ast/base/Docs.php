<?php
/**
 * This file is part of the Tea programming language project
 *
 * @author 		Benny <benny@meetdreams.com>
 * @copyright 	(c)2019 YJ Technology Ltd. [http://tealang.org]
 * For the full copyright and license information, please view the LICENSE file that was distributed with this source code.
 */

namespace Tea;

class Docs extends Node
{
	const KIND = 'docs';

	/**
	 * @var string[] | ParameterDoc[]
	 */
	public $items;

	/**
	 * used for check, and render target code
	 * @var ParameterDoc[]
	 */
	public $parameter_items;

	/**
	 * @items string[] | ParameterDoc[]
	 * @parameter_items ParameterDoc[]
	 */
	public function __construct(array $items, array $parameter_items = null)
	{
		$this->items = $items;
		$this->parameter_items = $parameter_items;
	}
}

class ParameterDoc extends Node
{
	const KIND = 'parameter_doc';

	public $name;

	public $options;

	public $comment;

	public function __construct(string $name, ?array $options, string $comment)
	{
		$this->name = $name;
		$this->options = $options;
		$this->comment = $comment;
	}
}
