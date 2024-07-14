<?php
/**
 * This file is part of the Tea programming language project
 *
 * @author 		Benny <benny@meetdreams.com>
 * @copyright 	(c)2019 YJ Technology Ltd. [http://tealang.org]
 * For the full copyright and license information, please view the LICENSE file that was distributed with this source code.
 */

namespace Tea;

const _XTAG_SELF_END = '/>';
const _XTAG_COMMENT_OPEN = '!--';
const _XTAG_COMMENT_CLOSE = '-->';
const _SELF_CLOSING_TAGS = [
	'meta', 'link', 'img', 'input', 'br', 'hr', '!doctype',
	'wbr', 'col', 'embed', 'param', 'source', 'track', 'area', 'keygen'
];

trait TeaXBlockTrait
{
	private $has_interpolation;

	protected function read_xblock()
	{
		$this->has_interpolation = false;

		$align_spaces = $this->get_heading_spaces_inline();

		$token = $this->read_tag_name();

		$top_is_virtual = $token === '';
		if (!$top_is_virtual and !TeaHelper::is_xtag_name($token)) {
			throw $this->new_unexpected_error();
		}

		$xtag = $this->read_xtag($token, $align_spaces);

		$skiped_spaces = '';
		while ($this->get_token_closely($skiped_spaces) === _XTAG_OPEN) {
			$this->scan_token_ignore_empty(); // _XTAG_OPEN
			throw $this->new_unexpected_error();
		}

		$xblock = new XBlock($xtag);
		$xblock->has_interpolation = $this->has_interpolation;
		$xblock->pos = $this->pos;

		return $xblock;
	}

	private function read_tag_name()
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

	private function read_xtag(string $tag, string $align_spaces)
	{
		if ($tag === _XTAG_COMMENT_OPEN) {
			return $this->read_xtag_comment($align_spaces);
		}

		$attributes = $this->read_xtag_attributes();

		$current_token = $this->get_current_token_string();

		// tag end
		if ($current_token === _XTAG_SELF_END) {
			$elem = new XBlockElement($tag, $attributes);
		}
		// no children
		elseif (in_array(strtolower($tag), _SELF_CLOSING_TAGS, true)) {
			$elem = new XBlockLeaf($tag, $attributes);
		}
		// expect tag head close
		elseif ($current_token === _XTAG_CLOSE) {
			$children = $this->read_xtag_children($tag, $align_spaces);
			if ($align_spaces) {
				$this->strip_leading_spaces_for_items($children, $align_spaces);
			}

			$elem = new XBlockElement($tag, $attributes, $children);
		}
		else {
			throw $this->new_unexpected_error();
		}

		return $elem;
	}

	private function read_xtag_comment(string $align_spaces)
	{
		$content = $this->scan_to_token(_XTAG_COMMENT_CLOSE);
		$this->scan_token(); // skip -->

		$align_spaces && $content = $this->strip_leading_spaces($content, $align_spaces);

		return new XBlockComment($content);
	}

	private function read_xtag_attributes()
	{
		$attributes = [];
		$text = '';
		$closed = false;
		while (($token = $this->scan_token()) !== null) {
			// the close tag
			if ($token === _XTAG_CLOSE || $token === _XTAG_SELF_END) {
				$closed = true;
				break;
			}

			if ($token === _SHARP && $this->skip_token(_BLOCK_BEGIN)) {
				$expr = $this->read_sharp_interpolation();
				$this->append_xtag_attribute($attributes, $text, $expr);
				continue;
			}
			elseif ($token === _DOLLAR) {
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

	private function read_xtag_children(string $tag, string $align_spaces)
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
					$next = $this->read_tag_name();
					if (TeaHelper::is_xtag_name($next)) {
						$xtag = $this->read_xtag($next, $align_spaces);
						$this->append_xtag_child($children, $text, $xtag);
					}
					elseif ($next === _SLASH) { // the </
						if ($this->read_tag_name() !== $tag) {
							// a wrong close tag
							throw $this->new_parse_error("Unexpected close tag '</$tag>'.");
						}

						$this->expect_token_ignore_empty(_XTAG_CLOSE); // the >

						// element end
						$closed = true;
						break 2;
					}
					elseif ($tag === '' and $next === _XTAG_SELF_END) {
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
						$expr = $this->read_sharp_interpolation();
						$this->append_xtag_child($children, $text, $expr, true);
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
						$this->append_xtag_child($children, $text, $expr, true);
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

	private function strip_leading_spaces_for_items(array &$items, string $align_spaces)
	{
		if ($align_spaces === '') {
			return;
		}

		$align_spaces_len = strlen($align_spaces);
		foreach ($items as $idx => $item) {
			if (is_string($item) and $item !== LF) {
				if (str_starts_with($item, $align_spaces)) {
					$items[$idx] = substr($item, $align_spaces_len);
				}
			}
		}
	}

	private function strip_leading_spaces(string $string, string $leading_spaces)
	{
		if ($leading_spaces && strpos($string, $leading_spaces) !== false) {
			return str_replace(LF . $leading_spaces, LF, $string);
		}

		return $string;
	}

	private function append_xtag_attribute(array &$attributes, string &$text, ?BaseExpression $expr = null, bool $is_interpolation = false)
	{
		if ($text !== '') {
			$attributes[] = $text;
		}

		if ($expr) {
			$attributes[] = $expr;
			if ($is_interpolation) {
				$this->has_interpolation = true;
			}
		}

		$text = ''; // reset
	}

	private function append_xtag_child(array &$children, string &$text, ?BaseExpression $expr = null, bool $is_interpolation = false)
	{
		if ($text !== '') {
			$children[] = $text;
		}

		if ($expr) {
			$children[] = $expr;
			if ($is_interpolation) {
				$this->has_interpolation = true;
			}
		}

		$text = ''; // reset
	}
}

// end
