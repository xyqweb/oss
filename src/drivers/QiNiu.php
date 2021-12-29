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
    public function uploadRemoteFile(string $url, string $name) : array
    {
        // TODO: Implement uploadRemoteFile() method.
        return [];
    }

    public function uploadLocalFile(string $filePath, string $name) : array
    {
        // TODO: Implement uploadLocalFile() method.
    }

    public function uploadLocalSpecialFile(string $filePath, string $newBasePath, string $name = '') : array
    {
        // TODO: Implement uploadLocalSpecialFile() method.
    }

    public function delFile(string $file) : array
    {
        // TODO: Implement delFile() method.
        return [];
    }

    public function getSign() : array
    {
        // TODO: Implement getSign() method.
        return [];
    }
}
