<?php
/**
 * This file is part of the Tea programming language project
 *
 * @author 		Benny <benny@meetdreams.com>
 * @copyright 	(c)2019 YJ Technology Ltd. [http://tealang.org]
 * For the full copyright and license information, please view the LICENSE file that was distributed with this source code.
 */

namespace Tea;

class TeaParser extends BaseParser
{
	use TeaTokenTrait, TeaStringTrait, TeaXBlockTrait, TeaSharpTrait, TeaDocsTrait;

	protected const IS_IN_HEADER = false;

	protected $is_declare_mode = false;

	protected $root_statements = [];

	public function read_program(): Program
	{
		$program = $this->program;

		while ($this->pos < $this->tokens_count) {
			$item = $this->read_root_statement();
			if ($item instanceof IRootDeclaration) {
				$program->append_declaration($item);
			}
			elseif ($item) {
				$this->root_statements[] = $item;
			}
		}

		$this->factory->end_program();

		// set statements to main function when has any onload executings
		if ($this->root_statements) {
			$program->main_function->set_body_with_statements(...$this->root_statements);
		}
		else {
			$program->main_function = null;
		}

		return $program;
	}

	protected function read_root_statement($leading = null, Docs $docs = null)
	{
		$token = $this->scan_token_ignore_space();
		if ($token === LF) {
			return $this->read_root_statement(LF);
		}
		elseif ($token === _SEMICOLON || $token === null) {
			// empty statement, or at the end of program
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
		elseif (TeaHelper::is_structure_key($token)) {
			$node = $this->read_structure($token);
		}
		elseif (TeaHelper::is_modifier($token)) {
			$node = $this->read_definition_with_modifier($token);
		}
		// elseif (TeaHelper::is_classlike_name($token) && ($node = $this->try_read_classlike_declaration($token))) {
		// 	// that's the class declaration
		// }
		else {
			$node = $this->read_expression_with_token($token);
			if ($this->is_next_assign_operator()) {
				$node = $this->read_assignment($node);
			}
			elseif ($node instanceof IExpression) {
				// for normal expression
				$node = new NormalStatement($node);
			}
		}

		$this->expect_statement_end();

		if ($node !== null) {
			$node->leading = $leading;
			$node->docs = $docs;
		}

		return $node;
	}

	protected function read_normal_statement($leading = null, Docs $docs = null, bool $is_in_when_branch = false)
	{
		if ($this->get_token_ignore_space() === _BLOCK_END) {
			return null;
		}

		$token = $this->scan_token_ignore_space();
		if ($token === LF) {
			return $this->read_normal_statement(LF, null, $is_in_when_branch);
		}
		elseif ($token === _SEMICOLON || $token === null) {
			return null;
		}

		$this->trace_statement($token);

		if ($token === _SHARP) {
			$node = $this->read_label_statement();
		}
		elseif ($token === _DOCS_MARK) {
			$docs = $this->read_docs();
			return $this->read_normal_statement($leading, $docs, $is_in_when_branch);
		}
		elseif ($token === _INLINE_COMMENT_MARK) {
			$this->skip_current_line();
			return $this->read_normal_statement($leading, $docs, $is_in_when_branch);
		}
		elseif (TeaHelper::is_structure_key($token)) {
			$node = $this->read_structure($token);
		}
		else {
			$node = $this->read_expression_with_token($token);
			if ($this->is_next_assign_operator()) {
				$node = $this->read_assignment($node);
			}
			elseif ($node instanceof IExpression) {
				if ($is_in_when_branch && in_array($this->get_token_ignore_space(), [_COLON, _COMMA])) {
					return $node;
				}

				// for normal expression
				$node = new NormalStatement($node);
			}
		}

		$this->expect_statement_end();

		$node->leading = $leading;
		$node->docs = $docs;

		return $node;
	}

	protected function read_assignment($assignalbe)
	{
		if (!$assignalbe instanceof IAssignable) {
			throw $this->new_parse_error("Assignment cannot put in any expression.");
		}

		$operator = $this->scan_token_ignore_empty();
		$value = $this->read_expression();

		$node = $this->factory->create_assignment($assignalbe, $value, $operator);

		return $node;
	}

	protected function assert_not_reserveds_word($token)
	{
		if (TeaHelper::is_normal_reserveds($token)) {
			throw $this->new_parse_error("'$token' is a reserveds word, cannot use for a class/function/constant/variable name.");
		}
	}

	protected function read_definition_with_modifier(string $modifier)
	{
		$token = $this->scan_token_ignore_space();

		$this->assert_not_reserveds_word($token);

		if (TeaHelper::is_function_name($token)) {
			return $this->read_function_declaration($token, $modifier);
		}

		if (TeaHelper::is_classlike_name($token)) {
			// maybe is a constant in some times, so need to try read class
			if ($class = $this->try_read_classlike_declaration($token, $modifier)) {
				$class->define_mode = true; // for check
				return $class;
			}
		}

		if (TeaHelper::is_constant_name($token)) {
			return $this->read_constant_declaration($token, $modifier);
		}

		throw $this->new_unexpected_error();
	}

	protected function read_function_declaration(string $name, ?string $modifier, bool $declare_mode = false)
	{
		// func1(arg0 String, arg1 Int = 0) // declare mode has no body
		// func1(arg0 String, arg1 Int = 0) { ... } // normal mode required body

		$declaration = $this->factory->create_function_declaration($modifier, $name);
		$declaration->pos = $this->pos;

		$parameters = $this->read_parameters_with_parentheses();
		$this->factory->set_enclosing_parameters($parameters);

		$declaration->type = $this->try_read_return_type_identifier();

		if (!$declare_mode) {
			$this->read_body_statements($declaration);
		}

		$this->factory->end_root_declaration();

		return $declaration;
	}

	protected function read_block_body(BaseBlock $block)
	{
		$this->read_body_statements($block);
		$this->factory->end_block();
	}

	protected function read_body_statements(BaseBlock $block)
	{
		$this->expect_block_begin_inline();

		$items = [];
		while (($item = $this->read_normal_statement()) !== null) {
			$items[] = $item;
		}

		$this->expect_block_end();

		$block->set_body_with_statements(...$items);
	}

	protected function read_constant_declaration(string $name, string $modifier = null)
	{
		if ($this->root_statements) {
			throw $this->new_parse_error("Please define the constants at header of the program.");
		}

		$declaration = $this->read_constant_declaration_header($name, $modifier);

		$this->expect_token_ignore_space(_ASSIGN);
		$declaration->value = $this->read_expression();

		$this->factory->end_root_declaration();

		return $declaration;
	}

	protected function read_constant_declaration_without_value(string $name, string $modifier = null)
	{
		$this->assert_not_reserveds_word($name);

		$declaration = $this->read_constant_declaration_header($name, $modifier);
		if (!$declaration->type) {
			$this->new_unexpected_error();
		}

		$this->factory->end_root_declaration();

		return $declaration;
	}

	protected function read_constant_declaration_header(string $name, string $modifier = null)
	{
		$declaration = $this->factory->create_constant_declaration($modifier, $name);
		$declaration->pos = $this->pos;
		$declaration->type = $this->try_read_type_identifier();
		return $declaration;
	}

	protected function read_structure(string $type)
	{
		switch ($type) {
			case _VAR:
				$node = $this->read_variable_declaration();
				break;
			case _RETURN:
				$node = $this->read_return_statement();
				break;
			case _ECHO:
				$node = $this->read_echo_statement();
				break;
			case _PRINT:
				$node = $this->read_print_statement();
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
			case _EXIT:
				$node = $this->read_exit_declaration();
				break;

			case _IF:
				$node = $this->read_if_block();
				break;
			case _WHEN:
				$node = $this->read_when_block();
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
				throw $this->new_unexpected_error();
		}

		return $node;
	}

	protected function read_masked_declaration()
	{
		// masked name(...) => fn(...)

		// the declaration options
		$name = $this->scan_token_ignore_space();
		if (!TeaHelper::is_function_name($name)) {
			throw $this->new_unexpected_error();
		}

		$declaration = $this->factory->create_masked_declaration($name);
		$declaration->pos = $this->pos;

		if ($this->get_token() === _PAREN_OPEN) {
			$parameters = $this->read_parameters_with_parentheses();
			$this->factory->set_enclosing_parameters($parameters);
		}

		$declaration->type = $this->try_read_return_type_identifier();

		$this->expect_token_ignore_empty(_ARROW);

		$declaration->set_body_with_expression($this->read_expression());

		return $declaration;
	}

	protected function read_print_statement()
	{
		// print
		// print argument0, argument1, ...

		$args = $this->read_inline_arguments();
		if (!$args) {
			throw $this->new_parse_error("Required arguments for print.");
		}

		$node = new EchoStatement(...$args);
		$node->end_newline = false;

		return $node;
	}

	protected function read_echo_statement()
	{
		// echo
		// echo argument0, argument1, ...

		$args = $this->read_inline_arguments();
		return new EchoStatement(...$args);
	}

	protected function read_variable_declaration()
	{
		// var abc
		// var abc Type
		// var abc Type = expression

		$name = $this->scan_token_ignore_empty();

		$type = $this->try_read_type_identifier();

		$value = null;
		if ($this->get_token_ignore_empty() === _ASSIGN) {
			$this->scan_token_ignore_empty();
			$value = $this->read_expression();
		}

		return $this->factory->create_variable_declaration($name, $type, $value);
	}

	protected function try_attach_post_condition(PostConditionAbleStatement $statement)
	{
		if (!$this->skip_token_ignore_space(_WHEN)) {
			return;
		}

		$condition = $this->read_expression_inline();
		if ($condition === null) {
			throw $this->new_parse_error("Required condition expression after 'when' keyword.");
		}

		$statement->condition = $condition;
	}

	protected function try_attach_goto_label(IGotoAbleStatement $statement, bool $is_continue_statement = false)
	{
		if (!$this->skip_token_ignore_space(_SHARP)) {
			return;
		}

		$goto_label = $this->expect_identifier_token();
		$statement->layer_num = $this->factory->require_labeled_layer_number($goto_label, $is_continue_statement);
		$statement->goto_label = $goto_label;
	}

	protected function read_continue_statement()
	{
		// continue
		// continue #goto_label
		// continue #goto_label when condition-expression

		$statement = new ContinueStatement();
		$this->try_attach_goto_label($statement, true);
		$this->try_attach_post_condition($statement);

		return $statement;
	}

	protected function read_break_statement()
	{
		// break
		// break #goto_label
		// break #goto_label when condition-expression

		$statement = new BreakStatement();
		$this->try_attach_goto_label($statement);
		$this->try_attach_post_condition($statement);

		return $statement;
	}

	protected function read_exit_declaration()
	{
		// exit
		// exit int expression
		// exit when condition-expression

		if ($this->get_token_ignore_space() === _WHEN) {
			$argument = null;
		}
		else {
			$argument = $this->read_expression_inline();
		}

		$statement = new ExitStatement($argument);
		$this->try_attach_post_condition($statement);

		return $statement;
	}

	protected function read_return_statement()
	{
		// return
		// return expression
		// return when condition-expression

		if ($this->get_token_ignore_space() === _WHEN) {
			$argument = null;
		}
		else {
			$argument = $this->read_expression_inline();
		}

		$statement = new ReturnStatement($argument);
		$this->try_attach_post_condition($statement);

		return $statement;
	}

	protected function read_throw_statement()
	{
		// throw Exception()

		$argument = $this->read_expression_inline();
		if ($argument === null) {
			throw $this->new_parse_error("Required an Exception argument.");
		}

		$statement = new ThrowStatement($argument);
		$this->try_attach_post_condition($statement);

		return $statement;
	}

	protected function read_if_block()
	{
		// if test_expression { ... } [elseif test_expression {...}] [else { ... }] [catch e Exception {}] finally {}

		$test = $this->read_expression();
		$master_block = $this->factory->create_if_block($test);
		$this->read_block_body($master_block);

		$this->try_attach_else_block($master_block);
		$this->try_attach_except_block($master_block);

		return $master_block;
	}

	protected function try_attach_else_block(IElseAble $master_block)
	{
		$keyword = $this->get_token_ignore_empty();

		if ($keyword === _ELSE) {
			$this->scan_token_ignore_empty();
			$sub_block = $this->factory->create_else_block();
			$this->read_block_body($sub_block);

			// else block would be the end
		}
		elseif ($keyword === _ELSEIF) {
			$this->scan_token_ignore_empty();
			$test = $this->read_expression();
			$sub_block = $this->factory->create_elseif_block($test);
			$this->read_block_body($sub_block);

			// another else block
			$this->try_attach_else_block($sub_block);
		}
		elseif ($keyword === _INLINE_COMMENT_MARK) {
			$current_pos = $this->pos;
			// $current_line = $this->current_line;

			$this->scan_token_ignore_empty(); // skip the //
			$this->skip_inline_comment(); //  skip the comment
			$result = $this->try_attach_else_block($master_block);

			// Avoid of ignoring empty lines
			if ($result === null) {
				$this->pos = $current_pos;
				// $this->current_line = $current_line;
			}

			return;
		}
		else {
			return;
		}

		$master_block->set_else_block($sub_block);
	}

	protected function read_try_block()
	{
		$master_block = $this->factory->create_try_block();
		$this->read_block_body($master_block);

		$this->try_attach_except_block($master_block);

		return $master_block;
	}

	protected function try_attach_except_block(IExceptAble $master_block)
	{
		$keyword = $this->get_token_ignore_empty();

		if ($keyword === _CATCH) {
			$this->scan_token_ignore_empty();

			$var_name = $this->scan_token_ignore_space();
			if (!TeaHelper::is_declarable_variable_name($var_name)) {
				throw $this->new_parse_error("Invalid variable name '{$var_name}'", 1);
			}

			$type = $this->try_read_classlike_identifier();
			$sub_block = $this->factory->create_catch_block($var_name, $type);

			$this->read_block_body($sub_block);

			// another except block
			$this->try_attach_except_block($sub_block);
		}
		elseif ($keyword === _FINALLY) {
			$this->scan_token_ignore_empty();

			$sub_block = $this->factory->create_finally_block();
			$this->read_block_body($sub_block);

			// finally block would be the end
		}
		else {
			// no any to attach
			return;
		}

		$master_block->set_except_block($sub_block);
	}

	protected function read_when_block(string $label = null)
	{
		// case test-expression { branches }

		$argument = $this->read_expression();

		$master_block = $this->factory->create_when_block($argument);
		$master_block->label = $label;

		$branches = $this->read_when_branches();
		$master_block->set_branches(...$branches);

		$this->try_attach_else_block($master_block);
		$this->try_attach_except_block($master_block);

		return $master_block;
	}

	protected function read_when_branches()
	{
		$this->expect_block_begin_inline();

		// the rule expression of first branch
		$rule_expr = $this->read_expression();
		if (!$rule_expr) {
			throw $this->new_unexpected_error();
		}

		// read branches
		$branches = [];
		while ($rule_expr) {
			// the multi-matches
			if ($this->get_token() === _COMMA) {
				$rule_expr = $this->read_expression_list_with($rule_expr);
			}

			$this->expect_token_ignore_space(_COLON);

			$when_branch = $this->factory->create_when_branch_block($rule_expr);

			$next_rule_expr = null;
			$statements = [];
			while (($item = $this->read_normal_statement(null, null, true)) !== null) {
				if ($item instanceof IExpression) {
					$next_rule_expr = $item;
					break;
				}
				else {
					$statements[] = $item;
				}
			}

			$when_branch->set_body_with_statements(...$statements);
			$branches[] = $when_branch;
			$rule_expr = $next_rule_expr;
		}

		$this->expect_block_end();

		return $branches;
	}

	protected function read_expression_list_with(IExpression $expression)
	{
		$list = [$expression];
		while ($this->skip_token_ignore_space(_COMMA)) {
			$expression = $this->read_expression();
			if ($expression === null) {
				throw $this->new_unexpected_error();
			}

			$list[] = $expression;
		}

		return new ExpressionList(...$list);
	}

	protected function read_for_block(string $label = null)
	{
		// for k, v in items {} else {}
		// for i = 0 to 9 {}  // the default step is 1
		// for i = 0 to 9 step 2 {}  // with step option
		// for i = 9 downto 0 {}  // the default step is 1
		// for i = 9 downto 0 step 2 {}

		$value_var = $this->read_variable_identifier();

		if ($this->skip_token_ignore_space(_ASSIGN)) {
			// the for-to mode
			$master_block = $this->read_forto_block_with($value_var);
		}
		else {
			// the for-in mode
			$master_block = $this->read_forin_block_with($value_var);
		}

		$master_block->label = $label;
		$this->read_block_body($master_block);

		// the iterable need test at if-block
		// so need assign to a temp variable on render the target code
		$this->try_attach_else_block($master_block);

		$this->try_attach_except_block($master_block);

		return $master_block;
	}

	protected function read_forto_block_with(VariableIdentifier $value_var)
	{
		$start = $this->read_expression();

		$mode = $this->scan_token_ignore_space();
		if ($mode !== _TO && $mode !== _DOWNTO) {
			throw $this->new_unexpected_error();
		}

		$end = $this->read_expression();

		if ($this->skip_token_ignore_space(_STEP)) {
			$step = $this->scan_token_ignore_space();
			if (!TeaHelper::is_uint_number($step)) {
				throw $this->new_parse_error('Required unsigned int literal value for "step" in for-to/for-downto statement.');
			}

			$step = intval($step);
			if ($step === 0) {
				throw $this->new_parse_error('"step" cannot set to 0.');
			}
		}

		$block = $this->factory->create_forto_block($value_var, $start, $end, $step ?? null);

		if ($mode === _DOWNTO) {
			$block->is_downto_mode = true;
		}

		return $block;
	}

	protected function read_forin_block_with(VariableIdentifier $value_var)
	{
		$key_var = null;
		if ($this->skip_comma()) {
			$key_var = $value_var;
			$value_var = $this->read_variable_identifier();
		}

		$this->expect_token_ignore_empty(_IN);

		$iterable = $this->read_expression();
		$block = $this->factory->create_forin_block($iterable, $key_var, $value_var);

		return $block;
	}

	protected function read_while_block(string $label = null)
	{
		// while test_expression {}
		// while #first or test_expression {}

		$do_the_first = false;
		if ($this->skip_token_ignore_space(_SHARP)) {
			if (!$this->skip_token_ignore_space(_FIRST) || !$this->skip_token_ignore_space(_OR)) {
				throw $this->new_unexpected_error();
			}

			$do_the_first = true;
		}

		$test = $this->read_expression();
		$master_block = $this->factory->create_while_block($test);
		$master_block->label = $label;

		$this->read_block_body($master_block);

		if ($do_the_first) {
			$master_block->do_the_first = true;
			if ($this->skip_token_ignore_empty(_ELSE) || $this->skip_token_ignore_empty(_ELSEIF)) {
				throw $this->new_unexpected_error();
			}
		}
		// else { // do not support else/elseif in while-block
		// 	// the while condition would be test in every loop, so need a tmp var to record is looped
		// 	$else_block = $this->try_read_else_block();
		// 	$else_block && $master_block->set_else_block($else_block);
		// }

		$this->try_attach_except_block($master_block);

		return $master_block;
	}

// -------- statement end

	protected function read_expression_inline()
	{
		if ($this->get_token() === LF) {
			return null;
		}

		return $this->read_expression();
	}

	const EXPRESSION_ENDINGS = [null, _PAREN_CLOSE, _BRACKET_CLOSE, _BLOCK_END, _SEMICOLON];

	protected function read_expression(OperatorSymbol $prev_operator = null)
	{
		$token = $this->scan_token_ignore_empty();
		if (in_array($token, self::EXPRESSION_ENDINGS, true)) {
			$this->back();
			return null;
		}

		$expression = $this->read_expression_with_token($token, $prev_operator);

		return $expression;
	}

	protected function read_argument()
	{
		$token = $this->scan_token_ignore_empty();
		if (in_array($token, self::EXPRESSION_ENDINGS, true)) {
			$this->back();
			return null;
		}

		$label = null;
		if ($this->skip_colon()) {
			$label = $token;
			$token = $this->scan_token_ignore_space();
		}

		$expression = $this->read_expression_with_token($token);
		$expression and $expression->pos = $this->pos;

		$expression->label = $label;

		return $expression;
	}

	protected function read_expression_with_token(string $token, OperatorSymbol $prev_operator = null)
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
				$expression = $this->read_arraylike_expression();
				break;

			// case _BRACE_OPEN: // the object declaration begin
			// 	$expression = $this->read_object_expression();
			// 	break;

			case _XTAG_OPEN:
				$expression = $this->read_xblock();
				$expression->pos || $expression->pos = $this->pos;
				return $expression; // that should be the end of current expression

			case _SHARP:
				$expression = $this->read_sharp_expression_with($this->scan_token());
				if ($expression === null) {
					throw $this->new_unexpected_error();
				}
				break;

			case _YIELD:
				$argument = $this->read_expression();
				if ($argument === null) {
					throw $this->new_unexpected_error();
				}

				$expression = $this->factory->create_yield_expression($argument);
				$expression->pos || $expression->pos = $this->pos;
				return $expression;

			default:
				// maybe a regex
				if ($token === _SLASH) {
					$expression = $this->try_read_regular_expression();
					if ($expression !== null) {
						break;
					}
				}

				// check is prefix operator
				$check_operator = OperatorFactory::get_prefix_operator_symbol($token);
				if ($check_operator !== null) {
					$expression = $this->read_prefix_operation($check_operator);
					break;
				}

				// identifier
				if (TeaHelper::is_identifier_name($token)) {
					$expression = $this->factory->create_identifier($token);
					break;
				}

				// the number literal
				if (($base_type = TeaHelper::check_number($token)) !== null) {
					$expression = $this->read_number_with($token, $base_type);
					break;
				}

				if (_INLINE_COMMENT_MARK === $token) {
					$this->skip_inline_comment(); // the // ...
					return $this->read_expression($prev_operator);
				}
				elseif (_DOLLAR === $token) {
					$expression = $this->read_super_variable_identifier();
					break;
				}

				throw $this->new_unexpected_error();
		}

		$expression->pos || $expression->pos = $this->pos;
		$expression = $this->read_expression_combination($expression, $prev_operator);

		$expression->pos || $expression->pos = $this->pos;
		if ($this->get_token_ignore_space() === _INLINE_COMMENT_MARK) {
			$expression->tailing = $this->skip_inline_comment();
		}

		return $expression;
	}

	protected function read_variable_identifier()
	{
		$token = $this->scan_token_ignore_space();
		if (TeaHelper::is_declarable_variable_name($token)) {
			return new VariableIdentifier($token);
		}

		throw $this->new_parse_error("Invalid variable name '{$token}'", 1);
	}

	protected function read_super_variable_identifier()
	{
		$token = $this->scan_token();
		if (TeaHelper::is_identifier_name($token)) {
			return new VariableIdentifier($token);
		}

		throw $this->new_parse_error("Invalid super-variable name '{$token}'", 1);
	}

	protected function read_prefix_operation(OperatorSymbol $operator)
	{
		$expression = $this->read_expression($operator);
		if ($expression === null) {
			throw $this->new_unexpected_error();
		}

		return new PrefixOperation($operator, $expression);
	}

	protected function read_parentheses_expression(bool $is_in_parentheses = false)
	{
		$is_in_parentheses || $this->expect_token_ignore_empty(_PAREN_OPEN);

		$expression = $this->read_expression();

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
		if ($this->get_token_ignore_space() === _ARROW) {
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
		if (!TeaHelper::is_declarable_variable_name($readed_identifier->name)) {
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

		while ($parameter = $this->try_read_parameter()) {
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
			throw $this->new_parse_error("Separator '_' can not put at the end of numeric literals.");
		}

		if ($base_type === _BASE_DECIMAL) {
			// has dot
			if ($this->skip_token(_DOT)) {
				$fractional_part = $this->scan_token();
				if (!preg_match('/^[0-9_]+(e\+?[0-9_]*)?$/i', $fractional_part)) {
					throw $this->new_unexpected_error();
				}

				$token .= _DOT . $fractional_part; // the real type number

				if ($token[-1] === _LOW_CASE_E || $token[-1] === _UP_CASE_E) {
					// eg. 0.123e-6 or 0.123E-6
					return $this->read_scientific_notation_number_with($token);
				}

				return new FloatLiteral($token);
			}
			elseif ($token[-1] === _LOW_CASE_E || $token[-1] === _UP_CASE_E) {
				// eg. 123e-6 or 123E-6
				return $this->read_scientific_notation_number_with($token);
			}
		}

		return new UnsignedIntegerLiteral($token);
	}

	protected function read_scientific_notation_number_with(string $prefix)
	{
		// eg. 123e-6 or 123e+6

		// '+' or '-'
		$modifier = $this->get_token();
		if ($modifier === _NEGATION || $modifier === _ADDITION) {
			$this->scan_token();
			$prefix .= $modifier;
		}

		$exp = $this->scan_token();
		if (!is_numeric($exp)) {
			throw $this->new_unexpected_error();
		}

		return new FloatLiteral($prefix . $exp);
	}

	protected function try_read_regular_expression()
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

	protected function read_conditional_expression_with(IExpression $test)
	{
		// 由于三元条件运算符优先级最低，故可直接读取，无需与子表达式比较优先级

		if ($this->skip_token(_COLON)) {
			$then = null;
		}
		else {
			$this->expect_space("Missed space char after '?'.");
			$then = $this->read_expression();
			if ($then instanceof ConditionalExpression) {
				throw $this->new_parse_error("Required () for compound conditional expressions");
			}

			if ($this->get_token_ignore_empty() === _INLINE_COMMENT_MARK) {
				$this->skip_inline_comment();
			}

			$this->expect_token_ignore_empty(_COLON);
		}

		$else = $this->read_expression();
		if ($else instanceof ConditionalExpression) {
			throw $this->new_parse_error("Required () for compound conditional expressions");
		}

		$expression = new ConditionalExpression($test, $then, $else);
		$expression->pos = $else->pos;

		return $expression;
	}

	protected function read_object_expression()
	{
		$has_non_literal = false;

		$items = [];
		while ($key = $this->read_object_key()) {
			$this->expect_token_ignore_space(_COLON);

			$expr = $this->read_expression();
			if ($expr === null) {
				throw $this->new_unexpected_error();
			}

			if (!$expr instanceof ILiteral) {
				$has_non_literal = true;
			}

			$items[$key] = $expr;

			if (!$this->skip_comma()) {
				break;
			}
		}

		$this->expect_token_ignore_empty(_BRACE_CLOSE);

		return $has_non_literal ? new ObjectExpression($items) : new ObjectLiteral($items);
	}

	protected function read_object_key()
	{
		$token = $this->get_token_ignore_empty();
		if ($token === null || $token === _BRACE_CLOSE) {
			return null;
		}

		$this->scan_token_ignore_empty();

		if (!TeaHelper::is_declarable_variable_name($token)) {
			throw $this->new_unexpected_error();
		}

		return $token;
	}

	protected function read_arraylike_expression()
	{
		$is_associative = false;
		$has_non_literal_key = false;
		$has_non_literal_value = false;

		$check_token = $this->get_token_ignore_space();
		if ($check_token === LF || $check_token === _INLINE_COMMENT_MARK) {
			$is_vertical_layout = true;
		}
		elseif ($check_token === _COLON) {
			// that is an empty Dict
			$this->scan_token_ignore_space(); // skip colon
			$this->expect_token(_BRACKET_CLOSE);
			return new DictLiteral();
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
		}
		else {
			// non read anything, that should be an empty Array
			$expr = new ArrayLiteral();
		}

		isset($is_vertical_layout) and $expr->is_vertical_layout = $is_vertical_layout;

		$this->expect_token_ignore_empty(_BRACKET_CLOSE);

		return $expr;
	}

	protected function read_array_with_first_item(IExpression $item)
	{
		$has_non_literal = false;
		$items = [];

		do {
			$items[] = $item;

			if (!$item instanceof ILiteral) {
				$has_non_literal = true;
			}

			if (!$this->skip_token(_COMMA)) {
				break;
			}
		} while ($item = $this->read_expression());

		return $has_non_literal ? new ArrayExpression($items) : new ArrayLiteral($items);
	}

	protected function read_dict_with_first_item(IExpression $key)
	{
		$has_non_literal = false;
		$items = [];

		while (true) {
			$value = $this->read_expression();
			if ($value === null) {
				throw $this->new_parse_error("Value expression for dict required.");
			}

			$items[] = new DictItem($key, $value);

			if (!$key instanceof ILiteral || !$value instanceof ILiteral) {
				$has_non_literal = true;
			}

			if (!$this->skip_token(_COMMA)) {
				break;
			}

			// read the next item
			$key = $this->read_expression();
			if ($key === null) {
				break;
			}

			if ($this->scan_token() !== _COLON) {
				throw $this->new_unexpected_error();
			}
		}

		return $has_non_literal ? new DictExpression($items) : new DictLiteral($items);
	}

	protected function read_lambda_combination(array $parameters, $arrow_is_optional = false)
	{
		$return_type = $this->try_read_return_type_identifier();

		$next = $this->get_token_ignore_space();
		if ($next === _ARROW) {
			$this->scan_token_ignore_space();
		}
		elseif (!$arrow_is_optional) {
			throw $this->new_parse_error("Unexpected token '$next', or missed token '=>'.");
		}

		$block = $this->factory->create_lambda_expression($return_type, $parameters);
		$block->pos = $this->pos;

		if ($this->get_token_ignore_empty() === _BLOCK_BEGIN) {
			$this->read_block_body($block);
		}
		else {
			$expression = $this->read_expression();
			$block->set_body_with_expression($expression);
		}

		return $block;
	}

	protected function read_expression_combination(IExpression $expression, OperatorSymbol $prev_operator = null)
	{
		$token = $this->get_token_ignore_empty();

		if ($token === _PAREN_OPEN) {
			// 强限制为紧跟的括号才有效
			if ($this->get_token() !== _PAREN_OPEN) {
				return $expression;
			}

			$call = $this->read_call_expression($expression, $prev_operator);
			$expression = $this->read_expression_combination($call, $prev_operator);
		}
		elseif ($token === _DOT) {
			$this->scan_token_ignore_empty(); // skip .
			$expression = $this->read_dot_expression($expression, $prev_operator);
		}
		elseif ($token === _BRACKET_OPEN) {
			// 强限制为紧跟的括号才有效
			if ($this->get_token() !== _BRACKET_OPEN) {
				return $expression;
			}

			$expression = $this->read_key_accessing($expression, $prev_operator);
		}
		elseif ($token === _INLINE_COMMENT_MARK) {
			$current_pos = $this->pos;
			// $current_line = $this->current_line;

			$this->scan_token_ignore_empty(); // skip the //
			$this->skip_inline_comment(); //  skip the comment
			$continue_combination = $this->read_expression_combination($expression, $prev_operator);

			// Avoid of ignoring empty lines
			if ($continue_combination === $expression) {
				$this->pos = $current_pos;
				// $this->current_line = $current_line;
			}

			return $continue_combination;
		}

		if ($expression instanceof ArrayElementAssignment) {
			if ($prev_operator) {
				throw $this->new_parse_error("ArrayElementAssignment cannot use in BinaryOperation.");
			}

			return $expression;
		}

		// maybe has a operation at behind
		return $this->try_read_operation($expression, $prev_operator);
	}

	protected function read_dot_expression(IExpression $master, OperatorSymbol $prev_operator = null)
	{
		// class / object member call

		$member = $this->scan_token();
		if (!TeaHelper::is_identifier_name($member)) {
			throw $this->new_unexpected_error();
		}

		$expression = $this->factory->create_accessing_identifier($master, $member);
		$expression->pos = $this->pos;

		return $this->read_expression_combination($expression, $prev_operator);
	}

	protected function read_key_accessing(IExpression $master, OperatorSymbol $prev_operator = null)
	{
		// array key accessing

		$this->skip_token(_BRACKET_OPEN);
		$key = $this->read_expression();
		$this->skip_token(_BRACKET_CLOSE);

		if ($key === null) {
			return $this->read_array_element_assignment($master);
		}

		$expression = new KeyAccessing($master, $key);
		$expression->pos = $this->pos;

		return $this->read_expression_combination($expression, $prev_operator);
	}

	protected function read_array_element_assignment(IExpression $master)
	{
		$this->expect_token_ignore_empty(_ASSIGN);

		$value = $this->read_expression();
		if ($value === null) {
			throw $this->new_unexpected_error();
		}

		return new ArrayElementAssignment($master, null, $value);
	}

	protected function read_call_expression(IExpression $handler, OperatorSymbol $prev_operator = null)
	{
		// new class, or function call, or function declaration

		// support the super() call
		if ($handler instanceof PlainIdentifier) {
			if ($handler->name === _SUPER) {
				$handler = $this->factory->create_accessing_identifier($handler, _CONSTRUCT);
				$handler->pos = $this->pos;
			}
		}
		elseif (!$handler instanceof Identifiable) {
			$this->scan_token_ignore_space(); // 将语法错误定位到下一个token
			throw $this->new_unexpected_error();
		}

		$this->skip_token(_PAREN_OPEN);
		$args = $this->read_call_expression_arguments();
		$this->skip_token(_PAREN_CLOSE);

		// function call
		$call = new CallExpression($handler, $args);
		$call->pos = $this->pos;

		if ($callbacks = $this->try_read_callback_arguments()) {
			$call->set_callbacks(...$callbacks);
		}

		return $call;
	}

	protected function try_read_callback_arguments()
	{
		if (!$this->skip_token_ignore_empty(_NOTIFY)) {
			return null;
		}

		// the simple mode, just support one callback
		if ($this->get_token_ignore_empty() === _BLOCK_BEGIN) {
			$lambda = $this->read_lambda_combination([], true);
			return [$this->create_callback_argument(null, $lambda)];
		}

		// the normal mode
		$items = [];
		while ($item = $this->read_callback_argument()) {
			$items[] = $item;
			if (!$this->skip_token_ignore_empty(_NOTIFY)) {
				break;
			}
		}

		return $items;
	}

	protected function read_callback_argument()
	{
		$name = $this->scan_token_ignore_empty();
		if (!TeaHelper::is_declarable_variable_name($name)) {
			throw $this->new_unexpected_error();
		}

		if ($this->skip_colon()) {
			// assign or lambda mode

			// eg. -> done: done_callable
			// eg. -> done: () => {}  // a normal style lambda declaration
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

	protected function try_read_operation(IExpression $expression, OperatorSymbol $prev_operator = null)
	{
		$token = $this->get_token_ignore_empty();

		$operator = OperatorFactory::get_normal_operator_symbol($token);
		if ($operator === null) {
			return $expression;
		}

		// compare operator precedences
		if ($prev_operator) {
			if ($prev_operator->precedence <= $operator->precedence) {
				return $expression;
			}
		}

		// if ($operator === OperatorFactory::$_dot) {
		// 	$this->scan_token_ignore_empty(); // skip the operator
		// 	$expression = $this->read_dot_expression($expression, $prev_operator);
		// }
		// else
		if ($operator === OperatorFactory::$_cast) {
			$this->scan_token_ignore_empty(); // skip the operator

			if ($this->skip_token(_PAREN_OPEN)) {
				$as_type = $this->try_read_type_identifier();
				$this->expect_token(_PAREN_CLOSE);
			}
			else {
				$as_type = $this->try_read_simple_type_identifier();
			}

			if ($as_type === null) {
				throw $this->new_unexpected_error();
			}

 			$expression = new CastOperation($expression, $as_type);
			$expression->pos = $this->pos;

 			$expression = $this->read_expression_combination($expression, $prev_operator);
 			if ($expression instanceof ArrayElementAssignment) {
 				return $expression;
 			}
		}
		elseif ($operator === OperatorFactory::$_is) {
			$this->scan_token_ignore_empty(); // skip the operator
			$is_not = $this->skip_token_ignore_space(_NOT);
			// $assert_type = $this->read_expression($operator);

			$assert_type = $this->try_read_type_identifier();
			if ($assert_type === null) {
				throw $this->new_parse_error("Expected a type name for the 'is' expression.");
			}

			$expression = new IsOperation($expression, $assert_type, $is_not);
			$expression->pos = $this->pos;
		}
		elseif ($operator === OperatorFactory::$_conditional) {
			$this->scan_token_ignore_empty(); // skip the operator
			$expression = $this->read_conditional_expression_with($expression);
		}
		else {
			// the normal binary operation

			$this->skip_binary_operator_with_space($token);
			$right_expression = $this->read_expression($operator);
			$expression = new BinaryOperation($operator, $expression, $right_expression);
			$expression->pos = $right_expression->pos;
		}

		return $this->try_read_operation($expression, $prev_operator);
	}

	protected function skip_binary_operator_with_space(string $operator)
	{
		$this->expect_space("Missed space char before operator '$operator'.");
		$this->scan_token_ignore_empty(); // skip the operator
		$this->expect_space("Missed space char after operator '$operator'.");
	}

	protected function read_call_expression_arguments()
	{
		$has_label = false;
		$items = [];
		while ($item = $this->read_argument()) {
			if ($item->label !== null) {
				// the labeled argument
				if (isset($items[$item->label])) {
					throw $this->new_parse_error("Parameter '{$item->label}' already has be assigned.");
				}
				else {
					$items[$item->label] = $item;
					$item->label = null;
				}
			}
			elseif ($has_label) {
				throw $this->new_parse_error("This argument required a label, because of the prevent has a labeled.");
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
	// 	// eg. &identifier
	// 	// eg. expression

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
		if ($token === LF || $token === _INLINE_COMMENT_MARK) {
			return [];
		}

		while ($item = $this->read_expression()) {
			$items[] = $item;
			if (!$this->skip_comma()) {
				break;
			}
		}

		return $items;
	}

	protected function try_read_classlike_declaration(string $name, string $modifier = null)
	{
		$next = $this->get_token_ignore_empty();
		if ($next !== _BLOCK_BEGIN && $next !== _COLON) {
			return null;
		}

		if (TeaHelper::is_interface_marked_name($name)) {
			$declaration = $this->read_interface_declaration($name, $modifier);
		}
		else {
			$declaration = $this->read_class_declaration($name, $modifier);
		}

		return $declaration;
	}

	protected function read_class_declaration(string $name, ?string $modifier)
	{
		$declaration = $this->factory->create_class_declaration($name, $modifier);
		$declaration->pos = $this->pos;

		$this->read_rest_for_classlike_declaration($declaration);

		return $declaration;
	}

	protected function read_interface_declaration(string $name, ?string $modifier)
	{
		$declaration = $this->factory->create_interface_declaration($name, $modifier);
		$declaration->pos = $this->pos;

		$this->is_declare_mode = true;
		$this->read_rest_for_classlike_declaration($declaration);
		$this->is_declare_mode = false;

		return $declaration;
	}

	protected function read_rest_for_classlike_declaration(ClassLikeDeclaration $declaration)
	{
		if ($baseds = $this->read_class_baseds()) {
			$declaration->set_baseds(...$baseds);
		}

		$this->expect_block_begin_ignore_empty();

		while ($item = $this->read_class_member_declaration());

		$this->expect_block_end();
		$this->factory->end_class();
	}

	protected function read_class_baseds()
	{
		if (!$this->skip_colon()) {
			return null;
		}

		$baseds = [];
		while ($identifer = $this->try_read_classlike_identifier()) {
			$baseds[] = $identifer;
			if (!$this->skip_comma()) {
				break;
			}
		}

		if (!$baseds) {
			throw $this->new_parse_error("Based class or interfaces expected.");
		}

		return $baseds;
	}

	protected function try_read_classlike_identifier()
	{
		$token = $this->get_token_ignore_empty();
		if (!TeaHelper::is_identifier_name($token)) {
			return null;
		}

		$this->scan_token_ignore_empty();

		if (!$this->is_in_tea_declaration && TypeFactory::exists_type($token)) {
			throw $this->new_parse_error("Cannot use type '$token' as a class/interface.");
		}

		// return $this->read_classlike_identifier($token);
		$identifer = $this->factory->create_classlike_identifier($token);
		$identifer->pos = $this->pos;

		return $identifer;
	}

	// protected function read_classlike_identifier(string $name = null)
	// {
	// 	if ($name === null) {
	// 		$name = $this->expect_identifier_token();
	// 	}

	// 	$ns = null;

	// 	// do not support namespace now
	// 	// if ($this->skip_token(_DOT)) {
	// 	// 	$ns = $this->factory->create_identifier($name);
	// 	// 	$name = $this->expect_identifier_token();
	// 	// }

	// 	// if ($this->skip_token(_DOT)) {
	// 	// 	throw $this->new_parse_error("Namespaces that exceed two levels not supported.");
	// 	// }

	// 	return $this->factory->create_classlike_identifier($ns, $name);
	// }

	protected function try_read_simple_type_identifier()
	{
		$token = $this->get_token_ignore_space();

		if ($type = TypeFactory::get_type($token)) {
			$this->scan_token_ignore_space(); // skip
		}
		elseif (TeaHelper::is_identifier_name($token)) {
			$this->scan_token_ignore_space(); // skip
			// $type = $this->read_classlike_identifier($token);
			$type = $this->factory->create_classlike_identifier($token);
		}
		else {
			return null;
		}

		$type->pos = $this->pos;
		return $type;
	}

	protected function try_read_type_identifier()
	{
		$token = $this->get_token_ignore_space();

		if ($type = TypeFactory::get_type($token)) {
			$this->scan_token_ignore_space(); // skip
		}
		elseif (TeaHelper::is_identifier_name($token)) {
			$this->scan_token_ignore_space(); // skip
			// $type = $this->read_classlike_identifier($token);
			$type = $this->factory->create_classlike_identifier($token);
		}
		else {
			return null;
		}

		// try read Dict or Array
		$next = $this->get_token();
		if ($next === _BRACKET_OPEN) {
			// the String[][:] style compound type
			$type = $this->read_bracket_style_compound_type($type);
		}
		elseif ($next === _DOT) {
			// the String.Array.Dict style compound type
			$type = $this->read_dots_style_compound_type($type);
		}

		$type->pos = $this->pos;
		return $type;
	}

	protected function read_dots_style_compound_type(IType $value_type): IType
	{
		$type = $value_type;
		$i = 0;
		while ($this->skip_token(_DOT)) {
			if ($i === _MAX_STRUCT_DIMENSIONS) {
				throw $this->new_parse_error('The dimensions of Array/Dict exceeds, the max is ' . _MAX_STRUCT_DIMENSIONS);
			}

			$kind = $this->scan_token();
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

	protected function read_bracket_style_compound_type(IType $value_type): IType
	{
		$type = $value_type;
		$i = 0;
		while ($this->skip_token(_BRACKET_OPEN)) {
			if ($i === _MAX_STRUCT_DIMENSIONS) {
				throw $this->new_parse_error('The dimensions of Array/Dict exceeds, the max is ' . _MAX_STRUCT_DIMENSIONS);
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

		return $type;
	}

	protected function try_read_return_type_identifier()
	{
		$type = $this->try_read_type_identifier();
		if ($type === null) {
			return null;
		}

		// the collector feature, eg. IView >> Array
		if ($this->skip_token_ignore_space(_COLLECT)) {
			$target_type = $this->scan_token_ignore_space();
			if ($target_type !== _ARRAY) {
				throw $this->new_parse_error("The target type for collector should be 'Array'.");
			}

			if ($type === TypeFactory::$_string || $type === TypeFactory::$_any) {
				throw $this->new_parse_error("The type to collect do not supported String or Any.");
			}

			$type = TypeFactory::create_collector_type($type);
		}

		return $type;
	}

	protected function read_class_member_declaration($leading = null, Docs $docs = null, string $modifier = null, bool $static = false)
	{
		$token = $this->get_token_ignore_space();
		if ($token === null || $token === _BLOCK_END) {
			return null;
		}

		$this->scan_token_ignore_space();

		if ($token === LF) {
			// has leading empty line
			// will ignore docs when has empty lines
			return $this->read_class_member_declaration(LF);
		}

		$this->trace_statement($token);

		if (TeaHelper::is_modifier($token)) {
			if ($token === _MASKED) {
				return $this->read_masked_declaration();
			}

			$modifier = $token;
			$token = $this->scan_token_ignore_space();
		}

		if ($token === _STATIC) {
			$static = true;
			$token = $this->scan_token_ignore_space();
		}

		// 此处无需检查类成员保留字，因相关修饰符可以被上述代码正确处理

		$header_pos = $this->pos;

		if (TeaHelper::is_constant_name($token)) {
			$declaration = $this->read_class_constant_declaration($token, $modifier);
		}
		elseif (TeaHelper::is_strict_less_function_name($token)) { // 因为需要支持PHP库，这里放开为宽松的命名规范
			if ($this->get_token() === _PAREN_OPEN) {
				$declaration = $this->read_method_declaration($token, $modifier, $static);
			}
			else {
				$declaration = $this->read_property_declaration($token, $modifier, $static);
			}
		}
		elseif ($token === _DOCS_MARK) {
			$docs = $this->read_docs();
			return $this->read_class_member_declaration($leading, $docs, $modifier, $static);
		}
		elseif ($token === _INLINE_COMMENT_MARK) {
			$this->skip_current_line();
			return $this->read_class_member_declaration($leading, $docs, $modifier, $static);
		}
		else {
			throw $this->new_unexpected_error();
		}

		$this->expect_statement_end();

		$declaration->pos = $header_pos;
		$declaration->leading = $leading;
		$docs && $declaration->docs = $docs;

		return $declaration;
	}

	protected function read_literal_expression()
	{
		$value = $this->read_expression();

		// require defer check is a literal expression
		$value->require_literal = true;

		return $value;
	}

	protected function read_class_constant_declaration(string $name, ?string $modifier)
	{
		$declaration = $this->factory->create_class_constant_declaration($modifier, $name);
		$declaration->pos = $this->pos;

		$declaration->type = $this->try_read_type_identifier();

		if ($this->skip_token_ignore_space(_ASSIGN)) {
			$declaration->value = $this->read_literal_expression();
		}
		elseif (!$this->is_declare_mode) {
			throw $this->new_parse_error('Expected value assign expression.');
		}
		elseif (!$declaration->type) {
			throw $this->new_parse_error('Expected type expression.');
		}

		$this->factory->end_class_member();

		return $declaration;
	}

	protected function read_property_declaration(string $name, ?string $modifier, bool $static)
	{
		// prop1 String
		// prop1 = 'abcdef'
		// prop1 String = 'abcdef'
		// public prop1 String = 'abcdef'

		$declaration = $this->factory->create_property_declaration($modifier, $name);
		$declaration->pos = $this->pos;

		// type or =
		$token = $this->get_token_ignore_space();

		// the type name
		if (TeaHelper::is_type_name($token)) {
			$declaration->type = $this->try_read_type_identifier();
			$token = $this->get_token_ignore_space();
		}

		if ($token === _ASSIGN) {
			// the assign expression
			$this->scan_token_ignore_space(); // skip =
			$declaration->value = $this->read_literal_expression();
		}

		$declaration->is_static = $static;

		$this->factory->end_class_member();

		return $declaration;
	}

	protected function read_method_declaration(string $name, ?string $modifier, bool $static)
	{
		$declaration = $this->factory->create_method_declaration($modifier, $name);
		$declaration->pos = $declaration;

		$declaration->is_static = $static;

		$parameters = $this->read_parameters_with_parentheses();
		$this->factory->set_enclosing_parameters($parameters);

		$declaration->type = $this->try_read_return_type_identifier();

		$next = $this->get_token_ignore_empty();
		if ($next === _BLOCK_BEGIN) {
			$this->read_body_statements($declaration);
		}
		elseif ($this->is_declare_mode) {
			// no any others
		}
		else {
			throw $this->new_parse_error('Method body required.');
		}

		$this->factory->end_class_member();

		return $declaration;
	}

	// protected function try_read_callback_protocols()
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

	// 	if (!TeaHelper::is_function_name($name)) {
	// 		throw $this->new_unexpected_error();
	// 	}

	// 	$parameters = $this->read_parameters_with_parentheses();
	// 	$return_type = $this->try_read_return_type_identifier();

	// 	$node = new CallbackProtocol($is_async, $name, $return_type, ...$parameters);
	// 	$node->pos = $this->pos;

	// 	return $node;
	// }

	protected function read_parameters_with_parentheses()
	{
		$this->expect_token_ignore_empty(_PAREN_OPEN);

		$items = [];
		while ($item = $this->try_read_parameter()) {
			$items[] = $item;
			if (!$this->skip_comma()) {
				break;
			}
		}

		$this->expect_token_ignore_empty(_PAREN_CLOSE);

		return $items;
	}

	protected function try_read_parameter()
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
		// &param Int  // for referenced parameters

		$type = null;
		$value = null;

		$is_referenced = false;
		if ($name === _REFERENCE) {
			$is_referenced = true;
			$name = $this->scan_token_ignore_space();
		}

		if (!TeaHelper::is_declarable_variable_name($name)) {
			throw $this->new_unexpected_error();
		}

		$next = $this->get_token_ignore_space();
		if (TeaHelper::is_type_name($next)) {
			$type = $this->try_read_type_identifier();
			$next = $this->get_token_ignore_space();
		}
		elseif ($next === _PAREN_OPEN) { // the Callable protocol
			$type = $this->read_callable_type();
			$next = $this->get_token_ignore_space();
		}

		if ($next === _ASSIGN) {
			$this->scan_token_ignore_space();
			$value = $this->read_literal_expression();
		}

		$parameter = $this->create_parameter($name, $type, $value);

		if ($is_referenced) {
			if ($value && $value !== ASTFactory::$default_value_marker) {
				throw $this->new_parse_error("Cannot set a default value for the referenced parameter.");
			}

			$parameter->is_referenced = $is_referenced;
		}

		return $parameter;
	}

	protected function create_parameter(string $name, IType $type = null, IExpression $value = null)
	{
		$parameter = new ParameterDeclaration($name, $type, $value, true);
		$parameter->pos = $this->pos;
		return $parameter;
	}

	protected function read_callable_type()
	{
		$parameters = $this->read_parameters_with_parentheses();
		$return_type = $this->try_read_return_type_identifier();

		$node = TypeFactory::create_callable_type($return_type, $parameters);
		$node->pos = $this->pos;

		return $node;
	}
}

// end
