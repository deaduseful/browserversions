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
        $string = '{{wikidata|property|edit|reference|Q777|P548=Q2804309|P400=Q1406|P348}}';
        $actual = BrowserVersions::parseWikidata($string);
        $expected = 'Q777';
        $this->assertEquals($expected, $actual);
    }

    public function testParseWikidata2()
    {
        $string = '{{wikidata|property|preferred|references|edit|Q777|P348|P400=Q1406|P548=Q2804309}}';
        $actual = BrowserVersions::parseWikidata($string);
        $expected = 'Q777';
        $this->assertEquals($expected, $actual);
    }

    public function testParseWikidata3()
    {
        $string = '{{{{{|safesubst:}}}wikidata|property|preferred|references|edit|Q777|P348|P400=Q1406|P548=Q2804309}}';
        $actual = BrowserVersions::parseWikidata($string);
        $expected = 'Q777';
        $this->assertEquals($expected, $actual);
    }

    public function testFetchChromeVersion()
    {
        $fragment = 'Google_Chrome';
        $actual = BrowserVersions::fetchVersion($fragment, 1);
        $this->assertGreaterThanOrEqual('87', $actual);
    }

    public function testFetchFirefoxVersion()
    {
        $fragment = 'Firefox';
        $actual = BrowserVersions::fetchVersion($fragment, 1);
        $this->assertGreaterThanOrEqual('84', $actual);
    }

    public function testGetVersionsFile()
    {
        $browserVersions = new BrowserVersions();
        $outputFile = $browserVersions->getCacheFile();
        $this->assertEquals('versions.json', $outputFile);
    }
}