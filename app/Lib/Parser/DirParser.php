<?php

namespace App\Lib\Parser;

use App\Models\File;

class DirParser extends BaseParser
{

    public function parse(?int $parentId = null): array
    {
        $items = scandir($this->path);
        $withoutDots = array_diff($items, ['.', '..']);
        $skips = ['mimetype', 'manifest.xml', 'message.json', 'message.html', 'header.txt'];
        $files = [];
        foreach ($withoutDots as $item) {
            if (in_array($item, $skips)) {
                continue;
            }
            $childPath = $this->path . '/' . $item;
            if (is_dir($childPath)) {
                $files = [
                    ...$files,
                    ...(new DirParser($childPath))->parse($parentId)
                ];
            } elseif (is_file($childPath)) {
                $extension = strtolower(pathinfo($childPath, PATHINFO_EXTENSION));
                $parsed = [];
                switch ($extension) {
                    case 'asice':
                    case 'bdoc':
                        $parsed = (new AsiceParser($childPath))->parse($parentId);
                        break;
                    case 'eml':
                        $parsed = (new EmlParser($childPath))->parse($parentId);
                        break;
                    case 'rtf':
                        $parsed = (new RtfParser($childPath))->parse($parentId);
                        break;
                    case 'docx':
                    case 'doc':
                        $parsed = (new DocxParser($childPath))->parse($parentId);
                        break;
                    case 'txt':
                        $parsed = (new TxtParser($childPath))->parse($parentId);
                        break;
                    case 'pdf':
                        $parsed = (new PdfParser($childPath))->parse($parentId);
                        break;
                    case 'msg':
                        $parsed = (new MsgParser($childPath))->parse($parentId);
                        break;
                    case 'xml':
                        if (preg_match('/signatures\d\.xml/', $item) && $parentId) {
                            $parsed = (new SignatureParser($childPath))->parse($parentId);
                        }
                        break;
                    default:
                        $parsed = (new FileParser($childPath))->parse($parentId);
                }
                $files = [
                    ...$files,
                    ...$parsed,
                ];
            }

            $extension = pathinfo($item, PATHINFO_EXTENSION);
        }
        return $files;
    }
}
