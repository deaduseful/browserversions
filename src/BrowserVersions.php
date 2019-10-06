<?php

namespace Deaduseful\BrowserVersions;

use Exception;
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
    var $dataFile = __DIR__ . '/browsers.json';

    /**
     * The cache file, the browser versions.
     */
    var $cacheFile = 'versions.json';

    /**
     * The maximum age of the cache file. Default is 3 months which is half of the expected browser release cycle.
     */
    var $maxAge = 3 * 7 * 24 * 60 * 60;

    /**
     * @var array The browser data.
     */
    var $data;

    /**
     * BrowserVersions constructor.
     *
     * @param bool $force
     * @throws Exception
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
     * @throws Exception
     */
    function updateCache($force = false)
    {
        $cacheFile = $this->getCacheFile();
        if ($force ||
            is_file($cacheFile) === false ||
            filesize($cacheFile) === 0 ||
            time() - filemtime($cacheFile) >= $this->getMaxAge()
        ) {
            $versions = is_file($cacheFile) ? json_decode(file_get_contents($cacheFile)) : [];
            if (is_array($versions) === false) {
                throw new Exception('Unable to parse cache file.');
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
     * @throws Exception
     */
    function fetchVersions($versions = [])
    {
        if (!is_array($versions)) {
            throw new UnexpectedValueException('Expected array.');
        }
        $this->setData();
        $data = $this->getData();
        foreach ($data as $key => $name) {
            $browser = $key;
            $fragment = $this->getData($browser, 'wikipedia');
            $normalize = $this->getData($browser, 'normalized');
            $version = $this->fetchVersion($fragment, $normalize);
            if ($version) {
                $versions[$key] = $version;
            }
        }
        return $versions;
    }

    /**
     *
     */
    private function setData()
    {
        $this->data = json_decode(file_get_contents($this->getDataFile()), 1);
    }

    /**
     * @return mixed
     */
    public function getDataFile()
    {
        return $this->dataFile;
    }

    /**
     * @param mixed $dataFile
     */
    public function setDataFile($dataFile)
    {
        $this->dataFile = $dataFile;
    }

    /**
     * @param bool $browser
     * @param bool $item
     * @return mixed|null
     */
    function getData($browser = false, $item = false)
    {
        if ($browser) {
            if ($item) {
                return $this->data[$browser][$item];
            }
            return $this->data[$browser];
        }
        return $this->data;
    }

    /**
     * Fetch browser version from Wikipedia.
     *
     * @param $fragment string The "wikipedia" fragment, eg: Firefox.
     * @param $normalize double The "normalized", eg: 1.5
     * @return array|bool|mixed|string
     * @throws Exception
     */
    function fetchVersion($fragment, $normalize)
    {
        $url = self::WIKIPEDIA_URL . $fragment;

        $raw_content = file_get_contents($url);

        $content = unserialize($raw_content);
        if ($content == $raw_content) {
            throw new Exception('Invalid content.');
        }
        $page = array_pop($content['query']['pages']);
        $raw_data = $page['revisions'][0]['*'];

        $version = false;
        if (preg_match(self::WIKIPEDIA_PATTERN, $raw_data, $matches)) {
            $version = $matches[1];
        }

        if (empty($version)) {
            throw new Exception("Missing version for $fragment.");
        }

        $version = preg_replace('/[^0-9\.]/', '', $version);

        if ($normalize) {
            return $this->normalizeVersion($version, $normalize);
        }

        return $version;
    }

    /**
     * @param $version
     * @param $normalize
     * @return array|string
     */
    function normalizeVersion($version, $normalize)
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
}