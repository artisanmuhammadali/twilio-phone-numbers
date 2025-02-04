<?php

namespace App\Providers;

use Illuminate\Support\Str;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\ServiceProvider;

class AppServiceProvider extends ServiceProvider
{
    /**
     * Register any application services.
     */
    public function register(): void
    {
        //
    }

    /**
     * Bootstrap any application services.
     */
    public function boot(): void
    {
        Str::macro('toAudio', function (string $text, string $language = 'en_US'): string {
            $text = rawurlencode($text);

            $response = Http::withOptions(['allow_redirects' => true])->get('https://translate.google.com/translate_tts?ie=UTF-8&client=gtx&q='.$text.'&tl='.$language);

            $fileName = time().'.mp3';

            Storage::disk('public')->put($fileName, $response);

            return public_path('uploads/'.$fileName);
        });
    }
}
