<?php
/**
 * This file is part of the Tea programming language project
 *
 * @author 		Benny <benny@meetdreams.com>
 * @copyright 	(c)2019 YJ Technology Ltd. [http://tealang.org]
 * For the full copyright and license information, please view the LICENSE file that was distributed with this source code.
 */

namespace Tea;

const SPLIT_PATTERN = '/(\s|\{|\}|\(|\)|\[|\]|\'|\"|\,|\.=|\.|\$|\\\|\/\*|\*\/|\/\/|\/>|\/=|\/|\;|\+=|---|-->|->|-=|--|-|~|=>|<=|>=|>>=|<<=|>>|<<|<|>|===|\!==|\!=|==|\*=|%=|&=|\|=|\^=|\?\?=|=|@|#|&|:)/';

trait TeaTokenTrait
{
	public $pos = -1;

	protected $tokens = 0;
	protected $tokens_count = 0;
	protected $current_token;

	protected $current_line = 1;

	protected $line2pos = [];

	public $errors = [];

	protected function init_with_file()
	{
		if (strpos($this->file, 'FilterGroup.tea')) {
			$this->debug = true;
		}

		$code = file_get_contents($this->file);
		if ($code === false) {
			throw new \ErrorException("File '$this->file' to parse load failed.");
		}

		$this->init_with_code($code);
	}

	protected function init_with_code(string $code)
	{
		$this->pos = -1;
		$this->current_line = 1;
		$this->current_token = null;

		$this->split_tokens($code);
	}

	protected function split_tokens(string $code)
	{
		$items = preg_split(SPLIT_PATTERN, $code, null, PREG_SPLIT_DELIM_CAPTURE);

		$this->line2pos[] = $real_pos = 0;

		$tokens = [];
		foreach ($items as $item) {
			if ($item === _NOTHING) {
				continue;
			}

			if ($item === NL) {
				$this->line2pos[] = $real_pos;
			}

			$tokens[] = $item;
			$real_pos++;
		}

		$this->tokens = $tokens;
		$this->tokens_count = count($tokens);
	}

	protected function get_line_by_pos(int $pos)
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
		$this->scan_to_token(NL);
		$this->scan_token(); // skip NL
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
		if ($token === NL) {
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
		$token = $this->current_token = $this->tokens[$this->pos] ?? null;

		if ($token === _NOTHING) {
			return $this->scan_string_component();
		}

		// if ($token === NL) {
		// 	$this->current_line++;
		// }

		return $token;
	}

	private $is_in_comment_block = false;
	protected function scan_token()
	{
		$this->pos++;
		$token = $this->current_token = $this->tokens[$this->pos] ?? null;

		if ($token === _CR) {
			return $this->scan_token();
		}

		// if ($token === NL) {
		// 	$this->current_line++;
		// }

		if ($token === _COMMENTS_OPEN && !$this->is_in_comment_block) {
			$this->skip_comments();
			return $this->scan_token();
		}

		return $token;
	}

	protected function skip_inline_comment()
	{
		return $this->scan_to_token(NL);
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
			if ($token === NL) {
				return false;
			}

			if ($token === _BACK_SLASH) {
				$i += 2;
				continue;
			}

			$i++;
			if ($token === _SLASH) {
				$rest_inline = $this->get_to_token(NL, $i);
				return !preg_match('/^\s+[a-z0-9_\-\!\~\$\'\"]/i', $rest_inline);
			}
		}

		return false;
	}

	protected function expect_token(string $token)
	{
		$next = $this->scan_token();
		if ($next !== $token) {
			throw $this->new_exception("Unexpected token '$next', or missed token '$token'", 1);
		}

		return $next;
	}

	protected function expect_token_ignore_space(string $token)
	{
		$next = $this->scan_token_ignore_space();
		if ($next !== $token) {
			throw $this->new_exception("Unexpected token '$next', or missed token '$token'", 1);
		}

		return $next;
	}

	protected function expect_token_ignore_empty(string $token)
	{
		$next = $this->scan_token_ignore_empty();
		if ($next !== $token) {
			throw $this->new_exception("Unexpected token '$next', or missed token '$token'", 1);
		}

		return $next;
	}

	protected function expect_space(string $message = null)
	{
		$token = $this->scan_token();
		if (!TeaHelper::is_space_tab_nl($token)) {
			throw $this->new_exception($message ?? "Expect a space, but suplied '$token'", 1);
		}

		return $token;
	}

	protected function expect_identifier_token()
	{
		$token = $this->scan_token();
		if (TeaHelper::is_identifier_name($token)) {
			return $token;
		}

		throw $this->new_exception("Invalid identifier token '{$token}'", 1);
	}

	protected function expect_identifier_token_ignore_space()
	{
		$token = $this->scan_token_ignore_space();
		if (TeaHelper::is_identifier_name($token)) {
			return $token;
		}

		throw $this->new_exception("Invalid identifier token '{$token}'", 1);
	}

	protected function expect_block_begin()
	{
		$this->expect_token_ignore_empty(_BLOCK_BEGIN);

		// skip current empty line
		if ($this->get_token_ignore_space() === NL) {
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
		if ($token === NL) {
			$this->scan_token_ignore_space();
		}
		elseif ($token === _BLOCK_END || $token === null) {
			//
		}
		elseif ($token === _SEMICOLON) {
			$this->scan_token_ignore_space();
			if ($this->get_token_ignore_space() === NL) {
				$this->scan_token_ignore_space();
			}
		}
		elseif ($token === _INLINE_COMMENT_MARK) {
			$this->scan_token_ignore_space(); // skip the _INLINE_COMMENT_MARK
			$this->scan_to_token(NL); // ignore the inline comment
			$this->scan_token_ignore_space(); // skip the NL
		}
		elseif ($token === _COMMENTS_OPEN) {
			$this->scan_token_ignore_space(); // skip the _COMMENTS_OPEN
			$this->scan_to_token(_COMMENTS_CLOSE); // ignore the comments
			$this->scan_token(); // skip the _COMMENTS_CLOSE
			$this->scan_token_ignore_space(); // skip the NL
		}
		else {
			$this->scan_token_ignore_space();
			throw $this->new_exception("Unexpect token '$token' in statement");
		}
	}

	protected function trace_statement(string $token)
	{
		// echo sprintf("- %s\n", $token);
	}

	protected function get_previous_inline(int $pos = null)
	{
		if ($pos === null) $pos = $this->pos;

		$tmp = '';
		for ($pos; $pos >= 0; $pos--) {
			if ($this->tokens[$pos] === NL) {
				break;
			}

			$tmp = $this->tokens[$pos] . $tmp;
		}

		return $tmp;
	}

	protected function get_previous_inline_spaces(int $pos = null)
	{
		$string = $this->get_previous_inline($pos);

		if (preg_match('/^\s+/', $string, $matches)) {
			return $matches[0];
		}

		return '';
	}

// -----

	public function new_unexpect_exception(string $token = null, int $trace_start = 1)
	{
		if ($token === null) {
			$token = $this->current_token;
		}

		return $this->new_exception("Unexpect token '$token'", $trace_start);
	}

	public function new_ast_check_error(string $message, Node $node = null, int $trace_start = 0)
	{
		if ($node) {
			if ($node->pos) {
				return $this->new_exception($message, $trace_start + 1, $node->pos, 'checking');
			}

			$addition = get_class($node);
			if (isset($node->name)) {
				$addition .= " of '$node->name'";
			}

			$message .= "\nError near $addition.";
		}

		DEBUG && $message .= "\nTraces:\n" . $this->get_traces($trace_start);

		throw new \Exception('Syntax error: ' . $message);
	}

	public function new_exception(string $message, int $trace_start = 0, int $token_pos = null, string $kind = 'parsing')
	{
		if ($token_pos === null) {
			$token_pos = $this->pos;
		}

		$code = $this->get_previous_inline($token_pos);

		$line = $this->get_line_by_pos($token_pos);

		$message = "Syntax error: {$message}\nError on {$kind} {$this->file}:{$line}";
		$message .= "\n--->" . ltrim($code) . "<---\n";

		DEBUG && $message .= "\nTraces:\n" . $this->get_traces($trace_start);

		return new \Exception($message);
	}

	public function get_traces(int $trace_start = 0)
	{
		$traces = '';

		$trace_items = debug_backtrace();
		$len = count($trace_items) - 1;
		for ($i = $trace_start + 1; $i < $len; $i++) {
			$item = $trace_items[$i];

			$args = [];
			foreach ($item['args'] as $arg) {
				$args[] = json_encode($arg, JSON_UNESCAPED_UNICODE);
			}

			$traces .= sprintf("%s:%d \t%s(%s)\n",
				$item['file'],
				$item['line'],
				$item['function'],
				join(', ', $args)
			);
		}

		return $traces;
	}
}

