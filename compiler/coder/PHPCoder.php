<?php
/**
 * This file is part of the Tea programming language project
 * @copyright 	(c)2019 tealang.org
 * For the full copyright and license information, please view the LICENSE file that was distributed with this source code.
 */

namespace Tea;

class PHPCoder extends BaseCoder
{
	const VAR_DECLARE_PREFIX = _DOLLAR;

	const USE_DECLARE_PREFIX = 'use ';

	const NS_SEPARATOR = PHPParser::NS_SEPARATOR;

	const STATEMENT_TERMINATOR = ';';

	const CLASS_MEMBER_TERMINATOR = ';';

	const CLASS_MEMBER_OPERATOR = '::';

	const OBJECT_MEMBER_OPERATOR = '->';

	const DICT_KV_OPERATOR = ' => ';

	const DICT_EMPTY_VALUE = '[]';

	const VAL_NONE = _VAL_NULL;

	const NAMESPACE_REPLACES = [
		_STRIKETHROUGH => _UNDERSCORE,
		_DOT => _UNDERSCORE,
		TeaParser::NS_SEPARATOR => PHPParser::NS_SEPARATOR
	];

	const CASTABLE_TYPES = [_STRING, _INT, _UINT, _FLOAT, _BOOL, _ARRAY, _DICT, _OBJECT];

	const BUILTIN_TYPE_MAP = [
		_VOID => 'void',
		_MIXED => '',
		_ANY => '',
		_NONE => 'null',
		_STRING => 'string',
		_TEXT_TYPE => 'string',
		_PLAIN => 'string',
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
		_TYPE_SELF => 'static',
	];

	const IS_TEST_MAP = [
		_STRING => 'is_string',
		_TEXT_TYPE => 'is_string',
		_PLAIN => 'is_string',
		_INT => 'is_int',
		_UINT => 'is_uint',
		_FLOAT => 'is_float',
		_BOOL => 'is_bool',
		_ARRAY => 'is_array',
		_DICT => 'is_array',
		_CALLABLE => 'is_callable',
		_ITERABLE => 'is_iterable',
		_OBJECT => 'is_object',
		_REGEX => 'is_string',
		_XVIEW => 'is_string',
		_METATYPE => 'is_string'
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
		// _CONSTRUCT => '__construct',
		// _DESTRUCT => '__destruct',
		// 'to_string' => '__toString',
	];

	const PROGRAM_HEADER = '<?php';

	protected $uses = [];

	protected function render_program_statements(Program $program)
	{
		$decls = [];
		foreach ($program->declarations as $node) {
			// the common consts and functions would be render to the loader file
			if (!$node->is_unit_level || $node instanceof ClassKindredDeclaration) {
				$decls[] = $node;
			}
		}

		$this->process_use_statments($decls);

		$items = $this->render_heading_statements($program);

		if ($program->as_main) {
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

		// classes that use traits must be placed at the beginning of the file
		// otherwise the runtime will prompt that they cannot be found
		foreach ($decls as $node) {
			$item = $this->render_node($node);
			$item === null || $items[] = $item . LF;
		}

		if ($program->initializer) {
			$body_items = $this->render_block_nodes($program->initializer->body);
			$items[] = '// ---------';
			$items[] = trim(join($body_items));

			$items[] = '// ---------';
			$items[] = '';
		}

		return $items;
	}

	protected function process_use_statments(array $decls)
	{
		foreach ($decls as $node) {
			$this->collect_use_statements($node);
		}

		$this->program->initializer and $this->collect_use_statements($this->program->initializer);
	}

	protected function collect_use_statements(BaseDeclaration $decl)
	{
		foreach ($decl->uses as $use) {
			// it should be a use statement in __package

			$uri = $use->ns->uri;
			if ($use->target_name) {
				$uri .= '!'; // just to differentiate, avoid conflict with no targets use statements
			}

			// same URI will be merged into one
			if (!isset($this->uses[$uri])) {
				$this->uses[$uri] = new UseStatement($use->ns);
			}

			$this->uses[$uri]->append_target($use);
		}

		foreach ($decl->unknow_identifiers as $identifier) {
			$symbol = $identifier instanceof TypeReference
				? TypeHelper::get_type_symbol($identifier)
				: ASTHelper::get_identifier_symbol($identifier);
			$dependence = $symbol->declaration;
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

	protected function generate_use_targets(array $targets)
	{
		$items = [];
		foreach ($targets as $target) {
			$decl = ASTHelper::get_use_source_declaration($target);
			$source_name = $decl instanceof BaseDeclaration
				? ($decl->origin_name ?? $target->source_name)
				: $target->source_name;
			if ($source_name) {
				if (is_array($source_name)) {
					$source_name = join(static::NS_SEPARATOR, $source_name);
				}

				$item = "$source_name as {$target->target_name}";
			}
			else {
				$item = $target->target_name;
			}

			if ($decl instanceof ClassKindredDeclaration) {
				// no any
			}
			elseif ($decl instanceof FunctionDeclaration) {
				$item = "function $item";
			}
			elseif ($decl instanceof ConstantDeclaration) {
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

	protected function generate_class_bases(ClassKindredDeclaration $node)
	{
		$code = '';
		if ($node->extends) {
			$code = ' extends ' . $this->render_bases_identifiers($node->extends);
		}

		if ($node->implements) {
			$code .= ' implements ' . $this->render_bases_identifiers($node->implements);
		}

		return $code;
	}

	private function render_bases_identifiers(array $identifiers)
	{
		$items = [];
		foreach ($identifiers as $item) {
			$items[] = $this->render_classkindred_identifier($item);
		}

		return join(', ', $items);
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

		// just render return type on declared, otherwise maybe causing error
		if ($node->declared_type !== null && $node->declared_type !== TypeFactory::$_any) {
			$return_type = $this->render_type_expr($node->declared_type);
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
		if ($node->is_extern) {
			return null;
		}

		return parent::render_constant_declaration($node);
	}

	public function render_member_mapping_declaration(MemberMappingDeclaration $node)
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

	public function render_anonymous_function(AnonymousFunction $node)
	{
		$parameters = $this->render_parameters($node->parameters);
		$body = $this->render_function_body($node);

		if ($node->using_params) {
			$uses = $this->render_anonymous_using_parameters($node->using_params);
			$header = sprintf('function (%s) use(%s)', $parameters, $uses);
		}
		else {
			$header = sprintf('function (%s)', $parameters);
		}

		if ($node->is_static) {
			$header = _STATIC . ' ' . $header;
		}

		return $header . ' ' . $body;
	}

	protected function render_anonymous_using_parameters(array $using_params)
	{
		$items = [];
		foreach ($using_params as $param) {
			$item = $this->render_node($param);
			$items[] = $item;
		}

		return join(', ', $items);
	}

	public function render_function_body(IFunctionDeclaration $node): string
	{
		$body = $node->body;
		$items = [];

		if (is_array($body)) {
			$tmp_items = $this->render_block_nodes($body);
			$items = $items ? array_merge($items, $tmp_items) : $tmp_items;
		}
		else {
			// the single expression lambda body
			$items[] = 'return ' . $this->render_node($body) . static::STATEMENT_TERMINATOR;
		}

		return $this->wrap_block_code($items);
	}

	protected function render_function_parameters(Node $node)
	{
		$parameters = $node->parameters ?? [];
		if ($node->callbacks) {
			foreach ($node->callbacks as $cb) {
				$parameters[] = new ParameterDeclaration($cb->name, TypeFactory::$_callable, new LiteralNone());
			}
		}

		return $this->render_parameters($parameters);
	}

	public function render_parameter_declaration(ParameterDeclaration $node)
	{
		$expr = $this->add_variable_prefix($node->name);
		if ($node->is_inout) {
			$expr = '&' . $expr;
		}

		if ($node->declared_type) {
			$type = $this->render_type_expr($node->declared_type);
			if ($type) {
				$expr = "{$type} {$expr}";
			}
		}

		if ($node->value) {
			$expr .= ' = ' . $this->render_node($node->value);
		}

		return $expr;
	}

	public function render_unset_statement(UnsetStatement $node)
	{
		return 'unset(' . $this->render_node($node->argument) . ')' . static::STATEMENT_TERMINATOR;
	}

// ---

	public function render_type_declaration(BuiltinTypeClassDeclaration $node)
	{
		return null;
	}

	public function render_class_declaration(ClassDeclaration|EnumDeclaration $node)
	{
		if ($node->is_extern) {
			return null;
		}

		$items = $this->render_block_nodes($node->members);

		$traits = $this->get_using_trait_name_in_bases($node->implements);
		if ($traits) {
			array_unshift($items, 'use ' . join(', ', $traits) . ";\n\n");
		}

		$code = sprintf("%s%s %s",
			$this->generate_classkindred_header($node, 'class'),
			$this->generate_class_bases($node),
			$this->wrap_block_code($items)
		);

		return $code;
	}

	private function get_using_trait_name_in_bases(array $identifiers)
	{
		$items = [];
		foreach ($identifiers as $identifier) {
			$symbol = $identifier instanceof TypeReference
				? TypeHelper::get_type_symbol($identifier)
				: ASTHelper::get_identifier_symbol($identifier);
			$target_declaration = $symbol->declaration;
			if ($target_declaration instanceof IntertraitDeclaration) {
				$name = $this->render_node($identifier);
				$items[] = $this->get_intertrait_trait_name($name);
			}
		}

		return $items;
	}

	public function render_interface_declaration(InterfaceDeclaration $node)
	{
		if ($node->is_extern) {
			return null;
		}

		$code = sprintf("%s%s %s",
			$this->generate_classkindred_header($node, 'interface'),
			$this->generate_class_bases($node),
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
			$this->generate_class_bases($node),
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

	public static function get_intertrait_trait_name(string $name)
	{
		return $name . 'Trait';
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
		$subject = $this->render_node($node->subject);

		$branches = [];
		foreach ($node->branches as $branch) {
			$branches[] = $this->render_switch_branch($branch);
		}

		if ($node->else) {
			$branches[] = $this->render_else_for_switch_block($node->else);
		}

		$branches = $this->indents(join(LF, $branches));
		$code = "switch ($subject) {\n$branches\n}";

		if ($node->has_exceptional()) {
			$code = $this->wrap_with_except_block($node, $code);
		}

		return $code;
	}

	public function render_match_block(MatchBlock $node)
	{
		$subject = $this->render_node($node->subject);
		$arms = [];
		foreach ($node->arms as $arm) {
			$arms[] = $this->render_match_arm($arm);
		}

		$body = $this->indents(join(",\n", $arms));
		return "match ($subject) {\n$body,\n}";
	}

	public function render_match_arm(MatchArm $node)
	{
		$patterns = [];
		foreach ($node->patterns as $pattern) {
			$patterns[] = $this->render_node($pattern);
		}

		$return = $this->render_node($node->return);
		return join(', ', $patterns) . " => $return";
	}

	public function render_default_pattern(DefaultPattern $node)
	{
		return 'default';
	}

	protected function render_else_for_switch_block(IElseBlock $node)
	{
		if ($node instanceof ElseBlock) {
			$body = $this->render_switch_branch_body($node->body);
		}
		else {
			// that should be ElseIfBlock

			$items = [];
			$items[] = sprintf("if (%s) %s", $this->render_node($node->condition), $this->render_control_structure_body($node));

			if ($node->else) {
				$items[] = $this->render_node($node->else);
			}

			$body = $this->indents(join($items));
		}

		return "default:\n{$body}";
	}

	protected function render_switch_branch(SwitchBranch $node)
	{
		$codes = [];
		foreach ($node->patterns as $pattern) {
			if ($pattern) {
				$expr = $this->render_node($pattern);
				$branch = "case {$expr}:";
			}
			else {
				$branch = 'default:';
			}

			$codes[] = $branch;
		}

		$codes[] = $this->render_switch_branch_body($node->body);

		return join(LF, $codes);
	}

	protected function render_switch_branch_body(array $nodes)
	{
		$items = [];
		$node = null;
		foreach ($nodes as $node) {
			$item = $this->render_node($node);
			$items[] = $item === LF ? $item : $item . LF;
		}

		if (empty($nodes) || !$node instanceof BreakStatement) {
			$items[] = 'break' . static::STATEMENT_TERMINATOR;
		}

		return $this->indents(join($items));
	}

	public function render_forin_block(ForInBlock $node)
	{
		$iterable = $this->render_node($node->iterable);

		$temp_assignment = null;
		if ($node->else) {
			// create temp assignment to avoid duplicate computation
			if (!$node->iterable instanceof PlainIdentifier && !$node->iterable->is_const_value) {
				$temp_name = $this->generate_temp_variable_name();
				$temp_assignment = "$temp_name = $iterable;\n";
				$iterable = $temp_name;
			}
		}

		$code = $this->build_foreach_statement($node, $iterable);

		if ($node->else) {
				$code = $this->indents($code);
				$code = "{$temp_assignment}if ($iterable && count($iterable) > 0) {\n$code\n}";
				$code .= $this->render_node($node->else);
			}

		if ($node->has_exceptional()) {
			$code = $this->wrap_with_except_block($node, $code);
		}

		return $code;
	}

	public function render_forto_block(ForToBlock $node)
	{
		$start = $this->render_node($node->start);
		$end = $this->render_node($node->end);

		$code = '';
		if (!$node->start->is_const_value && !$node->start instanceof PlainIdentifier) {
			$temp_name = $this->generate_temp_variable_name();
			$code .= "$temp_name = $start;\n";
			$start = $temp_name;
		}

		if (!$node->end->is_const_value && !$node->end instanceof PlainIdentifier) {
			$temp_name = $this->generate_temp_variable_name();
			$code .= "$temp_name = $end;\n";
			$end = $temp_name;
		}

		$step = $node->step ?? 1;

		if ($node->is_downto_mode) {
			$iterable = "\\range($start, $end, -$step)";
		}
		elseif ($step === 1) {
			$iterable = "\\range($start, $end)";
		}
		else {
			$iterable = "\\range($start, $end, $step)";
		}

		$for_code = $this->build_foreach_statement($node, $iterable);

		if ($node->else) {
			$for_code = $this->indents($for_code);
				$op = $node->is_downto_mode ? '>=' : '<=';
				$code .= "if ($start $op $end) {\n$for_code\n}";
				$code .= $this->render_node($node->else);
			}
		else {
			$code .= $for_code;
		}

		if ($node->has_exceptional()) {
			$code = $this->wrap_with_except_block($node, $code);
		}

		return $code;
	}

	protected function generate_temp_variable_name()
	{
		return _DOLLAR . '__tmp' . $this->temp_name_index++;
	}

	private function build_foreach_statement(BaseControlBlock $node, string $iterable)
	{
		$val = $this->render_node($node->val);
		$body = $this->render_control_structure_body($node);

		if ($node->key) {
			$key = $this->render_node($node->key);
			$code = sprintf('foreach (%s as %s => %s) %s', $iterable, $key, $val, $body);
		}
		else {
			$code = sprintf('foreach (%s as %s) %s', $iterable, $val, $body);
		}

		return $code;
	}

	public function render_while_block(WhileBlock $node)
	{
		$test = $this->render_node($node->condition);
		$body = $this->render_control_structure_body($node);

		// if ($node->do_the_first) {
		// 	$code = sprintf('do %s while (%s);', $body, $test);
		// }
		// else {
			$code = sprintf('while (%s) %s', $test, $body);
		// }

		if ($node->has_exceptional()) {
			$code = $this->wrap_with_except_block($node, $code);
		}

		return $code;
	}

	public function render_loop_block(LoopBlock $node)
	{
		$body = $this->render_control_structure_body($node);
		$code = sprintf('while (true) %s', $body);

		if ($node->has_exceptional()) {
			$code = $this->wrap_with_except_block($node, $code);
		}

		return $code;
	}

	public function render_try_block(TryBlock $node)
	{
		$items = [];
		$code = $this->render_control_structure_body($node);
		$items[] = "try {$code}";

		foreach ($node->catchings as $block) {
			$items[] = $this->render_catch_block($block);
		}

		if ($node->finally) {
			$items[] = $this->render_finally_block($node->finally);
		}

		return join("\n", $items);
	}

// ---

	// public function render_array_element_assignment(ArrayElementAssignment $node)
	// {
	// 	$basing = $node->basing;
	// 	if ($basing instanceof AsOperation) {
	// 		$basing = $basing->left;
	// 	}

	// 	$basing = $basing->render($this);
	// 	$key = $node->key ? $node->key->render($this) : '';
	// 	$value = $node->value->render($this);

	// 	return "{$basing}[{$key}] = {$value}" . static::STATEMENT_TERMINATOR;
	// }

	public function render_assignment_operation(AssignmentOperation $node)
	{
		$left = $node->left;
		$right = $this->render_node($node->right);

		if ($left instanceof SquareAccessing) {
			$expr = $this->render_node($left->basing);
			if ($left->is_prefix) {
				return "array_unshift({$expr}, {$right})";
			}

			$left =  "{$expr}[]";
		}
		else {
			$left = $this->render_node($left);
		}

		$op = $this->get_operator_sign($node->operator);

		return sprintf('%s %s %s', $left, $op, $right);
	}

	public function render_xtag(XTag $node)
	{
		if ($node->name) {
			$code = parent::render_xtag($node);
		}
		elseif ($node->children) {
			$items = [];
			$subitems = $this->get_children_components($node->children);
			$this->merge_xtag_components($items, $subitems);
			$code = $this->render_xtag_components($items);

			// strip intent spaces
			if ($node->inner_br) {
				$code = preg_replace('/\n(\t| {4})/', "\n", $code);
				$code = '\'' . trim(substr($code, 1, -1)) . '\'';
			}
		}
		else {
			$code = "''";
		}

		return $this->new_string_placeholder($code);
	}

	protected function render_xtag_components(array $items)
	{
		foreach ($items as $k => $item) {
			if ($item instanceof XTagAttrInterpolation) {
				$expr = $this->render_xtag_attr_interpolation($item);
			}
			elseif ($item instanceof XTagChildInterpolation) {
				$expr = $this->render_xtag_child_interpolation($item);
			}
			elseif ($item instanceof BaseExpression) {
				$expr = $this->render_subexpression($item, OperatorFactory::$concat);
			}
			else {
				if (strpos($item, _SINGLE_QUOTE) !== false) {
					$item = $this->add_escape_slashs($item, _SINGLE_QUOTE);
				}

				$expr = "'$item'";
			}

			$items[$k] = $expr;
		}

		$code = join(' . ', $items);

		if (strpos($code, "\t\n")) {
			$code = preg_replace('/\t+\n/', '', $code);
		}

		return $code;
	}

	public function render_xtag_attr_interpolation(XTagAttrInterpolation $node)
	{
		$expr = $node->content;
		$code = OutputSafety::can_skip_html_escape($expr, OutputSafety::HTML_ATTRIBUTE)
			? $this->render_subexpression($expr, OperatorFactory::$concat)
			: $this->render_expression_with_html_escaping($expr);
		return $code;
	}

	public function render_xtag_child_interpolation(XTagChildInterpolation $node)
	{
		$expr = $node->content;
		$type = ASTHelper::get_expressed_type($expr);

		if (OutputSafety::can_skip_html_escape($expr, OutputSafety::HTML_TEXT)) {
			$code = $this->render_subexpression($expr, OperatorFactory::$concat);
		}
		elseif (TypeHelper::is_simple_xtag_safe_value_type($type)) {
			$code = $this->render_subexpression($expr, OperatorFactory::$concat);
		}
		elseif ($type instanceof IterableType and TypeHelper::is_simple_xtag_safe_value_type($type->generic_type)) {
			if (TypeHelper::is_nullable_type($type)) {
				$expr = new NoneCoalescingOperation($expr, new ArrayExpression());
			}

			$expr = $this->create_native_call('\implode', [$this->get_br_string_expr(), $expr]);
			$code = $this->render_subexpression($expr, OperatorFactory::$concat);
		}
		else {
			$code = $this->render_expression_with_html_escaping($expr);
		}

		return $code;
	}

	private function get_br_string_expr()
	{
		static $it;
		if ($it === null) {
			$it = new EscapedLiteralString('\n');
		}

		return $it;
	}

	private function render_expression_with_html_escaping(BaseExpression $expr)
	{
		if ($expr instanceof TernaryExpression && $expr->then !== null) {
			$condition = $this->render_node($expr->condition);
			$then = $this->render_xtag_text_expression($expr->then);
			$else = $this->render_xtag_text_falsy_branch($expr);
			return "({$condition} ? {$then} : {$else})";
		}

		$code = $this->render_node($expr);
		$fn = TypeHelper::is_nullable_type(ASTHelper::get_expressed_type($expr))
			? '\html_escape'
			: '\htmlspecialchars';
		return "{$fn}({$code})";
	}

	private function render_xtag_text_expression(BaseExpression $expr): string
	{
		return OutputSafety::can_skip_html_escape($expr, OutputSafety::HTML_TEXT)
			? $this->render_subexpression($expr, OperatorFactory::$concat)
			: $this->render_expression_with_html_escaping($expr);
	}

	private function render_xtag_text_falsy_branch(TernaryExpression $expr): string
	{
		if ($this->is_same_falsy_branch_expression($expr->condition, $expr->else)) {
			return $this->render_subexpression($expr->else, OperatorFactory::$concat);
		}

		return $this->render_xtag_text_expression($expr->else);
	}

	private function is_same_falsy_branch_expression(BaseExpression $condition, BaseExpression $branch): bool
	{
		$condition = $this->unwrap_falsy_branch_expression($condition);
		$branch = $this->unwrap_falsy_branch_expression($branch);

		return $this->render_node($condition) === $this->render_node($branch);
	}

	private function unwrap_falsy_branch_expression(BaseExpression $expr): BaseExpression
	{
		while ($expr instanceof Parentheses || $expr instanceof AsOperation) {
			$expr = $expr instanceof Parentheses ? $expr->expression : $expr->left;
		}

		return $expr;
	}

	protected function build_xtag_attribute_components(XTag $node)
	{
		$fixed_map = $node->fixed_attributes;
		$dynamic_expr = $node->dynamic_attributes;

		$items = [];
		if ($dynamic_expr) {
			// when setted dynamic expression, required rendering on runtime
			foreach ($fixed_map as $key => $item) {
				if ($item instanceof XTagAttrInterpolation) {
					$fixed_map[$key] = $item->content;
				}
			}

			$items[] = ' ';
			$items[] = $this->create_building_attributes_expression($fixed_map, $dynamic_expr);
		}
		elseif ($fixed_map) {
			$items = $this->build_xtag_attribute_components_for_fixed($fixed_map);
		}

		return $items;
	}

	private function build_xtag_attribute_components_for_fixed(array $fixed_map)
	{
		$items = [];
		$instable_map = []; // items that maybe null/false
		foreach ($fixed_map as $key => $val) {
			// true value attribute
			if ($val === true) {
				$items[] = ' ' . $key;
				continue;
			}

			// normal header
			$items[] = ' ' . $key . '="';

			// normal value
			if ($val->is_const_value) {
				// static
				$items[] = $val->value;
			}
			elseif ($val instanceof XTagAttrInterpolation) {
				$content = $val->content;
				if ($content instanceof InterpolatedString) {
					// not empty
					if ($this->is_safe_xtag_interpolated($content)) {
						// no need to escaping
						$items = array_merge($items, $content->items);
					}
					else {
						// need to escaping
						$items[] = $val;
					}
				}
				else {
					$type = ASTHelper::get_expressed_type($content);
					if ($this->is_instable_xtag_attribute_value($content, $type)) {
						// the null or false value cannot be presented
						$instable_map[$key] = $content;
						array_pop($items);
						continue;
					}
					else {
						$items[] = $val;
					}
				}
			}
			else {
				throw new Exception("Invalid xtag attribute value");
			}

			$items[] = '"';
		}

		if ($instable_map) {
			$items[] = ' ';
			$items[] = $this->create_building_attributes_expression($instable_map);
		}

		return $items;
	}

	private function is_instable_xtag_attribute_value(BaseExpression $expr, BaseType $type): bool
	{
		if (TypeHelper::is_nullable_type($type) || $type instanceof BoolType) {
			return true;
		}

		return $this->should_use_nullable_xtag_escaping($expr);
	}

	private function should_use_nullable_xtag_escaping(BaseExpression $expr): bool
	{
		if (TypeHelper::is_nullable_type(ASTHelper::get_expressed_type($expr))) {
			return true;
		}

		$decl_type = $this->get_identifiable_original_type($expr);
		return $decl_type instanceof BaseType && TypeHelper::is_nullable_type($decl_type);
	}

	private function get_identifiable_original_type(BaseExpression $expr): ?BaseType
	{
		if (!$expr instanceof Identifiable) {
			return null;
		}

		$decl = ASTHelper::get_identifier_symbol($expr)->declaration ?? null;
		if (!$decl instanceof IVariableDeclaration) {
			return null;
		}

		return ASTHelper::get_noted_type($decl)
			?? $decl->declared_type
			?? $decl->infered_type
			?? ($decl->value !== null ? ASTHelper::get_expressed_type($decl->value) : null);
	}

	private function create_building_attributes_expression(array $fixed_map, ?BaseExpression $dynamic_expr = null)
	{
		$args = [];
		if ($fixed_map) {
			$args[] = $this->create_dict_expression_for_map($fixed_map);
		}

		if ($dynamic_expr) {
			$args[] = $dynamic_expr;
		}

		return $this->create_native_call('\_build_attributes', $args);
	}

	private function create_native_call(string $fn, array $args)
	{
		$callee = new NativeIdentifier($fn);
		return new CallExpression($callee, $args);
	}

	private function create_dict_expression_for_map(array $map)
	{
		$members = [];
		foreach ($map as $name => $val_exp) {
			$key_exp = new PlainLiteralString($name);
			$members[] = new DictMember($key_exp, $val_exp);
		}

		return new DictExpression($members);
	}

	private function is_safe_xtag_interpolated(InterpolatedString $node) {
		$safe = true;
		foreach ($node->items as $item) {
			if ($item instanceof StringInterpolation
				&& !OutputSafety::can_skip_html_escape($item->content, OutputSafety::HTML_ATTRIBUTE)) {
				$safe = false;
				break;
			}
		}

		return $safe;
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

	protected function get_normalized_method_name(string $name)
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

	private function get_normalized_name_with_declaration(BaseDeclaration $node)
	{
		$name = $node->origin_name ?? $node->name;
		return $node->is_extern ? $name : $this->get_normalized_name($name);
	}

	public function render_accessing_identifier(AccessingIdentifier $node)
	{
		$decl = ASTHelper::get_identifier_symbol($node)->declaration;
		if ($decl instanceof MemberMappingDeclaration) {
			return $this->render_member_mapping_accessing_identifier($node);
		}

		$name = $decl->name;
		$is_property = $decl instanceof PropertyDeclaration;
		if ($is_property) {
			//
		}
		if ($decl instanceof MethodDeclaration) {
			$name = $this->get_normalized_method_name($name);
		}
		elseif ($decl instanceof ClassConstantDeclaration) {
			$name = $this->get_normalized_name($name);
		}

		if ($node->basing instanceof CallExpression && $this->is_instancing_call($node->basing)) {
			// for the class new expression
			$basing = $this->render_node($node->basing);
			$basing = "($basing)";
		}
		else {
			$basing = $this->render_basing_expression($node->basing);
		}
		// elseif ($node->basing instanceof Identifiable && $node->basing->symbol->declaration instanceof NamespaceDeclaration) {
		// 	// namespace accessing
		// 	// class/function/const
		// 	return $basing . static::NS_SEPARATOR . $name;
		// }

		if ($decl->is_static) {
			// static accessing

			// cannot use '$this' or 'static' for private member, it will be cause syntax error
			if ($decl->modifier === _PRIVATE) {
				$basing = 'self';
			}
			elseif ($basing === _THIS) {
				// $basing_declaration = $node->basing->symbol->declaration;
				// if ($basing_declaration->is_root_namespace()) {
				// 	$basing = $this->get_identifier_name_for_root_namespace_declaration($basing_declaration);
				// }
				// else {
				// 	$basing = $this->get_normalized_name($basing_declaration->name);
				// }

				$basing = 'static';
			}

			if ($is_property) {
				$name = $this->add_variable_prefix($name);
			}

			$operator = static::CLASS_MEMBER_OPERATOR;
		}
		elseif ($basing === '$super') {
			if ($is_property) {
				$name = $this->add_variable_prefix($name);
			}

			$operator = static::CLASS_MEMBER_OPERATOR;
			$basing = 'parent';
		}
		else {
			// object accessing
			$operator = static::OBJECT_MEMBER_OPERATOR;
		}

		return $basing . $operator . $name;
	}

	protected function render_member_mapping_accessing_identifier(AccessingIdentifier $node)
	{
		$decl = ASTHelper::get_identifier_symbol($node)->declaration;
		$mapping_body = $decl->body;

		if ($mapping_body instanceof CallExpression) {
			$actual_arguments = [];
			foreach ($decl->arguments_map as $idx) {
				// assert($idx === 0);
				$actual_arguments[] = $node->basing;
			}

			$actual_call = clone $mapping_body;
			$actual_call->arguments = $actual_arguments;
			$actual_call->callee->pos = $node->pos; // just for debug

			return $this->render_basecall_expression($actual_call);
		}
		elseif ($mapping_body instanceof PlainIdentifier) {
			if ($mapping_body->name === _THIS) {
				return $this->render_node($node->basing);
			}
			else {
				return $this->render_node($mapping_body);
			}
		}
		elseif ($mapping_body->is_const_value) {
			return $this->render_node($mapping_body);
		}
		else {
			throw new Exception("Unknow member mapping body.", $decl);
		}
	}

	protected function render_member_mapping_call(CallExpression $node)
	{
		$decl = ASTHelper::get_identifier_symbol($node->callee)->declaration;
		$mapping_body = $decl->body;

		$source_arguments = ASTHelper::get_normalized_arguments($node) ?? $node->arguments;

		$actual_arguments = [];
		foreach ($decl->arguments_map as $dest_idx => $src) {
			// an expression, but not an argument
			if (!is_int($src)) {
				$actual_arguments[] = $src;
				continue;
			}

			// the 'this'
			if ($src === 0) {
				$actual_arguments[] = $node->callee->basing;
				continue;
			}

			// because offset 0 in arguments_map is 'this'
			$actual_index = $src - 1;
			if (isset($source_arguments[$actual_index])) {
				$arg_value = $source_arguments[$actual_index];
			}
			elseif (isset($decl->parameters[$actual_index]->value)) {
				$arg_value = $decl->parameters[$actual_index]->value;
			}
			else {
				throw new Exception("Unexpected render error for member mapping call '{$node->callee->name}'.");
			}

			if ($arg_value === ASTFactory::$default_value_mark) {
				// is should be the last real argument, so we check it is correct
				if (count($decl->arguments_map) !== count($actual_arguments) + 1) {
					throw new Exception("Unexpected arguments error for member mapping call '{$node->callee->name}'.");
				}
			}
			else {
				$actual_arguments[] = $arg_value;
			}
		}

		$actual_call = clone $mapping_body;
		$actual_call->arguments = $actual_arguments;
		$actual_call->callee->pos = $node->callee->pos; // just for debug

		return $this->render_basecall_expression($actual_call);
	}

	public function render_call_expression(CallExpression $node)
	{
		return $this->render_basecall_expression($node);
	}

	public function render_new_expression(InstancingExpression $node)
	{
		return $this->render_basecall_expression($node);
	}

	public function render_first_class_callable_expression(FirstClassCallableExpression $node)
	{
		$callee_code = $this->render_basing_expression($node->callee);
		return "{$callee_code}(...)";
	}

	public function render_pipecall_expression(PipeCallExpression $node)
	{
		return $this->render_basecall_expression($node);
	}

	public function render_basecall_expression(BaseCallExpression $node)
	{
		if (ASTHelper::get_callee_declaration($node) instanceof MemberMappingDeclaration) {
			return $this->render_member_mapping_call($node);
		}

		$callee = $node->callee;
		$callee_code = $this->render_basing_expression($callee);

		// object member as callee, must be got it's result, then handling call
		if ($callee instanceof AccessingIdentifier
			and !(ASTHelper::get_identifier_symbol($callee)->declaration instanceof ICallableDeclaration)
			// and $callee->symbol->declaration !== ASTFactory::$virtual_property_for_any
		) {
			$callee_code = "($callee_code)";
		}

		$arguments = ASTHelper::get_normalized_arguments($node) ?? $node->arguments;
		$arguments_code = $this->render_arguments($arguments);
		
		// Add named arguments (PHP 8.0+)
		$named_args_code = '';
		if ($node->named_arguments) {
			$named_items = [];
			foreach ($node->named_arguments as $named_arg) {
				$named_items[] = $named_arg->name . ': ' . $this->render_node($named_arg->value);
			}
			$named_args_code = implode(', ', $named_items);
		}
		
		// Combine positional and named arguments
		$all_args = [];
		if ($arguments_code) {
			$all_args[] = $arguments_code;
		}
		if ($named_args_code) {
			$all_args[] = $named_args_code;
		}
		$args_string = implode(', ', $all_args);

		if ($this->is_instancing_call($node)) {
			$code = "new {$callee_code}($args_string)";
		}
		else {
			$code = "{$callee_code}($args_string)";
		}

		return $code;
	}

	private function is_instancing_call(BaseCallExpression $node): bool
	{
		return $node instanceof InstancingExpression
			|| ASTHelper::get_callee_declaration($node) instanceof ClassKindredDeclaration;
	}

	protected function render_arguments(array $nodes)
	{
		if (!$nodes) return '';

		$items = [];
		foreach ($nodes as $arg) {
			if ($arg) {
				$item = $this->render_node($arg);
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
			$basing = $this->render_node($arg->basing);

			// format for call_use_func
			return "[$basing, '{$arg->name}']";
		}

		return $this->render_node($arg);
	}

	public function render_named_argument(NamedArgument $node)
	{
		return $node->name . ': ' . $this->render_node($node->value);
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

	public function render_plain_identifier(PlainIdentifier $node): string
	{
		$decl = ASTHelper::get_identifier_symbol($node)->declaration;

		// variable
		if ($decl instanceof IVariableDeclaration) {
			return $this->add_variable_prefix($node->name);
		}

		if ($decl instanceof ClassKindredDeclaration) {
			$name = $this->get_classkindred_identifier_name($node);
		}
		else {
			// function/constant
			$name = $this->get_normalized_name_with_declaration($decl);
		}

		if (!$node->is_accessing_or_invoking()) {
			if ($decl instanceof FunctionDeclaration) {
				$uri = ltrim($decl->program->unit->dist_ns_uri, static::NS_SEPARATOR);
				$name = sprintf("'%s%s%s'", $uri, static::NS_SEPARATOR, $name);
			}
			elseif ($decl instanceof BuiltinTypeClassDeclaration) {
				$name = "'{$decl->name}'";
			}
			elseif ($decl instanceof ClassKindredDeclaration) {
				$name .= '::class';
			}
		}

		return $name;
	}

	public function render_native_identifier(NativeIdentifier $node)
	{
		return $node->name;
	}

	public function render_type_expr(BaseType $node)
	{
		$code = $this->render_node($node);

		// if ($code && ($node->nullable || $node->has_null)) {
		// 	$code = '?' . $code;
		// }

		return $code;
	}

	public function render_type_identifier(BaseType $node)
	{
		if ($node instanceof InvalidableType) {
			return $this->render_invalidable_type_identifier($node);
		}
		if ($node instanceof ExcludableType) {
			return $this->render_node($node->base_type);
		}

		return static::BUILTIN_TYPE_MAP[$node->name] ?? $node->name;
	}

	protected function render_invalidable_type_identifier(InvalidableType $node): string
	{
		if ($node->sentinel instanceof LiteralNone) {
			$valid_code = $this->render_node($node->valid_type);
			if ($valid_code === '') {
				return '';
			}

			if ($node->valid_type instanceof UnionType) {
				return $valid_code . '|null';
			}

			return _QUESTION . $valid_code;
		}

		if ($node->sentinel instanceof LiteralBoolean
			&& ($node->sentinel->value === false || $node->sentinel->value === '' || $node->sentinel->value === '0')) {
			$valid_code = $this->render_node($node->valid_type);
			return $valid_code === '' ? '' : $valid_code . '|false';
		}

		return '';
	}

	protected function add_short_nullable_sign(string $expr)
	{
		return _QUESTION . $expr;
	}

	public function render_classkindred_identifier(ClassKindredIdentifier|TypeReference $node)
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

	private function get_classkindred_identifier_name(PlainIdentifier|TypeReference $node)
	{
		$decl = $node instanceof TypeReference
			? TypeHelper::get_type_symbol($node)->declaration
			: ASTHelper::get_identifier_symbol($node)->declaration;
		if ($decl->is_root_namespace()) {
			$name = $this->get_identifier_name_for_root_namespace_declaration($decl);
		}
		else {
			$name = $this->get_normalized_name($node->name);
		}

		return $name;
	}

	private function get_identifier_name_for_root_namespace_declaration(ClassKindredDeclaration $decl)
	{
		$name = $decl->name;
		if ($decl->origin_name !== null) {
			$name = $decl->origin_name;
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
				$value = $this->render_node($member->value);
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
			$code .= ' = ' . $this->render_node($node->value);
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

	private function render_basing_expression(BaseExpression $expr)
	{
		if ($expr instanceof AsOperation) {
			$code = $this->render_as_operation($expr, true);
		}
		else {
			$code = $this->render_node($expr);
		}

		return $code;
	}

	public function render_square_accessing(SquareAccessing $node)
	{
		$basing = $this->render_basing_expression($node->basing);

		if ($node->is_prefix) {
			$code = "array_shift({$basing})";
		}
		else {
			$code = "array_pop({$basing})";
		}

		return $code;
	}

	public function render_key_accessing(KeyAccessing $node)
	{
		$basing = $this->render_basing_expression($node->basing);

		if ($node->key === null) {
			return "{$basing}[]";
		}

		$key = $this->render_node($node->key);

		// the auto-cast type to String
		// if (!TypeHelper::is_dict_key_type($node->key->expressed_type)) {
		// 	// Cast others to string, and bool to ''
		// 	// Avoid of float/bool being cast to integers
		// 	$key = '(string)' . $key;
		// }

		return "{$basing}[{$key}]";
	}

	public function render_literal_integer(LiteralInteger $node)
	{
		$num = $this->remove_number_underline($node->value);
		if (strpos($num, 'o')) {
			$num = str_replace('o', '', $num);
		}

		return $num;
	}

	public function render_literal_float(LiteralFloat $node)
	{
		return $this->remove_number_underline($node->value);
	}

	protected function remove_number_underline(string $num)
	{
		return strpos($num, _UNDERSCORE) ? str_replace(_UNDERSCORE, _NOTHING, $num) : $num;
	}

	public function render_plain_literal_string(PlainLiteralString $node)
	{
		$code = "'$node->value'";
		return $this->new_string_placeholder($code);
	}

	public function render_escaped_literal_string(EscapedLiteralString $node)
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

	public function render_plain_interpolated_string(PlainInterpolatedString $node)
	{
		$pieces = [];
		foreach ($node->items as $item) {
			if ($item instanceof StringInterpolation) {
				$item = $this->render_string_interpolation($item);
			}
			else {
				$item = "'$item'";
			}

			$pieces[] = $item;
		}

		$code = join(' . ', $pieces);
		return $this->new_string_placeholder($code);
	}

	public function render_escaped_interpolated_string(EscapedInterpolatedString $node)
	{
		$items = $node->items;
		if (count($items) === 1 and $items[0] instanceof StringInterpolation) {
			return $this->render_node($items[0]);
		}

		$parts = ['"'];
		foreach ($items as $item) {
				if ($item instanceof StringInterpolation) {
					if ($this->is_simple_expression($item->content)) {
						$expr = $this->render_node($item->content);
						$parts[] = "{{$expr}}";
					}
				else {
					$expr = $this->render_string_interpolation($item);
					if (count($parts) === 1) {
						$parts = [$expr . ' . "'];
					}
					else {
						$parts[] = '" . ' . $expr;
						$parts[] = ' . "';
					}
				}
			}
			else {
				$parts[] = $item;
			}
		}

		if (end($parts) === ' . "') {
			array_pop($parts);
		}
		else {
			$parts[] = '"';
		}

		$code = join($parts);
		return $this->new_string_placeholder($code);
	}

	private function is_simple_expression(BaseExpression $item)
	{
		if ($item instanceof PlainIdentifier) {
			if (ASTHelper::get_identifier_symbol($item)->declaration instanceof IVariableDeclaration) {
				return true;
			}
		}
		elseif ($item instanceof KeyAccessing) {
			return true;
		}
		elseif ($item instanceof AccessingIdentifier) {
			$decl = ASTHelper::get_identifier_symbol($item)->declaration;
			if ($decl instanceof PropertyDeclaration and !$decl->is_static) {
				return true;
			}
		}

		return false;
	}

	public function render_string_interpolation(StringInterpolation $node)
	{
		$expr = $node->content;
		$code = $this->render_subexpression($expr, OperatorFactory::$concat);
		return $code;
	}

	// public function render_object_expression(BaseExpression $node)
	// {
	// 	$members = $node->symbol->declaration->members;

	// 	$items = [];
	// 	foreach ($members as $subnode) {
	// 		$items[] = $subnode->render($this);
	// 		if ($subnode->value instanceof AnonymousFunction) {
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

	public function render_as_operation(AsOperation $node, bool $add_parentheses = false)
	{
		$tea_type_name = $node->right->name;
		if (in_array($tea_type_name, static::CASTABLE_TYPES, true)
			and $this->is_need_casting($node)) {
			$native_type_name = static::BUILTIN_TYPE_MAP[$tea_type_name];
			$left = $this->render_subexpression($node->left, $node->operator);
			if ($tea_type_name === _UINT) {
				$code = "uint_ensure((int)$left)";
			}
			elseif ($add_parentheses) {
				$code = "(($native_type_name)$left)";
			}
			else {
				$code = "($native_type_name)$left";
			}
		}
		else {
			$code = $this->render_node($node->left); // not to do anything for non-castable
		}

		return $code;
	}

	private function is_need_casting(AsOperation $node)
	{
		return !($node->right instanceof IterableType
			and ASTHelper::get_expressed_type($node->left) instanceof IterableType);
	}

	public function render_cast_operation(CastOperation $node, bool $add_parentheses = false): string
	{
		$tea_type_name = $node->right->name;
		if (!isset(static::BUILTIN_TYPE_MAP[$tea_type_name])) {
			return $this->render_node($node->left);
		}

		$native_type_name = static::BUILTIN_TYPE_MAP[$tea_type_name];
		$left = $this->render_subexpression($node->left, $node->operator);
		if ($tea_type_name === _UINT) {
			return "uint_ensure((int)$left)";
		}

		return $add_parentheses
			? "(($native_type_name)$left)"
			: "($native_type_name)$left";
	}

	public function render_is_operation(IsOperation $node)
	{
		$left = $this->render_subexpression($node->left, $node->operator);
		if ($node->right instanceof InvalidType) {
			$left_type = ASTHelper::get_expressed_type($node->left);
			if (!$left_type instanceof InvalidableType) {
				throw new Exception("Invalid marker requires Invalidable left type");
			}

			$operator = $node->not ? '!==' : '===';
			return "{$left} {$operator} " . $this->render_node($left_type->sentinel);
		}

		$func_name = $node->right instanceof BaseType
			? (static::IS_TEST_MAP[$node->right->name] ?? null)
			: null;

		if ($func_name) {
			$code = "{$func_name}($left)";
			if ($node->not) {
				$code = '!' . $code;
			}
		}
		elseif ($node->right instanceof NoneType) {
			$operator = $node->not ? '!==' : '===';
			$code = "{$left} $operator null";
		}
		else {
			$right = $node->right instanceof BaseType
				? $this->get_classkindred_identifier_name($node->right)
				: $this->render_node($node->right);
			$code = "{$left} instanceof {$right}";
			if ($node->not) {
				$code = '!' . $code;
			}
		}

		return $code;
	}

	public function render_prefix_operation(BaseExpression $node)
	{
		$operator = $node->operator;
		$expression = $node->expression;

		$expr_code = $this->render_node($expression);
		if ($this->is_need_parentheses_for_operation_item($expression, $operator, true)) {
			$expr_code = "($expr_code)";
		}

		$oper = $this->get_operator_sign($operator);
		if (!in_array($oper, self::NOSPACE_PREFIX_OPERATORS, true)) {
			$oper .= ' ';
		}

		return $oper . $expr_code;
	}

	public function render_binary_operation(BinaryOperation $node)
	{
		$operator = $node->operator;
		$left = $this->render_node($node->left);
		$right = $this->render_node($node->right);

		if ($operator->is(OPID::ARRAY_CONCAT)) {
			// concat Arrays
			// $code = sprintf('\array_merge(%s, array_values(%s))', $left, $right);
			$code = sprintf('\array_merge(%s, %s)', $left, $right);
		}
		elseif ($operator->is(OPID::REPEAT)) {
			$code = sprintf('\str_repeat(%s, %s)', $left, $right);
		}
		// elseif ($operator->is(OPID::MERGE)) {
		// 	// merge Dicts
		// 	$code = sprintf('\array_merge(%s, %s)', $left, $right);
		// }
		// elseif ($operator->is(OPID::REMAINDER) && $node->expressed_type === TypeFactory::$_float) {
		// 	// use the 'fmod' function for the float arguments
		// 	$code = sprintf('fmod(%s, %s)', $left, $right);
		// }
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
			$curr_operator = $expr->operator;
			if ($expr instanceof IsOperation) {
				// use the actual operators
				if ($expr->right instanceof NoneType) {
					$curr_operator = OperatorFactory::$identical;
				}
				elseif ($expr->not) {
					$curr_operator = OperatorFactory::$bool_not;
				}
			}

			$prev_prec = $prev_operator->php_prec;
			$curr_prec = $curr_operator->php_prec;
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
		$code = $this->render_node($expr);
		if ($expr instanceof MultiOperation
			&& $expr->operator->php_prec >= $operator->php_prec) {
			$code = '(' . $code . ')';
		}

		return $code;
	}

	private function render_casted_access_for_none_coalescing(BinaryOperation $node): ?array
	{
		if (!$node->left instanceof KeyAccessing) {
			return null;
		}

		$cached_keys = [];
		$test_code = $this->render_key_accessing_for_isset_with_cached_keys($node->left, $cached_keys);
		if (!$cached_keys) {
			return null;
		}

		$value_expr = clone $node;
		$value_expr->left = $this->clone_key_accessing_with_cached_keys($node->left, $cached_keys);

		return [$test_code, $this->render_node($value_expr)];
	}

	private function render_key_accessing_for_isset_with_cached_keys(KeyAccessing $node, array &$cached_keys): string
	{
		$basing = $this->render_basing_for_cached_key_isset($node->basing, $cached_keys);

		if ($node->key === null) {
			return "{$basing}[]";
		}

		$key_code = $this->render_key_for_cached_key_isset($node, $cached_keys);
		return "{$basing}[{$key_code}]";
	}

	private function render_basing_for_cached_key_isset(BaseExpression $node, array &$cached_keys): string
	{
		if ($node instanceof KeyAccessing) {
			return $this->render_key_accessing_for_isset_with_cached_keys($node, $cached_keys);
		}

		return $this->render_basing_expression($node);
	}

	private function render_key_for_cached_key_isset(KeyAccessing $node, array &$cached_keys): string
	{
		if (!$this->should_cache_none_coalescing_key($node->key)) {
			return $this->render_node($node->key);
		}

		$temp_name = $this->generate_temp_variable_name();
		$cached_keys[spl_object_id($node)] = $temp_name;

		return $temp_name . ' = ' . $this->render_node($node->key);
	}

	private function should_cache_none_coalescing_key(BaseExpression $key): bool
	{
		return $key->is_const_value !== true && !$key instanceof Identifiable;
	}

	private function clone_key_accessing_with_cached_keys(KeyAccessing $node, array $cached_keys): KeyAccessing
	{
		$clone = clone $node;
		if ($clone->basing instanceof KeyAccessing) {
			$clone->basing = $this->clone_key_accessing_with_cached_keys($clone->basing, $cached_keys);
		}

		$temp_name = $cached_keys[spl_object_id($node)] ?? null;
		if ($temp_name !== null) {
			$clone->key = new NativeIdentifier($temp_name);
		}

		return $clone;
	}

	public function render_none_coalescing_operation(NoneCoalescingOperation $node)
	{
		$right_expr = $node->right;
		$right_code = $this->render_node($right_expr);

		if ($right_expr instanceof NoneCoalescingOperation) {
			if ($right_expr->left instanceof AsOperation) {
				$right_code = "($right_code)";
			}
			elseif ($right_expr->left instanceof CastOperation) {
				$right_code = "($right_code)";
			}
		}

		$left_expr = $node->left;
		if ($left_expr instanceof AsOperation) {
			$cached_access = $this->render_casted_access_for_none_coalescing($left_expr);
			if ($cached_access) {
				[$test, $left_code] = $cached_access;
			}
			else {
				$left_code = $this->render_node($left_expr);
				$test = $this->render_node($left_expr->left);
			}

			$code = sprintf("isset(%s) ? %s : %s", $test, $left_code, $right_code);
		}
		elseif ($left_expr instanceof CastOperation) {
			$cached_access = $this->render_casted_access_for_none_coalescing($left_expr);
			if ($cached_access) {
				[$test, $left_code] = $cached_access;
			}
			else {
				$left_code = $this->render_node($left_expr);
				$test = $this->render_node($left_expr->left);
			}

			$code = sprintf("isset(%s) ? %s : %s", $test, $left_code, $right_code);
		}
		else {
			$left_code = $this->render_node($left_expr);
			$code = "$left_code ?? $right_code";
		}

		return $code;
	}

	public function render_break_statement(Node $node)
	{
		$argument = $node->target_layers > 1 ? ' ' . $node->target_layers : '';
		$code = 'break' . $argument . static::STATEMENT_TERMINATOR;

		// if ($node->condition) {
		// 	$code = $this->render_with_post_condition($node, $code);
		// }

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

		// if ($node->condition) {
		// 	$code = $this->render_with_post_condition($node, $code);
		// }

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

// end
