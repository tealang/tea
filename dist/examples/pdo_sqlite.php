<?php
namespace tea\examples;

require_once __DIR__ . '/__unit.php';

#public
class SQLitePDO extends \PDO {
	public function __construct(string $filename) {
		parent::__construct('sqlite:' . realpath($filename));
	}
}

// ---------
$sqlite = new SQLitePDO(__DIR__ . '/pdo_sqlite.db');
$rs = $sqlite->query('SELECT name FROM sqlite_master ORDER BY name');
$rows = $rs->fetchAll(\PDO::FETCH_ASSOC);

if (!$rows) {
	$sqlite->exec('CREATE TABLE demo (id int,name string)');
	echo 'The demo table created', NL;
}
else {
	var_dump($rows);
}
// ---------

// program end
