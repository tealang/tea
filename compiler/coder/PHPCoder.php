<?php
/**
 * This file is part of the Tea programming language project
 *
 * @author 		Benny <benny@meetdreams.com>
 * @copyright 	(c)2019 YJ Technology Ltd. [http://tealang.org]
 * For the full copyright and license information, please view the LICENSE file that was distributed with this source code.
 */

namespace Tea;

class PHPCoder extends TeaCoder
{
	const VAR_PREFIX = _DOLLAR;

	const VAR_DECLARE_PREFIX = _DOLLAR;

	const USE_DECLARE_PREFIX = 'use ';

	const NS_SEPARATOR = PHPParser::NS_SEPARATOR;

	const STATEMENT_TERMINATOR = ';';

	const CLASS_MEMBER_TERMINATOR = ';';

	const CLASS_MEMBER_OPERATOR = '::';

	const OBJECT_MEMBER_OPERATOR = '->';

	const DICT_KV_OPERATOR = ' => ';

	const DICT_EMPTY_VALUE = '[]';

	const VAL_NONE = PHPParser::VAL_NONE;

	const NAMESPACE_REPLACES = [
		_STRIKETHROUGH => _UNDERSCORE,
		_DOT => _UNDERSCORE,
		TeaParser::NS_SEPARATOR => PHPParser::NS_SEPARATOR
	];

	const CASTABLE_TYPES = ['string', 'int', 'float', 'bool', 'array', 'object'];

	const TYPE_MAP = [
		_VOID => 'void',
		_ANY => '',
		_STRING => 'string',
		_INT => 'int',
		_UINT => 'int',
		_FLOAT => 'float',
		_BOOL => 'bool',
		_ARRAY => 'array',
		_DICT => 'array',
		_CALLABLE => 'callable',
		_ITERABLE => 'iterable',
		_OBJECT => 'object',
		_REGEX => 'string',
		_XVIEW => 'string',
		_METATYPE => 'string',
	];

	const EXTRA_RESERVEDS = [
		'abstract',
		'final',
		'class',
		'interface',
		'trait',
		'function',
		'const',
		'array',
		'callable',
		'list',
		'each',
		'foreach',
		'default',
		'print',
	];

	const METHOD_NAMES_MAP = [
		_CONSTRUCT => '__construct',
		_DESTRUCT => '__destruct',
		'to_string' => '__toString',
	];

	const PREFIX_OPERATOR_MAP = [
		_NOT => '!',
	];

	const NORMAL_OPERATOR_MAP = [
		_IS => 'instanceof',
		_CONCAT => '.',
		_AND => '&&',
		_OR => '||',
	];

	// precedences for PrefixOperation
	const PREFIX_OPERATOR_PRECEDENCES = [
		'!' => 5, '-' => 5, '+' => 5, '~' => 5,
	];

	// precedences for MultiOperation
	const NORMAL_OPERATOR_PRECEDENCES = [
		_ARRAY_CONCAT => 0, 	// array concat, use function
		_EXPONENTIATION => 2, 	// arithmetic
		_DOUBLE_COLON => 3, 	// cast
		'instanceof' => 4, 		// type

		'*' => 6, '/' => 6, '%' => 6, 	// arithmetic
		'+' => 7, 	// arithmetic, array
		'-' => 7, 	// arithmetic
		// '.' => 7,			// string (prior to PHP 8.0.0)
		'<<' => 8, '>>' => 8, 	// bitwise
		'.' => 9,				// string PHP8.0
		'<' => 10, '<=' => 10, '>' => 10, '>=' => 10, 	// comparison
		'==' => 11, '!=' => 11, '===' => 11, '!==' => 11, '<=>' => 11, 	// comparison
		'&' => 12, 		// bitwise and references
		'^' => 13, 		// bitwise
		'|' => 14, 		// bitwise
		'&&' => 15, 	// logical
		'||' => 16, 	// logical
		'??' => 17, 	// null coalescing
		'?' => 18,		// ternary
	];

	const PROGRAM_HEADER = '<?php';

	protected $uses = [];

	protected function process_use_statments(array $declarations)
	{
		foreach ($declarations as $node) {
			$this->collect_use_statements($node);
		}

		$this->program->main_function && $this->collect_use_statements($this->program->main_function);
	}

	protected function collect_use_statements(IDeclaration $declaration)
	{
		foreach ($declaration->uses as $use) {
			// it should be a use statement in __unit

			$uri = $use->ns->uri;
			if ($use->target_name) {
				$uri .= '!'; // just to differentiate, avoid conflict with no targets use statements
			}

			// URI相同的将合并到一条
			if (!isset($this->uses[$uri])) {
				$this->uses[$uri] = new UseStatement($use->ns);
			}

			$this->uses[$uri]->append_target($use);
		}

		foreach ($declaration->defer_check_identifiers as $identifier) {
			$dependence = $identifier->symbol->declaration;
			if ($dependence instanceof FunctionDeclaration && $dependence->program->is_native) {
				$this->program->append_depends_native_program($dependence->program);
			}
		}
	}

	protected function render_heading_statements(Program $program)
	{
		$items = [];

		$ns_uri = $program->unit->dist_ns_uri;
		if ($ns_uri !== null) {
			$ns_uri = ltrim($ns_uri, static::NS_SEPARATOR);
			$items[] = "namespace $ns_uri;\n";
		}

		if ($this->uses) {
			$items[] = $this->render_uses($this->uses) . LF;
		}

		return $items;
	}

	protected function render_program_statements(Program $program)
	{
		$declarations = [];
		foreach ($program->declarations as $node) {
			// the common consts and functions would be render to the loader file
			if (!$node->is_unit_level || $node instanceof ClassKindredDeclaration) {
				$declarations[] = $node;
			}
		}

		$this->process_use_statments($declarations);

		$items = $this->render_heading_statements($program);

		if ($program->as_main_program) {
			if ($program->is_using_coroutine) {
				$items[] = '\Swoole\Runtime::enableCoroutine();';
				$items[] = '';
			}

			$levels = $program->count_subdirectory_levels();
			if ($levels) {
				$path_token = "dirname(__DIR__, {$levels})";
			}
			else {
				$path_token = "__DIR__";
			}

			$items[] = "require_once $path_token . '/" . PUBLIC_LOADER_FILE_NAME . "';\n";
		}

		// include dependencies
		foreach ($program->depends_native_programs as $depends_program) {
			$items[] = "require_once UNIT_PATH . '{$depends_program->name}.php';\n";
		}

		// 生成定义，使用了trait的类，必须放在文件的前面，否则执行时提示找不到
		foreach ($declarations as $node) {
			$item = $node->render($this);
			$item === null || $items[] = $item . LF;
		}

		// 生成游离语句
		if ($program->main_function) {
			$body_items = $this->render_block_nodes($program->main_function->body);
			$items[] = '// ---------';
			$items[] = trim(join($body_items));

			$items[] = '// ---------';
			$items[] = '';

			if ($program->as_main_program && $program->is_using_coroutine) {
				$items[] = '\Swoole\Event::wait();';
				$items[] = '';
			}
		}

		return $items;
	}

	protected function generate_use_targets(array $targets)
	{
		$items = [];
		foreach ($targets as $target) {
			$source_name = $target->source_declaration->origin_name ?? $target->source_name;
			if ($source_name) {
				if (is_array($source_name)) {
					$source_name = join(static::NS_SEPARATOR, $source_name);
				}

				$item = "$source_name as {$target->target_name}";
			}
			else {
				$item = $target->target_name;
			}

			$declaration = $target->source_declaration;
			if ($declaration instanceof ClassKindredDeclaration) {
				// no any
			}
			elseif ($declaration instanceof FunctionDeclaration) {
				$item = "function $item";
			}
			elseif ($declaration instanceof ConstantDeclaration) {
				$item = "const $item";
			}
			else {
				$kind = $target::KIND;
				throw new Exception("Unknow use target kind '$kind'.");
			}

			$items[] = $item;
		}

		return sprintf('\{ %s }', join(', ', $items));
	}

	protected function generate_classkindred_header(ClassKindredDeclaration $node, string $kind)
	{
		$modifier = $node->modifier ?? _INTERNAL;
		$name = $this->get_normalized_name($node->name);

		if ($node instanceof ClassDeclaration) {
			if ($node->is_readonly) {
				$kind = 'readonly ' . $kind;
			}

			if ($node->is_abstract) {
				$kind = 'abstract ' . $kind;
			}
		}

		return "#$modifier\n$kind $name";
	}

	protected function generate_class_baseds(ClassKindredDeclaration $node)
	{
		$code = '';
		if ($node->inherits) {
			$code = ' extends ' . $this->render_classkindred_identifier($node->inherits);
		}

		if ($node->baseds) {
			$items = [];
			foreach ($node->baseds as $item) {
				$items[] = $this->render_classkindred_identifier($item);
			}

			$keyword = $node instanceof InterfaceDeclaration ? ' extends ' : ' implements ';
			$code .= $keyword . join(', ', $items);
		}

		return $code;
	}

	protected function generate_function_header(IFunctionDeclaration $node, bool $is_abstract = false)
	{
		if ($node->belong_block) {
			$modifier = $node->modifier ?? _PUBLIC;;
			if ($modifier === _INTERNAL) {
				$prefix = "#$modifier\n";
			}
			else {
				$prefix = $modifier . ' ';
			}

			if ($is_abstract) {
				$prefix .= 'abstract ';
			}

			if ($node->is_static) {
				$prefix .= 'static ';
			}

			$name = $this->get_normalized_method_name($node->name);
		}
		else {
			$modifier = $node->modifier ?? _INTERNAL;
			$prefix = $modifier === _INTERNAL ? "#$modifier\n" : '';
			$name = $this->get_normalized_name($node->name);
		}

		$parameters = $this->render_function_parameters($node);
		$code = "{$prefix}function {$name}({$parameters})";

		// just render return type on hinted
		// because on unhinted, it's not necessary, and sometimes it makes mistakes
		if ($node->is_hinted_return_type && $node->type !== TypeFactory::$_any) {
			$return_type = $this->render_type($node->type);
			$return_type and $code .= ": $return_type";
		}

		return $code;
	}

	protected function generate_property_header(PropertyDeclaration $node)
	{
		$name = $this->add_variable_prefix($node->name);
		$modifier = $node->modifier ? $node->modifier : _PUBLIC;

		if ($modifier === _INTERNAL) {
			$modifier = "#internal\npublic ";
		}

		if ($node->is_static) {
			$modifier .= ' static';
		}

		return "$modifier $name";
	}

	protected function generate_class_constant_header(ClassConstantDeclaration $node)
	{
		$prefix = 'const';

		$modifier = $node->modifier ?? null;
		if ($modifier === _INTERNAL) {
			$prefix = "#internal\n" . $prefix;
		}
		elseif ($modifier) {
			$prefix = $modifier . ' ' . $prefix;
		}

		return $prefix . ' ' . $node->name;
	}

	protected function generate_constant_header(IConstantDeclaration $node)
	{
		$prefix = 'const';
		$modifier = $node->modifier ?? _INTERNAL;

		if ($modifier === _INTERNAL) {
			$prefix = "#{$modifier}\n" . $prefix;
		}

		$name = $this->get_normalized_name($node->name);

		return $prefix . ' ' . $name;
	}

// ---

	public function render_constant_declaration(IConstantDeclaration $node)
	{
		if ($node->is_runtime) {
			return null;
		}

		return parent::render_constant_declaration($node);
	}

	public function render_masked_declaration(MaskedDeclaration $node)
	{
		return null;
	}

	public function render_method_declaration(MethodDeclaration $node)
	{
		if ($node->body === null) {
			return null;
		}

		$header = $this->generate_function_header($node);
		$body = $this->render_function_body($node);

		return $header . ' ' . $body;
	}

	public function render_function_declaration(FunctionDeclaration $node)
	{
		if ($node->body === null) {
			return null;
		}

		$header = $this->generate_function_header($node);
		$body = $this->render_function_body($node);

		return $header . ' ' . $body;
	}

	// public function render_coroutine_block(CoroutineBlock $node)
	// {
	// 	$lambda = $this->render_lambda_expression($node);
	// 	return sprintf('\Swoole\Coroutine::create(%s);', $lambda);
	// }

	public function render_lambda_expression(LambdaExpression $node)
	{
		$parameters = $this->render_parameters($node->parameters);
		$body = $this->render_function_body($node);

		if ($node->use_variables) {
			$uses = $this->render_lambda_use_arguments($node);
			$header = sprintf('function (%s) use(%s)', $parameters, $uses);
		}
		else {
			$header = sprintf('function (%s)', $parameters);
		}

		return $header . ' ' . $body;
	}

	protected function render_lambda_use_arguments(LambdaExpression $node)
	{
		foreach ($node->use_variables as $arg) {
			$item = $arg->render($this);
			// if (in_array($arg->name, $node->mutating_variable_names, true)) {
			// 	$item = '&' . $item;
			// }

			// 某些情况下不能检测出是否有被改变，如调用了外部实现的时候
			// 统一处理成引用，因为这些变量的作用域应是属于延伸，处理成拷贝语意也不合理
			$item = '&' . $item;

			$items[] = $item;
		}

		return join(', ', $items);
	}

	public function render_function_body(IScopeBlock $node)
	{
		$body = $node->fixed_body ?? $node->body;

		$items = [];

		// if ($node->auto_declarations) {
		// 	foreach ($node->auto_declarations as $declar) {
		// 		if (!$declar->block instanceof IScopeBlock) {
		// 			$items[] = static::VAR_DECLARE_PREFIX . "{$declar->name} = null;\n";
		// 		}
		// 	}

		// 	$items[] = LF;
		// }

		if (is_array($body)) {
			$tmp_items = $this->render_block_nodes($body);
			$items = $items ? array_merge($items, $tmp_items) : $tmp_items;
		}
		else {
			// the single expression lambda body
			$items[] = 'return ' . $body->render($this) . static::STATEMENT_TERMINATOR;
		}

		return $this->wrap_block_code($items);
	}

	protected function render_function_parameters(Node $node)
	{
		$parameters = $node->parameters ?? [];
		if ($node->callbacks) {
			foreach ($node->callbacks as $cb) {
				$parameters[] = new ParameterDeclaration($cb->name, TypeFactory::$_callable, new NoneLiteral());
			}
		}

		return $this->render_parameters($parameters);
	}

	public function render_parameter_declaration(ParameterDeclaration $node)
	{
		$expr = $this->add_variable_prefix($node->name);
		if ($node->is_value_mutable) {
			$expr = '&' . $expr;
		}

		if ($node->type) {
			$type = $this->render_type($node->type);
			if ($type) {
				$expr = "{$type} {$expr}";
			}
		}

		if ($node->value) {
			$expr .= ' = ' . $node->value->render($this);
		}

		return $expr;
	}

	public function render_unset_statement(UnsetStatement $node)
	{
		return 'unset(' . $node->argument->render($this) . ')' . static::STATEMENT_TERMINATOR;
	}

// ---

	public function render_type_declaration(BuiltinTypeClassDeclaration $node)
	{
		return null;
	}

	public function render_class_declaration(ClassDeclaration $node)
	{
		if ($node->is_runtime) {
			return null;
		}

		$items = $this->render_block_nodes($node->members);

		$traits = $this->get_using_trait_name_in_baseds($node->baseds);
		if ($traits) {
			array_unshift($items, 'use ' . join(', ', $traits) . ";\n\n");
		}

		$code = sprintf("%s%s %s",
			$this->generate_classkindred_header($node, 'class'),
			$this->generate_class_baseds($node),
			$this->wrap_block_code($items)
		);

		return $code;
	}

	private function get_using_trait_name_in_baseds(array $identifiers)
	{
		$items = [];
		foreach ($identifiers as $identifier) {
			$target_declaration = $identifier->symbol->declaration;
			if ($target_declaration instanceof IntertraitDeclaration) {
				$name = $identifier->render($this);
				$items[] = $this->get_intertrait_trait_name($name);
			}
		}

		return $items;
	}

	public function render_interface_declaration(InterfaceDeclaration $node)
	{
		if ($node->is_runtime) {
			return null;
		}

		$code = sprintf("%s%s %s",
			$this->generate_classkindred_header($node, 'interface'),
			$this->generate_class_baseds($node),
			$this->wrap_block_code($this->render_interface_members($node))
		);

		return $code;
	}

	public function render_intertrait_declaration(IntertraitDeclaration $node)
	{
		// interface
		$members = $this->render_interface_members($node);
		$interface_code = sprintf("%s%s %s",
			$this->generate_classkindred_header($node, 'interface'),
			$this->generate_class_baseds($node),
			$this->wrap_block_code($members)
		);

		// trait
		$members = $this->render_trait_members_for_intertrait($node);
		$name = $this->get_normalized_name($node->name);
		$trait_code = sprintf("trait %s %s",
			$this->get_intertrait_trait_name($name),
			$this->wrap_block_code($members)
		);

		return $interface_code . "\n\n" . $trait_code;
	}

	public static function get_intertrait_trait_name(string $origin_name)
	{
		return $origin_name . '_T';
	}

	protected function render_interface_members(InterfaceDeclaration $node)
	{
		$items = [];
		foreach ($node->members as $member) {
			// PHP disallowed private/protected in Interface
			if ($member->modifier === _PRIVATE or $member->modifier === _PROTECTED) {
				continue;
			}

			if ($member instanceof MethodDeclaration) {
				$item = $this->render_interface_method($member);
			}
			elseif ($member instanceof ClassConstantDeclaration) {
				$item = $this->render_class_constant_declaration($member);
			}
			else {
				continue;
			}

			$items[] = $item . LF;
		}

		return $items;
	}

	protected function render_interface_method(MethodDeclaration $node)
	{
		$header = $this->generate_function_header($node);
		return $header . static::CLASS_MEMBER_TERMINATOR;
	}

	protected function render_trait_members_for_intertrait(InterfaceDeclaration $node)
	{
		$items = [];
		foreach ($node->members as $member) {
			$modifier = $member->modifier ?? null;
			$is_not_public = $modifier === _PROTECTED || $modifier === _PRIVATE;
			if ($member instanceof PropertyDeclaration) {
				$item = $this->render_property_declaration($member);
			}
			elseif ($member instanceof MethodDeclaration && ($is_not_public || $member->body !== null)) {
				$item = $this->render_trait_method_declaration($member);
				$item = LF . $item;
			}
			elseif ($member instanceof ClassConstantDeclaration && $is_not_public) {
				$item = $this->render_class_constant_declaration($member);
			}
			else {
				continue;
			}

			$items[] = $item . LF;
		}

		return $items;
	}

	private function render_trait_method_declaration(MethodDeclaration $node)
	{
		$is_abstract = $node->body === null;
		$code = $this->generate_function_header($node, $is_abstract);

		if ($is_abstract) {
			$code .= static::CLASS_MEMBER_TERMINATOR;
		}
		else {
			$code .= ' ' . $this->render_function_body($node);
		}

		return $code;
	}

// ---

	public function render_switch_block(SwitchBlock $node)
	{
		$test = $node->test->render($this);

		$branches = [];
		foreach ($node->branches as $branch) {
			$branches[] = $this->render_case_branch($branch);
		}

		if ($node->else) {
			$branches[] = $this->render_else_for_switch_block($node->else);
		}

		$branches = $this->indents(join(LF, $branches));
		$code = "switch ($test) {\n$branches\n}";

		if ($node->except) {
			$code = $this->wrap_with_except_block($node->except, $code);
		}

		return $code;
	}

	protected function render_else_for_switch_block(IElseBlock $node)
	{
		if ($node instanceof ElseBlock) {
			$body = $this->render_case_branch_body($node->body);
		}
		else {
			// that should be ElseIfBlock

			$items = [];
			$items[] = sprintf("if (%s) %s", $node->condition->render($this), $this->render_control_structure_body($node));

			if ($node->else) {
				$items[] = $node->else->render($this);
			}

			$body = $this->indents(join($items));
		}

		return "default:\n{$body}";
	}

	protected function render_case_branch(CaseBranch $node)
	{
		$codes = [];
		if ($node->rule instanceof ExpressionList) {
			foreach ($node->rule->items as $subexpr) {
				$expr = $subexpr->render($this);
				$codes[] = "case {$expr}:";
			}
		}
		else {
			$expr = $node->rule->render($this);
			$codes[] = "case {$expr}:";
		}

		$codes[] = $this->render_case_branch_body($node->body);

		return join(LF, $codes);
	}

	protected function render_case_branch_body(array $nodes)
	{
		$items = [];
		foreach ($nodes as $node) {
			$item = $node->render($this);
			$items[] = $item === LF ? $item : $item . LF;
		}

		if (empty($nodes) || !$node instanceof BreakStatement) {
			$items[] = 'break' . static::STATEMENT_TERMINATOR;
		}

		return $this->indents(join($items));
	}

	public function render_forin_block(ForInBlock $node)
	{
		$iterable = $node->iterable->render($this);

		if ($node->else) {
			// create temp assignment to avoid duplicate computation
			$temp_assignment = '';
			if (!$node->iterable instanceof PlainIdentifier && !$node->iterable instanceof ILiteral) {
				$temp_name = $this->generate_temp_variable_name();
				$temp_assignment = "$temp_name = $iterable;\n";
				$iterable = $temp_name;
			}
		}

		$value_var = $node->value_var->render($this);
		$body = $this->render_control_structure_body($node);

		if ($node->key_var) {
			$code = sprintf('foreach (%s as %s => %s) %s', $iterable, $node->key_var->render($this), $value_var, $body);
		}
		else {
			$code = sprintf('foreach (%s as %s) %s', $iterable, $value_var, $body);
		}

		if ($node->else) {
			$code = $this->indents($code);
			$code = "{$temp_assignment}if ($iterable && count($iterable) > 0) {\n$code\n}";
			$code .= $node->else->render($this);
		}

		if ($node->except) {
			$code = $this->wrap_with_except_block($node->except, $code);
		}

		return $code;
	}

	public function render_forto_block(ForToBlock $node)
	{
		$start = $node->start->render($this);
		$end = $node->end->render($this);

		$code = '';
		if (!$node->start instanceof ILiteral && !$node->start instanceof PlainIdentifier) {
			$temp_name = $this->generate_temp_variable_name();
			$code .= "$temp_name = $start;\n";
			$start = $temp_name;
		}

		$temp_assignment2 = null;
		if (!$node->end instanceof ILiteral && !$node->end instanceof PlainIdentifier) {
			$temp_name = $this->generate_temp_variable_name();
			$code .= "$temp_name = $end;\n";
			$end = $temp_name;
		}

		$var = $node->var->render($this);
		$step = $node->step ?? 1;

		$body = $this->render_control_structure_body($node);

		if ($node->is_downto_mode) {
			// $for_code = "for ($var = $start; $var >= $end; $var -= $step) $body";
			$for_code = "foreach (\\range($start, $end, -$step) as $var) $body";
		}
		elseif ($step === 1) {
			$for_code = "foreach (\\range($start, $end) as $var) $body";
		}
		else {
			// $for_code = "for ($var = $start; $var <= $end; $var += $step) $body";
			$for_code = "foreach (\\range($start, $end, $step) as $var) $body";
		}

		if ($node->else) {
			$for_code = $this->indents($for_code);
			$op = $node->is_downto_mode ? '>=' : '<=';
			$code .= "if ($start $op $end) {\n$for_code\n}";
			$code .= $node->else->render($this);
		}
		else {
			$code .= $for_code;
		}

		if ($node->except) {
			$code = $this->wrap_with_except_block($node->except, $code);
		}

		return $code;
	}

	public function render_while_block(WhileBlock $node)
	{
		$test = $node->condition->render($this);
		$body = $this->render_control_structure_body($node);

		// if ($node->do_the_first) {
		// 	$code = sprintf('do %s while (%s);', $body, $test);
		// }
		// else {
			$code = sprintf('while (%s) %s', $test, $body);
		// }

		if ($node->except) {
			$code = $this->wrap_with_except_block($node->except, $code);
		}

		return $code;
	}

	public function render_loop_block(LoopBlock $node)
	{
		$body = $this->render_control_structure_body($node);
		$code = sprintf('while (true) %s', $body);

		if ($node->except) {
			$code = $this->wrap_with_except_block($node->except, $code);
		}

		return $code;
	}

	public function render_try_block(TryBlock $node)
	{
		$items = [];
		$code = $this->render_control_structure_body($node);
		$items[] = "try {$code}";
		$items[] = $this->render_catch_block($node->except);

		return join($items);
	}

// ---

	public function render_array_element_assignment(ArrayElementAssignment $node)
	{
		$master = $node->master;
		if ($master instanceof CastOperation) {
			$master = $master->left;
		}

		$master = $master->render($this);
		$key = $node->key ? $node->key->render($this) : '';
		$value = $node->value->render($this);

		return "{$master}[{$key}] = {$value}" . static::STATEMENT_TERMINATOR;
	}

	public function render_normal_assignment(Assignment $node)
	{
		$master = $node->master;
		$right = $node->value->render($this);

		if ($master instanceof SquareAccessing) {
			$expr = $master->expression->render($this);
			if ($master->is_prefix) {
				return "array_unshift({$expr}, {$right})" . static::STATEMENT_TERMINATOR;
			}

			$master =  "{$expr}[]";
		}
		else {
			$master = $master->render($this);
		}

		return sprintf('%s = %s', $master, $right) . static::STATEMENT_TERMINATOR;
	}

	public function render_html_escape_expression(HTMLEscapeExpression $node)
	{
		$expr = $node->expression->render($this);
		// $code = "htmlspecialchars($expr, ENT_QUOTES)";

		// // PHP 8.1 not allowed null
		// if ($node->expression->infered_type->nullable 
		// 	or $node->expression->infered_type->is_same_with(TypeFactory::$_any)) {
		// 	$code = "($expr === null ? '' : htmlspecialchars($expr, ENT_QUOTES))";
		// }

		// use the tea lib function instead, avoiding target is a function call
		$code = "\html_encode($expr)";

		return $code;
	}

	protected function render_xblock_elements(array $items)
	{
		foreach ($items as $k => $item) {
			if ($item instanceof BaseExpression) {
				$expr = $this->render_subexpression($item, OperatorFactory::$concat);
				$items[$k] = $expr;
			}
			else {
				if (strpos($item, _SINGLE_QUOTE) !== false) {
					$item = $this->add_escape_slashs($item, _SINGLE_QUOTE);
				}

				$items[$k] = "'$item'";
			}
		}

		$code = join(' . ', $items);

		// remove the virtual tag
		// if (substr($code, 0, 5) === '<vtag>') {
		// 	$code = substr($code, 5, -11);
		// }

		if (strpos($code, "\t\n")) {
			$code = preg_replace('/\t+\n/', '', $code);
		}

		return $this->new_string_placeholder($code);
	}

	// public function render_relay_expression(RelayExpression $node)
	// {
	// 	$expr = $node->argument->render($this);
	// 	foreach ($node->callees as $callee) {
	// 		$callee = $callee->render($this);
	// 		$expr = "{$callee}({$expr})";
	// 	}

	// 	return $expr;
	// }

	public function render_regular_expression(RegularExpression $node)
	{
		$pattern = $node->pattern;
		if (strpos($pattern, _SINGLE_QUOTE) !== false) {
			$pattern = $this->add_escape_slashs($pattern, _SINGLE_QUOTE);
		}

		return "'/{$pattern}/{$node->flags}'";
	}

	private function get_normalized_method_name(string $name)
	{
		return static::METHOD_NAMES_MAP[$name] ?? $name;
	}

	private function get_normalized_name(string $name)
	{
		if (in_array(strtolower($name), static::EXTRA_RESERVEDS, true)) {
			$name = 't__' . $name;
		}

		return $name;
	}

	private function get_normalized_name_with_declaration(IDeclaration $node)
	{
		$name = $node->name;
		return $node->is_runtime ? $name : $this->get_normalized_name($name);
	}

	public function render_accessing_identifier(AccessingIdentifier $node)
	{
		$declaration = $node->symbol->declaration;
		if ($declaration instanceof MaskedDeclaration) {
			return $this->render_masked_accessing_identifier($node);
		}

		$name = $declaration->name;
		$is_property = $declaration instanceof PropertyDeclaration;
		if ($is_property) {
			//
		}
		if ($declaration instanceof MethodDeclaration) {
			$name = $this->get_normalized_method_name($name);
		}
		elseif ($declaration instanceof ClassConstantDeclaration) {
			$name = $this->get_normalized_name($name);
		}

		if ($node->master instanceof CallExpression && $node->master->is_class_new()) {
			// for the class new expression
			$master = $node->master->render($this);
			$master = "($master)";
		}
		else {
			$master = $this->render_master_expression($node->master);
		}

		// 当前已无支持带名称空间访问
		// elseif ($node->master instanceof Identifiable && $node->master->symbol->declaration instanceof NamespaceDeclaration) {
		// 	// namespace accessing
		// 	// class/function/const
		// 	return $master . static::NS_SEPARATOR . $name;
		// }

		if ($declaration->is_static) {
			// static accessing

			if ($master === _THIS) {
				$master_declaration = $node->master->symbol->declaration;
				if ($master_declaration->is_root_namespace()) {
					$master = $this->get_identifier_name_for_root_namespace_declaration($master_declaration);
				}
				else {
					$master = $this->get_normalized_name($master_declaration->name);
				}
			}

			if ($is_property) {
				$name = $this->add_variable_prefix($name);
			}

			// cannot use '$this' for private member, it will be cause syntax error
			if ($declaration->modifier === _PRIVATE) {
				$master = 'self';
			}

			$operator = static::CLASS_MEMBER_OPERATOR;
		}
		elseif ($master === '$super') {
			// $super need map to parent
			$master = 'parent';

			if ($is_property) {
				$name = $this->add_variable_prefix($name);
			}

			$operator = static::CLASS_MEMBER_OPERATOR;
		}
		else {
			// object accessing
			$operator = static::OBJECT_MEMBER_OPERATOR;
		}

		return $master . $operator . $name;
	}

	protected function render_masked_accessing_identifier(AccessingIdentifier $node)
	{
		$declaration = $node->symbol->declaration;
		$masked = $declaration->body;

		if ($masked instanceof CallExpression) {
			$actual_arguments = [];
			foreach ($declaration->arguments_map as $idx) {
				// assert($idx === 0);
				$actual_arguments[] = $node->master;
			}

			$actual_call = clone $masked;
			$actual_call->arguments = $actual_arguments;
			$actual_call->callee->pos = $node->pos; // just for debug

			return $this->render_basecall_expression($actual_call);
		}
		elseif ($masked instanceof PlainIdentifier) {
			if ($masked->name === _THIS) {
				return $node->master->render($this);
			}
			else {
				return $masked->render($this);
			}
		}
		elseif ($masked instanceof ILiteral) {
			return $masked->render($this);
		}
		else {
			throw new Exception("Unknow masked contents.", $declaration);
		}
	}

	protected function render_masked_call(CallExpression $node)
	{
		$declaration = $node->callee->symbol->declaration;
		$masked = $declaration->body;

		$masking_arguments = $node->normalized_arguments ?? $node->arguments;

		$actual_arguments = [];
		foreach ($declaration->arguments_map as $dest_idx => $src) {
			// an expression, but not an argument
			if (!is_int($src)) {
				$actual_arguments[] = $src;
				continue;
			}

			// the 'this'
			if ($src === 0) {
				$actual_arguments[] = $node->callee->master;
				continue;
			}

			// because offset 0 in arguments_map is 'this'
			$actual_index = $src - 1;
			if (isset($masking_arguments[$actual_index])) {
				$arg_value = $masking_arguments[$actual_index];
			}
			elseif (isset($declaration->parameters[$actual_index]->value)) {
				$arg_value = $declaration->parameters[$actual_index]->value;
			}
			else {
				throw new Exception("Unexpected render error for masked call '{$node->callee->name}'.");
			}

			if ($arg_value === ASTFactory::$default_value_marker) {
				// is should be the last real argument, so we check it is correct
				if (count($declaration->arguments_map) !== count($actual_arguments) + 1) {
					throw new Exception("Unexpected arguments error for masked call '{$node->callee->name}'.");
				}
			}
			else {
				$actual_arguments[] = $arg_value;
			}
		}

		$actual_call = clone $masked;
		$actual_call->arguments = $actual_arguments;
		$actual_call->callee->pos = $node->callee->pos; // just for debug

		return $this->render_basecall_expression($actual_call);
	}

	public function render_call_expression(CallExpression $node)
	{
		return $this->render_basecall_expression($node);
	}

	public function render_pipecall_expression(PipeCallExpression $node)
	{
		return $this->render_basecall_expression($node);
	}

	public function render_basecall_expression(BaseCallExpression $node)
	{
		if ($node->infered_callee_declaration instanceof MaskedDeclaration) {
			return $this->render_masked_call($node);
		}

		$callee = $this->render_master_expression($node->callee);

		// object member as callee, must be got it result, then handle call
		if ($node->infered_callee_declaration instanceof IVariableDeclaration
			and $node->callee instanceof AccessingIdentifier
			// and $node->callee->symbol->declaration !== ASTFactory::$virtual_property_for_any
		) {
			$callee = "($callee)";
		}

		$arguments = $node->normalized_arguments ?? $node->arguments;
		$arguments = $this->render_arguments($arguments);

		if ($node->is_class_new()) {
			$code = "new {$callee}($arguments)";
		}
		else {
			$code = "{$callee}($arguments)";
		}

		return $code;
	}

	protected function render_arguments(array $nodes)
	{
		if (!$nodes) return '';

		$items = [];
		foreach ($nodes as $arg) {
			if ($arg) {
				$item = $arg->render($this);
			}
			else {
				$item = static::VAL_NONE;
			}

			$items[] = $item;
		}

		$code = join(', ', $items);
		return $code;
	}

	public function render_callback_argument(CallbackArgument $node)
	{
		$arg = $node->value;
		if ($arg instanceof AccessingIdentifier) {
			$master = $arg->master->render($this);

			// format for call_use_func
			return "[$master, '{$arg->name}']";
		}

		return $arg->render($this);
	}

	public function render_constant_identifier(ConstantIdentifier $node)
	{
		return $this->get_normalized_name($node->name);
	}

	protected function add_variable_prefix(string $name)
	{
		return _DOLLAR . $name;
	}

	public function render_variable_identifier(VariableIdentifier $node)
	{
		return $this->add_variable_prefix($node->name);
	}

	public function render_plain_identifier(PlainIdentifier $node)
	{
		$declaration = $node->symbol->declaration;

		// variable
		if ($declaration instanceof IVariableDeclaration) {
			return $this->add_variable_prefix($node->name);
		}

		if ($declaration instanceof ClassKindredDeclaration) {
			$name = $this->get_classkindred_identifier_name($node);
		}
		else {
			// function/constant
			$name = $this->get_normalized_name_with_declaration($declaration);
		}

		if (!$node->is_call_mode) {
			if ($declaration instanceof FunctionDeclaration) {
				$uri = ltrim($declaration->program->unit->dist_ns_uri, static::NS_SEPARATOR);
				$name = sprintf("'%s%s%s'", $uri, static::NS_SEPARATOR, $name);
			}
			elseif ($declaration instanceof ClassKindredDeclaration) {
				$name = TeaHelper::is_builtin_type_name($declaration->name)
					? "'{$declaration->name}'"
					: $name . '::class';
			}
		}

		return $name;
	}

	public function render_type(IType $node)
	{
		$code = $node->render($this);

		if ($code && $node->nullable && !$node->has_null) {
			$code = '?' . $code;
		}

		return $code;
	}

	public function render_type_identifier(BaseType $node)
	{
		return static::TYPE_MAP[$node->name] ?? $node->name;
	}

	public function render_classkindred_identifier(ClassKindredIdentifier $node)
	{
		if ($node->ns) {
			$name = $this->get_normalized_name($node->name);
			$name = $this->render_namespace_identifier($node->ns) . static::NS_SEPARATOR . $name;
		}
		else {
			$name = $this->get_classkindred_identifier_name($node);
		}

		return $name;
	}

	private function get_classkindred_identifier_name(PlainIdentifier $node)
	{
		$declaration = $node->symbol->declaration;
		if ($declaration->is_root_namespace()) {
			$name = $this->get_identifier_name_for_root_namespace_declaration($declaration);
		}
		else {
			$name = $this->get_normalized_name($node->name);
		}

		return $name;
	}

	private function get_identifier_name_for_root_namespace_declaration(ClassKindredDeclaration $declaration)
	{
		$name = $declaration->name;

		if ($declaration->origin_name !== null) {
			$name = $declaration->origin_name;
		}

		return static::NS_SEPARATOR . $name;
	}

	public function render_namespace_identifier(NamespaceIdentifier $node)
	{
		return static::ns_to_string($node);
	}

	public static function ns_to_string(NamespaceIdentifier $identifier)
	{
		// use root namespace for tea builtins
		if ($identifier->uri === _BUILTIN_NS) {
			return null;
		}

		return strtr($identifier->uri, static::NAMESPACE_REPLACES);
	}

	public function render_var_statement(VarStatement $node)
	{
		$items = [];
		foreach ($node->members as $member) {
			if ($member->value) {
				$value = $member->value->render($this);
			}
			else {
				$value = static::VAL_NONE;
			}

			$name = $this->add_variable_prefix($member->name);
			$items[] = $name . ' = ' . $value . static::STATEMENT_TERMINATOR;
		}

		$code = join("\n", $items);

		return $code;
	}

	public function render_variable_declaration(VariableDeclaration $node)
	{
		$code = $this->add_variable_prefix($node->name);
		if ($node->value) {
			$code .= ' = ' . $node->value->render($this);
		}
		else {
			$code .= ' = null';
		}

		return $code . static::STATEMENT_TERMINATOR;
	}

	// public function render_super_variable_declaration(SuperVariableDeclaration $node)
	// {
	// 	return null;
	// }

	private function render_master_expression(BaseExpression $expr)
	{
		if ($expr instanceof CastOperation) {
			$code = $this->render_cast_operation($expr, true);
		}
		else {
			$code = $expr->render($this);
		}

		return $code;
	}

	public function render_square_accessing(SquareAccessing $node)
	{
		$master = $this->render_master_expression($node->expression);

		if ($node->is_prefix) {
			$code = "array_shift({$master})";
		}
		else {
			$code = "array_pop({$master})";
		}

		return $code;
	}

	public function render_key_accessing(KeyAccessing $node)
	{
		$master = $this->render_master_expression($node->left);

		if ($node->right === null) {
			return "{$master}[]";
		}

		$key = $node->right->render($this);

		// the auto-cast type to String
		$infered_type = $node->right->infered_type;
		if (!TypeFactory::is_dict_key_directly_supported_type($infered_type)) {
			// Cast others to string, and bool to ''
			// Avoid of float/bool being cast to integers
			$key = '(string)' . $key;
		}

		return "{$master}[{$key}]";
	}

	public function render_integer_literal(IntegerLiteral $node)
	{
		$num = $this->remove_number_underline($node->value);
		if (strpos($num, 'o')) {
			$num = str_replace('o', '', $num);
		}

		return $num;
	}

	public function render_float_literal(FloatLiteral $node)
	{
		return $this->remove_number_underline($node->value);
	}

	protected function remove_number_underline(string $num)
	{
		return strpos($num, _UNDERSCORE) ? str_replace(_UNDERSCORE, _NOTHING, $num) : $num;
	}

	public function render_unescaped_string_literal(UnescapedStringLiteral $node)
	{
		$code = "'$node->value'";
		return $this->new_string_placeholder($code);
	}

	public function render_escaped_string_literal(EscapedStringLiteral $node)
	{
		if (strpos($node->value, _DOLLAR) === false) {
			$value = $node->value;
		}
		else {
			$value = $this->add_escape_slashs($node->value, _DOLLAR);
		}

		$code = "\"$value\"";
		return $this->new_string_placeholder($code);
	}

	protected static function add_escape_slashs(string $string, string $escaping)
	{
		$components = explode($escaping, $string);
		for ($j = count($components) - 2; $j >=0; $j--) {
			$item = &$components[$j];

			// check is need to escape
			$need_escape = true;
			for ($i = strlen($item) - 1; $i >= 0; $i--) {
				if ($item[$i] === static::NS_SEPARATOR) {
					$need_escape = !$need_escape;
				}
				else {
					break;
				}
			}

			if ($need_escape) {
				$item .= static::NS_SEPARATOR;
			}
		}

		return join($escaping, $components);
	}

	public function render_unescaped_string_interpolation(UnescapedStringInterpolation $node)
	{
		foreach ($node->items as $item) {
			if (is_string($item)) {
				$pieces[] = "'$item'";
			}
			elseif ($item) {
				$item = $this->render_instring_expression($item);
				$pieces[] = $item;
			}
		}

		$code = join(' . ', $pieces);
		return $this->new_string_placeholder($code);
	}

	public function render_escaped_string_interpolation(EscapedStringInterpolation $node)
	{
		$items = ['"'];
		foreach ($node->items as $item) {
			if (is_string($item)) {
				$items[] = $item;
			}
			elseif ($this->is_interpolated($item)) {
				$item = $item->render($this);
				$items[] = "{{$item}}";
			}
			else {
				$item = $this->render_instring_expression($item);
				if (count($items) === 1) {
					$items = [$item . ' . "'];
				}
				else {
					$items[] = '" . ' . $item;
					$items[] = ' . "';
				}
			}
		}

		if (end($items) === ' . "') {
			array_pop($items);
		}
		else {
			$items[] = '"';
		}

		$code = join($items);
		return $this->new_string_placeholder($code);
	}

	private function is_interpolated(BaseExpression $item)
	{
		if ($item instanceof PlainIdentifier) {
			if ($item->symbol->declaration instanceof IVariableDeclaration) {
				return true;
			}
		}
		elseif ($item instanceof KeyAccessing) {
			return true;
		}
		elseif ($item instanceof AccessingIdentifier) {
			$declaration = $item->symbol->declaration;
			if ($declaration instanceof PropertyDeclaration and !$declaration->is_static) {
				return true;
			}
		}

		return false;
	}

	protected function render_instring_expression(BaseExpression $expr)
	{
		$code = $expr->render($this);

		if ($expr instanceof MultiOperation) {
			return '(' . $code . ')';
		}

		return $code;
	}

	// public function render_object_expression(BaseExpression $node)
	// {
	// 	$members = $node->class_declaration->members;

	// 	$items = [];
	// 	foreach ($members as $subnode) {
	// 		$items[] = $subnode->render($this);
	// 		if ($subnode->value instanceof LambdaExpression) {
	// 			//
	// 		}
	// 	}

	// 	$body = $this->join_member_items($items, $node->is_vertical_layout);

	// 	return $this->wrap_object($body);
	// }

	protected function render_key_for_object_member(ObjectMember $node)
	{
		return "'{$node->name}'";
	}

	protected function wrap_object(string $body)
	{
		return '(object)[' . $body . ']';
	}

	public function render_cast_operation(CastOperation $node, bool $add_parentheses = false)
	{
		$type_name = static::TYPE_MAP[$node->right->name] ?? null;

		if ($type_name !== null && in_array($type_name, static::CASTABLE_TYPES, true)) {
			$left = $this->render_subexpression($node->left, $node->operator);
			if ($node->right->name === _UINT) {
				$code = "uint_ensure((int)$left)";
			}
			elseif ($add_parentheses) {
				$code = "(($type_name)$left)";
			}
			else {
				$code = "($type_name)$left";
			}
		}
		else {
			$code = $node->left->render($this); // not to do anything for non-castable
		}

		return $code;
	}

	public function render_is_operation(IsOperation $node)
	{
		$left = $this->render_subexpression($node->left, $node->operator);
		$type_name = static::TYPE_MAP[$node->right->name] ?? null;

		if ($type_name) {
			if ($node->right->name === _UINT) {
				$type_name = 'uint';
			}

			$code = "is_{$type_name}($left)";
			if ($node->is_not) {
				$code = '!' . $code;
			}
		}
		elseif ($node->right instanceof NoneType) {
			$operator = $node->is_not ? '!==' : '===';
			$code = "{$left} $operator null";
		}
		else {
			$right = $this->get_classkindred_identifier_name($node->right);
			$code = "{$left} instanceof {$right}";
			if ($node->is_not) {
				$code = '!' . $code;
			}
		}

		return $code;
	}

	public function render_prefix_operation(BaseExpression $node)
	{
		$operator = $node->operator;
		$expression = $node->expression;

		$expr_code = $expression->render($this);
		if ($this->is_need_parentheses_for_operation_item($expression, $operator, true)) {
			$expr_code = "($expr_code)";
		}

		return $this->get_operator_sign($operator) . $expr_code;
	}

	public function render_binary_operation(BinaryOperation $node)
	{
		$operator = $node->operator;
		$left = $node->left->render($this);
		$right = $node->right->render($this);

		if ($operator->is(OPID::ARRAY_CONCAT)) {
			// concat Arrays
			// $code = sprintf('array_merge(%s, array_values(%s))', $left, $right);
			$code = sprintf('array_merge(%s, %s)', $left, $right);
		}
		elseif ($operator->is(OPID::ARRAY_UNION)) {
			// union Arrays / Dicts
			$code = "$right + $left";
		}
		elseif ($operator->is(OPID::REMAINDER) && $node->infered_type === TypeFactory::$_float) {
			// use the 'fmod' function for the float arguments
			$code = sprintf('fmod(%s, %s)', $left, $right);
		}
		else {
			if ($this->is_need_parentheses_for_operation_item($node->left, $operator)) {
				$left = "($left)";
			}

			if ($this->is_need_parentheses_for_operation_item($node->right, $operator, true)) {
				$right = "($right)";
			}

			$code = sprintf('%s %s %s', $left, $this->get_operator_sign($operator), $right);
		}

		return $code;
	}

	protected function get_operator_sign(Operator $oper)
	{
		return $oper->php_sign;
	}

	private function is_need_parentheses_for_operation_item(BaseExpression $expr, Operator $prev_operator, bool $right_side = false)
	{
		if ($expr instanceof BaseOperation) {
			$prev_prec = $prev_operator->php_prec;
			$curr_prec = $expr->operator->php_prec;
			$need = ($curr_prec > $prev_prec)
				|| (($right_side || ($prev_operator->php_assoc === OP_R)) && ($curr_prec === $prev_prec));
		}
		else {
			$need = false;
		}

		return $need;
	}

	protected function render_subexpression(BaseExpression $expr, Operator $operator)
	{
		$code = $expr->render($this);
		if ($expr instanceof MultiOperation
			&& $expr->operator->php_prec >= $operator->php_prec) {
			$code = '(' . $code . ')';
		}

		return $code;
	}

	public function render_none_coalescing_operation(NoneCoalescingOperation $node)
	{
		$expr = end($node->items)->render($this);
		for ($i = count($node->items) - 2; $i >= 0; $i--) {
			$item = $node->items[$i];
			$left = $item->render($this);

			if ($item instanceof CastOperation) {
				$test = $item->left->render($this);
				$expr = sprintf("isset(%s) ? %s : %s", $test, $left, $expr ?? static::VAL_NONE);
				if ($i !== 0) {
					$expr = "($expr)";
				}
			}
			elseif ($expr !== null) {
				$expr = "$left ?? $expr";
			}
			else {
				$expr = $item instanceof MultiOperation ? "($left)" : $left;
			}
		}

		return $expr;
	}

	// public function render_include_expression(IncludeExpression $expr)
	// {
	// 	$path = $this->generate_include_string($expr->target);
	// 	return "(include $path)";
	// }

	// private function generate_include_string(string $name)
	// {
	// 	$filename = $this->program->unit->include_prefix . $name . '.php';
	// 	return "UNIT_PATH . '{$filename}'";
	// }

	protected function render_with_post_condition(PostConditionAbleStatement $node, string $code)
	{
		return sprintf("if (%s) {\n\t%s\n}", $node->condition->render($this), $code);
	}

	public function render_break_statement(Node $node)
	{
		$argument = $node->target_layers > 1 ? ' ' . $node->target_layers : '';
		$code = 'break' . $argument . static::STATEMENT_TERMINATOR;

		if ($node->condition) {
			$code = $this->render_with_post_condition($node, $code);
		}

		return $code;
	}

	public function render_continue_statement(Node $node)
	{
		// php can continue to switch-block, so needed to ignore switch-blocks
		$target_layers = $node->target_layers;
		if ($node->switch_layers) {
			if ($target_layers == 0) {
				$target_layers = 1;
			}

			$target_layers += $node->switch_layers;
		}

		$argument = $target_layers > 1 ? ' ' . $target_layers : '';
		$code = 'continue' . $argument . static::STATEMENT_TERMINATOR;

		if ($node->condition) {
			$code = $this->render_with_post_condition($node, $code);
		}

		return $code;
	}

	public function render_echo_statement(EchoStatement $node)
	{
		// for print
		if (!$node->end_newline) {
			$arguments = $this->render_arguments($node->arguments);
			return "echo $arguments;";
		}

		// for echo
		if ($node->arguments) {
			$arguments = $this->render_arguments($node->arguments);
			return "echo $arguments, LF;";
		}
		else {
			return 'echo LF;';
		}
	}
}
