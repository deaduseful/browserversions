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
    const WIKIPEDIA_URL = 'https://en.wikipedia.org/w/api.php?action=query&prop=revisions&rvprop=content&format=php&titles=Template:Latest_stable_software_release/';

    /** @var string The pattern to use to match the Wikipedia article */
    const WIKIPEDIA_PATTERN = '/(?:version1|latest[_ ]release[_ ]version)\s*=\s*(.+)/';

    /** @var string Wikipedia Start Characters */
    const START_CHARACTERS = '{{';

    /** @var string Wikipedia End Characters */
    const END_CHARACTERS = '}}';

    /** The data file, details about the browsers */
    private string $configFile = __DIR__ . '/browsers.json';

    /** The cache file, the browser versions */
    private string $cacheFile = 'versions.json';

    /** The maximum age of the cache file. Default is 3 months which is half of the expected browser release cycle. */
    private int $maxAge = 3 * 7 * 24 * 60 * 60;

    /** @var array The browser data */
    private array $configData;

    function __construct(bool $force = false)
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
        foreach ($data as $key => $name) {
            $browser = $key;
            $fragment = $this->getConfigData($browser, 'wikipedia');
            $normalize = $this->getConfigData($browser, 'normalized');
            $version = self::fetchVersion($fragment, $normalize);
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
            $wikidataQuery = self::getWikidataQuery($wikidata);
            $wikidata = self::getWikiData($wikidataQuery);
            $version = self::getVersionMatches($wikidata);
        } else {
            $version = $match;
        }
        return self::parseVersion($version, $normalize);
    }

    public static function getRawData(string $fragment): ?string
    {
        $url = self::WIKIPEDIA_URL . $fragment;
        $rawContent = file_get_contents($url);
        $content = unserialize($rawContent);
        if ($content == $rawContent) {
            throw new DomainException('Invalid content');
        }
        $page = array_pop($content['query']['pages']);
        if (array_key_exists('revisions', $page) === false) {
            return null;
        }
        return $page['revisions'][0]['*'];
    }

    public static function getMatches(string $rawData): array
    {
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
            if ($wikidata[0] === 'Q') {
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
    protected static function fileGetContents(string $host, array $headers = ['User-Agent: Browser Versions'], int $timeout = 1): string
    {
        if (ini_get('allow_url_fopen') == '0') {
            throw new RuntimeException('Disabled in the server configuration by allow_url_fopen=0');
        }
        $options = [
            'http' =>
                [
                    'header' => $headers,
                    'timeout' => $timeout
                ]
        ];
        $context = stream_context_create($options);
        $flags = null;
        return file_get_contents($host, $flags, $context);
    }

    private static function getVersionMatches(string $response): ?string
    {
        $data = json_decode($response);
        if (
            empty($data) ||
            empty($data->results) ||
            !is_array($data->results->bindings)
        ) {
            return null;
        }
        $bindings = $data->results->bindings;
        if (
            empty($bindings[0]) ||
            empty($bindings[0]->version) ||
            empty($bindings[0]->version->value)
        ) {
            return null;
        }
        usort($bindings, function ($a, $b) {
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
                $version[1] !== '0')
                $return .= '.' . $version[1];
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
