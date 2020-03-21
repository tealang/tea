<?php
/**
 * This file is part of the Tea programming language project
 *
 * @author 		Benny <benny@meetdreams.com>
 * @copyright 	(c)2019 YJ Technology Ltd. [http://tealang.org]
 * For the full copyright and license information, please view the LICENSE file that was distributed with this source code.
 */

namespace Tea;

const TEA_TOKENS_SPLIT_PATTERN = '/(\s|\{|\}|\(|\)|\[|\]|\'|\"|\,|\.=|\.|\$|\\\|\/\*|\*\/|\/\/|\/>|\/=|\/|\;|\+=|---|-->|->|-=|--|-|~|=>|<=|>=|>>=|<<=|>>|<<|<|>|===|\!==|\!=|\!|==|\*=|%=|&=|\|=|\^=|\?\?=|=|@|#|&|::|:)/';

trait TeaTokenTrait
{
	protected function tokenize(string $source)
	{
		$items = preg_split(TEA_TOKENS_SPLIT_PATTERN, $source, null, PREG_SPLIT_DELIM_CAPTURE);

		$this->line2pos[] = $real_pos = 0;

		$tokens = [];
		foreach ($items as $item) {
			if ($item === _NOTHING) {
				continue;
			}

			if ($item === LF) {
				$this->line2pos[] = $real_pos;
			}

			$tokens[] = $item;
			$real_pos++;
		}

		$this->tokens = $tokens;
		$this->tokens_count = count($tokens);
	}

	protected function get_current_token_string()
	{
		return $this->tokens[$this->pos] ?? null;
	}

	protected function get_line_number(int $pos): int
	{
		$idx = 0;
		$end = count($this->line2pos);

		while (true) {
			$idx = intval(($end - $idx) / 2) + $idx;
			$current = $this->line2pos[$idx];

			if ($current > $pos) {
				if ($idx === 0) {
					break;
				}

				$end = $idx;
				$idx = 0;
			}
			elseif ($current === $pos) {
				break;
			}
			elseif ($idx === $end - 1) {
				break;
			}
		}

		return $idx + 1;
	}

	protected function skip_current_line()
	{
		$this->scan_to_token(LF);
		$this->scan_token(); // skip LF
	}

	// the ':'
	protected function skip_colon()
	{
		if ($this->get_token_ignore_space() === _COLON) {
			$this->scan_token_ignore_space();
			return true;
		}

		return false;
	}

	// the ','
	protected function skip_comma()
	{
		if ($this->get_token_ignore_space() === _COMMA) {
			$this->scan_token_ignore_space();
			return true;
		}

		return false;
	}

	protected function skip_token_ignore_empty(string $token)
	{
		$next = $this->get_token_ignore_empty();
 		if ($next === $token) {
			$this->scan_token_ignore_empty();
			return true;
		}

		return false;
	}

	protected function skip_token_ignore_space(string $token)
	{
		$next = $this->get_token_ignore_space();
 		if ($next === $token) {
			$this->scan_token_ignore_space();
			return true;
		}

		return false;
	}

	protected function skip_token(string $token)
	{
		$next = $this->get_token();
 		if ($next === $token) {
			$this->scan_token();
			return true;
		}

		return false;
	}

	protected function get_token()
	{
		$token = $this->tokens[$this->pos + 1] ?? null;
		if ($token === _CR) {
			$this->pos++;
			return $this->get_token();
		}

		return $token;
	}

	protected function get_token_at(int $pos)
	{
		$token = $this->tokens[$pos] ?? null;
		if ($token === _CR) {
			return $this->get_token_at($pos + 1);
		}

		return $token;
	}

	/**
	 * get token inline or next line
	 */
	protected function get_token_closely(string &$skiped_spaces = '')
	{
		$pos = $this->pos;
		$token = $this->get_token_ignore_space($pos);
		if ($token === LF) {
			$token = $this->get_token_ignore_space($pos);
		}

		for ($i = $this->pos + 1; $i < $pos; $i++) {
			$skiped_spaces .= $this->tokens[$i];
		}

		return $token;
	}

	protected function get_token_ignore_space(int &$pos = null)
	{
		if ($pos === null) {
			$pos = $this->pos;
		}

		while (true) {
			$pos++;
			$token = $this->tokens[$pos] ?? null;
			if (!TeaHelper::is_space_tab($token) && $token !== _CR) {
				return $token;
			}
		}
	}

	protected function get_token_ignore_empty(int &$pos = null)
	{
		if ($pos === null) {
			$pos = $this->pos;
		}

		while (true) {
			$pos++;
			$token = $this->tokens[$pos] ?? null;
			if (!TeaHelper::is_space_tab_nl($token)) {
				return $token;
			}
		}
	}

	protected function get_to_token(string $to, int $from = null)
	{
		$i = $from ?? $this->pos + 1;

		$tmp = '';
		while ($i < $this->tokens_count) {
			$token = $this->tokens[$i];
			$i++;

			if ($token === $to) {
				break;
			}

			$tmp .= $token;
		}

		return $tmp;
	}

	protected function scan_string_component()
	{
		$this->pos++;
		$token = $this->tokens[$this->pos] ?? null;

		if ($token === _NOTHING) {
			return $this->scan_string_component();
		}

		return $token;
	}

	private $is_in_comment_block = false;
	protected function scan_token()
	{
		$this->pos++;
		$token = $this->tokens[$this->pos] ?? null;

		if ($token === _CR) {
			return $this->scan_token();
		}

		if ($token === _COMMENTS_OPEN && !$this->is_in_comment_block) {
			$this->skip_comments();
			return $this->scan_token();
		}

		return $token;
	}

	protected function skip_inline_comment()
	{
		return $this->scan_to_token(LF);
	}

	protected function skip_comments()
	{
		while (($token = $this->get_token()) !== null) {
			$this->pos++;
			if ($token === _COMMENTS_CLOSE) {
				break;
			}
		}
	}

	protected function scan_token_ignore_empty()
	{
		while (true) {
			$token = $this->scan_token();
			if (!TeaHelper::is_space_tab_nl($token)) {
				return $token;
			}
		}
	}

	protected function scan_token_ignore_space()
	{
		while (true) {
			$token = $this->scan_token();
			if (!TeaHelper::is_space_tab($token)) {
				return $token;
			}
		}
	}

	protected function scan_to_token(string $to)
	{
		$tmp = '';
		while (($token = $this->get_token()) !== null) {
			if ($token === $to) {
				break;
			}

			$this->scan_token();
			$tmp .= $token;
		}

		return $tmp;
	}

	protected function back(int $num = 1)
	{
		$this->pos -= $num;
	}

	// protected function is_next_token(string ...$tokens)
	// {
	// 	$next = $this->get_token();
	// 	return in_array($next, $tokens, true);
	// }

	protected function is_next_space()
	{
		return $this->get_token() === _SPACE;
	}

	protected function is_next_assign_operator()
	{
		return TeaHelper::is_assign_operator($this->get_token_ignore_empty());
	}

	protected function is_next_regular_expression()
	{
		// / ... /xxx
		// / ... / [not expression here]

		$i = $this->pos;
		while ($i < $this->tokens_count) {
			$token = $this->tokens[$i];
			if ($token === LF) {
				return false;
			}

			if ($token === _BACK_SLASH) {
				$i += 2;
				continue;
			}

			$i++;
			if ($token === _SLASH) {
				$rest_inline = $this->get_to_token(LF, $i);
				return !preg_match('/^\s+[a-z0-9_\-\!\~\$\'\"]/i', $rest_inline);
			}
		}

		return false;
	}

	protected function expect_token(string $token)
	{
		$next = $this->scan_token();
		if ($next !== $token) {
			throw $this->new_parse_error("Unexpected token '$next', or missed token '$token'", 1);
		}

		return $next;
	}

	protected function expect_token_ignore_space(string $token)
	{
		$next = $this->scan_token_ignore_space();
		if ($next !== $token) {
			throw $this->new_parse_error("Unexpected token '$next', or missed token '$token'", 1);
		}

		return $next;
	}

	protected function expect_token_ignore_empty(string $token)
	{
		$next = $this->scan_token_ignore_empty();
		if ($next !== $token) {
			throw $this->new_parse_error("Unexpected token '$next', or missed token '$token'", 1);
		}

		return $next;
	}

	protected function expect_space(string $message = null)
	{
		$token = $this->scan_token();
		if (!TeaHelper::is_space_tab_nl($token)) {
			throw $this->new_parse_error($message ?? "Expect a space, but suplied '$token'", 1);
		}

		return $token;
	}

	protected function expect_identifier_token()
	{
		$token = $this->scan_token();
		if (TeaHelper::is_identifier_name($token)) {
			return $token;
		}

		throw $this->new_parse_error("Invalid identifier token '{$token}'", 1);
	}

	protected function expect_identifier_token_ignore_space()
	{
		$token = $this->scan_token_ignore_space();
		if (TeaHelper::is_identifier_name($token)) {
			return $token;
		}

		throw $this->new_parse_error("Invalid identifier token '{$token}'", 1);
	}

	protected function expect_block_begin()
	{
		$this->expect_token_ignore_space(_BLOCK_BEGIN);

		// skip current empty line
		if ($this->get_token_ignore_space() === LF) {
			$this->scan_token_ignore_space();
		}
	}

	protected function expect_block_end()
	{
		$this->expect_token_ignore_empty(_BLOCK_END);
	}

	protected function expect_statement_end()
	{
		$token = $this->get_token_ignore_space();
		if ($token === LF) {
			$this->scan_token_ignore_space();
		}
		elseif ($token === _BLOCK_END || $token === null) {
			//
		}
		elseif ($token === _SEMICOLON) {
			$this->scan_token_ignore_space();
			if ($this->get_token_ignore_space() === LF) {
				$this->scan_token_ignore_space();
			}
		}
		elseif ($token === _INLINE_COMMENT_MARK) {
			$this->scan_token_ignore_space(); // skip the _INLINE_COMMENT_MARK
			$this->scan_to_token(LF); // ignore the inline comment
			$this->scan_token_ignore_space(); // skip the LF
		}
		elseif ($token === _COMMENTS_OPEN) {
			$this->scan_token_ignore_space(); // skip the _COMMENTS_OPEN
			$this->scan_to_token(_COMMENTS_CLOSE); // ignore the comments
			$this->scan_token(); // skip the _COMMENTS_CLOSE
			$this->scan_token_ignore_space(); // skip the LF
		}
		else {
			$this->scan_token_ignore_space();
			throw $this->new_parse_error("Unexpect token '$token' in statement");
		}
	}

	protected function trace_statement(string $token)
	{
		// echo sprintf("- %s\n", $token);
	}

	protected function get_previous_code_inline(int $pos = null): string
	{
		if ($pos === null) $pos = $this->pos;

		$tmp = '';
		for ($pos; $pos >= 0; $pos--) {
			if (!isset($this->tokens[$pos]) || $this->tokens[$pos] === LF) {
				break;
			}

			$tmp = $this->tokens[$pos] . $tmp;
		}

		return $tmp;
	}

	protected function get_heading_spaces_inline(int $pos = null)
	{
		$string = $this->get_previous_code_inline($pos);

		if (preg_match('/^\s+/', $string, $matches)) {
			return $matches[0];
		}

		return '';
	}
}

