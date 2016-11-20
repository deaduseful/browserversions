<?php

namespace Deaduseful\BrowserVersions;

/**
 * Class BrowserVersions
 * @package Deaduseful\BrowserVersions
 */
class BrowserVersions
{
    /**
     * The data file, details about the browsers.
     */
    var $dataFile = __DIR__ . '/browsers.json';
    /**
     * The cache file, the browser versions.
     */
    var $cacheFile = __DIR__ . 'versions.json';
    /**
     * The maximum age of the cache file.
     */
    var $maxAge = 3 * 7 * 24 * 60 * 60;
    /**
     * @var array The browser data.
     */
    var $data;

    /**
     * BrowserVersions constructor.
     */
    function __construct($force = false)
    {
        $this->updateCache($force);
    }

    /**
     * @param bool $force
     * @return bool
     */
    function updateCache($force = false)
    {
        $cacheFile = $this->getCacheFile();
        if (!is_file($cacheFile) ||
            !filesize($cacheFile) ||
            time() - filemtime($cacheFile) >= $this->getMaxAge() ||
            $force
        ) {
            $versions = is_file($cacheFile) ? json_decode(file_get_contents($cacheFile)) : array();
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
     * @return string
     */
    function fetchVersions($versions = array())
    {
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
        return json_encode($versions, true);
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
     * @param $fragment
     * @param $normalize
     * @return array|bool|mixed|string
     */
    function fetchVersion($fragment, $normalize)
    {
        if (!$fragment) {
            return false;
        }

        $url = 'http://en.wikipedia.org/w/api.php?action=query&prop=revisions&rvprop=content&format=php&titles=Template:Latest_stable_software_release/';
        $url .= $fragment;

        $raw_content = file_get_contents($url);

        $content = unserialize($raw_content);
        if ($content == $raw_content) {
            return false;
        }
        $page = array_pop($content['query']['pages']);
        $raw_data = explode("\n", $page['revisions'][0]['*']);

        $version = false;
        foreach ($raw_data as $data) {
            $data = trim($data, '| ');
            if (false !== strpos($data, 'Android') || false !== strpos($data, 'iOS'))
                continue;
            if (false !== strpos($data, 'Linux') && false === strpos($data, 'Mac OS X') && false === strpos($data, 'Windows') && false === strpos($data, 'Microsoft'))
                continue;
            if ((false !== $pos = strpos($data, 'latest_release_version')) || (false !== $pos = strpos($data, 'latest release version'))) {
                if ($pos)
                    $data = substr($data, $pos);
                $version = trim(str_replace(array('latest_release_version', 'latest release version', '='), '', $data), '| ') . " ";
                $version = str_replace("'''Mac OS X''' and '''Microsoft Windows'''<br />", '', $version);
                $version = str_replace("'''Windows 10'''<br>", '', $version);
                $version = substr($version, 0, strpos($version, ' '));
                break;
            }
        }

        if (false === $version) {
            return false;
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
            if ('0' !== $version[1])
                $return .= '.' . $version[1];
            return $return;
        }

        $return = array();
        for ($i = 0; $i < $normalize; $i++) {
            $return[] = $version[$i];
        }
        return implode('.', $return);
    }
}