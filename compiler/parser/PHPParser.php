<?php
/**
 * This file is part of the Tea programming language project
 *
 * @author 		Benny <benny@meetdreams.com>
 * @copyright 	(c)2019 YJ Technology Ltd. [http://tealang.org]
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
		'this' => _THIS,
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
		'__construct' => _CONSTRUCT,
		'__destruct' => _DESTRUCT,
		'__toString' => 'to_string',
	];

	private const PREFIX_OPERATORS = [_EXCLAMATION, _NEGATION, _IDENTITY, _BITWISE_NOT];

	private const EXPR_STOPPING_SIGNS = [_PAREN_CLOSE, _BRACKET_CLOSE, _BLOCK_END, _COMMA, _SEMICOLON];

	private const NORMAL_IDENTIFIER_TOKEN_TYPES = [T_STRING, T_ARRAY, T_STATIC, T_NAME_FULLY_QUALIFIED];

	private const MEMBER_IDENTIFIER_TOKEN_TYPES = [T_STRING, T_PRINT, T_ECHO, T_EXIT, T_USE, T_UNSET, T_CLASS];

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

	private function read_root_statement()
	{
		$token = $this->scan_token_ignore_empty();
		if ($token === null) {
			return null;
		}

		$token_type = is_string($token) ? $token : $token[0];

		$doc = null;
		if ($token_type === T_DOC_COMMENT) {
			$doc = $token[1];
			$token = $this->expect_typed_token_ignore_empty();
		}

		switch ($token_type) {
			case T_NAMESPACE:
				$node = $this->read_namespace_statement();
				break;
			case T_USE:
				$node = $this->read_use_namespace_statement();
				break;
			case T_CLASS:
				$node = $this->read_class_declaration();
				break;
			case T_ABSTRACT:
				$this->expect_typed_token(T_CLASS);
				$node = $this->read_class_declaration(true);
				break;
			case T_INTERFACE:
				$node = $this->read_interface_declaration();
				break;
			case T_TRAIT:
				$node = $this->read_trait_declaration();
				break;
			case T_FUNCTION:
				$node = $this->read_function_declaration($doc);
				break;
			case T_CONST:
				$node = $this->read_const_statement();
				break;
			case T_OPEN_TAG:
			case T_CLOSE_TAG:
				$node = null;
				break;
			default:
				$node = $this->read_normal_statement_with_token($token);
				break;
		}

		return $node;
	}

	protected function read_inner_statement()
	{
		$token = $this->scan_token_ignore_empty();
		if ($token === null) {
			return null;
		}

		$token_type = is_string($token) ? $token : $token[0];
		switch ($token_type) {
			case T_USE:
				$node = $this->read_use_trait_statement();
				break;
			case _BLOCK_END:
				$this->back();
				$node = null;
				break;
			default:
				$node = $this->read_normal_statement_with_token($token);
				break;
		}

		return $node;
	}

	private function read_normal_statement_with_token(array|string $token): ?IStatement
	{
		$token_type = is_string($token) ? $token : $token[0];
		switch ($token_type) {
			case T_COMMENT:
				$content = $token[1];
				$node = str_starts_with($content, '/*')
					? new BlockComment($content)
					: new LineComment($token[1]);
				$node->pos = $this->pos;
				break;
			case T_DOC_COMMENT:
				$node = new DocComment($token[1]);
				$node->pos = $this->pos;
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
				$node = $this->read_do_block();
				break;
			case T_TRY:
				$node = $this->read_try_block();
				break;
			case T_GLOBAL:
				$node = $this->read_global_statement();
				break;
			case T_STATIC:
				if ($this->get_typed_token_ignore_empty(T_VARIABLE)) {
					$node = $this->read_static_statement();
					break;
				}
			default:
				$node = $this->read_expression_statement_with($token);
				break;
		}

		return $node;
	}

	private function read_expression_statement_with(string|array $token): ?NormalStatement
	{
		$expr = $this->read_expression_with_token($token);
		if ($expr === null) {
			return null;
		}

		$this->expect_statement_end();

		$node = new NormalStatement($expr);
		$node->pos = $this->pos;

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
		// global var0 Type0, var1 Type1, ...

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
		// static var0 Type0, var1 Type1, ...

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
		// var abc Type
		// var abc Type = expression

		$name = $this->scan_token_ignore_empty();
		$type = $this->try_read_type_expression();

		$value = null;

		if (!$without_value) {
			if ($this->get_token_ignore_empty() === _ASSIGN) {
				$this->scan_token_ignore_empty();
				$value = $this->read_expression();
			}
		}

		return $this->factory->create_variable_declaration($name, $type, $value);
	}

	private function read_continue_statement()
	{
		// continue
		// continue target_layers

		$statement = new ContinueStatement();

		$token = $this->scan_typed_token_ignore_empty(T_LNUMBER);
		if ($token !== null) {
			$statement->target_layers = $token[1];
		}

		$this->expect_statement_end();

		return $statement;
	}

	private function read_break_statement()
	{
		// break
		// break target_layers

		$statement = new BreakStatement();

		$token = $this->scan_typed_token_ignore_empty(T_LNUMBER);
		if ($token !== null) {
			$statement->target_layers = $token[1];
		}

		$this->expect_statement_end();

		return $statement;
	}

	private function read_exit_statement()
	{
		// exit
		// exit expression

		$argument = $this->read_expression();
		$statement = $this->factory->create_exit_statement($argument);

		$this->expect_statement_end();

		return $statement;
	}

	private function read_return_statement()
	{
		// return
		// return expression

		$argument = $this->read_expression();
		$statement = $this->factory->create_return_statement($argument);

		$this->expect_statement_end();

		return $statement;
	}

	private function read_throw_statement()
	{
		// throw Exception()

		$argument = $this->read_expression();
		if ($argument === null) {
			throw $this->new_parse_error("Required an Exception argument.");
		}

		$statement = $this->factory->create_throw_statement($argument);

		$this->expect_statement_end();

		return $statement;
	}

	protected function read_echo_statement()
	{
		// echo
		// echo argument0, argument1, ...

		$args = $this->read_arguments();

		$this->expect_statement_end();

		return new EchoStatement($args);
	}

	private function read_if_block()
	{
		// if test_expression { ... } [elseif test_expression {...}] [else { ... }] [catch e Exception {}] finally {}

		$this->expect_char_token(_PAREN_OPEN);
		$test = $this->read_expression();
		$this->expect_char_token(_PAREN_CLOSE);

		$block = $this->factory->create_if_block($test);
		$this->read_block_body_for($block);

		$this->try_attach_else_block($block);

		$this->factory->end_branches($block);

		return $block;
	}

	private function try_attach_else_block(IElseAble $main_block)
	{
		$this->skip_comments();

		$token = $this->get_token_ignore_empty();
		$token_type = $token[0];
		if ($token_type === T_ELSE) {
			$this->scan_token_ignore_empty();
			$sub_block = $this->factory->create_else_block();
			$this->read_block_body_for($sub_block);

			// else block would be the end
		}
		elseif ($token_type === T_ELSEIF) {
			$this->scan_token_ignore_empty();
			$this->expect_char_token(_PAREN_OPEN);
			$test = $this->read_expression();
			$this->expect_char_token(_PAREN_CLOSE);

			$sub_block = $this->factory->create_elseif_block($test);
			$this->read_block_body_for($sub_block);

			// another else block
			$this->try_attach_else_block($sub_block);
		}
		else {
			return;
		}

		$main_block->set_else_block($sub_block);
	}

	private function skip_comments()
	{
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
		$this->read_block_body_for($block);

		$this->try_read_catching_block($block);

		$this->factory->end_branches($block);

		return $block;
	}

	private function try_read_catching_block(IExceptAble $main_block)
	{
		$this->skip_comments();

		$token = $this->get_token_ignore_empty();
		$token_type = $token[0];

		if ($token_type === T_CATCH) {
			$this->scan_token_ignore_empty();
			$this->expect_char_token(_PAREN_OPEN);

			$type = $this->try_read_type_expression();
			$var_name = $this->expect_variable_name();

			$this->expect_char_token(_PAREN_CLOSE);

			$sub_block = $this->factory->create_catch_block($var_name, $type);
			$this->read_block_body_for($sub_block);
			$main_block->add_catching_block($sub_block);

			// another except block
			$this->try_read_catching_block($main_block);
		}
		elseif ($token_type === T_FINALLY) {
			$this->scan_token_ignore_empty();

			$sub_block = $this->factory->create_finally_block();
			$this->read_block_body_for($sub_block);
			$main_block->set_finally_block($sub_block);

			// finally block would be the end
		}
	}

	private function read_switch_block(string $label = null)
	{
		$this->expect_char_token(_PAREN_OPEN);
		$argument = $this->read_expression();
		$this->expect_char_token(_PAREN_CLOSE);

		$block = $this->factory->create_switch_block($argument);
		$block->label = $label;

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
				? $this->expect_expression()
				: null;

			$this->expect_char_token(_COLON);
			$case_type = $this->skip_case();
		}
		while ($case_type);

		return $items;
	}

	private function read_foreach_block(string $label = null)
	{
		$this->expect_char_token(_PAREN_OPEN);
		$iterable = $this->read_expression();

		$this->expect_typed_token_ignore_empty(_AS);
		$val_var = $this->read_variable_identifier();

		$key_var = null;
		if ($this->skip_typed_token(T_DOUBLE_ARROW)) {
			$key_var = $val_var;
			$val_var = $this->read_variable_identifier();
		}

		$this->expect_char_token(_PAREN_CLOSE);

		$block = $this->factory->create_forin_block($key_var, $val_var, $iterable);

		$block->label = $label;
		$this->read_block_body_for($block);

		return $block;
	}

	private function read_variable_identifier()
	{
		$token = $this->scan_token_ignore_empty();
		$name = $this->get_var_name($token);
		$identifier = $this->create_variable_identifier($name);
		return $identifier;
	}

	private function create_variable_identifier(string $name)
	{
		$identifier = $this->factory->create_variable_identifier($name);
		$identifier->pos = $this->pos;
		return $identifier;
	}

	private function read_for_block(string $label = null)
	{
		$this->expect_char_token(_PAREN_OPEN);
		$args1 = $this->read_arguments();

		$this->expect_char_token(_SEMICOLON);
		$args2 = $this->read_arguments();

		$this->expect_char_token(_SEMICOLON);
		$args3 = $this->read_arguments();

		$this->expect_char_token(_PAREN_CLOSE);

		$block = $this->factory->create_for_block($args1, $args2, $args3);

		$block->label = $label;
		$this->read_block_body_for($block);

		return $block;
	}

	private function read_arguments()
	{
		$args = [];
		do {
			$args[] = $this->read_expression();
		}
		while ($this->skip_comma());

		return $args;
	}

	private function read_while_block(string $label = null)
	{
		$this->expect_char_token(_PAREN_OPEN);
		$test = $this->read_expression();
		$this->expect_char_token(_PAREN_CLOSE);

		$block = $this->factory->create_while_block($test);
		$block->label = $label;

		$this->read_block_body_for($block);

		return $block;
	}

	private function read_do_while_block(string $label = null)
	{
		// e.g. while test_expression {}

		$block = $this->factory->create_do_while_block($test);
		$block->label = $label;

		$this->read_block_body_for($block);

		$this->expect_typed_token_ignore_empty(T_WHILE);

		$this->expect_char_token(_PAREN_OPEN);
		$test = $this->read_expression();
		$this->expect_char_token(_PAREN_CLOSE);

		return $block;
	}

// ---

	protected function expect_expression(): BaseExpression
	{
		$expr = $this->read_expression();
		if ($expr === null) {
			throw $this->new_unexpected_error();
		}

		return $expr;
	}

	protected function read_expression(Operator $prev_operator = null): ?BaseExpression
	{
		$token = $this->scan_token_ignore_empty();
		if ($token === null or $this->is_expression_stopping_token($token)) {
			$this->back();
			return null;
		}

		$expr = $this->read_expression_with_token($token, $prev_operator);
		return $expr;
	}

	private function is_expression_stopping_token(string|array $token)
	{
		return is_string($token) && in_array($token, static::EXPR_STOPPING_SIGNS, true);
	}

	private function read_expression_with_token(string|array $token, Operator $prev_operator = null): ?BaseExpression
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
			case T_VARIABLE:
				$name = $this->get_var_name($token);
				$expr = $this->create_variable_identifier($name);
				break;

			case T_LINE:
			case T_FILE:
			case T_DIR:
			case T_EMPTY:
			case T_ISSET:
			case T_UNSET:
			case T_STATIC:
			case T_STRING:
				$lower_case_name = strtolower($token_content);
				$mapped_name = self::BUILTIN_IDENTIFIER_MAP[$lower_case_name] ?? null;
				if ($mapped_name) {
					$expr = $this->factory->create_builtin_identifier($mapped_name);
				}
				else {
					$expr = $this->create_unchecking_identifier($token_content);
				}
				$expr->pos = $this->pos;
				break;
			case T_NS_SEPARATOR:
				$expr = $this->read_classkindred_identifier($token);
				break;
			case T_NAME_QUALIFIED:
			case T_NAME_FULLY_QUALIFIED:
				$expr = $this->create_unchecking_identifier($token_content);
				$expr->pos = $this->pos;
				break;

			case T_CONSTANT_ENCAPSED_STRING:
				$quote_sign = $token_content[0];
				$quote_content = substr($token_content, 1, -1);
				$expr = $quote_sign === _DOUBLE_QUOTE
					? new EscapedLiteralString($quote_content)
					: new PlainLiteralString($quote_content);
				$expr->pos = $this->pos;
				break;
			case T_LNUMBER:
				$expr = new LiteralInteger($token_content);
				$expr->pos = $this->pos;
				break;
			case T_DNUMBER:
				$expr = new LiteralFloat($token_content);
				$expr->pos = $this->pos;
				break;

			case _DOUBLE_QUOTE:
				$expr = $this->read_double_quoted_string_expression();
				break;

			case _BRACKET_OPEN:
				$expr = $this->read_bracket_expression(true);
				break;
			case T_FN:
				$expr = $this->read_lambda_expression();
				break;

			case T_DOUBLE_ARROW:
				$this->back();
				return null;

			case T_COMMENT:
			case T_DOC_COMMENT:
				return $this->read_expression($prev_operator);

			case T_INCLUDE_ONCE:
			case T_INCLUDE:
			case T_REQUIRE_ONCE:
			case T_REQUIRE:
				$expr = $this->expect_expression();
				$expr = new IncludeExpression($expr, $token_type);
				$expr->pos = $this->pos;
				return $expr;

			default:
				// check is prefix operator
				$operator = OperatorFactory::get_php_prefix_operator($token_content);
				if ($operator === null) {
					throw $this->new_unexpected_error();
				}

				$expr = $this->read_prefix_operation($operator);
				break;
		}

		$expr = $this->read_expression_combination($expr, $prev_operator);

		return $expr;
	}

	protected function read_double_quoted_string_expression()
	{
		$items = [];
		do {
			$token = $this->scan_token();
			if ($token === _DOUBLE_QUOTE) {
				break;
			}

			$token_content = $token[1];
			switch ($token[0]) {
				case T_ENCAPSED_AND_WHITESPACE:
					$item = $token_content;
					break;
				case T_VARIABLE:
					$name = $this->get_var_name($token);
					$item = $this->create_variable_identifier($name);
					while ($this->skip_typed_token(T_OBJECT_OPERATOR)) {
						$accessing_name = $this->expect_identifier_name();
						$item = $this->factory->create_accessing_identifier($item, $accessing_name);
						$item->pos = $this->pos;
					}
					break;
				case T_CURLY_OPEN:
					$item = $this->read_expression();
					$this->expect_char_token(_BRACE_CLOSE);
					break;
				default:
					throw $this->new_unexpected_error();
			}

			$items[] = $token;
		}
		while (1);

		$expr = new EscapedInterpolatedString($items);
		$expr->pos = $this->pos;
		return $expr;
	}

	// protected function read_plain_identifier()
	// {
	// 	$name = $this->expect_identifier_name();
	// 	$identifier = $this->factory->create_identifier($name);
	// 	$identifier->pos = $this->pos;
	// 	return $identifier;
	// }

	protected function read_expression_combination(BaseExpression $expr, Operator $prev_operator = null)
	{
		$token = $this->get_token_ignore_empty();
		$token_type = is_string($token) ? $token : $token[0];
		switch ($token_type) {
			case _PAREN_OPEN:
				$expr = $this->read_call_expression($expr);
				break;

			case _BRACKET_OPEN:
				$expr = $this->read_bracket_expression();
				break;

			case T_OBJECT_OPERATOR:
				$this->scan_token_ignore_empty();
				$name = $this->expect_member_identifier_name();
				$expr = $this->factory->create_accessing_identifier($expr, $name);
				$expr->pos = $this->pos;
				break;

			case T_DOUBLE_COLON:
				$this->scan_token_ignore_empty();
				$name = $this->expect_member_identifier_name();
				$expr = $this->factory->create_accessing_identifier($expr, $name);
				$expr->pos = $this->pos;
				break;

			default:
				return $this->try_read_operation($expr, $prev_operator);
		}

		return $this->read_expression_combination($expr, $prev_operator);
	}

	protected function read_call_expression(BaseExpression $handler)
	{
		$this->expect_char_token(_PAREN_OPEN);
		$args = $this->read_call_expression_arguments();
		$this->expect_char_token(_PAREN_CLOSE);

		$call = new CallExpression($handler, $args);
		$call->pos = $this->pos;

		return $call;
	}

	protected function read_call_expression_arguments()
	{
		$items = [];
		while ($item = $this->read_argument()) {
			$items[] = $item;
			if (!$this->skip_char_token(_COMMA)) {
				break;
			}
		}

		return $items;
	}

	protected function read_argument()
	{
		$token = $this->scan_token_ignore_empty();
		if ($token === null or $this->is_expression_stopping_token($token)) {
			$this->back();
			return null;
		}

		$expr = $this->read_expression_with_token($token);
		return $expr;
	}

	protected function try_read_operation(BaseExpression $expr, Operator $prev_operator = null)
	{
		$token = $this->get_token_ignore_empty();
		$maybe_oper = is_string($token) ? $token : $token[1];

		$operator = OperatorFactory::get_php_normal_operator($maybe_oper);
		if ($operator === null) {
			return $expr;
		}

		// compare operator precedences
		if ($prev_operator !== null and $this->is_prev_priority($prev_operator, $operator)) {
			return $expr;
		}

		$this->scan_token_ignore_empty();

		if ($operator->is(OPID::TERNARY)) {
			$expr2 = $this->read_expression($operator);
			$this->expect_char_token(_COLON);
			$expr3 = $this->read_expression($operator);
			$expr = new TernaryExpression($expr, $expr2, $expr3);
		}
		elseif ($operator->is_assignment()) {
			$expr2 = $this->read_expression($operator);
			$expr = $this->factory->create_assignment($expr, $expr2, $operator);
		}
		// elseif ($operator->is(OPID::MEMBER_ACCESSING)) {
		// 	$name = $this->expect_identifier_name();
		// 	$expr = $this->factory->create_accessing_identifier($expr, $name);
		// }
		else {
			$expr2 = $this->read_expression($operator);
			$expr = $this->factory->create_binary_operation($expr, $expr2, $operator);
		}

		$expr->pos = $this->pos;

		return $this->try_read_operation($expr, $prev_operator);
	}

	private function is_prev_priority(Operator $prev_op, Operator $curr_op)
	{
		$prev_prec = $prev_op->php_prec;
		$curr_prec = $curr_op->php_prec;
		return ($prev_prec < $curr_prec) || ($prev_prec === $curr_prec && $curr_op->php_assoc !== OP_R);
	}

	private function create_unchecking_identifier(string $name)
	{
		$identifier = new PlainIdentifier($name);
		$identifier->pos = $this->pos;
		return $identifier;
	}

	private function read_bracket_expression(bool $opened = false)
	{
		$opened || $this->expect_char_token(_BRACKET_OPEN);

		$is_const_value = true;
		$is_dict = false;
		$members = [];
		while ($item = $this->read_expression()) {
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

		$this->expect_char_token(_BRACKET_CLOSE);

		$expr = $is_dict ? new DictExpression($members) : new ArrayExpression($members);
		$expr->is_const_value = $is_const_value;
		$expr->pos = $this->pos;

		return $expr;
	}

	private function read_interface_declaration()
	{
		$name = $this->expect_identifier_name();

		$declaration = $this->factory->create_interface_declaration($name, _PUBLIC, $this->namespace);
		$declaration->pos = $this->pos;

		if ($this->skip_typed_token(T_EXTENDS)) {
			$declaration->bases = $this->expect_identifier_name();
		}

		$this->expect_block_begin();

		while ($this->read_interface_member());

		$this->expect_block_end();
		$this->factory->end_class();

		return $declaration;
	}

	private function read_trait_declaration()
	{
		$name = $this->expect_identifier_name();

		$declaration = $this->factory->create_trait_declaration($name, _PUBLIC, $this->namespace);
		$declaration->pos = $this->pos;

		$this->expect_block_begin();

		while ($this->read_class_member());

		$this->expect_block_end();
		$this->factory->end_class();

		return $declaration;
	}

	private function read_class_declaration(bool $is_abstract = false)
	{
		$name = $this->expect_identifier_name();

		$declaration = $this->factory->create_class_declaration($name, _PUBLIC, $this->namespace);
		$declaration->pos = $this->pos;
		$declaration->is_abstract = $is_abstract;

		if ($this->skip_typed_token(T_EXTENDS)) {
			$declaration->inherits = $this->read_classkindred_identifier();
		}

		if ($this->skip_typed_token(T_IMPLEMENTS)) {
			do {
				$implements[] = $this->read_classkindred_identifier();
			}
			while ($this->skip_char_token(_COMMA));

			$declaration->bases = $implements;
		}

		$this->expect_block_begin();

		while ($this->read_class_member());

		$this->expect_block_end();
		$this->factory->end_class();

		return $declaration;
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
				$declaration = $this->read_class_constant_declaration($modifier, $doc);
				$this->expect_statement_end();
				break;
			case T_FUNCTION:
				$declaration = $this->read_method_declaration($modifier, $doc, true);
				break;
			case T_COMMENT:
				return $this->read_interface_member();
			default:
				throw $this->new_unexpected_error();
		}

		$declaration->is_static = $is_static;

		return $declaration;
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
				$declaration = $this->read_property_declaration($token, $modifier, $doc);
				$this->expect_statement_end();
				break;

			case T_STRING: // type annotated property
			case T_ARRAY:
			case T_NAME_FULLY_QUALIFIED:
				$name = $token[1];
				$declared_type = $this->read_declared_type_with_name($name, $nullable);
				$noted_type = $this->try_read_noted_type();
				$token = $this->expect_typed_token_ignore_empty();
				$declaration = $this->read_property_declaration($token, $modifier, $doc, $declared_type);
				$declaration->noted_type = $noted_type;
				$this->expect_statement_end();
				break;

			case T_CONST:
				$declaration = $this->read_class_constant_declaration($modifier, $doc);
				$this->expect_statement_end();
				break;

			case T_FUNCTION:
				$declaration = $this->read_method_declaration($modifier, $doc);
				break;

			case T_COMMENT:
				return $this->read_class_member();

			case T_USE:
				$declaration = $this->read_trait_use_declaration();
				break;

			default:
				throw $this->new_unexpected_error();
		}

		if ($is_static) {
			$declaration->is_static = $is_static;
		}

		$this->factory->end_class_member();

		return $declaration;
	}

	private function read_trait_use_declaration()
	{
		$used_traits = [];
		do {
			$used_traits[] = $this->read_classkindred_identifier();
		}
		while ($this->skip_char_token(_COMMA));

		$this->expect_statement_end();

		return new ClassUseTraitsDeclaration($used_traits);
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
		$declaration = $this->factory->create_constant_declaration(_PUBLIC, $name, $this->namespace);
		$declaration->pos = $this->pos;

		$this->continue_reading_constant_decl($declaration, $doc);

		return $declaration;
	}

	private function read_class_constant_declaration(?string $modifier, ?string $doc)
	{
		$name = $this->expect_member_identifier_name();
		$declaration = $this->factory->create_class_constant_declaration($modifier ?? _PUBLIC, $name);
		$declaration->pos = $this->pos;

		$this->continue_reading_constant_decl($declaration, $doc);

		return $declaration;
	}

	private function continue_reading_constant_decl(IConstantDeclaration $declaration, ?string $doc)
	{
		if ($doc) {
			$declaration->noted_type = $this->get_type_in_doc($doc, 'var');
		}

		$this->expect_char_token(_ASSIGN);
		$declaration->value = $this->read_expression();
	}

	private function get_type_in_doc(?string $doc, string $kind)
	{
		// /**
		//  * @var int
		//  */

		if ($doc !== null and preg_match('/\s+\*\s+@' . $kind . '\s+([^\s]+)/', $doc, $match)) {
			$name = $match[1];
			$identifier = $this->create_noted_type_identifier($name);
		}
		else {
			$identifier = null;
		}

		return $identifier;
	}

	private function read_property_declaration(array $token, string $modifier, ?string $doc, IType $type = null)
	{
		$name = $this->get_var_name($token);
		$declaration = $this->factory->create_property_declaration($modifier, $name);
		$declaration->pos = $this->pos;

		if ($doc) {
			$declaration->noted_type = $this->get_type_in_doc($doc, 'var');
		}

		if ($type) {
			$declaration->declared_type = $type;
		}

		if ($this->skip_char_token(_ASSIGN)) {
			$declaration->value = $this->read_expression();
		}

		return $declaration;
	}

	private function read_method_declaration(string $modifier = null, ?string $doc, bool $is_interface = false)
	{
		$name = $this->expect_member_identifier_name();
		if (isset(static::METHOD_MAP[$name])) {
			$name = static::METHOD_MAP[$name];
		}

		$declaration = $this->factory->create_method_declaration($modifier ?? _PUBLIC, $name);
		$declaration->pos = $this->pos;

		$parameters = $this->read_parameters();
		$this->factory->set_scope_parameters($parameters);

		$this->try_read_function_return_types_for($declaration);

		if ($is_interface) {
			$this->expect_statement_end();
		}
		else {
			$this->read_body_statements_for($declaration);
			$this->factory->end_class_member();
		}

		return $declaration;
	}

	private function read_lambda_expression()
	{
		$lambda = $this->factory->create_anonymous_function();
		$parameters = $this->read_parameters();
		$this->factory->set_scope_parameters($parameters);

		$lambda->pos = $this->pos;
		$this->expect_typed_token_ignore_empty(T_DOUBLE_ARROW);

		$expr = $this->expect_expression();
		$lambda->set_body_with_expression($expr);

		$this->factory->end_block();

		return $lambda;
	}

	private function read_function_declaration(?string $doc)
	{
		$name = $this->expect_identifier_name();

		$declaration = $this->factory->create_function_declaration(_PUBLIC, $name, $this->namespace);
		$declaration->pos = $this->pos;

		$parameters = $this->read_parameters();
		$this->factory->set_scope_parameters($parameters);

		$this->try_read_function_return_types_for($declaration);

		$this->read_body_statements_for($declaration);
		$this->factory->end_root_declaration();

		return $declaration;
	}

	private function try_read_function_return_types_for(IFunctionDeclaration $declaration)
	{
		if ($this->skip_char_token(_COLON)) {
			$nullable = $this->skip_char_token('?');
			$name = $this->expect_identifier_name();
			$declaration->declared_type = $this->read_declared_type_with_name($name, $nullable);
		}

		$noted_type = $this->try_read_noted_type();
		if ($noted_type) {
			$declaration->noted_type = $noted_type;
		}
	}

	private function read_parameters()
	{
		$this->expect_char_token(_PAREN_OPEN);

		$items = [];
		while ($parameter = $this->read_parameter()) {
			$items[] = $parameter;
			if (!$this->skip_char_token(_COMMA)) {
				break;
			}
		}

		$this->expect_char_token(_PAREN_CLOSE);

		return $items;
	}

	private function read_parameter()
	{
		$token = $this->get_token_ignore_empty();
		if ($token === _PAREN_CLOSE) {
			return null;
		}

		$this->scan_token_ignore_empty();
		$token_type = $token[0];

		// parameters at __construct maybe has modifiers
		$modifier = null;
		if (in_array($token_type, [T_PUBLIC, T_PROTECTED, T_PRIVATE])) {
			$modifier = $token[1];
			$token = $this->scan_token_ignore_empty();
		}

		$declared_type = $this->try_read_type_expression_with_token($token);
		$noted_type = $this->try_read_noted_type();

		if ($declared_type or $noted_type) {
			$token = $this->scan_token_ignore_empty();
		}

		// variadic feature, the '...' operator
		$is_variadic = false;
		if ($token[0] === T_ELLIPSIS) {
			$is_variadic = true;
			$token = $this->expect_typed_token_ignore_empty();
		}

		// &
		$inout_mode = $token === _REFERENCE
			|| $token[0] === T_AMPERSAND_FOLLOWED_BY_VAR_OR_VARARG;
		if ($inout_mode) {
			$token = $this->expect_typed_token_ignore_empty();
		}

		$name = $this->get_var_name($token);

		$value = null;
		if ($this->skip_char_token(_ASSIGN)) {
			$value = $this->read_expression();
			if ($value === null) {
				throw $this->new_unexpected_error();
			}
		}

		$declar = new ParameterDeclaration($name, $declared_type, $value);
		if ($inout_mode) {
			$declar->is_inout = true;
			$declar->is_mutable = true;
		}

		$declar->noted_type = $noted_type;
		$declar->is_variadic = $is_variadic;
		$declar->pos = $this->pos;

		return $declar;
	}

	private function try_read_type_expression()
	{
		$token = $this->scan_token_ignore_empty();
		return $this->try_read_type_expression_with_token($token);
	}

	private function try_read_type_expression_with_token($token)
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

	// private function read_heredoc()
	// {
	// 	$items = [];
	// 	while (($token = $this->scan_token_ignore_empty())) {
	// 		if ($token[0] === T_END_HEREDOC) {
	// 			break;
	// 		}

	// 		$items[] = $token;
	// 	}

	// 	return $items;
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

	private function read_classkindred_identifier($token = null)
	{
		// NS1\NS2\Target
		// \NS1\NS2\Target

		if ($token === null) {
			$token = $this->scan_token_ignore_empty();
		}

		$components = $this->read_qualified_name_with($token);
		$identifier = $this->create_classkindred_identifier(array_pop($components), $components);
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

		$this->program->set_defer_check_identifier($identifier);

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

	private function try_read_noted_type()
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
				$type = $this->create_noted_type_identifier($name, $nullable);
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
		return $this->create_compatible_type_identifier($name, $nullable);
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

	private function create_noted_type_identifier(string $name, bool $nullable = false)
	{
		if (strpos($name, _DOT)) {
			$identifier = $this->create_dots_style_compound_type($name);
			$identifier->nullable = $nullable;
		}
		else {
			$identifier = $this->create_type_identifier($name, $nullable);
		}

		$identifier->pos = $this->pos;

		return $identifier;
	}

	private function create_dots_style_compound_type(string $names): IType
	{
		$names = explode(_DOT, $names);
		$name = array_shift($names);
		$type = $this->create_type_identifier($name);

		$i = 0;
		foreach ($names as $kind) {
			if ($i === _STRUCT_DIMENSIONS_MAX) {
				throw $this->new_parse_error('The dimensions of Array/Dict exceeds, the max is ' . _STRUCT_DIMENSIONS_MAX);
			}

			if ($kind === _DOT_SIGN_ARRAY) {
				$type = TypeFactory::create_array_type($type);
			}
			elseif ($kind === _DOT_SIGN_DICT) {
				$type = TypeFactory::create_dict_type($type);
			}
			elseif ($kind === _DOT_SIGN_METATYPE) {
				$type = TypeFactory::create_meta_type($type);
			}
			else {
				throw $this->new_unexpected_error();
			}

			$i++;
		}

		return $type;
	}

	protected function expect_statement_end()
	{
		return $this->expect_char_token(_SEMICOLON);
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

	private function read_qualified_name_with($token)
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

		return substr($token[1], 1); // remove the prefix '$'
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

	private function expect_member_identifier_name()
	{
		$token = $this->scan_token_ignore_empty();
		$this->assert_member_identifier_token($token);
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

	private function assert_member_identifier_token($token)
	{
		if (is_string($token) || !in_array($token[0], self::MEMBER_IDENTIFIER_TOKEN_TYPES, true) ) {
			throw $this->new_unexpected_error();
		}
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
