<?php
/**
 * This file is part of the Tea programming language project
 *
 * @author 		Benny <benny@meetdreams.com>
 * @copyright 	(c)2019 YJ Technology Ltd. [http://tealang.org]
 * For the full copyright and license information, please view the LICENSE file that was distributed with this source code.
 */

namespace Tea;

class OperatorSymbol
{
	public $sign;
	public $precedence;

	// for render
	public $dist_sign;
	public $dist_precedence;

	public function __construct(string $sign, int $precedence)
	{
		$this->sign = $sign;
		$this->precedence = $precedence;
	}
}

