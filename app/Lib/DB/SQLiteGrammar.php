<?php

namespace App\Lib\DB;

use Illuminate\Support\Arr;

class SQLiteGrammar extends \Illuminate\Database\Query\Grammars\SQLiteGrammar
{
    /**
     * Compile a "where full text" clause.
     *
     * @param \Illuminate\Database\Query\Builder $query
     * @param array $where
     * @return string
     */
    public function whereFullText(\Illuminate\Database\Query\Builder $query, $where)
    {
        $value = $this->parameter($where['value']);

        $column = Arr::first($where['columns']);

        return $this->wrap($column) . ' MATCH ' . $value;
    }
}
