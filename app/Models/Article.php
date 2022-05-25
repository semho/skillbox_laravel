<?php

namespace App\Models;

use App\Events\ArticleDeleted;
use App\Events\ArticleUpdated;
use App\Events\ArticleCreated;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Arr;
use Illuminate\Support\Facades\Auth;
use function PHPUnit\TestFixture\func;
use function Webmozart\Assert\Tests\StaticAnalysis\string;

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
    }

    public function tags()
    {
        return $this->belongsToMany(Tag::class);
    }

    public function comments()
    {
        return $this->hasMany(Comment::class, 'article_id');
    }

    public function history()
    {
        return $this->belongsToMany(User::class, 'article_histories')
            ->withPivot(['before', 'after'])->withTimestamps();
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
        return static::where('slug', $slug)->first();
    }
}
