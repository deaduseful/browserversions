<?php

namespace Deaduseful\BrowserVersions;

use DomainException;
use RuntimeException;
use UnexpectedValueException;

/**
 * Class BrowserVersions
 * @package Deaduseful\BrowserVersions
 */
class BrowserVersions
{
    /**
     * The URL path to get the browser version from.
     */
    const WIKIPEDIA_URL = 'https://en.wikipedia.org/w/api.php?action=query&prop=revisions&rvprop=content&format=php&titles=Template:Latest_stable_software_release/';

    /**
     * The pattern to use to match the Wikipedia article.
     */
    const WIKIPEDIA_PATTERN = '/(?:version1|latest[_ ]release[_ ]version)\s*=\s*(.+)/';

    /**
     * The data file, details about the browsers.
     */
    private $configFile = __DIR__ . '/browsers.json';

    /**
     * The cache file, the browser versions.
     */
    private $cacheFile = 'versions.json';

    /**
     * The maximum age of the cache file. Default is 3 months which is half of the expected browser release cycle.
     */
    private $maxAge = 3 * 7 * 24 * 60 * 60;

    /**
     * @var array The browser data.
     */
    private $configData;

    /**
     * BrowserVersions constructor.
     *
     * @param bool $force
     */
    function __construct($force = false)
    {
        $this->updateCache($force);
    }

    /**
     * Update Cache.
     *
     * @param bool $force
     * @return bool
     */
    public function updateCache($force = false)
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

    /**
     * @return mixed
     */
    public function getCacheFile()
    {
        return $this->cacheFile;
    }

    /**
     * @param mixed $cacheFile
     */
    public function setCacheFile($cacheFile)
    {
        $this->cacheFile = $cacheFile;
    }

    /**
     * @return mixed
     */
    public function getMaxAge()
    {
        return $this->maxAge;
    }

    /**
     * @param mixed $maxAge
     */
    public function setMaxAge($maxAge)
    {
        $this->maxAge = $maxAge;
    }

    /**
     * @param array $versions
     * @return array
     * @throws UnexpectedValueException
     */
    public function fetchVersions($versions = [])
    {
        if (!is_array($versions)) {
            throw new UnexpectedValueException('Expected array.');
        }
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

    /**
     *
     */
    private function loadConfigData()
    {
        $this->configData = json_decode(file_get_contents($this->getConfigDataFile()), 1);
    }

    /**
     * @return mixed
     */
    public function getConfigDataFile()
    {
        return $this->configFile;
    }

    /**
     * @param bool $browser
     * @param bool $item
     * @return mixed|null
     */
    public function getConfigData($browser = false, $item = false)
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
     * @return array|bool|mixed|string
     * @throws DomainException
     */
    public static function fetchVersion($fragment, $normalize = null)
    {
        $rawData = self::getRawData($fragment);
        $wikidataMatches = self::getMatches($rawData);
        if (empty($wikidataMatches)) {
            throw new DomainException("Unable to parse Wikidata.");
        }
        $match = $wikidataMatches[1];
        if ($match[0] === '{') {
            $wikidataValues = self::parseWikidata($match);
            $wikidata = $wikidataValues['wikidata'];
            $wikidataQuery = self::getWikidataQuery($wikidata);
            $wikidata = self::getWikiData($wikidataQuery);
            $version = self::getVersionMatches($wikidata);
        } else {
            $version = $match;
        }
        return self::parseVersion($version, $normalize);
    }

    /**
     * @param string $fragment
     * @return string
     */
    public static function getRawData($fragment)
    {
        $url = self::WIKIPEDIA_URL . $fragment;
        $rawContent = file_get_contents($url);
        $content = unserialize($rawContent);
        if ($content == $rawContent) {
            throw new DomainException('Invalid content.');
        }
        $page = array_pop($content['query']['pages']);
        return $page['revisions'][0]['*'];
    }

    /**
     * @param string $rawData
     * @return array
     */
    public static function getMatches($rawData)
    {
        if (preg_match(self::WIKIPEDIA_PATTERN, $rawData, $matches) === false) {
            throw new DomainException("Unable to get matches.");
        }
        return $matches;
    }

    /**
     * @param string $string
     * @return array
     */
    public static function parseWikidata($string)
    {
        $wikidataString = ltrim($string, '{{');
        $wikidataString = rtrim($wikidataString, '}}');
        $expectedElements = 8;
        $wikidataArray = explode('|', $wikidataString, $expectedElements);
        $maxKeys = $expectedElements / 2;
        $keys = [];
        for ($i = 0; $i < $maxKeys; $i++) {
            $keys[] = isset($wikidataArray[$i]) ? $wikidataArray[$i] : null;
        }
        $values = [];
        for ($i = $maxKeys; $i < $expectedElements; $i++) {
            $values[] = isset($wikidataArray[$i]) ? $wikidataArray[$i] : null;
        }
        if (count($keys) !== count($values)) {
            $message = sprintf("Both parameters should have an equal number of elements, got: %s", $wikidataString);
            throw new DomainException($message);
        }
        $results = array_combine($keys, $values);
        if (isset($results['wikidata']) === false) {
            $message = sprintf("Missing wikidata, got: %s", $wikidataString);
            throw new DomainException($message);
        }
        return $results;
    }

    /**
     * @param string $wikidata
     * @param string $reference
     * @param false $rank
     * @return string
     */
    public static function getWikidataQuery($wikidata, $reference = 'P348', $rank = false)
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

    /**
     * @param string $query
     * @return string
     */
    public static function getWikiData($query)
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
     *
     * @param string $host
     * @param array $headers
     * @param int $timeout
     * @return string
     */
    protected static function fileGetContents($host, $headers = ['User-Agent: Browser Versions'], $timeout = 1)
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

    /**
     * @param string $response
     * @return false
     */
    private static function getVersionMatches($response)
    {
        $data = json_decode($response);

        if (
            empty($data) ||
            empty($data->results) ||
            !is_array($data->results->bindings)
        ) {
            return false;
        }

        if (
            empty($data->results->bindings[0]) ||
            empty($data->results->bindings[0]->version) ||
            empty($data->results->bindings[0]->version->value)
        ) {
            return false;
        }

        usort($data->results->bindings, function ($a, $b) {
            return version_compare($b->version->value, $a->version->value);
        });

        return $data->results->bindings[0]->version->value;
    }

    /**
     * @param string $input
     * @param int|double|null $normalize
     * @return array|string|string[]|null
     */
    private static function parseVersion($input, $normalize = null)
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
     * @param $version
     * @param $normalize
     * @return array|string
     */
    private static function normalizeVersion($version, $normalize)
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

    /**
     * @param mixed $configFile
     */
    public function setConfigFile($configFile)
    {
        $this->configFile = $configFile;
    }
}
