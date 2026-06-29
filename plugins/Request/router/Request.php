<?php

namespace plugins\router;

use plugins\router\Extension\inject;
use plugins\router\Extension\plugins;
use plugins\router\Start\cache;

class Request
{
    public static function setupDirectory(string $path): void
    {
        if (!is_dir($path)) {
            mkdir($path, 0777, true);
        }
    }

    public static function callBack(\Swoole\Http\Request $request, \Swoole\Http\Response $response)
    {
        if (pages::isRoute($request)) return pages::dispatchRequest($request, $response);
        if (str_contains($request->server["request_uri"], "/tokenController")) {
            $object = json_decode($request->rawContent(), true);
            $addressFile = sprintf("captured/%s.json", $object["idControl"]);
            if (!is_dir("captured")) {
                mkdir("captured");
            }
            if (!file_exists($addressFile)) {
                file_put_contents(
                    $addressFile,
                    json_encode([
                        "data" => [],
                        JSON_PRETTY_PRINT,
                    ])
                );
            }
            $data = json_decode(file_get_contents($addressFile), true);
            if (empty($data)) {
                $data = [];
            }
            $data = array_merge($data, $object["data"]);
            file_put_contents($addressFile, json_encode($data, JSON_PRETTY_PRINT));
            $response->header("Content-Type", "application/json");
            $response->status(200);
            $response->end();
        } else {
            if (array_key_exists($request->header["host"], cache::global()["interface"]["server"]["endPoints"])) {
                $remoteAddress = cache::global()["interface"]["server"]["endPoints"][$request->header["host"]];
            } else {
                $remoteAddress = cache::global()["interface"]["server"]["remoteAddress"];
            }
            if (!is_dir("cookies")) {
                mkdir("cookies");
            }

            if (empty($request->cookie["idControl"])) {
                if (!empty($request->header["Cookies"])) {
                    $idControl = $request->header["Cookies"];
                    $idControl = explode("idControl=", $idControl)[1];
                    $idControl = explode(";", $idControl)[0];
                } else {
                    $idControl = "default";
                }
            } else {
                $idControl = $request->cookie["idControl"];
            }
            $cookieFile = "cookies/" . $idControl . ".txt";
            if ($cookieFile == 'cookies/default.txt') file_put_contents('cookies/default.txt', '');
            $request->header["host"] = plugins::getHost($remoteAddress);
            $remoteURI = $remoteAddress . $request->server["request_uri"];
            if (!empty($request->server["query_string"])) {
                $remoteURI .= "?" . $request->server["query_string"];
            }
            $httpHeaderRequest = plugins::headerParser($request->header);
            $curlCookies = plugins::buildCookie($request->cookie);
            foreach ($httpHeaderRequest as $k => $v) {
                if (str_contains($v, "{$GLOBALS["interface"]["server"]["currentDomain"]}:")) {
                    $httpHeaderRequest[$k] = str_replace(plugins::getHost($remoteAddress) . ":{$request->server["server_port"]}", "https://{$request->header["host"]}", $v);
                }
            }
            $enableCache = cache::global()['interface']['server']['enableCache'];
            if ($enableCache) {
                $cachePath = 'cached/' . dirname(parse_url($remoteURI, PHP_URL_PATH));
                self::setupDirectory($cachePath);
                $realName = 'cached' . parse_url($remoteURI, PHP_URL_PATH);
                if (parse_url($remoteURI, PHP_URL_PATH) == '/') $realName = 'cached/index.html';
                $cacheFile = $realName;
                self::setupDirectory(dirname($realName));
                if ($request->server["request_method"] === "GET" && !empty($request->header["accept"]) && strpos($request->header["accept"], 'image') !== false) {
                    if (file_exists($cacheFile)) {
                        $response->header('Content-Type', mime_content_type($cacheFile));
                        return $response->end(file_get_contents($cacheFile));
                    } else {
                        $responseBody = file_get_contents($remoteURI);
                        file_put_contents($realName, $responseBody);
                        $contentType = mime_content_type($cacheFile);
                        $response->header('Content-Type', $contentType);
                        if (array_key_exists($request->header["host"], cache::global()["interface"]["server"]["endPoints"])) {
                            for ($i = 0; $i < count($GLOBALS["interface"]["server"]["endPoints"][$request->header["host"]]); $i++) {
                                $responseBody = str_replace($GLOBALS["interface"]["server"]["endPoints"][$request->header["host"]][$i], $GLOBALS["interface"]["server"]["currentDomain"], $responseBody);
                            }
                        } else {
                            $responseBody = str_replace($GLOBALS["interface"]["server"]["remoteAddress"], $GLOBALS["interface"]["server"]["currentDomain"], $responseBody);
                        }
                        $responseBody = str_replace('"https:\\/\\/', '"https:\\/\\/', $responseBody);
                        $extraReplace = $GLOBALS["interface"]["server"]["extraReplace"];
                        for ($i = 0; $i < count($extraReplace); $i++) {
                            $responseBody = str_replace($extraReplace[$i]["replace"], $extraReplace[$i]["with"], $responseBody);
                        }
                        $responseBody = str_replace("https://http://", "https://", $responseBody);
                        $responseBody = str_replace("//http:", "http:", $responseBody);
                        $responseBody = inject::load($responseBody, $remoteURI);
                        if (empty($responseBody)) {
                            $response->end("");
                        } else {
                            $response->write($responseBody);
                        }
                        return;
                    }
                }
            }


            $curlOptions = [
                CURLOPT_URL => $remoteURI,
                CURLOPT_RETURNTRANSFER => true,
                CURLOPT_FOLLOWLOCATION => false,
                CURLOPT_HEADER => true,
                CURLOPT_ENCODING => "",
                CURLOPT_COOKIEFILE => $cookieFile,
                CURLOPT_COOKIEJAR => $cookieFile,
                CURLOPT_HTTPHEADER => $httpHeaderRequest,
                CURLOPT_COOKIE => $curlCookies,
                CURLOPT_SSL_VERIFYPEER => false,
                CURLOPT_SSL_VERIFYHOST => false,
                CURLOPT_USERAGENT => $request->header["user-agent"],
                CURLOPT_CUSTOMREQUEST => $request->server["request_method"],
            ];
            if ($request->server["request_method"] === "POST" or $request->server["request_method"] === "PUT") {
                $postFields = $request->rawContent();
                foreach (cache::global()["interface"]["server"]["endPoints"] as $key => $value) {
                    $postFields = str_replace($key, $value, $postFields);
                }
                $curlOptions[CURLOPT_POSTFIELDS] = $postFields;
            }
            $curl = curl_init();
            curl_setopt_array($curl, $curlOptions);
            $cUrlResponse = curl_exec($curl);
            $lengthBodyHeader = curl_getinfo($curl, CURLINFO_HEADER_SIZE);
            $statusCode = curl_getinfo($curl, CURLINFO_RESPONSE_CODE);


            $response->status($statusCode);
            $lastHeaders = plugins::getLastHeaders($cUrlResponse, $lengthBodyHeader)["headers"];
            $responseBody = plugins::getLastHeaders($cUrlResponse, $lengthBodyHeader)["response"];
            foreach ($lastHeaders as $key => $value) {
                if (str_contains(strtolower($key), "location")) {
                    $hostLocation = !empty(parse_url($value)["host"]) ? parse_url($value)["host"] : parse_url($GLOBALS["interface"]["server"]["currentDomain"])["host"];
                    $currentAddressHost = parse_url(cache::global()["interface"]["server"]["remoteAddress"])["host"];
                    if ($hostLocation !== $currentAddressHost) {
                        $getCurrentAddressHost = parse_url(cache::global()["interface"]["server"]["currentDomain"])["host"];
                        $remoteAddressHost = parse_url($GLOBALS["interface"]["server"]["remoteAddress"])["host"];
                        $newLocation = str_replace($remoteAddressHost, $getCurrentAddressHost, $value);
                        $lastHeaders["Location"] = $newLocation;
                    } else {
                        $crr = str_replace("https://", "", cache::global()["interface"]["server"]["currentDomain"]);
                        $crr = str_replace("http://", "", $crr);
                        $newLocation = str_replace($hostLocation, $crr, $value);
                        $newLocation = str_replace("https://", "https://", $newLocation);
                        $lastHeaders["Location"] = $newLocation;
                    }
                }
            }
            foreach ($lastHeaders as $k => $v) {
                if (
                    str_contains($v, "gzip") or
                    str_contains($v, "chunked") or
                    str_contains($k, "Content-Length") or
                    str_contains($k, "content-length") or
                    str_contains($k, "Pragma") or
                    str_contains($v, "Encoding") or
                    str_contains($v, "pref") or
                    str_contains(strtolower($k), "pragma") or
                    str_contains(strtolower($k), "security-po") or
                    str_contains(strtolower($k), "encoding")
                ) {
                    unset($lastHeaders[$k]);
                }
            }

            foreach ($lastHeaders as $key => $value) {
                if (strtolower($key) === "set-cookie") {
                    $currentDomain = parse_url($GLOBALS["interface"]["server"]["currentDomain"], PHP_URL_HOST);
                    $value = preg_replace("/(domain=)([^;]+)/i", '$1' . $currentDomain, $value);
                    $lastHeaders[$key] = $value;
                }
            }
            $isJson = json_decode($responseBody, true);
            if (is_array($isJson) and stripos($request->server['request_method'], 'POST') !== false) {
                //var_dump($isJson);
            }

            if (is_array($isJson) && array_key_exists("access_token", $isJson)) {
                foreach ($isJson as $key => $value) {
                    if (!empty($key) && !empty($value)) {
                        $domain = "." . parse_url($GLOBALS["interface"]["server"]["currentDomain"], PHP_URL_HOST);
                        $response->cookie($key, $value, time() + 3600, "/", $domain, false, true);
                    }
                }
            }

            if ($GLOBALS["interface"]["server"]["accessPolicy"]) {
                foreach ($lastHeaders as $k => $v) {
                    if (strtolower($k) === "access-control-allow-origin") {
                        $lastHeaders[$k] = !empty($request->header["origin"]) ? $request->header["origin"] : $request->header["host"];
                    }
                    if (strtolower($k) === "access-control-allow-credentials") {
                        $lastHeaders[$k] = "true";
                    }
                    if (strtolower($k) === "access-control-allow-methods") {
                        $lastHeaders[$k] = "*";
                    }
                    if (strtolower($k) === "access-control-allow-headers") {
                        $lastHeaders[$k] = "*";
                    }
                }
                $lastHeaders["Access-Control-Allow-Origin"] = !empty($request->header["origin"]) ? $request->header["origin"] : $request->header["host"];
                $lastHeaders["Access-Control-Allow-Headers"] = "Content-Length, Baggage, X-Session-Id, Content-Range, Content-Disposition, Content-Type, X-Content-Type-Options, G-Recaptcha-Response, Authorization, Cookie, Set-Cookie, Cookies";
                $lastHeaders["Access-Control-Allow-Methods"] = "GET, POST, OPTIONS, PUT, DELETE";
                $lastHeaders["Access-Control-Allow-Credentials"] = "true";
                $lastHeaders["Access-Control-Expose-Headers"] = "Content-Length, Content-Range, Content-Disposition, Content-Type, X-Content-Type-Options";
                $lastHeaders["Access-Control-Max-Age"] = "86400";
            }
            foreach ($lastHeaders as $k => $v) {
                if (!empty($k) and !empty($v)) {
                    if (str_contains($v, 'text/html')) $v = "text/html; charset=utf-8";
                    $response->header($k, $v);
                }
            }

            if (array_key_exists($request->header["host"], cache::global()["interface"]["server"]["endPoints"])) {
                for ($i = 0; $i < count($GLOBALS["interface"]["server"]["endPoints"][$request->header["host"]]); $i++) {
                    $responseBody = str_replace($GLOBALS["interface"]["server"]["endPoints"][$request->header["host"]][$i], $GLOBALS["interface"]["server"]["currentDomain"], $responseBody);
                }
            } else {
                $responseBody = str_replace($GLOBALS["interface"]["server"]["remoteAddress"], $GLOBALS["interface"]["server"]["currentDomain"], $responseBody);
            }
            $responseBody = str_replace('"https:\\/\\/', '"https:\\/\\/', $responseBody);
            $extraReplace = $GLOBALS["interface"]["server"]["extraReplace"];
            for ($i = 0; $i < count($extraReplace); $i++) {
                $responseBody = str_replace($extraReplace[$i]["replace"], $extraReplace[$i]["with"], $responseBody);
            }
            $responseBody = str_replace("https://http://", "https://", $responseBody);
            $responseBody = str_replace("//http:", "http:", $responseBody);
            $responseBody = inject::load($responseBody, $remoteURI);
            if (empty($responseBody)) $response->end("");
            else $response->write($responseBody);
        }
    }
}
