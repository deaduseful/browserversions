<?php

use Deaduseful\BrowserVersions\BrowserVersions;
use PHPUnit\Framework\TestCase;

final class BrowserVersionsTest extends TestCase
{
    /**
     * Regression: Wikipedia removed format=php from the MediaWiki API, so
     * unserialize() on the JSON response always failed and getRawData()
     * could never refresh the cache. parseRawData() must extract wikitext
     * from the modern formatversion=2 + rvslots=main JSON shape.
     *
     * @see https://github.com/deaduseful/browserversions/pull/2
     */
    public function testParseRawDataReturnsWikitextForJsonResponseWithSlots()
    {
        $rawContent = file_get_contents(__DIR__ . '/fixtures/wikipedia_firefox_response.json');

        $actual = BrowserVersions::parseRawData($rawContent);

        $this->assertIsString($actual);
        $this->assertStringContainsString('{{wikidata', $actual);
        $this->assertStringContainsString('Q698', $actual);
    }

    public function testParseRawDataReturnsNullForMissingPage()
    {
        $rawContent = file_get_contents(__DIR__ . '/fixtures/wikipedia_missing_page_response.json');

        $this->assertNull(BrowserVersions::parseRawData($rawContent));
    }

    /**
     * Reproduces the original failure mode: the legacy format=php response
     * is not valid JSON, so parseRawData() must throw rather than silently
     * trying to access ['query']['pages'] on a non-array.
     */
    public function testParseRawDataThrowsForLegacyPhpSerializedResponse()
    {
        $legacyPhpResponse = 'a:1:{s:5:"query";a:1:{s:5:"pages";a:0:{}}}';

        $this->expectException(\DomainException::class);
        BrowserVersions::parseRawData($legacyPhpResponse);
    }

    public function testParseRawDataThrowsForEmptyResponse()
    {
        $this->expectException(\DomainException::class);
        BrowserVersions::parseRawData('');
    }

    public function testParseRawDataReadsContentFieldWhenSlotsAreOmitted()
    {
        $rawContent = json_encode([
            'query' => [
                'pages' => [
                    [
                        'pageid' => 1,
                        'title' => 'Test',
                        'revisions' => [
                            ['content' => 'version1 = 9.9.9'],
                        ],
                    ],
                ],
            ],
        ]);

        $this->assertSame('version1 = 9.9.9', BrowserVersions::parseRawData($rawContent));
    }

    public function testParseRawDataFallsBackToLegacyStarField()
    {
        $rawContent = json_encode([
            'query' => [
                'pages' => [
                    '12345' => [
                        'pageid' => 12345,
                        'title' => 'Test',
                        'revisions' => [
                            ['*' => 'version1 = 1.2.3'],
                        ],
                    ],
                ],
            ],
        ]);

        $this->assertSame('version1 = 1.2.3', BrowserVersions::parseRawData($rawContent));
    }

    public function testGetMatches()
    {
        $rawData = file_get_contents(__DIR__ . '/test_raw_data.txt');
        $matches = BrowserVersions::getMatches($rawData);
        $actual = $matches[1];
        $expected = '{{wikidata|property|edit|reference|Q777|P548=Q2804309|P400=Q1406|P348}}';
        $this->assertEquals($expected, $actual);
    }

    /**
     * Regression: Wikipedia editors keep the previous version commented out
     * above the live `{{Wikidata}}` macro, e.g.
     *
     *     <!--| version1 = 134.0.6998.177/178 -->
     *     | version1 = {{Wikidata|...}}
     *
     * A naive first-match regex locked onto the commented (stale) line and
     * caused Chrome to silently regress to "134". `getMatches()` must strip
     * comments before matching and return the live wikidata macro instead.
     */
    public function testGetMatchesSkipsCommentedVersionLine()
    {
        $rawData = file_get_contents(__DIR__ . '/fixtures/wikitext_chrome_with_commented_version.txt');

        $matches = BrowserVersions::getMatches($rawData);

        $this->assertNotEmpty($matches);
        $this->assertStringContainsString('wikidata', $matches[1]);
        $this->assertStringContainsString('Q777', $matches[1]);
        $this->assertStringNotContainsString('134.0.6998', $matches[1]);
    }

    public function testGetVersionMatchesFiltersNonNumericLabels()
    {
        $response = json_encode([
            'results' => [
                'bindings' => [
                    ['version' => ['value' => 'Technology Preview 156']],
                    ['version' => ['value' => '26.2']],
                    ['version' => ['value' => 'Technology Preview 139']],
                    ['version' => ['value' => '18.6.2']],
                ],
            ],
        ]);

        $this->assertSame('26.2', BrowserVersions::getVersionMatches($response));
    }

    public function testGetVersionMatchesReturnsNullWhenAllNonNumeric()
    {
        $response = json_encode([
            'results' => [
                'bindings' => [
                    ['version' => ['value' => 'Technology Preview 156']],
                    ['version' => ['value' => 'beta']],
                ],
            ],
        ]);

        $this->assertNull(BrowserVersions::getVersionMatches($response));
    }

    public function testGetVersionMatchesReturnsNullForEmptyBindings()
    {
        $response = json_encode(['results' => ['bindings' => []]]);

        $this->assertNull(BrowserVersions::getVersionMatches($response));
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