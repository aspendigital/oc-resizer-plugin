<?php namespace AspenDigital\Resizer;

use AspenDigital\Resizer\Models\ExtendedFile;
use AspenDigital\Resizer\Classes\ResizeService;
use Config;
use Redirect;
use Route;
use System\Classes\PluginBase;

class Plugin extends PluginBase
{

    /**
     * @inheritdoc
     */
    public function pluginDetails()
    {
        return [
            'name'          => 'Resizer',
            'description'   => '',
            'author'        => 'Aspen Digital',
            'icon'          => 'icon-image',
            'homepage'      => 'http://www.aspendigital.com'
        ];
    }

    public function register()
	{
        $this->app->singleton(ResizeService::class, function($app) {
            $service = new ResizeService();
            $app['events']->listen('cms.page.display', function() use ($service) {
                $service->flush();
            });

            return $service;
        });
	}

    public function boot()
    {
        require_once(__DIR__ . DIRECTORY_SEPARATOR . 'lib' . DIRECTORY_SEPARATOR . 'global_helper.php');

        Route::get('_resize/model/{id}/{width}/{height}/{options?}', function($id, $width, $height, $options=[]) {
            if (is_string($options)) {
                $options = unserialize(base64_decode($options));
            }

            $file = ExtendedFile::findOrFail($id);
            list($publicPath, $exists) = $file->resizerThumbInfo($width, $height, $options);
            $service = $this->app[ResizeService::class];
            if ($service->useQueue) {
                $count = 0;
                $timeout = Config::get('aspendigital.resizer::loadTimeout') * 2;
                while (!$exists && ($timeout <= 0 || $count < $timeout)) {
                    usleep(500000);
                    list($publicPath, $exists) = $file->resizerThumbInfo($width, $height, $options);
                    $count++;
                }
            }

            return Redirect::to($file->getThumb($width, $height, $options));
        });

        Route::get('_resize/file/{path}/{width}/{height}/{options?}', function($path, $width, $height, $options=[]) {
            $path = base64_decode($path);
            if ($options) {
                $options = unserialize(base64_decode($options));
            }

            $service = $this->app[ResizeService::class];
            if ($service->useQueue) {
                $thumbInfo = $service->fileThumbInfo($path, $width, $height, $options);
                $count = 0;
                $timeout = Config::get('aspendigital.resizer::loadTimeout') * 2;
                while (!$thumbInfo['disk']->exists($thumbInfo['diskTo']) && ($timeout <= 0 || $count < $timeout)) {
                    usleep(500000);
                    $count++;
                }
            }

            return Redirect::to($service->generateFileThumb($path, $width, $height, $options));
        });
    }

    /**
     * @inheritdoc
     */
    public function registerMarkupTags()
    {
        return [
            'filters' => [
                'smart_resize' => [$this, 'resize']
            ]
        ];
    }

    public function resize($image, $width, $height, $options = [], $priority = null)
    {
        return $this->app->make(ResizeService::class)->thumb($image, $width, $height, $options, $priority);
    }
}
