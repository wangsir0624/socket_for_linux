<?php
namespace Protocol;

interface ProtocolInterface {
    /**
     * 将协议的请求数据解析成数组形式
     * @param string $request
     * @return array
     */
    static function parseRequest($request);

    /**
     * 将协议的响应数据解析成数组形式
     * @param string $response
     * @return array
     */
    static function parseResponse($response);

    /**
     * 将数组形式的请求转换成字符串供套接字发送
     * @param array $arrayRequest
     * @return string
     */
    static function toRequestString($arrayRequest);

    /**
     * 将数组形式的响应转换成字符串供套接字发送
     * @param array $arrayResponse
     * @return string
     */
    static function toResponseString($arrayResponse);
}