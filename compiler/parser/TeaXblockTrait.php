<?php
/**
 * This file is part of the Tea programming language project
 *
 * @author 		Benny <benny@meetdreams.com>
 * @copyright 	(c)2019 YJ Technology Ltd. [http://tealang.org]
 * For the full copyright and license information, please view the LICENSE file that was distributed with this source code.
 */

namespace Tea;

const _XTAG_SELF_CLOSE = '/>';
const _XTAG_COMMENT_OPEN = '!--';
const _XTAG_COMMENT_CLOSE = '-->';
const _SELF_CLOSING_TAGS = [
	'meta', 'link', 'img', 'input', 'br', 'hr', '!doctype',
	'wbr', 'col', 'embed', 'param', 'source', 'track', 'area', 'keygen'
];

trait TeaXBlockTrait
{
	protected function read_xtag_expression()
	{
		$align_spaces = $this->get_heading_spaces_inline();

		$token = $this->read_xtag_name();
		if ($token !== '' and !TeaHelper::is_xtag_name($token)) {
			throw $this->new_unexpected_error();
		}

		$xtag = $this->read_xtag_with_name($token, $align_spaces);

		$skiped_spaces = '';
		while ($this->get_token_closely($skiped_spaces) === _XTAG_OPEN) {
			$this->scan_token_ignore_empty(); // skip _XTAG_OPEN
			throw $this->new_unexpected_error();
		}

		return $xtag;
	}

	private function read_xtag_name()
	{
		$token = $this->scan_token();
		if ($token === _XTAG_CLOSE) {
			$this->back();
			return '';
		}

		// <!--
		// <!DOCTYPE
		if ($token === _EXCLAMATION) {
			$token .= $this->scan_token();
		}

		$next = $this->get_token();

		// <prefix:posfix
		// <prefix-posfix
		if ($next === '-' || $next === ':') {
			$this->scan_token();
			$after = $this->scan_token();
			$token .= $next . $after;
		}

		return $token;
	}

	private function read_xtag_with_name(string $tag, string $align_spaces)
	{
		if ($tag === _XTAG_COMMENT_OPEN) {
			return $this->read_xtag_comment($align_spaces);
		}

		$is_literal = true;
		$attributes = $this->read_xtag_attributes($is_literal);
		$current_token = $this->get_current_token_string();

		if ($current_token === _XTAG_SELF_CLOSE) {
			$elem = new XTag($tag, $attributes);
		}
		elseif (in_array(strtolower($tag), _SELF_CLOSING_TAGS, true)) {
			$elem = new XTag($tag, $attributes);
			$elem->is_self_closing_tag = true;
		}
		// expect tag head close
		elseif ($current_token === _XTAG_CLOSE) {
			$children = $this->read_xtag_children($tag, $align_spaces, $is_literal);
			if ($align_spaces) {
				$this->strip_leading_spaces_for_items($children, $align_spaces);
			}

			$elem = new XTag($tag, $attributes, $children);
		}
		else {
			throw $this->new_unexpected_error();
		}

		$elem->is_literal = $is_literal;
		$elem->pos = $this->pos;

		return $elem;
	}

	private function strip_leading_spaces_for_items(array &$items, string $indent_spaces)
	{
		$indent_len = strlen($indent_spaces);
		foreach ($items as $idx => $item) {
			if (is_object($item)
				and $item->indent_spaces
				and str_starts_with($item->indent_spaces, $indent_spaces)) {
				$item->indent_spaces = substr($item->indent_spaces, $indent_len);
			}
		}
	}

	private function read_xtag_comment(string $align_spaces)
	{
		$content = $this->scan_to_token(_XTAG_COMMENT_CLOSE);
		$this->scan_token(); // skip -->

		if ($align_spaces && strpos($content, $align_spaces) !== false) {
			$content = str_replace(LF . $align_spaces, LF, $content);
		}

		return new XTagComment($content);
	}

	private function read_xtag_attributes(bool &$is_literal)
	{
		$attributes = [];
		$text = '';
		$closed = false;
		while (($token = $this->scan_token()) !== null) {
			// the close tag
			if ($token === _XTAG_CLOSE || $token === _XTAG_SELF_CLOSE) {
				$closed = true;
				break;
			}

			if ($token === _SHARP && $this->skip_token(_BLOCK_BEGIN)) {
				$is_literal = false;
				$expr = $this->read_sharp_interpolation();
				$this->append_xtag_attribute($attributes, $text, $expr);
				continue;
			}
			elseif ($token === _DOLLAR) {
				$is_literal = false;
				$expr = $this->try_read_dollar_interpolation();
				if ($expr === null) {
					$text .= $token;
				}
				else {
					$this->append_xtag_attribute($attributes, $text, $expr);
				}

				continue;
			}
			elseif ($token === _BACK_SLASH) {
				$token .= $this->scan_token();
			}

			$text .= $token;
		}

		// close not found
		if (!$closed) {
			throw $this->new_unexpected_error($token);
		}

		// the ending texts
		$this->append_xtag_attribute($attributes, $text);

		return $attributes;
	}

	private function read_xtag_children(string $tag, string $align_spaces, bool &$is_literal)
	{
		$children = [];
		$text = '';
		$closed = false;
		while (($token = $this->scan_token()) !== null) {
			switch ($token) {
				case LF:
					$this->append_xtag_child($children, $text);
					$children[] = LF;
					break;

				case _XTAG_OPEN:
					// maybe a tag
					$next = $this->read_xtag_name();
					if (TeaHelper::is_xtag_name($next)) {
						$xtag = $this->read_xtag_with_name($next, $align_spaces);
						if (!$xtag->is_literal) {
							$is_literal = false;
						}

						$this->append_xtag_child($children, $text, $xtag);
					}
					elseif ($next === _SLASH) { // the </
						if ($this->read_xtag_name() !== $tag) {
							// a wrong close tag
							throw $this->new_parse_error("Unexpected close tag '</$tag>'.");
						}

						$this->expect_token_ignore_empty(_XTAG_CLOSE); // the >

						// element end
						$closed = true;
						break 2;
					}
					elseif ($tag === '' and $next === _XTAG_SELF_CLOSE) {
						// </>
						$closed = true;
						break 2;
					}
					else { // just text
						$text .= $token . $next;
						continue 2;
					}

					break;

				case _SHARP:
					if ($this->skip_token(_BLOCK_BEGIN)) {
						$is_literal = false;
						$expr = $this->read_sharp_interpolation();
						$this->append_xtag_child($children, $text, $expr);
					}
					else {
						$text .= $token;
					}
					break;

				case _DOLLAR:
					$expr = $this->try_read_dollar_interpolation();
					if ($expr === null) {
						$text .= $token;
					}
					else {
						$is_literal = false;
						$this->append_xtag_child($children, $text, $expr);
					}
					break;

				case _BACK_SLASH:
					$token .= $this->scan_token();
					// unbreak

				default:
					// the inner texts
					$text .= $token;
			}
		}

		if (!$closed) {
			throw $this->new_parse_error("Missed close tag '</$tag>'.");
		}

		// the ending texts
		$this->append_xtag_child($children, $text);

		return $children;
	}

	private function append_xtag_attribute(array &$attributes, string &$text, ?BaseExpression $expr = null)
	{
		if ($text !== '') {
			$attributes[] = $text;
		}

		if ($expr) {
			$attributes[] = $expr;
		}

		$text = ''; // reset
	}

	private function append_xtag_child(array &$children, string &$text, ?BaseExpression $expr = null)
	{
		$count = count($children);
		$is_newline = $count && $children[$count - 1] === LF;
		$text_is_spaces = trim($text) === '';

		if ($text !== '') {
			if ($is_newline) {
				if (!$text_is_spaces) {
					$elem = new XTagText(ltrim($text));
					$elem->is_newline = true;
					// $elem->indent_spaces = $this->get_pre_spaces($text);
					$children[] = $elem;
					$is_newline = false;
				}
			}
			else {
				// keep spaces when inline
				$children[] = new XTagText($text);
				$is_newline = false;
			}
		}

		if ($expr) {
			$expr->is_newline = $is_newline;
			// if ($is_newline and $text_is_spaces) {
			// 	$expr->indent_spaces = $text;
			// }

			$children[] = $expr;
		}

		$text = ''; // reset
	}

	private function get_pre_spaces(string $text)
	{
		$matched = preg_match('/^[ \t]+/', $text, $matches);
		return $matched ? $matches[0] : null;
	}
}

// end
