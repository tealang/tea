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
	public const SOURCE_DIALECT = Program::SOURCE_DIALECT_PHP;

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
		'never' => _VOID,
		'null' => _NONE,
		'mixed' => _MIXED,
		'string' => _STRING,
		'text' => _TEXT_TYPE,
		'plain' => _PLAIN,
		'int' => _INT,
		'integer' => _INT,
		'float' => _FLOAT,
		'double' => _FLOAT,
		'bool' => _BOOL,
		'boolean' => _BOOL,
		'true' => _BOOL,
		'false' => _BOOL,
		'array' => _GENERAL_ARRAY,
		'dict' => _DICT,  // using in noted
		'iterable' => _ITERABLE,
		'callable' => _CALLABLE,
		'object' => _OBJECT,
		'self' => _TYPE_SELF,
		'static' => _TYPE_SELF,
	];

	private const TEA_EXTENSION_TYPE_NAMES = [
		'text' => true,
		'plain' => true,
		'dict' => true,
	];

	private const TYPING_TOKEN_TYPES = [
		T_STRING,
		T_ARRAY,
		T_CALLABLE,
		T_STATIC,
		T_NAME_QUALIFIED,
		T_NAME_FULLY_QUALIFIED,
		T_NAME_RELATIVE
	];

	protected const METHOD_MAP = [
	];

	private const PREFIX_OPERATORS = [_EXCLAMATION, _NEGATION, _IDENTITY, _BITWISE_NOT];

	private const EXPR_STOPPING_SIGNS = [_PAREN_CLOSE, _BRACKET_CLOSE, _BLOCK_END, _COMMA, _SEMICOLON];

	private const NORMAL_IDENTIFIER_TOKEN_TYPES = [T_STRING, T_ARRAY, T_STATIC, T_USE, T_UNSET, T_PRINT, T_NEW, T_CLONE, T_AS, T_YIELD, T_EMPTY, T_EVAL, T_INCLUDE, T_NAME_QUALIFIED, T_NAME_FULLY_QUALIFIED, T_NAME_RELATIVE];
	private const CLASS_MEMBER_IDENTIFIER_TOKEN_TYPES = [T_STRING, T_ARRAY, T_STATIC, T_USE, T_UNSET, T_PRINT, T_NEW, T_CLONE, T_AS, T_YIELD, T_EMPTY, T_EVAL, T_INCLUDE, T_LIST, T_CLASS, T_INTERFACE, T_TRAIT, T_NAMESPACE, T_FUNCTION, T_CONST, T_VAR, T_PUBLIC, T_PROTECTED, T_PRIVATE, T_ABSTRACT, T_FINAL, T_READONLY, T_INSTEADOF, T_DEFAULT, T_LOGICAL_AND, T_LOGICAL_OR, T_LOGICAL_XOR, T_NAME_QUALIFIED, T_NAME_FULLY_QUALIFIED, T_NAME_RELATIVE];

	private const SUPER_GLOBAL_NAMES = ['$_GET', '$_POST', '$_FILE', '$_COOKIE', '$_REQUEST', '$GLOBALS'];

	/**
	 * @var NamespaceIdentifier
	 */
	private $namespace;

	private $current_following_comment;

	private bool $last_noted_type_explicit_nullable = false;

	public function read_program(): Program
	{
		$this->is_declare_mode = false;

		$max_pos = $this->tokens_count - 1;

		$this->program->is_native = true;

		while ($this->pos < $max_pos) {
			$item = $this->read_root_statement();
			// if ($item instanceof RootDeclaration) {
			// 	$this->program->append_declaration($item);
			// }
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

	private function read_root_statement(array $attributes = [], ?string $doc = null)
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
			case T_DECLARE:
				$this->read_declare_directive();
				$node = null;
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

	private function read_declare_directive(): void
	{
		$this->skip_balanced_tokens(_PAREN_OPEN, _PAREN_CLOSE);
		$this->expect_statement_end();
	}

	private function skip_balanced_tokens(string $open, string $close): void
	{
		$this->expect_char_token($open);
		$depth = 1;

		while ($depth > 0) {
			$token = $this->scan_token_ignore_empty();
			if ($token === null) {
				throw $this->new_parse_error("Expected token \"$close\".");
			}

			if ($token === $open) {
				$depth++;
			}
			elseif ($token === $close) {
				$depth--;
			}
		}
	}

	protected function read_inner_statement(array $attributes = [], ?string $doc = null): ?IStatement
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
				$node = $this->read_inner_statement($attributes, $doc);
				break;
			case T_DOC_COMMENT:
				$node = $this->read_inner_statement($attributes, $token[1]);
				break;
			default:
				$node = $this->read_normal_statement_with_token($token, $attributes, $doc);
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

	private function read_normal_statement_with_token(array|string $token, array $attributes, ?string $doc = null): IStatement
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
				$node = $this->read_function_declaration($doc);
				break;
			case T_CLASS:
				$node = $this->read_class_declaration($attributes);
				break;
			case T_ABSTRACT:
				$this->expect_typed_token(T_CLASS);
				$node = $this->read_class_declaration($attributes);
				$node->is_abstract = true;
				break;
			case T_FINAL:
				$this->expect_typed_token(T_CLASS);
				$node = $this->read_class_declaration($attributes);
				$node->is_final = true;
				break;
			case T_READONLY:
				$this->expect_typed_token(T_CLASS);
				$node = $this->read_class_declaration($attributes);
				$node->is_readonly = true;
				break;
			case T_ENUM:
				$node = $this->read_enum_declaration($attributes);
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
			case T_PRINT:
				$node = $this->read_print_statement();
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
			case T_GOTO:
				$node = $this->read_goto_statement();
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
				if (is_array($token) && $token[0] === T_STRING && $this->skip_char_token(_COLON)) {
					$node = $this->read_label_statement_with($token[1]);
				}
				else {
					$node = $this->read_expression_statement_with($token);
				}
				break;
		}

		return $node;
	}

	private function read_goto_statement(): GotoStatement
	{
		$label = $this->expect_identifier_name();
		$this->expect_statement_end();
		return $this->factory->create_goto_statement($label);
	}

	private function read_label_statement_with(string $label): LabelStatement
	{
		return $this->factory->create_label_statement($label);
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

		$import_kind = UseDeclaration::IMPORT_CLASS;

		// support `use function Foo\bar;` / `use const Foo\BAR;`
		if (is_array($token) && $token[0] === T_FUNCTION) {
			$import_kind = UseDeclaration::IMPORT_FUNCTION;
			$token = $this->scan_token_ignore_empty();
		}
		elseif (is_array($token) && $token[0] === T_CONST) {
			$import_kind = UseDeclaration::IMPORT_CONST;
			$token = $this->scan_token_ignore_empty();
		}

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
		$ns = null;

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
					$targets = $this->read_use_targets($ns, $import_kind);
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
				$target = $this->factory->append_use_target($ns, $alias_name, $name, $import_kind);
			}
			else {
				$target = $this->factory->append_use_target($ns, $name, null, $import_kind);
			}

			$targets = [$target];
		}

		$statement = $this->create_use_statement_when_not_exists($ns, $targets);

		$this->expect_statement_end();

		return $statement;
	}

	private function read_use_targets(NamespaceIdentifier $ns, string $import_kind): array
	{
		$targets = [];
		while ($token = $this->scan_token_ignore_empty()) {
			$name = $this->get_identifier_name($token);
			$next = $this->get_token_ignore_empty();

			if (is_array($next) && $next[0] === T_AS) {
				$this->scan_token_ignore_empty(); // skip `as`
				$alias = $this->expect_identifier_name();
				$target = $this->factory->append_use_target($ns, $alias, $name, $import_kind);
			}
			else {
				$target = $this->factory->append_use_target($ns, $name, null, $import_kind);
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
		// continue(target_layers)

		$statement = $this->factory->create_continue_statement();

		$target_layers = $this->read_loop_control_target_layers();
		if ($target_layers !== null) {
			$statement->target_layers = $target_layers;
		}

		$this->expect_statement_end();
		$statement->pos = $this->pos;

		return $statement;
	}

	private function read_break_statement()
	{
		// break
		// break target_layers
		// break(target_layers)

		$statement = $this->factory->create_break_statement();

		$target_layers = $this->read_loop_control_target_layers();
		if ($target_layers !== null) {
			$statement->target_layers = $target_layers;
		}

		$this->expect_statement_end();
		$statement->pos = $this->pos;

		return $statement;
	}

	private function read_loop_control_target_layers(): ?int
	{
		if ($this->skip_char_token(_PAREN_OPEN)) {
			$token = $this->expect_typed_token_ignore_empty(T_LNUMBER);
			$this->expect_paren_close();
			return (int)$token[1];
		}

		$token = $this->scan_typed_token_ignore_empty(T_LNUMBER);
		return $token !== null ? (int)$token[1] : null;
	}

	private function read_exit_statement()
	{
		// exit
		// exit($argument)

		$argument = null;
		if ($this->skip_char_token(_PAREN_OPEN)) {
			if ($this->get_token_ignore_empty() !== _PAREN_CLOSE) {
				$argument = $this->read_expression();
			}
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

	protected function read_print_statement()
	{
		$argument = $this->read_expression();
		$this->expect_statement_end();

		$statement = new EchoStatement([$argument]);
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
		if ($token === null) {
			return;
		}

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
			if ($token === null) {
				break;
			}
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
		if ($token === null || is_string($token)) {
			$this->back_skiped_comments();
			return;
		}
		$token_type = $token[0];

		if ($token_type === T_CATCH) {
			$this->scan_token_ignore_empty();
			$this->expect_paren_open();

			$type_token = $this->expect_typed_token_ignore_empty();
			$type = $this->read_type_expression_with_token($type_token);
			if ($type === null) {
				throw $this->new_unexpected_error();
			}
			$var_name = $this->get_token_ignore_empty() === _PAREN_CLOSE
				? null
				: $this->expect_variable_name();

			$this->expect_paren_close();

			$sub_block = $this->factory->create_catch_block($var_name, $type);
			$this->read_body_for_control_block($sub_block);
			$main_block->add_catching_block($sub_block);

			// if ($type->name === _BASE_EXCEPTION && $type->ns?->is_global_space()) {
			// 	$main_block->catching_all = $sub_block;
			// }

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

	private function read_match_block(?string $label = null)
	{
		$this->expect_paren_open();
		$argument = $this->read_expression();
		$this->expect_paren_close();

		$block = $this->factory->create_match_block($argument);
		$block->label = $label;
		$block->pos = $this->pos;

		$arms = $this->read_match_arms();
		$block->set_arms($arms);

		return $block;
	}

	private function read_match_arms()
	{
		$this->expect_block_begin();

		$items = [];
		while (true) {
			$this->skip_comments();
			$patterns = $this->scan_patterns();
			if (!$patterns) {
				break;
			}

			$item = $this->factory->create_match_arm($patterns);
			$this->skip_comments();
			$this->expect_typed_token_ignore_empty(T_DOUBLE_ARROW); // Validate token type
			$item->return = $this->read_expression();
			$items[] = $item;

			if (!$this->skip_char_token(_COMMA)) {
				break;
			}
		}

		$this->expect_block_end();

		return $items;
	}

	protected function scan_patterns()
	{
		$items = [];
		while ($item = $this->scan_expression()) {
			$items[] = $item;
			if (!$this->skip_comma()) {
				break;
			}
		}

		// the 'default' pattern
		// if ($this->skip_typed_token(T_DEFAULT)) {
		// 	if ($items) {
		// 		throw $this->new_unexpected_error();
		// 	}

		// 	$item = new DefaultPattern();
		// 	$item->pos = $this->pos;
		// 	$items[] = $item;
		// }

		return $items;
	}

	private function read_switch_block(?string $label = null)
	{
		$this->expect_paren_open();
		$argument = $this->read_expression();
		$this->expect_paren_close();

		$block = $this->factory->create_switch_block($argument);
		$block->label = $label;
		$block->pos = $this->pos;

		$branches = $this->read_switch_branches();
		$block->set_branches($branches);

		return $block;
	}

	private function read_switch_branches()
	{
		$this->expect_block_begin();

		$items = [];
		$case_type = $this->scan_case_token();

		while ($case_type) {
			$patterns = $this->read_switch_branch_patterns($case_type);
			$branch = $this->factory->create_switch_branch($patterns);

			$stmts = [];
			if ($this->skip_block_begin()) {
				while ($stmt = $this->read_inner_statement()) {
					$stmts[] = $stmt;
				}

				$this->expect_block_end();
				$this->skip_comments();
				$case_type = $this->scan_case_token();
			}
			else {
				while ($stmt = $this->read_inner_statement()) {
					$stmts[] = $stmt;
					$this->skip_comments();
					$case_type = $this->scan_case_token();
					if ($case_type) {
						break;
					}
				}
			}

			$branch->set_body_with_statements($stmts);
			$items[] = $branch;
		}

		$this->expect_block_end();

		return $items;
	}

	private function scan_case_token()
	{
		$this->skip_comments();
		$next = $this->get_token_ignore_empty();
		$type = is_string($next) ? 0 : $next[0];

		$skiped = $type === T_CASE
			? T_CASE
			: ($type === T_DEFAULT ? T_DEFAULT : 0);

		if ($skiped) {
			$this->scan_token_ignore_empty();
		}
		else {
			$this->back_skiped_comments();
		}

		return $skiped;
	}

	private function read_switch_branch_patterns(int $case_type)
	{
		$items = [];
		do {
			$items[] = $case_type === T_CASE
				? $this->read_expression()
				: null;

			$this->expect_char_token(_COLON);
			$case_type = $this->scan_case_token();
		}
		while ($case_type);

		return $items;
	}

	private function read_foreach_block(?string $label = null)
	{
		$this->expect_paren_open();
		$iterable = $this->read_expression();

		$this->expect_typed_token_ignore_empty(_AS); // Validate token type
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

	private function read_for_block(?string $label = null)
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

	private function read_while_block(?string $label = null)
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

	private function read_do_while_block(?string $label = null)
	{
		// e.g. while test_expression {}

		$block = $this->factory->create_do_while_block();
		$block->label = $label;
		$block->pos = $this->pos;

		$this->read_body_for_control_block($block);

		$this->expect_typed_token_ignore_empty(T_WHILE); // Validate token type

		$this->expect_paren_open();
		$block->condition = $this->read_expression();
		$this->expect_paren_close();

		$this->factory->end_mandatory_block($block);

		return $block;
	}

// ---

	protected function read_expression(?Operator $prev_op = null): BaseExpression
	{
		$this->skip_comments();
		$token = $this->scan_expr_token_ignore_empty();
		if ($token === null) {
			throw $this->new_unexpected_error();
		}

		$expr = $this->read_expression_with_token($token, $prev_op);
		return $expr;
	}

	protected function scan_expression(?Operator $prev_op = null): ?BaseExpression
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

	private function read_expression_with_token(string|array $token, ?Operator $prev_op = null): BaseExpression
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
			case T_STATIC:
				if ($this->skip_typed_token(T_FUNCTION)) {
					$expr = $this->read_anonymous_function_expression(true);
					break;
				}

				if ($this->skip_typed_token(T_FN)) {
					$expr = $this->read_lambda_expression(true);
					break;
				}

				$expr = $this->scan_identifiable_expression_with_token($token);
				if ($expr === null) {
					throw $this->new_unexpected_error();
				}
				break;
			case T_STRING_CAST:
			case T_INT_CAST:
			case T_DOUBLE_CAST:
			case T_BOOL_CAST:
			case T_ARRAY_CAST:
			case T_OBJECT_CAST:
			case T_UNSET_CAST:
				$expr = $this->read_cast_operation_with_token($token);
				break;
			case T_LIST:
				$expr = $this->read_list_destructuring_assignment($token);
				break;
			case T_START_HEREDOC:
				$expr = $this->read_interpolated_string(T_END_HEREDOC);
				break;
			case T_MATCH:
				$expr = $this->read_match_block();
				break;
			case T_THROW:
				$argument = $this->read_expression();
				$expr = $this->factory->create_throw_expression($argument);
				$expr->pos = $this->pos;
				return $expr;
			case T_YIELD_FROM:
				$argument = $this->read_expression();
				$expr = $this->factory->create_yield_expression($argument, true);
				$expr->pos = $this->pos;
				return $expr;
			case T_YIELD:
				$is_from = false;
				$this->skip_comments();
				$next = $this->get_token_ignore_empty();
				if (is_array($next) && $next[0] === T_STRING && strtolower($next[1]) === _FROM) {
					$this->scan_token_ignore_empty();
					$is_from = true;
				}

				$argument = $this->read_expression();
				$expr = $this->factory->create_yield_expression($argument, $is_from);
				$expr->pos = $this->pos;
				return $expr;
			case T_DEFAULT:
				$expr = new DefaultPattern();
				$expr->pos = $this->pos;
				return $expr;
			case T_INCLUDE_ONCE:
			case T_INCLUDE:
			case T_REQUIRE_ONCE:
			case T_REQUIRE:
				// include expression do not supported any combination
				return $this->read_include_expression_with_token($token);
			case T_LINE:
			case T_FILE:
			case T_DIR:
			case T_CLASS_C:
			case T_FUNC_C:
			case T_METHOD_C:
			case T_NS_C:
			case T_TRAIT_C:
				$expr = $this->factory->create_identifier($token_content);
				$expr->pos = $this->pos;
				break;
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

	private function read_cast_operation_with_token(array $token)
	{
		$type = TypeFactory::get_for_casting_token_id($token[0]);
		$expr = $this->read_expression(OperatorFactory::$as);
		$expr = new CastOperation($expr, $type);
		$expr->pos = $this->pos;
		return $expr;
	}

	private function read_list_destructuring_assignment(array $token)
	{
		$this->expect_paren_open();

		$items = $this->read_destructuring_items(_PAREN_CLOSE);
		$left = $this->factory->create_destructuring($items);
		$left->pos = $this->pos;

		$this->expect_paren_close();
		$this->expect_char_token(_ASSIGN);

		$right = $this->read_expression();
		$expr = $this->factory->create_assignment($left, $right, OperatorFactory::$assignment);
		$expr->pos = $this->pos;

		return $expr;
	}

	private function read_destructuring_items(string $ending): array
	{
		$items = [];
		while (true) {
			$this->skip_comments();
			$token = $this->get_token_ignore_empty();
			if ($token === $ending) {
				break;
			}

			if ($token === _COMMA) {
				$this->scan_token_ignore_empty();
				$items[] = null;
				continue;
			}

			$item = $this->read_expression();
			if ($this->skip_typed_token(T_DOUBLE_ARROW)) {
				$value = $this->read_expression();
				$item = new DictMember($item, $value);
				$item->pos = $this->pos;
			}

			$items[] = $item;
			$this->skip_comments();
			if (!$this->skip_char_token(_COMMA)) {
				break;
			}
		}

		return $items;
	}

	protected function read_instancing_expression()
	{
		// Check for anonymous class first: new class (args) { ... }
		$this->skip_comments();
		$token = $this->scan_token();
		if ($token[0] === T_CLASS) {
			return $this->read_anonymous_class_instancing();
		}
		
		// Put the token back and continue with normal instancing
		// We need to re-parse this token as part of the identifiable
		$this->back();
		
		$identifiable = $this->read_expression(OperatorFactory::$new);
		if ($identifiable instanceof CallExpression) {
			$args = $identifiable->arguments;
			$named_args = $identifiable->named_arguments;
			$identifiable = $identifiable->callee;
		}
		else {
			$args = [];
			$named_args = [];
		}

		if ($identifiable instanceof PlainIdentifier && $identifiable->name === _THIS) {
			$identifiable = $this->create_classkindred_identifier(_TYPE_SELF);
		}

		$expr = new InstancingExpression($identifiable, $args, $named_args);
		$expr->pos = $this->pos;
		return $expr;
	}

	protected function read_anonymous_class_instancing(): InstancingExpression
	{
		// T_CLASS already consumed by read_instancing_expression
		// Check for constructor arguments: new class(args) { ... }
		$args = [];
		$this->skip_comments();
		$token = $this->get_token_ignore_empty();
		if ($token === _PAREN_OPEN) {
			$this->scan_token(); // consume (
			$scanned = $this->scan_arguments_with_names();
			$args = $scanned[0];
			$named_args = $scanned[1];
			$this->expect_paren_close();
		}
		else {
			$named_args = [];
		}
		
		$anonymous_class = $this->read_anonymous_class_declaration_body($args);
		
		// Create a placeholder identifiable for the anonymous class
		$identifiable = new PlainIdentifier('class@anonymous');
		$identifiable->pos = $this->pos;
		
		$expr = new InstancingExpression($identifiable, $args, $named_args);
		$expr->anonymous_class = $anonymous_class;
		$expr->pos = $this->pos;
		return $expr;
	}

	protected function read_anonymous_class_declaration_body(array $arguments): ClassDeclaration
	{
		// T_CLASS already consumed, parse the rest: (extends/implements) { members }
		$extends = null;
		$implements = [];

		// Parse extends clause
		$this->skip_comments();
		$token = $this->get_token_ignore_empty();
		if (is_array($token) && $token[0] === T_EXTENDS) {
			$this->scan_token();
			$extends = $this->read_expression();
		}

		// Parse implements clause
		$this->skip_comments();
		$token = $this->get_token_ignore_empty();
		if (is_array($token) && $token[0] === T_IMPLEMENTS) {
			$this->scan_token();
			$implements = $this->read_implements_list();
		}

		// Create the anonymous class declaration
		$decl = new ClassDeclaration(null, '');
		$decl->is_anonymous = true;
		$decl->pos = $this->pos;
		
		if ($extends) {
			$decl->extends = [$extends];
		}
		$decl->implements = $implements;

		// Set up class context for member parsing
		$this->factory->begin_class($decl);
		
		// Parse class body
		$this->expect_block_begin();
		$members = $this->read_class_members();
		$this->expect_block_end();
		
		$decl->members = $members;

		// Clean up class context
		$this->factory->end_class();

		return $decl;
	}

	protected function read_implements_list(): array
	{
		$interfaces = [];
		while ($interface = $this->read_expression()) {
			$interfaces[] = $interface;
			$this->skip_comments();
			$token = $this->get_token();
			if ($token !== ',') {
				break;
			}
			$this->scan_token();
		}
		return $interfaces;
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

	protected function scan_arguments(): array
	{
		return $this->scan_arguments_with_names()[0];
	}

	protected function scan_arguments_with_names(): array
	{
		$positional = [];
		$named = [];
		
		while (true) {
			// Check for named argument: name: expression
			$this->skip_comments();
			$token = $this->get_token_ignore_empty();
			
			// Look ahead for identifier followed by colon
			if (is_array($token) && in_array($token[0], [T_STRING, T_NAME_FULLY_QUALIFIED, T_NAME_QUALIFIED])) {
				$name = $token[1];
				$this->scan_token(); // consume identifier
				
				$this->skip_comments();
				$next = $this->get_token_ignore_empty();
				
				if ($next === _COLON) {
					$this->scan_token(); // consume colon
					$value = $this->scan_expression();
					$named[] = new NamedArgument($name, $value);
				} else {
					// Not a named argument, put token back and parse as expression
					$this->back();
					$item = $this->scan_expression();
					if ($item) {
						$positional[] = $item;
					}
				}
			} else {
				// Spread operator or regular expression
				$item = $this->scan_expression();
				if ($item) {
					$positional[] = $item;
				}
			}
			
			if (!$this->skip_comma()) {
				break;
			}
		}

		return [$positional, $named];
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
		$token_type = is_string($token) ? $token : $token[0];
		switch ($token_type) {
			case _DOLLAR:
				$expr = $this->read_dynamic_variable_identifier();
				break;
			case T_VARIABLE:
				$expr = $this->create_variable_identifier_with_token($token);
				break;
			case T_LINE:
			case T_FILE:
			case T_DIR:
			case T_EMPTY:
			case T_EVAL:
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

	private function read_dynamic_variable_identifier(): VariableIdentifier
	{
		$token = $this->read_expr_token_ignore_empty();

		if ($token === _DOLLAR) {
			$identifier = $this->read_dynamic_variable_identifier();
			$name = _DOLLAR . $identifier->name;
		}
		elseif (is_array($token) && $token[0] === T_VARIABLE) {
			$name = _DOLLAR . $token[1];
		}
		else {
			throw $this->new_unexpected_error();
		}

		$identifier = $this->factory->create_variable_identifier($name);
		$identifier->pos = $this->pos;
		return $identifier;
	}

	private function read_normal_identifier_with_token(array $token)
	{
		if ($token[0] === T_NAME_QUALIFIED || $token[0] === T_NAME_FULLY_QUALIFIED || $token[0] === T_NAME_RELATIVE) {
			[$ns, $name] = $this->read_namespace_and_name($token);
			$expr = $this->factory->create_plain_identifier($name);
			$ns && $expr->ns = $ns;
			$expr->pos = $this->pos;
			return $expr;
		}

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
				case T_DOLLAR_OPEN_CURLY_BRACES:
					$name_token = $this->scan_token();
					if (!is_array($name_token) || $name_token[0] !== T_STRING_VARNAME) {
						throw $this->new_unexpected_error();
					}
					$item = $this->factory->create_variable_identifier(_DOLLAR . $name_token[1]);
					$this->expect_char_token(_BRACE_CLOSE);
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

	protected function read_expression_combination(BaseExpression $expr, ?Operator $prev_op = null)
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
			case T_EMPTY:
			case T_EVAL:
			case T_INCLUDE:
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
			case T_VARIABLE:
				$name = $this->get_property_name($token);
				break;
			case T_PRINT:
			case T_NEW:
			case T_CLONE:
			case T_AS:
			case T_YIELD:
			case T_EMPTY:
			case T_EVAL:
			case T_INCLUDE:
			case T_PUBLIC:
			case T_PROTECTED:
			case T_PRIVATE:
			case T_STATIC:
			case T_ABSTRACT:
			case T_FINAL:
			case T_READONLY:
			case T_DEFAULT:
			case T_STRING:
				$name = $token[1];
				// $name = static::METHOD_MAP[$name] ?? $name;
				break;
			case T_CLASS:
				$name = strtolower($token[1]);
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
		if ($this->is_first_class_callable_argument_list()) {
			$this->skip_typed_token(T_ELLIPSIS);
			$this->expect_paren_close();
			$callable = new FirstClassCallableExpression($handler);
			$callable->pos = $this->pos;
			return $callable;
		}

		$scanned = $this->scan_arguments_with_names();
		$args = $scanned[0];
		$named_args = $scanned[1];
		$this->expect_paren_close();

		foreach ($args as $arg) {
			if ($arg instanceof VariableIdentifier) {
				$var_name = $arg->name;
				if ($arg->symbol === null && !in_array($var_name, self::SUPER_GLOBAL_NAMES, true)) {
					$this->factory->create_variable_declaration_for_identifier($arg);
				}
			}
		}

		$call = new CallExpression($handler, $args, $named_args);
		$call->pos = $this->pos;

		return $call;
	}

	private function is_first_class_callable_argument_list(): bool
	{
		$pos = $this->pos;
		$token = $this->scan_token_ignore_empty();
		$is_first_class_callable = is_array($token)
			&& $token[0] === T_ELLIPSIS
			&& $this->get_token_ignore_empty() === _PAREN_CLOSE;
		$this->pos = $pos;

		return $is_first_class_callable;
	}

	protected function scan_combinated_operator(?Operator $prev_op = null)
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

	private function read_bracket_expression_with_token(string|array|null $token = null)
	{
		$is_bracket = $token === _BRACKET_OPEN;
		$ending = $is_bracket ? _BRACKET_CLOSE : _PAREN_CLOSE;
		if (!$is_bracket) {
			$this->expect_paren_open();
		}

		$is_const_value = true;
		$is_dict = false;
		$members = [];
		while (true) {
			$this->skip_comments();
			$next = $this->get_token_ignore_empty();
			if ($next === $ending) {
				break;
			}

			if ($is_bracket && $next === _COMMA) {
				$this->scan_token_ignore_empty();
				$members[] = null;
				continue;
			}

			$item = $this->read_expression();
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

			$this->skip_comments();

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
			$identifiers = $this->read_type_reference_list();
			$decl->set_extends($identifiers);
		}

		$this->expect_block_begin();

		while ($this->read_interface_member());

		$this->expect_block_end();
		$this->factory->end_class();

		return $decl;
	}

	private function read_class_declaration(array $attributes)
	{
		$name = $this->expect_identifier_name();
		$decl = $this->factory->create_class_declaration($name, _PUBLIC, $this->namespace);
		$decl->pos = $this->pos;
		$decl->attributes = $attributes;

		if ($this->skip_typed_token(T_EXTENDS)) {
			$identifiers = $this->read_type_reference_list();
			$decl->set_extends($identifiers);
		}

		if ($this->skip_typed_token(T_IMPLEMENTS)) {
			$identifiers = $this->read_type_reference_list();
			$decl->set_implements($identifiers);
		}

		$this->expect_block_begin();

		$this->read_class_members();

		$this->expect_block_end();
		$this->factory->end_class();

		return $decl;
	}

	private function read_enum_declaration(array $attributes)
	{
		$name = $this->expect_identifier_name();
		$decl = $this->factory->create_enum_declaration($name, _PUBLIC, $this->namespace);
		$decl->pos = $this->pos;
		$decl->attributes = $attributes;

		if ($this->skip_char_token(_COLON)) {
			$decl->value_type = $this->read_simple_type_identifier();
		}

		if ($this->skip_typed_token(T_IMPLEMENTS)) {
			$identifiers = $this->read_type_reference_list();
			$decl->set_implements($identifiers);
		}

		$this->expect_block_begin();

		$this->read_class_members();

		// the default property for backed enum
		if ($decl->value_type) {
			$value_prop = $this->factory->create_property_declaration(_PUBLIC, 'value');
			$value_prop->declared_type = $decl->value_type;
		}

		$this->expect_block_end();
		$this->factory->end_class();

		return $decl;
	}

	private function read_simple_type_identifier()
	{
		$name = $this->expect_identifier_name();
		return $this->create_type_identifier($name);
	}

	private function read_type_reference_list()
	{
		$items = [];
		do {
			$items[] = $this->read_type_reference();
		}
		while ($this->skip_char_token(_COMMA));

		return $items;
	}

	private function read_trait_declaration()
	{
		$name = $this->expect_identifier_name();

		$decl = $this->factory->create_trait_declaration($name, _PUBLIC, $this->namespace);
		$decl->pos = $this->pos;

		$this->expect_block_begin();

		$this->read_class_members();

		$this->expect_block_end();
		$this->factory->end_class();

		return $decl;
	}

	private function read_interface_member()
	{
		$attributes = [];
		$token = $this->get_token_ignore_empty();
		if (is_string($token)) {
			if ($token === _BLOCK_END) {
				return null;
			}

			$this->scan_token_ignore_empty();
			throw $this->new_unexpected_error();
		}

		$this->scan_token_ignore_empty();

		while (is_array($token) && $token[0] === T_ATTRIBUTE) {
			$attributes = $this->read_meta_attributes($attributes);
			$token = $this->expect_typed_token_ignore_empty();
		}

		$doc = null;
		if ($token[0] === T_DOC_COMMENT) {
			$doc = $token[1];
			$token = $this->expect_typed_token_ignore_empty();
		}

		while (is_array($token) && $token[0] === T_ATTRIBUTE) {
			$attributes = $this->read_meta_attributes($attributes);
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
		if (!empty($attributes)) {
			$decl->attributes = $attributes;
		}

		return $decl;
	}

	private function read_class_members(): array
	{
		$members = [];
		while ($member = $this->read_class_member()) {
			$members[] = $member;
		}

		return $members;
	}

	private function read_class_member()
	{
		$attributes = [];
		$token = $this->get_token_ignore_empty();
		if (is_string($token)) {
			if ($token === _BLOCK_END) {
				return null;
			}

			$this->scan_token_ignore_empty();
			throw $this->new_unexpected_error();
		}

		$this->scan_token_ignore_empty();

		while (is_array($token) && $token[0] === T_ATTRIBUTE) {
			$attributes = $this->read_meta_attributes($attributes);
			$token = $this->expect_typed_token_ignore_empty();
		}

		$doc = null;
		if ($token[0] === T_DOC_COMMENT) {
			$doc = $token[1];
			$token = $this->expect_typed_token_ignore_empty();
		}

		while (is_array($token) && $token[0] === T_ATTRIBUTE) {
			$attributes = $this->read_meta_attributes($attributes);
			$token = $this->expect_typed_token_ignore_empty();
		}

		$modifier = null;
		$is_readonly = false;
		$is_abstract = false;
		$is_static = false;

		// Scan all modifiers in any order
		while (true) {
			if (in_array($token[0], [T_PUBLIC, T_PROTECTED, T_PRIVATE], true)) {
				$modifier = $token[1];
				$token = $this->scan_token_ignore_empty();
			}
			elseif ($token[0] === T_READONLY) {
				$is_readonly = true;
				$token = $this->scan_token_ignore_empty();
			}
			elseif ($token[0] === T_ABSTRACT) {
				$is_abstract = true;
				$token = $this->scan_token_ignore_empty();
			}
			elseif ($token[0] === T_FINAL) {
				$token = $this->scan_token_ignore_empty();
			}
			elseif ($token[0] === T_STATIC) {
				$is_static = true;
				$token = $this->scan_token_ignore_empty();
			}
			else {
				break;
			}
		}

			if ($this->is_type_expression_start_token($token)) {
				$declared_type = $this->read_type_expression_with_token($token);
				$noted_type = $this->read_noted_type();
				$token = $this->expect_typed_token_ignore_empty();
				$member = $this->read_property_declaration($token, $modifier, $doc, $declared_type, $is_readonly);
				$member->is_static = $is_static;
				if ($noted_type) {
					ASTHelper::set_noted_type($member, $this->apply_declared_nullable_to_noted_type($member, $declared_type, $noted_type));
				}
				if (!isset($member->has_hooks) || !$member->has_hooks) {
					$this->expect_statement_end();
				}
		}
		else {
			switch ($token[0]) {
				case T_VAR:
					$token = $this->expect_typed_token_ignore_empty();
					// unbreak
				case T_VARIABLE:
					$member = $this->read_property_declaration($token, $modifier, $doc, null, $is_readonly);
					$member->is_static = $is_static;
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
					$member->attributes = $attributes;
					break;

				case T_CASE:
					if ($modifier or $is_static) {
						throw $this->new_unexpected_error();
					}

					$member = $this->read_enum_case_declaration($modifier);
					$this->expect_statement_end();
					break;

				case T_USE:
					$member = $this->read_traits_using_statement();
					break;

				case T_COMMENT:
					return $this->read_class_member();

				default:
					throw $this->new_unexpected_error();
			}
		}

		if (!empty($attributes) && !isset($member->attributes)) {
			$member->attributes = $attributes;
		}

		return $member;
	}

	private function read_traits_using_statement()
	{
		$items = [];
		$has_block = false;
		
		while (true) {
			$items[] = $this->read_type_reference();
			
			// Check what's next
			$this->skip_comments();
			$next = $this->get_token_ignore_empty();
			
			if ($next === _COMMA) {
				$this->scan_token_ignore_empty(); // consume comma
				continue;
			}
			elseif ($next === _BLOCK_BEGIN) {
				$has_block = true;
				break;
			}
			else {
				// No adaptation block
				break;
			}
		}

		// Check for trait adaptation block: { ... }
		$options = [];
		if ($has_block) {
			$this->scan_token(); // consume {
			$options = $this->read_trait_adaptations();
			$this->expect_block_end();
		}
		else {
			$this->expect_statement_end();
		}

		$node = $this->factory->create_traits_using_statement($items, $options);
		$node->pos = $this->pos;

		$this->factory->end_class_member();

		return $node;
	}

	private function read_trait_adaptations(): array
	{
		$adaptations = [];
		
		while (true) {
			$this->skip_comments();
			$token = $this->get_token_ignore_empty();
			
			if ($token === _BLOCK_END) {
				break;
			}
			
			// Parse adaptation: Trait::method insteadof OtherTrait
			// Or: Trait::method as [modifier] newname
			$method_ref = $this->read_method_reference();
			
			$this->skip_comments();
			$token = $this->get_token_ignore_empty();
			
			if (is_array($token) && $token[0] === T_INSTEADOF) {
				// insteadof adaptation
				$this->scan_token();
				$insteadof_traits = $this->read_trait_list();
				$adaptations[] = [
					'type' => 'insteadof',
					'method' => $method_ref,
					'traits' => $insteadof_traits,
				];
				$this->expect_statement_end();
			}
			elseif (is_array($token) && $token[0] === T_AS) {
				// as adaptation
				$this->scan_token();
				
				$modifier = null;
				$new_name = null;
				
				$this->skip_comments();
				$next = $this->get_token_ignore_empty();
				
				// Check for visibility modifier or new name
				if (is_array($next) && in_array($next[0], [T_PUBLIC, T_PROTECTED, T_PRIVATE], true)) {
					$modifier = $next[1];
					$this->scan_token();
					
					// Check if there's a new name after modifier
					$this->skip_comments();
					$after_modifier = $this->get_token_ignore_empty();
					if (is_array($after_modifier) && in_array($after_modifier[0], [T_STRING, T_STATIC], true)) {
						$new_name = $this->expect_identifier_name();
					}
				}
				elseif (is_array($next) && in_array($next[0], [T_STRING, T_STATIC], true)) {
					// Just a new name, no modifier
					$new_name = $this->expect_identifier_name();
				}
				
				$adaptations[] = [
					'type' => 'as',
					'method' => $method_ref,
					'modifier' => $modifier,
					'new_name' => $new_name,
				];
				$this->expect_statement_end();
			}
			else {
				throw $this->new_unexpected_error();
			}
		}
		
		return $adaptations;
	}

	private function read_method_reference(): array
	{
		// Parse Trait::method or just method name
		// First read the first identifier
		$first = $this->read_type_reference();
		
		$this->skip_comments();
		$next = $this->get_token_ignore_empty();
		
		if ($next === _DOUBLE_COLON || (is_array($next) && $next[0] === T_DOUBLE_COLON)) {
			// Trait::method format
			$this->scan_token(); // consume ::
			$method = $this->expect_identifier_name();
			return [$first, $method];
		}
		else {
			// Just method name (when trait is implied)
			// The first token was actually the method name
			return [null, $first->name];
		}
	}

	private function read_trait_list(): array
	{
		$traits = [];
		do {
			$traits[] = $this->read_expression();
		}
		while ($this->skip_char_token(_COMMA));
		
		return $traits;
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

	private function continue_reading_constant_decl(IConstantDeclaration $decl, ?string $doc)
	{
		if ($doc) {
			$noted_type = $this->get_type_in_doc($doc, 'var');
			if ($noted_type && $this->should_apply_phpdoc_noted_type($decl, $noted_type)) {
				ASTHelper::set_noted_type($decl, $noted_type);
				ASTHelper::set_noted_type_from_phpdoc($decl, true);
			}
		}

		$this->expect_char_token(_ASSIGN);
		$decl->value = $this->read_expression();
	}

	private function get_type_in_doc(?string $doc, string $kind)
	{
		$tag = $this->get_phpdoc_tag_content($doc, $kind);
		if ($tag === null) {
			return null;
		}

		[$type, $_] = $this->extract_phpdoc_type_and_rest($tag);
		return $type === null ? null : $this->create_phpdoc_noted_type_expr($type);
	}

	private function apply_phpdoc_param_types(array $params, ?string $doc): void
	{
		if ($doc === null) {
			return;
		}

		$types = $this->get_param_types_in_doc($doc);
		if ($types === []) {
			return;
		}

		foreach ($params as $param) {
			$name = ltrim($param->name, '$');
			if (isset($types[$name])) {
				$this->apply_phpdoc_param_type($param, $types[$name]);
			}
			elseif (isset($types[$param->name])) {
				$this->apply_phpdoc_param_type($param, $types[$param->name]);
			}
		}
	}

	private function apply_phpdoc_param_type(ParameterDeclaration $param, BaseType $type): void
	{
		if ($this->is_unknown_phpdoc_alias_for_native_array_param($param, $type)) {
			return;
		}

		if (!$this->should_apply_phpdoc_noted_type($param, $type)) {
			return;
		}

		ASTHelper::set_noted_type($param, $this->apply_declared_nullable_to_noted_type($param, $param->declared_type, $type));
		ASTHelper::set_noted_type_from_phpdoc($param, true);
	}

	private function should_apply_phpdoc_noted_type(BaseDeclaration $decl, ?BaseType $type): bool
	{
		if (!$type instanceof BaseType) {
			return false;
		}

		if (ASTHelper::get_noted_type($decl) instanceof BaseType && ASTHelper::is_noted_type_trusted_contract($decl)) {
			return false;
		}

		if ($decl->declared_type === null) {
			return true;
		}

		return ($this->is_native_array_declared_type($decl->declared_type)
				&& $this->is_precise_phpdoc_array_type($type))
			|| ($decl->declared_type instanceof TypeReference
				&& $this->is_precise_phpdoc_class_type($type));
	}

	private function is_precise_phpdoc_array_type(BaseType $type): bool
	{
		if (($type instanceof ArrayType || $type instanceof DictType) && $type->generic_type instanceof BaseType) {
			return true;
		}

		if (!$type instanceof UnionType) {
			return false;
		}

		foreach ($type->get_members() as $member) {
			if ($this->is_precise_phpdoc_array_type($member)) {
				return true;
			}
		}

		return false;
	}

	private function is_precise_phpdoc_class_type(BaseType $type): bool
	{
		if ($type instanceof TypeReference) {
			return true;
		}

		if (!$type instanceof UnionType) {
			return false;
		}

		foreach ($type->get_members() as $member) {
			if (!$this->is_precise_phpdoc_class_type($member)) {
				return false;
			}
		}

		return $type->get_members() !== [];
	}

	private function is_unknown_phpdoc_alias_for_native_array_param(ParameterDeclaration $param, BaseType $type): bool
	{
		return $this->is_native_array_declared_type($param->declared_type)
			&& $this->is_unknown_phpdoc_alias_type($type);
	}

	private function is_native_array_declared_type(?BaseType $type): bool
	{
		if ($type instanceof ArrayType || $type instanceof DictType) {
			return true;
		}

		if (!$type instanceof UnionType) {
			return false;
		}

		$members = $type->get_members();
		if ($members === []) {
			return false;
		}

		foreach ($members as $member) {
			if (!$member instanceof ArrayType && !$member instanceof DictType) {
				return false;
			}
		}

		return true;
	}

	private function get_param_types_in_doc(string $doc): array
	{
		$types = [];
		foreach ($this->get_phpdoc_tag_contents($doc, 'param') as $tag) {
			[$type, $rest] = $this->extract_phpdoc_type_and_rest($tag);
			if ($type === null || $rest === '') {
				continue;
			}

			if (preg_match('/^\$?([a-zA-Z_][a-zA-Z0-9_]*)\b/', $rest, $matches)) {
				$type_expr = $this->create_phpdoc_noted_type_expr($type);
				if ($type_expr) {
					$types[$matches[1]] = $type_expr;
				}
			}
		}

		return $types;
	}

	private function create_phpdoc_noted_type_expr(string $type): ?BaseType
	{
		if (!$this->is_supported_phpdoc_type_note($type)) {
			return null;
		}

		try {
			return $this->create_noted_type_expr($type);
		}
		catch (Exception $e) {
			return null;
		}
	}

	private function is_supported_phpdoc_type_note(string $type): bool
	{
		if (str_contains($type, '{') || str_contains($type, '}') || str_contains($type, '(') || str_contains($type, ')')) {
			return false;
		}

		$has_non_false_member = false;
		foreach ($this->split_phpdoc_type_list($type, '|') as $member) {
			$member = trim($member);
			if (str_starts_with($member, _QUESTION)) {
				$member = substr($member, 1);
			}

			if ($member === '' || !$this->is_supported_phpdoc_type_member($member)) {
				return false;
			}

			if (strtolower(ltrim($member, self::NS_SEPARATOR)) === 'false') {
				continue;
			}

			$has_non_false_member = true;
		}

		return $has_non_false_member;
	}

	private function is_supported_phpdoc_type_member(string $member): bool
	{
		return str_contains($member, '<')
			|| str_contains($member, '[]')
			|| preg_match('/^\\\\?[a-zA-Z_][a-zA-Z0-9_\\\\]*$/', $member);
	}

	private function is_phpdoc_builtin_type_member(string $member): bool
	{
		return isset(static::TYPE_MAP[strtolower(ltrim($member, self::NS_SEPARATOR))]);
	}

	private function get_phpdoc_tag_content(?string $doc, string $kind): ?string
	{
		return $this->get_phpdoc_tag_contents($doc, $kind)[0] ?? null;
	}

	private function get_phpdoc_tag_contents(?string $doc, string $kind): array
	{
		if ($doc === null) {
			return [];
		}

		$items = [];
		foreach (preg_split('/\R/', $doc) ?: [] as $line) {
			$line = trim($line);
			$line = preg_replace('/^\/\*\*?/', '', $line);
			$line = preg_replace('/\*\/$/', '', $line);
			$line = trim(preg_replace('/^\*\s?/', '', trim($line)));
			if (preg_match('/^@' . preg_quote($kind, '/') . '\s+(.+)$/', $line, $matches)) {
				$items[] = trim($matches[1]);
			}
		}

		return $items;
	}

	private function extract_phpdoc_type_and_rest(string $text): array
	{
		$text = trim($text);
		$type = '';
		$depth = 0;
		$length = strlen($text);
		for ($i = 0; $i < $length; $i++) {
			$char = $text[$i];
			if ($char === '<') {
				$depth++;
			}
			elseif ($char === '>' && $depth > 0) {
				$depth--;
			}
			elseif (ctype_space($char) && $depth === 0) {
				break;
			}

			$type .= $char;
		}

		$type = trim($type);
		if ($type === '') {
			return [null, ''];
		}

		$rest = trim(substr($text, strlen($type)));
		return [$this->normalize_phpdoc_type_note($type), $rest];
	}

	private function normalize_phpdoc_type_note(string $note): string
	{
		return preg_replace('/\s+/', '', $note);
	}

	private function read_enum_case_declaration()
	{
		$name = $this->expect_identifier_name();
		$decl = $this->factory->create_enum_case_declaration($name);
		$decl->pos = $this->pos;

		if ($this->skip_char_token(_ASSIGN)) {
			$decl->value = $this->read_expression();
		}

		$this->factory->end_class_member();

		return $decl;
	}

	private function read_class_constant_declaration(?string $modifier, ?string $doc)
	{
		$name = $this->expect_class_member_name();
		$decl = $this->factory->create_class_constant_declaration($modifier ?? _PUBLIC, $name);
		$decl->pos = $this->pos;

		$this->continue_reading_constant_decl($decl, $doc);

		$this->factory->end_class_member();

		return $decl;
	}

	private function read_property_declaration(array $token, string $modifier, ?string $doc, ?BaseType $type = null, bool $is_readonly = false)
	{
		$name = $this->get_property_name($token);
		$decl = $this->factory->create_property_declaration($modifier, $name);
		$decl->pos = $this->pos;

		if ($type) {
			$decl->declared_type = $type;
		}

		if ($doc) {
			$noted_type = $this->get_type_in_doc($doc, 'var');
			if ($noted_type && $this->should_apply_phpdoc_noted_type($decl, $noted_type)) {
				ASTHelper::set_noted_type($decl, $noted_type);
				ASTHelper::set_noted_type_from_phpdoc($decl, true);
			}
		}

		if ($is_readonly) {
			$decl->is_readonly = true;
		}

		// Check for PHP 8.4+ property hooks
		if ($this->get_token_ignore_empty() === _BLOCK_BEGIN) {
			$this->read_property_hooks_for($decl);
		}
		else {
			if ($this->skip_char_token(_ASSIGN)) {
				$decl->value = $this->read_expression();
			}
		}

		$this->factory->end_class_member();

		return $decl;
	}

	private function read_property_hooks_for($decl)
	{
		$this->expect_block_begin();
		
		$decl->has_hooks = true;
		$decl->hook_get = null;
		$decl->hook_set = null;
		
		while ($token = $this->get_token_ignore_empty()) {
			if ($token === _BLOCK_END) {
				break;
			}
			
			if (is_string($token)) {
				$this->scan_token_ignore_empty();
				continue;
			}
			
			if ($token[0] === T_STRING) {
				$hook_name = strtolower($token[1]);
				$this->scan_token_ignore_empty();
				
				if ($hook_name === 'get') {
					if ($this->skip_typed_token(T_DOUBLE_ARROW)) {
						$decl->hook_get = $this->read_expression();
						$this->expect_statement_end();
					}
					else {
						$this->skip_nested_block();
					}
				}
				elseif ($hook_name === 'set') {
					$decl->hook_set = 'block'; // placeholder
					$this->skip_nested_block();
				}
				else {
					$this->scan_token_ignore_empty();
				}
			}
			else {
				$this->scan_token_ignore_empty();
			}
		}
		
		$this->expect_block_end();
	}

	private function skip_nested_block(): void
	{
		$this->expect_block_begin();
		$brace_count = 1;
		while ($brace_count > 0 && $this->get_token_ignore_empty() !== null) {
			$token = $this->scan_token_ignore_empty();
			if ($token === _BLOCK_BEGIN) {
				$brace_count++;
			}
			elseif ($token === _BLOCK_END) {
				$brace_count--;
			}
		}
	}

	private function read_method_declaration(?string $modifier, ?string $doc, bool $is_abstract = false)
	{
		$this->skip_return_reference_marker();
		$name = $this->expect_class_member_name();
		$decl = $this->factory->create_method_declaration($modifier ?? _PUBLIC, $name);
		$decl->pos = $this->pos;

		$params = $this->read_parameters();
		$this->apply_phpdoc_param_types($params, $doc);
		
		// Handle PHP 8.0+ constructor property promotion
		if ($name === _CONSTRUCT) {
			foreach ($params as $param) {
				if ($param->promoted_property_modifier !== null) {
					$prop_name = $param->name;
					if ($prop_name[0] === '$') {
						$prop_name = substr($prop_name, 1);
					}
					
					$prop_type = $param->declared_type ?? ASTHelper::get_noted_type($param);
					$prop_decl = new PropertyDeclaration(
						$param->promoted_property_modifier,
						$prop_name,
						$prop_type
					);
					$prop_decl->value = $param->value;
					
					$symbol = new Symbol($prop_decl);
					$this->factory->append_class_member_symbol($symbol);
				}
			}
		}

		$this->factory->set_scope_parameters($params);

		$this->read_function_return_types_for($decl, $doc);

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
		$token = $this->scan_token_ignore_empty();
		while (is_array($token) and $token[0] === T_COMMENT) {
			$token = $this->scan_token_ignore_empty();
		}

		if (is_string($token) || !in_array($token[0], self::CLASS_MEMBER_IDENTIFIER_TOKEN_TYPES, true)) {
			throw $this->new_unexpected_error();
		}

		$name = $token[1];
		if (isset(static::METHOD_MAP[$name])) {
			$name = static::METHOD_MAP[$name];
		}

		return $name;
	}

	private function read_lambda_expression(bool $is_static = false)
	{
		$decl = $this->factory->create_anonymous_function();
		$decl->is_static = $is_static;
		$this->skip_return_reference_marker();
		$params = $this->read_parameters();
		$this->factory->set_scope_parameters($params);
		$decl->pos = $this->pos;

		$this->read_function_return_types_for($decl);

		$this->expect_typed_token_ignore_empty(T_DOUBLE_ARROW); // Validate token type

		$expr = $this->read_expression();
		$decl->set_body_with_expression($expr);

		$this->factory->end_block();

		return $decl;
	}

	private function read_anonymous_function_expression(bool $is_static = false)
	{
		$decl = $this->factory->create_anonymous_function();
		$decl->is_static = $is_static;
		$this->skip_return_reference_marker();
		$params = $this->read_parameters();
		$this->factory->set_scope_parameters($params);
		$decl->pos = $this->pos;

		if ($this->skip_typed_token(T_USE)) {
			$decl->using_params = $this->read_parameters();
		}

		$this->read_function_return_types_for($decl);

		$this->read_body_for_decl($decl);

		$this->factory->end_block();

		return $decl;
	}

	private function read_function_declaration(?string $doc = null)
	{
		$this->skip_return_reference_marker();
		$name = $this->expect_identifier_name();

		$decl = $this->factory->create_function_declaration(_PUBLIC, $name, $this->namespace);
		$decl->pos = $this->pos;

		$params = $this->read_parameters();
		$this->apply_phpdoc_param_types($params, $doc);
		$this->factory->set_scope_parameters($params);

		$this->read_function_return_types_for($decl, $doc);

		$this->read_body_for_decl($decl);
		$this->factory->end_root_declaration();

		return $decl;
	}

	private function skip_return_reference_marker(): bool
	{
		$token = $this->get_token_ignore_empty();
		if ($token === '&') {
			$this->scan_token_ignore_empty();
			return true;
		}

		if (is_array($token) && in_array($token[0], [T_AMPERSAND_NOT_FOLLOWED_BY_VAR_OR_VARARG, T_AMPERSAND_FOLLOWED_BY_VAR_OR_VARARG], true)) {
			$this->scan_token_ignore_empty();
			return true;
		}

		return false;
	}

	private function read_function_return_types_for(IFunctionDeclaration $decl, ?string $doc = null)
	{
		if ($this->skip_char_token(_COLON)) {
			$type_token = $this->scan_token_ignore_empty();
			$declared_type = $this->read_type_expression_with_token($type_token);
			if ($declared_type === null) {
				throw $this->new_unexpected_error();
			}

			$decl->declared_type = $declared_type;
		}

		$noted_type = $this->read_noted_type();
		if ($noted_type) {
			ASTHelper::set_noted_type($decl, $this->apply_declared_nullable_to_noted_type($decl, $decl->declared_type, $noted_type));
		}
		elseif ($doc) {
			$doc_return_type = $this->get_type_in_doc($doc, 'return');
			if ($doc_return_type && $decl instanceof BaseDeclaration && $this->should_apply_phpdoc_noted_type($decl, $doc_return_type)) {
				ASTHelper::set_noted_type($decl, $this->apply_declared_nullable_to_noted_type($decl, $decl->declared_type, $doc_return_type));
				ASTHelper::set_noted_type_from_phpdoc($decl, true);
			}
		}

		if ($doc) {
			$this->apply_phpdoc_assert_if_true_properties($decl, $doc);
		}
	}

	private function apply_phpdoc_assert_if_true_properties(IFunctionDeclaration $decl, string $doc): void
	{
		$properties = ASTHelper::get_php_true_property_non_null_assertions($decl);
		foreach (['psalm-assert-if-true', 'phpstan-assert-if-true'] as $kind) {
			foreach ($this->get_phpdoc_tag_contents($doc, $kind) as $assertion) {
				if (preg_match('/^!(?:null|empty)\s+\$this->([A-Za-z_][A-Za-z0-9_]*)$/', $assertion, $matches)) {
					$properties[] = $matches[1];
				}
			}
		}

		if ($properties) {
			ASTHelper::set_php_true_property_non_null_assertions($decl, array_values(array_unique($properties)));
		}
	}

	private function apply_declared_nullable_to_noted_type(BaseDeclaration $decl, ?BaseType $declared_type, ?BaseType $noted_type): ?BaseType
	{
		if ($declared_type instanceof BaseType
			&& $noted_type instanceof BaseType
			&& TypeHelper::is_nullable_type($declared_type)
			&& !$this->last_noted_type_explicit_nullable
			&& !TypeHelper::is_nullable_type($noted_type)) {
			ASTHelper::set_noted_type_nullable_inherited($decl, true);
			return TypeHelper::to_nullable($noted_type);
		}

		return $noted_type;
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
		$attributes = [];
		$token = $this->get_token_ignore_empty();
		if ($token === _PAREN_CLOSE) {
			return null;
		}

		$this->scan_token_ignore_empty();

		while (is_array($token) && $token[0] === T_ATTRIBUTE) {
			$attributes = $this->read_meta_attributes($attributes);
			$token = $this->expect_typed_token_ignore_empty();
		}

		// parameters at __construct maybe has ?modifiers
		$modifier = null;
		$set_visibility = null;
		$get_visibility = null;
		if (in_array($token[0], [T_PUBLIC, T_PROTECTED, T_PRIVATE])) {
			$modifier = $token[1];
			$token = $this->scan_token_ignore_empty();
			
		// Check for PHP 8.4+ asymmetric visibility
		// Examples: public private(set), protected protected(set), private public(set)
		$peek = $this->get_token_ignore_empty();
		if ($peek[0] === T_STRING && in_array(strtolower($peek[1]), ['public', 'protected', 'private'])) {
			$this->scan_token_ignore_empty(); // skip the second visibility
			$next = $this->expect_char_token('('); // expect opening parenthesis
			
			$modifier_type = $this->expect_typed_token_ignore_empty(); // should be 'set' or 'get'
			if ($modifier_type[0] === T_STRING && in_array(strtolower($modifier_type[1]), ['set', 'get'])) {
				$this->expect_char_token(')'); // expect closing parenthesis
				
				if (strtolower($modifier_type[1]) === 'set') {
					$set_visibility = strtolower($peek[1]);
				} else {
					$get_visibility = strtolower($peek[1]);
				}
				$token = $this->scan_token_ignore_empty();
			}
		}
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
		ASTHelper::set_noted_type($decl, $this->apply_declared_nullable_to_noted_type($decl, $declared_type, $noted_type));
		$decl->is_variadic = $is_variadic;
		
		// for PHP 8.0+ constructor property promotion
		if ($modifier !== null) {
			$decl->promoted_property_modifier = $modifier;
			if ($set_visibility !== null) {
				$decl->promoted_property_set_modifier = $set_visibility;
			}
		}
		
		// Set asymmetric visibility for property declarations
		if ($set_visibility !== null) {
			$decl->set_visibility = $set_visibility;
		}
		if ($get_visibility !== null) {
			$decl->get_visibility = $get_visibility;
		}
		$decl->attributes = $attributes;

		return $decl;
	}

	/**
	 * Parse PHP 8.4+ asymmetric visibility modifier
	 * Examples: private(set), protected(set), public(set), virtual(set)
	 * Returns [set_visibility, get_visibility, token_after]
	 */
	private function parse_asymmetric_visibility(array $token, string $current_visibility): array
	{
		$set_visibility = null;
		$get_visibility = null;
		$next_token = $token;
		
		// Check if current token is a visibility modifier that could be followed by (set)
		$visibility_types = [T_PUBLIC, T_PROTECTED, T_PRIVATE, T_STRING];
		if (!in_array($token[0], $visibility_types)) {
			return [$set_visibility, $get_visibility, $next_token];
		}
		
		// Check for pattern: visibility (set)
		$token_str = is_array($token) ? strtolower($token[1]) : strtolower($token);
		$valid_visibilities = ['public', 'protected', 'private', 'virtual'];
		
		if (in_array($token_str, $valid_visibilities)) {
			$peek = $this->get_token_ignore_empty();
			if ($peek === '(') {
				$this->scan_token_ignore_empty(); // skip visibility
				$token = $this->expect_typed_token_ignore_empty(); // should be 'set' or 'get'
				
				if ($token[0] === T_STRING) {
					$modifier_type = strtolower($token[1]);
					$this->expect_char_token(')');
					
					if ($modifier_type === 'set') {
						$set_visibility = $token_str;
					} elseif ($modifier_type === 'get') {
						$get_visibility = $token_str;
					}
					
					$next_token = $this->scan_token_ignore_empty();
				}
			}
		}
		
		return [$set_visibility, $get_visibility, $next_token];
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

	private function read_type_expression_with_token(string|array|null $token)
	{
		$nullable = $token === _QUESTION;
		if ($nullable) {
			$token = $this->expect_typed_token_ignore_empty();
		}

		$type = $this->read_type_union_with_token($token, $nullable);
		if ($type === null && $nullable) {
			throw $this->new_unexpected_error();
		}

		return $type;
	}

	// private function read_block(?string $label = null)
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

	// private function read_class_type()
	// {
	// 	// NS1\NS2\Target
	// 	// \NS1\NS2\Target

	// 	$token = $this->expect_typed_token_ignore_empty();

	// 	$ns_components = $this->read_qualified_name_with($token);
	// 	$name = array_pop($ns_components);

	// 	$identifier = $this->create_class_type_identifier($name, $ns_components);
	// 	$identifier->pos = $this->pos;

	// 	return $identifier;
	// }

	private function read_type_reference(?array $token = null)
	{
		[$ns, $name] = $this->read_namespace_and_name($token);

		$identifier = $this->create_type_reference($name, $ns);
		$identifier->pos = $this->pos;

		return $identifier;
	}

	private function read_classkindred_identifier(?array $token = null)
	{
		[$ns, $name] = $this->read_namespace_and_name($token);

		$identifier = $this->create_classkindred_identifier($name, $ns);
		$identifier->pos = $this->pos;

		return $identifier;
	}

	private function read_namespace_and_name(?array $token)
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

		$ns = $ns_components ? $this->create_namespace_identifier($ns_components) : null;

		return [$ns, $name];
	}

	// private function create_class_type_identifier(string $name, ?array $ns_components = null)
	// {
	// 	$identifier = $this->factory->create_class_type_identifier($name);
	// 	$identifier->pos = $this->pos;

	// 	if ($ns_components) {
	// 		$ns = $this->create_namespace_identifier($ns_components);
	// 		$identifier->set_namespace($ns);

	// 		// $target = $this->factory->append_use_target($ns, $name);
	// 		// $statement = $this->create_use_statement_when_not_exists($ns, [$target]);
	// 	}

	// 	$this->program->append_unknow_identifier($identifier);
	// 	return $identifier;
	// }

	private function create_type_reference(string $name, ?NamespaceIdentifier $ns = null)
	{
		$identifier = $this->factory->create_type_reference($name);
		$identifier->pos = $this->pos;
		$ns && $identifier->set_namespace($ns);
		$this->program->append_unknow_identifier($identifier);
		return $identifier;
	}

	private function create_classkindred_identifier(string $name, ?NamespaceIdentifier $ns = null)
	{
		$identifier = $this->factory->create_classkindred_identifier($name);
		$identifier->pos = $this->pos;
		$ns && $identifier->set_namespace($ns);
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
		$first_type = $this->create_type_identifier($name, $nullable);
		$type = $this->read_type_intersection_rest($first_type);
		return $this->read_type_union_rest($type);
	}

	private function read_type_union_with_token(string|array|null $token, bool $nullable = false): ?BaseType
	{
		$type = $this->read_type_intersection_with_token($token, $nullable);
		return $type === null ? null : $this->read_type_union_rest($type);
	}

	private function read_type_union_rest(BaseType $first_type): BaseType
	{
		$members = [$first_type];
		while ($this->skip_char_token(_TYPE_UNION)) {
			$token = $this->scan_token_ignore_empty();
			$type = $this->read_type_intersection_with_token($token);
			if ($type === null) {
				throw $this->new_unexpected_error();
			}
			$members[] = $type;
		}

		return count($members) === 1 ? $first_type : TypeFactory::create_union_type($members);
	}

	private function read_type_intersection_with_token(string|array|null $token, bool $nullable = false): ?BaseType
	{
		$type = $this->read_type_primary_with_token($token, $nullable);
		return $type === null ? null : $this->read_type_intersection_rest($type);
	}

	private function read_type_intersection_rest(BaseType $first_type): BaseType
	{
		$members = [$first_type];
		while ($this->skip_intersection_separator()) {
			$token = $this->scan_token_ignore_empty();
			$type = $this->read_type_primary_with_token($token);
			if ($type === null) {
				throw $this->new_unexpected_error();
			}
			$members[] = $type;
		}

		return count($members) === 1 ? $first_type : TypeFactory::create_intersection_type($members);
	}

	private function read_type_primary_with_token(string|array|null $token, bool $nullable = false): ?BaseType
	{
		if ($token === _PAREN_OPEN) {
			$inner_token = $this->scan_token_ignore_empty();
			$type = $this->read_type_union_with_token($inner_token);
			if ($type === null) {
				throw $this->new_unexpected_error();
			}
			$this->expect_paren_close();
			if ($nullable) {
				$type = TypeHelper::to_nullable($type);
			}
			return $type;
		}

		if (!is_array($token) || !in_array($token[0], self::TYPING_TOKEN_TYPES, true)) {
			return null;
		}

		return $this->create_type_identifier($token[1], $nullable, false);
	}

	private function is_type_expression_start_token(string|array|null $token): bool
	{
		return $token === _QUESTION
			|| $token === _PAREN_OPEN
			|| (is_array($token) && in_array($token[0], self::TYPING_TOKEN_TYPES, true));
	}

	private function skip_intersection_separator(): bool
	{
		if ($this->skip_char_token('&')) {
			return true;
		}

		$token = $this->get_token_ignore_empty();
		if ($this->is_intersection_separator_token($token)) {
			$this->scan_token_ignore_empty();
			return true;
		}

		return false;
	}

	private function is_intersection_separator_token(mixed $token): bool
	{
		return $token === '&'
			|| (is_array($token)
				&& $token[0] === T_AMPERSAND_NOT_FOLLOWED_BY_VAR_OR_VARARG);
	}

	private function read_noted_type()
	{
		$type = null;
		$this->last_noted_type_explicit_nullable = false;
		$following_comment = $this->scan_comment_ignore_space();
		if ($following_comment !== null) {
			// trim '/*' and '*/'
			$name = substr($following_comment, 2, -2);
			$nullable = str_ends_with($name, _QUESTION);
			$this->last_noted_type_explicit_nullable = $nullable;
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

	private function create_type_identifier(string $name, bool $nullable = false, bool $allow_tea_extension_types = true)
	{
		$name = $this->normalize_relative_qualified_name($name);
		$lower_name = strtolower($name);
		$name_in_tea = static::TYPE_MAP[$lower_name] ?? null;
		if (!$allow_tea_extension_types && isset(self::TEA_EXTENSION_TYPE_NAMES[$lower_name])) {
			$name_in_tea = null;
		}
		if ($name_in_tea and $type_identifier = TypeFactory::get_type($name_in_tea)) {
			$identifier = clone $type_identifier;
		}
		elseif (strpos($name, static::NS_SEPARATOR) !== false) {
			$names = explode(static::NS_SEPARATOR, $name);
			$name = array_pop($names);
			$ns = $this->create_namespace_identifier($names);
			$identifier = $this->create_type_reference($name, $ns);
		}
		else {
			$identifier = $this->create_type_reference($name);
		}

		$nullable and $identifier = TypeHelper::to_nullable($identifier);
		$identifier->pos = $this->pos;

		return $identifier;
	}

	private function create_noted_type_expr(string $note, bool $nullable = false): BaseType
	{
		$note = trim($note);
		if (str_starts_with($note, _QUESTION)) {
			$nullable = true;
			$note = substr($note, 1);
		}

		// Handle union types like "IStatement[]|BaseExpression"
		$types = $this->split_phpdoc_type_list($note, '|');
		if (count($types) > 1) {
			$type_exprs = [];
			$has_false_sentinel = false;
			foreach ($types as $type) {
				$type = trim($type);
				if (strtolower(ltrim($type, self::NS_SEPARATOR)) === 'false') {
					$has_false_sentinel = true;
					continue;
				}

				$type_exprs[] = $this->create_noted_type_expr($type, false);
			}
			if (!$type_exprs) {
				$expr = $this->create_type_identifier('false');
			}
			else {
				$expr = count($type_exprs) === 1
					? $type_exprs[0]
					: TypeFactory::create_union_type($type_exprs);
				if ($has_false_sentinel) {
					$expr = TypeFactory::create_invalidable_type($expr, new LiteralBoolean(false));
				}
			}
			$nullable and $expr = TypeHelper::to_nullable($expr);
			$expr->pos = $this->pos;
			return $expr;
		}

		if ($this->is_phpdoc_generic_type($note)) {
			$expr = $this->create_phpdoc_generic_type_expr($note);
			$nullable and $expr = TypeHelper::to_nullable($expr);
		}
		elseif (strpos($note, _DOT)) {
			$expr = $this->create_dots_style_compound_type($note);
			$nullable and $expr = TypeHelper::to_nullable($expr);
		}
		elseif (strpos($note, _BRACKET_OPEN)) {
			$expr = $this->create_bracket_style_type($note);
			$nullable and $expr = TypeHelper::to_nullable($expr);
		}
		else {
			$expr = $this->create_type_identifier($note, $nullable);
		}

		$expr->pos = $this->pos;
		return $expr;
	}

	private function is_phpdoc_generic_type(string $note): bool
	{
		return (bool)preg_match('/^[a-zA-Z_\\\\][a-zA-Z0-9_\\\\-]*<.+>$/', $note);
	}

	private function create_phpdoc_generic_type_expr(string $note): BaseType
	{
		if (!preg_match('/^([a-zA-Z_\\\\][a-zA-Z0-9_\\\\-]*)<(.+)>$/', $note, $matches)) {
			throw $this->new_parse_error("Invalid PHPDoc generic type expression noted");
		}

		$name = strtolower($matches[1]);
		$args = $this->split_phpdoc_type_list($matches[2], ',');
		if ($name === 'list' && count($args) === 1) {
			return TypeFactory::create_array_type($this->create_noted_type_expr($args[0]));
		}

		if ($name !== 'array') {
			return $this->create_type_identifier($matches[1]);
		}

		if (count($args) === 1) {
			return TypeFactory::create_array_type($this->create_noted_type_expr($args[0]));
		}

		if (count($args) !== 2) {
			return $this->create_type_identifier('array');
		}

		$key = strtolower($args[0]);
		$value_type = $this->create_noted_type_expr($args[1]);
		return match ($key) {
			'string' => TypeFactory::create_dict_type($value_type),
			'int', 'integer' => TypeFactory::create_array_type($value_type),
			'array-key' => TypeFactory::create_union_type([
				TypeFactory::create_array_type($value_type),
				TypeFactory::create_dict_type($value_type),
			]),
			default => $this->create_type_identifier('array'),
		};
	}

	private function is_unknown_phpdoc_alias_type(BaseType $type): bool
	{
		return $type instanceof TypeReference
			&& $type->ns === null
			&& $type->generic_types === []
			&& !$this->is_phpdoc_builtin_type_member($type->name);
	}

	private function split_phpdoc_type_list(string $text, string $separator): array
	{
		$items = [];
		$item = '';
		$depth = 0;
		$length = strlen($text);
		for ($i = 0; $i < $length; $i++) {
			$char = $text[$i];
			if ($char === '<') {
				$depth++;
			}
			elseif ($char === '>' && $depth > 0) {
				$depth--;
			}
			elseif ($char === $separator && $depth === 0) {
				$items[] = trim($item);
				$item = '';
				continue;
			}

			$item .= $char;
		}

		$items[] = trim($item);
		return array_values(array_filter($items, fn($item) => $item !== ''));
	}

	private function create_bracket_style_type(string $note): BaseType
	{
		$parts = explode('[]', $note);
		if ($parts[0] === '') {
			throw $this->new_parse_error("Invalid bracket style type expression noted");
		}

		$expr = $this->create_noted_type_expr($parts[0]);
		for ($i = 1; $i < count($parts); $i++) {
			if ($parts[$i] !== '') {
				throw $this->new_parse_error("Invalid bracket style type expression noted");
			}

			$expr = TypeFactory::create_array_type($expr);
			$expr->pos = $this->pos;
		}

		return $expr;
	}

	private function create_dots_style_compound_type(string $note): BaseType
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
		$this->skip_comments();
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
			case T_NAME_RELATIVE:
				$token[1] = $this->normalize_relative_qualified_name($token[1]);
				$names = explode(_BACK_SLASH, $token[1]);
				break;

			default:
				throw $this->new_unexpected_error();
		}

		return $names;
	}

	private function normalize_relative_qualified_name(string $name): string
	{
		$relative_prefix = 'namespace' . static::NS_SEPARATOR;
		if (!str_starts_with($name, $relative_prefix)) {
			return $name;
		}

		$name = substr($name, strlen($relative_prefix));
		if ($this->namespace !== null && !$this->namespace->is_global_space()) {
			$name = $this->namespace->uri . static::NS_SEPARATOR . $name;
		}

		return $name;
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

	protected function get_current_token_string(): array|string|null
	{
		$token = $this->tokens[$this->pos] ?? null;
		return is_array($token) ? $token[1] : $token;
	}

	private function expect_typed_token_ignore_empty(int|string|null $expected = null)
	{
		$token = $this->scan_token_ignore_empty();
		if (!is_array($token)) {
			throw $this->new_unexpected_error();
		}
		
		// Add type validation when $expected is provided
		// if ($expected !== null && $token[0] !== $expected) { ... }

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
			$tmp = null;
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
		$token = null;
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

		$token = null;
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

		$token = null;
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

	protected function get_to_line_end(?int $from = null): string
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

	protected function get_previous_code_inline(?int $pos = null): string
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

	protected function print_token($token = null, ?string $marker = null)
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
