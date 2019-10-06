<?php

use Deaduseful\BrowserVersions\BrowserVersions;

include 'src/BrowserVersions.php';

$browserVersions = new BrowserVersions(true);
$versionsFile = $browserVersions->getCacheFile();
readfile($versionsFile);
