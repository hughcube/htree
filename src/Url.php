<?php

namespace HughCube\PUrl;

use HughCube\PUrl\Exceptions\InvalidArgumentException;
use Psr\Http\Message\UriInterface;

class Url implements UriInterface
{
    private $schemes = [
        'http' => 80,
        'https' => 443,
    ];

    /**
     * @var string|null url scheme
     */
    private $scheme;

    /**
     * @var string|null url host
     */
    private $host;

    /**
     * @var integer|null url port
     */
    private $port;

    /**
     * @var string|null url user
     */
    private $user;

    /**
     * @var string|null url pass
     */
    private $pass;

    /**
     * @var string|null url path
     */
    private $path;

    /**
     * @var string|null url query string
     */
    private $query;

    /**
     * @var string|null url fragment
     */
    private $fragment;

    /**
     * 获取实例
     *
     * @param null|UriInterface $url
     * @return static
     */
    public static function instance($url = null)
    {
        return new static($url);
    }

    /**
     * Url constructor.
     * @param null $url
     */
    protected function __construct($url = null)
    {
        if (null === $url) {
            return;
        }

        if ($url instanceof UriInterface) {
            $this->parsePsrUrl($url);
        } elseif (is_string($url)) {
            $this->parseStringUrl($url);
        } elseif (is_array($url)) {
            $this->parseArrayUrl($url);
        }
    }

    /**
     * 解析 Psr 标准库的url
     *
     * @param UriInterface $url
     */
    private function parsePsrUrl(UriInterface $url)
    {
        $this->scheme = (null == ($_ = $url->getScheme())) ? null : $_;
        $this->host = (null == ($_ = $url->getHost())) ? null : $_;
        $this->port = (null == ($_ = $url->getPort())) ? null : $_;
        $this->path = (null == ($_ = $url->getPath())) ? null : $_;
        $this->query = (null == ($_ = $url->getQuery())) ? null : $_;
        $this->fragment = (null == ($_ = $url->getFragment())) ? null : $_;

        $user = $this->getUserInfo();
        $user = explode(':', $user);
        $this->user = (is_array($user) && isset($user[0])) ? $user[0] : null;
        $this->pass = (is_array($user) && isset($user[1])) ? $user[1] : null;
    }

    /**
     * 解析字符串url
     *
     * @param $url
     */
    private function parseStringUrl($url)
    {
        if (!static::isUrlString($url)) {
            throw new InvalidArgumentException('the parameter must be a url');
        }

        $parts = parse_url($url);
        $this->parseArrayUrl($parts);
    }

    /**
     * 解析数组url
     *
     * @param $parts
     */
    private function parseArrayUrl($parts)
    {
        $this->scheme = isset($parts['scheme']) ? $parts['scheme'] : null;
        $this->host = isset($parts['host']) ? $parts['host'] : null;
        $this->port = isset($parts['port']) ? $parts['port'] : null;
        $this->user = isset($parts['user']) ? $parts['user'] : null;
        $this->pass = isset($parts['pass']) ? $parts['pass'] : null;
        $this->path = isset($parts['path']) ? $parts['path'] : null;
        $this->query = isset($parts['query']) ? $parts['query'] : null;
        $this->fragment = isset($parts['fragment']) ? $parts['fragment'] : null;
    }

    /**
     * 填充 Psr 标准库的url
     *
     * @param UriInterface $url
     * @return UriInterface
     */
    public function fillPsrUri(UriInterface $url)
    {
        return $url->withScheme($this->getScheme())
            ->withUserInfo($this->getUser(), $this->getPass())
            ->withHost($this->getHost())
            ->withPort($this->getPort())
            ->withPath($this->getPath())
            ->withQuery($this->getQuery())
            ->withFragment($this->getFragment());
    }

    /**
     * @inheritDoc
     */
    public function getScheme()
    {
        return strval($this->scheme);
    }

    /**
     * @inheritDoc
     */
    public function getAuthority()
    {
        $authority = $host = $this->getHost();
        if (empty($host)) {
            return $authority;
        }

        $userInfo = $this->getUserInfo();
        if (!empty($userInfo)) {
            $authority = "{$userInfo}@{$authority}";
        }

        $port = $this->getPort();
        if ($this->isNonStandardPort() && !empty($port)) {
            $authority = "{$authority}:{$port}";
        }

        return $authority;
    }

    /**
     * @inheritDoc
     */
    public function getUserInfo()
    {
        $userInfo = $user = $this->getUser();
        if (empty($user)) {
            return $userInfo;
        }

        $pass = $this->getPass();
        if (!empty($pass)) {
            $userInfo = "{$userInfo}:{$pass}";
        }

        return $userInfo;
    }

    /**
     * 获取 url user
     *
     * @return string
     */
    public function getUser()
    {
        return strval($this->user);
    }

    /**
     * 获取 url pass
     *
     * @return string
     */
    public function getPass()
    {
        return strval($this->pass);
    }

    /**
     * @inheritDoc
     */
    public function getHost()
    {
        return strval($this->host);
    }

    /**
     * @inheritDoc
     */
    public function getPort()
    {
        if (!empty($this->port)) {
            return $this->port;
        }

        $scheme = $this->getScheme();
        if (empty($scheme)) {
            return null;
        }

        return isset($this->schemes[$scheme]) ? $this->schemes[$scheme] : null;
    }

    /**
     * @inheritDoc
     */
    public function getPath()
    {
        if (empty($this->path)) {
            return '';
        }

        return '/' === substr($this->path, 0, 1) ? $this->path : "/{$this->path}";
    }

    /**
     * @inheritDoc
     */
    public function getQuery()
    {
        return strval($this->query);
    }

    /**
     * 获取query数组
     *
     * @return array
     */
    public function getQueryArray()
    {
        $query = $this->getQuery();

        $queryArray = [];
        if (!empty($query)) {
            parse_str($query, $queryArray);
        }

        return is_array($queryArray) ? $queryArray : [];
    }

    /**
     * 是否存在query的key
     *
     * @return array
     */
    public function hasQueryKey($key)
    {
        $queryArray = $this->getQueryArray();

        return array_key_exists($key, $queryArray);
    }

    /**
     * 是否存在query的key
     *
     * @return array
     */
    public function getQueryValue($key, $default = null)
    {
        $queryArray = $this->getQueryArray();

        return array_key_exists($key, $queryArray) ? $queryArray[$key] : $default;
    }

    /**
     * @inheritDoc
     */
    public function getFragment()
    {
        return strval($this->fragment);
    }

    /**
     * Return the string representation as a URI reference.
     *
     * @return string
     */
    public function toString()
    {
        $url = '';

        $scheme = $this->getScheme();
        if (!empty($scheme)) {
            $url = "{$scheme}://{$url}";
        }

        $authority = $this->getAuthority();
        if (!empty($authority)) {
            $url = "{$url}{$authority}";
        }

        $path = $this->getPath();
        if (!empty($path)) {
            $url = "{$url}{$path}";
        }

        $query = $this->getQuery();
        if (!empty($query)) {
            $url = "{$url}?{$query}";
        }

        $fragment = $this->getFragment();
        if (!empty($fragment)) {
            $url = "{$url}#{$fragment}";
        }

        return $url;
    }

    /**
     * @inheritDoc
     */
    public function withScheme($scheme)
    {
        $new = clone $this;
        $new->scheme = $scheme;

        return $new;
    }

    /**
     * @inheritDoc
     */
    public function withUserInfo($user, $password = null)
    {
        $new = clone $this;
        $new->user = $user;
        $new->pass = $password;

        return $new;
    }

    /**
     * @inheritDoc
     */
    public function withHost($host)
    {
        $new = clone $this;
        $new->host = $host;

        return $new;
    }

    /**
     * @inheritDoc
     */
    public function withPort($port)
    {
        $new = clone $this;
        $new->port = $port;

        return $new;
    }

    /**
     * @inheritDoc
     */
    public function withPath($path)
    {
        $new = clone $this;
        $new->path = $path;

        return $new;
    }

    /**
     * @inheritDoc
     */
    public function withQuery($query)
    {
        $new = clone $this;
        $new->query = $query;

        return $new;
    }

    /**
     * Return an instance with the specified query array.
     *
     * @param array $queryArray
     * @return static
     */
    public function withQueryArray(array $queryArray)
    {
        return $this->withQuery(http_build_query($queryArray));
    }

    /**
     * Create a new URI with a specific query string value removed.
     *
     * @param $key
     * @return static
     */
    public function withoutQueryValue($key)
    {
        $queryArray = $this->getQueryArray();

        if (isset($queryArray[$key])) {
            unset($queryArray[$key]);
        }

        return $this->withQueryArray($queryArray);
    }

    /**
     * Create a new URI with a specific query string value.
     *
     * @param string $key
     * @param string|integer $value
     * @return static
     */
    public function withQueryValue($key, $value)
    {
        $queryArray = $this->getQueryArray();
        $queryArray[$key] = $value;

        return $this->withQueryArray($queryArray);
    }

    /**
     * @inheritDoc
     */
    public function withFragment($fragment)
    {
        $new = clone $this;
        $new->fragment = $fragment;

        return $new;
    }

    /**
     * @inheritDoc
     */
    public function __toString()
    {
        return $this->toString();
    }

    /**
     * Is a given port non-standard for the current scheme?
     *
     * @return bool
     */
    private function isNonStandardPort()
    {
        if (!$this->scheme && $this->port) {
            return true;
        }

        if (!$this->host || !$this->port) {
            return false;
        }

        return !isset($this->schemes[$this->scheme])
            || $this->port !== $this->schemes[$this->scheme];
    }

    /**
     * is url string
     *
     * @param $url
     * @return bool
     */
    public static function isUrlString($url)
    {
        return false !== filter_var($url, FILTER_VALIDATE_URL);
    }
}
