<?php
/**
 * This file is part of the Tea programming language project
 * @copyright 	(c)2019 tealang.org
 * For the full copyright and license information, please view the LICENSE file that was distributed with this source code.
 */

namespace Tea;

const SUPPORT_PHP_VERSION = '8.1.10';

if (version_compare(PHP_VERSION, SUPPORT_PHP_VERSION, '<')) {
	trigger_error('The minimum supported PHP version for parser is "' . SUPPORT_PHP_VERSION . '".', E_USER_ERROR);
}

/**
 * A lite parser for PHP programs
 * uses to supported mixed programming in Tea projects
 */
class PHPParser extends BaseParser
{
	const NS_SEPARATOR = _BACK_SLASH;

	const BUILTIN_IDENTIFIER_MAP = [
		// 'this' => _THIS,
		'parent' => _SUPER,
		'self' => _THIS,  // Temporary implementation
		'static' => _THIS,
		'true' => _VAL_TRUE,
		'false' => _VAL_FALSE,
		_VAL_NULL => _VAL_NONE,
	];

	private const TYPE_MAP = [
		'void' => _VOID,
		'null' => _NONE,
		'mixed' => _ANY,
		'string' => _STRING,
		'int' => _INT,
		'float' => _FLOAT,
		'bool' => _BOOL,
		'false' => _BOOL,
		'array' => _GENERAL_ARRAY,
		'iterable' => _ITERABLE,
		'callable' => _CALLABLE,
		'object' => _OBJECT,
		'static' => _TYPE_SELF,
	];

	private const TYPING_TOKEN_TYPES = [
		T_STRING,
		T_ARRAY,
		T_CALLABLE,
		T_NAME_QUALIFIED,
		T_NAME_FULLY_QUALIFIED
	];

	private const METHOD_MAP = [
	];

	private const PREFIX_OPERATORS = [_EXCLAMATION, _NEGATION, _IDENTITY, _BITWISE_NOT];

	private const EXPR_STOPPING_SIGNS = [_PAREN_CLOSE, _BRACKET_CLOSE, _BLOCK_END, _COMMA, _SEMICOLON];

	private const NORMAL_IDENTIFIER_TOKEN_TYPES = [T_STRING, T_ARRAY, T_STATIC, T_USE, T_UNSET, T_PRINT, T_NAME_FULLY_QUALIFIED];

	private const SUPER_GLOBAL_NAMES = ['$_GET', '$_POST', '$_FILE', '$_COOKIE', '$_REQUEST', '$GLOBALS'];

	/**
	 * @var NamespaceIdentifier
	 */
	private $namespace;

	private $current_following_comment;

	public function read_program(): Program
	{
		$this->is_declare_mode = false;

		$max_pos = $this->tokens_count - 1;

		$this->program->is_native = true;

		while ($this->pos < $max_pos) {
			$item = $this->read_root_statement();
			if ($item instanceof IRootDeclaration) {
				$this->program->append_declaration($item);
			}
		}

		$this->factory->end_program();

		$this->program->initializer = null;

		return $this->program;
	}

	protected function tokenize(string $source)
	{
		$this->tokens = token_get_all($source);
		$this->tokens_count = count($this->tokens);
	}

	private function read_root_statement(array $attributes = [])
	{
		$token = $this->scan_token_ignore_empty();
		if ($token === null) {
			return null;
		}

		$token_type = is_string($token) ? $token : $token[0];

		switch ($token_type) {
			case T_NAMESPACE:
				$node = $this->read_namespace_statement();
				break;
			case T_USE:
				$node = $this->read_use_namespace_statement();
				break;
			case T_CONST:
				$node = $this->read_const_statement($attributes);
				break;
			case T_ATTRIBUTE:
				$attributes = $this->read_meta_attributes($attributes);
				$node = $this->read_root_statement($attributes);
				break;
			case T_OPEN_TAG:
			case T_CLOSE_TAG:
				$node = null;
				break;
			default:
				$node = $this->read_normal_statement_with_token($token, $attributes);
				break;
		}

		return $node;
	}

	protected function read_inner_statement(array $attributes = [])
	{
		$token = $this->scan_token_ignore_empty();
		if ($token === null) {
			return null;
		}

		$token_type = is_string($token) ? $token : $token[0];
		switch ($token_type) {
			case _BLOCK_END:
				$this->back();
				$node = null;
				break;
			case T_ATTRIBUTE:
				$attributes = $this->read_meta_attributes($attributes);
				$node = $this->read_inner_statement($attributes);
				break;
			default:
				$node = $this->read_normal_statement_with_token($token, $attributes);
				break;
		}

		return $node;
	}

	protected function read_meta_attributes(array $items)
	{
		$group = empty($items) ? 0 : end($items)->group;
		do {
			$identifier = $this->read_classkindred_identifier();
			$args = $this->scan_instancing_arguments();
			$meta_attribute = new MetaAttribute($identifier, $args, $group);
			$meta_attribute->pos = $this->pos;
			$items[] = $meta_attribute;
		}
		while ($this->skip_comma());

		$this->expect_char_token(_BRACKET_CLOSE);

		return $items;
	}

	private function read_normal_statement_with_token(array|string $token, array $attributes): IStatement
	{
		$token_type = is_string($token) ? $token : $token[0];
		switch ($token_type) {
			case T_COMMENT:
				$content = $token[1];
				$node = str_starts_with($content, '/*')
					? $this->create_block_comment($content)
					: $this->create_line_comment($content);
				break;
			case T_DOC_COMMENT:
				$node = $this->create_doc_comment($token[1]);
				break;
			case T_FUNCTION:
				$node = $this->read_function_declaration($attributes);
				break;
			case T_CLASS:
				$node = $this->read_class_declaration($attributes);
				break;
			case T_ABSTRACT:
				$this->expect_typed_token(T_CLASS);
				$node = $this->read_class_declaration($attributes);
				$node->is_abstract = true;
				break;
			case T_INTERFACE:
				$node = $this->read_interface_declaration($attributes);
				break;
			case T_TRAIT:
				$node = $this->read_trait_declaration($attributes);
				break;
			case T_ECHO:
				$node = $this->read_echo_statement();
				break;
			case T_EXIT:
				$node = $this->read_exit_statement();
				break;
			case T_RETURN:
				$node = $this->read_return_statement();
				break;
			case T_BREAK:
				$node = $this->read_break_statement();
				break;
			case T_CONTINUE:
				$node = $this->read_continue_statement();
				break;
			case T_THROW:
				$node = $this->read_throw_statement();
				break;
			case T_IF:
				$node = $this->read_if_block();
				break;
			case T_SWITCH:
				$node = $this->read_switch_block();
				break;
			case T_MATCH:
				$node = $this->read_match_block();
				break;
			case T_FOR:
				$node = $this->read_for_block();
				break;
			case T_FOREACH:
				$node = $this->read_foreach_block();
				break;
			case T_WHILE:
				$node = $this->read_while_block();
				break;
			case T_DO:
				$node = $this->read_do_while_block();
				break;
			case T_TRY:
				$node = $this->read_try_block();
				break;
			case T_GLOBAL:
				$node = $this->read_global_statement();
				break;
			case _SEMICOLON:
				$node = $this->create_normal_statement();
				break;
			case T_STATIC:
				if ($this->get_typed_token_ignore_empty(T_VARIABLE)) {
					$node = $this->read_static_statement();
					break;
				}

				// otherwise fallthrough to default
			default:
				$node = $this->read_expression_statement_with($token);
				break;
		}

		return $node;
	}

	private function read_expression_statement_with(string|array $token): NormalStatement
	{
		$expr = $this->read_expression_with_token($token);

		$this->expect_statement_end();
		$node = $this->create_normal_statement($expr);

		return $node;
	}

	private function read_namespace_statement()
	{
		if ($this->namespace !== null) {
			throw $this->new_parse_error("Cannot redeclare a new namespace");
		}

		$token = $this->scan_token();
		$names = $this->read_qualified_name_with($token);

		$ns = $this->create_namespace_identifier($names);
		$this->namespace = $ns;
		$this->program->ns = $ns;

		$statement = new NamespaceStatement($ns);

		$this->expect_statement_end();

		return $statement;
	}

	private function create_namespace_identifier(array $names)
	{
		$identifier = $this->factory->create_namespace_identifier($names);
		$identifier->pos = $this->pos;
		return $identifier;
	}

	private function read_use_namespace_statement()
	{
		// e.g. use NS\Target;
		// e.g. use NS\Target as AliasName1;
		// e.g. use NS1\NS2\{Target1, Target2 as AliasName2};

		$token = $this->scan_token_ignore_empty();

		// if has the \ separator, skip it
		if ($token[0] === T_NS_SEPARATOR) {
			$token = $this->scan_token_ignore_empty();
		}

		if ($token[0] === T_NAME_QUALIFIED || $token[0] === T_NAME_FULLY_QUALIFIED) {
			$names = explode(_BACK_SLASH, $token[1]);
		}
		else {
			$names[] = $token[1];
		}

		$alias_name = null;
		$targets = null;

		// scan the namespace components
		while ($token = $this->scan_token_ignore_empty()) {
			if ($token === _SEMICOLON) {
				$this->back();
				break;
			}
			elseif ($token[0] === T_NS_SEPARATOR) {
				$next = $this->scan_token_ignore_empty();
				if ($next === _BLOCK_BEGIN) {
					// the multi targets mode
					$ns = $this->create_namespace_identifier($names);
					$targets = $this->read_use_targets($ns);
					$this->expect_block_end();
					break;
				}
				else {
					$names[] = $this->get_identifier_name($next);
				}
			}
			elseif ($token[0] === T_AS) {
				$alias_name = $this->expect_identifier_name();
				break;
			}
			else {
				throw $this->new_unexpected_error();
			}
		}

		// the single target mode
		if ($targets === null) {
			$name = array_pop($names);
			$ns = $this->create_namespace_identifier($names);

			if ($alias_name) {
				$target = $this->factory->append_use_target($ns, $alias_name, $name);
			}
			else {
				$target = $this->factory->append_use_target($ns, $name);
			}

			$targets = [$target];
		}

		$statement = $this->create_use_statement_when_not_exists($ns, $targets);

		$this->expect_statement_end();

		return $statement;
	}

	private function read_use_targets(NamespaceIdentifier $ns): array
	{
		$targets = [];
		while ($token = $this->scan_token_ignore_empty()) {
			$name = $token[0];
			$next = $this->get_token_ignore_empty();

			if ($next[0] === T_AS) {
				$alias = $this->expect_identifier_name($next);
				$target = $this->factory->append_use_target($ns, $alias, $name);
			}
			else {
				$target = $this->factory->append_use_target($ns, $name);
			}

			$target->pos = $this->pos;
			$targets[] = $target;

			if (!$this->skip_char_token(_COMMA)) {
				break;
			}
		}

		return $targets;
	}

// ---

	private function read_global_statement()
	{
		// global var0, var1, ...

		$members = [];
		do {
			$members[] = $this->read_variable_declaration(true);
		}
		while ($this->skip_comma());

		$this->expect_statement_end();

		return new VarStatement($members);
	}

	private function read_static_statement()
	{
		// static var0, var1, ...

		$members = [];
		do {
			$members[] = $this->read_variable_declaration();
		}
		while ($this->skip_comma());

		$this->expect_statement_end();

		return new VarStatement($members);
	}

	private function read_variable_declaration(bool $without_value = false)
	{
		// var abc
		// var abc = expression

		$name = $this->expect_variable_name();

		$value = null;
		if (!$without_value) {
			if ($this->skip_char_token(_ASSIGN)) {
				$value = $this->read_expression();
			}
		}

		$decl = $this->factory->create_variable_declaration($name, null, $value);
		$decl->pos = $this->pos;

		return $decl;
	}

	private function read_continue_statement()
	{
		// continue
		// continue target_layers

		$statement = $this->factory->create_continue_statement();

		$token = $this->scan_typed_token_ignore_empty(T_LNUMBER);
		if ($token !== null) {
			$statement->target_layers = $token[1];
		}

		$this->expect_statement_end();
		$statement->pos = $this->pos;

		return $statement;
	}

	private function read_break_statement()
	{
		// break
		// break target_layers

		$statement = $this->factory->create_break_statement();

		$token = $this->scan_typed_token_ignore_empty(T_LNUMBER);
		if ($token !== null) {
			$statement->target_layers = $token[1];
		}

		$this->expect_statement_end();
		$statement->pos = $this->pos;

		return $statement;
	}

	private function read_exit_statement()
	{
		// exit
		// exit($argument)

		$argument = null;
		if ($this->skip_char_token(_PAREN_OPEN)) {
			$argument = $this->read_expression();
			$this->expect_paren_close();
		}

		$statement = $this->factory->create_exit_statement($argument);

		$this->expect_statement_end();
		$statement->pos = $this->pos;

		return $statement;
	}

	private function read_return_statement()
	{
		// return
		// return expression

		$argument = $this->scan_expression();
		$statement = $this->factory->create_return_statement($argument);

		$this->expect_statement_end();
		$statement->pos = $this->pos;

		return $statement;
	}

	private function read_throw_statement()
	{
		// throw Exception()

		$argument = $this->read_expression();
		$statement = $this->factory->create_throw_statement($argument);

		$this->expect_statement_end();
		$statement->pos = $this->pos;

		return $statement;
	}

	protected function read_echo_statement()
	{
		// echo
		// echo argument0, argument1, ...

		$args = $this->read_arguments();

		$this->expect_statement_end();

		$statement = new EchoStatement($args);
		$statement->pos = $this->pos;

		return $statement;
	}

	private function read_if_block()
	{
		// if test_expression { ... } [elseif test_expression {...}] [else { ... }] [catch e Exception {}] finally {}

		$this->expect_paren_open();
		$test = $this->read_expression();
		$this->expect_paren_close();

		$block = $this->factory->create_if_block($test);
		$block->pos = $this->pos;

		$this->read_body_for_control_block($block);

		$this->scan_else_block_for($block);

		$this->factory->end_branches($block);

		return $block;
	}

	private function expect_paren_open()
	{
		$this->expect_char_token(_PAREN_OPEN);
	}

	private function expect_paren_close()
	{
		$this->skip_comments();
		$this->expect_char_token(_PAREN_CLOSE);
	}

	private function scan_else_block_for(IElseAble $main_block)
	{
		$this->skip_comments();

		$token = $this->get_token_ignore_empty();
		$token_type = $token[0];
		if ($token_type === T_ELSE) {
			$this->scan_token_ignore_empty();
			$sub_block = $this->factory->create_else_block();
			$this->read_body_for_control_block($sub_block);

			$main_block->set_else_block($sub_block);

			// else block would be the end
		}
		elseif ($token_type === T_ELSEIF) {
			$this->scan_token_ignore_empty();
			$this->expect_paren_open();
			$test = $this->read_expression();
			$this->expect_paren_close();

			$sub_block = $this->factory->create_elseif_block($test);
			$this->read_body_for_control_block($sub_block);

			$main_block->set_else_block($sub_block);

			// another else block
			$this->scan_else_block_for($sub_block);
		}
		else {
			$this->back_skiped_comments();
		}
	}

	private function skip_comments()
	{
		$this->pos_before_skiped_comments = $this->pos;

		do {
			$token = $this->get_token_ignore_empty();
			$type = is_string($token) ? 0 : $token[0];
			if ($type !== T_COMMENT && $type !== T_DOC_COMMENT) {
				break;
			}

			$this->scan_token_ignore_empty();
		}
		while (true);
	}

	private function read_try_block()
	{
		$block = $this->factory->create_try_block();
		$block->pos = $this->pos;

		$this->read_body_for_control_block($block);

		$this->read_catching_block_for($block);

		$this->factory->end_branches($block);

		return $block;
	}

	private function read_catching_block_for(IExceptAble $main_block)
	{
		$this->skip_comments();

		$token = $this->get_token_ignore_empty();
		$token_type = $token[0];

		if ($token_type === T_CATCH) {
			$this->scan_token_ignore_empty();
			$this->expect_paren_open();

			$type = $this->read_classkindred_identifier();
			$var_name = $this->expect_variable_name();

			$this->expect_paren_close();

			$sub_block = $this->factory->create_catch_block($var_name, $type);
			$this->read_body_for_control_block($sub_block);
			$main_block->add_catching_block($sub_block);

			if ($type->name === _BASE_EXCEPTION && $type->ns?->is_global_space()) {
				$main_block->catching_all = $sub_block;
			}

			// another except block
			$this->read_catching_block_for($main_block);
		}
		elseif ($token_type === T_FINALLY) {
			$this->scan_token_ignore_empty();

			$sub_block = $this->factory->create_finally_block();
			$this->read_body_for_control_block($sub_block);
			$main_block->set_finally_block($sub_block);

			// finally block would be the end
		}
		else {
			$this->back_skiped_comments();
		}
	}

	private function read_switch_block(string $label = null)
	{
		$this->expect_paren_open();
		$argument = $this->read_expression();
		$this->expect_paren_close();

		$block = $this->factory->create_switch_block($argument);
		$block->label = $label;
		$block->pos = $this->pos;

		$branches = $this->read_case_branches();
		$block->set_branches($branches);

		return $block;
	}

	private function read_case_branches()
	{
		$this->expect_block_begin();

		$branches = [];
		$case_type = $this->skip_case();

		while ($case_type) {
			$arguments = $this->read_case_arguments($case_type);
			$case_branch = $this->factory->create_case_branch_block($arguments);

			$stmts = [];
			while ($item = $this->read_inner_statement()) {
				$stmts[] = $item;
				$this->skip_comments();
				$case_type = $this->skip_case();
				if ($case_type) {
					break;
				}
			}

			$case_branch->set_body_with_statements($stmts);
			$branches[] = $case_branch;
		}

		$this->expect_block_end();

		return $branches;
	}

	private function skip_case()
	{
		$next = $this->get_token_ignore_empty();
		$type = is_string($next) ? 0 : $next[0];

		$skiped = $type === T_CASE
			? T_CASE
			: ($type === T_DEFAULT ? T_DEFAULT : 0);

		if ($skiped) {
			$this->scan_token_ignore_empty();
		}

		return $skiped;
	}

	private function read_case_arguments(int $case_type)
	{
		$items = [];
		do {
			$items[] = $case_type === T_CASE
				? $this->read_expression()
				: null;

			$this->expect_char_token(_COLON);
			$case_type = $this->skip_case();
		}
		while ($case_type);

		return $items;
	}

	private function read_foreach_block(string $label = null)
	{
		$this->expect_paren_open();
		$iterable = $this->read_expression();

		$this->expect_typed_token_ignore_empty(_AS);
		$val = $this->read_expression();

		$key = null;
		if ($this->skip_typed_token(T_DOUBLE_ARROW)) {
			$key = $val;
			$val = $this->read_expression();
		}

		$this->expect_paren_close();

		if ($val instanceof ArrayExpression) {
			$val = $this->factory->create_destructuring($val->items);
		}

		$block = $this->factory->create_foreach_block($iterable, $key, $val);
		$block->label = $label;
		$block->pos = $this->pos;

		$this->read_body_for_control_block($block);

		return $block;
	}

	private function create_variable_identifier_with_token(array $token)
	{
		$name = $this->get_var_name($token);
		$identifier = $this->factory->create_variable_identifier($name);
		$identifier->pos = $this->pos;
		return $identifier;
	}

	private function read_for_block(string $label = null)
	{
		$this->expect_paren_open();
		$args1 = $this->scan_arguments();

		$this->expect_char_token(_SEMICOLON);
		$args2 = $this->scan_arguments();

		$this->expect_char_token(_SEMICOLON);
		$args3 = $this->scan_arguments();

		$this->expect_paren_close();

		$block = $this->factory->create_for_block($args1, $args2, $args3);
		$block->label = $label;
		$block->pos = $this->pos;

		$this->read_body_for_control_block($block);

		return $block;
	}

	private function read_while_block(string $label = null)
	{
		$this->expect_paren_open();
		$test = $this->read_expression();
		$this->expect_paren_close();

		$block = $this->factory->create_while_block($test);
		$block->label = $label;
		$block->pos = $this->pos;

		$this->read_body_for_control_block($block);

		return $block;
	}

	private function read_do_while_block(string $label = null)
	{
		// e.g. while test_expression {}

		$block = $this->factory->create_do_while_block();
		$block->label = $label;
		$block->pos = $this->pos;

		$this->read_body_for_control_block($block);

		$this->expect_typed_token_ignore_empty(T_WHILE);

		$this->expect_paren_open();
		$block->condition = $this->read_expression();
		$this->expect_paren_close();

		return $block;
	}

// ---

	protected function read_expression(Operator $prev_op = null): BaseExpression
	{
		$this->skip_comments();
		$token = $this->scan_expr_token_ignore_empty();
		if ($token === null) {
			throw $this->new_unexpected_error();
		}

		$expr = $this->read_expression_with_token($token, $prev_op);
		return $expr;
	}

	protected function scan_expression(Operator $prev_op = null): ?BaseExpression
	{
		$this->skip_comments();
		$token = $this->scan_expr_token_ignore_empty();
		$expr = $token
			? $this->read_expression_with_token($token, $prev_op)
			: null;
		return $expr;
	}

	private function read_expr_token_ignore_empty()
	{
		$token = $this->scan_expr_token_ignore_empty();
		if ($token === null) {
			throw $this->new_unexpected_error();
		}

		return $token;
	}

	private function scan_expr_token_ignore_empty()
	{
		$token = $this->get_token_ignore_empty();
		if ($token === null
			|| $token[0] === T_DOUBLE_ARROW
			|| is_string($token) && in_array($token, static::EXPR_STOPPING_SIGNS, true)
		) {
			return null;
		}

		$this->scan_token_ignore_empty();
		return $token;
	}

	private function read_expression_with_token(string|array $token, Operator $prev_op = null): BaseExpression
	{
		// the typed token
		if (is_string($token)) {
			$token_type = $token;
			$token_content = $token;
		}
		else {
			$token_type = $token[0];
			$token_content = $token[1];
		}

		switch ($token_type) {
			case T_NUM_STRING:
			case T_LNUMBER:
				$expr = new LiteralInteger($token_content);
				$expr->pos = $this->pos;
				break;
			case T_DNUMBER:
				$expr = new LiteralFloat($token_content);
				$expr->pos = $this->pos;
				break;
			case _PAREN_OPEN:
				$expr = $this->read_expression();
				$expr = new Parentheses($expr);
				$this->expect_paren_close();
				$expr->pos = $this->pos;
				break;
			case T_ARRAY:
			case _BRACKET_OPEN:
				$expr = $this->read_bracket_expression_with_token($token);
				break;
			case T_NEW:
				$expr = $this->read_instancing_expression();
				break;
			case T_FN:
				$expr = $this->read_lambda_expression();
				break;
			case T_FUNCTION:
				$expr = $this->read_anonymous_function_expression();
				break;
			case T_STRING_CAST:
			case T_INT_CAST:
			case T_DOUBLE_CAST:
			case T_BOOL_CAST:
			case T_ARRAY_CAST:
			case T_OBJECT_CAST:
			case T_UNSET_CAST:
				$expr = $this->read_as_operation_with_token($token);
				break;
			case T_LIST:
				$expr = $this->read_list_destructuring_assignment($token);
				break;
			case T_START_HEREDOC:
				$expr = $this->read_interpolated_string(T_END_HEREDOC);
				break;
			case T_INCLUDE_ONCE:
			case T_INCLUDE:
			case T_REQUIRE_ONCE:
			case T_REQUIRE:
				// include expression do not supported any combination
				return $this->read_include_expression_with_token($token);
			default:
				$expr = $this->scan_identifiable_expression_with_token($token);
				if ($expr === null) {
					// check is prefix operator
					$operator = OperatorFactory::get_php_prefix_operator($token_content);
					if ($operator === null) {
						throw $this->new_unexpected_error();
					}

					$expr = $this->read_prefix_operation($operator);
				}
				break;
		}

		$expr = $this->read_expression_combination($expr, $prev_op);
		return $expr;
	}

	private function read_as_operation_with_token(array $token)
	{
		$type = TypeFactory::get_for_casting_token_id($token[0]);
		$expr = $this->read_expression(OperatorFactory::$as);
		$expr = new AsOperation($expr, $type);
		$expr->pos = $this->pos;
		return $expr;
	}

	private function read_list_destructuring_assignment(array $token)
	{
		$this->expect_paren_open();

		$items = $this->read_arguments();
		$left = $this->factory->create_destructuring($items);
		$left->pos = $this->pos;

		$this->expect_paren_close();
		$this->expect_char_token(_ASSIGN);

		$right = $this->read_expression();
		$expr = $this->factory->create_assignment($left, $right, OperatorFactory::$assignment);
		$expr->pos = $this->pos;

		return $expr;
	}

	protected function read_instancing_expression()
	{
		$token = $this->read_expr_token_ignore_empty();
		$identifiable = $this->scan_identifiable_expression_with_token($token, true);
		if ($identifiable === null) {
			throw $this->new_unexpected_error();
		}

		$args = $this->scan_instancing_arguments();
		$expr = new InstancingExpression($identifiable, $args);
		$expr->pos = $this->pos;
		return $expr;
	}

	protected function scan_instancing_arguments()
	{
		if ($this->skip_char_token(_PAREN_OPEN)) {
			$args = $this->scan_arguments();
			$this->expect_paren_close();
		}
		else {
			$args = [];
		}

		return $args;
	}

	protected function scan_arguments()
	{
		$items = [];

		$item = $this->scan_expression();
		if ($item) {
			$items[] = $item;
			while ($this->skip_comma() and $item = $this->scan_expression()) {
				$items[] = $item;
			}
		}

		return $items;
	}

	protected function read_arguments()
	{
		$args = $this->scan_arguments();
		if (!$args) {
			throw $this->new_unexpected_error();
		}

		return $args;
	}

	protected function read_include_expression_with_token(array $token)
	{
		$expr = $this->read_expression();
		$expr = new IncludeExpression($expr, $token[0]);
		$expr->pos = $this->pos;
		return $expr;
	}

	protected function scan_identifiable_expression_with_token(string|array $token, bool $is_instancing = false): ?BaseExpression
	{
		switch ($token[0]) {
			case T_VARIABLE:
				$expr = $this->create_variable_identifier_with_token($token);
				break;
			case T_LINE:
			case T_FILE:
			case T_DIR:
			case T_EMPTY:
			case T_ISSET:
			case T_UNSET:
			case T_STRING:
			case T_NAME_QUALIFIED:
			case T_NAME_FULLY_QUALIFIED:
				$expr = $this->read_normal_identifier_with_token($token);
				break;
			case T_NS_SEPARATOR:
				$expr = $this->read_classkindred_identifier($token);
				break;
			case T_STATIC:
				$expr = $is_instancing
					? $this->read_classkindred_identifier($token)
					: $this->read_normal_identifier_with_token($token);
				break;
			case T_CONSTANT_ENCAPSED_STRING:
				$expr = $this->read_constant_encapsed_string_with_token($token);
				break;
			case _DOUBLE_QUOTE:
				$expr = $this->read_interpolated_string(_DOUBLE_QUOTE);
				break;
			default:
				$expr = null;
				break;
		}

		return $expr;
	}

	private function read_normal_identifier_with_token(array $token)
	{
		$name = $token[1];
		$mapped_name = self::BUILTIN_IDENTIFIER_MAP[strtolower($name)] ?? null;
		if ($mapped_name) {
			$expr = $this->factory->create_builtin_identifier($mapped_name);
		}
		else {
			$expr = $this->factory->create_plain_identifier($name);
		}

		$expr->pos = $this->pos;
		return $expr;
	}

	private function read_constant_encapsed_string_with_token(array $token)
	{
		$content = $token[1];
		$quote = $content[0];
		$content = substr($content, 1, -1);

		$expr = $quote === _DOUBLE_QUOTE
			? new EscapedLiteralString($content)
			: new PlainLiteralString($content);
		$expr->pos = $this->pos;

		return $expr;
	}

	protected function read_interpolated_string(string|int $ending)
	{
		$items = [];
		do {
			$token = $this->scan_token();
			$type = is_array($token) ? $token[0] : $token;
			switch ($type) {
				case T_ENCAPSED_AND_WHITESPACE:
					$item = $token[1];
					break;
				case T_VARIABLE:
					$item = $this->read_string_interpolation_with($token);
					break;
				case T_CURLY_OPEN:
					$item = $this->read_expression();
					$this->expect_char_token(_BRACE_CLOSE);
					break;
				case $ending:
					break 2;
				default:
					throw $this->new_unexpected_error();
			}

			$items[] = $item;
		}
		while (true);

		$expr = $ending === T_ENCAPSED_AND_WHITESPACE
			? new HereDocString($items)
			: new EscapedInterpolatedString($items);
		$expr->pos = $this->pos;
		return $expr;
	}

	private function read_string_interpolation_with(array $token)
	{
		$expr = $this->create_variable_identifier_with_token($token);
		while ($token = $this->get_token_ignore_empty()) {
			$token_type = is_array($token) ? $token[0] : $token;
			if ($token_type === T_OBJECT_OPERATOR || $token_type === T_NULLSAFE_OBJECT_OPERATOR) {
				$this->scan_token_ignore_empty();
				$nullsafe = $token_type === T_NULLSAFE_OBJECT_OPERATOR;
				$accessing_name = $this->expect_identifier_name();
				$expr = $this->factory->create_accessing_identifier($expr, $accessing_name, $nullsafe);
			}
			elseif ($token_type === _BRACKET_OPEN) {
				$this->scan_token_ignore_empty();
				$key = $this->read_expression();
				$this->skip_char_token(_BRACKET_CLOSE);
				$expr = new KeyAccessing($expr, $key);
			}
			else {
				break;
			}

			$expr->pos = $this->pos;
		}

		return $expr;
	}

	protected function read_expression_combination(BaseExpression $expr, Operator $prev_op = null)
	{
		$token = $this->get_token_ignore_empty();
		$token_type = is_string($token) ? $token : $token[0];
		switch ($token_type) {
			case _PAREN_OPEN:
				$expr = $this->read_call_expression($expr);
				break;

			case _BRACKET_OPEN:
				$expr = $this->read_key_accessing($expr);
				break;

			case T_OBJECT_OPERATOR:
				$this->scan_token_ignore_empty(); // skip ->
				$expr = $this->read_object_member_accessing($expr);
				break;

			case T_NULLSAFE_OBJECT_OPERATOR:
				$this->scan_token_ignore_empty(); // skip ?->
				$expr = $this->read_object_member_accessing($expr, true);
				break;

			case T_DOUBLE_COLON:
				$this->scan_token_ignore_empty(); // skip ::
				$expr = $this->read_static_member_accessing($expr);
				break;

			default:
				while ($next_operator = $this->scan_combinated_operator($prev_op)) {
					$expr = $this->read_operation_for($expr, $next_operator);
				}

				return $expr;
		}

		return $this->read_expression_combination($expr, $prev_op);
	}

	protected function read_object_member_accessing(BaseExpression $basing, bool $nullsafe = false)
	{
		$operator = $nullsafe
			? OperatorFactory::$nullsafe_member_accessing
			: OperatorFactory::$member_accessing;
		$token = $this->scan_token_ignore_empty();

		$type = $token[0];
		switch ($type) {
			case T_STRING:
				$name = $token[1];
				// $name = static::METHOD_MAP[$name] ?? $name;
				$expr = $this->factory->create_accessing_identifier($basing, $name, $nullsafe);
				break;
			case T_VARIABLE:
				$expr = $this->create_variable_identifier_with_token($token);
				$expr = $this->factory->create_binary_operation($basing, $expr, $operator);
				break;
			case _BRACE_OPEN:
				$expr = $this->read_expression();
				$this->expect_char_token(_BRACE_CLOSE);
				$expr = $this->factory->create_binary_operation($basing, $expr, $operator);
				break;
			default:
				throw $this->new_unexpected_error();
		}

		$expr->pos = $this->pos;
		return $expr;
	}

	protected function read_static_member_accessing(BaseExpression $basing)
	{
		$token = $this->scan_token_ignore_empty();
		$type = $token[0];
		switch ($type) {
			case T_STRING:
			case T_CLASS:
			case T_PRINT:
				$name = $token[1];
				// $name = static::METHOD_MAP[$name] ?? $name;
				break;
			case T_VARIABLE:
				$name = $this->get_property_name($token);
				break;
			default:
				throw $this->new_unexpected_error();
		}

		$expr = $this->factory->create_accessing_identifier($basing, $name);
		$expr->is_static = true;
		$expr->pos = $this->pos;
		return $expr;
	}

	protected function read_key_accessing(BaseExpression $basing)
	{
		// array key accessing

		$this->skip_char_token(_BRACKET_OPEN);
		$key = $this->scan_expression();
		$this->skip_char_token(_BRACKET_CLOSE);

		if ($key === null) {
			$expression = new SquareAccessing($basing, false);
		}
		else {
			$expression = new KeyAccessing($basing, $key);
		}

		$expression->pos = $this->pos;
		return $expression;
	}

	protected function read_call_expression(BaseExpression $handler)
	{
		$this->expect_paren_open();
		$args = $this->scan_arguments();
		$this->expect_paren_close();

		foreach ($args as $arg) {
			if ($arg instanceof VariableIdentifier) {
				$var_name = $arg->name;
				if ($arg->symbol === null && !in_array($var_name, self::SUPER_GLOBAL_NAMES, true)) {
					$this->factory->create_variable_declaration_for_identifier($arg);
				}
			}
		}

		$call = new CallExpression($handler, $args);
		$call->pos = $this->pos;

		return $call;
	}

	protected function scan_combinated_operator(Operator $prev_op = null)
	{
		$this->skip_comments();
		$token = $this->get_token_ignore_empty();
		$sign = is_string($token) ? $token : $token[1];

		// PHP has a special grammar rule
		// The assignment operation inside the expression is independent of normal priority rules
		$next_op = OperatorFactory::get_php_normal_operator($sign);
		if ($next_op === null || !$next_op->is_type(OP_ASSIGN) && $this->is_prev_op_priority($prev_op, $next_op)) {
			$this->back_skiped_comments();
			return null;
		}

		$this->scan_token_ignore_empty();

		return $next_op;
	}

	private function is_prev_op_priority(?Operator $prev_op, Operator $next_op)
	{
		if ($prev_op === null) {
			return false;
		}

		$prev_prec = $prev_op->php_prec;
		$next_prec = $next_op->php_prec;
		return ($prev_prec < $next_prec) || ($prev_prec === $next_prec && $next_op->php_assoc !== OP_R);
	}

	protected function read_operation_for(BaseExpression $expr, Operator $operator)
	{
		if ($operator->is(OPID::TERNARY)) {
			// ? [then] : else

			if ($this->skip_char_token(_COLON)) {
				$then = null;
			}
			else {
				$then = $this->read_expression($operator);
				$this->skip_comments();
				$this->expect_char_token(_COLON);
			}

			$else = $this->read_expression($operator);
			$expr = new TernaryExpression($expr, $then, $else);
		}
		elseif ($operator->is_type(OP_ASSIGN)) {
			if ($expr instanceof IArrayLikeExpression) {
				$expr_pos = $expr->pos;
				$expr = $this->factory->create_destructuring($expr->items);
				$expr->pos = $expr_pos;
			}

			$expr2 = $this->read_expression($operator);
			$expr = $this->factory->create_assignment($expr, $expr2, $operator);
		}
		// elseif ($operator->is(OPID::MEMBER_ACCESSING)) {
		// 	$name = $this->expect_identifier_name();
		// 	$expr = $this->factory->create_accessing_identifier($expr, $name);
		// }
		elseif ($operator->is_type(OP_POSTFIX)) {
			$expr = $this->factory->create_postfix_operation($expr, $operator);
		}
		else {
			// allow undefined for left expression of ?? operation
			if ($expr instanceof VariableIdentifier && $operator->is(OPID::NONE_COALESCING)) {
				$this->factory->remove_defer_check($expr);
			}

			$expr2 = $this->read_expression($operator);
			$expr = $this->factory->create_binary_operation($expr, $expr2, $operator);
		}

		$expr->pos = $this->pos;
		return $expr;
	}

	private function read_bracket_expression_with_token(string|array $token = null)
	{
		$is_bracket = $token === _BRACKET_OPEN;
		if (!$is_bracket) {
			$this->expect_paren_open();
		}

		$is_const_value = true;
		$is_dict = false;
		$members = [];
		while ($item = $this->scan_expression()) {
			if (!$item->is_const_value) {
				$is_const_value = false;
			}

			if ($this->skip_typed_token(T_DOUBLE_ARROW)) {
				$is_dict = true;
				$val = $this->read_expression();
				if (!$val->is_const_value) {
					$is_const_value = false;
				}

				$item = new DictMember($item, $val);
				$item->pos = $this->pos;
			}

			$members[] = $item;

			while ($this->get_token_ignore_empty()[0] === T_COMMENT) {
				$this->scan_token_ignore_empty();
			}

			if (!$this->skip_char_token(_COMMA)) {
				break;
			}
		}

		if ($is_bracket) {
			$this->expect_char_token(_BRACKET_CLOSE);
		}
		else {
			$this->expect_paren_close();
		}

		$expr = $is_dict ? new DictExpression($members) : new ArrayExpression($members);
		$expr->is_const_value = $is_const_value;
		$expr->pos = $this->pos;

		return $expr;
	}

	private function read_interface_declaration()
	{
		$name = $this->expect_identifier_name();

		$decl = $this->factory->create_interface_declaration($name, _PUBLIC, $this->namespace);
		$decl->pos = $this->pos;

		if ($this->skip_typed_token(T_EXTENDS)) {
			$identifiers = [];
			do {
				$identifiers[] = $this->read_classkindred_identifier();
			}
			while ($this->skip_char_token(_COMMA));
			$decl->set_extends($identifiers);
		}

		$this->expect_block_begin();

		while ($this->read_interface_member());

		$this->expect_block_end();
		$this->factory->end_class();

		return $decl;
	}

	private function read_trait_declaration()
	{
		$name = $this->expect_identifier_name();

		$decl = $this->factory->create_trait_declaration($name, _PUBLIC, $this->namespace);
		$decl->pos = $this->pos;

		$this->expect_block_begin();

		while ($this->read_class_member());

		$this->expect_block_end();
		$this->factory->end_class();

		return $decl;
	}

	private function read_class_declaration(array $attributes)
	{
		$name = $this->expect_identifier_name();

		$decl = $this->factory->create_class_declaration($name, _PUBLIC, $this->namespace);
		$decl->pos = $this->pos;

		if ($this->skip_typed_token(T_EXTENDS)) {
			$identifiers = [];
			do {
				$identifiers[] = $this->read_classkindred_identifier();
			}
			while ($this->skip_char_token(_COMMA));
			$decl->set_extends($identifiers);
		}

		if ($this->skip_typed_token(T_IMPLEMENTS)) {
			$identifiers = [];
			do {
				$identifiers[] = $this->read_classkindred_identifier();
			}
			while ($this->skip_char_token(_COMMA));
			$decl->set_implements($identifiers);
		}

		$this->expect_block_begin();

		while ($this->read_class_member());

		$this->expect_block_end();
		$this->factory->end_class();

		return $decl;
	}

	private function read_interface_member()
	{
		$token = $this->get_token_ignore_empty();
		if (is_string($token)) {
			if ($token === _BLOCK_END) {
				return null;
			}

			$this->scan_token_ignore_empty();
			throw $this->new_unexpected_error();
		}

		$this->scan_token_ignore_empty();

		$doc = null;
		if ($token[0] === T_DOC_COMMENT) {
			$doc = $token[1];
			$token = $this->expect_typed_token_ignore_empty();
		}

		$modifier = null;
		if (in_array($token[0], [T_PUBLIC], true)) {
			$modifier = $token[1];
			$token = $this->expect_typed_token_ignore_empty();
		}

		$is_static = $token[0] === T_STATIC;
		if ($is_static) {
			$token = $this->expect_typed_token_ignore_empty();
		}

		switch ($token[0]) {
			case T_CONST:
				$decl = $this->read_class_constant_declaration($modifier, $doc);
				$this->expect_statement_end();
				break;
			case T_FUNCTION:
				$decl = $this->read_method_declaration($modifier, $doc, true);
				break;
			case T_COMMENT:
				return $this->read_interface_member();
			default:
				throw $this->new_unexpected_error();
		}

		$decl->is_static = $is_static;

		return $decl;
	}

	private function read_class_member()
	{
		$token = $this->get_token_ignore_empty();
		if (is_string($token)) {
			if ($token === _BLOCK_END) {
				return null;
			}

			$this->scan_token_ignore_empty();
			throw $this->new_unexpected_error();
		}

		$this->scan_token_ignore_empty();

		$doc = null;
		if ($token[0] === T_DOC_COMMENT) {
			$doc = $token[1];
			$token = $this->expect_typed_token_ignore_empty();
		}

		$modifier = null;
		if (in_array($token[0], [T_PUBLIC, T_PROTECTED, T_PRIVATE], true)) {
			$modifier = $token[1];
			$token = $this->scan_token_ignore_empty();;
		}

		$is_abstract = $token[0] === T_ABSTRACT;
		if ($is_abstract) {
			$token = $this->scan_token_ignore_empty();;
		}

		$is_static = $token[0] === T_STATIC;
		if ($is_static) {
			$token = $this->scan_token_ignore_empty();;
		}

		// for property/constant
		$nullable = false;
		if (is_string($token)) {
			if ($token === '?') {
				$nullable = true;
			}
			else {
				throw $this->new_unexpected_error();
			}

			$token = $this->expect_typed_token_ignore_empty();
		}

		switch ($token[0]) {
			case T_VAR:
				$token = $this->expect_typed_token_ignore_empty();
				// unbreak
			case T_VARIABLE:
				$member = $this->read_property_declaration($token, $modifier, $doc);
				$member->is_static = $is_static;
				$this->expect_statement_end();
				break;

			case T_STRING: // type annotated property
			case T_ARRAY:
			case T_NAME_FULLY_QUALIFIED:
				$name = $token[1];
				$declared_type = $this->read_declared_type_with_name($name, $nullable);
				$noted_type = $this->read_noted_type();
				$token = $this->expect_typed_token_ignore_empty();
				$member = $this->read_property_declaration($token, $modifier, $doc, $declared_type);
				$member->is_static = $is_static;
				$member->noted_type = $noted_type;
				$this->expect_statement_end();
				break;

			case T_CONST:
				$member = $this->read_class_constant_declaration($modifier, $doc);
				$this->expect_statement_end();
				break;

			case T_FUNCTION:
				$member = $this->read_method_declaration($modifier, $doc, $is_abstract);
				$member->is_static = $is_static;
				$member->is_abstract = $is_abstract;
				break;

			case T_COMMENT:
				return $this->read_class_member();

			case T_USE:
				$member = $this->read_traits_using_statement();
				break;

			default:
				throw $this->new_unexpected_error();
		}

		return $member;
	}

	private function read_traits_using_statement()
	{
		$items = [];
		do {
			$items[] = $this->read_classkindred_identifier();
		}
		while ($this->skip_char_token(_COMMA));

		$this->expect_statement_end();

		$node = $this->factory->create_traits_using_statement($items);
		$node->pos = $this->pos;

		return $node;
	}

	private function read_const_statement()
	{
		$members = [];
		// for `const A = xxx, B = xxx, C = xxx;` style
		do {
			$node = $this->read_normal_constant_declaration();
			$this->program->append_declaration($node);
		}
		while ($this->skip_char_token(_COMMA));

		$this->expect_statement_end();

		$node = new ConstStatement($members);

		return $node;
	}

	private function read_normal_constant_declaration(?string $doc = null)
	{
		$name = $this->expect_identifier_name();
		$decl = $this->factory->create_constant_declaration(_PUBLIC, $name, $this->namespace);
		$decl->pos = $this->pos;

		$this->continue_reading_constant_decl($decl, $doc);

		return $decl;
	}

	private function read_class_constant_declaration(?string $modifier, ?string $doc)
	{
		$name = $this->expect_identifier_name();
		$decl = $this->factory->create_class_constant_declaration($modifier ?? _PUBLIC, $name);
		$decl->pos = $this->pos;

		$this->continue_reading_constant_decl($decl, $doc);

		$this->factory->end_class_member();

		return $decl;
	}

	private function continue_reading_constant_decl(IConstantDeclaration $decl, ?string $doc)
	{
		if ($doc) {
			$decl->noted_type = $this->get_type_in_doc($doc, 'var');
		}

		$this->expect_char_token(_ASSIGN);
		$decl->value = $this->read_expression();
	}

	private function get_type_in_doc(?string $doc, string $kind)
	{
		// /**
		//  * @var int
		//  */

		$type_expr = $doc && preg_match('/\s+\*\s+@' . $kind . '\s+([^\s]+)/', $doc, $match)
			? $this->create_noted_type_expr($match[1])
			: null;

		return $type_expr;
	}

	private function read_property_declaration(array $token, string $modifier, ?string $doc, IType $type = null)
	{
		$name = $this->get_property_name($token);
		$decl = $this->factory->create_property_declaration($modifier, $name);
		$decl->pos = $this->pos;

		if ($doc) {
			$decl->noted_type = $this->get_type_in_doc($doc, 'var');
		}

		if ($type) {
			$decl->declared_type = $type;
		}

		if ($this->skip_char_token(_ASSIGN)) {
			$decl->value = $this->read_expression();
		}

		$this->factory->end_class_member();

		return $decl;
	}

	private function read_method_declaration(string $modifier = null, ?string $doc, bool $is_abstract = false)
	{
		$name = $this->expect_class_member_name();
		$decl = $this->factory->create_method_declaration($modifier ?? _PUBLIC, $name);
		$decl->pos = $this->pos;

		$params = $this->read_parameters();
		$this->factory->set_scope_parameters($params);

		$this->read_function_return_types_for($decl);

		if ($is_abstract) {
			$this->expect_statement_end();
		}
		else {
			$this->read_body_for_decl($decl);
		}

		$this->factory->end_class_member();
		return $decl;
	}

	private function expect_class_member_name()
	{
		$name = $this->expect_identifier_name();
		if (isset(static::METHOD_MAP[$name])) {
			$name = static::METHOD_MAP[$name];
		}

		return $name;
	}

	private function read_lambda_expression()
	{
		$decl = $this->factory->create_anonymous_function();
		$params = $this->read_parameters();
		$this->factory->set_scope_parameters($params);
		$decl->pos = $this->pos;

		$this->expect_typed_token_ignore_empty(T_DOUBLE_ARROW);

		$expr = $this->read_expression();
		$decl->set_body_with_expression($expr);

		$this->factory->end_block();

		return $decl;
	}

	private function read_anonymous_function_expression()
	{
		$decl = $this->factory->create_anonymous_function();
		$params = $this->read_parameters();
		$this->factory->set_scope_parameters($params);
		$decl->pos = $this->pos;

		if ($this->skip_typed_token(T_USE)) {
			$decl->using_params = $this->read_parameters();
		}

		$this->read_body_for_decl($decl);

		$this->factory->end_block();

		return $decl;
	}

	private function read_function_declaration()
	{
		$name = $this->expect_identifier_name();

		$decl = $this->factory->create_function_declaration(_PUBLIC, $name, $this->namespace);
		$decl->pos = $this->pos;

		$params = $this->read_parameters();
		$this->factory->set_scope_parameters($params);

		$this->read_function_return_types_for($decl);

		$this->read_body_for_decl($decl);
		$this->factory->end_root_declaration();

		return $decl;
	}

	private function read_function_return_types_for(IFunctionDeclaration $decl)
	{
		if ($this->skip_char_token(_COLON)) {
			$nullable = $this->skip_char_token('?');
			$name = $this->expect_identifier_name();
			$decl->declared_type = $this->read_declared_type_with_name($name, $nullable);
		}

		$noted_type = $this->read_noted_type();
		if ($noted_type) {
			$decl->noted_type = $noted_type;
		}
	}

	private function read_parameters()
	{
		$this->expect_paren_open();

		$items = [];
		while ($parameter = $this->read_parameter()) {
			$items[] = $parameter;
			if (!$this->skip_char_token(_COMMA)) {
				break;
			}
		}

		$this->expect_paren_close();

		return $items;
	}

	private function read_parameter()
	{
		$token = $this->get_token_ignore_empty();
		if ($token === _PAREN_CLOSE) {
			return null;
		}

		$this->scan_token_ignore_empty();

		// parameters at __construct maybe has modifiers
		$modifier = null;
		if (in_array($token[0], [T_PUBLIC, T_PROTECTED, T_PRIVATE])) {
			$modifier = $token[1];
			$token = $this->scan_token_ignore_empty();
		}

		$declared_type = $this->read_type_expression_with_token($token);
		$noted_type = $this->read_noted_type();

		if ($declared_type or $noted_type) {
			$token = $this->expect_typed_token_ignore_empty();
		}

		// variadic feature, the '...' operator
		$is_variadic = false;
		if ($token[0] === T_ELLIPSIS) {
			$is_variadic = true;
			$token = $this->expect_typed_token_ignore_empty();
		}

		$decl = $this->read_parameter_header_with_token($token);

		if ($this->skip_char_token(_ASSIGN)) {
			$decl->value = $this->read_expression();
		}

		$decl->declared_type = $declared_type;
		$decl->noted_type = $noted_type;
		$decl->is_variadic = $is_variadic;

		return $decl;
	}

	private function read_parameter_header_with_token(array|string $token)
	{
		// &
		$inout_mode = $token[0] === T_AMPERSAND_FOLLOWED_BY_VAR_OR_VARARG;
		if ($inout_mode) {
			$token = $this->expect_typed_token_ignore_empty();
		}

		$name = $this->get_var_name($token);
		$decl = $this->create_parameter($name);

		if ($inout_mode) {
			$decl->is_inout = true;
			$decl->is_mutable = true;
		}

		return $decl;
	}

	private function read_type_expression_with_token(string|array $token)
	{
		$nullable = $token === _INVALIDABLE_SIGN;
		if ($nullable) {
			$token = $this->expect_typed_token_ignore_empty();
		}

		$token_type = $token[0];
		if (in_array($token_type, self::TYPING_TOKEN_TYPES)) {
			$name = $token[1];
			$type = $this->read_declared_type_with_name($name, $nullable);
		}
		elseif ($nullable) {
			throw $this->new_unexpected_error();
		}
		else {
			$type = null;
		}

		return $type;
	}

	// private function read_block(string $label = null)
	// {
	// 	// echo "\n--------------------begin {$label}\n";
	// 	$this->expect_block_begin();

	// 	// we don't care the contents
	// 	while (($token = $this->get_token_ignore_empty()) !== null) {
	// 		// var_dump($token);
	// 		if ($token === _BLOCK_BEGIN) {
	// 			$this->read_block('local');
	// 		}
	// 		elseif ($token === _BLOCK_END) {
	// 			break;
	// 		}
	// 		elseif ($token === _DOUBLE_QUOTE) {
	// 			$this->scan_token_ignore_empty();
	// 			$this->read_double_quoted_literal_string();
	// 		}
	// 		elseif ($token === _SINGLE_QUOTE) {
	// 			$this->scan_token_ignore_empty();
	// 			$this->read_single_quoted_literal_string();
	// 		}
	// 		elseif ($token[0] === T_START_HEREDOC) {
	// 			$this->scan_token_ignore_empty();
	// 			$this->read_heredoc();
	// 		}
	// 		else {
	// 			$this->scan_token_ignore_empty();
	// 		}
	// 	}

	// 	$this->expect_block_end();
	// 	// echo "\n--------------------end {$label}\n";
	// }

	// private function read_double_quoted_literal_string()
	// {
	// 	$value = $this->skip_to_char_token(_DOUBLE_QUOTE);
	// 	$this->scan_token();
	// 	return $value;
	// }

	// private function read_single_quoted_literal_string()
	// {
	// 	$value = $this->skip_to_char_token(_SINGLE_QUOTE);
	// 	$this->scan_token();
	// 	return $value;
	// }

	private function read_classkindred_identifier(?array $token = null)
	{
		// NS1\NS2\Target
		// \NS1\NS2\Target

		if ($token === null) {
			$token = $this->expect_typed_token_ignore_empty();
		}

		if ($token[0] === T_STATIC) {
			$name = _TYPE_SELF;
			$ns_components = [];
		}
		else {
			$ns_components = $this->read_qualified_name_with($token);
			$name = array_pop($ns_components);
		}

		$identifier = $this->create_classkindred_identifier($name, $ns_components);
		$identifier->pos = $this->pos;

		return $identifier;
	}

	private function create_classkindred_identifier(string $name, array $ns_components = null)
	{
		$identifier = $this->factory->create_classkindred_identifier($name);
		$identifier->pos = $this->pos;

		if ($ns_components) {
			$ns = $this->create_namespace_identifier($ns_components);
			$identifier->set_namespace($ns);

			// $target = $this->factory->append_use_target($ns, $name);
			// $statement = $this->create_use_statement_when_not_exists($ns, [$target]);
		}

		$this->program->append_unknow_identifier($identifier);
		return $identifier;
	}

	private function create_use_statement_when_not_exists(NamespaceIdentifier $ns, array $targets = [])
	{
		if ($this->factory->exists_use_unit($ns)) {
			$statement = null;
		}
		else {
			$statement = new UseStatement($ns, $targets);
			$statement->pos = $this->pos;
		}

		return $statement;
	}

	private function read_declared_type_with_name(string $name, bool $nullable)
	{
		if ($this->get_token_ignore_empty() === _TYPE_UNION) {
			$members = [];
			$members[] = $this->create_type_identifier($name);
			while ($this->skip_char_token(_TYPE_UNION)) {
				$name = $this->expect_identifier_name();
				$members[] = $this->create_type_identifier($name);
			}

			$type = TypeFactory::create_union_type($members);
		}
		else {
			$type = $this->create_type_identifier($name, $nullable);
		}

		return $type;
	}

	private function read_noted_type()
	{
		$type = null;
		$following_comment = $this->scan_comment_ignore_space();
		if ($following_comment !== null) {
			// trim '/*' and '*/'
			$name = substr($following_comment, 2, -2);
			$nullable = str_ends_with($name, _INVALIDABLE_SIGN);
			if ($nullable) {
				$name = substr($name, 0, -1);
			}

			if (self::is_looks_like_type_expression($name)) {
				$type = $this->create_noted_type_expr($name, $nullable);
			}
		}

		return $type;
	}

	private static function is_looks_like_type_expression(?string $token)
	{
		return preg_match('/^[A-Z][a-zA-Z0-9_]*(\.[A-Z][a-zA-Z0-9_]*)*$/', $token);
	}

	private function create_type_identifier(string $name, bool $nullable = false)
	{
		$name = static::TYPE_MAP[strtolower($name)] ?? $name;
		$identifier = $this->create_compatible_type_identifier($name, $nullable);
		$identifier->pos = $this->pos;

		return $identifier;
	}

	private function create_compatible_type_identifier(string $name, bool $nullable = false)
	{
		if ($identifier = TypeFactory::get_type($name)) {
			if ($nullable) {
				$identifier = clone $identifier;
			}
		}
		elseif (strpos($name, static::NS_SEPARATOR) !== false) {
			$names = explode(static::NS_SEPARATOR, $name);
			$identifier = $this->create_classkindred_identifier(array_pop($names), $names);
		}
		else {
			$identifier = $this->create_classkindred_identifier($name);
		}

		$identifier->nullable = $nullable;
		$identifier->pos = $this->pos;

		return $identifier;
	}

	private function create_noted_type_expr(string $note, bool $nullable = false): IType
	{
		if (strpos($note, _DOT)) {
			$expr = $this->create_dots_style_compound_type($note);
			$expr->nullable = $nullable;
		}
		elseif (strpos($note, _BRACKET_OPEN)) {
			$expr = $this->create_bracket_style_type($note);
			$expr->nullable = $nullable;
		}
		else {
			$expr = $this->create_type_identifier($note, $nullable);
		}

		$expr->pos = $this->pos;
		return $expr;
	}

	private function create_bracket_style_type(string $note): IType
	{
		$parts = explode('[]', $note);
		if (!TeaHelper::is_identifier_name($parts[0])) {
			throw $this->new_parse_error("Invalid bracket style type expression noted");
		}

		$expr = $this->create_type_identifier($parts[0]);
		for ($i = 1; $i < count($parts); $i++) {
			if ($parts[$i] !== '') {
				throw $this->new_parse_error("Invalid bracket style type expression noted");
			}

			$expr = TypeFactory::create_array_type($expr);
			$expr->pos = $this->pos;
		}

		return $expr;
	}

	private function create_dots_style_compound_type(string $note): IType
	{
		$names = explode(_DOT, $note);
		$name = array_shift($names);
		$expr = $this->create_type_identifier($name);

		$i = 0;
		foreach ($names as $kind) {
			if ($i === _STRUCT_DIMENSIONS_MAX) {
				throw $this->new_parse_error('The dimensions of Array/Dict exceeds, the max is ' . _STRUCT_DIMENSIONS_MAX);
			}

			if ($kind === _DOT_SIGN_ARRAY) {
				$expr = TypeFactory::create_array_type($expr);
			}
			elseif ($kind === _DOT_SIGN_DICT) {
				$expr = TypeFactory::create_dict_type($expr);
			}
			elseif ($kind === _DOT_SIGN_METATYPE) {
				$expr = TypeFactory::create_meta_type($expr);
			}
			else {
				throw $this->new_unexpected_error();
			}

			$expr->pos = $this->pos;
			$i++;
		}

		return $expr;
	}

	protected function expect_statement_end()
	{
		$this->skip_comments();
		return $this->expect_char_token(_SEMICOLON);
	}

	protected function skip_block_begin()
	{
		return $this->skip_char_token(_BLOCK_BEGIN);
	}

	protected function expect_block_begin()
	{
		return $this->expect_char_token(_BLOCK_BEGIN);
	}

	protected function expect_block_end()
	{
		return $this->expect_char_token(_BLOCK_END);
	}

	private function expect_char_token(string $char)
	{
		$token = $this->scan_token_ignore_empty();
		if ($token !== $char) {
			throw $this->new_unexpected_error();
		}

		return $token;
	}

	private function expect_typed_token(int $type)
	{
		$token = $this->scan_token_ignore_empty();
		if (!is_array($token) || $token[0] !== $type) {
			throw $this->new_unexpected_error();
		}

		return $token;
	}

	private function read_qualified_name_with(array|string $token)
	{
		switch ($token[0]) {
			case T_NS_SEPARATOR:
				$names = $this->read_names_with_component(_NOTHING);
				break;

			case T_STRING:
				$names = $this->read_names_with_component($token[1]);
				break;

			case T_NAME_QUALIFIED:
			case T_NAME_FULLY_QUALIFIED:
				$names = explode(_BACK_SLASH, $token[1]);
				break;

			default:
				throw $this->new_unexpected_error();
		}

		return $names;
	}

	private function read_names_with_component(string $component)
	{
		$names = [$component];

		while (($next = $this->get_token()) && $next[0] === T_NS_SEPARATOR) {
			$this->scan_token();
			$names[] = $this->expect_identifier_name();
		}

		return $names;
	}

	private function expect_variable_name()
	{
		$token = $this->scan_token_ignore_empty();
		while (is_array($token) and $token[0] === T_COMMENT) {
			$token = $this->scan_token_ignore_empty();
		}

		$name = $this->get_var_name($token);
		return $name;
	}

	private function get_var_name(array|string $token)
	{
		if ($token[0] !== T_VARIABLE) {
			throw $this->new_unexpected_error();
		}

		$name = $token[1];
		// $name = substr($token[1], 1); // remove the prefix '$'

		return $name;
	}

	private function get_property_name(array|string $token)
	{
		if ($token[0] !== T_VARIABLE) {
			throw $this->new_unexpected_error();
		}

		$name = substr($token[1], 1); // remove the prefix '$'
		return $name;
	}

	private function expect_identifier_name()
	{
		$token = $this->scan_token_ignore_empty();
		while (is_array($token) and $token[0] === T_COMMENT) {
			$token = $this->scan_token_ignore_empty();
		}

		return $this->get_identifier_name($token);
	}

	private function get_identifier_name(string|array $token)
	{
		if (is_string($token) || !in_array($token[0], self::NORMAL_IDENTIFIER_TOKEN_TYPES, true) ) {
			throw $this->new_unexpected_error();
		}

		return $token[1];
	}

	private function skip_to_char_token(string $char)
	{
		while (($token = $this->scan_token()) !== null) {
			if ($token === $char) {
				return;
			}
		}

		throw $this->new_parse_error("Expected token \"$char\".");
	}

	private function skip_comma()
	{
		return $this->skip_char_token(_COMMA);
	}

	private function skip_char_token(string $char)
	{
		$token = $this->get_token_ignore_empty();
		if ($token === $char) {
			$this->scan_token_ignore_empty();
			return true;
		}

		return false;
	}

	private function skip_typed_token(int $type)
	{
		$token = $this->get_token_ignore_empty();
		if (is_array($token) && $token[0] === $type) {
			$this->scan_token_ignore_empty();
			return true;
		}

		return false;
	}

	protected function get_current_token_string()
	{
		$token = $this->tokens[$this->pos] ?? null;
		return is_array($token) ? $token[1] : $token;
	}

	private function expect_typed_token_ignore_empty()
	{
		$token = $this->scan_token_ignore_empty();
		if (!is_array($token)) {
			throw $this->new_unexpected_error();
		}

		return $token;
	}

	private function scan_typed_token_ignore_empty(int $type)
	{
		$token = $this->get_token_ignore_empty();
		if (is_array($token) && $token[0] === $type) {
			$this->scan_token_ignore_empty();
			return $token;
		}

		return null;
	}

	private function get_typed_token_ignore_empty(int $type)
	{
		$token = $this->get_token_ignore_empty();
		if (is_array($token) && $token[0] === $type) {
			return $token;
		}

		return null;
	}

	private function scan_comment_ignore_space()
	{
		$comment = null;
		$next = $this->get_token_ignore_space();
		if ($next !== null and ($next[0] === T_DOC_COMMENT || $next[0] === T_COMMENT)) {
			$comment = $next[1];
			do {
				$this->pos++;
				$tmp = $this->tokens[$this->pos] ?? null;
				if ($tmp !== _SPACE && (!is_array($tmp) || $tmp[0] !== T_WHITESPACE)) {
					break;
				}
			} while ($tmp !== null);
		}

		return $comment;
	}

	private function scan_token_ignore_empty()
	{
		do {
			$this->pos++;
			$token = $this->tokens[$this->pos] ?? null;
			if ($token !== _SPACE && !$this->is_whitespace($token)) {
				break;
			}
		} while ($token !== null);

		return $token;
	}

	private function scan_token()
	{
		$this->pos++;
		$token = $this->tokens[$this->pos] ?? null;

		if ($token[0] === T_WHITESPACE) {
			return $this->scan_token();
		}

		return $token;
	}

	private function get_token_ignore_space()
	{
		$pos = $this->pos;

		do {
			$pos++;
			$token = $this->tokens[$pos] ?? null;
			if ($token !== _SPACE && !$this->is_inline_whitespace($token)) {
				break;
			}
		} while ($token !== null);

		return $token;
	}

	private function get_token_ignore_empty()
	{
		$pos = $this->pos;

		do {
			$pos++;
			$token = $this->tokens[$pos] ?? null;
			if ($token !== _SPACE && !$this->is_whitespace($token)) {
				break;
			}
		} while ($token !== null);

		return $token;
	}

	private function is_inline_whitespace($token)
	{
		return is_array($token) && $token[0] === T_WHITESPACE && strpos($token[1], "\n") === false;
	}

	private function is_whitespace($token)
	{
		return is_array($token) && $token[0] === T_WHITESPACE;
	}

	private function get_token()
	{
		return $this->tokens[$this->pos + 1] ?? null;
	}

	protected function get_to_line_end(int $from = null)
	{
		$i = $from ?? $this->pos + 1;

		$tmp = '';
		while ($i < $this->tokens_count) {
			$token = $this->tokens[$i];
			$i++;

			if ($token === LF || (is_array($token) && strpos($token[1], LF) !== false)) {
				break;
			}

			$tmp .= is_string($token) ? $token : $token[1];
		}

		return $tmp;
	}

	protected function get_line_number(int $pos): int
	{
		if ($pos >= $this->tokens_count) {
			$pos = $this->tokens_count - 1;
		}

		while ($pos < $this->tokens_count) {
			if (is_array($this->tokens[$pos]) || $pos <= 0) {
				break;
			}
			else {
				$pos--;
			}
		}

		return $this->tokens[$pos][2];
	}

	protected function get_previous_code_inline(int $pos = null): string
	{
		if ($pos === null) {
			$pos = $this->pos;
		}

		$code = '';
		$temp_line = null;

		while (isset($this->tokens[$pos])) {
			$token = $this->tokens[$pos];
			if (is_array($token)) {
				if ($temp_line !== null && $temp_line !== $token[2]) {
					break;
				}

				$code = $token[1] . $code;
				$temp_line = $token[2];
			}
			else {
				$code = $token . $code;
			}

			$pos--;
		}

		return $code;
	}

	protected function print_token($token = null, string $marker = null)
	{
		$token === null and ($token = $this->tokens[$this->pos] ?? null);

		if ($marker) {
			echo $marker . "\t";
		}

		if (is_string($token)) {
			echo $token, LF;
		}
		elseif ($token) {
			echo token_name($token[0]), " $token[1]\n";
		}
		else {
			echo "no token at pos {$this->pos}\n";
		}
	}
}

// end
