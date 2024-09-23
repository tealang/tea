<?php
/**
 * This file is part of the Tea programming language project
 * @copyright 	(c)2019 tealang.org
 * For the full copyright and license information, please view the LICENSE file that was distributed with this source code.
 */

namespace Tea;

abstract class InterpolatedString extends BaseExpression
{
	public $items;

	public function __construct(array $items)
	{
		$this->items = $items;
	}
}

class HereDocString extends InterpolatedString
{
	const KIND = 'heredoc_string';
}

class EscapedInterpolatedString extends InterpolatedString
{
	const KIND = 'escaped_interpolated_string';
}

class PlainInterpolatedString extends InterpolatedString
{
	const KIND = 'plain_interpolated_string';
}

interface Interpolation {}

trait InterpolationTrait
{
	public $content;

	public function __construct(BaseExpression $content)
	{
		if ($content instanceof Parentheses) {
			$content = $content->expression;
		}

		$this->content = $content;
	}
}

class StringInterpolation extends BaseExpression implements Interpolation
{
	use InterpolationTrait;
	const KIND = 'string_interpolation';
}

// end
