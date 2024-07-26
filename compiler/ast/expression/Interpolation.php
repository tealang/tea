<?php
/**
 * This file is part of the Tea programming language project
 *
 * @author 		Benny <benny@meetdreams.com>
 * @copyright 	(c)2019 YJ Technology Ltd. [http://tealang.org]
 * For the full copyright and license information, please view the LICENSE file that was distributed with this source code.
 */

namespace Tea;

class Interpolation extends XTagElement
{
	const KIND = 'interpolation';

	public $content;

	public $escaping;

	public function __construct(BaseExpression $content, bool $escaping = false)
	{
		if ($content instanceof Parentheses) {
			$content = $content->expression;
		}

		$this->content = $content;
		$this->escaping = $escaping;
	}
}

// end
