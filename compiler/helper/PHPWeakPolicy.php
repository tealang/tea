<?php
namespace Tea;

final class PHPWeakPolicy
{
	public static function is_dynamic_top_type(BaseType $type): bool
	{
		$type = TypeHelper::unwrap_excludable_type($type);
		return $type instanceof AnyType
			|| $type instanceof MixedType
			|| ($type instanceof InvalidableType
				&& $type->valid_type instanceof AnyType
				&& $type->sentinel instanceof LiteralNone);
	}
}
