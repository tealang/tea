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

trait TeaXTagTrait
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

		if ($xtag->closing_indents and str_starts_with($xtag->closing_indents, $align_spaces)) {
			$indent_len = strlen($align_spaces);
			$xtag->closing_indents = substr($xtag->closing_indents, $indent_len);
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

	private function read_xtag_with_name(string $name, string $align_spaces)
	{
		if ($name === _XTAG_COMMENT_OPEN) {
			return $this->read_xtag_comment($align_spaces);
		}

		$is_literal = true;
		$attributes = $this->read_xtag_attributes($is_literal);
		$current_token = $this->get_current_token_string();

		if ($current_token === _XTAG_SELF_CLOSE) {
			$elem = new XTag($name, $attributes);
		}
		elseif (in_array(strtolower($name), _SELF_CLOSING_TAGS, true)) {
			$elem = new XTag($name, $attributes);
			$elem->is_self_closing_tag = true;
		}
		// expect tag head close
		elseif ($current_token === _XTAG_CLOSE) {
			// has inner line break?
			$inner_br = false;
			if ($this->get_token_ignore_space() === LF) {
				$this->scan_token_ignore_space();
				$inner_br = true;
			}

			$elem = new XTag($name, $attributes);
			$elem->inner_br = $inner_br;

			$this->read_xtag_children($elem, $align_spaces, $is_literal, $inner_br);
			if ($align_spaces) {
				$this->strip_align_spaces_for_items($elem, $align_spaces);
			}
		}
		else {
			throw $this->new_unexpected_error();
		}

		$elem->is_literal = $is_literal;
		$elem->pos = $this->pos;

		return $elem;
	}

	protected function try_read_ending_for_xtag_child(XTagElement $child)
	{
		// is ending with line break?
		if ($this->get_token_ignore_space() === LF) {
			$this->scan_token_ignore_space();
			$child->tailing_br = true;
		}
	}

	private function strip_align_spaces_for_items(XTag $tag, string $align_spaces)
	{
		$indent_len = strlen($align_spaces);
		foreach ($tag->children as $idx => $item) {
			if ($item->indents and str_starts_with($item->indents, $align_spaces)) {
				$item->indents = substr($item->indents, $indent_len);
			}

			if ($item instanceof XTag and $item->closing_indents and str_starts_with($item->closing_indents, $align_spaces)) {
				$item->closing_indents = substr($item->closing_indents, $indent_len);
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

	private function read_xtag_children(XTag $tag, string $align_spaces, bool &$is_literal, bool $is_newline)
	{
		$indents = $is_newline ? $this->scan_spaces() : '';
		$name = $tag->name;
		$tag->children = [];
		$text = '';
		$closed = false;
		while (($token = $this->scan_token()) !== null) {
			switch ($token) {
				case LF:
					$this->xtag_append_text($tag, $text, true, $is_newline, $indents);
					break;

				case _XTAG_OPEN:
					// maybe a tag
					$next = $this->read_xtag_name();
					if (TeaHelper::is_xtag_name($next)) {
						if ($text !== '') {
							$this->xtag_append_text($tag, $text, false, $is_newline, $indents);
						}

						$child_tag = $this->read_xtag_with_name($next, $align_spaces);
						$this->try_read_ending_for_xtag_child($child_tag);
						$this->xtag_append_expr($tag, $child_tag, $is_newline, $indents);

						if (!$child_tag->is_literal) {
							$is_literal = false;
						}
					}
					elseif ($next === _SLASH) { // the </
						if ($this->read_xtag_name() !== $name) {
							// a wrong close tag
							throw $this->new_parse_error("Unexpected close tag '</$name>'.");
						}

						$this->expect_token_ignore_empty(_XTAG_CLOSE); // the >

						// element end
						$closed = true;
						break 2;
					}
					elseif ($name === '' and $next === _XTAG_SELF_CLOSE) {
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

						if ($text !== '') {
							$this->xtag_append_text($tag, $text, false, $is_newline, $indents);
						}

						$expr = $this->read_sharp_interpolation();
						$this->xtag_append_expr($tag, $expr, $is_newline, $indents);
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

						if ($text !== '') {
							$this->xtag_append_text($tag, $text, false, $is_newline, $indents);
						}

						$this->xtag_append_expr($tag, $expr, $is_newline, $indents);
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
			throw $this->new_parse_error("Missed close tag '</$name>'.");
		}

		// the last child
		if ($text !== '') {
			$this->xtag_append_text($tag, $text, false, $is_newline, $indents);
		}

		$tag->closing_indents = $indents;
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

	private function xtag_append_expr(XTag $tag, XTagElement | BaseExpression $child, bool &$is_newline, string &$indents)
	{
		$tag->children[] = $child;

		if ($is_newline) {
			$child->indents = $indents;
			$indents = '';
		}

		$tailing_br = $child->tailing_br;
		if ($tailing_br) {
			$child->tailing_br = true;
			$indents = $this->scan_spaces();
		}

		// for next
		$is_newline = $tailing_br;
	}

	private function xtag_append_text(XTag $tag, string &$text, bool $tailing_br, bool &$is_newline, string &$indents)
	{
		$child = new XTagText($text);
		$tag->children[] = $child;

		if ($is_newline) {
			$child->indents = $indents;
			$indents = '';
		}

		if ($tailing_br) {
			$child->tailing_br = true;
			$indents = $this->scan_spaces();
		}

		// for next
		$is_newline = $tailing_br;

		// reset
		$text = '';

		return $child;
	}
}

// end
