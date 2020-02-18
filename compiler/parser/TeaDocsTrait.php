<?php
/**
 * This file is part of the Tea programming language project
 *
 * @author 		Benny <benny@meetdreams.com>
 * @copyright 	(c)2019 YJ Technology Ltd. [http://tealang.org]
 * For the full copyright and license information, please view the LICENSE file that was distributed with this source code.
 */

namespace Tea;

trait TeaDocsTrait
{
	protected function read_docs()
	{
		$opened_pos = $this->pos;

		$left_spaces = $this->get_previous_inline($this->pos - 1);

		$token = $this->scan_token_ignore_space();
		if ($token === LF) {
			$this->skip_token(LF); // skip current line
		}
		else {
			$docs = $this->read_inline_docs();
			if ($docs !== null) {
				return $docs;
			}
		}

		return $this->read_multiline_docs($left_spaces, $opened_pos);
	}

	private function read_inline_docs()
	{
		$content = '';
		while (($token = $this->scan_string_component()) !== null) {
			if ($token === _DOCS_MARK) {
				// doc end
				$docs = new Docs([$content]);
				break;
			}
			elseif ($token === LF) {
				$docs = null;
				break; // ignore inline doc content
			}
			else {
				$content .= $token;
			}
		}

		return $docs;
	}

	private function read_multiline_docs(string $left_spaces, int $opened_pos)
	{
		// remove the indents
		$left_spaces_len = strlen($left_spaces);

		// split each lines to items
		$items = [];

		$tmp = '';
		while (($token = $this->scan_string_component()) !== null) {
			if ($token === _DOCS_MARK && $this->get_previous_inline($this->pos - 1) === $left_spaces) {
				if ($tmp !== _NOTHING) $items[] = $tmp;
				break;
			}

			if ($token === LF) {
				$items[] = $this->remove_prefix_spaces($tmp, $left_spaces, $left_spaces_len);
				$tmp = '';
			}
			elseif ($token === _AT && trim($tmp) === _NOTHING) {
				$items[] = $this->read_parameter_doc();
			}
			else {
				$tmp .= $token;
			}
		}

		// is not found the end mark of docs?
		if ($this->pos >= $this->tokens_count) {
			$line = $this->get_line_by_pos($opened_pos);
			throw $this->new_exception("The close mark of Docs which opened on line {$line} not found.");
		}

		$this->expect_statement_end();

		return new Docs($items);
	}

	private function remove_prefix_spaces(string $content, string $left_spaces, int $left_spaces_len)
	{
		if ($left_spaces_len === 0 || $content === _NOTHING) {
			// not to do anyting
		}
		elseif (substr($content, 0, $left_spaces_len) === $left_spaces) {
			$content = substr(rtrim($content), $left_spaces_len);
		}
		else {
			throw $this->new_exception("The indents should be same to the indents of Docs begin mark.");
		}

		return $content;
	}

	private function read_parameter_doc()
	{
		$name = $this->expect_identifier_token();
		$comment = $this->scan_to_token(LF);
		$this->scan_token(); // skip LF

		return new ParameterDoc($name, null, $comment);
	}
}
