<?php

use Deaduseful\BrowserVersions\BrowserVersions;
use PHPUnit\Framework\TestCase;

final class BrowserVersionsTest extends TestCase
{
    public function testGetMatches()
    {
        $rawData = file_get_contents(__DIR__ . '/test_raw_data.txt');
        $matches = BrowserVersions::getMatches($rawData);
        $actual = $matches[1];
        $expected = '{{wikidata|property|edit|reference|Q777|P548=Q2804309|P400=Q1406|P348}}';
        $this->assertEquals($expected, $actual);

    }
}