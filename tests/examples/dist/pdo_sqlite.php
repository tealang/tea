<?php
namespace tests\examples;

require_once dirname(__DIR__, 1) . '/__public.php';

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

if (empty($rows)) {
	$sqlite->exec('CREATE TABLE demo (id int,name string)');
	echo 'The demo table created', LF;
}
else {
	var_dump($rows);
}
// ---------

// program end
