<?php

namespace Dashed\DashedArticles\Models;

use Spatie\SchemaOrg\Schema;
use Dashed\DashedPages\Models\Page;
use Illuminate\Support\Facades\App;
use Dashed\DashedCore\Classes\Sites;
use Illuminate\Support\Facades\View;
use Dashed\DashedCore\Classes\Locales;
use Illuminate\Database\Eloquent\Model;
use Dashed\DashedCore\Models\Customsetting;
use Illuminate\Database\Eloquent\SoftDeletes;
use Dashed\DashedCore\Models\Concerns\IsVisitable;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Dashed\DashedCore\Models\Concerns\HasCustomBlocks;
use Dashed\LaravelLocalization\Facades\LaravelLocalization;

class Article extends Model
{
    use HasCustomBlocks;
    use IsVisitable;
    use SoftDeletes;

    protected $table = 'dashed__articles';

    public $translatable = [
        'name',
        'slug',
        'content',
    ];

    protected $casts = [
        'start_date' => 'datetime',
        'end_date' => 'datetime',
        'site_ids' => 'array',
        'content' => 'array',
    ];

    protected $appends = [
        'status',
    ];

    protected $with = [
        'author',
    ];

    public function getNextArticle()
    {
        if ($this->category) {
            return $this->category->articles()->thisSite()->publicShowable()->where('id', '>', $this->id)->orderBy('id', 'ASC')->first();
        } else {
            return Article::thisSite()->publicShowable()->where('id', '>', $this->id)->orderBy('id', 'ASC')->first();
        }
    }

    public function author(): BelongsTo
    {
        return $this->belongsTo(Author::class);
    }

    public function category(): BelongsTo
    {
        return $this->belongsTo(ArticleCategory::class);
    }

    public function getReadingTimeMinutesAttribute()
    {
        $amount = floor(str_word_count(strip_tags($this->getRawOriginal('content').json_encode($this->contentBlocks))) / 200);

        return $amount > 0 ? $amount : 1;
    }

    public static function resolveRoute($parameters = [])
    {
        $slug = $parameters['slug'] ?? '';
        $slugComponents = explode('/', $slug);

        if ($slug && $overviewPage = self::getOverviewPage()) {
            $article = Article::publicShowable()->where('slug->'.App::getLocale(), $slugComponents[count($slugComponents) - 1])->first();
            if ($article && ((! Customsetting::get('article_use_category_in_url', null, false) && count($slugComponents) == 2) || ((! $article->category && count($slugComponents) == 2) || (Customsetting::get('article_use_category_in_url', null, false) && $article->category && $article->category->slug == ($slugComponents[count($slugComponents) - 2] ?? '') && count($slugComponents) == 3)))) {
                $page = Page::publicShowable()->isNotHome()->where('slug->'.App::getLocale(), $slugComponents[0])->where('id', $overviewPage->id)->first();
                if ($page) {
                    if (View::exists(env('SITE_THEME', 'dashed').'.articles.show')) {
                        seo()->metaData('metaTitle', $article->metadata && $article->metadata->title ? $article->metadata->title : $article->name);
                        seo()->metaData('metaDescription', $article->metadata->description ?? '');
                        seo()->metaData('ogType', 'article');
                        if ($article->metadata && $article->metadata->image) {
                            seo()->metaData('metaImage', $article->metadata->image);
                        }

                        $articleSchema = Schema::article()
                            ->name(seo()->metaData('metaTitle'))
                            ->url(request()->url())
                            ->image(seo()->metaData('metaImage'))
                            ->description($article->contentBlocks['excerpt'] ?? '')
                            ->author($article->author ? $article->author->name : [
                                '@type' => 'Organization',
                                '@id' => request()->url() . '#organization',
                            ])
                            ->publisher($article->author ? $article->author->name : [
                                '@type' => 'Organization',
                                '@id' => request()->url() . '#organization',
                            ])
                            ->dateCreated($article->created_at)
                            ->dateModified($article->updated_at)
                            ->datePublished($article->start_date ?: $article->created_at)
                            ->inLanguage(LaravelLocalization::getCurrentLocaleName())
                            ->thumbnailUrl(mediaHelper()->getSingleMedia(seo()->metaData('metaImage'))->url ?? '')
                            ->timeRequired("PT{$article->readingTimeMinutes}M")
                            ->wordCount(str_word_count($article->getPlainContent()))
                            ->articleBody($article->getPlainContent())
                            ->text($article->getPlainContent())
                            ->about($article->category ? $article->category->name : '');

                        $schemas = seo()->metaData('schemas');
                        $schemas['article'] = $articleSchema;
                        seo()->metaData('schemas', $schemas);

                        $correctLocale = App::getLocale();
                        $alternateUrls = [];
                        foreach (Sites::getLocales() as $locale) {
                            if ($locale['id'] != $correctLocale) {
                                LaravelLocalization::setLocale($locale['id']);
                                App::setLocale($locale['id']);
                                $alternateUrls[$locale['id']] = $article->getUrl();
                            }
                        }
                        LaravelLocalization::setLocale($correctLocale);
                        App::setLocale($correctLocale);
                        seo()->metaData('alternateUrls', $alternateUrls);

                        View::share('article', $article);
                        View::share('model', $article);
                        View::share('breadcrumbs', $article->breadcrumbs());
                        View::share('page', $page);

                        return view(env('SITE_THEME', 'dashed').'.articles.show');
                    } else {
                        return 'pageNotFound';
                    }
                }
            }
        }
    }

    public function breadcrumbs(): array
    {
        $breadcrumbs = [];
        $model = $this;

        $homePage = Page::isHome()->publicShowable()->first();
        if ($homePage) {
            $breadcrumbs[] = [
                'name' => $homePage->name,
                'url' => $homePage->getUrl(),
            ];
        }

        $overviewPage = self::getOverviewPage();
        if ($overviewPage) {
            $breadcrumbs[] = [
                'name' => $overviewPage->name,
                'url' => $overviewPage->getUrl(),
            ];
        }

        if ($this->category) {
            $categoryBreadcrumbs = [];
            $category = $this->category;
            $categoryBreadcrumbs[] = [
                'name' => $category->name,
                'url' => $category->getUrl(),
            ];
            while ($category->parent) {
                $category = $category->parent;
                $categoryBreadcrumbs[] = [
                    'name' => $category->name,
                    'url' => $category->getUrl(),
                ];
            }
            if (count($categoryBreadcrumbs)) {
                $categoryBreadcrumbs = array_reverse($categoryBreadcrumbs);
                $breadcrumbs = array_merge($breadcrumbs, $categoryBreadcrumbs);
            }

        }

        $breadcrumbs[] = [
            'name' => $this->name,
            'url' => $this->getUrl(),
        ];

        return $breadcrumbs;
    }

    public function likes(): HasMany
    {
        return $this->hasMany(ArticleLike::class)
            ->where('like', 1);
    }

    public function dislikes(): HasMany
    {
        return $this->hasMany(ArticleLike::class)
            ->where('like', 0);
    }

    public function getUrl($activeLocale = null, bool $native = true)
    {
        $originalLocale = app()->getLocale();

        if (! $activeLocale) {
            $activeLocale = $originalLocale;
        }

        $url = '';

        if ($overviewPage = self::getOverviewPage()) {
            if (method_exists($this, 'parent') && $this->parent) {
                $url .= "{$this->parent->getUrl($activeLocale)}/";
            } else {
                $url .= "{$overviewPage->getUrl($activeLocale)}/";
            }

            if (Customsetting::get('article_use_category_in_url') && $this->category) {
                $url .= "{$this->category->getTranslation('slug', $activeLocale)}/";
            }
        } else {
            return '/';
        }

        $url .= $this->getTranslation('slug', $activeLocale);

        if (! str($url)->startsWith('/')) {
            $url = '/'.$url;
        }
        if ($activeLocale != Locales::getFirstLocale()['id'] && ! str($url)->startsWith("/{$activeLocale}")) {
            $url = '/'.$activeLocale.$url;
        }

        return $native ? $url : url($url);
    }
}
