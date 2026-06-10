<?php
/**
 * This file is part of the Tea programming language project
 * @copyright 	(c)2019 tealang.org
 * For the full copyright and license information, please view the LICENSE file that was distributed with this source code.
 */

namespace Tea;

class UseDeclaration extends RootDeclaration
{
	const KIND = 'use_declaration';

	const IMPORT_CLASS = 'class';
	const IMPORT_FUNCTION = 'function';
	const IMPORT_CONST = 'const';

	public ?string $target_name = null;

	public ?string $source_name = null;

	public ?string $import_kind = null;

	public function __construct(NamespaceIdentifier $ns, ?string $target_name = null, ?string $source_name = null, ?string $import_kind = null)
	{
		$this->ns = $ns;
		$this->name = $target_name ?? $ns->get_last_name();
		$this->target_name = $target_name;
		$this->source_name = $source_name;
		$this->import_kind = $import_kind;
	}
}
