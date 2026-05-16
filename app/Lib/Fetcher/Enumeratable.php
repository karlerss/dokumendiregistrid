<?php

namespace App\Lib\Fetcher;

interface Enumeratable
{
    public function enumerateBackwards(int $maxId, int $minId = 1, callable $callback = null): void;

    public function enumerateForwards(int $minId, int $maxFailures = 20, callable $callback = null): void;

    public function getCurrentMaxId(): int;
}
