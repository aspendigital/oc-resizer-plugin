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
            'name'          => 'Smart Resizer',
            'description'   => 'Offloads thumbnail generation and image resizing to queue or image load',
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
                $options = $this->decodeResizeOptions($options);
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
            $path = base64_decode($path, true);
            if (!$this->isValidResizePath($path)) {
                abort(404);
            }

            if ($options) {
                $options = $this->decodeResizeOptions($options);
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
     * Decode public route options without allowing serialized PHP objects.
     *
     * JSON is used by newly generated URLs. The unserialize fallback keeps
     * existing cached URLs working, but explicitly disables object creation.
     *
     * @param string $encoded
     * @return array|string
     */
    protected function decodeResizeOptions($encoded)
    {
        $decoded = base64_decode($encoded, true);
        if ($decoded === false) {
            abort(404);
        }

        $options = json_decode($decoded, true);
        if (json_last_error() !== JSON_ERROR_NONE) {
            $options = @unserialize($decoded, ['allowed_classes' => false]);
        }

        if (!(is_array($options) || is_string($options)) || !$this->isSafeResizeOptionValue($options)) {
            abort(404);
        }

        return $options;
    }

    /**
     * Only scalar option values and shallow arrays are valid resize options.
     */
    protected function isSafeResizeOptionValue($value, $depth = 0)
    {
        if ($depth > 5 || is_object($value) || is_resource($value)) {
            return false;
        }

        if (!is_array($value)) {
            return is_null($value) || is_scalar($value);
        }

        foreach ($value as $key => $item) {
            if (!(is_int($key) || is_string($key)) || !$this->isSafeResizeOptionValue($item, $depth + 1)) {
                return false;
            }
        }

        return true;
    }

    /**
     * Limit direct file resizing to local, non-traversing storage paths.
     */
    protected function isValidResizePath($path)
    {
        if (!is_string($path) || $path === '' || strpos($path, "\0") !== false || strpos($path, '://') !== false) {
            return false;
        }

        $normalized = str_replace('\\', '/', $path);
        return !preg_match('#(^|/)\.\.(/|$)#', $normalized);
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
