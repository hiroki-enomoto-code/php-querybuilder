<?php

namespace Database;

use PDO;

final class QueryBuilder
{
    private string $table;
    private ?string $tableAlias = null;

    /** @var list<string> */
    private array $columns = ['*'];

    /** @var list<array{type:string,table:string,condition:string}> */
    private array $joins = [];

    /** 
     * Where条件を階層的に管理
     * @var list<array{type:string,condition:string,isGroup:bool}> 
     */
    private array $wheres = [];

    /** @var array<string,mixed> */
    private array $bindings = [];

    /** @var list<string> */
    private array $groups = [];

    /** @var list<string> */
    private array $havings = [];

    /** @var list<string> */
    private array $orders = [];

    private ?int $limit = null;
    private ?int $offset = null;
    private int $paramCounter = 0;
    private bool $distinct = false;

    public function __construct(string $table)
    {
        if (preg_match('/^(\S+)\s+(\S+)$/', $table, $matches)) {
            $this->table = $matches[1];
            $this->tableAlias = $matches[2];
        } else {
            $this->table = $table;
        }
    }

    private function pdo(): PDO
    {
        return DB::pdo();
    }

    public function select($columns): self
    {
        if ($this->columns !== ['*']) {
            $this->columns = array_merge($this->columns, is_array($columns) ? $columns : [$columns]);
        } else {
            $this->columns = is_array($columns) ? $columns : [$columns];
        }
        return $this;
    }

    public function distinct(): self
    {
        $this->distinct = true;
        return $this;
    }

    public function leftJoin(string $table, string $condition): self
    {
        $this->joins[] = [
            'type' => 'LEFT JOIN',
            'table' => $table,
            'condition' => $condition
        ];
        return $this;
    }

    public function rightJoin(string $table, string $condition): self
    {
        $this->joins[] = [
            'type' => 'RIGHT JOIN',
            'table' => $table,
            'condition' => $condition
        ];
        return $this;
    }

    public function innerJoin(string $table, string $condition): self
    {
        $this->joins[] = [
            'type' => 'INNER JOIN',
            'table' => $table,
            'condition' => $condition
        ];
        return $this;
    }

    public function join(string $table, string $condition): self
    {
        return $this->innerJoin($table, $condition);
    }

    /**
     * 通常のWHERE条件（AND）
     */
    public function where(string $column, $opOrVal, $value = null): self
    {
        $op = '=';
        $val = $opOrVal;
        if ($value !== null) {
            $op = (string)$opOrVal;
            $val = $value;
        }
        $op = strtoupper($op);
        $this->assertOperator($op);
        $p = $this->paramName('w');
        
        $condition = sprintf('%s %s :%s', $this->id($column), $op, $p);
        
        $this->wheres[] = [
            'type' => 'AND',
            'condition' => $condition,
            'isGroup' => false
        ];
        
        $this->bindings[$p] = $val;
        return $this;
    }

    /**
     * 生のWHERE条件を追加
     */
    public function whereRaw(string $condition, array $bindings = []): self
    {
        $this->wheres[] = [
            'type' => 'AND',
            'condition' => $condition,
            'isGroup' => false
        ];
        
        foreach ($bindings as $key => $val) {
            $this->bindings[$key] = $val;
        }
        return $this;
    }

    /**
     * OR条件グループを追加
     * 使用例: ->orWhere(function($q) { $q->where('a', 1)->where('b', 2); })
     * 生成: OR (a = 1 AND b = 2)
     */
    public function orWhere(callable $callback): self
    {
        // 現在のwheresとbindingsを一時保存
        $originalWheres = $this->wheres;
        $originalBindings = $this->bindings;
        
        // 空の状態でコールバック実行
        $this->wheres = [];
        
        $callback($this);
        
        // コールバック内で追加された条件を取得
        $groupConditions = [];
        foreach ($this->wheres as $where) {
            $groupConditions[] = $where['condition'];
        }
        
        // 元の状態に戻す
        $this->wheres = $originalWheres;
        // bindingsは追加されたものを保持
        
        // OR条件グループとして追加
        if (!empty($groupConditions)) {
            $this->wheres[] = [
                'type' => 'OR',
                'condition' => '(' . implode(' AND ', $groupConditions) . ')',
                'isGroup' => true
            ];
        }
        
        return $this;
    }

    /**
     * 生のOR WHERE条件を追加
     */
    public function orWhereRaw(string $condition, array $bindings = []): self
    {
        $this->wheres[] = [
            'type' => 'OR',
            'condition' => '(' . $condition . ')',
            'isGroup' => true
        ];
        
        foreach ($bindings as $key => $val) {
            $this->bindings[$key] = $val;
        }
        return $this;
    }

    /**
     * ネストされたWHERE条件グループ（括弧で囲まれる）
     * 使用例: ->whereNested(function($q) { $q->where('a', 1)->orWhere(function($q2) { ... }); })
     * 生成: AND (a = 1 OR (...))
     */
    public function whereNested(callable $callback, string $boolean = 'AND'): self
    {
        // 現在のwheresを一時保存
        $originalWheres = $this->wheres;
        
        // 空の状態でコールバック実行
        $this->wheres = [];
        
        $callback($this);
        
        // コールバック内で追加された条件全体を取得
        $nestedWheres = $this->wheres;
        
        // 元の状態に戻す
        $this->wheres = $originalWheres;
        
        // ネストされた条件をコンパイル
        if (!empty($nestedWheres)) {
            $nestedSql = $this->compileNestedConditions($nestedWheres);
            
            $this->wheres[] = [
                'type' => strtoupper($boolean),
                'condition' => '(' . $nestedSql . ')',
                'isGroup' => true
            ];
        }
        
        return $this;
    }

    /**
     * ネストされた条件をコンパイル（内部用）
     */
    private function compileNestedConditions(array $wheres): string
    {
        if (empty($wheres)) {
            return '';
        }

        $sql = [];
        
        foreach ($wheres as $index => $where) {
            $condition = $where['condition'];
            $type = $where['type'];
            
            if ($index === 0) {
                $sql[] = $condition;
            } else {
                $sql[] = $type . ' ' . $condition;
            }
        }

        return implode(' ', $sql);
    }

    /**
     * WHERE IN句
     */
    public function whereIn(string $column, array $values): self
    {
        if ($values === []) {
            $this->wheres[] = [
                'type' => 'AND',
                'condition' => '0 = 1',
                'isGroup' => false
            ];
            return $this;
        }
        
        $placeholders = [];
        foreach ($values as $v) {
            $p = $this->paramName('in');
            $placeholders[] = ':' . $p;
            $this->bindings[$p] = $v;
        }
        
        $this->wheres[] = [
            'type' => 'AND',
            'condition' => sprintf('%s IN (%s)', $this->id($column), implode(', ', $placeholders)),
            'isGroup' => false
        ];
        
        return $this;
    }

    public function whereNull(string $column): self
    {
        $this->wheres[] = [
            'type' => 'AND',
            'condition' => sprintf('%s IS NULL', $this->id($column)),
            'isGroup' => false
        ];
        return $this;
    }

    public function whereNotNull(string $column): self
    {
        $this->wheres[] = [
            'type' => 'AND',
            'condition' => sprintf('%s IS NOT NULL', $this->id($column)),
            'isGroup' => false
        ];
        return $this;
    }

    public function groupBy($columns): self
    {
        $cols = is_array($columns) ? $columns : [$columns];
        foreach ($cols as $col) {
            $this->groups[] = $this->id($col);
        }
        return $this;
    }

    public function having(string $condition, array $bindings = []): self
    {
        $this->havings[] = $condition;
        foreach ($bindings as $key => $val) {
            $this->bindings[$key] = $val;
        }
        return $this;
    }

    public function orderBy(string $column, string $direction = 'ASC'): self
    {
        $dir = strtoupper($direction);
        if (!in_array($dir, ['ASC', 'DESC'], true)) {
            throw new \InvalidArgumentException('orderBy direction must be ASC or DESC');
        }
        $this->orders[] = $this->id($column) . ' ' . $dir;
        return $this;
    }

    public function orderByRaw(string $orderExpression): self
    {
        $this->orders[] = $orderExpression;
        return $this;
    }

    public function limit(int $n): self { $this->limit = max(0, $n); return $this; }
    public function offset(int $n): self { $this->offset = max(0, $n); return $this; }

    public function get(): array
    {
        [$sql, $params] = $this->compileSelect();
        $stmt = $this->pdo()->prepare($sql);
        foreach ($params as $k => $v) { $stmt->bindValue(':' . $k, $v); }

        DB::debugDump($sql, $params);

        $stmt->execute();
        $rows = $stmt->fetchAll();
        
        return $rows;
    }

    public function paginate(int $perPage = 15, int $page = 1): array
    {
        $page = max(1, $page);
        $total = $this->count();
        
        $this->limit($perPage)->offset(($page - 1) * $perPage);
        $data = $this->get();
        
        return [
            'data' => $data,
            'total' => $total,
            'per_page' => $perPage,
            'current_page' => $page,
            'last_page' => (int)ceil($total / $perPage),
            'from' => ($page - 1) * $perPage + 1,
            'to' => min($page * $perPage, $total),
        ];
    }

    public function first(): ?array
    {
        $this->limit = 1;
        [$sql, $params] = $this->compileSelect();

        DB::debugDump($sql, $params);

        $stmt = $this->pdo()->prepare($sql);
        foreach ($params as $k => $v) { $stmt->bindValue(':' . $k, $v); }
        $stmt->execute();
        $row = $stmt->fetch();
        return $row === false ? null : $row;
    }

    public function value(string $column)
    {
        $this->select([$column])->limit(1);
        $row = $this->first();
        return $row[$column] ?? null;
    }

    public function count(string $column = '*'): int
    {
        if (!empty($this->groups)) {
            $originalColumns = $this->columns;
            $originalLimit = $this->limit;
            $originalOffset = $this->offset;
            $originalOrders = $this->orders;
            
            $this->limit = null;
            $this->offset = null;
            $this->orders = [];
            
            [$subSql, $params] = $this->compileSelect();
            
            $this->columns = $originalColumns;
            $this->limit = $originalLimit;
            $this->offset = $originalOffset;
            $this->orders = $originalOrders;
            
            $sql = "SELECT COUNT(*) as aggregate FROM ({$subSql}) as subquery";
            
            DB::debugDump($sql, $params);
            
            $stmt = $this->pdo()->prepare($sql);
            foreach ($params as $k => $v) { $stmt->bindValue(':' . $k, $v); }
            $stmt->execute();
            $row = $stmt->fetch();
            
            return (int)($row['aggregate'] ?? 0);
        }
        
        $originalColumns = $this->columns;
        $originalLimit = $this->limit;
        $originalOffset = $this->offset;
        $originalOrders = $this->orders;
        
        $this->columns = ["COUNT({$column}) as aggregate"];
        $this->limit = null;
        $this->offset = null;
        $this->orders = [];
        
        $row = $this->first();
        
        $this->columns = $originalColumns;
        $this->limit = $originalLimit;
        $this->offset = $originalOffset;
        $this->orders = $originalOrders;
        
        return (int)($row['aggregate'] ?? 0);
    }

    public function insert(array $data): string
    {
        if ($data === []) { throw new \InvalidArgumentException('insert data is empty'); }
        $cols = array_keys($data);
        $phs = [];
        $params = [];
        foreach ($data as $col => $val) {
            $p = $this->paramName('i');
            $phs[] = ':' . $p;
            $params[$p] = $val;
        }
        $sql = sprintf(
            'INSERT INTO %s (%s) VALUES (%s)',
            $this->idTable($this->table),
            implode(', ', array_map(fn($c) => $this->id($c), $cols)),
            implode(', ', $phs)
        );

        DB::debugDump($sql, $params);

        $stmt = $this->pdo()->prepare($sql);
        foreach ($params as $k => $v) { $stmt->bindValue(':' . $k, $v); }
        $stmt->execute();
        return $this->pdo()->lastInsertId();
    }

    public function update(array $data): int
    {
        if ($data === []) { throw new \InvalidArgumentException('update data is empty'); }
        $sets = [];
        $params = $this->bindings;
        foreach ($data as $col => $val) {
            $p = $this->paramName('u');
            $sets[] = sprintf('%s = :%s', $this->id($col), $p);
            $params[$p] = $val;
        }
        $sql = sprintf('UPDATE %s SET %s', $this->getTableExpression(), implode(', ', $sets));
        $sql .= $this->compileWhereSql();

        DB::debugDump($sql, $params);

        $stmt = $this->pdo()->prepare($sql);
        foreach ($params as $k => $v) { $stmt->bindValue(':' . $k, $v); }
        $stmt->execute();
        return $stmt->rowCount();
    }

    public function delete(): int
    {
        $sql = sprintf('DELETE FROM %s', $this->getTableExpression());
        $sql .= $this->compileWhereSql();
        $stmt = $this->pdo()->prepare($sql);
        foreach ($this->bindings as $k => $v) { $stmt->bindValue(':' . $k, $v); }

        DB::debugDump($sql, $this->bindings);

        $stmt->execute();
        return $stmt->rowCount();
    }

    private function getTableExpression(): string
    {
        $tableExpr = $this->idTable($this->table);
        if ($this->tableAlias !== null) {
            $tableExpr .= ' ' . $this->tableAlias;
        }
        return $tableExpr;
    }

    private function formatJoinTable(string $table): string
    {
        if (strpos(trim($table), '(') === 0) {
            return $table;
        }
        
        if (preg_match('/^(\S+)\s+(\S+)$/', $table, $matches)) {
            return $this->idTable($matches[1]) . ' ' . $matches[2];
        }
        return $this->idTable($table);
    }

    private function compileSelect(): array
    {
        $modifiers = [];
        
        if ($this->distinct) {
            $modifiers[] = 'DISTINCT';
        }
        
        $modifierClause = $modifiers ? implode(' ', $modifiers) . ' ' : '';
        
        $cols = $this->columns === ['*']
            ? '*'
            : implode(', ', array_map(fn($c) => $this->id($c), $this->columns));
        
        $sql = sprintf('SELECT %s%s FROM %s', $modifierClause, $cols, $this->getTableExpression());
        
        foreach ($this->joins as $join) {
            $sql .= sprintf(' %s %s ON %s', 
                $join['type'], 
                $this->formatJoinTable($join['table']), 
                $join['condition']
            );
        }
        
        $sql .= $this->compileWhereSql();
        
        if ($this->groups) {
            $sql .= ' GROUP BY ' . implode(', ', $this->groups);
        }
        
        if ($this->havings) {
            $sql .= ' HAVING ' . implode(' AND ', $this->havings);
        }
        
        if ($this->orders) {
            $sql .= ' ORDER BY ' . implode(', ', $this->orders);
        }
        if ($this->limit !== null) {
            $sql .= ' LIMIT ' . $this->limit;
        }
        if ($this->offset !== null) {
            $sql .= ' OFFSET ' . $this->offset;
        }
        return [$sql, $this->bindings];
    }

    /**
     * WHERE句のコンパイル（改善版）
     * OR条件がある場合、適切に括弧で囲む
     */
    private function compileWhereSql(): string
    {
        if (empty($this->wheres)) {
            return '';
        }

        // OR条件が含まれているかチェック
        $hasOr = false;
        foreach ($this->wheres as $where) {
            if ($where['type'] === 'OR') {
                $hasOr = true;
                break;
            }
        }

        // OR条件がない場合はシンプルにANDで結合
        if (!$hasOr) {
            $conditions = array_map(function($where) {
                return $where['condition'];
            }, $this->wheres);
            return ' WHERE ' . implode(' AND ', $conditions);
        }

        // OR条件がある場合は、連続するAND条件をグループ化
        $groups = [];
        $currentGroup = [];
        
        foreach ($this->wheres as $where) {
            if ($where['type'] === 'OR') {
                // 現在のANDグループを保存
                if (!empty($currentGroup)) {
                    $groups[] = [
                        'type' => 'AND',
                        'conditions' => $currentGroup
                    ];
                    $currentGroup = [];
                }
                // OR条件を単独で追加
                $groups[] = [
                    'type' => 'OR',
                    'conditions' => [$where['condition']]
                ];
            } else {
                // AND条件を現在のグループに追加
                $currentGroup[] = $where['condition'];
            }
        }
        
        // 最後のANDグループを保存
        if (!empty($currentGroup)) {
            $groups[] = [
                'type' => 'AND',
                'conditions' => $currentGroup
            ];
        }

        // グループを結合
        $sql = [];
        foreach ($groups as $index => $group) {
            $groupSql = implode(' AND ', $group['conditions']);
            
            // 複数条件がある場合は括弧で囲む
            if (count($group['conditions']) > 1) {
                $groupSql = '(' . $groupSql . ')';
            }
            
            if ($index === 0) {
                $sql[] = $groupSql;
            } else {
                $sql[] = $group['type'] . ' ' . $groupSql;
            }
        }

        return ' WHERE ' . implode(' ', $sql);
    }

    private function idTable(string $table): string
    {
        return sprintf('`%s`', str_replace('`','``',$table));
    }

    private function id(string $identifier): string
    {
        if (
            $identifier === '*' ||
            strpos($identifier, '(') !== false ||
            strpos($identifier, ')') !== false ||
            stripos($identifier, ' AS ') !== false
        ) {
            return $identifier;
        }
        if (strpos($identifier, '.') !== false) {
            [$t, $c] = explode('.', $identifier, 2);
            if ($c === '*') {
                return $t . '.*';
            }
            return $t . '.`' . str_replace('`','``',$c) . '`';
        }
        return sprintf('`%s`', str_replace('`','``',$identifier));
    }

    private function assertOperator(string $op): void
    {
        $ok = ['=', '!=', '<>', '<', '>', '<=', '>=', 'LIKE', 'NOT LIKE'];
        if (!in_array($op, $ok, true)) {
            throw new \InvalidArgumentException('Unsupported operator: ' . $op);
        }
    }

    private function paramName(string $prefix): string
    {
        $this->paramCounter++;
        return $prefix . $this->paramCounter;
    }
}