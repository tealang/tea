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
			case _TEA:
				$node = $this->read_tea_declaration();
				$node->label = $token;
				break;

			case _PHP:
				$node = $this->read_php_declaration();
				$node->label = $token;
				break;

			case _USE:
				return $this->read_use_statement();

			case _UNIT:
				$this->read_unit_declaration();
				return null;

			case _MAIN:
				$this->factory->set_as_main_program();
				return;

			default:
				$node = $this->read_custom_label_statement_with($token);
		}

		return $node;
	}

	protected function read_tea_declaration()
	{
		$name = $this->scan_token_ignore_space();

		// use to allow extends with a builtin type class
		$this->is_in_tea_declaration = true;

		$declaration = $this->factory->create_builtin_type_class_declaration($name);
		if ($declaration === null) {
			throw $this->new_parse_error("'$name' not a builtin type, cannot use the #tea label.");
		}

		$this->read_rest_for_classkindred_declaration($declaration);

		$this->is_in_tea_declaration = false;

		return $declaration;
	}

	protected function read_php_declaration()
	{
		$modifier = _PUBLIC;
		$token = $this->scan_token_ignore_space();
		$root_namespace = $this->factory->root_namespace;

		switch ($token) {
			case _CLASS:
				[$origin_name, $name] = $this->read_header_declaration_names();
				$declaration = $this->read_class_declaration($name, $modifier, $root_namespace, true);
				break;
			case _ABSTRACT:
				[$origin_name, $name] = $this->read_header_declaration_names();
				$declaration = $this->read_class_declaration($name, $modifier, $root_namespace, true);
				$declaration->is_abstract = true;
				break;
			case _INTERFACE:
				[$origin_name, $name] = $this->read_header_declaration_names();
				$declaration = $this->read_interface_declaration($name, $modifier, $root_namespace, true);
				break;
			case _TRAIT:
				[$origin_name, $name] = $this->read_header_declaration_names();
				break;
			case _FUNC:
				[$origin_name, $name] = $this->read_header_declaration_names();
				$declaration = $this->read_function_declaration($name, $modifier, $root_namespace, true);
				break;
			case _CONST:
				[$origin_name, $name] = $this->read_header_declaration_names();
				$declaration = $this->read_constant_declaration_without_value($name, $modifier, $root_namespace, true);
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

	private function read_custom_label_statement_with(string $label)
	{
		// normal statement
		$expression = $this->read_sharp_expression_with($label);
		if ($expression !== null) {
			$expression = $this->read_expression_combination($expression);
			$node = new NormalStatement($expression);
		}
		else {
			if (TeaHelper::is_reserveds($label)) {
				throw $this->new_parse_error("Cannot use a reserveds keyword '$label' as a label name.");
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

	protected function read_unit_declaration()
	{
		if (!$this->is_parsing_header) {
			throw $this->new_parse_error("The '#unit' label could not be used at here.");
		}

		$ns = $this->read_namespace_identifier();
		$this->factory->set_namespace($ns);

		if ($this->skip_token_ignore_space(_BRACE_OPEN)) {
			$this->read_unit_options();
			$this->expect_token_ignore_empty(_BRACE_CLOSE);
		}
	}

	private function read_unit_options()
	{
		// try read options for unit
		while ($key = $this->read_object_key()) {
			if (!in_array($key, _UNIT_OPTIONAL_KEYS, true)) {
				throw $this->new_parse_error("Invalid option key '$key' for unit definition.");
			}

			$this->expect_token_ignore_space(_COLON);

			$expr = $this->read_expression();
			if ($expr === null || !$expr instanceof ILiteral) {
				throw $this->new_unexpected_error();
			}

			// set to unit
			$this->factory->set_unit_option($key, $expr->value);

			if (!$this->skip_comma()) {
				break;
			}
		}
	}

	protected function read_namespace_identifier()
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

	private function read_use_statement()
	{
		if (!$this->is_parsing_header) {
			throw $this->new_parse_error("The '#use' statements can only be used in the __unit.th or __public.th files.");
		}

		$ns = $this->read_namespace_identifier();

		if ($this->skip_token_ignore_space(_BLOCK_BEGIN)) {
			$targets = $this->read_use_targets($ns);
			$this->expect_block_end();
		}
		else {
			// throw $this->new_parse_error("Expected the use targets {...}.");
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
					throw $this->new_parse_error("Invalid name format token '$alias' for the use statement.");
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
