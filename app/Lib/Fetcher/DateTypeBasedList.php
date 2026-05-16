<?php

namespace App\Lib\Fetcher;

use Carbon\Carbon;

interface DateTypeBasedList
{
    public function list(Carbon $date, $type = null): array;
}
