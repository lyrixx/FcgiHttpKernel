<?php

namespace Igorw\FcgiHttpKernel;

use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpFoundation\ParameterBag;
use Symfony\Component\HttpFoundation\Cookie;
use Symfony\Component\HttpFoundation\FileBag;
use Symfony\Component\HttpFoundation\File\UploadedFile;
use Symfony\Component\HttpKernel\HttpKernelInterface;

class FcgiHttpKernel implements HttpKernelInterface
{
    private $rootDir;
    private $frontController;
    private $client;

    public function __construct(Client $client, $rootDir, $frontController = null)
    {
        $this->client = $client;
        $this->rootDir = $rootDir;
        $this->frontController = $frontController;
    }

    public function handle(Request $request, $type = HttpKernelInterface::MASTER_REQUEST, $catch = true)
    {
        $filename = $this->frontController ?: ltrim($request->getPathInfo(), '/');

        if (!file_exists($this->rootDir.'/'.$filename)) {
            return new Response('The requested file could not be found.', 404);
        }

        $requestBody = $this->getRequestBody($request);

        if (count($request->files)) {
            $boundary = $this->getMimeBoundary();
            $request->headers->set('Content-Type', 'multipart/form-data; boundary='.$boundary);
            $requestBody = $this->encodeMultipartFiles($boundary, $request->files);
        }

        $params = array(
            'SCRIPT_NAME' => '/'.$filename,
            'SCRIPT_FILENAME' => $this->rootDir.'/'.$filename,
            'PATH_INFO' => $request->getPathInfo(),
            'QUERY_STRING' => $request->getQueryString(),
            'REQUEST_URI' => $request->getRequestUri(),
            'REQUEST_METHOD' => $request->getMethod(),
            'CONTENT_LENGTH' => strlen($requestBody),
            'CONTENT_TYPE' => $request->headers->get('Content-Type'),
            'SYMFONY_ATTRIBUTES' => serialize($request->attributes->all()),
        );

        foreach ($request->headers->all() as $name => $values) {
            $name = 'HTTP_'.strtoupper(str_replace('-', '_', $name));
            $params[$name] = array_shift($values);
        }

        $cookie = $this->getUrlEncodedParameterBag($request->cookies);
        $params['HTTP_COOKIE'] = $cookie;

        $response = $this->client->request($params, false);

        list($headerList, $body) = explode("\r\n\r\n", $response, 2);
        $headerMap = $this->getHeaderMap($headerList);
        $cookies = $this->getCookies($headerMap);

        $headers = $this->flattenHeaderMap($headerMap);
        unset($headers['Cookie']);
        $status = $this->getStatusCode($headers);

        $response = new Response($body, $status, $headers);
        foreach ($cookies as $cookie) {
            $response->headers->setCookie($cookie);
        }
        return $response;
    }

    private function getRequestBody(Request $request)
    {
        return $request->getContent() ?: $this->getUrlEncodedParameterBag($request->request);
    }

    private function getStatusCode(array $headers)
    {
        if (isset($headers['Status'])) {
            list($code) = explode(' ', $headers['Status']);
            return (int) $code;
        }

        return 200;
    }

    private function getHeaderMap($headerListRaw)
    {
        if (0 === strlen($headerListRaw)) {
            return array();
        }

        $headerMap = array();

        $headerList  = preg_replace('~\r\n[\t ]~', ' ', $headerListRaw);
        $headerLines = explode("\r\n", $headerList);
        foreach ($headerLines as $headerLine) {
            if (false === strpos($headerLine, ':')) {
                throw new \RuntimeException('Unable to parse header line, name missing');
            }

            list($name, $value) = explode(':', $headerLine, 2);

            $name  = implode('-', array_map('ucwords', explode('-', $name)));
            $value = trim($value, "\t ");

            $headerMap[$name][] = $value;
        }

        return $headerMap;
    }

    private function flattenHeaderMap(array $headerMap)
    {
        $flatHeaderMap = array();
        foreach ($headerMap as $name => $values) {
            $flatHeaderMap[$name] = implode(', ', $values);
        }
        return $flatHeaderMap;
    }

    private function getCookies(array $headerMap)
    {
        if (!isset($headerMap['Set-Cookie'])) {
            return array();
        }

        return array_map(
            array($this, 'cookieFromResponseHeaderValue'),
            $headerMap['Set-Cookie']
        );
    }

    private function cookieFromResponseHeaderValue($value)
    {
        $cookieParts = preg_split('/;\s?/', $value);
        $cookieMap = array();
        foreach ($cookieParts as $part) {
            preg_match('/(\w+)(?:=(.*)|)/', $part, $capture);
            $name = $capture[1];
            $value = isset($capture[2]) ? $capture[2] : '';

            $cookieMap[$name] = $value;
        }

        $firstKey = key($cookieMap);

        $cookieMap = array_merge($cookieMap, array(
            'secure'    => isset($cookieMap['secure']),
            'httponly'  => isset($cookieMap['httponly']),
        ));

        $cookieMap = array_merge(array(
            'expires' => 0,
            'path' => '/',
            'domain' => null,
        ), $cookieMap);

        return new Cookie(
            $firstKey,
            $cookieMap[$firstKey],
            $cookieMap['expires'],
            $cookieMap['path'],
            $cookieMap['domain'],
            $cookieMap['secure'],
            $cookieMap['httponly']
        );
    }

    private function getUrlEncodedParameterBag(ParameterBag $bag)
    {
        return http_build_query($bag->all());
    }

    private function encodeMultipartFiles($boundary, FileBag $files)
    {
        $mimeBoundary = '--'.$boundary."\r\n";

        $data = '';
        foreach ($files->all() as $name => $file) {
            $data .= $mimeBoundary;
            $data .= $this->encodeMultipartFile($name, $file);
            $data .= $mimeBoundary;
        }
        $data .= "\r\n";

        return $data;
    }

    private function encodeMultipartFile($name, UploadedFile $file)
    {
        $eol = "\r\n";

        $content = file_get_contents($file);

        $data = '';
        $data .= sprintf('Content-Disposition: form-data; name="%s"; filename="%s"'.$eol,
                         $name,
                         $file->getClientOriginalName());
        $data .= sprintf('Content-Type: %s'.$eol,
                         $file->getClientMimeType());
        $data .= 'Content-Transfer-Encoding: base64'.$eol.$eol;
        $data .= chunk_split(base64_encode($content)).$eol;

        return $data;
    }

    private function getMimeBoundary()
    {
        return md5('cgi-http-kernel');
    }
}
