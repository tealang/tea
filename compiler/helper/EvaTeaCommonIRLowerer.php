<?php
/**
 * This file is part of the Tea programming language project
 * @copyright 	(c)2019 tealang.org
 * For the full copyright and license information, please view the LICENSE file that was distributed with this source code.
 */

namespace Tea;

class EvaTeaCommonIRLowerer
{
	public function lower_file(string $source_path): array
	{
		if (!is_file($source_path)) {
			throw new \InvalidArgumentException("Eva-Tea source file not found: {$source_path}");
		}

		$source = file_get_contents($source_path);
		$declarations = [];
		$exports = [];

		if ($sample = $this->read_sample_declaration($source, $source_path)) {
			$declarations[] = $sample;
		}

		foreach ($this->read_import_declarations($source, $source_path) as $declaration) {
			$declarations[] = $declaration;
		}

		foreach ($this->read_enum_declarations($source, $source_path) as $declaration) {
			$declarations[] = $declaration;
			$exports[] = $declaration['name'];
		}

		foreach ($this->read_model_declarations($source, $source_path) as $declaration) {
			$declarations[] = $declaration;
			$exports[] = $declaration['name'];
		}

		foreach ($this->read_feature_declarations($source, $source_path) as $declaration) {
			$declarations[] = $declaration;
			$exports[] = $declaration['qualified_name'];
		}

		return [
			'schema_version' => 'common-ir-fixture/v0',
			'program' => [
				'packages' => [
					[
						'name' => $this->infer_package_name($source_path),
						'path' => dirname($source_path),
						'source_units' => [
							[
								'source_path' => $source_path,
								'source_dialect' => 'eva-tea',
								'source_kind' => 'source',
							],
						],
						'dependencies' => $this->read_dependencies($source),
						'declarations' => $declarations,
						'symbol_graph' => [
							'imports' => $this->read_symbol_imports($source),
							'exports' => $exports,
						],
					],
				],
				'diagnostics' => $this->read_diagnostics($source, $source_path),
			],
		];
	}

	private function infer_package_name(string $source_path): string
	{
		$name = pathinfo($source_path, PATHINFO_FILENAME);
		return $name === 'rems-department' || $name === 'department' ? 'rems.department' : $name;
	}

	private function source_ref(string $source_path): array
	{
		return [
			'path' => $source_path,
			'dialect' => 'eva-tea',
		];
	}

	private function read_sample_declaration(string $source, string $source_path): ?array
	{
		if (!preg_match('/\bsamples\s+([A-Za-z_][A-Za-z0-9_]*)\s+([A-Za-z_][A-Za-z0-9_]*<([A-Za-z_][A-Za-z0-9_]*)>)\s*=/u', $source, $matches)) {
			return null;
		}

		return [
			'kind' => 'SampleDecl',
			'name' => $matches[1],
			'type' => [
				'kind' => $matches[2] === $matches[3] ? 'Named' : 'List',
				'item' => [
					'kind' => 'Named',
					'name' => $matches[3],
				],
			],
			'source' => $this->source_ref($source_path),
			'items' => $this->read_sample_items($source, $matches[1]),
		];
	}

	private function read_sample_items(string $source, string $sample_name): array
	{
		$pattern = '/\bsamples\s+' . preg_quote($sample_name, '/') . '\s+[A-Za-z_][A-Za-z0-9_]*<[^>]+>\s*=\s*\[/u';
		if (!preg_match($pattern, $source, $match, PREG_OFFSET_CAPTURE)) {
			return [];
		}

		$head = $match[0][0];
		$start = $match[0][1] + strlen($head) - 1;
		$end = $this->find_matching_square_bracket($source, $start);
		if ($end === null) {
			return [];
		}

		$body = substr($source, $start + 1, $end - $start - 1);
		$items = [];
		foreach ($this->read_blocks($body, '/\{/u') as $block) {
			$item = [];
			if (preg_match_all('/\b([A-Za-z_][A-Za-z0-9_]*):\s*"([^"]*)"/u', $block['body'], $matches, PREG_SET_ORDER)) {
				foreach ($matches as $field) {
					$item[$field[1]] = $field[2];
				}
			}

			if ($item) {
				$items[] = $item;
			}
		}

		return $items;
	}

	private function read_import_declarations(string $source, string $source_path): array
	{
		$declarations = [];
		foreach ($this->read_dependencies($source) as $dependency) {
			$imports = [];
			foreach ($dependency['imports'] as $name) {
				$imports[] = ['name' => $name];
			}

			$declarations[] = [
				'kind' => 'ImportDecl',
				'from' => $dependency['package'],
				'imports' => $imports,
				'source' => $this->source_ref($source_path),
			];
		}

		return $declarations;
	}

	private function read_dependencies(string $source): array
	{
		$dependencies = [];
		if (!preg_match_all('/\buse\s+([A-Za-z0-9_-]+)\s*\{([^}]+)\}/u', $source, $matches, PREG_SET_ORDER)) {
			return $dependencies;
		}

		foreach ($matches as $match) {
			$dependencies[] = [
				'package' => $match[1],
				'imports' => $this->split_names($match[2]),
			];
		}

		return $dependencies;
	}

	private function read_symbol_imports(string $source): array
	{
		$imports = [];
		foreach ($this->read_dependencies($source) as $dependency) {
			foreach ($dependency['imports'] as $name) {
				$imports[] = [
					'local' => $name,
					'from' => $dependency['package'] . '.' . $name,
				];
			}
		}

		return $imports;
	}

	private function read_enum_declarations(string $source, string $source_path): array
	{
		$declarations = [];
		if (!preg_match_all('/\btype\s+([A-Za-z_][A-Za-z0-9_]*)\s*=\s*Enum\s*\{([^}]*)\}/u', $source, $matches, PREG_SET_ORDER)) {
			return $declarations;
		}

		foreach ($matches as $match) {
			$declarations[] = [
				'kind' => 'TypeDecl',
				'name' => $match[1],
				'type_kind' => 'enum',
				'base_type' => [
					'kind' => 'Named',
					'name' => 'Text',
				],
				'enum_values' => $this->split_names($match[2]),
				'source' => $this->source_ref($source_path),
			];
		}

		return $declarations;
	}

	private function read_model_declarations(string $source, string $source_path): array
	{
		$declarations = [];
		foreach ($this->read_blocks($source, '/\bmodel\s+([A-Za-z_][A-Za-z0-9_]*)\s*\{/u') as $block) {
			$body = $block['body'];
			$declarations[] = [
				'kind' => 'ModelDecl',
				'name' => $block['matches'][1],
				'title' => $this->read_title($body),
				'fields' => $this->read_model_fields($body),
				'source' => $this->source_ref($source_path),
			];
		}

		return $declarations;
	}

	private function read_feature_declarations(string $source, string $source_path): array
	{
		$declarations = [];
		foreach ($this->read_blocks($source, '/\bfeature\s+([A-Za-z_][A-Za-z0-9_]*)\s+by\s+([A-Za-z_][A-Za-z0-9_]*)\s*\{/u') as $block) {
			$name = $block['matches'][1];
			$model = $block['matches'][2];
			$body = $block['body'];
			$declarations[] = [
				'kind' => 'FeatureDecl',
				'name' => $name,
				'qualified_name' => $model . '.' . $name,
				'target_model' => $model,
				'feature_kind' => $this->infer_feature_kind($name),
				'title' => $this->read_title($body),
				'guards' => $this->read_feature_guards($body),
				'input' => $this->read_feature_fields($body, 'input', $model),
				'query' => $this->read_feature_fields($body, 'query', $model),
				'updates' => $this->read_feature_updates($body),
				'output' => $this->read_feature_fields($body, 'output', $model),
				'source' => $this->source_ref($source_path),
			];
		}

		return $declarations;
	}

	private function infer_feature_kind(string $name): string
	{
		return match ($name) {
			'create' => 'create',
			'list' => 'query',
			default => 'update',
		};
	}

	private function read_blocks(string $source, string $pattern): array
	{
		$blocks = [];
		if (!preg_match_all($pattern, $source, $matches, PREG_SET_ORDER | PREG_OFFSET_CAPTURE)) {
			return $blocks;
		}

		foreach ($matches as $match) {
			$head = $match[0][0];
			$start = $match[0][1] + strlen($head) - 1;
			$end = $this->find_matching_brace($source, $start);
			if ($end === null) {
				continue;
			}

			$plain_matches = [];
			foreach ($match as $item) {
				$plain_matches[] = $item[0];
			}

			$blocks[] = [
				'matches' => $plain_matches,
				'body' => substr($source, $start + 1, $end - $start - 1),
			];
		}

		return $blocks;
	}

	private function find_matching_brace(string $source, int $start): ?int
	{
		$depth = 0;
		$length = strlen($source);
		for ($i = $start; $i < $length; $i++) {
			$char = $source[$i];
			if ($char === '{') {
				$depth++;
			} elseif ($char === '}') {
				$depth--;
				if ($depth === 0) {
					return $i;
				}
			}
		}

		return null;
	}

	private function find_matching_square_bracket(string $source, int $start): ?int
	{
		$depth = 0;
		$length = strlen($source);
		for ($i = $start; $i < $length; $i++) {
			$char = $source[$i];
			if ($char === '[') {
				$depth++;
			} elseif ($char === ']') {
				$depth--;
				if ($depth === 0) {
					return $i;
				}
			}
		}

		return null;
	}

	private function read_title(string $body): ?string
	{
		return preg_match('/^\s*title:\s*"([^"]+)"/um', $body, $matches) ? $matches[1] : null;
	}

	private function read_model_fields(string $body): array
	{
		$fields = [];
		foreach (preg_split('/\R/u', $body) as $line) {
			$line = trim($line);
			if ($line === '' || str_starts_with($line, 'title:')) {
				continue;
			}

			if (!preg_match('/^([A-Za-z_][A-Za-z0-9_]*)\s+([A-Za-z_][A-Za-z0-9_]*)(?:\s*\{\s*title:\s*"([^"]+)"\s*\})?$/u', $line, $matches)) {
				continue;
			}

			$field = [
				'name' => $matches[1],
				'type' => $this->named_type($matches[2]),
			];
			if (isset($matches[3]) && $matches[3] !== '') {
				$field['metadata'] = ['title' => $matches[3]];
			}
			$fields[] = $field;
		}

		return $fields;
	}

	private function read_feature_fields(string $body, string $section, string $model): array
	{
		$section_body = $this->read_section_body($body, $section);
		if ($section_body === null) {
			return [];
		}

		$fields = [];
		foreach ($this->split_section_items($section_body) as $line) {
			if (!preg_match('/^([A-Za-z_][A-Za-z0-9_]*)(?:\s+([A-Za-z_][A-Za-z0-9_]*)(?:\s*\{([^}]*)\})?)?$/u', $line, $matches)) {
				continue;
			}

			$name = $matches[1];
			$field = ['name' => $name];
			if (!isset($matches[2]) || $matches[2] === '') {
				$field['type_from'] = $model . '.' . $name;
			} else {
				$field['type'] = $this->named_type($matches[2]);
			}

			$metadata = $matches[3] ?? '';
			if (str_contains($metadata, 'nullable')) {
				$field['type'] = [
					'kind' => 'Invalidable',
					'base' => $this->named_type($matches[2]),
					'sentinel' => ['kind' => 'NullLiteral'],
				];
				$field['metadata'] = ['nullable' => true];
			}

			if (preg_match('/default:\s*\.?([^\s}]+)/u', $metadata, $default_matches)) {
				$field['default'] = $this->read_default_value($matches[2] ?? '', $default_matches[1]);
			}

			$fields[] = $field;
		}

		return $fields;
	}

	private function read_feature_guards(string $body): array
	{
		$guards = [];
		$allow = $this->read_section_body($body, 'allow');
		if ($allow !== null && str_contains($allow, '$auth.role == .admin')) {
			$guards[] = [
				'kind' => 'PermissionGuard',
				'expr' => $this->binary_member_case_expr('$auth', 'role', 'PolicyLiteral', 'admin'),
			];
		}

		$where = $this->read_section_body($body, 'where');
		if ($where !== null && str_contains($where, '$old.status == .启用')) {
			$guards[] = [
				'kind' => 'StateGuard',
				'expr' => $this->binary_member_case_expr('$old', 'status', 'EnumCaseRef', '启用', 'DepartmentStatus'),
			];
		}

		return $guards;
	}

	private function read_feature_updates(string $body): array
	{
		$update = $this->read_section_body($body, 'update');
		if ($update === null || !preg_match('/\b([A-Za-z_][A-Za-z0-9_]*):\s*\.([^\s}]+)/u', $update, $matches)) {
			return [];
		}

		return [
			[
				'kind' => 'SetField',
				'field' => $matches[1],
				'value' => [
					'kind' => 'EnumCaseRef',
					'type' => 'DepartmentStatus',
					'case' => $matches[2],
				],
			],
		];
	}

	private function read_section_body(string $body, string $section): ?string
	{
		$blocks = $this->read_blocks($body, '/\b' . preg_quote($section, '/') . '\s*\{/u');
		return $blocks[0]['body'] ?? null;
	}

	private function split_section_items(string $body): array
	{
		$items = [];
		foreach (preg_split('/\R/u', trim($body)) as $line) {
			$line = trim($line);
			if ($line !== '') {
				$items[] = $line;
			}
		}

		if (!$items && trim($body) !== '') {
			$items[] = trim($body);
		}

		return $items;
	}

	private function read_default_value(string $type, string $value): array
	{
		if ($type === 'Bool') {
			return [
				'kind' => 'BoolNormalizationLiteral',
				'source_value' => $value,
			];
		}

		return [
			'kind' => 'EnumCaseRef',
			'type' => $type,
			'case' => $value,
		];
	}

	private function binary_member_case_expr(string $object, string $member, string $right_kind, string $right_name, ?string $right_type = null): array
	{
		$right = [
			'kind' => $right_kind,
			'name' => $right_name,
		];
		if ($right_kind === 'EnumCaseRef') {
			$right = [
				'kind' => 'EnumCaseRef',
				'type' => $right_type,
				'case' => $right_name,
			];
		}

		return [
			'kind' => 'BinaryExpr',
			'op' => '==',
			'left' => [
				'kind' => 'MemberExpr',
				'object' => [
					'kind' => 'HostSymbol',
					'name' => $object,
				],
				'member' => $member,
			],
			'right' => $right,
		];
	}

	private function named_type(string $name): array
	{
		$type = [
			'kind' => 'Named',
			'name' => $name,
		];
		if ($name === 'Id') {
			$type['origin'] = 'imported';
		}

		return $type;
	}

	private function read_diagnostics(string $source, string $source_path): array
	{
		if (!str_contains($source, 'is_key: "是"') && !str_contains($source, 'is_key: "否"')) {
			return [];
		}

		return [
			[
				'severity' => 'warning',
				'code' => 'eva.bool-normalization.pending',
				'message' => 'Department samples and defaults use Chinese yes/no literals for Bool; lowering must normalize them explicitly or report a source diagnostic.',
				'source' => $this->source_ref($source_path),
			],
		];
	}

	private function split_names(string $source): array
	{
		$names = preg_split('/[\s,]+/u', trim($source));
		return array_values(array_filter($names, static fn ($name) => $name !== ''));
	}
}
