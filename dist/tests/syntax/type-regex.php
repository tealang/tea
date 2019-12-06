<?php
namespace tea\tests\syntax;

// ---------
$str = '\'abc123\'';
$match_count = preg_match('/^\\\'[a-z0-9]+\'$/i', $str);
echo $match_count, NL;
// ---------

// program end
