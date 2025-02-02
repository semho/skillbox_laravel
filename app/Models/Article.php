<?php

namespace App\Models;

use App\Events\ArticleDeleted;
use App\Events\ArticleUpdated;
use App\Events\ArticleCreated;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Arr;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;

class Article extends Model
{
    use HasFactory;

    protected $fillable = ['name', 'owner_id', 'slug', 'description', 'text', 'is_published'];

    protected $dispatchesEvents = [
        'created' => ArticleCreated::class,
        'updated' => ArticleUpdated::class,
        'deleted' => ArticleDeleted::class,
    ];

    protected static function boot()
    {
        parent::boot(); // TODO: Change the autogenerated stub

        static::updating(function (Article $article) {
            $after = $article->getDirty();

            $article->history()->attach(auth()->id(), [
                'before' => json_encode(Arr::only($article->fresh()->toArray(), array_keys($after))),
                'after' => json_encode($after),
            ]);
        });

        static::created(function() {
            Cache::tags([
                'articles',
                'articlesCount',
                'getMaxCountArticlesUser',
                'getArticleMaxLengthName',
                'getArticleMinLengthName',
                'getAvgCountArticles',
                'articles_tags'
            ])->flush();
        });
        static::updated(function() {
            Cache::tags([
                'articles',
                'getArticleMaxLengthName',
                'getArticleMinLengthName',
                'getMostUpdatedArticle',
                'articles_tags'
            ])->flush();
        });
        static::deleted(function() {
            Cache::tags([
                'articles',
                'articlesCount',
                'getMaxCountArticlesUser',
                'getArticleMaxLengthName',
                'getArticleMinLengthName',
                'getAvgCountArticles',
                'getMostUpdatedArticle',
                'getMostDiscussedArticle',
                'articles_tags'
            ])->flush();
        });
    }

    public static function getArticles()
    {
        if (Auth::check()) {
            $articles = Cache::tags('articles')->remember('user_alrticles|' . auth()->user()->id, now()->addWeek(), function () {
                if (Role::isAdmin(auth()->user())) {
                    return static::with('tags')->orderByDesc('id')->simplePaginate(10);
                } else {
                    return static::publishedAndUser()->orderByDesc('id')->simplePaginate(10);
                }
            });
        } else {
            $articles = Cache::tags('articles')->remember('no_auth', now()->addWeek(), function () {
                return static::published()->orderByDesc('id')->simplePaginate(10);
            });
        }

        return $articles;
    }

    public function tags()
    {
        return $this->morphToMany(Tag::class, 'taggable');
    }

    public function history()
    {
        return $this->belongsToMany(User::class, 'article_histories')
            ->withPivot(['before', 'after'])->withTimestamps();
    }

    public static function lastChangeInHistory($article)
    {
        return static::find($article->id)->history()->get()->sortBy('pivot_updated_at')->last()->pivot->toArray();
    }

    public static function scopePublished($query)
    {
        return $query->where('is_published', 1);
    }

    public static function publishedAndUser()
    {
        $articlesPublished = static::published();
        $articlesNoPublishedUser = static::where([['owner_id', '=', auth()->id()], ['is_published', '=', 0]]);
        $articles = $articlesPublished->unionAll($articlesNoPublishedUser);

        return $articles;
    }

    public static function getArticle($slug)
    {
        return Cache::remember('article_' . $slug, now()->addWeek(), function() use ($slug) {
            return static::where('slug', $slug)->first();
        });
    }

    public function commentArticle()
    {
        $comments = $this->morphOne(Comment::class, 'commentable')->get()->each(function ($item) {
            $item['author'] = User::find($item->owner_id)->name;
            return $item;
        });

        return $comments;
    }

    //максимальное созданное количесво статей одним пользователем
    public static function getMaxCountArticlesUser()
    {
        return DB::table('articles')
            ->join('users', 'users.id', '=', 'articles.owner_id')
            ->select('owner_id', 'users.name', DB::raw('count(*) as total'))
            ->groupBy('owner_id', 'users.name')
            ->orderBy('total', 'desc')
            ->first();
    }

    //сортировка статей по длине названия
    public static function getSortArticlesByLengthName()
    {
        return DB::table('articles')
            ->select('name', 'slug', DB::raw('max(length(name)) as max'))
            ->groupBy('name', 'slug')
            ->orderBy('max', 'desc')->get();
    }

    //максимальная длинна названия статьи
    public static function getArticleMaxLengthName()
    {
        return static::getSortArticlesByLengthName()->first();
    }

    //минимальная длинна названия статьи
    public static function getArticleMinLengthName()
    {
        return static::getSortArticlesByLengthName()->last();
    }

    //активные пользователи
    public static function getActiveUsers()
    {
        return DB::table('articles')
            ->join('users', 'users.id', '=', 'articles.owner_id')
            ->select('owner_id', 'users.name', DB::raw('count(*) as total'))
            ->groupBy('owner_id', 'users.name')
            ->having('total', '>', 1);
    }

    //среднее количество статей
    public static function getAvgCountArticles()
    {
        return (int)round(static::getActiveUsers()->avg('total'));
    }

    //выбираем статьи, которые хоть раз обновлялись и забираем статьию у которой больше всех обновлений
    public static function getMostUpdatedArticle()
    {
        return DB::table('articles')
            ->join('article_histories', 'article_histories.article_id', '=', 'articles.id')
            ->select('articles.name', 'articles.slug', DB::raw('count(articles.name) as max'))
            ->groupBy('articles.name', 'articles.slug')
            ->orderBy('max', 'desc')->first();
    }

    //самая обсуждаемая статья
    public static function getMostDiscussedArticle()
    {
        return DB::table('articles')
            ->join('comments', 'comments.commentable_id', '=', 'articles.id')
            ->select('articles.name', 'articles.slug', 'comments.commentable_type', DB::raw('count(comments.commentable_id) as max'))
            ->groupBy('articles.name', 'articles.slug', 'comments.commentable_type')
            ->having('comments.commentable_type', 'App\Models\Article')
            ->orderBy('max', 'desc')->first();
    }
}
