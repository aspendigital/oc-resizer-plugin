<?php

namespace AspenDigital\Resizer\Jobs;

use AspenDigital\Resizer\Models\ExtendedFile;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Contracts\Queue\ShouldQueue;
use Queue;

/**
 * To keep page loads faster, on render the resizer batches together image resizes,
 * which this job separates and queues individual resize jobs
 */
class ResizeFanout implements ShouldQueue
{
    use InteractsWithQueue;

    /** @var array */
    protected $data;

    /** @var string */
    protected $queue;

    public function __construct($data, $queue = null)
    {
        $this->data = $data;
        $this->queue = $queue;
    }

    public function handle()
    {
        $ids = [];
        foreach ($this->data as $jobInfo) {
            if ($jobInfo['type'] === 'model') {
                $ids[] = $jobInfo['id'];
            }
        }

        $files = empty($ids) ? collect([]) : ExtendedFile::findMany($ids)->keyBy('id');

        $jobs = [];
        foreach ($this->data as $jobInfo) {
            if ($jobInfo['type'] === 'model') {
                $file = $files->get($jobInfo['id']);
                if (!$file) {
                    continue;
                }

                $jobs[] = new ModelResize($file, $jobInfo['width'], $jobInfo['height'], $jobInfo['options']);
            }
            else {
                $jobs[] = new FileResize($jobInfo['path'], $jobInfo['width'], $jobInfo['height'], $jobInfo['options']);
            }
        }

        Queue::bulk($jobs, '', $this->queue);
    }
}