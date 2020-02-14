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
	const IS_IN_HEADER = true;

	protected function read_root_statement($leading = null, Docs $docs = null)
	{
		$token = $this->scan_token_ignore_space();
		if ($token === NL) {
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
		elseif (TeaHelper::is_modifier($token)) {
			$node = $this->read_declaration_with_modifier($token);
		}
		else {
			throw $this->new_unexpect_exception();
		}

		$this->expect_statement_end();
		if ($node !== null) {
			$node->leading = $leading;
			$node->docs = $docs;
		}

		return $node;
	}

	protected function read_declaration_with_modifier(string $modifier)
	{
		$name = $this->scan_token_ignore_space();

		$next = $this->get_token_ignore_space();
		if ($next === _DOT) {
			// the name path feature, just for class
			// eg. Name0.Name1.KeyName

			$namepath = $this->read_dot_name_components($name);
			$name = array_pop($namepath);
			$next = $this->get_token_ignore_space();
		}
		elseif (TeaHelper::is_strict_less_function_name($name)) {
			// function
			$this->assert_not_reserveds_word($name);
			return $this->read_function_declaration($name, $modifier);
		}
		elseif (TeaHelper::is_constant_name($name) && ($type = $this->try_read_type_identifier()) ) {
			// constant
			$this->assert_not_reserveds_word($name);
			return $this->factory->create_constant_declaration($modifier, $name, $type, null);
		}

		// the alias feature
		// just for class?
		if ($next === _AS) {
			// eg. NS1.NS2.OriginName as DestinationName

			if (isset($namepath)) {
				$namepath[] = $name;
				$origin_name = $namepath;
			}
			else {
				$origin_name = $name;
			}

			$this->scan_token_ignore_space(); // skip _AS
			$name = $this->scan_token_ignore_space();

			$this->assert_not_reserveds_word($name);
		}
		elseif (isset($namepath)) {
			throw $this->new_exception("Required the 'as' keyword to alias to a new name without dots.");
		}

		if (!TeaHelper::is_classlike_name($name)) {
			throw $this->new_exception("Invalid class/interface name.");
		}

		// class or interface
		$declaration = $this->try_read_classlike_declaration($name, $modifier);
		if (!$declaration) {
			throw $this->new_unexpect_exception();
		}

		if (isset($origin_name)) {
			$declaration->origin_name = $origin_name;
		}

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

	protected function read_class_constant_declaration(string $name, ?string $modifier)
	{
		$type = $this->try_read_type_identifier();

		if ($this->skip_token_ignore_space(_ASSIGN)) {
			$value = $this->read_literal_expression();
		}
		elseif (!$type) {
			throw $this->new_exception('Expected type or value assign expression for define constant.');
		}
		else {
			$value = null;
		}

		return $this->factory->create_class_constant_declaration($modifier, $name, $type, $value);
	}

	protected function read_method_declaration(string $name, ?string $modifier, bool $static)
	{
		$parameters = $this->read_parameters_with_parentheses();
		$return_type = $this->try_read_return_type_identifier();
		$callbacks = $this->try_read_callback_protocols();

		$declaration = $this->factory->declare_method($modifier, $name, $return_type, $parameters, $callbacks);
		$declaration->is_static = $static;

		return $declaration;
	}
}
