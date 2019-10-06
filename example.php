<?php

use Deaduseful\BrowserVersions\BrowserVersions;

include 'src/BrowserVersions.php';

$browserVersions = new BrowserVersions();
$versionsFile = $browserVersions->getCacheFile();
readfile($versionsFile);
