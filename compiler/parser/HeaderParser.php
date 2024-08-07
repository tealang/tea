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

	protected function read_root_statement(bool $leading_br = false, Docs $docs = null)
	{
		$token = $this->scan_token_ignore_space();
		if ($token === LF) {
			return $this->read_root_statement($token);
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
			return $this->read_root_statement($leading_br, $docs);
		}
		elseif ($token === _INLINE_COMMENT_MARK) {
			$this->skip_current_line();
			return $this->read_root_statement($leading_br, $docs);
		}
		elseif ($token === _RUNTIME) {
			$node = $this->read_runtime_declaration();
		}
		elseif (TeaHelper::is_modifier($token)) {
			$node = $this->read_header_declaration_with_modifier($token);
		}
		elseif ($token === _USE) {
			$this->read_use_statement();
			return;
		}
		elseif ($token === _NAMESPACE) {
			$this->read_module_declaration();
			return;
		}
		else {
			throw $this->new_unexpected_error();
		}

		$this->expect_statement_end();
		if ($node !== null) {
			$node->leading_br = $leading_br;
			$node->docs = $docs;
		}

		return $node;
	}

	protected function read_header_declaration_with_modifier(string $modifier)
	{
		$token = $this->scan_token_ignore_space();
		$this->is_declare_mode = true;

		switch ($token) {
			case _CLASS:
				[$origin_name, $name] = $this->read_header_declaration_names();
				$declaration = $this->read_class_declaration_with($name, $modifier);
				break;
			case _ABSTRACT:
				[$origin_name, $name] = $this->read_header_declaration_names();
				$declaration = $this->read_class_declaration_with($name, $modifier);
				$declaration->is_abstract = true;
				break;
			case _INTERTRAIT:
				[$origin_name, $name] = $this->read_header_declaration_names();
				$declaration = $this->read_intertrait_declaration($name, $modifier);
				break;
			case _INTERFACE:
				[$origin_name, $name] = $this->read_header_declaration_names();
				$declaration = $this->read_interface_declaration($name, $modifier);
				break;
			case _TRAIT:
				[$origin_name, $name] = $this->read_header_declaration_names();
				$declaration = $this->read_trait_declaration($name, $modifier);
				break;
			case _FUNC:
				[$origin_name, $name] = $this->read_header_declaration_names();
				$declaration = $this->read_function_declaration_with($name, $modifier);
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

	protected function read_class_constant_declaration(string $name, ?string $modifier)
	{
		$declaration = $this->factory->create_class_constant_declaration($modifier, $name);

		$declaration->declared_type = $this->try_read_type_expression();

		if ($this->skip_token_ignore_space(_ASSIGN)) {
			$declaration->value = $this->read_compile_time_value();
		}
		elseif (!$declaration->declared_type) {
			throw $this->new_parse_error('Expected type or value assign expression for define constant.');
		}

		$this->factory->end_class_member();
		$this->expect_statement_end();

		return $declaration;
	}

	protected function read_method_declaration(string $name, ?string $modifier, bool $static)
	{
		$declaration = $this->factory->create_method_declaration($modifier, $name);
		$declaration->pos = $this->pos;
		$declaration->is_static = $static;

		$parameters = $this->read_parameters_with_parentheses();
		$this->factory->set_scope_parameters($parameters);

		$this->read_type_hints_for_declaration($declaration);

		$this->factory->end_class_member();
		$this->expect_statement_end();

		return $declaration;
	}

// ---

	protected function read_runtime_declaration()
	{
		$modifier = _PUBLIC;
		$token = $this->scan_token_ignore_space();
		$root_namespace = $this->factory->root_namespace;
		$this->is_declare_mode = true;

		switch ($token) {
			case _CLASS:
				[$origin_name, $name] = $this->read_header_declaration_names();
				$declaration = $this->read_class_declaration_with($name, $modifier, $root_namespace);
				break;
			case _ABSTRACT:
				[$origin_name, $name] = $this->read_header_declaration_names();
				$declaration = $this->read_class_declaration_with($name, $modifier, $root_namespace);
				$declaration->is_abstract = true;
				break;
			case _INTERFACE:
				[$origin_name, $name] = $this->read_header_declaration_names();
				$declaration = $this->read_interface_declaration($name, $modifier, $root_namespace);
				break;
			case _TRAIT:
				[$origin_name, $name] = $this->read_header_declaration_names();
				break;
			case _FUNC:
				[$origin_name, $name] = $this->read_header_declaration_names();
				$declaration = $this->read_function_declaration_with($name, $modifier, $root_namespace);
				break;
			case _CONST:
				[$origin_name, $name] = $this->read_header_declaration_names();
				$declaration = $this->read_constant_declaration_without_value($name, $modifier, $root_namespace);
				break;
			// case _VAR:
			//	$origin_name = null;
			// 	$name = $this->expect_identifier_token();
			// 	$declaration = $this->read_php_super_var_declaration($name);
			// 	break;
			default:
				throw $this->new_parse_error("Unknow declaration type '$token'");
		}

		$declaration->origin_name = $origin_name;
		$declaration->is_runtime = true;

		return $declaration;
	}

	protected function read_header_declaration_names()
	{
		$name = $this->expect_identifier_token_ignore_space();

		$origin_name = null;
		if ($this->get_token() === _DOT) {
			// the name path feature, just for class by now
			// e.g. Name0.Name1.KeyName

			$namepath = [$name];
			while ($this->skip_token(_DOT)) {
				$namepath[] = $this->expect_identifier_token();
			}

			if (!$this->expect_token_ignore_space(_AS)) {
				throw $this->new_parse_error("Required keyword 'as' to alias");
			}

			$origin_name = $namepath;
			$name = $this->expect_identifier_token_ignore_space();
		}
		elseif ($this->skip_token_ignore_space(_AS)) {
			$origin_name = $name;
			$name = $this->expect_identifier_token_ignore_space();
		}

		return [$origin_name, $name];
	}

	// private function read_php_super_var_declaration(string $name)
	// {
	// 	$type = $this->try_read_type_expression();
	// 	if (!$type) {
	// 		throw $this->new_parse_error("Expected type for declared super variable '$name'.");
	// 	}

	// 	$declaration = $this->factory->create_super_variable_declaration($name, $type, null);

	// 	return $declaration;
	// }

	private function read_module_declaration()
	{
		$ns = $this->read_namespace_identifier();
		$this->factory->set_namespace($ns);

		if ($this->skip_token_ignore_space(_BRACE_OPEN)) {
			$this->read_module_options();
			$this->expect_token_ignore_empty(_BRACE_CLOSE);
		}
	}

	private function read_module_options()
	{
		// try read options for unit
		while ($key = $this->read_object_key()) {
			if (!in_array($key, _UNIT_OPTIONAL_KEYS, true)) {
				throw $this->new_parse_error("Invalid option key '$key' for module definition.");
			}

			$this->expect_token_ignore_space(_COLON);

			$expr = $this->read_expression();
			if ($expr === null || !$expr->is_const_value) {
				throw $this->new_unexpected_error();
			}

			// set to unit
			$this->factory->set_unit_option($key, $expr->value);

			if (!$this->skip_comma()) {
				break;
			}
		}
	}

	private function read_namespace_identifier()
	{
		$domain = $this->read_domain_name();

		$names = [];
		while ($this->skip_token(static::NS_SEPARATOR)) {
			$token = $this->scan_token();
			if (!TeaHelper::is_subnamespace_name($token)) {
				throw $this->new_parse_error("Invalid subnamespace name.");
			}

			$names[] = $token;
		}

		if (empty($names) && !TeaHelper::is_subnamespace_name($domain)) {
			throw $this->new_parse_error("Invalid namespace name.");
		}

		array_unshift($names, $domain);
		if (count($names) > _NS_LEVELS_MAX) {
			throw $this->new_parse_error(sprintf("It's too many namespace levels, the max levels is %d.", _NS_LEVELS_MAX));
		}

		$ns = $this->factory->create_namespace_identifier($names, true);
		$ns->pos = $this->pos;

		return $ns;
	}

	private function read_domain_name(): ?string
	{
		$token = $this->scan_token_ignore_space();
		if (!TeaHelper::is_domain_component($token)) {
			throw $this->new_unexpected_error();
		}

		$components[] = $token;
		while ($this->skip_token(_DOT)) {
			$token = $this->scan_token();
			if (!TeaHelper::is_domain_component($token)) {
				throw $this->new_unexpected_error();
			}

			$components[] = $token;
		}

		return join(_DOT, $components);
	}

	protected function read_use_statement()
	{
		if (!$this->is_parsing_header) {
			throw $this->new_parse_error("The 'use' statements can only be used in header files");
		}

		$ns = $this->read_namespace_identifier();

		if ($this->skip_token_ignore_space(_BLOCK_BEGIN)) {
			$targets = $this->read_use_targets($ns);
			$this->expect_block_end();
		}
		else {
			$targets = [];
		}

		$this->factory->create_use_statement($ns, $targets);
	}

	private function read_use_targets(NamespaceIdentifier $ns): array
	{
		$targets = [];
		while ($token = $this->scan_token_ignore_empty()) {
			if (!TeaHelper::is_identifier_name($token)) {
				break;
			}

			if ($this->skip_token_ignore_space(_AS)) {
				$alias = $this->scan_token_ignore_empty();
				if (!TeaHelper::is_identifier_name($alias)) {
					throw $this->new_parse_error("Invalid name format token '$alias' for the use statement");
				}

				$target = $this->factory->append_use_target($ns, $alias, $token);
			}
			else {
				$target = $this->factory->append_use_target($ns, $token);
			}

			$target->pos = $this->pos;
			$targets[] = $target;

			if (!$this->skip_comma()) {
				break;
			}
		}

		return $targets;
	}
}

// end
