<?php
/**
 * This file is part of the Tea programming language project
 *
 * @author 		Benny <benny@meetdreams.com>
 * @copyright 	(c)2019 YJ Technology Ltd. [http://tealang.org]
 * For the full copyright and license information, please view the LICENSE file that was distributed with this source code.
 */

namespace Tea;

trait TeaStringTrait
{
	protected function read_unescaped_string_literal()
	{
		return new UnescapedStringLiteral($this->read_quoted_string(_SINGLE_QUOTE));
	}

	protected function read_escaped_string_literal()
	{
		return new EscapedStringLiteral($this->read_quoted_string(_DOUBLE_QUOTE));
	}

	protected function read_quoted_string(string $quote_mark)
	{
		$string = '';
		while (($token = $this->scan_string_component()) !== null) {
			if ($token === $quote_mark) {
				return $string;
			}

			$string .= $token;
			if ($token === _BACK_SLASH) {
				$string .= $this->scan_string_component(); // 略过转义字符
			}
		}

		throw $this->new_parse_error("Missed the close quote mark ($quote_mark).");
	}

	protected function read_single_quoted_expression()
	{
		$items = $this->read_quoted_items(_SINGLE_QUOTE);

		if (empty($items)) {
			$expression = new UnescapedStringLiteral(_NOTHING);
		}
		elseif (!isset($items[1]) && is_string($items[0])) {
			$expression = new UnescapedStringLiteral($items[0]);
		}
		else {
			$expression = new UnescapedStringInterpolation($items);
		}

		return $expression;
	}

	protected function read_double_quoted_expression()
	{
		$items = $this->read_quoted_items(_DOUBLE_QUOTE);

		if (empty($items)) {
			$expression = new UnescapedStringLiteral(_NOTHING);
		}
		elseif (!isset($items[1]) && is_string($items[0])) {
			$expression = new EscapedStringLiteral($items[0]);
		}
		else {
			$expression = new EscapedStringInterpolation($items);
		}

		return $expression;
	}

	protected function read_quoted_items(string $quote_mark): array
	{
		$items = [];
		$string = '';
		while (($token = $this->scan_string_component()) !== null) {
			if ($token === $quote_mark) {
				if ($string !== '') {
					$items[] = $string;
				}

				return $items;
			}
			elseif ($token === _DOLLAR) {
				$expression = $this->try_read_dollar_interpolation();
				if ($expression === null) {
					$string .= $token;
					continue;
				}

				static::collect_and_reset_temp($items, $string, $expression);
				continue;
			}
			elseif ($token === _SHARP && $this->skip_token(_BLOCK_BEGIN)) {
				$expression = $this->read_sharp_interpolation();
				if ($expression) {
					static::collect_and_reset_temp($items, $string, $expression);
				}
				continue;
			}
			elseif ($token === _BACK_SLASH) {
				$string .= $token . $this->scan_string_component(); // 略过转义字符
				continue;
			}

			$string .= $token;
		}

		throw $this->new_parse_error("Missed the quote close mark ($quote_mark).");
	}

	protected function read_sharp_interpolation()
	{
		$expression = $this->read_expression();
		if (!$expression) {
			throw $this->new_unexpected_error();
		}

		$this->expect_block_end();
		return new HTMLEscapeExpression($expression);
	}

	protected function try_read_dollar_interpolation(): ?IExpression
	{
		if ($this->get_token() === _BLOCK_BEGIN) {
			$this->scan_token(); // skip {

			$expression = $this->read_expression();
			if ($expression === null) {
				throw $this->new_parse_error("Required an expression in \${}.");
			}

			$this->expect_block_end();
		}
		else {
			// without {}
			$expression = $this->try_read_dollar_identifier();
		}

		return $expression;
	}

	protected function try_read_dollar_identifier(): ?Identifiable
	{
		$token = $this->get_token();
		if (!TeaHelper::is_identifier_name($token)) {
			return null;
		}

		$this->scan_token();

		$next = $this->get_token();
		if ($next === _DOT) {
			$temp_pos = $this->pos;
			$this->scan_token(); // temp skip the dot

			$next = $this->get_token();
			if (TeaHelper::is_identifier_name($next)) {
				$this->scan_token();
				throw $this->new_parse_error('The member accessing interpolations required \'${}\'.');
			}

			$this->pos = $temp_pos;
		}
		elseif ($next === _BRACKET_OPEN) {
			$this->scan_token();
			throw $this->new_parse_error('The key accessing interpolations required \'${}\'.');
		}

		$identifer = $this->factory->create_identifier($token);
		$identifer->pos = $this->pos;

		return $identifer;
	}

	protected static function collect_and_reset_temp(array &$items, string &$string, IExpression $expression)
	{
		if ($string !== '') {
			$items[] = $string;
			$string = ''; // reset
		}

		$items[] = $expression;
	}
}

// end
