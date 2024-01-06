<?php
/**
 * This file is part of the Tea programming language project
 *
 * @author 		Benny <benny@meetdreams.com>
 * @copyright 	(c)2019 YJ Technology Ltd. [http://tealang.org]
 * For the full copyright and license information, please view the LICENSE file that was distributed with this source code.
 */

namespace Tea;

class NamespaceIdentifier extends Node
{
	const KIND = 'namespace_identifier';

	public $uri;

	/**
	 * @var array
	 */
	public $names;

	public function __construct(array $names)
	{
		$this->names = $names;
		$this->uri = join(_SLASH, $names);
	}

	public function get_last_name()
	{
		return $this->names[array_key_last($this->names)];
	}

	public function get_namepath()
	{
		$names = $this->names;
		if ($names[0] === '') {
			array_shift($names);
		}

		return $names;
	}
}

// end
