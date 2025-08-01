<?php

declare(strict_types=1);

namespace Daisukedaisuketeam\AgenBugs;

if(!file_exists(__DIR__ . '/../vendor/autoload.php')){
	echo "final: vendor not found";
	return;
}

include __DIR__ . '/../vendor/autoload.php';

echo "===========bug212===========" . PHP_EOL;

$bugs = new bug212();
$bugs->main();

echo "===========bug212===========" . PHP_EOL;