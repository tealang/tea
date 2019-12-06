<?php
/**
 * This file is part of the Tea programming language project
 *
 * @author 		Benny <benny@meetdreams.com>
 * @copyright 	(c)2019 YJ Technology Ltd. [http://tealang.org]
 * For the full copyright and license information, please view the LICENSE file that was distributed with this source code.
 */

namespace Tea;

const _UNIT_OPTIONAL_KEYS = ['type', 'loader'];

trait TeaSharpTrait
{
	protected function read_label_statement()
	{
		$token = $this->scan_token();
		switch ($token) {
			case _TEA:
				$node = $this->read_tea_declaration();
				break;

			case _PHP:
				$node = $this->read_php_declaration();
				break;

			case _USE:
				return $this->read_use_statement();

			case _UNIT:
				return $this->read_unit_declaration();

			case _EXPECT:
				return $this->read_expect_declaration();

			case _MAIN:
				$this->factory->set_as_main_program();
				return;

			default:
				$node = $this->read_other_label_statement_with($token);
		}

		$node->label = $token;
		return $node;
	}

	protected $is_in_meta_class = false;
	protected function read_tea_declaration()
	{
		$name = $this->scan_token_ignore_space();

		// use to allow extends with meta type class
		$this->is_in_meta_class = true;

		$declaration = $this->factory->create_meta_class_declaration($name);
		if ($declaration === null) {
			throw $this->new_exception("'$name' not a metatype, cannot use the #tea label.");
		}

		$this->read_rest_for_classlike_declaration($declaration);

		$this->is_in_meta_class = false;

		return $declaration;
	}

	protected function read_php_declaration()
	{
		$name = $this->scan_token_ignore_space();

		$this->assert_not_reserveds_word($name);

		if (TeaHelper::is_constant_name($name)) {
			$type = $this->try_read_type_identifier();
			if ($type) {
				$declaration = $this->factory->create_constant_declaration(_PUBLIC, $name, $type, null);
				return $declaration;
			}
		}

		if (TeaHelper::is_classlike_name($name)) {
			// the alias feature
			if ($this->skip_token_ignore_space(_AS)) {
				$origin_name = $name;
				$name = $this->scan_token_ignore_space();
				if (!TeaHelper::is_classlike_name($name)) {
					throw $this->new_exception("Invalid class/interface name.");
				}
			}

			$this->is_declare_mode = true;
			$declaration = $this->read_class_declaration($name, _PUBLIC);
			$this->is_declare_mode = false;

			if (isset($origin_name)) {
				$declaration->origin_name = $origin_name;
			}
		}
		elseif (TeaHelper::is_strict_less_function_name($name)) {
			$declaration = $this->read_function_declaration($name, _PUBLIC);
		}
		elseif ($name === _DOLLAR) {
			$name = $this->expect_identifier_token();
			$type = $this->try_read_type_identifier();
			if (!$type) {
				throw $this->new_exception("Expected type for declared super variable '$name'.");
			}

			$declaration = $this->factory->create_super_variable_declaration($name, $type, null);
		}
		else {
			throw $this->new_unexpect_exception();
		}

		return $declaration;
	}

	protected function read_expect_declaration()
	{
		// #expect var0 Type0, var1 Type1, ...

		$items[] = $this->read_expect_parameter_declaration();

		while ($this->skip_comma()) {
			$items[] = $this->read_expect_parameter_declaration();
		}

		return $this->factory->create_program_expection(...$items);
	}

	protected function read_expect_parameter_declaration()
	{
		$name = $this->scan_token_ignore_space();

		$type = null;
		$value = null;
		$reassignable = null;

		if (!TeaHelper::is_declarable_variable_name($name) && $name !== _THIS) {
			throw $this->new_unexpect_exception();
		}

		$next = $this->get_token_ignore_empty();
		if (TeaHelper::is_type_name($next)) {
			$type = $this->try_read_type_identifier();
		}

		$parameter = new ParameterDeclaration($name, $type, $value, $reassignable);
		$parameter->pos = $this->pos;

		return $parameter;
	}

	private function read_other_label_statement_with(string $label)
	{
		// normal statement
		$expression = $this->read_sharp_expression_with($label);
		if ($expression !== null) {
			$expression = $this->read_expression_combination($expression);
			return new NormalStatement($expression);
		}

		// labeled block
		$next = $this->scan_token_ignore_empty();
		if ($next === _FOR) {
			$block = $this->read_for_block($label);
		}
		elseif ($next === _WHILE) {
			$block = $this->read_while_block($label);
		}
		// elseif ($next === _LOOP) {
		// 	$block = $this->read_loop_block($label);
		// }
		elseif ($next === _CASE) {
			$block = $this->read_case_block($label);
		}

		return $block;
	}

	private function read_unit_declaration()
	{
		if (!static::IS_IN_HEADER) {
			throw $this->new_exception("The '#unit' statements can only be used in the __unit.th or __public.th files.");
		}

		$this->read_unit_namespace();

		if (!$this->skip_token_ignore_space(_BRACE_OPEN)) {
			return;
		}

		// try read options for unit
		while ($key = $this->read_object_key()) {
			if (!in_array($key, _UNIT_OPTIONAL_KEYS, true)) {
				throw $this->new_exception("Invalid option key '$key' for unit definition.");
			}

			$this->expect_token_ignore_space(_COLON);

			$expr = $this->read_expression();
			if ($expr === null || !$expr instanceof ILiteral) {
				throw $this->new_unexpect_exception();
			}

			// set to unit
			$this->factory->set_unit_option($key, $expr->value);

			if (!$this->skip_comma()) {
				break;
			}
		}

		$this->expect_token_ignore_empty(_BRACE_CLOSE);
	}

	private function read_unit_namespace()
	{
		$namespace = $this->read_namespace_identifier();
		$this->factory->set_namespace($namespace);

		return null;
	}

	private function read_namespace_identifier()
	{
		$domain = $this->read_domain_name();

		$names = [];
		while ($this->skip_token(_SLASH)) {
			$token = $this->scan_token();
			if (!TeaHelper::is_subnamespace_name($token)) {
				throw $this->new_exception("Invalid subnamespace name.");
			}

			$names[] = $token;
		}

		if (empty($names) && !TeaHelper::is_subnamespace_name($domain)) {
			throw $this->new_exception("Invalid namespace name.");
		}

		array_unshift($names, $domain);
		if (count($names) > 3) {
			throw $this->new_exception("It's too many namespace levels, the max levels is 3.");
		}

		return $this->factory->create_namespace_identifier($names);
	}

	private function read_domain_name(): ?string
	{
		$token = $this->scan_token_ignore_space();
		if (!TeaHelper::is_domain_component($token)) {
			throw $this->new_unexpect_exception();
		}

		$components[] = $token;
		while ($this->skip_token(_DOT)) {
			$token = $this->scan_token();
			if (!TeaHelper::is_domain_component($token)) {
				throw $this->new_unexpect_exception();
			}

			$components[] = $token;
		}

		return join(_DOT, $components);
	}

	private function read_use_statement()
	{
		if (!static::IS_IN_HEADER) {
			throw $this->new_exception("The '#use' statements can only be used in the __unit.th or __public.th files.");
		}

		$ns = $this->read_namespace_identifier();

		if ($this->skip_token_ignore_space(_BLOCK_BEGIN)) {
			$targets = $this->read_use_targets($ns);
			$this->expect_block_end();

			$this->factory->create_use_statement($ns, $targets);
		}
		else {
			throw $this->new_exception("Expected the use targets {...}.");
		// 	$this->factory->create_use_statement($ns);
		}
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
					throw $this->new_exception("Invalid name format token '$alias' for the use statement.");
				}

				// $targets[$token] = $alias;
				$target = $this->factory->append_use_target($ns, $alias, $token);
			}
			else {
				// $targets[] = $token;
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

			case _INCLUDE:
				return $this->read_include_expression();

			case _SINGLE_QUOTE:
				$expression = $this->read_single_quoted_expression();
				return new HTMLEscapeExpression($expression);

			case _DOUBLE_QUOTE:
				$expression = $this->read_double_quoted_expression();
				return new HTMLEscapeExpression($expression);

			case _PAREN_OPEN:
				$expression = $this->read_expression();
				$this->expect_token_ignore_empty(_PAREN_CLOSE);
				return new HTMLEscapeExpression($expression);

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
				throw $this->new_unexpect_exception();
		}

		return $expression;
	}
}

// end
