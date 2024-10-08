<?php

use Illuminate\Support\Facades\Schema;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Database\Migrations\Migration;

return new class () extends Migration {
    /**
     * Run the migrations.
     *
     * @return void
     */
    public function up()
    {
        foreach (\Dashed\DashedArticles\Models\Article::get() as $model) {
            $content = [];
            foreach (\Dashed\DashedCore\Classes\Locales::getLocales() as $locale) {
                $content['title'][$locale['id']] = $model->getTranslation('meta_title', $locale['id']);
                $content['description'][$locale['id']] = $model->getTranslation('meta_description', $locale['id']);
                $content['image'][$locale['id']] = $model->getTranslation('meta_image', $locale['id']);
            }
            $model->metadata()->updateOrCreate([], $content);
        }

        Schema::table('dashed__articles', function (Blueprint $table) {
            $table->dropColumn('meta_title');
            $table->dropColumn('meta_description');
            $table->dropColumn('meta_image');
        });
    }

    /**
     * Reverse the migrations.
     *
     * @return void
     */
    public function down()
    {
        //
    }
};
