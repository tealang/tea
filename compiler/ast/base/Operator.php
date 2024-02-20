<?php
/**
 * This file is part of the Tea programming language project
 *
 * @author 		Benny <benny@meetdreams.com>
 * @copyright 	(c)2019 YJ Technology Ltd. [http://tealang.org]
 * For the full copyright and license information, please view the LICENSE file that was distributed with this source code.
 */

namespace Tea;

class Operator
{
	/**
	 * @var int
	 */
	private $id;

	public $tea_sign;
	public $tea_prec;	// precedence
	public $tea_assoc; 	// associativity

	public $php_sign;
	public $php_prec;
	public $php_assoc;

	public function __construct(int $id)
	{
		$this->id = $id;
	}

	public function is(int $id)
	{
		return $this->id === $id;
	}
}

// end
