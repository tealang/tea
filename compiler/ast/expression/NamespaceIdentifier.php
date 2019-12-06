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

	// public static function create_with_uri(string $uri)
	// {
	// 	$names = explode(_SLASH, $uri);

	// 	if (count($names) === 1 && !TeaHelper::is_identifier_name($names[0])) {
	// 		throw new \Exception("Invalid namespace URI '$uri'.");
	// 	}

	// 	return new static($names);
	// }
}
