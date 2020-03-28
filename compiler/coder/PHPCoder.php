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
		_CONCAT => _DOT,
		_NOT => '!',
		_AND => '&&',
		_OR => '||'
	];

	// 一元和三元运算直接在代码中处理
	const OPERATOR_PRECEDENCES = [
		_DOUBLE_COLON => 1,
		'**' => 2, 			// 算术运算符
		'instanceof' => 3, 	// 类型
		'*' => 4, '/' => 4, '%' => 4, 	// 算术运算符
		'+' => 5, '-' => 5, '.' => 5, 'merge' => 5,	// 算术运算符和字符串运算符
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
			if (!$node->is_unit_level || $node instanceof ClassLikeDeclaration) {
				$declarations[] = $node;
			}
		}

		$this->process_use_statments($declarations);

		$items = $this->render_heading_statements($program);

		if ($program->as_main_program) {
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
			if ($declaration instanceof ClassLikeDeclaration) {
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

	protected function generate_class_header(ClassLikeDeclaration $node, string $kind = null)
	{
		$modifier = $node->modifier ?? _INTERNAL;
		$name = $this->get_normalized_name($node);
		$type = $node instanceof InterfaceDeclaration ? 'interface' : 'class';

		return "#$modifier\n{$type} {$name}";
	}

	protected function generate_class_baseds(ClassLikeDeclaration $node)
	{
		$code = '';
		if ($node->inherits) {
			$code = ' extends ' . $this->render_classlike_identifier($node->inherits);
		}

		if ($node->baseds) {
			$items = [];
			foreach ($node->baseds as $item) {
				$items[] = $this->render_classlike_identifier($item);
			}

			$keyword = $node instanceof InterfaceDeclaration ? ' extends ' : ' implements ';
			$code .= $keyword . join(', ', $items);
		}

		return $code;
	}

	protected function generate_function_header(IFunctionDeclaration $node)
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
			$name = $this->get_normalized_name($node);
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

		$name = $this->get_normalized_name($node);

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
		$body = $this->render_enclosing_block($node);

		if ($node->type === null || $node->type === TypeFactory::$_any || $node->type === TypeFactory::$_void) {
			$return_type = null;
		}
		else {
			$return_type = $node->type->render($this);
		}

		return $return_type
			? "$header($parameters): $return_type $body"
			: "$header($parameters) $body";
	}

	public function render_lambda_expression(IExpression $node)
	{
		$parameters = $this->render_parameters($node->parameters);
		$body = $this->render_enclosing_block($node);

		if ($node->use_variables) {
			$uses = $this->render_lambda_use_arguments($node->use_variables);
			return sprintf('function (%s) use(%s) ', $parameters, $uses) . $body;
		}

		return sprintf('function (%s) ', $parameters) . $body;
	}

	protected function render_lambda_use_arguments(array $nodes)
	{
		foreach ($nodes as $arg) {
			$item = $arg->render($this);
			$items[] = '&' . $item;
		}

		return join(', ', $items);
	}

	public function render_enclosing_block(IEnclosingBlock $node)
	{
		$body = $node->fixed_body ?? $node->body;

		$items = [];

		// if ($node->auto_declarations) {
		// 	foreach ($node->auto_declarations as $declar) {
		// 		if (!$declar->block instanceof IEnclosingBlock) {
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
		if ($node->is_referenced) {
			$expr = _REFERENCE . $expr;
		}

		if ($node->type) {
			$type = $node->type->render($this);
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

			$name = $this->get_normalized_name($node);
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
			$return_type = $node->type->render($this);
			$code .= ": $return_type";
		}

		return $code . static::CLASS_MEMBER_TERMINATOR;
	}

// ---

	public function render_when_block(WhenBlock $node)
	{
		$test = $node->test->render($this);

		$branches = [];
		foreach ($node->branches as $branch) {
			$branches[] = $this->render_when_branch($branch);
		}

		if ($node->else) {
			$branches[] = $this->render_else_for_when_block($node->else);
		}

		$branches = $this->indents(join(LF, $branches));
		$code = "switch ($test) {\n$branches\n}";

		if ($node->except) {
			$code = $this->wrap_with_except_block($node->except, $code);
		}

		return $code;
	}

	protected function render_else_for_when_block(IElseBlock $node)
	{
		if ($node instanceof ElseBlock) {
			$body = $this->render_when_branch_body($node->body);
		}
		else {
			// that should be ElseIfBlock

			$items = [];
			$items[] = sprintf("if (%s) %s", $node->condition->render($this), $this->render_block($node, 'case-elseif'));

			if ($node->else) {
				$items[] = $node->else->render($this);
			}

			$body = $this->indents(join($items));
		}

		return "default:\n{$body}";
	}

	protected function render_when_branch(WhenBranch $node)
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

		$codes[] = $this->render_when_branch_body($node->body);

		return join(LF, $codes);
	}

	protected function render_when_branch_body(array $nodes)
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
		$body = $this->render_block($node);

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

		$body = $this->render_block($node);

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
		$body = $this->render_block($node);

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
		$body = $this->render_block($node);
		$code = sprintf('while (true) %s', $body);

		if ($node->except) {
			$code = $this->wrap_with_except_block($node->except, $code);
		}

		return $code;
	}

	public function render_try_block(TryBlock $node)
	{
		$items = [];
		$code = $this->render_block($node);
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
			if ($item instanceof IExpression) {
				$expr = $this->render_expression($item);
				if ($item instanceof BinaryOperation) {
					$expr = '(' . $expr . ')';
				}

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

	protected function get_normalized_class_member_name(IClassMemberDeclaration $declaration)
	{
		$name = $declaration->name;
		if (isset(static::CLASS_MEMBER_NAMES_MAP[$name]) && $declaration instanceof FunctionDeclaration) {
			$name = static::CLASS_MEMBER_NAMES_MAP[$name];
		}

		return $name;
	}

	protected function get_normalized_name(IDeclaration $declaration)
	{
		$name = $declaration->name;
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

		if ($declaration->is_static || $master === '$super') {
			// super or static accessing

			// $super need map to parent
			if ($master === '$super') {
				$master = 'parent';
			}

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
				return $this->render_expression($node->master);
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
		return $this->get_normalized_name($node->symbol->declaration);
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

		if ($declaration instanceof ClassLikeDeclaration) {
			$name = $this->get_classlike_declaration_name($declaration);
		}
		else {
			// function/constant
			$name = $this->get_normalized_name($declaration);
		}

		if (!$node->is_call_mode) {
			if ($declaration instanceof FunctionDeclaration) {
				$name = sprintf("'%s%s%s'", $declaration->program->unit->dist_ns_uri, static::NS_SEPARATOR, $name);
			}
			elseif ($declaration instanceof ClassLikeDeclaration) {
				$name = TeaHelper::is_builtin_type_name($declaration->name)
					? "'{$declaration->name}'"
					: $name . '::class';
			}
		}

		return $name;
	}

	public function render_type_identifier(BaseType $node)
	{
		return static::TYPE_MAP[$node->name] ?? $node->name;
	}

	public function render_union_type_identifier(UnionType $node)
	{
		return static::TYPE_MAP[_ANY];
	}

	public function render_classlike_identifier(ClassLikeIdentifier $node)
	{
		if ($node->ns) {
			$name = $this->get_normalized_name($declaration);
			return $this->render_plain_identifier($node->ns) . static::NS_SEPARATOR . $name;
		}

		return $this->get_classlike_declaration_name($node->symbol->declaration);
	}

	private function get_classlike_declaration_name(ClassLikeDeclaration $declaration)
	{
		$name = $this->get_normalized_name($declaration);

		if ($declaration->program->unit === null || $declaration->label === _PHP) {
			if ($declaration->origin_name !== null) {
				$name = $declaration->origin_name;
			}

			// for the builtin declaration
			$name = static::NS_SEPARATOR . $name;
		}

		return $name;
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

	private function render_master_expression(IExpression $expr)
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

	public function render_int_literal(IntegerLiteral $node)
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

	protected function render_instring_expression(IExpression $expr)
	{
		$code = $expr->render($this);

		if ($expr instanceof BinaryOperation || $expr instanceof ConditionalExpression) {
			return '(' . $code . ')';
		}

		return $code;
	}

	protected function render_dict_key(IExpression $expr)
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

	protected function render_object_item(string $key, IExpression $value)
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
		$left = $this->render_expression($node->left);

		if ($node->right->name === _UINT) {
			return "uint_ensure((int)$left)";
		}

		$type_name = static::TYPE_MAP[$node->right->name] ?? null;
		if ($type_name === null || !in_array($type_name, static::CASTABLE_TYPES, true)) {
			return $left; // not to do anything
		}

		$code = "($type_name)$left";
		if ($add_parentheses) {
			$code = "($code)";
		}

		return $code;
	}

	public function render_is_operation(IsOperation $node)
	{
		$left = $this->render_expression($node->left);
		$type_name = static::TYPE_MAP[$node->right->name] ?? null;

		if ($type_name) {
			if ($node->right->name === _UINT) {
				$type_name = 'uint';
			}

			$code = "is_{$type_name}($left)";
		}
		else {
			$right = $this->get_classlike_declaration_name($node->right->symbol->declaration);
			$code = "{$left} instanceof {$right}";
		}

		if ($node->is_not) {
			$code = '!' . $code;
		}

		return $code;
	}

	public function render_prefix_operation(IExpression $node)
	{
		$expression = $node->expression->render($this);
		if ($node->operator === OperatorFactory::$_bool_not) {
			$operator = '!';
			if ($node->expression instanceof BinaryOperation) {
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
		$left = $node->left->render($this);
		$right = $node->right->render($this);

		// array concat
		if (!empty($node->is_array_concat)) {
			// return sprintf('array_merge(%s, array_values(%s))', $left, $right);
			return sprintf('array_merge(%s, %s)', $left, $right);
		}

		// merge arrays
		if ($node->operator === OperatorFactory::$_merge) {
			// return "$right + $left"; // 可能会有顺序不对的问题
			return "array_replace({$left}, {$right})";
		}

		// for the ?? operation, eg. expr::String ?? none
		if ($node->operator === OperatorFactory::$_none_coalescing && $node->left instanceof CastOperation) {
			$test = $node->left->left->render($this);
			return "isset($test) ? $left : $right";
		}

		if ($this->is_need_parentheses_for_left_of_node($node)) {
			$left = "($left)";
		}

		if ($this->is_need_parentheses_for_right_of_node($node)) {
			$right = "($right)";
		}

		return sprintf('%s %s %s', $left, $node->operator->dist_sign, $right);
	}

	protected function is_need_parentheses_for_left_of_node(BinaryOperation $node)
	{
		$op = $node->operator;
		$left = $node->left;

		if ($left instanceof BaseBinaryOperation) {
			if ($left->operator->dist_precedence > $op->dist_precedence) {
				return true;
			}

			// PHP 的幂运算符是右结合的
			if ($left->operator->dist_precedence === $op->dist_precedence && $op === OperatorFactory::$_exponentiation) {
				return true;
			}
		}
		elseif ($op === OperatorFactory::$_exponentiation && $left instanceof PrefixOperation) {
			// PHP 的幂运算符比一元运算优先级高
			return true;
		}

		return false;
	}

	protected function is_need_parentheses_for_right_of_node(BinaryOperation $node)
	{
		$op = $node->operator;
		$right = $node->right;

		if ($right instanceof BaseBinaryOperation) {
			if ($right->operator->dist_precedence >= $op->dist_precedence) {
				return true;
			}
		}
		elseif ($op === OperatorFactory::$_exponentiation && $right instanceof PrefixOperation) {
			// PHP 的幂运算符比一元运算优先级高
			return true;
		}

		return false;
	}

	protected function render_expression(IExpression $expr)
	{
		if ($expr instanceof BinaryOperation) {
			$need_parentheses = ($expr->left instanceof BinaryOperation) || ($expr->right instanceof BinaryOperation);
		}
		elseif ($expr instanceof ConditionalExpression) {
			$need_parentheses = true;
		}
		else {
			$need_parentheses = false;
		}

		$code = $expr->render($this);
		if ($need_parentheses) {
			$code = '(' . $code . ')';
		}

		return $code;
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
