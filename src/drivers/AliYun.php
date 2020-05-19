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
     * AliYun constructor.
     * @param array $params
     * @throws \Exception
     */
    public function __construct(array $params)
    {
        parent::__construct($params);
        if (!isset($this->params['path'])) {
            throw new \Exception('未找到oss路径');
        }
        $merchantId = $this->params['merchant_id'] ?? 0;
        $this->filePath = $this->params['path'] . vsprintf('/image/%d/%s/%s/', [$merchantId, date('Ymd'), date('His')]);
        if (!$this->createDir($this->filePath)) {
            throw new \Exception('创建上传目录失败');
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
            $fileArray = explode('/', $url);
            $name = !empty($name) ? $name : end($fileArray);
            $realFile = $this->filePath . $this->getName($name);
            $file = $this->curlGet($url);
            if (file_put_contents($realFile, $file)) {
                return ['status' => 1, 'msg' => '上传成功', 'data' => ['url' => $this->getOssPath($realFile)]];
            }
            return ['status' => 0, 'msg' => '上传失败'];
        } catch (\Exception $e) {
            return ['status' => 0, 'msg' => $e->getMessage()];
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
            if ($isKeepLocal) {
                if (copy($filePath, $realFile)) {
                    return ['status' => 1, 'msg' => '上传成功', 'data' => ['url' => $this->getOssPath($realFile)]];
                }
            } else {
                if (rename($filePath, $realFile)) {
                    return ['status' => 1, 'msg' => '上传成功', 'data' => ['url' => $this->getOssPath($realFile)]];
                }
            }
            return ['status' => 0, 'msg' => '上传失败'];
        } catch (\Exception $e) {
            return ['status' => 0, 'msg' => $e->getMessage()];
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
            $realFile = $this->filePath . $this->getName($name);
            if (move_uploaded_file($file['tmp_name'], $realFile)) {
                return ['status' => 1, 'msg' => '', 'data' => ['url' => $this->getOssPath($realFile)]];
            }
            return ['status' => 0, 'msg' => '上传失败'];
        } catch (\Exception $e) {
            return ['status' => 0, 'msg' => $e->getMessage()];
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
            $file = trim($file, '/');
            $realFile = $this->params['path'] . $file;
            if (!file_exists($realFile)) {
                return ['status' => 1, 'msg' => '文件不存在，无需删除'];
            }
            if (unlink($realFile)) {
                return ['status' => 1, 'msg' => '文件删除成功'];
            } else {
                return ['status' => 0, 'msg' => '文件删除失败'];
            }
        } catch (\Exception $e) {
            return ['status' => 0, 'msg' => $e->getMessage()];
        }
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
        return str_replace($this->params['path'], '', $filePath);
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
            $response = [];
            $now = time();
            $expire = 3600;  //设置该policy超时时间是10s. 即这个policy过了这个有效时间，将不能访问。
            $end = $now + $expire;
            $expiration = $this->gmtIso8601($end);
            $conditions = [
                //最大文件大小.用户可以自己设置
                [0 => 'content-length-range', 1 => 0, 2 => 20971520],
                /**
                 * 表示用户上传的数据，必须是以$filePath开始，不然上传会失败，这一步不是必须项，只是为了安全起见，防止用户通过policy上传到别人的目录。
                 * 特别注意，此处的'$key'是正确的，不允许解析成$key对应的值
                 */
                [0 => 'starts-with', 1 => '$key', 2 => $this->filePath]
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
            $response['key'] = $this->filePath;  //这个参数是设置用户上传文件时指定的前缀。
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