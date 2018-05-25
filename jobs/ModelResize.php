<?php

namespace AspenDigital\Resizer\Jobs;

use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Contracts\Bus\SelfHandling;
use System\Models\File;

class ModelResize implements ShouldQueue, SelfHandling
{
    use InteractsWithQueue;

    /** @var File */
    protected $file;

    /** @var int */
    protected $width;

    /** @var int */
    protected $height;

    /** @var array */
    protected $options;

    public function __construct(File $file, $width, $height, $options)
    {
        $this->file = $file;
        $this->width = $width;
        $this->height = $height;
        $this->options = $options;
    }

    public function handle()
    {
        $this->file->getThumb($this->width, $this->height, $this->options);
    }
}
