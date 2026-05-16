<?php

namespace App\Lib\Fetcher;

interface SimplePaginatedList
{
    public function list(int $startPage, int $endPage = null, int $untilDocId = null): array;
}
