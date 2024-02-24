<?php
/**
 * This file is part of the Tea programming language project
 *
 * @author 		Benny <benny@meetdreams.com>
 * @copyright 	(c)2019 YJ Technology Ltd. [http://tealang.org]
 * For the full copyright and license information, please view the LICENSE file that was distributed with this source code.
 */

namespace Tea;

trait TeaSharpTrait
{
	protected $is_in_tea_declaration = false;

	protected function read_label_statement()
	{
		$token = $this->scan_token();
		switch ($token) {
			case _MAIN:
				$this->factory->set_as_main_program();
				return;

			default:
				// throw $this->new_parse_error("Unknow sharp labeled statement");
				$node = $this->read_custom_label_statement_with($token);
		}

		return $node;
	}

	private function read_custom_label_statement_with(string $label)
	{
		// normal statement
		$expression = $this->read_sharp_expression_with($label);
		if ($expression !== null) {
			$expression = $this->read_expression_combination($expression);
			$node = new NormalStatement($expression);
		}
		else {
			if (TeaHelper::is_reserved($label)) {
				throw $this->new_parse_error("Cannot use a reserved keyword '$label' as a label name.");
			}

			// labeled block
			$next = $this->scan_token_ignore_space();
			if ($next === _FOR) {
				$node = $this->read_for_block($label);
			}
			elseif ($next === _WHILE) {
				$node = $this->read_while_block($label);
			}
			// elseif ($next === _LOOP) {
			// 	$node = $this->read_loop_block($label);
			// }
			elseif ($next === _SWITCH) {
				$node = $this->read_switch_block($label);
			}
			else {
				throw $this->new_parse_error("Expected a inline statement after label #{$label}.");
			}
		}

		$node->label = $label;
		return $node;
	}

	protected function read_sharp_expression_with(string $token)
	{
		switch ($token) {
			case _DEFAULT:
				return ASTFactory::$default_value_marker;

			case _TEXT:
				return $this->read_string_literal();

			default:
				return null;
		}
	}

	private function read_string_literal()
	{
		$token = $this->scan_token_ignore_space();

		switch ($token) {
			case _SINGLE_QUOTE:
				$expression = $this->read_unescaped_string_literal();
				break;

			case _DOUBLE_QUOTE:
				$expression = $this->read_escaped_string_literal();
				break;

			default:
				throw $this->new_unexpected_error();
		}

		return $expression;
	}
}

// end
