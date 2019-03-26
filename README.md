# Smart Image Resizer Plugin for OctoberCMS Build 420+

This plugin allows you to offload image resizing from page rendering by either pushing image resize jobs onto a queue or by resizing on image load. This functionality is most useful on image-heavy sites where the images to be displayed will change over time and various sizes of the same image are used in your site templates.

For the best user experience, it's anticipated that wherever possible you will be pre-generating the image sizes you need, e.g. by triggering relevant page renders when image galleries change via the backend. The purpose of this plugin is to make that easier by avoiding page execution time limits and handling resizes in queue workers where higher memory limits might be appropriate.

If for some reason required image sizes were not pre-generated and don't exist at the time a site visitor accesses a page, the page can render and start displaying before the image resizes have been performed. Depending on server load and your configuration, there may still be a significant delay before your visitor can load all page images, but they should experience temporary slowness loading images rather than long waits for page loads or PHP execution time limit errors.

## Usage

A `smart_resize` filter is available for use in your Twig templates. This filter is almost a drop-in replacement for the `getThumb` function provided by [OctoberCMS file attachments](http://octobercms.com/docs/database/attachments#viewing-attachments) with the addition of a `priority` parameter to specify queueing priority.

The full list of available parameters is `smart_resize(width, height, options, priority)`

Parameter    | Description
------------ | -------------
**width**    | integer: desired image width. 0 if height should be used as the sole constraint.
**height**   | integer: desired image height. 0 if width should be used as the sole constraint.
**options**  | (optional) string or array: if a string, it is used as the `mode` option. All `getThumb` options are the same, but this plugin adds some options. See the next table.
**priority** | (optional) string: the priority level to use for queuing (more below). By default takes the `defaultPriority` value set in the plugin config.

Added options:

Option         | Default | Description
-------------- | ------- | ------------
**upscale**    | false   | `getThumb` will automatically upscale images to match your desired dimensions, but this plugin will only do so if `upscale` is true or "exact" is the `mode`.
**flatten**    | false   | Set to true to flatten transparent images using the `background` option.
**background** | [0,0,0] | Array of RGB values specifying a background color to use when flattening an image. Has no effect if `flatten` is false.

### File attachment `getThumb` replacement

```
<img src="{{ file.getThumb(200, 200) }}">
```

becomes

```
<img src="{{ file | smart_resize(200, 200) }}">
```

### Direct paths

In addition to handling file attachment objects, you can also pass the filter an image path in your `media` or `uploads` storage. Consistent with paths saved by the `mediafinder` form widget, `media` paths should use the `media` folder as the root. Paths in `uploads` should be written relative to the project root.

As an example, considering a default directory structure:

```
-- storage
   `-- app
       `-- media
           `-- media_file.jpg
       `-- uploads
           `-- test_upload.jpg
```

your page could contain the following markup:

```
<img src="{{ '/media_file.jpg' | smart_resize(600, 400, { 'quality': 95 }) }}">
<img src="{{ '/storage/app/uploads/test_upload.jpg' | smart_resize(350, 350, { 'mode': 'crop', 'quality': 95 }) }}">
```

### Global function

A global `smartResize` function is defined for use in the back end (or wherever). This function's first parameter is the file attachment object or string path, followed by the same parameters as the Twig filter. Like the filter, it returns the URL to use for loading the resized image.

```
$resizedUrl = smartResize('/media/file.jpg', 300, 200);
```

## Priority levels

By default, this plugin is set up with two priority levels which impact the way resize jobs are queued: `high` and `default`:

```
...
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
...
```

As an example where priority levels can be useful, consider an image gallery displaying thumbnails on the page with a link to a larger generated image size. The thumbnails will be loaded automatically on page load, but the larger images may or may not be loaded by a site visitor. So we may choose to use markup similar to the following:

```
...
{% for image in gallery_images %}
    <a href="{{ image | smart_resize(1200, 800) }}">
        <img src="{{ image | smart_resize(250, 250, 'auto', 'high') }}">
    </a>
{% endfor %}
...
```

Because `isPriority` is set for the `high` priority level, resize batches will be sent to the queue as soon as they're ready, and when the page has finished rendering, any remaining resizes at the `high` priority level will be queued before sending the rest.

That works great if one page needing image generation is rendered at a time, but if there's a possibility of other pages queuing image resizes concurrently, you may want to set up queueing with priorities as explained in the [queue documentation](http://octobercms.com/docs/services/queues). With our example markup above, assigning each priority level to a different queue via the `queue` setting would allow prioritizing thumbnail generation for a gallery page ahead of the larger images being resized for another gallery page queued earlier.

## License

[MIT License](LICENSE.md)