<?php
declare(strict_types=1);

namespace app\service;

use app\service\LogService;

/**
 * 企业微信消息加解密工具
 * 基于官方 PHP SDK 精简封装
 */
class WxWorkCrypto
{
    private string $token;
    private string $encodingAESKey;
    private string $corpId;

    public function __construct(string $token, string $encodingAESKey, string $corpId)
    {
        $this->token          = $token;
        $this->encodingAESKey = $encodingAESKey;
        $this->corpId         = $corpId;
    }

    /**
     * 验证并解密企业微信推送来的消息
     *
     * @param string $msgSignature  企业微信签名
     * @param string $timestamp     时间戳
     * @param string $nonce         随机字符串
     * @param string $postData      POST 原始 XML 数据
     * @return array 解析后的事件数组
     */
    public function decryptMessage(
        string $msgSignature,
        string $timestamp,
        string $nonce,
        string $postData
    ): array {
        // 1. 从 XML 取出加密消息
        libxml_use_internal_errors(true);
        $xml = simplexml_load_string($postData, 'SimpleXMLElement', LIBXML_NOCDATA);
        if ($xml === false) {
            throw new \InvalidArgumentException('XML 解析失败');
        }

        $encrypt = (string)$xml->Encrypt;

        // 2. 验证签名
        $this->verifySignature($msgSignature, $timestamp, $nonce, $encrypt);

        // 3. AES 解密
        $decrypted = $this->aesDecrypt($encrypt);

        // 4. 解析解密后的 XML
        $innerXml = simplexml_load_string($decrypted, 'SimpleXMLElement', LIBXML_NOCDATA);
        if ($innerXml === false) {
            throw new \InvalidArgumentException('解密后 XML 解析失败');
        }

        return json_decode(json_encode($innerXml), true);
    }

    /**
     * 验证企业微信 GET 请求（URL 接入验证）
     * 企业微信会用加密模式推送 echostr，需要解密后原样返回
     */
    public function verifyUrl(
        string $msgSignature,
        string $timestamp,
        string $nonce,
        string $echoStr
    ): string {
        $this->verifySignature($msgSignature, $timestamp, $nonce, $echoStr);
        // echoStr 本身就是加密后的字符串，解密得到明文随机串
        return $this->aesDecrypt($echoStr);
    }

    // ─────────────────────────────────────────────
    // 私有方法
    // ─────────────────────────────────────────────

    private function verifySignature(
        string $signature,
        string $timestamp,
        string $nonce,
        string $encrypt
    ): void {
        $arr = [$this->token, $timestamp, $nonce, $encrypt];
        sort($arr, SORT_STRING);
        $str = implode('', $arr);
        $computed = sha1($str);

        // 调试日志
        LogService::info([
            'tag'     => 'Crypto',
            'message' => '签名验证调试',
            'data'    => [
                'token'     => $this->token,
                'timestamp' => $timestamp,
                'nonce'     => $nonce,
                'encrypt'   => $encrypt,
                'sorted'    => $arr,
                'joined'    => $str,
                'computed'  => $computed,
                'received'  => $signature,
            ],
        ]);

        if (!hash_equals($computed, $signature)) {
            throw new \RuntimeException('签名验证失败');
        }
    }

    private function aesDecrypt(string $encrypt): string
    {
        $aesKey     = base64_decode($this->encodingAESKey . '=');
        $cipherText = base64_decode($encrypt);

        $decrypted = openssl_decrypt(
            $cipherText,
            'AES-256-CBC',
            $aesKey,
            OPENSSL_RAW_DATA | OPENSSL_ZERO_PADDING,
            substr($aesKey, 0, 16)
        );

        if ($decrypted === false) {
            throw new \RuntimeException('AES 解密失败');
        }

        // 去掉 PKCS7 填充
        $decrypted = $this->pkcs7Unpad($decrypted);

        // 前 16 字节为随机串，之后 4 字节（网络字节序）为消息体长度
        $contentLen = unpack('N', substr($decrypted, 16, 4))[1];
        $content    = substr($decrypted, 20, $contentLen);

        // 验证 corpId：消息体之后的剩余内容
        $fromCorpId = substr($decrypted, 20 + $contentLen);
        // 去掉可能的空字符/填充字符再对比
        $fromCorpId = rtrim($fromCorpId, "\x00");
        if ($fromCorpId !== $this->corpId) {
            throw new \RuntimeException('corpId 不匹配，疑似非法请求');
        }

        return $content;
    }

    private function pkcs7Unpad(string $data): string
    {
        $len     = strlen($data);
        $padLen  = ord($data[$len - 1]);
        return substr($data, 0, $len - $padLen);
    }
}
