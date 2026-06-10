<?php
/**
 * This file is part of the Tea programming language project
 * @copyright 	(c)2019 tealang.org
 * For the full copyright and license information, please view the LICENSE file that was distributed with this source code.
 */

namespace Tea;

class NamespaceIdentifier extends Node
{
	const KIND = 'namespace_identifier';

	public string $uri = '';

	public array $names = [];

	public function __construct(array $names)
	{
		$this->set_names($names);
	}

	public function set_names(array $names)
	{
		$this->names = $names;
		$this->uri = join(TeaParser::NS_SEPARATOR, $names);
	}

	public function is_global_space()
	{
		return $this->uri === '';
	}

	public function get_last_name()
	{
		return $this->names ? $this->names[count($this->names) - 1] : null;
	}

	public function get_namepath()
	{
		$names = $this->names;
		if ($names and $names[0] === '') {
			array_shift($names);
		}

		return $names;
	}
}

// end
