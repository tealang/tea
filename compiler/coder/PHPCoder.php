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

	const NS_SEPARATOR = _BACK_SLASH;

	const STATEMENT_TERMINATOR = ';';

	const CLASS_MEMBER_TERMINATOR = ';';

	const CLASS_MEMBER_OPERATOR = '::';

	const OBJECT_MEMBER_OPERATOR = '->';

	const DICT_KV_OPERATOR = ' => ';

	const DICT_EMPTY_VALUE = '[]';

	const NONE = 'null';

	const NAMESPACE_REPLACES = [_STRIKETHROUGH => _UNDERSCORE, _DOT => _UNDERSCORE, _SLASH => _BACK_SLASH];

	const CASTABLE_TYPES = ['string', 'int', 'float', 'bool', 'array', 'object'];

	const TYPE_MAP = [
		_VOID => 'void', _ANY => '',
		_STRING => 'string', _INT => 'int', _UINT => 'int', _FLOAT => 'float', _BOOL => 'bool',
		_ARRAY => 'array', _DICT => 'array',
		_CALLABLE => 'callable', _ITERABLE => 'iterable', _OBJECT => 'object',
		_REGEX => 'string', _XVIEW => 'string', _METATYPE => 'string',
	];

	const EXTRA_RESERVEDS = [
		'class', 'interface', 'abstract', 'trait', 'final',
		'function', 'const', 'array', 'callable',
		'list', 'each', 'foreach', 'default'
	];

	const CLASS_MEMBER_NAMES_MAP = [
		_CONSTRUCT => '__construct', _DESTRUCT => '__destruct',  'to_string' => '__toString',
		'CLASS' => '__CLASS', // 'CLASS' can not to use as a class constant name in PHP
	];

	const OPERATOR_MAP = [
		_IS => 'instanceof',
		_CONCAT => '.',
		_NOT => '!',
		_AND => '&&',
		_OR => '||',
		_REMAINDER => '%',
		_EXPONENTIATION => '**',
		_BITWISE_XOR => '^',
		'^|=' => '^=',
	];

	// precedences for MultiOperation
	const OPERATOR_PRECEDENCES = [
		_VCAT => 0, 		// array concat, use function
		// _MERGE => 0,		// array/dict merge, use function
		_DOUBLE_COLON => 1, // cast
		'**' => 2, 			// 幂运算符
		'instanceof' => 3, 	// 类型
		'*' => 4, '/' => 4, '%' => 4, 	// 算术运算符
		'+' => 5, '-' => 5, '.' => 5,	// 算术运算符和字符串运算符
		'<<' => 6, '>>' => 6, 			// 位运算符
		'<' => 7, '<=' => 7, '>' => 7, '>=' => 7, 	// 比较运算符
		'==' => 8, '!=' => 8, '===' => 8, '!==' => 8, '<>' => 8, '<=>' => 8, 	// 比较运算符
		'&' => 9, 		// 位运算符和引用
		'^' => 10, 		// 位运算符
		'|' => 11, 		// 位运算符
		'&&' => 12, 	// 逻辑运算符
		'||' => 13, 	// 逻辑运算符
		'??' => 14, 	// 比较运算符
		'?' => 15,		// 三元运算符
	];

	const PROGRAM_HEADER = '<?php';

	public $include_prefix;

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
			// 公用常量和函数都生成到了__public.php中
			if (!$node->is_unit_level || $node instanceof ClassKindredDeclaration) {
				$declarations[] = $node;
			}
		}

		$this->process_use_statments($declarations);

		$items = $this->render_heading_statements($program);

		if ($program->as_main_program) {
			if ($program->unit->is_used_coroutine) {
				$items[] = '\Swoole\Runtime::enableCoroutine();';
				$items[] = '';
			}

			$levels = $program->get_subdirectory_levels();
			if ($levels) {
				// 在Unit子目录中
				$items[] = "require_once dirname(__DIR__, {$levels}) . '/__public.php';\n";
			}
			else {
				// 在Unit根目录中
				$items[] = "require_once __DIR__ . '/__public.php';\n";
			}
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

			if ($program->as_main_program && $program->unit->is_used_coroutine) {
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
				// do not do anything
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

	protected function generate_class_header(ClassKindredDeclaration $node, string $kind = null)
	{
		$modifier = $node->modifier ?? _INTERNAL;
		$name = $this->get_normalized_name($node->name);
		$type = $node instanceof InterfaceDeclaration ? 'interface' : 'class';

		return "#$modifier\n{$type} {$name}";
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

	protected function generate_function_header(FunctionDeclaration $node)
	{
		$modifier = '';
		if ($node->super_block) {
			if ($node->modifier === _INTERNAL) {
				// $modifier = "#internal\n";
			}
			else {
				$modifier = ($node->modifier ?? _PUBLIC) . ' ';
			}

			if ($node->is_static) {
				$modifier .= 'static ';
			}
		}
		// else {
			// $modifier = $node->modifier ?? _INTERNAL;
			// $modifier = "#$modifier\n";
		// }

		// thats method
		if ($node->super_block) {
			$name = $this->get_normalized_class_member_name($node);
		}
		else {
			$name = $this->get_normalized_name($node->name);
		}

		return "{$modifier}function $name";
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
		$code = "const {$node->name}";

		if ($node->modifier === _INTERNAL) {
			$code = "#internal\n$code";
		}
		elseif ($node->modifier && $node->modifier !== _PUBLIC) {
			$code = "{$node->modifier} $code";
		}

		return $code;
	}

	protected function generate_constant_header(ConstantDeclaration $node)
	{
		if (!$node->modifier || $node->modifier === _INTERNAL) {
			$modifier = 'internal';
		}
		else {
			$modifier = $node->modifier;
		}

		$name = $this->get_normalized_name($node->name);

		return "#{$modifier}\nconst $name";
	}

// ---

	public function render_constant_declaration(ConstantDeclaration $node)
	{
		if ($node->label === _PHP) {
			return null;
		}

		return parent::render_constant_declaration($node);
	}

	public function render_masked_declaration(MaskedDeclaration $node)
	{
		return null;
	}

	public function render_function_declaration(FunctionDeclaration $node)
	{
		if ($node->body === null) {
			return null;
		}

		$header = $this->generate_function_header($node);
		$parameters = $this->render_function_parameters($node);
		$body = $this->render_function_body($node);

		if ($node->type === null || $node->type === TypeFactory::$_any || $node->type === TypeFactory::$_void) {
			$return_type = null;
		}
		else {
			$return_type = $this->render_type($node->type);
		}

		return $return_type
			? "$header($parameters): $return_type $body"
			: "$header($parameters) $body";
	}

	public function render_coroutine_block(CoroutineBlock $node)
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

		return sprintf('\Swoole\Coroutine::create(%s %s);', $header, $body);
	}

	public function render_lambda_expression(LambdaExpression $node)
	{
		$parameters = $this->render_parameters($node->parameters);
		$body = $this->render_function_body($node);

		if ($node->use_variables) {
			$uses = $this->render_lambda_use_arguments($node);
			return sprintf('function (%s) use(%s) ', $parameters, $uses) . $body;
		}

		return sprintf('function (%s) ', $parameters) . $body;
	}

	protected function render_lambda_use_arguments(LambdaExpression $node)
	{
		foreach ($node->use_variables as $arg) {
			$item = $arg->render($this);
			if (in_array($arg->name, $node->mutating_variable_names, true)) {
				$item = '&' . $item;
			}

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

// ---

	public function render_class_declaration(ClassDeclaration $node)
	{
		if ($node instanceof BuiltinTypeClassDeclaration || $node->label === _PHP) {
			return null;
		}

		$items = $this->render_block_nodes($node->members);

		$traits = $this->get_traits_by_interface_identifiers($node->baseds);
		if ($traits) {
			array_unshift($items, 'use ' . join(', ', $traits) . ";\n\n");
		}

		$code = sprintf("%s%s %s",
			$this->generate_class_header($node),
			$this->generate_class_baseds($node),
			$this->wrap_block_code($items)
		);

		return $code;
	}

	private function get_traits_by_interface_identifiers(array $identifiers)
	{
		$traits = [];
		foreach ($identifiers as $identifier) {
			if ($identifier->symbol->declaration->has_default_implementations) {
				$interface_name = $identifier->render($this);
				$traits[] = $this->get_interface_trait_name($interface_name);
			}
		}

		return $traits;
	}

	public function render_interface_declaration(InterfaceDeclaration $node)
	{
		if ($node->label === _PHP) return null;

		// interface declare
		$code = sprintf("%s%s %s",
			$this->generate_class_header($node),
			$this->generate_class_baseds($node),
			$this->wrap_block_code($this->render_interface_members($node))
		);

		// use trait to code the implements
		if ($node->has_default_implementations) {
			$trait_members = [];
			foreach ($node->members as $member) {
				if ($member instanceof PropertyDeclaration || ($member instanceof FunctionDeclaration && $member->body !== null)) {
					$trait_members[] = $member;
				}
			}

			$name = $this->get_normalized_name($node->name);
			$code .= sprintf("\n\ntrait %s %s",
				$this->get_interface_trait_name($name),
				$this->wrap_block_code($this->render_block_nodes($trait_members))
			);
		}

		return $code;
	}

	protected static function get_interface_trait_name(string $interface_name)
	{
		return $interface_name . 'Trait';
	}

	protected function render_interface_members(InterfaceDeclaration $declaration)
	{
		$members = [];
		foreach ($declaration->members as $member) {
			if ($member instanceof FunctionDeclaration) {
				$item = $this->render_interface_method($member);
			}
			elseif ($member instanceof ClassConstantDeclaration) {
				$item = $this->render_class_constant_declaration($member);
			}
			else {
				continue;
			}

			$members[] = $item === LF ? $item : $item . LF;
		}

		return $members;
	}

	protected function render_interface_method(FunctionDeclaration $node)
	{
		$header = $this->generate_function_header($node);
		$parameters = $this->render_function_parameters($node);

		$code = "{$header}($parameters)";

		if ($node->type !== null && $node->type !== TypeFactory::$_any && $node->type !== TypeFactory::$_void) {
			$return_type = $this->render_type($node->type);
			$code .= ": $return_type";
		}

		return $code . static::CLASS_MEMBER_TERMINATOR;
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
			$items[] = sprintf("if (%s) %s", $node->condition->render($this), $this->render_control_structure_body($node, 'case-elseif'));

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

		if (isset($node) && !$node instanceof BreakStatement) {
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
			$for_code = "for ($var = $start; $var >= $end; $var -= $step) $body";
		}
		else {
			$for_code = "for ($var = $start; $var <= $end; $var += $step) $body";
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

		if ($node->do_the_first) {
			$code = sprintf('do %s while (%s);', $body, $test);
		}
		else {
			$code = sprintf('while (%s) %s', $test, $body);
		}

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

	public function render_html_escape_expression(HTMLEscapeExpression $node)
	{
		$expr = $node->expression->render($this);
		return "htmlspecialchars($expr, ENT_QUOTES)";
	}

	protected function render_xblock_elements(array $items)
	{
		foreach ($items as $k => $item) {
			if ($item instanceof BaseExpression) {
				$expr = $this->render_subexpression($item, OperatorFactory::$_concat);
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

	private function get_normalized_class_member_name(IClassMemberDeclaration $declaration)
	{
		$name = $declaration->name;
		if (isset(static::CLASS_MEMBER_NAMES_MAP[$name]) && $declaration instanceof FunctionDeclaration) {
			$name = static::CLASS_MEMBER_NAMES_MAP[$name];
		}

		return $name;
	}

	private function get_normalized_name(string $name)
	{
		if (in_array(strtolower($name), static::EXTRA_RESERVEDS, true)) {
			$name = '__' . $name;
		}

		return $name;
	}

	public function render_accessing_identifier(AccessingIdentifier $node)
	{
		$declaration = $node->symbol->declaration;
		if ($declaration instanceof MaskedDeclaration) {
			return $this->render_masked_accessing_identifier($node);
		}

		$name = $declaration === ASTFactory::$virtual_property_for_any
			? $node->name
			: $this->get_normalized_class_member_name($declaration);

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

			if ($declaration instanceof PropertyDeclaration) {
				$name = $this->add_variable_prefix($name);
			}

			$operator = static::CLASS_MEMBER_OPERATOR;
		}
		elseif ($master === '$super') {
			// $super need map to parent
			$master = 'parent';

			if ($declaration instanceof PropertyDeclaration) {
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
				assert($idx === 0);
				$actual_arguments[] = $node->master;
			}

			$actual_call = clone $masked;
			$actual_call->arguments = $actual_arguments;
			$actual_call->callee->pos = $node->pos; // just for debug

			return $this->render_call_expression($actual_call);
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
			throw new \Exception("Unknow masked contents.", $declaration);
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

		return $this->render_call_expression($actual_call);
	}

	public function render_call_expression(CallExpression $node)
	{
		if ($node->callee->symbol && $node->callee->symbol->declaration instanceof MaskedDeclaration) {
			return $this->render_masked_call($node);
		}

		$callee = $this->render_master_expression($node->callee);

		$arguments = $node->normalized_arguments ?? $node->arguments;
		$arguments = $this->render_arguments($arguments);

		if ($node->is_class_new()) {
			return "new {$callee}($arguments)";
		}
		else {
			return "{$callee}($arguments)";
		}
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
				$item = 'null';
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
			$name = $this->get_normalized_name($node->name);
		}

		if (!$node->is_call_mode) {
			if ($declaration instanceof FunctionDeclaration) {
				$name = sprintf("'%s%s%s'", $declaration->program->unit->dist_ns_uri, static::NS_SEPARATOR, $name);
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

		if ($code && $node->nullable) {
			$code = '?' . $code;
		}

		return $code;
	}

	public function render_type_identifier(BaseType $node)
	{
		return static::TYPE_MAP[$node->name] ?? $node->name;
	}

	public function render_union_type_identifier(UnionType $node)
	{
		return static::TYPE_MAP[_ANY];
	}

	public function render_classkindred_identifier(ClassKindredIdentifier $node)
	{
		if ($node->ns) {
			$name = $this->get_normalized_name($node->name);
			return $this->render_plain_identifier($node->ns) . static::NS_SEPARATOR . $name;
		}

		return $this->get_classkindred_identifier_name($node);
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

	public function render_ns_identifier(NSIdentifier $node)
	{
		return static::ns_to_string($node);
	}

	public static function ns_to_string(NSIdentifier $identifier)
	{
		// use root namespace for tea builtins
		if ($identifier->uri === _BUILTIN_NS) {
			return null;
		}

		return strtr($identifier->uri, static::NAMESPACE_REPLACES);
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

	public function render_super_variable_declaration(SuperVariableDeclaration $node)
	{
		return null;
	}

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

	public function render_key_accessing(KeyAccessing $node)
	{
		$master = $this->render_master_expression($node->left);
		$key = $node->right->render($this);

		if (isset($node->right->infered_type)) {
			// the auto-cast type to String
			if ($node->right->infered_type !== TypeFactory::$_uint && $node->right->infered_type !== TypeFactory::$_int) {
				// 如果不强制转为string, float/bool将会被转成int
				$key = '(string)' . $key;
			}
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
				if ($item[$i] === _BACK_SLASH) {
					$need_escape = !$need_escape;
				}
				else {
					break;
				}
			}

			if ($need_escape) {
				$item .= _BACK_SLASH;
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
			elseif (($item instanceof Identifiable && !$item->symbol->declaration instanceof IConstantDeclaration) || $item instanceof KeyAccessing) {
				$item = $item->render($this);
				$items[] = "{{$item}}";
			}
			elseif ($item) {
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

	protected function render_instring_expression(BaseExpression $expr)
	{
		$code = $expr->render($this);

		if ($expr instanceof MultiOperation) {
			return '(' . $code . ')';
		}

		return $code;
	}

	protected function render_dict_key(BaseExpression $expr)
	{
		$key = $expr->render($this);
		if (isset($expr->infered_type)) {
			// the auto-cast type to String
			if ($expr->infered_type !== TypeFactory::$_uint && $expr->infered_type !== TypeFactory::$_int) {
				// 如果不强制转为string, float/bool将会被转成int
				$key = '(string)' . $key;
			}
		}

		return $key;
	}

	protected function render_object_item(string $key, BaseExpression $value)
	{
		$value = $value->render($this);
		return "'$key' => $value";
	}

	protected function wrap_object(string $body)
	{
		return '[' . $body . ']';
	}

	public function render_cast_operation(CastOperation $node, bool $add_parentheses = false)
	{
		$type_name = static::TYPE_MAP[$node->right->name] ?? null;

		if ($type_name !== null && in_array($type_name, static::CASTABLE_TYPES, true)) {
			$left = $this->render_subexpression($node->left, OperatorFactory::$_cast);
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
		$left = $this->render_subexpression($node->left, OperatorFactory::$_is);
		$type_name = static::TYPE_MAP[$node->right->name] ?? null;

		if ($type_name) {
			if ($node->right->name === _UINT) {
				$type_name = 'uint';
			}

			$code = "is_{$type_name}($left)";
		}
		else {
			$right = $this->get_classkindred_identifier_name($node->right);
			$code = "{$left} instanceof {$right}";
		}

		if ($node->is_not) {
			$code = '!' . $code;
		}

		return $code;
	}

	public function render_prefix_operation(BaseExpression $node)
	{
		$expression = $node->expression->render($this);
		if ($node->operator === OperatorFactory::$_bool_not) {
			$operator = '!';
			if ($node->expression instanceof MultiOperation) {
				$expression = "($expression)";
			}
		}
		else {
			$operator = $node->operator->sign;
		}

		return $operator . $expression;
	}

	public function render_binary_operation(BinaryOperation $node)
	{
		$operator = $node->operator;
		$left = $node->left->render($this);
		$right = $node->right->render($this);

		if ($operator === OperatorFactory::$_vcat) {
			// concat Arrays
			// $code = sprintf('array_merge(%s, array_values(%s))', $left, $right);
			$code = sprintf('array_merge(%s, %s)', $left, $right);
		}
		// elseif ($operator === OperatorFactory::$_merge) {
		// 	// merge Arrays / Dicts
		// 	// $code = "$right + $left"; // the order would be incorrect in some times
		// 	$code = sprintf('array_replace(%s, %s)', $left, $right);
		// }
		elseif ($operator === OperatorFactory::$_remainder && $node->infered_type === TypeFactory::$_float) {
			// use the 'fmod' function for the float arguments
			$code = sprintf('fmod(%s, %s)', $left, $right);
		}
		else {
			if ($this->is_need_parentheses_for_operation_item($node->left, $operator, false)) {
				$left = "($left)";
			}

			if ($this->is_need_parentheses_for_operation_item($node->right, $operator)) {
				$right = "($right)";
			}

			$code = sprintf('%s %s %s', $left, $operator->dist_sign, $right);
		}

		return $code;
	}

	protected function is_need_parentheses_for_operation_item(BaseExpression $expr, Operator $operator, bool $is_rightside = true)
	{
		if ($expr instanceof MultiOperation) {
			$sub_operator = $expr->operator;
			if ($sub_operator->dist_precedence > $operator->dist_precedence) {
				return true;
			}
			elseif ($is_rightside && $sub_operator->dist_precedence === $operator->dist_precedence) {
				return true;
			}
			elseif ($sub_operator->dist_precedence === $operator->dist_precedence && $operator === OperatorFactory::$_exponentiation) {
				// the '**' operator is right associative in PHP
				return true;
			}
		}
		elseif ($operator === OperatorFactory::$_exponentiation && $expr instanceof UnaryOperation) {
			// the precedence of '**' operator is higher than unary-operator in PHP
			return true;
		}

		return false;
	}

	protected function render_subexpression(BaseExpression $expr, Operator $operator)
	{
		$code = $expr->render($this);
		if ($expr instanceof MultiOperation && $expr->operator->dist_precedence >= $operator->dist_precedence) {
			$code = '(' . $code . ')';
		}

		return $code;
	}

	public function render_none_coalescing_operation(NoneCoalescingOperation $node)
	{
		$items = $node->items;
		$end = count($items) - 1;

		$expr = null;
		for ($i = $end; $i >= 0; $i--) {
			$item = $items[$i];
			$left = $item->render($this);

			if ($item instanceof CastOperation) {
				$test = $item->left->render($this);
				$expr = sprintf("isset(%s) ? %s : %s", $test, $left, $expr ?? static::NONE);
				if ($i !== 0) {
					$expr = "($expr)";
				}
			}
			elseif ($expr !== null) {
				$expr = "$left ?? $expr";
			}
			else {
				$expr = $left;
			}
		}

		return $expr;
	}

	public function render_include_expression(IncludeExpression $expr)
	{
		$path = $this->generate_include_string($expr->target);
		return "(include $path)";
	}

	private function generate_include_string(string $name)
	{
		$filename = $this->program->unit->include_prefix . $name . '.php';
		return "UNIT_PATH . '{$filename}'";
	}

	protected function render_with_post_condition(PostConditionAbleStatement $node, string $code)
	{
		return sprintf("if (%s) {\n\t%s\n}", $node->condition->render($this), $code);
	}

	public function render_break_statement(Node $node)
	{
		$argument = $node->layer_num ? ' ' . $node->layer_num : '';
		$code = 'break' . $argument . static::STATEMENT_TERMINATOR;

		if ($node->condition) {
			$code = $this->render_with_post_condition($node, $code);
		}

		return $code;
	}

	public function render_continue_statement(Node $node)
	{
		$argument = $node->layer_num ? ' ' . $node->layer_num : '';
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
