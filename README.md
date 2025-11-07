# クエリービルダー仕様書

## 概要

LaravelライクなメソッドチェーンでSQLクエリを構築できるPHPクエリービルダーライブラリです。PDOを使用してMySQLデータベースとの安全なやり取りを実現します。

## 主要クラス

### Database\DB

ファサードクラス。すべてのデータベース操作の入り口となります。

### Database\Manager

PDO接続とクエリ実行を管理するマネージャークラス。

### Database\QueryBuilder

SQLクエリを構築するビルダークラス。メソッドチェーンで直感的にクエリを組み立てます。

---

## セットアップ

### 初期化

```php
use Database\DB;

// デバッグモードの有効化（オプション）
DB::setDebug(true);
```

データベース接続情報は `$_SERVER['DOCUMENT_ROOT']/../library/config/database.php` から自動的に読み込まれます。

---

## 基本的な使い方

### テーブルの指定

```php
DB::table('users')
```

テーブルエイリアスも使用可能：

```php
DB::table('users u')
```

---

## SELECT クエリ

### 全カラム取得

```php
$users = DB::table('users')->get();
```

### 特定カラムの選択

```php
$users = DB::table('users')
    ->select(['id', 'name', 'email'])
    ->get();

// 単一カラムの場合
$users = DB::table('users')
    ->select('name')
    ->get();
```

### DISTINCT

```php
$cities = DB::table('users')
    ->distinct()
    ->select('city')
    ->get();
```

### 単一レコード取得

```php
$user = DB::table('users')
    ->where('id', 1)
    ->first();
```

### 単一値の取得

```php
$email = DB::table('users')
    ->where('id', 1)
    ->value('email');
```

---

## WHERE 条件

### 基本的なWHERE

```php
// 等価条件
DB::table('users')->where('status', 'active')->get();

// 演算子を指定
DB::table('users')->where('age', '>', 18)->get();

// 複数条件（AND）
DB::table('users')
    ->where('status', 'active')
    ->where('age', '>', 18)
    ->get();
```

**サポートされる演算子:**
- `=`, `!=`, `<>`, `<`, `>`, `<=`, `>=`
- `LIKE`, `NOT LIKE`

### 生のWHERE条件

```php
DB::table('users')
    ->whereRaw('DATE(created_at) = CURDATE()')
    ->get();

// バインディング付き
DB::table('users')
    ->whereRaw('age BETWEEN :min AND :max', [
        'min' => 18,
        'max' => 65
    ])
    ->get();
```

### OR条件

```php
DB::table('users')
    ->where('status', 'active')
    ->orWhere(function($q) {
        $q->where('role', 'admin')
          ->where('verified', 1);
    })
    ->get();

// 生成されるSQL: WHERE status = 'active' OR (role = 'admin' AND verified = 1)
```

### 生のOR条件

```php
DB::table('users')
    ->where('status', 'active')
    ->orWhereRaw('role = :role AND verified = 1', ['role' => 'admin'])
    ->get();
```

### ネストされた条件

```php
DB::table('users')
    ->whereNested(function($q) {
        $q->where('name', 'LIKE', '%John%')
          ->orWhere(function($q2) {
              $q2->where('city', 'Tokyo')
                 ->where('age', '>', 20);
          });
    })
    ->where('status', 'active')
    ->get();

// 生成されるSQL: WHERE (name LIKE '%John%' OR (city = 'Tokyo' AND age > 20)) AND status = 'active'
```

### WHERE IN

```php
DB::table('users')
    ->whereIn('id', [1, 2, 3, 4, 5])
    ->get();

// 空配列の場合は自動的に false 条件になる
DB::table('users')
    ->whereIn('id', [])
    ->get(); // WHERE 0 = 1
```

### WHERE NULL / NOT NULL

```php
// NULL判定
DB::table('users')
    ->whereNull('deleted_at')
    ->get();

// NOT NULL判定
DB::table('users')
    ->whereNotNull('email_verified_at')
    ->get();
```

---

## JOIN

### INNER JOIN

```php
DB::table('users')
    ->join('posts', 'users.id = posts.user_id')
    ->get();

// または
DB::table('users')
    ->innerJoin('posts', 'users.id = posts.user_id')
    ->get();
```

### LEFT JOIN

```php
DB::table('users')
    ->leftJoin('posts', 'users.id = posts.user_id')
    ->get();
```

### RIGHT JOIN

```php
DB::table('users')
    ->rightJoin('posts', 'users.id = posts.user_id')
    ->get();
```

### 複数のJOIN

```php
DB::table('users u')
    ->leftJoin('posts p', 'u.id = p.user_id')
    ->leftJoin('comments c', 'p.id = c.post_id')
    ->select(['u.name', 'p.title', 'c.content'])
    ->get();
```

---

## 集計とグルーピング

### COUNT

```php
$count = DB::table('users')->count();

// 特定カラムのカウント
$count = DB::table('users')->count('email');

// 条件付きカウント
$count = DB::table('users')
    ->where('status', 'active')
    ->count();
```

### GROUP BY

```php
DB::table('orders')
    ->select(['user_id', 'COUNT(*) as order_count'])
    ->groupBy('user_id')
    ->get();

// 複数カラムでグルーピング
DB::table('orders')
    ->groupBy(['user_id', 'status'])
    ->get();
```

### HAVING

```php
DB::table('orders')
    ->select(['user_id', 'COUNT(*) as order_count'])
    ->groupBy('user_id')
    ->having('order_count > :min', ['min' => 5])
    ->get();
```

---

## ORDER BY

### 基本的なソート

```php
DB::table('users')
    ->orderBy('created_at', 'DESC')
    ->get();

// 複数カラムでソート
DB::table('users')
    ->orderBy('status', 'ASC')
    ->orderBy('created_at', 'DESC')
    ->get();
```

### 生のORDER BY

```php
DB::table('users')
    ->orderByRaw('FIELD(status, "premium", "active", "inactive")')
    ->get();
```

---

## LIMIT / OFFSET

```php
// 最初の10件を取得
DB::table('users')
    ->limit(10)
    ->get();

// 10件スキップして次の10件を取得
DB::table('users')
    ->limit(10)
    ->offset(10)
    ->get();
```

---

## ページネーション

```php
$result = DB::table('users')
    ->where('status', 'active')
    ->orderBy('created_at', 'DESC')
    ->paginate(15, 1); // 1ページあたり15件、1ページ目

/*
返り値:
[
    'data' => [...], // レコード配列
    'total' => 100, // 総レコード数
    'per_page' => 15,
    'current_page' => 1,
    'last_page' => 7,
    'from' => 1,
    'to' => 15
]
*/
```

---

## INSERT

### 単一レコードの挿入

```php
$insertId = DB::table('users')->insert([
    'name' => 'John Doe',
    'email' => 'john@example.com',
    'created_at' => date('Y-m-d H:i:s')
]);

// $insertId には最後に挿入されたIDが格納される
```

---

## UPDATE

### レコードの更新

```php
$affectedRows = DB::table('users')
    ->where('id', 1)
    ->update([
        'name' => 'Jane Doe',
        'updated_at' => date('Y-m-d H:i:s')
    ]);

// $affectedRows には更新された行数が格納される
```

### 複数条件での更新

```php
$affectedRows = DB::table('users')
    ->where('status', 'inactive')
    ->where('last_login', '<', '2023-01-01')
    ->update([
        'status' => 'archived'
    ]);
```

---

## DELETE

### レコードの削除

```php
$affectedRows = DB::table('users')
    ->where('id', 1)
    ->delete();

// $affectedRows には削除された行数が格納される
```

### 条件付き削除

```php
$affectedRows = DB::table('users')
    ->where('status', 'spam')
    ->where('created_at', '<', '2023-01-01')
    ->delete();
```

---

## 生のSQLクエリ

### SELECT

```php
$users = DB::select('SELECT * FROM users WHERE status = :status', [
    'status' => 'active'
]);
```

### 単一レコード取得

```php
$user = DB::selectOne('SELECT * FROM users WHERE id = :id', [
    'id' => 1
]);
```

### INSERT / UPDATE / DELETE

```php
$affectedRows = DB::statement(
    'UPDATE users SET status = :status WHERE last_login < :date',
    [
        'status' => 'inactive',
        'date' => '2023-01-01'
    ]
);
```

---

## トランザクション

```php
DB::transaction(function($manager) {
    // トランザクション内の処理
    DB::table('accounts')
        ->where('id', 1)
        ->update(['balance' => 100]);
    
    DB::table('accounts')
        ->where('id', 2)
        ->update(['balance' => 200]);
    
    // 例外が発生した場合は自動的にロールバック
    // 成功した場合は自動的にコミット
});
```

---

## デバッグ

### デバッグモードの有効化

```php
DB::setDebug(true);

// クエリ実行時にSQLとパラメータが出力される
DB::table('users')->where('id', 1)->get();
/*
出力例:
SQL: SELECT * FROM `users` WHERE `id` = :w1 LIMIT 1
Params: {
    "w1": 1
}
*/
```

---

## PDOへの直接アクセス

```php
$pdo = DB::pdo();

// PDOの機能を直接使用可能
$stmt = $pdo->prepare('SELECT * FROM users WHERE id = :id');
$stmt->execute(['id' => 1]);
```

---

## 実用例

### ユーザーメタデータの取得

```php
$result = DB::table('wp_usermeta')
    ->select(['meta_value'])
    ->where('user_id', $post_id)
    ->where('meta_key', $meta_key)
    ->first();

$metaValue = $result['meta_value'] ?? null;
```

### 複雑な検索クエリ

```php
$posts = DB::table('posts p')
    ->leftJoin('users u', 'p.author_id = u.id')
    ->leftJoin('categories c', 'p.category_id = c.id')
    ->select([
        'p.id',
        'p.title',
        'u.name as author_name',
        'c.name as category_name'
    ])
    ->where('p.status', 'published')
    ->whereNested(function($q) {
        $q->where('p.title', 'LIKE', '%検索%')
          ->orWhere(function($q2) {
              $q2->where('p.content', 'LIKE', '%検索%')
                 ->where('p.type', 'article');
          });
    })
    ->orderBy('p.created_at', 'DESC')
    ->paginate(20, 1);
```

### 集計レポート

```php
$report = DB::table('orders')
    ->select([
        'user_id',
        'COUNT(*) as total_orders',
        'SUM(amount) as total_amount',
        'AVG(amount) as avg_amount'
    ])
    ->where('status', 'completed')
    ->where('created_at', '>=', '2024-01-01')
    ->groupBy('user_id')
    ->having('total_orders >= :min', ['min' => 3])
    ->orderBy('total_amount', 'DESC')
    ->get();
```

---

## 注意事項

### セキュリティ

- すべてのユーザー入力は自動的にバインディングされるため、SQLインジェクションから保護されます
- 生のSQL（`whereRaw`, `orderByRaw`など）を使用する際は、バインディングを適切に使用してください

### パフォーマンス

- `count()` とその他のクエリは別々に実行されます。必要に応じて最適化してください
- 大量のデータを扱う場合は、`limit()` と `offset()` またはページネーションを使用してください

### データベース接続

- PDO接続は遅延初期化され、初回クエリ実行時に確立されます
- 接続は再利用され、スクリプト終了まで保持されます

### エラーハンドリング

- PDOは例外モード（`PDO::ERRMODE_EXCEPTION`）で動作します
- データベースエラーは `PDOException` として投げられます

---

## 型定義

### 返り値の型

- `get()`: `array` - レコード配列
- `first()`: `?array` - 単一レコードまたはnull
- `value()`: `mixed` - 単一値またはnull
- `count()`: `int` - レコード数
- `insert()`: `string` - 最後に挿入されたID
- `update()`: `int` - 更新された行数
- `delete()`: `int` - 削除された行数
- `paginate()`: `array` - ページネーション情報を含む配列

---

## バージョン情報

このドキュメントは提供されたコードに基づいています。

## サポート

詳細な使用方法や問題が発生した場合は、コードのコメントやPHPDocを参照してください。
