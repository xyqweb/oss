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
        $className = "xyqWeb\\oss\\" . ucfirst($type);
        if (!class_exists($className)) {
            throw new \Exception('不存在oss对象');
        }
        return new $className($params);
    }
}