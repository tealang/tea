<?php
/**
 * This file is part of the Tea programming language project
 * @copyright 	(c)2019 tealang.org
 * For the full copyright and license information, please view the LICENSE file that was distributed with this source code.
 */

namespace Tea;

class ContainerLiteralResolver
{
	private CheckerContext $context;
	private $infer_expression;
	private $reduce_types;

	public function __construct(
		CheckerContext $context,
		callable $infer_expression,
		callable $reduce_types
	) {
		$this->context = $context;
		$this->infer_expression = $infer_expression;
		$this->reduce_types = $reduce_types;
	}

	public function infer_array_expression(ArrayExpression $node): BaseType
	{
		if (!$node->items) {
			return TypeFactory::$_array;
		}

		$infered_item_types = [];
		foreach ($node->items as $item) {
			$infered_item_types[] = ($this->infer_expression)($item);
		}

		$item_type = ($this->reduce_types)($infered_item_types);

		return TypeFactory::create_array_type($item_type);
	}

	public function infer_dict_expression(DictExpression $node): BaseType
	{
		if (!$node->items) {
			return TypeFactory::$_dict;
		}

		$infered_value_types = [];
		$known_member_types = [];
		foreach ($node->items as $item) {
			if ($item instanceof DictMember) {
				$infered = $this->infer_dict_member($item);
				$key = $this->get_literal_dict_key($item->key);
				if ($key !== null) {
					$known_member_types[$key] = $infered;
				}
			}
			else {
				$infered = ($this->infer_expression)($item);
			}

			$infered_value_types[] = $infered;
		}

		$generic_type = ($this->reduce_types)($infered_value_types);

		return TypeFactory::create_dict_type($generic_type, $known_member_types);
	}

	public function get_literal_dict_key(BaseExpression $key): ?string
	{
		if ($key instanceof LiteralString) {
			return 's:' . $key->value;
		}

		if ($key instanceof LiteralInteger) {
			return 'i:' . (string)intval($key->value, 0);
		}

		return null;
	}

	public function infer_dict_member(DictMember $item): BaseType
	{
		$key_type = ($this->infer_expression)($item->key);
		if (!TypeHelper::is_dict_key_type($key_type) && $this->should_report_dict_key_mismatch($key_type)) {
			$type_name = ASTChecker::get_type_name($key_type);
			throw $this->context->new_syntax_error("Key type for Dict should be String/Int, {$type_name} given", $item->key);
		}

		return ($this->infer_expression)($item->value);
	}

	private function should_report_dict_key_mismatch(BaseType $key_type): bool
	{
		if (!$this->context->is_weakly_checking()) {
			return true;
		}

		return $key_type instanceof ObjectType;
	}
}
