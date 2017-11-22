<?php

namespace AspenDigital\Resizer\Jobs;

use AspenDigital\Resizer\Classes\ResizeService;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Contracts\Queue\ShouldQueue;

class FileResize implements ShouldQueue
{
    use InteractsWithQueue;

    /** @var string */
    protected $path;

    /** @var int */
    protected $width;

    /** @var int */
    protected $height;

    /** @var array */
    protected $options;

    public function __construct($path, $width, $height, $options)
    {
        $this->path = $path;
        $this->width = $width;
        $this->height = $height;
        $this->options = $options;
    }

    public function handle(ResizeService $service)
    {
        $service->generateFileThumb($this->path, $this->width, $this->height, $this->options);
    }
}
