<?php

namespace HughCube\PUrl\Tests;

use HughCube\PUrl\Exceptions\ExceptionInterface;
use HughCube\PUrl\Exceptions\InvalidArgumentException;
use HughCube\PUrl\Url;
use PHPUnit\Framework\TestCase;
use Psr\Http\Message\UriInterface;

class UrlTest extends TestCase
{
    public function testInstanceOfPsrUriInterface()
    {
        $url = Url::instance();

        $this->assertInstanceOf(UriInterface::class, $url);
    }

    public function testBadStringUrl()
    {
        try {
            Url::instance('php.net');
        } catch (\Throwable $exception) {
            $exception = null;
        }

        $this->assertInstanceOf(ExceptionInterface::class, $exception);
        $this->assertInstanceOf(InvalidArgumentException::class, $exception);
        $this->assertInstanceOf(\InvalidArgumentException::class, $exception);
    }

    /**
     * @dataProvider dataProviderUrl
     */
    public function testStringUrl($string)
    {
        $url = Url::instance($string);
        $this->assertEquals($string, $url->toString());
    }

    /**
     * @dataProvider dataProviderUrl
     */
    public function testPsrUrl($string)
    {
        $url = Url::instance($string);
        $url = Url::instance($url);

        $this->assertEquals($string, $url->toString());
    }

    /**
     * @dataProvider dataProviderUrl
     */
    public function testArrayUrl($string)
    {
        $parts = parse_url($string);
        $url = Url::instance($parts);

        $this->assertEquals($string, $url->toString());
    }

    /**
     * @dataProvider dataProviderUrl
     */
    public function testGetScheme($string)
    {
        $scheme = parse_url($string, PHP_URL_SCHEME);
        $url = Url::instance($string);
        $this->assertEquals($scheme, $url->getScheme());
    }

    /**
     * @dataProvider dataProviderUrl
     */
    public function testGetter(
        $string,
        $scheme,
        $authority,
        $userInfo,
        $user,
        $pass,
        $host,
        $port,
        $path,
        $query,
        $queryArray,
        $fragment
    ) {
        foreach ([
                     Url::instance($string),
                     Url::instance(Url::instance($string)),
                     Url::instance(parse_url(Url::instance($string)->toString()))
                 ] as $url
        ) {
            $this->assertEquals($string, $url->toString());
            $this->assertEquals($string, strval($url));
            $this->assertEquals($scheme, $url->getScheme());
            $this->assertEquals($authority, $url->getAuthority());
            $this->assertEquals($userInfo, $url->getUserInfo());
            $this->assertEquals($user, $url->getUser());
            $this->assertEquals($pass, $url->getPass());
            $this->assertEquals($host, $url->getHost());
            $this->assertEquals($port, $url->getPort());
            $this->assertEquals($path, $url->getPath());
            $this->assertEquals($port, $url->getPort());
            $this->assertEquals($query, $url->getQuery());
            $this->assertEquals($queryArray, $url->getQueryArray());
            $this->assertEquals($fragment, $url->getFragment());
        }
    }

    /**
     * @return array
     */
    public function dataProviderUrl()
    {
        return [
            [
                'url' => 'https://www.google.com/search?q=test&oq=test&sourceid=chrome&ie=UTF-8',
                'scheme' => 'https',
                'authority' => 'www.google.com',
                'userInfo' => '',
                'user' => '',
                'pass' => '',
                'host' => 'www.google.com',
                'port' => 443,
                'path' => '/search',
                'query' => 'q=test&oq=test&sourceid=chrome&ie=UTF-8',
                'queryArray' => ['q' => 'test', 'oq' => 'test', 'sourceid' => 'chrome', 'ie' => 'UTF-8'],
                'fragment' => '',
            ],
            [
                'url' => 'https://www.google.com/search?q=%E4%BD%A0%E5%A5%BD%E5%91%80&oq=%E4%BD%A0%E5%A5%BD%E5%91%80&aqs=chrome..69i57j0l5.4993j0j7&sourceid=chrome&ie=UTF-8#test',
                'scheme' => 'https',
                'authority' => 'www.google.com',
                'userInfo' => '',
                'user' => '',
                'pass' => '',
                'host' => 'www.google.com',
                'port' => 443,
                'path' => '/search',
                'query' => 'q=%E4%BD%A0%E5%A5%BD%E5%91%80&oq=%E4%BD%A0%E5%A5%BD%E5%91%80&aqs=chrome..69i57j0l5.4993j0j7&sourceid=chrome&ie=UTF-8',
                'queryArray' => [
                    'q' => '你好呀',
                    'oq' => '你好呀',
                    'aqs' => 'chrome..69i57j0l5.4993j0j7',
                    'sourceid' => 'chrome',
                    'ie' => 'UTF-8'
                ],
                'fragment' => 'test',
            ]
        ];
    }
}
