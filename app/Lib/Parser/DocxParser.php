<?php

namespace App\Lib\Parser;

use App\Models\File;
use Illuminate\Filesystem\Filesystem;

class DocxParser extends BaseParser
{

    public function parse(?int $parentId = null): array
    {
        $escapedFullPath = escapeshellarg($this->path);
        $myPid = getmypid();
        $tmpDir = 'file://' . sys_get_temp_dir() . DIRECTORY_SEPARATOR . "soffice-$myPid";

        $fs = new Filesystem();
        ($fs)->ensureDirectoryExists($tmpDir);

        $userInstallationArg = "-env:UserInstallation=$tmpDir";

        $extractCmd = "LANG=C.UTF-8 soffice $userInstallationArg --cat $escapedFullPath";

        $res = (string)shell_exec($extractCmd);

        $fs->deleteDirectory($tmpDir);

        $file = new \App\Models\File([
            'location' => File::store($this->path),
            'name' => basename($this->path),
            'contents' => $res,
            'parent_id' => $parentId,
            'parsed_with' => self::class,
        ]);
        $file->save();
        return [$file];
    }
}
