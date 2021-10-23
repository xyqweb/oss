<?php
declare(strict_types = 1);
/**
 * Created by PhpStorm.
 * User: XYQ
 * Date: 2020-05-19
 * Time: 11:49
 */

namespace xyqWeb\oss\drivers;


class AliYun extends OssFactory
{
    /**
     * @var string 文件路径
     */
    private $filePath;
    /**
     * @var string 临时文件路径
     */
    private $tempFilePath = '/tmp/oss';
    /**
     * @var bool 是否挂载到服务器
     */
    private $isMount = true;
    /**
     * @var bool 是否返回完整的地址
     */
    private $returnHost = false;
    /**
     * @var \OSS\OssClient
     */
    private $client;

    /**
     * AliYun constructor.
     * @param array $params
     * @throws \OSS\Core\OssException
     * @throws \Exception
     */
    public function __construct(array $params)
    {
        parent::__construct($params);
        if (!isset($this->params['path'])) {
            throw new \Exception('未找到oss路径');
        }
        if (isset($this->params['isMount']) && is_bool($this->params['isMount'])) {
            $this->isMount = $this->params['isMount'];
        }
        if (isset($this->params['returnHost']) && is_bool($this->params['returnHost'])) {
            $this->returnHost = $this->params['returnHost'];
        }
        if ($this->isMount && !is_dir($this->params['path'])) {
            $this->isMount = false;
        }
        $merchantId = $this->params['merchant_id'] ?? 0;
        if ($this->isMount) {
            $this->filePath = $this->params['path'] . vsprintf('/image/%d/%s/%s/', [$merchantId, date('Ymd'), date('His')]);
            $result = $this->createDir($this->filePath);
            if ($result > 0) {
                throw new \Exception('创建上传目录失败');
            }
        } else {
            if (!class_exists('\OSS\OssClient')) {
                throw new \Exception('请先安装aliyuncs/oss-sdk-php');
            }
            $this->filePath = vsprintf('image/%d/%s/%s/', [$merchantId, date('Ymd'), date('His')]);
            $this->client = new \OSS\OssClient($this->params['accessKeyId'], $this->params['accessKeySecret'], $this->params['endPoint']);
            $this->client->setUseSSL(true);
            if (!is_dir($this->tempFilePath)) {
                $result = $this->createDir($this->tempFilePath);
                if ($result > 0) {
                    throw new \Exception('创建临时上传目录失败');
                }
            }
            $this->tempFilePath .= '/';
        }
    }

    /**
     * 上传远程文件到oss
     *
     * @author xyq
     * @param string $url 远程URL地址
     * @param string $name 重命名文件名称
     * @return array
     */
    public function uploadRemoteFile(string $url, string $name = '') : array
    {
        try {
            $file = $this->curlGet($url);
            $name = !empty($name) ? $name : $this->getRemoteFileName($url, $file['header']['content_type']);
            $name = $this->getName($name);
            $realFile = $this->filePath . $name;
            if ($this->isMount && file_put_contents($realFile, $file['content'])) {
                return ['status' => 1, 'msg' => '上传成功', 'data' => ['url' => $this->getOssPath($realFile)]];
            } elseif (!$this->isMount && file_put_contents($this->tempFilePath . $name, $file['content'])) {
                $tempFilePath = $this->tempFilePath . $name;
                $this->uploadToRemoteOss($realFile, $tempFilePath);
                return ['status' => 1, 'msg' => '上传成功', 'data' => ['url' => $this->getOssPath('/' . $realFile)]];
            }
            return ['status' => 0, 'msg' => '上传失败'];
        } catch (\Exception $e) {
            return ['status' => 0, 'msg' => '上传失败：' . $e->getMessage()];
        }
    }

    /**
     * 上传本地文件到oss
     *
     * @author xyq
     * @param string $filePath 源文件路径
     * @param string $name 新文件名称，不填取原文件名
     * @param bool $isKeepLocal 是否保留本地文件，false不保留
     * @return array
     */
    public function uploadLocalFile(string $filePath, string $name = '', bool $isKeepLocal = true) : array
    {
        try {
            if (!file_exists($filePath)) {
                throw new \Exception('未找到您要上传的文件');
            }
            $fileArray = explode('/', $filePath);
            $name = !empty($name) ? $name : end($fileArray);
            $realFile = $this->filePath . $this->getName($name);
            if ($this->isMount && $this->copyFileToOss($realFile, $filePath, $isKeepLocal)) {
                return ['status' => 1, 'msg' => '上传成功', 'data' => ['url' => $this->getOssPath($realFile)]];
            } elseif (!$this->isMount) {
                $this->uploadToRemoteOss($realFile, $filePath, $isKeepLocal);
                return ['status' => 1, 'msg' => '上传成功', 'data' => ['url' => $this->getOssPath('/' . $realFile)]];
            }
            return ['status' => 0, 'msg' => '上传失败'];
        } catch (\Exception $e) {
            return ['status' => 0, 'msg' => '上传失败：' . $e->getMessage()];
        }
    }

    /**
     * 前端form上传文件
     *
     * @author xyq
     * @param string $fileName $_FILE内的名称
     * @param string $name 新文件名称
     * @return array
     */
    public function upload(string $fileName, string $name = '') : array
    {
        try {
            if (!isset($_FILES[$fileName]) || empty($_FILES[$fileName])) {
                throw new \Exception('未找到需要上传的文件');
            }
            $file = $_FILES[$fileName];
            if (!isset($file['error'])) {
                throw new \Exception('未找到上传的文件');
            }
            if (UPLOAD_ERR_OK != $file['error']) {
                throw new \Exception($this->getErrorMsg($file['error']));
            }
            $this->checkUploadType($file);
            if (empty($name)) {
                $name = $file['name'];
            }
            $name = $this->getName($name);
            $realFile = $this->filePath . $name;
            if ($this->isMount && move_uploaded_file($file['tmp_name'], $realFile)) {
                return ['status' => 1, 'msg' => '', 'data' => ['url' => $this->getOssPath($realFile)]];
            } elseif (!$this->isMount) {
                $tempFilePath = $this->tempFilePath . $name;
                if (!move_uploaded_file($file['tmp_name'], $tempFilePath)) {
                    return ['status' => 0, 'msg' => '文件上传失败'];
                }
                $this->uploadToRemoteOss($realFile, $tempFilePath);
                return ['status' => 1, 'msg' => '', 'data' => ['url' => $this->getOssPath('/' . $realFile)]];
            }
            return ['status' => 0, 'msg' => '上传失败'];
        } catch (\Exception $e) {
            return ['status' => 0, 'msg' => '上传失败：' . $e->getMessage()];
        }
    }

    /**
     * 校验oss状态
     *
     * @author xyq
     * @param array $oss_result
     * @throws \Exception
     */
    private function checkOssResult(array $oss_result)
    {
        if (!isset($oss_result['info'])) {
            throw new \Exception('oss响应结果异常');
        }
        if (!isset($oss_result['info']['http_code']) || !in_array($oss_result['info']['http_code'], [200, 204])) {
            throw new \Exception('oss响应状态异常');
        }
    }

    /**
     * 删除oss文件
     *
     * @author xyq
     * @param string $file 文件地址
     * @return array
     */
    public function delFile(string $file) : array
    {
        try {
            $file = str_replace($this->params['host'], '', $file);
            if ($this->isMount) {
                $realFile = $this->params['path'] . $file;
                if (!file_exists($realFile)) {
                    return ['status' => 1, 'msg' => '文件不存在，无需删除'];
                }
                if (unlink($realFile)) {
                    return ['status' => 1, 'msg' => '文件删除成功'];
                }
            } else {
                $file = trim($file, '/');
                $result = $this->client->deleteObject($this->params['bucket'], $file);
                $this->checkOssResult($result);
                return ['status' => 1, 'msg' => '文件删除成功'];
            }
            return ['status' => 0, 'msg' => '文件删除失败'];
        } catch (\Exception $e) {
            return ['status' => 0, 'msg' => '删除失败：' . $e->getMessage()];
        }
    }

    /**
     * 获取临时访问URL
     *
     * @author xyq
     * @param string $file
     * @param int $expire_time
     * @return array
     */
    public function getUrl(string $file, int $expire_time = 300)
    {
        try {
            if (0 === strpos($file, 'http://')) {
                $file = str_replace('http://', 'https://', $file);
            }
            if (0 === strpos($file, 'https://')) {
                $file = str_replace($this->params['host'] . '/', '', $file);
                if (0 === strpos($file, 'http')) {
                    return ['status' => 0, 'msg' => '地址不属于当前oss，请检查后再试！'];
                }
            }
            $file = trim($file, '/');
            $result = $this->client->signUrl($this->params['bucket'], $file, $expire_time);
            $originHost = 'https://' . $this->params['bucket'] . '.' . $this->params['endPoint'];
            if (0 === strpos($result, 'https://' . $this->params['bucket']) && $originHost != $this->params['host']) {
                $result = str_replace($originHost, $this->params['host'], $result);
            }
            return ['status' => 1, 'msg' => '', 'url' => $result];
        } catch (\Exception $e) {
            return ['status' => 0, 'msg' => '获取失败：' . $e->getMessage()];
        }
    }

    /**
     * 复制本地文件到oss内
     *
     * @author xyq
     * @param string $remote_file
     * @param string $local_file
     * @param bool $keep_local_file
     * @return bool
     */
    private function copyFileToOss(string $remote_file, string $local_file, bool $keep_local_file = false)
    {
        if ($keep_local_file && copy($local_file, $remote_file)) {
            return true;
        } elseif (!$keep_local_file && rename($local_file, $remote_file)) {
            return true;
        }
        return false;
    }

    /**
     * 上传远程oss
     *
     * @author xyq
     * @param string $remote_file
     * @param string $local_file
     * @param bool $keep_local_file
     * @throws \OSS\Core\OssException
     * @throws \Exception
     */
    private function uploadToRemoteOss(string $remote_file, string $local_file, bool $keep_local_file = false)
    {
        $result = $this->client->uploadFile($this->params['bucket'], $remote_file, $local_file);
        !$keep_local_file && unlink($local_file);
        $this->checkOssResult($result);
    }

    /**
     * 获取oss实际路径
     *
     * @author xyq
     * @param string $filePath
     * @return string
     */
    private function getOssPath(string $filePath) : string
    {
        return ($this->returnHost ? $this->params['host'] : '') . str_replace($this->params['path'], '', $filePath);
    }

    /**
     * 获取签名等信息
     *
     * @author xyq
     * @return array
     */
    public function getSign() : array
    {
        try {
            $filePath = str_replace($this->params['path'] . '/', '', $this->filePath);
            $response = [];
            $now = time();
            $expire = $this->params['expireTime'] ?? 3600;  //设置该policy超时时间是10s. 即这个policy过了这个有效时间，将不能访问。
            $end = $now + $expire;
            $expiration = $this->gmtIso8601($end);
            $conditions = [
                //最大文件大小.用户可以自己设置
                [0 => 'content-length-range', 1 => 0, 2 => $this->params['maxLength'] ?? 524288000],
                /**
                 * 表示用户上传的数据，必须是以$filePath开始，不然上传会失败，这一步不是必须项，只是为了安全起见，防止用户通过policy上传到别人的目录。
                 * 特别注意，此处的'$key'是正确的，不允许解析成$key对应的值
                 */
                [0 => 'starts-with', 1 => '$key', 2 => $filePath]
            ];
            $policy = json_encode(['expiration' => $expiration, 'conditions' => $conditions]);
            $base64_policy = base64_encode($policy);
            $string_to_sign = $base64_policy;
            $signature = base64_encode(hash_hmac('sha1', $string_to_sign, $this->params['accessKeySecret'], true));
            $response['OSSAccessKeyId'] = $this->params['accessKeyId'];
            $response['host'] = $this->params['endPoint'];
            $response['policy'] = $base64_policy;
            $response['signature'] = $signature;
            $response['expire'] = $end;
            $response['bucket'] = $this->params['bucket'];
            $response['key'] = $filePath;  //这个参数是设置用户上传文件时指定的前缀。
            $response['region'] = $this->params['region'];//bucket 所在的区域, 默认 oss-cn-hangzhou
            return ['status' => 1, 'msg' => '请求成功', 'data' => $response];
        } catch (\Exception $e) {
            return ['status' => 0, 'msg' => $e->getMessage()];
        }
    }

    /**
     * 获取时间戳
     *
     * @author xyq
     * @param $time
     * @return string
     * @throws \Exception
     */
    private function gmtIso8601($time) : string
    {
        $dtStr = date("c", $time);
        $expiration = (new \DateTime($dtStr))->format(\DateTime::ISO8601);
        $pos = strpos($expiration, '+');
        $expiration = substr($expiration, 0, $pos);
        return $expiration . "Z";
    }
}
