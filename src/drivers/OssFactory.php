<?php
declare(strict_types = 1);
/**
 * Created by PhpStorm.
 * User: XYQ
 * Date: 2020-05-19
 * Time: 11:49
 */

namespace xyqWeb\oss\drivers;


abstract class OssFactory
{
    protected $params;

    /**
     * OssFactory constructor.
     * @param array $params
     */
    public function __construct(array $params)
    {
        $this->params = $params;
    }

    /**
     * 上传远程文件
     *
     * @author xyq
     * @param string $url 远程URL地址
     * @param string $name 重命名文件名称
     * @return array
     */
    abstract public function uploadRemoteFile(string $url, string $name) : array;

    /**
     * 上传本地文件到oss
     *
     * @author xyq
     * @param string $filePath 本地文件地址
     * @param string $name 重命名文件名称
     * @return array
     */
    abstract public function uploadLocalFile(string $filePath, string $name) : array;

    /**
     * 上传本地特殊文件到oss
     *
     * @author xyq
     * @param string $filePath 本地文件地址
     * @param string $newBasePath 新基础文件路径
     * @param string $name 重命名文件名称
     * @return array
     */
    abstract public function uploadLocalSpecialFile(string $filePath, string $newBasePath, string $name) : array;

    /**
     * 删除oss文件
     *
     * @author xyq
     * @param string $file 文件地址
     * @return array
     */
    abstract public function delFile(string $file) : array;

    /**
     * 获取签名
     *
     * @author xyq
     * @return array
     */
    abstract public function getSign() : array;

    /**
     * 获取远程资源元信息
     *
     * @author xyq
     * @param string $file
     * @return array
     */
    abstract public function getStat(string $file):array;

    /**
     * 获取上传文件错误消息
     *
     * @author xyq
     * @param int $error
     * @return mixed
     */
    protected function getErrorMsg(int $error)
    {
        $errorArray = [
            UPLOAD_ERR_INI_SIZE   => '文件超过限制',
            UPLOAD_ERR_FORM_SIZE  => '文件大小超过限制',
            UPLOAD_ERR_PARTIAL    => '文件未能完成上传',
            UPLOAD_ERR_NO_FILE    => '未找到上传文件',
            UPLOAD_ERR_NO_TMP_DIR => '上传文件的大小超过限制',
            UPLOAD_ERR_CANT_WRITE => '文件写入失败',
            UPLOAD_ERR_EXTENSION  => '未找到临时文件',
        ];
        return $errorArray[$error];
    }

    /**
     * 过滤不可使用的字符串
     *
     * @author xyq
     * @param string $value
     * @return mixed|string|string[]|null
     */
    protected function getName(string $value)
    {
        $badStr = ["\0", "%00", "\r", "\t", '&', ' ', '"', "'", "<", ">", "   ", "%3C", "%3E"];
        $value = str_replace($badStr, '', $value);
        $value = preg_replace('/&((#(\d{3,5}|x[a-fA-F0-9]{4}));)/', '&\\1', $value);
        $value = preg_replace('/[\\x00-\\x08\\x0B\\x0C\\x0E-\\x1F]/', '', $value); //去掉控制字符
        return $value;
    }

    /**
     * 校验上传文件类型
     *
     * @author xyq
     * @param array $file
     * @throws \Exception
     */
    protected function checkUploadType(array $file)
    {
        $fileType = explode('/', $file['type']);
        if ('image' != strtolower($fileType[0])) {
            $fileTypeArray = [
                'application/zip',
                'application/msword',
                'application/vnd.openxmlformats-officedocument.wordprocessingml.document',
                'application/vnd.ms-excel',
                'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet',
                'application/pdf',
                'text/plain',
                'video/mp4',
                'video/quicktime',
                'application/octet-stream',
                'text/csv',
                'audio/mpeg',
            ];
            if (!isset($file['type']) || !in_array(strtolower($file['type']), $fileTypeArray)) {
                throw new \Exception($file['type'] . '类型文件不允许上传');
            }
        }
    }

    /**
     * 创建文件目录
     *
     * @author xyq
     * @param string $path
     * @return int
     */
    protected function createDir(string $path)
    {
        if (is_dir($path)) {
            return 0;
        }
        $code = 2;
        $result = @mkdir($path, 0777, true);
        if (false == $result) {
            $error = error_get_last();
            $message = $error['message'] ?? '';
            if (strpos($message, 'Permission denied')) {
                $code = 1;
            } elseif (strpos($message, 'File exists')) {
                $code = 0;
            }
        } elseif (is_dir($path)) {
            $code = 0;
        }
        return $code;
    }

    /**
     * 获取远程文件名称
     *
     * @author xyq
     * @param string $url
     * @param string $content_type
     * @return string
     */
    protected function getRemoteFileName(string $url, string $content_type)
    {
        $urlInfo = parse_url($url);
        $fileArray = explode('/', $urlInfo['path']);
        $name = (string)end($fileArray);
        if (empty($name)) {
            $name = md5(microtime());
        }
        $originType = explode('/', $content_type);
        $realType = end($originType);
        if (!is_int(strpos($name, $realType))) {
            $length = strpos($name, '.');
            if (!is_int($length)) {
                $length = strlen($name);
            }
            $name = substr($name, 0, $length) . '.' . $realType;
        }
        return $name;
    }

    /**
     * 获取去除域名的最终地址
     *
     * @author xyq
     * @param string $file
     * @param string $host
     * @return mixed|string
     */
    protected function getRealFile(string $file, string $host)
    {
        if (0 !== strpos($file, 'http')) {
            return ltrim($file, '/');
        }
        if (0 !== strpos($file, $host)) {
            return '';
        }
        return str_replace($host . '/', '', $file);
    }

    /**
     * curlGet
     *
     * @author xyq
     * @param string $url
     * @param int $time
     * @return array
     * @throws \Exception
     */
    public function curlGet(string $url, int $time = 5)
    {
        $proxySetting = $this->params['httpProxy'] ?? [];
        $curl = curl_init();
        curl_setopt($curl, CURLOPT_URL, $url);
        curl_setopt($curl, CURLOPT_RETURNTRANSFER, 1);
        curl_setopt($curl, CURLOPT_CONNECTTIMEOUT, $time);//超时时间
        curl_setopt($curl, CURLOPT_HEADER, 0);
        if (isset($proxySetting['host']) && '0.0.0.0' != $proxySetting['host'] && isset($proxySetting['port'])) {
            curl_setopt($curl, CURLOPT_PROXY, $proxySetting['host']);
            curl_setopt($curl, CURLOPT_PROXYPORT, $proxySetting['port']);
        }
        curl_setopt($curl, CURLOPT_SSL_VERIFYPEER, FALSE); // 对认证证书来源的检查
        curl_setopt($curl, CURLOPT_SSL_VERIFYHOST, FALSE); // 从证书中检查SSL加密算法是否存在
        curl_setopt($curl, CURLOPT_USERAGENT, 'Mozilla/5.0 (compatible; MSIE 5.01; Windows NT 5.0)'); // 模拟用户使用的浏览器
        $responseText = curl_exec($curl);
        $error = curl_errno($curl);
        $responseHeader = curl_getinfo($curl);
        curl_close($curl);
        //返回结果
        if ($error > 0) {
            throw new \Exception("curl error " . $error);
        }
        if ($responseHeader['http_code'] != 200) {
            throw new  \Exception("http error " . $responseHeader['http_code']);
        }
        return ['content' => $responseText, 'header' => $responseHeader];
    }
}
