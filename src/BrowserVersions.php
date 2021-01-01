<?php

namespace Deaduseful\BrowserVersions;

use DomainException;
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
    const WIKIPEDIA_PATTERN = '/(?:version1|latest[_ ]release[_ ]version)\s*=\s*([\d][\d\.]+)/';

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
    function updateCache($force = false)
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
    function fetchVersions($versions = [])
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
     * @param mixed $configFile
     */
    public function setConfigFile($configFile)
    {
        $this->configFile = $configFile;
    }

    /**
     * @param bool $browser
     * @param bool $item
     * @return mixed|null
     */
    function getConfigData($browser = false, $item = false)
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
     * @param double $normalize The "normalized", eg: 1.5
     * @return array|bool|mixed|string
     * @throws DomainException
     */
    public static function fetchVersion($fragment, $normalize)
    {
        $rawData = self::getRawData($fragment);

        $matches = self::getMatches($rawData);

        return self::parseVersion($matches, $fragment, $normalize);
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
     * @param string $rawData
     * @return mixed
     */
    public static function getMatches($rawData)
    {
        if (preg_match(self::WIKIPEDIA_PATTERN, $rawData, $matches) === false) {
            throw new DomainException("Unable to get matches.");
        }
        return $matches;
    }

    /**
     * @param string $fragment
     * @return mixed
     */
    private static function getRawData($fragment)
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
     * @param array $matches
     * @param string $fragment
     * @param int|float $normalize
     * @return array|string|string[]|null
     */
    private static function parseVersion($matches, $fragment, $normalize)
    {
        $version = $matches[1];

        if (empty($version)) {
            throw new DomainException("Missing version for $fragment.");
        }

        $version = preg_replace('/[^0-9\.]/', '', $version);

        if ($normalize) {
            return self::normalizeVersion($version, $normalize);
        }

        return $version;
    }
}
