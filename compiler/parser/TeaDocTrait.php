<?php
/**
 * This file is part of the Tea programming language project
 *
 * @author 		Benny <benny@meetdreams.com>
 * @copyright 	(c)2019 YJ Technology Ltd. [http://tealang.org]
 * For the full copyright and license information, please view the LICENSE file that was distributed with this source code.
 */

namespace Tea;

trait TeaDocTrait
{
	protected function read_doc_comment()
	{
		$opened_pos = $this->pos;

		$left_spaces = $this->get_previous_code_inline($this->pos - 1);

		// $token = $this->scan_token_ignore_space();
		// if ($token === LF) {
		// 	$this->skip_token(LF); // skip current line
		// }
		// else {
		// 	$doc = $this->read_inline_doc();
		// 	if ($doc !== null) {
		// 		return $doc;
		// 	}
		// }

		return $this->read_multiline_doc($left_spaces, $opened_pos);
	}

	// private function read_inline_doc()
	// {
	// 	$content = '';
	// 	while (($token = $this->scan_string_component()) !== null) {
	// 		if ($token === _DOC_MARK) {
	// 			// doc end
	// 			$doc = new DocComment([$content]);
	// 			break;
	// 		}
	// 		elseif ($token === LF) {
	// 			$doc = null;
	// 			break; // ignore inline doc content
	// 		}
	// 		else {
	// 			$content .= $token;
	// 		}
	// 	}

	// 	return $doc;
	// }

	private function read_multiline_doc(string $left_spaces, int $opened_pos)
	{
		// remove the indents
		$left_spaces_len = strlen($left_spaces);

		// split each lines to items
		// $items = [];

		$tmp = '';
		while (($token = $this->scan_string_component()) !== null) {
			if ($token === _DOC_MARK && $this->get_previous_code_inline($this->pos - 1) === $left_spaces) {
				// if ($tmp !== _NOTHING) $items[] = $tmp;
				break;
			}

			$tmp .= $token;

			// if ($token === LF) {
			// 	$items[] = $this->remove_prefix_spaces($tmp, $left_spaces, $left_spaces_len);
			// 	$tmp = '';
			// }
			// elseif ($token === _AT && trim($tmp) === _NOTHING) {
			// 	$items[] = $this->read_doc_parameter_item();
			// }
			// else {
			// 	$tmp .= $token;
			// }
		}

		// is not found the end mark of doc?
		if ($this->pos >= $this->tokens_count) {
			$line = $this->get_line_number($opened_pos);
			throw $this->new_parse_error("The close mark of Doc which opened in line {$line} not found.");
		}

		$this->expect_statement_end();

		return new DocComment($tmp);
	}

	// private function remove_prefix_spaces(string $content, string $left_spaces, int $left_spaces_len)
	// {
	// 	if ($left_spaces_len === 0 || $content === _NOTHING) {
	// 		// not to do anyting
	// 	}
	// 	elseif (substr($content, 0, $left_spaces_len) === $left_spaces) {
	// 		$content = substr(rtrim($content), $left_spaces_len);
	// 	}
	// 	else {
	// 		throw $this->new_parse_error("The indents should be same to the indents of Doc begin mark.");
	// 	}

	// 	return $content;
	// }

	// private function read_doc_parameter_item()
	// {
	// 	$name = $this->expect_identifier_token();
	// 	$comment = $this->scan_to_token(LF);
	// 	$this->scan_token(); // skip LF

	// 	return new DocParameterItem($name, null, $comment);
	// }
}

// end
