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

	/**
	 * @var string
	 */
	public $uri;

	/**
	 * @var array
	 */
	public $names;

	/**
	 * @var Unit
	 */
	public $based_unit;

	public function __construct(array $names)
	{
		$this->set_names($names);
	}

	public function set_based_unit(Unit $unit)
	{
		$this->based_unit = $unit;
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
		$last_name = $this->names
			? $this->names[count($this->names) - 1]
			: ($this->based_unit ? $this->based_unit->ns->get_last_name() : null);

		return $last_name;
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
