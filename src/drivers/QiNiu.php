<?php
declare(strict_types = 1);
/**
 * Created by PhpStorm.
 * User: XYQ
 * Date: 2020-05-19
 * Time: 11:49
 */

namespace xyqWeb\oss\drivers;


class QiNiu extends OssFactory
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
     * @var string
     */
    private $token;
    /**
     * @var string
     */
    private $auth;

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
            if (!class_exists('\Qiniu\Storage\UploadManager')) {
                throw new \Exception('请先安装qiniu/php-sdk');
            }
            $this->filePath = vsprintf('image/%d/%s/%s/', [$merchantId, date('Ymd'), date('His')]);
            $this->auth = new \Qiniu\Auth($this->params['accessKeyId'], $this->params['accessKeySecret']);
            $this->token = $this->auth->uploadToken($this->params['bucket']);
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
    public function uploadRemoteFile(string $url, string $name = ''): array
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
    public function uploadLocalFile(string $filePath, string $name = '', bool $isKeepLocal = true): array
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
     * 上传本地特殊文件到oss
     *
     * @author xyq
     * @param string $filePath 源文件路径
     * @param string $newBasePath 新基础文件路径
     * @param string $name 新文件名称，不填取原文件名
     * @return array
     */
    public function uploadLocalSpecialFile(string $filePath, string $newBasePath, string $name = ''): array
    {
        try {
            if (!file_exists($filePath)) {
                throw new \Exception('未找到您要上传的文件');
            }
            $fileArray = explode('/', $filePath);
            $name = !empty($name) ? $name : end($fileArray);
            $realPath = trim(trim($newBasePath), '/') . '/' . date('Ymd/His') . '/' . ($this->params['merchant_id'] ?? 0) . '/' . mt_rand(100000, 999999);
            $realFile = $realPath . '/' . $this->getName($name);
            if ($this->isMount && $this->copyFileToOss($realFile, $filePath, false)) {
                return ['status' => 1, 'msg' => '上传成功', 'data' => ['url' => $this->getOssPath($realFile)]];
            } elseif (!$this->isMount) {
                $this->uploadToRemoteOss($realFile, $filePath, false);
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
    public function upload(string $fileName, string $name = ''): array
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
     * 删除oss文件
     *
     * @author xyq
     * @param string $file 文件地址
     * @return array
     */
    public function delFile(string $file): array
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
                $config = new \Qiniu\Config();
                $bucketManager = new \Qiniu\Storage\BucketManager($this->auth, $config);
                list($result, $error) = $bucketManager->delete($this->params['bucket'], $file);
                if ($error == null && $result == null) {
                    return ['status' => 1, 'msg' => '文件删除成功'];
                }
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
            if (0 !== strpos($file, $this->params['host'])) {
                return ['status' => 0, 'msg' => '地址不属于当前oss，请检查后再试！'];
            }
            $result = $this->auth->privateDownloadUrl($file, $expire_time);
            return ['status' => 1, 'msg' => '', 'url' => $result, 'data' => ['url' => $result]];
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
     * @throws \Exception
     */
    private function uploadToRemoteOss(string $remote_file, string $local_file, bool $keep_local_file = false)
    {
        list($result, $error) = (new \Qiniu\Storage\UploadManager())->put($this->token, $remote_file, file_get_contents($local_file));
        if ($error !== null) {
            throw new \Exception($error);
        }
        !$keep_local_file && unlink($local_file);
        if (!isset($result['key']) || empty($result['key'])) {
            throw new \Exception('上传失败');
        }
    }

    /**
     * 获取oss实际路径
     *
     * @author xyq
     * @param string $filePath
     * @return string
     */
    private function getOssPath(string $filePath): string
    {
        return ($this->returnHost ? $this->params['host'] : '') . str_replace($this->params['path'], '', $filePath);
    }

    /**
     * 获取签名等信息
     *
     * @author xyq
     * @return array
     */
    public function getSign(): array
    {
        try {
            $filePath = str_replace($this->params['path'] . '/', '', $this->filePath);
            $response = [];
            $now = time();
            $expire = $this->params['expireTime'] ?? 3600;  //设置该policy超时时间是10s. 即这个policy过了这个有效时间，将不能访问。
            $end = $now + $expire;
            $response['signature'] = $this->auth->uploadToken($this->params['bucket'], null, $expire, null, true);
            $response['expire'] = $end;
            $response['bucket'] = $this->params['bucket'];
            $response['key'] = $filePath;  //这个参数是设置用户上传文件时指定的前缀。
            return ['status' => 1, 'msg' => '请求成功', 'data' => $response];
        } catch (\Exception $e) {
            return ['status' => 0, 'msg' => $e->getMessage()];
        }
    }
}
