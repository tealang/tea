<?php
/**
 * This file is part of the Tea programming language project
 * @copyright 	(c)2019 tealang.org
 * For the full copyright and license information, please view the LICENSE file that was distributed with this source code.
 */

namespace Tea;

const _XTAG_SELF_CLOSE = '/>';
const _XTAG_COMMENT_OPEN = '!--';
const _XTAG_COMMENT_CLOSE = '-->';
const _XTAG_SELF_CLOSING_TAGS = [
	'meta', 'link', 'img', 'input', 'br', 'hr', '!doctype',
	'wbr', 'col', 'embed', 'param', 'source', 'track', 'area', 'keygen'
];
const _XTAG_SLASH_ESCAPING_CHARS = ['{', '}', '$'];

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

		// <prefix-name
		// <prefix:name
		if ($next === _STRIKETHROUGH || $next === _COLON) {
			$this->scan_token();
			$after = $this->scan_token();
			$token .= $next . $after;
		}

		return $token;
	}

	private function scan_xtag_attr_name()
	{
		// attr
		// :attr
		// attri-bu-te

		$token = $this->get_token_ignore_empty();
		if ($token === _XTAG_CLOSE or $token === _XTAG_SELF_CLOSE or $token === _BRACE_OPEN) {
			return null;
		}

		$this->scan_token_ignore_empty();

		$name = $token;
		if ($token === _COLON) {
			$token = $this->expect_identifier_token();
			$name .= $token;
		}
		elseif (!TeaHelper::is_identifier_name($token)) {
			throw $this->new_unexpected_error();
		}

		$next = $this->get_token();
		while ($next === _STRIKETHROUGH) {
			$name .= $next;
			$this->scan_token(); // skip -
			$name .= $this->expect_identifier_token();
			$next = $this->get_token();
		}

		return $name;
	}

	private function read_xtag_with_name(string $name, string $align_spaces)
	{
		if ($name === _XTAG_COMMENT_OPEN) {
			return $this->read_xtag_comment($align_spaces);
		}

		$is_const_value = true;
		$xtag = new XTag($name);
		$this->read_xtag_attributes($xtag, $is_const_value);

		$current_token = $this->get_current_token_string();
		if ($current_token === _XTAG_SELF_CLOSE) {
			// no any
		}
		elseif (in_array(strtolower($name), _XTAG_SELF_CLOSING_TAGS, true)) {
			$xtag->is_self_closing_tag = true;
		}
		// expect tag head close
		elseif ($current_token === _XTAG_CLOSE) {
			// has inner line break?
			if ($this->get_token_ignore_space() === LF) {
				$this->scan_token_ignore_space();
				$xtag->inner_br = true;
			}

			$this->read_xtag_children($xtag, $align_spaces, $is_const_value);
			if ($align_spaces) {
				$this->strip_align_spaces_for_items($xtag, $align_spaces);
			}
		}
		else {
			throw $this->new_unexpected_error();
		}

		$xtag->is_const_value = $is_const_value;
		$xtag->pos = $this->pos;

		return $xtag;
	}

	protected function scan_ending_for_xtag_child(XTagElement $child)
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

	private function read_xtag_attributes(XTag $xtag, bool &$is_const_value)
	{
		while ($name = $this->scan_xtag_attr_name()) {
			if ($this->skip_token_ignore_space(_ASSIGN)) {
				$value = $this->read_xtag_attr_value();
			}
			else {
				$value = true;
			}

			$xtag->fixed_attributes[$name] = $value;
		}

		if ($this->skip_token_ignore_empty(_BRACE_OPEN)) {
			$xtag->dynamic_attributes = $this->read_expression();
			$this->expect_token_ignore_space(_BRACE_CLOSE);
		}

		$token = $this->scan_token_ignore_empty();
		if ($token !== _XTAG_CLOSE and $token !== _XTAG_SELF_CLOSE) {
			throw $this->new_unexpected_error();
		}
	}

	private function read_xtag_attr_value()
	{
		$token = $this->scan_token_ignore_space();
		switch ($token) {
			case _BRACE_OPEN:
				$expr = $this->read_expression();
				$expr = new XTagAttrInterpolation($expr);
				$this->expect_token_ignore_empty(_BRACE_CLOSE);
				break;
			// case _SINGLE_QUOTE:
			// 	$expr = $this->read_single_quoted_expression();
			// 	if ($expr instanceof InterpolatedString) {
			// 		throw $this->new_parse_error("Cannot use interpolation in quoted attribute values");
			// 	}
			// 	break;
			case _DOUBLE_QUOTE:
				$expr = $this->read_double_quoted_expression();
				if ($expr instanceof InterpolatedString) {
					throw $this->new_parse_error("Cannot use interpolation in quoted attribute values");
				}
				break;
			default:
				throw $this->new_unexpected_error();
		}

		return $expr;
	}

	private function read_xtag_children(XTag $xtag, string $align_spaces, bool &$is_const_value)
	{
		$is_newline = $xtag->inner_br;
		$indents = $is_newline ? $this->scan_spaces() : '';
		$name = $xtag->name;
		$xtag->children = [];
		$text = '';
		$closed = false;
		while (($token = $this->scan_token()) !== null) {
			switch ($token) {
				case LF:
					$this->xtag_append_text($xtag, $text, true, $is_newline, $indents);
					break;

				case _XTAG_OPEN:
					// maybe a tag
					$next = $this->read_xtag_name();
					if (TeaHelper::is_xtag_name($next)) {
						if ($text !== '') {
							$this->xtag_append_text($xtag, $text, false, $is_newline, $indents);
						}

						$child_tag = $this->read_xtag_with_name($next, $align_spaces);
						$this->scan_ending_for_xtag_child($child_tag);
						$this->xtag_append_expr($xtag, $child_tag, $is_newline, $indents);

						if (!$child_tag->is_const_value) {
							$is_const_value = false;
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

				case _BRACE_OPEN:
					$is_const_value = false;
					if ($text !== '') {
						$this->xtag_append_text($xtag, $text, false, $is_newline, $indents);
					}

					$expr = $this->read_html_escaping_interpolation();
					$this->xtag_append_expr($xtag, $expr, $is_newline, $indents);
					break;

				case _DOLLAR_BRACE_OPEN:
					$expr = $this->scan_normal_interpolation();
					if ($expr === null) {
						$text .= $token;
					}
					else {
						$is_const_value = false;
						if ($text !== '') {
							$this->xtag_append_text($xtag, $text, false, $is_newline, $indents);
						}

						$this->xtag_append_expr($xtag, $expr->content, $is_newline, $indents);
					}
					break;

				case _BACK_SLASH:
					$token = $this->scan_token();
					if (!in_array($token, _XTAG_SLASH_ESCAPING_CHARS, true)) {
						$token = '\'' . $token;
					}

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
			$this->xtag_append_text($xtag, $text, false, $is_newline, $indents);
		}

		$xtag->closing_indents = $indents;
	}

	private function read_html_escaping_interpolation()
	{
		$expr = $this->read_expression();
		if (!$expr) {
			throw $this->new_parse_error("Required an expression in {}.");
		}

		$this->expect_block_end();
		$expr = new XTagChildInterpolation($expr);
		$expr->pos = $this->pos;

		return $expr;
	}

	private function append_xtag_attribute(XTag $xtag, string &$text, ?BaseExpression $expr = null)
	{
		if ($text !== '') {
			$xtag->fixed_attributes[] = $text;
		}

		if ($expr) {
			$xtag->fixed_attributes[] = $expr;
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
