<?php

namespace Deaduseful\BrowserVersions;

use DomainException;
use RuntimeException;

/**
 * Class BrowserVersions
 * @package Deaduseful\BrowserVersions
 */
class BrowserVersions
{
    /** @var string The URL path to get the browser version from */
    private const WIKIPEDIA_URL = 'https://en.wikipedia.org/w/api.php?action=query&prop=revisions&rvprop=content&rvslots=main&format=json&formatversion=2&titles=Template:Latest_stable_software_release/';

    /** @var string The pattern to use to match the Wikipedia article */
    private const WIKIPEDIA_PATTERN = '/(?:version1|latest[_ ]release[_ ]version)\s*=\s*(.+)/';

    /** @var string Wikipedia Start Characters */
    private const START_CHARACTERS = '{{';

    /** @var string Wikipedia End Characters */
    private const END_CHARACTERS = '}}';

    /** @var array HTTP options for requests */
    private const HTTP_OPTIONS = [
        'http' => [
            'header' => ['User-Agent: Deaduseful BrowserVersions/1.0 (https://github.com/deaduseful/browserversions; admin@deaduseful.com)'],
            'timeout' => 1,
        ]
    ];

    /** The data file, details about the browsers */
    private string $configFile = __DIR__ . '/browsers.json';

    /** The cache file, the browser versions */
    private string $cacheFile = 'versions.json';

    /** The maximum age of the cache file. Default is 3 months which is half of the expected browser release cycle. */
    private int $maxAge = 3 * 7 * 24 * 60 * 60;

    /** @var array The browser data */
    private array $configData;

    public function __construct(bool $force = false)
    {
        $this->updateCache($force);
    }

    public function updateCache(bool $force = false): bool
    {
        $cacheFile = $this->getCacheFile();
        if ($force ||
            is_file($cacheFile) === false ||
            filesize($cacheFile) === 0 ||
            time() - filemtime($cacheFile) >= $this->getMaxAge()
        ) {
            $versions = [];
            if (is_file($cacheFile)) {
                $cacheContents = file_get_contents($cacheFile);
                if (empty($cacheContents) === false) {
                    $cachedVersions = json_decode($cacheContents);
                    if (json_last_error() === JSON_ERROR_NONE &&
                        is_array($cachedVersions)) {
                        $versions = $cachedVersions;
                    }
                }
            }
            $versions = $this->fetchVersions($versions);
            $output = json_encode($versions, true);
            if ($output) {
                return file_put_contents($cacheFile, $output);
            }
        }
        return false;
    }

    public function getCacheFile(): string
    {
        return $this->cacheFile;
    }

    public function setCacheFile(string $cacheFile)
    {
        $this->cacheFile = $cacheFile;
    }

    public function getMaxAge(): int
    {
        return $this->maxAge;
    }

    public function setMaxAge(int $maxAge)
    {
        $this->maxAge = $maxAge;
    }

    public function fetchVersions(array $versions): array
    {
        $this->loadConfigData();
        $data = $this->getConfigData();
        foreach ($data as $key => $config) {
            $normalize = $config['normalized'] ?? null;
            $version = null;
            if (!empty($config['wikipedia'])) {
                try {
                    $version = self::fetchVersion($config['wikipedia'], $normalize);
                } catch (DomainException $e) {
                    // Wikipedia template is absent or malformed; fall through
                    // to the Wikidata path below if one is configured.
                    $version = null;
                }
            }
            if ($version === null && !empty($config['wikidata'])) {
                try {
                    $version = self::fetchVersionByWikidata($config['wikidata'], $normalize);
                } catch (DomainException $e) {
                    $version = null;
                }
            }
            if ($version) {
                $versions[$key] = $version;
            }
        }
        return $versions;
    }

    private function loadConfigData()
    {
        $this->configData = json_decode(file_get_contents($this->getConfigDataFile()), 1);
    }

    public function getConfigDataFile(): string
    {
        return $this->configFile;
    }

    public function getConfigData(string $browser = null, string $item = null)
    {
        $configData = $this->configData;
        if ($browser) {
            if ($item) {
                return $configData[$browser][$item];
            }
            return $configData[$browser];
        }
        return $configData;
    }

    /**
     * Fetch browser version from Wikipedia.
     *
     * @param string $fragment The "wikipedia" fragment, eg: Firefox.
     * @param int|double|null $normalize The "normalized", eg: 1.5
     * @return null|array|string
     * @throws DomainException
     */
    public static function fetchVersion(string $fragment, $normalize = null)
    {
        $rawData = self::getRawData($fragment);
        if ($rawData === null) {
            return null;
        }
        if (empty($rawData)) {
            throw new DomainException('Empty raw Wikidata');
        }
        $wikidataMatches = self::getMatches($rawData);
        if (empty($wikidataMatches)) {
            throw new DomainException('Empty matches from Wikidata');
        }
        $match = $wikidataMatches[1];
        if ($match[0] === '{') {
            $wikidata = self::parseWikidata($match);
            $version = self::queryWikidataVersion($wikidata);
        } else {
            $version = $match;
        }
        if ($version === null) {
            return null;
        }
        return self::parseVersion($version, $normalize);
    }

    /**
     * Fetch a browser version directly from Wikidata, bypassing Wikipedia
     * templates entirely.
     *
     * Some browsers (Safari, Opera, Internet Explorer) no longer have a
     * `Template:Latest_stable_software_release/<Name>` page, so the template-
     * based path always returns null for them. Letting `browsers.json`
     * specify a Wikidata Q-identifier directly gives us a stable refresh path
     * that does not depend on the template layout staying constant.
     *
     * @param string $wikidata A Wikidata Q-identifier, eg: Q35773 (Safari).
     * @param int|double|null $normalize
     * @return array|string|null
     * @throws DomainException
     */
    public static function fetchVersionByWikidata(string $wikidata, $normalize = null)
    {
        $version = self::queryWikidataVersion($wikidata);
        if ($version === null) {
            return null;
        }
        return self::parseVersion($version, $normalize);
    }

    private static function queryWikidataVersion(string $wikidata): ?string
    {
        $query = self::getWikidataQuery($wikidata);
        $response = self::getWikiData($query);
        return self::getVersionMatches($response);
    }

    public static function getRawData(string $fragment): ?string
    {
        $url = self::WIKIPEDIA_URL . $fragment;
        $rawContent = self::fileGetContents($url);
        return self::parseRawData($rawContent);
    }

    /**
     * Parse a MediaWiki API revisions response and return the wikitext body.
     *
     * Split out from getRawData() so the parsing logic is unit-testable with
     * a fixture instead of hitting the live Wikipedia API.
     *
     * @throws DomainException When the response is not a valid MediaWiki query.
     */
    public static function parseRawData(string $rawContent): ?string
    {
        $content = json_decode($rawContent, true);
        if (!is_array($content) || empty($content['query']['pages'])) {
            throw new DomainException('Invalid content');
        }
        $page = array_pop($content['query']['pages']);
        if (!isset($page['revisions'][0])) {
            return null;
        }
        $revision = $page['revisions'][0];
        return $revision['slots']['main']['content']
            ?? $revision['content']
            ?? $revision['*']
            ?? null;
    }

    public static function getMatches(string $rawData): array
    {
        // Strip HTML/wikitext comments first. Wikipedia editors routinely keep
        // the previous `version1 = X.Y.Z` line commented out above the live
        // `{{Wikidata|...}}` macro, and a naive first-match regex would lock
        // onto that stale value forever (e.g. Chrome silently regressing to
        // "134" when the live Wikidata version was already much higher).
        $rawData = (string) preg_replace('/<!--.*?-->/s', '', $rawData);
        if (preg_match(self::WIKIPEDIA_PATTERN, $rawData, $matches) === false) {
            throw new DomainException('Unable to get matches');
        }
        return $matches;
    }

    /** @see https://en.wikipedia.org/wiki/Template:Wikidata */
    public static function parseWikidata(string $string): string
    {
        $wikidataString = ltrim($string, self::START_CHARACTERS);
        $wikidataString = rtrim($wikidataString, self::END_CHARACTERS);
        $separator = '|';
        $expectedElements = substr_count($wikidataString, $separator);
        $wikidataArray = explode($separator, $wikidataString, $expectedElements);
        foreach ($wikidataArray as $wikidata) {
            if (empty($wikidata) === false &&
                $wikidata[0] === 'Q') {
                return $wikidata;
            }
        }
        throw new DomainException('Unable to get Q-identifier');
    }

    public static function getWikidataQuery(string $wikidata, string $reference = 'P348', bool $rank = false): string
    {
        $rankType = $rank ? 'PreferredRank' : 'NormalRank';
        $limit = $rank ? 'LIMIT 1' : '';

        return "
		SELECT ?version WHERE {
			wd:{$wikidata} p:{$reference} [
				ps:{$reference} ?version;
				wikibase:rank wikibase:{$rankType}
			].
		}
		{$limit}
	";
    }

    public static function getWikiData(string $query): string
    {
        $queryArray = [
            'format' => 'json',
            'query' => $query,
        ];
        $queryString = http_build_query($queryArray);
        $url = 'https://query.wikidata.org/bigdata/namespace/wdq/sparql';
        $url .= '?' . $queryString;
        return self::fileGetContents($url);
    }

    /**
     * Similar to file_get_contents, but passes in a user agent.
     */
    protected static function fileGetContents(string $host, array $options = self::HTTP_OPTIONS): string
    {
        if (ini_get('allow_url_fopen') == '0') {
            throw new RuntimeException('Disabled in the server configuration by allow_url_fopen=0');
        }
        $context = stream_context_create($options);
        return file_get_contents($host, false, $context);
    }

    /**
     * Pick the highest plausible version from a Wikidata SPARQL response.
     *
     * Filters bindings to values that look like a real version number
     * (start with a digit). Without this, items such as Safari's
     * "Technology Preview 156" labels would be sorted alongside genuine
     * releases like "26.2" and could win the comparison.
     */
    public static function getVersionMatches(string $response): ?string
    {
        $data = json_decode($response);
        if (
            empty($data) ||
            empty($data->results) ||
            !is_array($data->results->bindings)
        ) {
            return null;
        }
        $bindings = array_values(array_filter(
            $data->results->bindings,
            static function ($binding): bool {
                return isset($binding->version->value)
                    && preg_match('/^\d/', $binding->version->value) === 1;
            }
        ));
        if (empty($bindings)) {
            return null;
        }
        usort($bindings, static function ($a, $b) {
            return version_compare($b->version->value, $a->version->value);
        });
        return $bindings[0]->version->value;
    }

    /**
     * @param int|double|null $normalize
     * @return array|string|string[]|null
     */
    private static function parseVersion(string $input, $normalize = null)
    {
        if (empty($input)) {
            throw new DomainException("Missing version.");
        }

        $version = preg_replace('/[^0-9.]/', '', $input);

        if ($normalize) {
            return self::normalizeVersion($version, $normalize);
        }

        return $version;
    }

    /**
     * @param int|double|null $normalize
     * @return array|string
     */
    private static function normalizeVersion(string $version, $normalize = null)
    {
        $version = explode('.', $version);

        if ($normalize == 1.5) {
            $return = $version[0];
            if (isset($version[1]) &&
                $version[1] !== '0') {
                $return .= '.' . $version[1];
            }
            return $return;
        }

        $return = [];
        for ($i = 0; $i < $normalize; $i++) {
            $return[] = $version[$i];
        }
        return implode('.', $return);
    }

    public function setConfigFile($configFile)
    {
        $this->configFile = $configFile;
    }
}
