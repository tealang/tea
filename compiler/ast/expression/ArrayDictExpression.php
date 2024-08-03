<?php
/**
 * This file is part of the Tea programming language project
 *
 * @author 		Benny <benny@meetdreams.com>
 * @copyright 	(c)2019 YJ Technology Ltd. [http://tealang.org]
 * For the full copyright and license information, please view the LICENSE file that was distributed with this source code.
 */

namespace Tea;

trait MemberContainerTrait
{
	public $items;

	/**
	 * @var bool
	 */
	public $is_vertical_layout = false; // for render target code

	public function __construct(array $items = [])
	{
		$this->items = $items;
	}
}

interface IArrayLikeExpression {}

class ArrayExpression extends BaseExpression implements IArrayLikeExpression
{
	use MemberContainerTrait;

	const KIND = 'array_expression';

	/**
	 * @var array of IExpession
	 */
	public $items;
}

class DictExpression extends BaseExpression implements IArrayLikeExpression
{
	use MemberContainerTrait;

	const KIND = 'dict_expression';

	/**
	 * @var array of DictMember
	 */
	public $items;
}

class DictMember extends Node
{
	const KIND = 'dict_member';

	public $key;
	public $value;

	public function __construct(BaseExpression $key, BaseExpression $value)
	{
		$this->key = $key;
		$this->value = $value;
	}
}

// end
