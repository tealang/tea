<?php
/**
 * This file is part of the Tea programming language project
 * @copyright 	(c)2019 tealang.org
 * For the full copyright and license information, please view the LICENSE file that was distributed with this source code.
 */

namespace Tea;

abstract class BracketAccessing extends BaseExpression
{
	/**
	 * @var BaseExpression
	 */
	public $basing;

	public function get_final_basing()
	{
		$basing = $this->basing;
		while ($basing instanceof BracketAccessing) {
			$basing = $basing->basing;
		}

		return $basing;
	}
}

// e.g. list[], []list
class SquareAccessing extends BracketAccessing implements IAssignable
{
	const KIND = 'square_accessing';

	/**
	 * @var bool
	 */
	public $is_prefix;

	public function __construct(BaseExpression $basing, bool $is_prefix)
	{
		$this->basing = $basing;
		$this->is_prefix = $is_prefix;
	}
}

class KeyAccessing extends BracketAccessing implements IAssignable
{
	const KIND = 'key_accessing';

	/**
	 * @var BaseExpression
	 */
	public $key;

	public function __construct(BaseExpression $basing, BaseExpression $key)
	{
		$this->basing = $basing;
		$this->key = $key;
	}
}

// end
