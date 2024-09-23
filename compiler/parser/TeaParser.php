<?php
/**
 * This file is part of the Tea programming language project
 * @copyright 	(c)2019 tealang.org
 * For the full copyright and license information, please view the LICENSE file that was distributed with this source code.
 */

namespace Tea;

class TeaParser extends BaseParser
{
	use TeaTokenTrait, TeaStringTrait, TeaXTagTrait, TeaDocTrait;

	const NS_SEPARATOR = _BACK_SLASH;

	const EXPR_STOPPING_SIGNS = [_PAREN_CLOSE, _BRACKET_CLOSE, _BLOCK_END, _SEMICOLON];

	protected const METHOD_MAP = ['construct' => _CONSTRUCT, 'destruct' => _DESTRUCT];

	protected $root_statements = [];

	protected $is_in_tea_declaration = false;

	public function read_program(): Program
	{
		$program = $this->program;

		while ($this->pos < $this->tokens_count) {
			$item = $this->read_root_statement();
			if ($item instanceof IRootDeclaration) {
				// $program->append_declaration($item);
			}
			elseif ($item) {
				$this->root_statements[] = $item;
			}
		}

		// set statements to main function when has any onload executings
		if ($this->root_statements) {
			$program->initializer->set_body_with_statements($this->root_statements);
		}
		else {
			$program->initializer = null;
		}

		$this->factory->end_program();

		return $program;
	}

	protected function read_root_statement(bool $leading_br = false, DocComment $doc = null)
	{
		$token = $this->scan_token_ignore_space();
		if ($token === LF) {
			return $this->read_root_statement(true);
		}
		elseif ($token === _SEMICOLON || $token === null) {
			// empty statement, or at the end of program
			return null;
		}

		$this->trace_statement($token);

		if ($token === _SHARP) {
			$node = $this->read_root_labeled_statement();
		}
		elseif ($token === _DOC_MARK) {
			$doc = $this->read_doc_comment();
			return $this->read_root_statement($leading_br, $doc);
		}
		elseif ($token === _LINE_COMMENT_MARK) {
			$this->skip_current_line();
			return $this->read_root_statement($leading_br, $doc);
		}
		elseif ($token === _FUNC) {
			$name = $this->expect_identifier_token_ignore_space();
			$node = $this->read_function_declaration_with($name);
		}
		elseif ($token === _CONST) {
			$name = $this->expect_identifier_token_ignore_space();
			$node = $this->read_constant_declaration_with($name);
		}
		elseif (TeaHelper::is_modifier($token)) {
			$node = $this->read_root_declaration_with_modifier($token);
		}
		elseif ($this->get_token_ignore_space() === _COLON) {
			$this->scan_token_ignore_space();
			$node = $this->read_custom_label_statement_with($token);
		}
		else {
			$node = $this->read_normal_statement_with_token($token);
		}

		$this->expect_statement_end();

		if ($node !== null) {
			$node->leading_br = $leading_br;
			$node->doc = $doc;
		}

		return $node;
	}

	protected function read_inner_statement(bool $leading_br = false, DocComment $doc = null)
	{
		if ($this->get_token_ignore_space() === _BLOCK_END) {
			return null;
		}

		$token = $this->scan_token_ignore_space();
		if ($token === LF) {
			return $this->read_inner_statement(true);
		}
		elseif ($token === _SEMICOLON || $token === null) {
			return null;
		}

		$this->trace_statement($token);

		// if ($token === _SHARP) {
		// 	$node = $this->read_inner_label_statement();
		// }
		// else
		if ($token === _DOC_MARK) {
			$doc = $this->read_doc_comment();
			return $this->read_inner_statement($leading_br, $doc);
		}
		elseif ($token === _LINE_COMMENT_MARK) {
			$this->skip_current_line();
			return $this->read_inner_statement($leading_br, $doc);
		}
		elseif ($this->get_token_ignore_space() === _COLON) {
			$this->scan_token_ignore_space();
			$node = $this->read_custom_label_statement_with($token);
		}
		else {
			$node = $this->read_normal_statement_with_token($token);
		}

		$this->expect_statement_end();

		if ($node !== null) {
			$node->leading_br = $leading_br;
			$node->doc = $doc;
		}

		return $node;
	}

	protected function read_normal_statement_with_token(string $token): ?IStatement
	{
		switch ($token) {
			case _VAR:
				$node = $this->read_var_statement();
				break;
			case _UNSET:
				$node = $this->read_unset_statement();
				break;
			case _ECHO:
				$node = $this->read_echo_statement();
				break;

			case _EXIT:
				$node = $this->read_exit_statement();
				break;
			case _RETURN:
				$node = $this->read_return_statement();
				break;
			case _BREAK:
				$node = $this->read_break_statement();
				break;
			case _CONTINUE:
				$node = $this->read_continue_statement();
				break;
			case _THROW:
				$node = $this->read_throw_statement();
				break;

			case _IF:
				$node = $this->read_if_block();
				break;
			case _SWITCH:
				$node = $this->read_switch_block();
				break;
			case _FOR:
				$node = $this->read_for_block();
				break;
			case _WHILE:
				$node = $this->read_while_block();
				break;
			// case _LOOP:
			// 	$node = $this->read_loop_block();
			// 	break;
			case _TRY:
				$node = $this->read_try_block();
				break;

			default:
				$expr = $this->read_expression_with_token($token);
				$node = $expr === null ?: $this->read_expression_statement_with($expr);
		}

		return $node;
	}

	private function read_root_labeled_statement()
	{
		$token = $this->scan_token();
		switch ($token) {
			case _MAIN:
				$this->factory->set_as_main();
				return;

			default:
				throw $this->new_parse_error("Unknow sharp statement");
		}

		return $node;
	}

	// protected function read_inner_label_statement()
	// {
	// 	$token = $this->scan_token();
	// 	switch ($token) {
	// 		case _DEFAULT:
	// 			$expression = ASTFactory::$default_value_mark;
	// 			$node = new NormalStatement($expression);
	// 			break;

	// 		case _TEXT:
	// 			$expression = $this->read_literal_string();
	// 			$expression = $this->read_expression_combination($expression);
	// 			$node = new NormalStatement($expression);
	// 			break;

	// 		default:
	// 			throw $this->new_parse_error("Unknow sharp statement");
	// 	}

	// 	return $node;
	// }

	private function read_custom_label_statement_with(string $name)
	{
		$this->assert_not_reserved_word($name);

		// labeled block
		$next = $this->scan_token_ignore_space();
		if ($next === _FOR) {
			$node = $this->read_for_block($name);
		}
		elseif ($next === _WHILE) {
			$node = $this->read_while_block($name);
		}
		// elseif ($next === _LOOP) {
		// 	$node = $this->read_loop_block($name);
		// }
		elseif ($next === _SWITCH) {
			$node = $this->read_switch_block($name);
		}
		else {
			throw $this->new_parse_error("Expected a inline statement after label #{$name}");
		}

		return $node;
	}

	private function read_expression_statement_with(BaseExpression $expr)
	{
		// if ($this->is_next_assign_operator()) {
		// 	if (!$expr instanceof IAssignable) {
		// 		throw $this->new_parse_error("Invalid assigned expression");
		// 	}

		// 	$sign = $this->scan_token_ignore_empty();
		// 	$operator = OperatorFactory::get_tea_normal_operator($sign);
		// 	if ($operator === null) {
		// 		throw $this->new_unexpected_error();
		// 	}

		// 	$value = $this->read_expression();
		// 	$expr = $this->factory->create_assignment($expr, $value, $operator);
		// }

		$node = $this->create_normal_statement($expr);
		return $node;
	}

	protected function assert_not_reserved_word($token)
	{
		if (TeaHelper::is_reserved($token)) {
			throw $this->new_parse_error("'$token' is a reserved word, cannot use for declaration name");
		}
	}

	protected function read_root_declaration_with_modifier(string $modifier)
	{
		$token = $this->scan_token_ignore_space();
		$this->is_declare_mode = false;

		switch ($token) {
			case _TYPE:
				$name = $this->expect_identifier_token_ignore_space();
				$decl = $this->read_type_declaration_with($name, $modifier);
				break;
			case _CLASS:
				$name = $this->expect_identifier_token_ignore_space();
				$decl = $this->read_class_declaration_with($name, $modifier);
				break;
			case _ABSTRACT:
				$name = $this->expect_identifier_token_ignore_space();
				$decl = $this->read_class_declaration_with($name, $modifier);
				$decl->is_abstract = true;
				break;
			case _INTERFACE:
				$name = $this->expect_identifier_token_ignore_space();
				$decl = $this->read_interface_declaration($name, $modifier);
				break;
			case _INTERTRAIT:
				$name = $this->expect_identifier_token_ignore_space();
				$decl = $this->read_intertrait_declaration($name, $modifier);
				break;
			case _FUNC:
				$name = $this->expect_identifier_token_ignore_space();
				$decl = $this->read_function_declaration_with($name, $modifier);
				break;
			case _CONST:
				$name = $this->expect_identifier_token_ignore_space();
				$decl = $this->read_constant_declaration_with($name, $modifier);
				break;
			default:
				$decl = $this->check_and_read_normal_root_declaration($token, $modifier);
				break;
		}

		return $decl;
	}

	protected function check_and_read_normal_root_declaration(string $name, string $modifier) {
		if (!TeaHelper::is_identifier_name($name)) {
			throw $this->new_unexpected_error();
		}

		$next = $this->get_token_ignore_empty();
		if ($next === _BLOCK_BEGIN || $next === _COLON) {
			$decl = $this->read_class_declaration_with($name, $modifier);
		}
		elseif ($next === _PAREN_OPEN) {
			$decl = $this->read_function_declaration_with($name, $modifier);
		}
		elseif (TeaHelper::is_normal_constant_name($name)) {
			$decl = $this->read_constant_declaration_with($name, $modifier);
		}
		else {
			throw $this->new_unexpected_error();
		}

		return $decl;
	}

	protected function read_type_declaration_with(string $name, string $modifier)
	{
		// use to allow extends with a builtin type class
		$this->is_in_tea_declaration = true;

		$decl = $this->factory->create_builtin_type_class_declaration($name);
		if ($decl === null) {
			throw $this->new_parse_error("'$name' not a builtin type");
		}

		$this->read_rest_for_classkindred_declaration($decl);

		$this->is_in_tea_declaration = false;

		return $decl;
	}

	protected function read_function_declaration_with(string $name, string $modifier = null, NamespaceIdentifier $ns = null)
	{
		// func1(arg0 String, arg1 Int = 0) // declare mode has no body
		// func1(arg0 String, arg1 Int = 0) { ... } // normal mode required body

		$decl = $this->factory->create_function_declaration($modifier, $name, $ns);
		$decl->pos = $this->pos;

		$parameters = $this->read_parameters_with_parentheses();
		$this->factory->set_scope_parameters($parameters);

		$this->read_type_hints_for_declaration($decl);

		if (!$this->is_declare_mode) {
			$this->read_body_for_decl($decl);
		}

		$this->factory->end_root_declaration();

		return $decl;
	}

	private function read_constant_declaration_with(string $name, string $modifier = null)
	{
		if ($this->root_statements) {
			throw $this->new_parse_error("Please define constants at header of program");
		}

		$decl = $this->read_constant_declaration_header($name, $modifier);

		$this->expect_token_ignore_space(_ASSIGN);
		$decl->value = $this->read_expression();

		$this->factory->end_root_declaration();

		return $decl;
	}

	protected function read_constant_declaration_without_value(string $name, string $modifier = null, NamespaceIdentifier $ns = null)
	{
		$this->assert_not_reserved_word($name);

		$decl = $this->read_constant_declaration_header($name, $modifier, $ns);
		if (!$decl->declared_type) {
			$this->new_unexpected_error();
		}

		$this->factory->end_root_declaration();

		return $decl;
	}

	private function read_constant_declaration_header(string $name, string $modifier = null, NamespaceIdentifier $ns = null)
	{
		$decl = $this->factory->create_constant_declaration($modifier, $name, $ns);
		$decl->pos = $this->pos;
		$this->read_type_hints_for_declaration($decl);

		return $decl;
	}

	protected function read_masked_declaration()
	{
		// masked name(...) => fn(...)

		// the declaration options
		$name = $this->scan_token_ignore_space();
		if (!TeaHelper::is_normal_function_name($name)) {
			throw $this->new_unexpected_error();
		}

		$decl = $this->factory->create_masked_declaration($name);
		$decl->pos = $this->pos;

		if ($this->get_token() === _PAREN_OPEN) {
			$parameters = $this->read_parameters_with_parentheses();
			$this->factory->set_scope_parameters($parameters);
		}

		$this->read_type_hints_for_declaration($decl);

		$this->expect_token_ignore_empty(_DOUBLE_ARROW);

		$expr = $this->read_expression();
		$decl->set_body_with_expression($expr);

		$this->factory->end_class_member();

		return $decl;
	}

// ---

	protected function read_echo_statement()
	{
		// echo
		// echo argument0, argument1, ...

		$args = $this->read_inline_arguments();
		return new EchoStatement($args);
	}

	protected function read_var_statement()
	{
		// var var0 Type0, var1 Type1, ...

		$members = [];
		do {
			$members[] = $this->read_variable_declaration();
		}
		while ($this->skip_comma());

		return new VarStatement($members);
	}

	protected function read_variable_declaration()
	{
		// var abc
		// var abc Type
		// var abc Type = expression

		$name = $this->scan_token_ignore_empty();
		$type = $this->scan_type_expression();

		$value = null;
		if ($this->skip_token_ignore_space(_ASSIGN)) {
			$value = $this->read_expression();
		}

		return $this->factory->create_variable_declaration($name, $type, $value);
	}

	protected function read_unset_statement()
	{
		// unset
		// unset variable expression

		$argument = $this->scan_expression_inline();
		if ($argument instanceof Parentheses) {
			$argument = $argument->expression;
		}

		$statement = new UnsetStatement($argument);

		return $statement;
	}

	protected function read_continue_statement()
	{
		// continue
		// continue #target_label

		$statement = $this->factory->create_continue_statement();

		$target_label = $this->get_identifier_token_ignore_space();

		// the target label
		if ($target_label === null) {
			$statement->switch_layers = $this->factory->count_switch_layers_contains_in_block(IContinueAble::class);
		}
		else {
			$this->scan_token_ignore_space();
			[$statement->target_layers, $statement->switch_layers] = $this->factory->count_target_block_layers_with_label($target_label, IContinueAble::class);
			$statement->target_label = $target_label;
		}


		return $statement;
	}

	protected function read_break_statement()
	{
		// break
		// break #target_label

		$statement = $this->factory->create_break_statement();

		$target_label = $this->get_identifier_token_ignore_space();

		// the target label
		if ($target_label === null) {
			$this->factory->count_switch_layers_contains_in_block(IBreakAble::class);
		}
		else {
			$this->scan_token_ignore_space();
			[$statement->target_layers] = $this->factory->count_target_block_layers_with_label($target_label, IBreakAble::class);
			$statement->target_label = $target_label;
		}

		return $statement;
	}

	protected function read_exit_statement()
	{
		// exit
		// exit int expression

		$argument = $this->scan_expression_inline();
		$statement = $this->factory->create_exit_statement($argument);

		return $statement;
	}

	protected function read_return_statement()
	{
		// return
		// return expression

		$argument = $this->scan_expression_inline();
		$statement = $this->factory->create_return_statement($argument);

		return $statement;
	}

	protected function read_throw_statement()
	{
		// throw Exception()

		$argument = $this->scan_expression_inline();
		if ($argument === null) {
			throw $this->new_parse_error("Required an Exception argument");
		}

		$statement = $this->factory->create_throw_statement($argument);

		return $statement;
	}

	protected function read_if_block()
	{
		// if test_expression { ... } [elseif test_expression {...}] [else { ... }] [catch e Exception {}] finally {}

		$test = $this->read_expression();
		$block = $this->factory->create_if_block($test);
		$this->read_body_for_control_block($block);

		$this->scan_else_block_for($block);
		// $this->scan_catching_block_for($block);

		$this->factory->end_branches($block);

		return $block;
	}

	protected function scan_else_block_for(IElseAble $main_block)
	{
		$this->skip_comments();
		$keyword = $this->get_token_ignore_empty();

		if ($keyword === _ELSE) {
			$this->scan_token_ignore_empty();
			$sub_block = $this->factory->create_else_block();
			$this->read_body_for_control_block($sub_block);

			$main_block->set_else_block($sub_block);

			// else block would be the end
		}
		elseif ($keyword === _ELSEIF) {
			$this->scan_token_ignore_empty();
			$test = $this->read_expression();
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

	protected function read_try_block()
	{
		$block = $this->factory->create_try_block();
		$this->read_body_for_control_block($block);

		$this->scan_catching_block_for($block);

		$this->factory->end_branches($block);

		return $block;
	}

	protected function scan_catching_block_for(IExceptAble $main_block)
	{
		$this->skip_comments();
		$keyword = $this->get_token_ignore_empty();

		if ($keyword === _CATCH) {
			$this->scan_token_ignore_empty();

			$var_name = $this->scan_token_ignore_space();
			if (!TeaHelper::is_normal_variable_name($var_name)) {
				throw $this->new_parse_error("Invalid variable name '{$var_name}'", 1);
			}

			$type = $this->scan_classkindred_identifier();
			$sub_block = $this->factory->create_catch_block($var_name, $type);
			$this->read_body_for_control_block($sub_block);
			$main_block->add_catching_block($sub_block);

			// another except block
			$this->scan_catching_block_for($main_block);
		}
		elseif ($keyword === _FINALLY) {
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

	protected function read_switch_block(string $label = null)
	{
		// case test-expression { branches }

		$argument = $this->read_expression();

		$block = $this->factory->create_switch_block($argument);
		$block->label = $label;

		$branches = $this->read_case_branches();
		$block->set_branches($branches);

		$this->scan_else_block_for($block);
		// $this->scan_catching_block_for($block);

		return $block;
	}

	protected function read_case_branches()
	{
		$this->expect_block_begin();

		$branches = [];
		while ($arguments = $this->read_case_arguments()) {
			$this->expect_token_ignore_space(_COLON);
			$case_branch = $this->factory->create_case_branch_block($arguments);

			$statements = [];
			while (($item = $this->read_inner_statement()) !== null) {
				$statements[] = $item;

				$this->skip_comments();

				// end current case
				if ($this->get_token_ignore_empty() === _CASE) {
					break;
				}
			}

			$case_branch->set_body_with_statements($statements);
			$branches[] = $case_branch;
		}

		$this->expect_block_end();

		return $branches;
	}

	private function read_case_arguments()
	{
		if (!$this->skip_token_ignore_empty(_CASE)) {
			return null;
		}

		$items = [];
		do {
			$expr = $this->read_expression();
			$items[] = $expr;
		}
		while ($this->skip_token_ignore_space(_COMMA));

		return $items;
	}

	protected function read_for_block(string $label = null)
	{
		// for k, v in items {} else {}
		// for i in 0 to 9 {}  // the default step is 1
		// for i in 0 to 9 step 2 {}  // with step 2
		// for i in 9 downto 0 {}
		// for i in 9 downto 0 step 2 {}

		$value_var = $this->read_lite_parameter();

		$block = $this->read_forin_block_with($value_var);
		$block->label = $label;
		$this->read_body_for_control_block($block);

		// the iterable need test at if-block
		// so need assign to a temp variable on render the target code
		$this->scan_else_block_for($block);

		// $this->scan_catching_block_for($block);

		return $block;
	}

	private function read_lite_parameter()
	{
		$token = $this->scan_token_ignore_space();
		if (!TeaHelper::is_normal_variable_name($token)) {
			throw $this->new_parse_error("Invalid variable name '{$token}'", 1);
		}

		return $this->create_parameter($token);
	}

	private function read_forin_block_with(ParameterDeclaration $value_var)
	{
		$key_var = null;
		if ($this->skip_comma()) {
			$key_var = $value_var;
			$value_var = $this->read_lite_parameter();
		}

		$this->expect_token_ignore_empty(_IN);

		$iterable_or_start = $this->read_expression();

		$mode = $this->get_token_ignore_space();
		if ($mode === _TO || $mode === _DOWNTO) {
			$this->scan_token_ignore_space();
			$end = $this->read_expression();
			$step = $this->scan_step();
			$block = $this->factory->create_forto_block($key_var, $value_var, $iterable_or_start, $end, $step);
			if ($mode === _DOWNTO) {
				$block->is_downto_mode = true;
			}
		}
		else {
			$block = $this->factory->create_forin_block($key_var, $value_var, $iterable_or_start);
		}

		return $block;
	}

	private function scan_step()
	{
		$step = null;
		if ($this->skip_token_ignore_space(_STEP)) {
			$step = $this->scan_token_ignore_space();
			if (!TeaHelper::is_uint_number($step)) {
				throw $this->new_parse_error('Required unsigned int literal value for "step" in for-to/for-downto statement');
			}

			$step = intval($step);
			if ($step === 0) {
				throw $this->new_parse_error('"step" cannot set to 0');
			}
		}

		return $step;
	}

	protected function read_while_block(string $label = null)
	{
		// e.g. while test_expression {}

		$test = $this->read_expression();
		$block = $this->factory->create_while_block($test);
		$block->label = $label;

		$this->read_body_for_control_block($block);

		// $this->scan_catching_block_for($block);

		return $block;
	}

// -------- statement end

	protected function scan_expression_inline()
	{
		if ($this->get_token() === LF) {
			return null;
		}

		return $this->scan_expression();
	}

	protected function read_expression(Operator $prev_operator = null): BaseExpression
	{
		$token = $this->scan_token_ignore_empty();
		if ($token === null) {
			throw $this->new_unexpected_error();
		}

		$expr = $this->read_expression_with_token($token, $prev_operator);
		if ($expr === null) {
			throw $this->new_unexpected_error();
		}

		return $expr;
	}

	protected function scan_expression(Operator $prev_operator = null)
	{
		$token = $this->scan_token_ignore_empty();
		if ($token === null or in_array($token, static::EXPR_STOPPING_SIGNS, true)) {
			$this->back();
			return null;
		}

		$expr = $this->read_expression_with_token($token, $prev_operator);
		return $expr;
	}

	protected function scan_argument()
	{
		$token = $this->scan_token_ignore_empty();
		if ($token === null or in_array($token, static::EXPR_STOPPING_SIGNS, true)) {
			$this->back();
			return null;
		}

		// the named arguments feature has bugs
		// $label = null;
		// if ($this->skip_token_ignore_space(_COLON)) {
		// 	$label = $token;
		// 	$token = $this->scan_token_ignore_space();
		// }

		$expression = $this->read_expression_with_token($token);

		// $expression->label = $label;

		return $expression;
	}

	protected function read_expression_with_token(string $token, Operator $prev_operator = null): ?BaseExpression
	{
		switch ($token) {
			case _SINGLE_QUOTE:
				$expression = $this->read_single_quoted_expression();
				break;

			case _DOUBLE_QUOTE:
				$expression = $this->read_double_quoted_expression();
				break;

			case _PAREN_OPEN:
				$expression = $this->read_parentheses_expression(true);
				break;

			case _BRACKET_OPEN:
				$expression = $this->read_bracket_expression();
				break;

			case _BRACE_OPEN: // the object declaration begin
				$expression = $this->read_object_expression();
				break;

			case _XTAG_OPEN:
				$expression = $this->read_xtag_expression();
				return $expression; // that should be the end of current expression

			case _SHARP:
				$token = $this->scan_token();
				$expression = $this->read_sharp_expression_with($token);
				break;

			case _YIELD:
				$argument = $this->read_expression();
				$expression = $this->factory->create_yield_expression($argument);
				$expression->pos = $this->pos;
				return $expression;

			default:
				// maybe a regex
				if ($token === _SLASH) {
					$expression = $this->scan_regular_expression();
					if ($expression !== null) {
						break;
					}
				}

				// check is prefix operator
				$operator = OperatorFactory::get_tea_prefix_operator($token);
				if ($operator !== null) {
					$expression = $this->read_prefix_operation($operator);
					break;
				}

				// identifier
				if (TeaHelper::is_identifier_name($token)) {
					$expression = $this->create_identifier($token);
					break;
				}

				// the number literal
				if (($base_type = TeaHelper::check_number($token)) !== null) {
					$expression = $this->read_number_with($token, $base_type);
					break;
				}

				if (_LINE_COMMENT_MARK === $token) {
					$this->scan_to_end();
					return $this->scan_expression($prev_operator);
				}
				// elseif (_DOLLAR === $token) {
				// 	$expression = $this->read_super_variable_identifier();
				// 	break;
				// }

				throw $this->new_unexpected_error();
		}

		$expression = $this->read_expression_combination($expression, $prev_operator);

		if ($this->get_token_ignore_space() === _LINE_COMMENT_MARK) {
			$expression->tailing_comment = $this->scan_to_end();
		}

		return $expression;
	}

	private function create_identifier(string $name)
	{
		$identifier = $this->factory->create_identifier($name);
		$identifier->pos = $this->pos;
		return $identifier;
	}

	private function read_sharp_expression_with(string $token)
	{
		switch ($token) {
			case _DEFAULT:
				$expression = ASTFactory::$default_value_mark;
				break;

			case _TEXT:
				$expression = $this->read_literal_string();
				$expression->label = $token;
				break;

			default:
				throw $this->new_unexpected_error();
		}

		return $expression;
	}

	protected function read_literal_string()
	{
		$token = $this->scan_token_ignore_space();
		switch ($token) {
			case _SINGLE_QUOTE:
				$expression = $this->read_plain_literal_string();
				break;

			case _DOUBLE_QUOTE:
				$expression = $this->read_escaped_literal_string();
				break;

			default:
				throw $this->new_unexpected_error();
		}

		return $expression;
	}

	// protected function read_super_variable_identifier()
	// {
	// 	$token = $this->scan_token();
	// 	if (TeaHelper::is_identifier_name($token)) {
	// 		return new VariableIdentifier($token);
	// 	}

	// 	throw $this->new_parse_error("Invalid super-variable name '{$token}'", 1);
	// }

	protected function read_parentheses_expression(bool $is_in_parentheses = false)
	{
		$is_in_parentheses || $this->expect_token_ignore_empty(_PAREN_OPEN);

		$expression = $this->scan_expression();

		$next = $this->get_token_ignore_empty();
		if ($next === _PAREN_CLOSE) {
			$this->scan_token_ignore_empty(); // skip )
		}
		elseif ($expression instanceof PlainIdentifier) {
			// lambda, (parameter Type) or (parameter, ...)
			return $this->read_lambda_for_parentheses($expression);
		}
		else {
			throw $this->new_unexpected_error();
		}

		// lambda, (0 or 1 parameter) => { ... }
		if ($this->get_token_ignore_space() === _DOUBLE_ARROW) {
			$parameters = [];
			if ($expression) {
				if (!$expression instanceof PlainIdentifier) {
					throw $this->new_unexpected_error();
				}

				if ($expression->symbol === null) {
					$this->factory->remove_defer_check($expression);
				}

				$parameters[] = $this->create_parameter($expression->name);
			}

			return $this->read_lambda_combination($parameters);
		}

		if ($expression === null) {
			throw $this->new_unexpected_error();
		}

		return new Parentheses($expression);
	}

	protected function read_lambda_for_parentheses(PlainIdentifier $readed_identifier)
	{
		if (!TeaHelper::is_normal_variable_name($readed_identifier->name)) {
			throw $this->new_unexpected_error();
		}

		if ($readed_identifier->symbol === null) {
			$this->factory->remove_defer_check($readed_identifier);
		}

		// lambda: (param1, ...) => {}
		// lambda: (param1 type, ...) => {}

		$parameter = $this->read_parameter_with_token($readed_identifier->name);
		$parameters = $this->read_rest_lambda_parameters($parameter);
		$this->expect_token_ignore_empty(_PAREN_CLOSE);

		return $this->read_lambda_combination($parameters);
	}

	protected function read_rest_lambda_parameters(ParameterDeclaration $parameter = null)
	{
		$items = [];

		if ($parameter) {
			$items[] = $parameter;
			if (!$this->skip_comma()) {
				return $items;
			}
		}

		while ($parameter = $this->scan_parameter()) {
			$items[] = $parameter;
			if (!$this->skip_comma()) {
				break;
			}
		}

		return $items;
	}

	protected function read_number_with(string $token, string $base_type)
	{
		if ($token[-1] === _UNDERSCORE) {
			throw $this->new_parse_error("Separator '_' can not put at the end of numeric literals");
		}

		if ($base_type === _BASE_DECIMAL) {
			// has dot
			if ($this->skip_token(_DOT)) {
				$fractional_part = $this->scan_token();
				if (!preg_match('/^[0-9_]+(e\+?[0-9_]*)?$/', $fractional_part)) {
					throw $this->new_unexpected_error();
				}

				$token .= _DOT . $fractional_part; // the real type number

				if ($token[-1] === _LOW_CASE_E) {
					// e.g. 0.123e-6
					return $this->read_scientific_notation_number_with($token);
				}

				return new LiteralFloat($token);
			}
			elseif ($token[-1] === _LOW_CASE_E) {
				// e.g. 123e-6
				return $this->read_scientific_notation_number_with($token);
			}
		}

		return new LiteralInteger($token);
	}

	protected function read_scientific_notation_number_with(string $prefix)
	{
		// e.g. 123e-6 or 123e+6

		// '+' or '-'
		$modifier = $this->get_token();
		if ($modifier === _NEGATION || $modifier === _IDENTITY) {
			$this->scan_token();
			$prefix .= $modifier;
		}

		$exp = $this->scan_token();
		if (!is_numeric($exp)) {
			throw $this->new_unexpected_error();
		}

		return new LiteralFloat($prefix . $exp);
	}

	protected function scan_regular_expression()
	{
		if ($this->is_next_space() && !$this->is_next_regular_expression()) {
			return null;
		}

		// scan to / skip \.
		$pattern = '';
		while (($token = $this->scan_token()) !== null) {
			if ($token === _BACK_SLASH) {
				$pattern .= $token . $this->scan_token(); // skip the escaped char
				continue;
			}

			if ($token === _SLASH) {
				break;
			}

			$pattern .= $token;
		}

		$flags = '';
		if (TeaHelper::is_regex_flags($this->get_token())) {
			$flags = $this->scan_token();
		}

		return new RegularExpression($pattern, $flags);
	}

	protected function read_ternary_expression_with(BaseExpression $test)
	{
		// due to the lowest priority of the ternary conditional operator
		// it can be read directly without comparing its priority with sub expressions

		if ($this->skip_token(_COLON)) {
			$then = null;
		}
		else {
			$this->expect_space("Missed space char after '?'");
			$then = $this->read_expression();
			if ($then instanceof TernaryExpression) {
				throw $this->new_parse_error("Required () for compound conditional expressions");
			}

			$this->skip_comments();
			$this->expect_token_ignore_empty(_COLON);
		}

		$else = $this->read_expression();
		if ($else instanceof TernaryExpression) {
			throw $this->new_parse_error("Required () for compound conditional expressions");
		}

		$expression = new TernaryExpression($test, $then, $else);
		$expression->pos = $else->pos;

		return $expression;
	}

	private function read_object_expression()
	{
		[$class_decl, $class_symbol] = $this->factory->create_virtual_class('__OBJ__');

		while ($key = $this->scan_object_key()) {
			$this->expect_token_ignore_space(_COLON);

			$quote_mark = null;
			if (!is_string($key)) {
				$quote_mark = _SINGLE_QUOTE;
				$key = $key->value;
			}

			[$member_decl, $member_symbol] = $this->factory->create_object_member($quote_mark, $key);
			if (!$class_decl->append_member_symbol($member_symbol)) {
				throw $this->parser->new_parse_error("Duplicated object member '{$member_decl->name}'");
			}

			$val = $this->read_expression();
			if ($val === null) {
				throw $this->new_unexpected_error();
			}

			$member_decl->value = $val;
			$member_decl->pos = $val->pos;

			if (!$this->skip_comma()) {
				break;
			}
		}

		// $this->factory->end_object_class();

		$this->expect_token_ignore_empty(_BRACE_CLOSE);

		// if support literal mode, then would be need to support compile-value
		// example as value for constants, thats a troublesome
		$object = new ObjectExpression();
		$object->symbol = $class_symbol;

		return $object;
	}

	private function scan_object_key()
	{
		$token = $this->get_token_ignore_empty();
		if ($token === null || $token === _BRACE_CLOSE) {
			return null;
		}

		if ($token === _SINGLE_QUOTE) {
			$item = $this->read_single_quoted_expression();
			if (!$item instanceof PlainLiteralString) {
				throw $this->new_parse_error("Required literal string");
			}
		}
		else {
			$this->scan_token_ignore_empty();
			if (!TeaHelper::is_identifier_name($token)) {
				throw $this->new_unexpected_error();
			}

			$item = $token;
		}

		return $item;
	}

	protected function read_bracket_expression()
	{
		$next = $this->get_token_ignore_space();
		if ($next === _BRACKET_CLOSE and $this->skip_token(_BRACKET_CLOSE)) {
			// SquareAccessing or empty Array
			if ($next = $this->get_token() and TeaHelper::is_identifier_name($next)) {
				$this->scan_token();
				$identifier = $this->create_identifier($next);
				$expr = new SquareAccessing($identifier, true);
			}
			else {
				$expr = new ArrayExpression();
				$expr->is_const_value = true;
			}

			return $expr;
		}
		elseif ($next === _COLON) {
			// empty Dict
			$this->scan_token_ignore_space(); // skip colon
			$this->expect_token(_BRACKET_CLOSE);
			$expr = new DictExpression();
			$expr->is_const_value = true;
			return $expr;
		}
		elseif ($next === LF || $next === _LINE_COMMENT_MARK) {
			$is_vertical_layout = true;
		}

		if ($item = $this->read_expression()) {
			if ($this->skip_token(_COLON)) {
				// Dict
				$expr = $this->read_dict_with_first_item($item);
			}
			else {
				// Array
				$expr = $this->read_array_with_first_item($item);
			}

			isset($is_vertical_layout) and $expr->is_vertical_layout = $is_vertical_layout;
		}
		else {
			// that should be an empty Array
			$expr = new ArrayExpression();
			$expr->is_const_value = true;
		}

		$this->expect_token_ignore_empty(_BRACKET_CLOSE);

		return $expr;
	}

	protected function read_array_with_first_item(BaseExpression $item)
	{
		$is_const_value = true;
		$items = [];

		do {
			$items[] = $item;

			if (!$item->is_const_value) {
				$is_const_value = false;
			}

			if (!$this->skip_token(_COMMA)) {
				break;
			}
		} while ($item = $this->scan_expression());

		$expr = new ArrayExpression($items);
		$expr->is_const_value = $is_const_value;
		return $expr;
	}

	protected function read_dict_with_first_item(BaseExpression $key)
	{
		$is_const_value = true;
		$items = [];

		while (true) {
			$value = $this->read_expression();
			if ($value === null) {
				throw $this->new_parse_error("Value expression for dict required");
			}

			$items[] = new DictMember($key, $value);

			if (!$key->is_const_value || !$value->is_const_value) {
				$is_const_value = false;
			}

			if (!$this->skip_token(_COMMA)) {
				break;
			}

			// read the next item
			$key = $this->scan_expression();
			if ($key === null) {
				break;
			}

			if ($this->scan_token() !== _COLON) {
				throw $this->new_unexpected_error();
			}
		}

		$expr = new DictExpression($items);
		$expr->is_const_value = $is_const_value;
		return $expr;
	}

	protected function read_lambda_combination(array $parameters, $arrow_is_optional = false)
	{
		$decl = $this->factory->create_anonymous_function();
		$this->factory->set_scope_parameters($parameters);

		$this->read_type_hints_for_declaration($decl);

		$decl->pos = $this->pos;

		$next = $this->get_token_ignore_space();
		if ($next === _DOUBLE_ARROW) {
			$this->scan_token_ignore_space();
		}
		elseif (!$arrow_is_optional) {
			throw $this->new_parse_error("Unexpected token '$next', or missed token '=>'");
		}

		if ($this->get_token_ignore_empty() === _BLOCK_BEGIN) {
			$this->read_body_for_decl($decl);
		}
		else {
			$expression = $this->read_expression();
			$decl->set_body_with_expression($expression);
		}

		$this->factory->end_block();

		return $decl;
	}

	protected function read_expression_combination(BaseExpression $expr, Operator $prev_operator = null)
	{
		$token = $this->get_token_ignore_empty();

		if ($token === _PAREN_OPEN) {
			// Spaces are not allowed
			if ($this->get_token() !== _PAREN_OPEN) {
				return $expr;
			}

			$expr = $this->read_call_expression($expr);
		}
		elseif ($token === _BRACKET_OPEN) {
			// Spaces are not allowed
			if ($this->get_token() !== _BRACKET_OPEN) {
				return $expr;
			}

			$expr = $this->read_key_accessing($expr);
		}
		elseif ($token === _DOT) {
			$this->scan_token_ignore_empty(); // skip .
			$expr = $this->read_dot_expression($expr);
		}
		elseif ($token === _SHARP) { //  the type mark operation
			// Spaces are not allowed
			if ($this->get_token() !== _SHARP) {
				return $expr;
			}

			$this->scan_token(); // skip the operator
			$expr = $this->read_as_operation($expr);
 		}
		elseif ($token === _DOUBLE_COLON) { //  the pipe call operation
			$this->scan_token_ignore_empty(); // skip the operator
			$expr = $this->read_pipe_call($expr);
 		}
		elseif ($token === _LINE_COMMENT_MARK) {
			$current_pos = $this->pos;
			// $current_line = $this->current_line;

			$this->scan_token_ignore_empty(); // skip the //
			$this->scan_to_end(); //  skip the comment
			$continue_combination = $this->read_expression_combination($expr, $prev_operator);

			// Avoid of ignoring empty lines
			if ($continue_combination === $expr) {
				$this->pos = $current_pos;
				// $this->current_line = $current_line;
			}

			return $continue_combination;
		}
		else {
			return $this->read_operation_for($expr, $prev_operator);
		}

		return $this->read_expression_combination($expr, $prev_operator);
	}

	protected function read_dot_expression(BaseExpression $basing)
	{
		// class / object member call

		$member = $this->scan_token();
		if (!TeaHelper::is_identifier_name($member)) {
			throw $this->new_unexpected_error();
		}

		$expr = $this->factory->create_accessing_identifier($basing, $member);
		$expr->pos = $this->pos;

		return $expr;
	}

	protected function read_key_accessing(BaseExpression $basing)
	{
		// array key accessing

		$this->skip_token(_BRACKET_OPEN);
		$key = $this->scan_expression();
		$this->skip_token(_BRACKET_CLOSE);

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
		// new class, or function call, or function declaration

		// support the super() call
		if ($handler instanceof PlainIdentifier) {
			if ($handler->name === _SUPER) {
				$handler = $this->factory->create_accessing_identifier($handler, _CONSTRUCT);
				$handler->pos = $this->pos;
			}
		}

		$this->skip_token(_PAREN_OPEN);
		$args = $this->read_call_expression_arguments();
		$this->skip_token_ignore_empty(_PAREN_CLOSE);

		// function call
		$call = new CallExpression($handler, $args);
		$call->pos = $this->pos;

		// if ($callbacks = $this->scan_callback_arguments()) {
		// 	$call->set_callbacks(...$callbacks);
		// }

		return $call;
	}

	// private function scan_callback_arguments()
	// {
	// 	if (!$this->skip_token_ignore_empty(_NOTIFY)) {
	// 		return null;
	// 	}

	// 	// the simple mode, just support one callback
	// 	if ($this->get_token_ignore_empty() === _BLOCK_BEGIN) {
	// 		$lambda = $this->read_lambda_combination([], true);
	// 		return [$this->create_callback_argument(null, $lambda)];
	// 	}

	// 	// the normal mode
	// 	$items = [];
	// 	while ($item = $this->read_callback_argument()) {
	// 		$items[] = $item;
	// 		if (!$this->skip_token_ignore_empty(_NOTIFY)) {
	// 			break;
	// 		}
	// 	}

	// 	return $items;
	// }

	protected function read_callback_argument()
	{
		$name = $this->scan_token_ignore_empty();
		if (!TeaHelper::is_normal_variable_name($name)) {
			throw $this->new_unexpected_error();
		}

		if ($this->skip_token_ignore_space(_COLON)) {
			// assign or lambda mode

			// e.g. -> done: done_callable
			// e.g. -> done: () => {}  // a normal style lambda declaration
			$callable = $this->read_expression();
			if ($callable) {
				return $this->create_callback_argument($name, $callable);
			}
		}

		// normal mode
		$parameters = $this->read_parameters_with_parentheses();
		$lambda = $this->read_lambda_combination($parameters, true);

		return $this->create_callback_argument($name, $lambda);
	}

	protected function create_callback_argument(?string $name, $lambda)
	{
		$node = new CallbackArgument($name, $lambda);
		$node->pos = $this->pos;
		return $node;
	}

	protected function read_operation_for(BaseExpression $expr, Operator $prev_operator = null)
	{
		$token = $this->get_token_ignore_empty();

		$operator = OperatorFactory::get_tea_normal_operator($token);
		if ($operator === null) {
			return $expr;
		}

		// compare operator precedences
		if ($prev_operator !== null and $this->is_prev_priority($prev_operator, $operator)) {
			return $expr;
		}

		if ($operator->is(OPID::IS)) {
			$this->scan_token_ignore_empty(); // skip operator
			$expr = $this->read_is_operation($expr);
		}
		elseif ($operator->is(OPID::TERNARY)) {
			$this->scan_token_ignore_empty(); // skip operator
			$expr = $this->read_ternary_expression_with($expr);
		}
		elseif ($operator->is(OPID::NONE_COALESCING)) {
			$this->scan_token_ignore_empty(); // skip operator
			$expr = $this->read_none_coalescing_with($expr, $operator);
		}
		else {
			// the normal binary operations
			// required spaces
			$this->skip_binary_operator_with_space($token);

			$expr2 = $this->read_expression($operator);
			$expr = $operator->is_type(OP_ASSIGN)
				? $this->factory->create_assignment($expr, $expr2, $operator)
				: new BinaryOperation($expr, $expr2, $operator);
			$expr->pos = $this->pos;
		}

		return $this->read_operation_for($expr, $prev_operator);
	}

	private function is_prev_priority(Operator $prev_op, Operator $curr_op)
	{
		$prev_prec = $prev_op->tea_prec;
		$curr_prec = $curr_op->tea_prec;
		return ($prev_prec < $curr_prec) || ($prev_prec === $curr_prec && $curr_op->tea_assoc !== OP_R);
	}

	private function read_is_operation(BaseExpression $expression)
	{
		$not = $this->skip_token_ignore_space(_NOT);

		$assert_type = $this->scan_type_expression();
		if ($assert_type === null) {
			throw $this->new_parse_error("Expected a type name for the 'is' expression");
		}

		$expression = new IsOperation($expression, $assert_type, $not);
		$expression->pos = $this->pos;

		return $expression;
	}

	private function read_as_operation(BaseExpression $expression)
	{
		if ($this->skip_token(_PAREN_OPEN)) {
			$as_type = $this->scan_type_expression();
			$this->expect_token(_PAREN_CLOSE);
			if ($as_type === null) {
				throw $this->new_unexpected_error();
			}
		}
		else {
			$as_type = $this->read_simple_type_identifier();
		}

		$expression = new AsOperation($expression, $as_type);
		$expression->pos = $this->pos;

		return $expression;
	}

	private function read_pipe_call(BaseExpression $expression)
	{
		// only allow a plain identifier after the pipe-operator
		$token = $this->expect_identifier_token();
		$callee = $this->create_identifier($token);

		// the arguments
		if ($this->skip_token(_PAREN_OPEN)) {
			$args = $this->read_call_expression_arguments();
			$this->skip_token(_PAREN_CLOSE);
		}
		else {
			$args = [];
		}

		// lets the base expression as the first argument
		array_unshift($args, $expression);

		$expression = new PipeCallExpression($callee, $args);
		$expression->pos = $this->pos;

		return $expression;
	}

	protected function skip_binary_operator_with_space(string $operator)
	{
		$this->expect_space("Missed space char before operator '$operator'");
		$this->scan_token_ignore_empty(); // skip the operator
		$this->expect_space("Missed space char after operator '$operator'");
	}

	protected function read_none_coalescing_with(BaseExpression $left, Operator $operator)
	{
		$right = $this->read_expression($operator);
		$expression = new NoneCoalescingOperation($left, $right);
		$expression->pos = $this->pos;

		return $expression;
	}

	protected function read_call_expression_arguments()
	{
		$has_label = false;
		$items = [];
		while ($item = $this->scan_argument()) {
			if (isset($item->label)) {
				// the labeled argument
				if (isset($items[$item->label])) {
					throw $this->new_parse_error("Parameter '{$item->label}' already has been assigned");
				}
				else {
					$items[$item->label] = $item;
					$item->label = null;
				}

				$has_label = true;
			}
			elseif ($has_label) {
				throw $this->new_parse_error("This argument required a label, because of the prevent has labeled");
			}
			else {
				$items[] = $item;
			}

			if (!$this->skip_comma()) {
				break;
			}
		}

		return $items;
	}

	// protected function read_argument_expression()
	// {
	// 	// e.g. &identifier
	// 	// e.g. expression

	// 	if ($this->skip_token_ignore_empty(_REFERENCE)) {
	// 		$expression = $this->read_expression();
	// 		$expression = new ReferenceOperation($expression);
	// 	}
	// 	else {
	// 		$expression = $this->read_expression();
	// 	}

	// 	return $expression;
	// }

	protected function read_inline_arguments()
	{
		$token = $this->get_token_ignore_space();
		if ($token === LF || $token === _LINE_COMMENT_MARK) {
			return [];
		}

		while ($item = $this->scan_expression()) {
			$items[] = $item;
			if (!$this->skip_comma()) {
				break;
			}
		}

		return $items;
	}

	protected function read_class_declaration_with(string $name, ?string $modifier, NamespaceIdentifier $ns = null)
	{
		$decl = $this->factory->create_class_declaration($name, $modifier, $ns);
		$decl->pos = $this->pos;
		$decl->define_mode = !$this->is_declare_mode;

		$this->read_rest_for_classkindred_declaration($decl);

		return $decl;
	}

	protected function read_interface_declaration(string $name, ?string $modifier, NamespaceIdentifier $ns = null)
	{
		$decl = $this->factory->create_interface_declaration($name, $modifier, $ns);
		$decl->pos = $this->pos;

		$this->set_declare_mode(true);
		$this->is_interface_mode = true;

		$this->read_rest_for_classkindred_declaration($decl);

		$this->is_interface_mode = false;
		$this->fallback_declare_mode();

		return $decl;
	}

	protected function read_intertrait_declaration(string $name, ?string $modifier, NamespaceIdentifier $ns = null)
	{
		$decl = $this->factory->create_intertrait_declaration($name, $modifier, $ns);
		$decl->pos = $this->pos;

		$this->set_declare_mode(true);

		$this->read_rest_for_classkindred_declaration($decl);

		$this->fallback_declare_mode();

		return $decl;
	}

	protected function read_trait_declaration(string $name, ?string $modifier, NamespaceIdentifier $ns = null)
	{
		$decl = $this->factory->create_trait_declaration($name, $modifier, $ns);
		$decl->pos = $this->pos;

		// $this->set_declare_mode(true);

		$this->read_rest_for_classkindred_declaration($decl);

		// $this->fallback_declare_mode();

		return $decl;
	}

	protected function read_rest_for_classkindred_declaration(ClassKindredDeclaration $decl)
	{
		if ($items = $this->read_class_extends()) {
			$decl->set_extends($items);
		}

		$this->expect_block_begin();

		while ($item = $this->read_class_member_declaration());

		$this->expect_block_end();
		$this->factory->end_class();
	}

	protected function read_class_extends()
	{
		if (!$this->skip_token_ignore_space(_COLON)) {
			return null;
		}

		$items = [];
		while ($identifier = $this->scan_classkindred_identifier()) {
			$items[] = $identifier;
			if (!$this->skip_comma()) {
				break;
			}
		}

		if (!$items) {
			throw $this->new_parse_error("Based class or interfaces expected");
		}

		return $items;
	}

	protected function scan_classkindred_identifier()
	{
		$is_based_root_ns = false;
		if ($this->skip_token_ignore_space(static::NS_SEPARATOR)) {
			$is_based_root_ns = true;
			$token = $this->expect_identifier_token();
		}
		else {
			$token = $this->get_token_ignore_empty();
			if (!TeaHelper::is_identifier_name($token)) {
				return null;
			}

			$this->scan_token_ignore_empty();
		}

		if (!$this->is_in_tea_declaration && TypeFactory::exists_type($token)) {
			throw $this->new_parse_error("Cannot use type '$token' as a class/interface");
		}

		$identifier = $this->read_classkindred_identifier_with($token, $is_based_root_ns);

		if ($this->skip_token(_GENERIC_OPEN)) {
			$identifier->generic_types = $this->read_generic_types();
			$this->expect_token(_GENERIC_CLOSE);
		}

		return $identifier;
	}

	protected function read_classkindred_identifier_with(string $name, bool $is_based_root_ns = false)
	{
		$ns_components = $is_based_root_ns ? [''] : [];

		while ($this->skip_token(static::NS_SEPARATOR)) {
			$ns_components[] = $name;
			$name = $this->expect_identifier_token();
		}

		$identifier = $this->factory->create_classkindred_identifier($name);

		if ($ns_components) {
			$ns = $this->create_namespace_identifier($ns_components);
			$identifier->set_namespace($ns);
		}

		$identifier->pos = $this->pos;

		return $identifier;
	}

	private function create_namespace_identifier(array $names)
	{
		$ns = $this->factory->create_namespace_identifier($names);
		$ns->pos = $this->pos;

		return $ns;
	}

	private function read_generic_types()
	{
		$map = [];
		do {
			$key = $this->scan_token_ignore_space();
			if (!TeaHelper::is_identifier_name($key)) {
				throw $this->new_parse_error("Expected a identifier for generic key");
			}

			$type = $this->scan_type_expression();
			if ($type === null) {
				throw $this->new_parse_error("Expected a identifier for generic type");
			}

			$map[$key] = $type;
		}
		while ($this->skip_token_ignore_empty(_COMMA) and $this->get_token_ignore_space() !== _GENERIC_CLOSE);

		return $map;
	}

	protected function read_type_hints_for_declaration(IDeclaration $decl)
	{
		$decl->declared_type = $this->scan_type_expression();
		if ($this->skip_token_ignore_space(_SHARP)) {
			$decl->noted_type = $this->scan_type_expression();
		}
	}

	protected function scan_type_expression(): ?IType
	{
		// the callable type
		$token = $this->get_token_ignore_space();
		if ($token === _PAREN_OPEN) { // the Callable protocol
			$type = $this->read_callable_protocol();
			return $type;
		}

		$type = $this->scan_type_identifier();
		if ($type !== null) {
			// union with members
			while ($this->skip_token_ignore_space(_TYPE_UNION)) {
				$member_type = $this->scan_type_identifier();
				if ($member_type === null) {
					throw $this->new_parse_error("Expected member type identifier");
				}

				$type = $type->unite_type($member_type);
			}

			$this->scan_nullable_for($type);
		}

		return $type;
	}

	private function scan_type_identifier(): ?IType
	{
		$token = $this->get_token_ignore_space();

		if ($type = TypeFactory::get_type($token)) {
			$this->scan_token_ignore_space(); // skip
		}
		elseif (TeaHelper::is_identifier_name($token)) {
			$this->scan_token_ignore_space(); // skip
			$type = $this->read_classkindred_identifier_with($token);
		}
		elseif ($token === static::NS_SEPARATOR) {
			$type = $this->scan_classkindred_identifier();
		}
		else {
			return null;
		}

		$this->scan_nullable_for($type);

		// try read Dict/Array
		$next = $this->get_token();
		if ($next === _BRACKET_OPEN) {
			// the String[][:] style compound type
			$type = $this->read_bracket_style_compound_type($type);
		}
		elseif ($next === _DOT) {
			// the String.Array.Dict style compound type
			$type = $this->read_dots_style_compound_type($type);
		}

		return $type;
	}

	private function read_simple_type_identifier()
	{
		$token = $this->get_token_ignore_space();

		if ($type = TypeFactory::get_type($token)) {
			$this->scan_token_ignore_space(); // skip
		}
		elseif (TeaHelper::is_identifier_name($token)) {
			$this->scan_token_ignore_space(); // skip
			$type = $this->read_classkindred_identifier_with($token);
		}
		else {
			throw $this->new_unexpected_error();
		}

		return $type;
	}

	private function scan_nullable_for(IType &$type)
	{
		if ($this->skip_token(_QUESTION)) {
			if ($type instanceof BaseType) {
				$type = $type->get_nullable_instance();
			}
			else {
				$type->let_nullable();
			}

			$type->pos = $this->pos;
		}
	}

	protected function read_dots_style_compound_type(IType $generic_type): IType
	{
		// e.g. String.Dict
		// e.g. String.Array

		$type = $generic_type;
		$i = 0;
		while ($this->skip_token(_DOT)) {
			if ($i === _STRUCT_DIMENSIONS_MAX) {
				throw $this->new_parse_error('The dimensions of type identifier exceeds, the max is ' . _STRUCT_DIMENSIONS_MAX);
			}

			$kind = $this->scan_token();
			switch ($kind) {
				case _DOT_SIGN_ARRAY:
					$type = TypeFactory::create_array_type($type);
					break;
				case _DOT_SIGN_DICT:
					$type = TypeFactory::create_dict_type($type);
					break;
				case _DOT_SIGN_METATYPE:
					$type = TypeFactory::create_meta_type($type);
					break;
				default:
					throw $this->new_unexpected_error();
			}

			$i++;
		}

		$type->pos = $this->pos;
		return $type;
	}

	protected function read_bracket_style_compound_type(IType $generic_type): IType
	{
		$type = $generic_type;
		$i = 0;
		while ($this->skip_token(_BRACKET_OPEN)) {
			if ($i === _STRUCT_DIMENSIONS_MAX) {
				throw $this->new_parse_error('The dimensions of Array/Dict exceeds, the max is ' . _STRUCT_DIMENSIONS_MAX);
			}

			if ($this->skip_token(_COLON)) {
				$type = TypeFactory::create_dict_type($type);
			}
			else {
				$type = TypeFactory::create_array_type($type);
			}

			if (!$this->skip_token(_BRACKET_CLOSE)) {
				throw $this->new_unexpected_error();
			}

			$i++;
		}

		$type->pos = $this->pos;
		return $type;
	}

	private function read_class_member_declaration()
	{
		$token = $this->get_token_ignore_space();
		if ($token === null || $token === _BLOCK_END) {
			return null;
		}

		$this->scan_token_ignore_space();

		if ($token === LF) {
			// has leading empty line
			// will ignore doc when has empty lines
			$decl = $this->read_class_member_declaration();
			$decl and $decl->leading_br = $token;
			return $decl;
		}

		$this->trace_statement($token);

		$modifier = null;
		if (TeaHelper::is_modifier($token)) {
			if ($token === _MASKED) {
				return $this->read_masked_declaration();
			}

			$modifier = $token;
			$token = $this->scan_token_ignore_space();
		}

		// interface required public member
		if ($this->is_interface_mode and $modifier !== null and $modifier !== _PUBLIC) {
			throw $this->new_parse_error('Access modifier for interface member must be public');
		}

		$static = false;
		if ($token === _STATIC) {
			$static = true;
			$token = $this->scan_token_ignore_space();
		}

		switch ($token) {
			case _VAR:
				$token = $this->expect_identifier_token_ignore_space();
				$decl = $this->read_property_declaration($token, $modifier, $static);
				break;
			case _CONST:
				$token = $this->expect_identifier_token_ignore_space();
				$decl = $this->read_class_constant_declaration($token, $modifier);
				break;
			case _FUNC:
				$token = $this->expect_identifier_token_ignore_space();
				$decl = $this->read_method_declaration($token, $modifier, $static);
				break;
			case _DOC_MARK:
				$doc = $this->read_doc_comment();
				$decl = $this->read_class_member_declaration();
				$decl and $decl->doc = $doc;
				break;
			case _LINE_COMMENT_MARK:
				$this->skip_current_line();
				$decl = $this->read_class_member_declaration();
				break;
			default:
				if (!TeaHelper::is_identifier_name($token)) {
					throw $this->new_unexpected_error();
				}

				if ($this->get_token() === _PAREN_OPEN) {
					$decl = $this->read_method_declaration($token, $modifier, $static);
				}
				elseif (TeaHelper::is_normal_constant_name($token)) {
					$decl = $this->read_class_constant_declaration($token, $modifier);
				}
				else {
					$decl = $this->read_property_declaration($token, $modifier, $static);
				}
		}

		return $decl;
	}

	// need defer check
	protected function read_compile_time_value()
	{
		return $this->read_expression();
	}

	protected function read_class_constant_declaration(string $name, ?string $modifier)
	{
		$decl = $this->factory->create_class_constant_declaration($modifier, $name);
		$decl->pos = $this->pos;

		$this->read_type_hints_for_declaration($decl);

		if ($this->skip_token_ignore_space(_ASSIGN)) {
			$decl->value = $this->read_compile_time_value();
		}
		elseif (!$this->is_declare_mode) {
			throw $this->new_parse_error('Expected value assign expression');
		}
		elseif (!$decl->declared_type) {
			throw $this->new_parse_error('Expected type expression');
		}

		$this->factory->end_class_member();
		$this->expect_statement_end();

		return $decl;
	}

	protected function read_property_declaration(string $name, ?string $modifier, bool $static)
	{
		if ($this->is_interface_mode) {
			throw $this->new_parse_error('Interfaces could not include properties');
		}

		// prop1 String
		// prop1 = 'abcdef'
		// prop1 String = 'abcdef'
		// public prop1 String = 'abcdef'

		$decl = $this->factory->create_property_declaration($modifier, $name);
		$decl->pos = $this->pos;

		$this->read_type_hints_for_declaration($decl);

		$token = $this->get_token_ignore_space();

		if ($token === _ASSIGN) {
			// the assign expression
			$this->scan_token_ignore_space(); // skip =
			$decl->value = $this->read_compile_time_value();
		}

		$decl->is_static = $static;

		$this->factory->end_class_member();
		$this->expect_statement_end();

		return $decl;
	}

	protected function read_method_declaration(string $name, ?string $modifier, bool $static)
	{
		$name = self::METHOD_MAP[$name] ?? $name;
		$decl = $this->factory->create_method_declaration($modifier, $name);
		$decl->pos = $this->pos;

		$decl->is_static = $static;

		$parameters = $this->read_parameters_with_parentheses();
		$this->factory->set_scope_parameters($parameters);

		$this->read_type_hints_for_declaration($decl);

		$next = $this->get_token_ignore_empty();
		if ($next === _BLOCK_BEGIN) {
			if ($this->is_interface_mode) {
				$this->scan_token_ignore_space();
				throw $this->new_parse_error('Member of interface only supported declaration mode');
			}

			$this->read_body_for_decl($decl);
		}
		elseif ($this->is_declare_mode) {
			// no any
		}
		else {
			throw $this->new_parse_error('Method body required');
		}

		$this->factory->end_class_member();
		$this->expect_statement_end();

		return $decl;
	}

	// protected function scan_callback_protocols()
	// {
	// 	if (!$this->skip_token_ignore_empty(_NOTIFY)) {
	// 		return null;
	// 	}

	// 	$items = [];
	// 	while ($item = $this->read_callback_protocol()) {
	// 		$items[] = $item;
	// 		if (!$this->skip_token_ignore_empty(_NOTIFY)) {
	// 			break;
	// 		}
	// 	}

	// 	return $items;
	// }

	// protected function read_callback_protocol()
	// {
	// 	$is_async = false;
	// 	$name = $this->scan_token_ignore_empty();
	// 	if ($name === _ASYNC) {
	// 		$is_async = true;
	// 		$name = $this->scan_token_ignore_empty();
	// 	}

	// 	if (!TeaHelper::is_normal_function_name($name)) {
	// 		throw $this->new_unexpected_error();
	// 	}

	// 	$parameters = $this->read_parameters_with_parentheses();
	// 	$return_type = $this->scan_return_type();

	// 	$node = new CallbackProtocol($is_async, $name, $return_type, ...$parameters);
	// 	$node->pos = $this->pos;

	// 	return $node;
	// }

	protected function read_parameters_with_parentheses()
	{
		$this->expect_token_ignore_empty(_PAREN_OPEN);
		$items = $this->read_parameters();
		$this->expect_token_ignore_empty(_PAREN_CLOSE);

		return $items;
	}

	private function read_parameters()
	{
		$items = [];
		while ($item = $this->scan_parameter()) {
			$items[] = $item;
			if (!$this->skip_comma()) {
				break;
			}
		}

		return $items;
	}

	protected function scan_parameter()
	{
		$token = $this->get_token_ignore_empty();
		if ($token === _PAREN_CLOSE) {
			return null;
		}

		$this->scan_token_ignore_empty();
		return $this->read_parameter_with_token($token);
	}

	protected function read_parameter_with_token(string $name)
	{
		// param Int = 1
		// param mut Array  // for mutable parameters
		// param inout Int  // for inout parameters

		$inout_mode = false;
		$mutable_mode = false;

		if (!TeaHelper::is_normal_variable_name($name)) {
			throw $this->new_unexpected_error();
		}

		if ($this->skip_token_ignore_space(_INOUT)) {
			$inout_mode = true;
		}

		if ($this->skip_token_ignore_space(_MUT)) {
			$mutable_mode = true;
		}

		$parameter = $this->create_parameter($name);

		$this->read_type_hints_for_declaration($parameter);
		$next = $this->get_token_ignore_space();

		// if ($mutable_mode && !($type instanceof ArrayType || $type instanceof DictType)) {
		// 	throw $this->new_parse_error("Cannot use the value-mutable for '$type->name' type parameter");
		// }

		if ($next === _ASSIGN) {
			$this->scan_token_ignore_space();
			$parameter->value = $this->read_compile_time_value();
		}

		if ($inout_mode) {
			if ($parameter->value && $parameter->value !== ASTFactory::$default_value_mark) {
				throw $this->new_parse_error("Cannot set a default value for inout mode parameter");
			}

			$parameter->is_inout = true;
		}

		if ($mutable_mode) {
			$parameter->is_mutable = true;
		}

		return $parameter;
	}

	protected function read_callable_protocol()
	{
		// (Parameters) ReturnType 		// normal
		// ((Parameters) ReturnType)?   // nullable

		$this->expect_token_ignore_empty(_PAREN_OPEN);
		$has_outer_paren = $this->skip_token_ignore_space(_PAREN_OPEN);

		$parameters = $this->read_parameters();
		$this->expect_token_ignore_empty(_PAREN_CLOSE);

		$return_type = $this->scan_type_expression();
		if ($return_type === null) {
			throw $this->new_parse_error("Required hinting the return type for callable protocol");
		}

		$node = TypeFactory::create_callable_type($return_type, $parameters);

		if ($has_outer_paren) {
			$this->expect_token_ignore_empty(_PAREN_CLOSE);
			$nullable = $this->skip_token_ignore_space(_QUESTION);
			if ($nullable) {
				$node->let_nullable();
			}
		}

		$node->pos = $this->pos;
		return $node;
	}
}

// end
