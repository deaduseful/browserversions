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

    public function testParseWikidata()
    {
        $matches = ['', '{{wikidata|property|edit|reference|Q777|P548=Q2804309|P400=Q1406|P348}}'];
        $actual = BrowserVersions::parseWikidata($matches);
        $expected = ['Q777', 'P548=Q2804309', 'P400=Q1406', 'P348'];
        $this->assertEquals($expected, $actual);
    }

    public function testFetchVersion()
    {
        $fragment = 'Google_Chrome';
        $actual = BrowserVersions::fetchVersion($fragment, 1);
        $this->assertIsNumeric($actual);
    }
}