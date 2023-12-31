<?php

namespace Dashed\DashedArticles\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\App;
use Illuminate\Support\Facades\View;
use Mcamara\LaravelLocalization\Facades\LaravelLocalization;
use Dashed\DashedCore\Classes\Sites;
use Dashed\DashedCore\Models\Concerns\IsVisitable;
use Dashed\DashedCore\Models\Customsetting;
use Dashed\DashedPages\Models\Page;
use Spatie\Translatable\HasTranslations;

class ArticleCategory extends Model
{
    use HasTranslations;
    use IsVisitable;

    protected $table = 'dashed__article_categories';

    public $translatable = [
        'name',
        'slug',
        'content',
    ];

    protected $casts = [
        'site_ids' => 'array',
        'created_at' => 'datetime',
        'updated_at' => 'datetime',
    ];

    public function articles()
    {
        return $this->hasMany(Article::class, 'category_id');
    }

    public static function resolveRoute($parameters = [])
    {
        $slug = $parameters['slug'] ?? '';
        $slugComponents = explode('/', $slug);

        if ($slug && Customsetting::get('article_overview_page_id', Sites::getActive())) {
            $articleCategory = ArticleCategory::where('slug->' . App::getLocale(), $slugComponents[count($slugComponents) - 1])->first();
            if ($articleCategory) {
                $page = Page::publicShowable()->isNotHome()->where('slug->' . App::getLocale(), str_replace('/' . $slugComponents[count($slugComponents) - 1], '', $slug))->where('id', Customsetting::get('article_overview_page_id', Sites::getActive()))->first();
                if ($page) {
                    if (View::exists('dashed.article-categories.show')) {
                        seo()->metaData('metaTitle', $articleCategory->metadata && $articleCategory->metadata->title ? $articleCategory->metadata->title : $articleCategory->name);
                        seo()->metaData('metaDescription', $articleCategory->metadata->description ?? '');
                        seo()->metaData('ogType', 'article');
                        if ($articleCategory->metadata && $articleCategory->metadata->image) {
                            seo()->metaData('metaImage', $articleCategory->metadata->image);
                        }

                        $correctLocale = App::getLocale();
                        $alternateUrls = [];
                        foreach (Sites::getLocales() as $locale) {
                            if ($locale['id'] != $correctLocale) {
                                LaravelLocalization::setLocale($locale['id']);
                                App::setLocale($locale['id']);
                                $alternateUrls[$locale['id']] = $articleCategory->getUrl();
                            }
                        }
                        LaravelLocalization::setLocale($correctLocale);
                        App::setLocale($correctLocale);
                        seo()->metaData('alternateUrls', $alternateUrls);

                        View::share('articleCategory', $articleCategory);
                        View::share('breadcrumbs', $articleCategory->breadcrumbs());
                        View::share('articles', $articleCategory->articles()->paginate(12));

                        return view('dashed.article-categories.show');
                    } else {
                        return 'pageNotFound';
                    }
                }
            }
        }
    }

    public function getUrl()
    {
        $url = (Article::getOverviewPage()->getUrl() ?? '/') . '/' . $this->slug;

        return LaravelLocalization::localizeUrl($url);
    }
}
