<?php

return [
    /*
     * true / false, or null to check the queue connection
     * and use the queue if not a 'sync' or 'null' driver
     */
    'useQueue' => null,

    // The name of the directory created for thumbnails in 'media' and 'uploads' paths
    'thumbDirName' => '_resized',

    'defaultPriority' => 'default',

    'priorityLevels' => [
        /*
         * The key defines the priority level to use in the Twig filter,
         * e.g. {{ image || smart_resize(600, 400, 'auto', 'high) }}
         */
        'high' => [
            'isPriority' => true, // true to send to queue as soon as a batch is ready
            'queue'      => null, // The queue name to send jobs to, or null for the default
        ],
        'default' => [
            'isPriority' => false,
            'queue'      => null
        ]
    ],

    /*
     * The number of image resizes to batch during page generation. A fan-out job
     * then runs on the queue to push an individual queue job for each image.
     */
    'fanoutSize' => 50,

    /**
     * On page load, resize jobs are queued immediately, but to avoid putting too
     * much load on the server, the image request will wait for this many seconds
     * for the queue processing to generate the requested thumbnail. After the
     * designated wait, if the thumbnail has still not been generated, the resize will
     * be executed as part of the image request.
     *
     * Wherever possible, thumbnails should of course be pre-generated before throwing
     * significant load at a page, but this setting provides a balance between
     * offloading resizing to the queue and avoiding making site visitors wait
     * too long for image loads if the queue processing is taking longer than expected.
     *
     * If not using the queue, images are resized immediately on the request for
     * image load.
     */
    'loadTimeout' => 15 // in seconds (0 to wait forever)
];