<?php

declare(strict_types=1);

namespace App\Repository;

use Database\DB;

DB::setup(
    'your_db_name',
    'your_host',
    'your_port',
    'your_user',
    'your_password'
);

class UserRepository
{
    private string $table = 'users';

    /**
     * 全ユーザーを取得
     * @return array
     */
    public function findAll(): array
    {
        return DB::table($this->table)
            ->orderBy('created_at', 'DESC')
            ->get();
    }

    /**
     * IDでユーザーを取得
     * @param int $id
     * @return array|null
     */
    public function findById(int $id): ?array
    {
        return DB::table($this->table)
            ->where('id', $id)
            ->first();
    }

    /**
     * メールアドレスでユーザーを取得
     * @param string $email
     * @return array|null
     */
    public function findByEmail(string $email): ?array
    {
        return DB::table($this->table)
            ->where('email', $email)
            ->first();
    }

    /**
     * アクティブなユーザーを取得
     * @return array
     */
    public function findActive(): array
    {
        return DB::table($this->table)
            ->where('status', 'active')
            ->whereNotNull('email_verified_at')
            ->orderBy('last_login', 'DESC')
            ->get();
    }

    /**
     * 検索条件でユーザーを取得
     * @param array $conditions
     * @return array
     */
    public function search(array $conditions): array
    {
        $query = DB::table($this->table);

        // 名前で検索
        if (!empty($conditions['name'])) {
            $query->where('name', 'LIKE', '%' . $conditions['name'] . '%');
        }

        // メールで検索
        if (!empty($conditions['email'])) {
            $query->where('email', 'LIKE', '%' . $conditions['email'] . '%');
        }

        // ステータスで絞り込み
        if (!empty($conditions['status'])) {
            $query->where('status', $conditions['status']);
        }

        // 年齢範囲で絞り込み
        if (!empty($conditions['age_min'])) {
            $query->where('age', '>=', $conditions['age_min']);
        }
        if (!empty($conditions['age_max'])) {
            $query->where('age', '<=', $conditions['age_max']);
        }

        // 作成日範囲で絞り込み
        if (!empty($conditions['created_from'])) {
            $query->where('created_at', '>=', $conditions['created_from']);
        }
        if (!empty($conditions['created_to'])) {
            $query->where('created_at', '<=', $conditions['created_to']);
        }

        return $query->orderBy('created_at', 'DESC')->get();
    }

    /**
     * ページネーションでユーザーを取得
     * @param int $perPage
     * @param int $page
     * @return array
     */
    public function paginate(int $perPage = 20, int $page = 1): array
    {
        return DB::table($this->table)
            ->where('deleted_at', null)
            ->orderBy('created_at', 'DESC')
            ->paginate($perPage, $page);
    }

    /**
     * 新しいユーザーを作成
     * @param array $data
     * @return string 挿入されたID
     */
    public function create(array $data): string
    {
        $data['created_at'] = date('Y-m-d H:i:s');
        $data['updated_at'] = date('Y-m-d H:i:s');

        return DB::table($this->table)->insert($data);
    }

    /**
     * ユーザー情報を更新
     * @param int $id
     * @param array $data
     * @return int 更新された行数
     */
    public function update(int $id, array $data): int
    {
        $data['updated_at'] = date('Y-m-d H:i:s');

        return DB::table($this->table)
            ->where('id', $id)
            ->update($data);
    }

    /**
     * ユーザーを削除（物理削除）
     * @param int $id
     * @return int 削除された行数
     */
    public function delete(int $id): int
    {
        return DB::table($this->table)
            ->where('id', $id)
            ->delete();
    }

    /**
     * ユーザーを論理削除
     * @param int $id
     * @return int 更新された行数
     */
    public function softDelete(int $id): int
    {
        return DB::table($this->table)
            ->where('id', $id)
            ->update([
                'deleted_at' => date('Y-m-d H:i:s')
            ]);
    }

    /**
     * ユーザー数をカウント
     * @param string|null $status
     * @return int
     */
    public function count(?string $status = null): int
    {
        $query = DB::table($this->table)
            ->whereNull('deleted_at');

        if ($status !== null) {
            $query->where('status', $status);
        }

        return $query->count();
    }

    /**
     * 複数IDでユーザーを取得
     * @param array $ids
     * @return array
     */
    public function findByIds(array $ids): array
    {
        return DB::table($this->table)
            ->whereIn('id', $ids)
            ->get();
    }

    /**
     * ロールでユーザーを取得
     * @param string $role
     * @return array
     */
    public function findByRole(string $role): array
    {
        return DB::table($this->table)
            ->where('role', $role)
            ->where('status', 'active')
            ->orderBy('name', 'ASC')
            ->get();
    }
}

/**
 * 投稿リポジトリ
 * JOIN、集計を使用したサンプルクラス
 */
class PostRepository
{
    private string $table = 'posts';

    /**
     * ユーザー情報付きで投稿を取得
     * @param int $limit
     * @return array
     */
    public function getWithUser(int $limit = 10): array
    {
        return DB::table($this->table . ' p')
            ->leftJoin('users u', 'p.user_id = u.id')
            ->select([
                'p.id',
                'p.title',
                'p.content',
                'p.created_at',
                'u.name as author_name',
                'u.email as author_email'
            ])
            ->where('p.status', 'published')
            ->orderBy('p.created_at', 'DESC')
            ->limit($limit)
            ->get();
    }

    /**
     * カテゴリー別の投稿を取得
     * @param int $categoryId
     * @param int $page
     * @param int $perPage
     * @return array
     */
    public function getByCategory(int $categoryId, int $page = 1, int $perPage = 20): array
    {
        return DB::table($this->table . ' p')
            ->leftJoin('categories c', 'p.category_id = c.id')
            ->select([
                'p.*',
                'c.name as category_name'
            ])
            ->where('p.category_id', $categoryId)
            ->where('p.status', 'published')
            ->orderBy('p.created_at', 'DESC')
            ->paginate($perPage, $page);
    }

    /**
     * タグで投稿を検索
     * @param array $tagIds
     * @return array
     */
    public function findByTags(array $tagIds): array
    {
        return DB::table($this->table . ' p')
            ->innerJoin('post_tags pt', 'p.id = pt.post_id')
            ->whereIn('pt.tag_id', $tagIds)
            ->distinct()
            ->select(['p.*'])
            ->get();
    }

    /**
     * 人気の投稿を取得（コメント数順）
     * @param int $limit
     * @return array
     */
    public function getPopular(int $limit = 10): array
    {
        return DB::table($this->table . ' p')
            ->leftJoin('comments c', 'p.id = c.post_id')
            ->select([
                'p.id',
                'p.title',
                'COUNT(c.id) as comment_count'
            ])
            ->where('p.status', 'published')
            ->groupBy('p.id')
            ->orderByRaw('comment_count DESC')
            ->limit($limit)
            ->get();
    }

    /**
     * ユーザーごとの投稿数を集計
     * @return array
     */
    public function countByUser(): array
    {
        return DB::table($this->table . ' p')
            ->leftJoin('users u', 'p.user_id = u.id')
            ->select([
                'u.id as user_id',
                'u.name as user_name',
                'COUNT(p.id) as post_count'
            ])
            ->groupBy(['u.id', 'u.name'])
            ->having('post_count > :min', ['min' => 0])
            ->orderByRaw('post_count DESC')
            ->get();
    }

    /**
     * 複雑な検索クエリ
     * @param array $filters
     * @return array
     */
    public function advancedSearch(array $filters): array
    {
        $query = DB::table($this->table . ' p')
            ->leftJoin('users u', 'p.user_id = u.id')
            ->leftJoin('categories c', 'p.category_id = c.id')
            ->select([
                'p.*',
                'u.name as author_name',
                'c.name as category_name'
            ]);

        // キーワード検索（タイトルまたは本文）
        if (!empty($filters['keyword'])) {
            $query->whereNested(function ($q) use ($filters) {
                $q->where('p.title', 'LIKE', '%' . $filters['keyword'] . '%')
                    ->orWhere(function ($q2) use ($filters) {
                        $q2->where('p.content', 'LIKE', '%' . $filters['keyword'] . '%')
                            ->where('p.status', 'published');
                    });
            });
        }

        // ステータス
        if (!empty($filters['status'])) {
            $query->where('p.status', $filters['status']);
        }

        // カテゴリー
        if (!empty($filters['category_id'])) {
            $query->where('p.category_id', $filters['category_id']);
        }

        // 著者
        if (!empty($filters['author_id'])) {
            $query->where('p.user_id', $filters['author_id']);
        }

        // 日付範囲
        if (!empty($filters['date_from'])) {
            $query->where('p.created_at', '>=', $filters['date_from']);
        }
        if (!empty($filters['date_to'])) {
            $query->where('p.created_at', '<=', $filters['date_to']);
        }

        return $query->orderBy('p.created_at', 'DESC')->get();
    }

    /**
     * 下書き状態の投稿を削除
     * @param int $daysOld 何日以前の下書きを削除するか
     * @return int 削除された行数
     */
    public function deleteDrafts(int $daysOld = 30): int
    {
        $cutoffDate = date('Y-m-d H:i:s', strtotime("-{$daysOld} days"));

        return DB::table($this->table)
            ->where('status', 'draft')
            ->where('created_at', '<', $cutoffDate)
            ->delete();
    }

    /**
     * 投稿のステータスを一括更新
     * @param array $postIds
     * @param string $status
     * @return int 更新された行数
     */
    public function bulkUpdateStatus(array $postIds, string $status): int
    {
        return DB::table($this->table)
            ->whereIn('id', $postIds)
            ->update([
                'status' => $status,
                'updated_at' => date('Y-m-d H:i:s')
            ]);
    }
}

/**
 * 注文リポジトリ
 * トランザクションを使用したサンプルクラス
 */
class OrderRepository
{
    private string $table = 'orders';

    /**
     * 注文を作成（トランザクション）
     * @param array $orderData
     * @param array $items
     * @return string 注文ID
     */
    public function createOrder(array $orderData, array $items): string
    {
        return DB::transaction(function () use ($orderData, $items) {
            // 注文を作成
            $orderId = DB::table($this->table)->insert([
                'user_id' => $orderData['user_id'],
                'total_amount' => $orderData['total_amount'],
                'status' => 'pending',
                'created_at' => date('Y-m-d H:i:s')
            ]);

            // 注文アイテムを作成
            foreach ($items as $item) {
                DB::table('order_items')->insert([
                    'order_id' => $orderId,
                    'product_id' => $item['product_id'],
                    'quantity' => $item['quantity'],
                    'price' => $item['price']
                ]);

                // 在庫を減らす
                DB::table('products')
                    ->where('id', $item['product_id'])
                    ->update([
                        'stock' => DB::pdo()->query("stock - {$item['quantity']}")
                    ]);
            }

            return $orderId;
        });
    }

    /**
     * 月別の売上レポート
     * @param string $year
     * @return array
     */
    public function monthlySalesReport(string $year): array
    {
        return DB::table($this->table)
            ->select([
                'MONTH(created_at) as month',
                'COUNT(*) as order_count',
                'SUM(total_amount) as total_sales',
                'AVG(total_amount) as avg_order_value'
            ])
            ->whereRaw('YEAR(created_at) = :year', ['year' => $year])
            ->where('status', 'completed')
            ->groupBy('month')
            ->orderBy('month', 'ASC')
            ->get();
    }

    /**
     * ユーザーの注文履歴
     * @param int $userId
     * @return array
     */
    public function getUserOrders(int $userId): array
    {
        return DB::table($this->table . ' o')
            ->leftJoin('order_items oi', 'o.id = oi.order_id')
            ->leftJoin('products p', 'oi.product_id = p.id')
            ->select([
                'o.id',
                'o.created_at',
                'o.status',
                'o.total_amount',
                'COUNT(oi.id) as item_count'
            ])
            ->where('o.user_id', $userId)
            ->groupBy(['o.id', 'o.created_at', 'o.status', 'o.total_amount'])
            ->orderBy('o.created_at', 'DESC')
            ->get();
    }
}

// 使用例
/*
// ユーザーリポジトリの使用
$userRepo = new UserRepository();

// 全ユーザー取得
$users = $userRepo->findAll();

// ID検索
$user = $userRepo->findById(1);

// 新規作成
$userId = $userRepo->create([
    'name' => '田中太郎',
    'email' => 'tanaka@example.com',
    'password' => password_hash('password', PASSWORD_DEFAULT),
    'status' => 'active'
]);

// 更新
$userRepo->update($userId, [
    'name' => '田中花子'
]);

// 検索
$results = $userRepo->search([
    'name' => '田中',
    'status' => 'active',
    'age_min' => 20,
    'age_max' => 40
]);

// 投稿リポジトリの使用
$postRepo = new PostRepository();

// ユーザー情報付きで投稿取得
$posts = $postRepo->getWithUser(10);

// 人気投稿
$popular = $postRepo->getPopular(5);

// 高度な検索
$searchResults = $postRepo->advancedSearch([
    'keyword' => 'PHP',
    'status' => 'published',
    'category_id' => 1
]);

// 注文リポジトリの使用
$orderRepo = new OrderRepository();

// 注文作成（トランザクション）
$orderId = $orderRepo->createOrder(
    ['user_id' => 1, 'total_amount' => 5000],
    [
        ['product_id' => 1, 'quantity' => 2, 'price' => 1500],
        ['product_id' => 2, 'quantity' => 1, 'price' => 2000]
    ]
);

// 売上レポート
$report = $orderRepo->monthlySalesReport('2024');
*/