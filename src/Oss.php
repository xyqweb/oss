<?php
declare(strict_types = 1);
/**
 * Created by PhpStorm.
 * User: XYQ
 * Date: 2020-05-19
 * Time: 11:48
 */

namespace xyqWeb\oss;


use xyqWeb\oss\drivers\OssFactory;

class Oss
{
    /**
     * @var \xyqWeb\oss\drivers\QiNiu|\xyqWeb\oss\drivers\AliYun
     */
    private static $client;
    /**
     * @var string
     */
    private static $md5String = '';

    /**
     * 初始化上传底层
     *
     * @author xyq
     * @param array $params 参数
     * @param string $type oss类型
     * @return drivers\AliYun|drivers\QiNiu
     * @throws \Exception
     */
    public static function init(array $params, string $type = 'aliYun') : OssFactory
    {
        if (!in_array($type, ['aliYun', 'qiNiu'])) {
            throw new \Exception('不存在oss名称');
        }
        $md5String = $type;
        foreach ($params as $key => $value) {
            $md5String .= $key . '=' . $value;
        }
        $md5String = md5($md5String);
        if ($md5String === self::$md5String && self::$client instanceof OssFactory) {
            return self::$client;
        }
        self::$md5String = $md5String;
        $className = "xyqWeb\oss\drivers\\" . ucfirst($type);
        if (!class_exists($className)) {
            throw new \Exception('不存在oss对象');
        }
        self::$client = new $className($params);
        return self::$client;
    }
}
