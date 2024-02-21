<?php
/**
 * This file is part of the Tea programming language project
 *
 * @author 		Benny <benny@meetdreams.com>
 * @copyright 	(c)2019 YJ Technology Ltd. [http://tealang.org]
 * For the full copyright and license information, please view the LICENSE file that was distributed with this source code.
 */

namespace Tea;

class HeaderParser extends TeaParser
{
	public $is_parsing_header = true;

	protected function read_root_statement($leading = null, Docs $docs = null)
	{
		$token = $this->scan_token_ignore_space();
		if ($token === LF) {
			return $this->read_root_statement(true);
		}
		elseif ($token === _SEMICOLON || $token === null) {
			// just an empty statement, or at the end of program
			return null;
		}

		$this->trace_statement($token);

		if ($token === _SHARP) {
			$node = $this->read_label_statement();
		}
		elseif ($token === _DOCS_MARK) {
			$docs = $this->read_docs();
			return $this->read_root_statement($leading, $docs);
		}
		elseif ($token === _INLINE_COMMENT_MARK) {
			$this->skip_current_line();
			return $this->read_root_statement($leading, $docs);
		}
		elseif ($token === _RUNTIME) {
			$node = $this->read_runtime_declaration();
		}
		elseif (TeaHelper::is_modifier($token)) {
			$node = $this->read_header_declaration_with_modifier($token);
		}
		else {
			throw $this->new_unexpected_error();
		}

		$this->expect_statement_end();
		if ($node !== null) {
			$node->leading = $leading;
			$node->docs = $docs;
		}

		return $node;
	}

	protected function read_header_declaration_with_modifier(string $modifier)
	{
		$token = $this->scan_token_ignore_space();

		switch ($token) {
			case _CLASS:
				[$origin_name, $name] = $this->read_header_declaration_names();
				$declaration = $this->read_class_declaration($name, $modifier);
				break;
			case _ABSTRACT:
				[$origin_name, $name] = $this->read_header_declaration_names();
				$declaration = $this->read_class_declaration($name, $modifier);
				$declaration->is_abstract = true;
				break;
			case _INTERFACE:
				[$origin_name, $name] = $this->read_header_declaration_names();
				$declaration = $this->read_interface_declaration($name, $modifier);
				break;
			case _TRAIT:
				[$origin_name, $name] = $this->read_header_declaration_names();
				break;
			case _FUNC:
				[$origin_name, $name] = $this->read_header_declaration_names();
				$declaration = $this->read_function_declaration($name, $modifier, null, true);
				break;
			case _CONST:
				[$origin_name, $name] = $this->read_header_declaration_names();
				$declaration = $this->read_constant_declaration_without_value($name, $modifier);
				break;
			default:
				throw $this->new_parse_error("Unknow declaration type '$token'");
		}

		$declaration->origin_name = $origin_name;

		return $declaration;
	}

	protected function read_dot_name_components(string $first_name)
	{
		$components = [$first_name];
		while ($this->skip_token(_DOT)) {
			$components[] = $this->expect_identifier_token();
		}

		return $components;
	}

	protected function read_class_constant_declaration(string $name, ?string $modifier, bool $is_declare_mode = false)
	{
		$declaration = $this->factory->create_class_constant_declaration($modifier, $name);

		$declaration->type = $this->try_read_type_expression();

		if ($this->skip_token_ignore_space(_ASSIGN)) {
			$declaration->value = $this->read_compile_time_value();
		}
		elseif (!$declaration->type) {
			throw $this->new_parse_error('Expected type or value assign expression for define constant.');
		}

		return $declaration;
	}

	protected function read_method_declaration(string $name, ?string $modifier, bool $static, bool $is_declare_mode = false)
	{
		$declaration = $this->factory->create_method_declaration($modifier, $name);
		$declaration->pos = $this->pos;
		$declaration->is_static = $static;

		$parameters = $this->read_parameters_with_parentheses();
		$this->factory->set_scope_parameters($parameters);

		$declaration->type = $this->try_read_return_type_expression();

		return $declaration;
	}
}

// end
