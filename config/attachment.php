<?php

declare(strict_types=1);

use App\Models\User;

return [
    /*
     * Model, table, and key configuration for the user relation.
     */
    'user_model' => User::class,
    'user_table' => 'users',
    'user_key_name' => 'id',
    'user_key_type' => 'uuid', // 'uuid' or 'int'

    /*
     * Directory path where attachments are stored on disk.
     */
    'directory' => 'attachments',

    /*
     * Maximum file size allowed in kilobytes (default: 20480 KB = 20 MB).
     */
    'max_size' => 20480,

    /*
     * Allowed file extensions for attachments.
     */
    'allowed_extensions' => [
        // Images
        'jpg', 'jpeg', 'png', 'gif', 'webp',

        // Video & Audio
        'mp4', 'webm', 'mp3', 'wav', 'ogg',

        // Documents & Office
        'pdf', 'doc', 'docx', 'odt', 'xls', 'xlsx', 'ods', 'ppt', 'pptx', 'odp', 'txt',

        // Archives
        'zip', 'rar',

        // Data & Geospatial / Geography / GIS
        'json', 'geojson', 'kml', 'kmz', 'gpx', 'topojson', 'gpkg', 'shp', 'shx', 'dbf', 'prj', 'mbtiles', 'tif', 'tiff',
    ],

    /*
     * Image optimization and auto-resizing settings.
     */
    'image' => [
        'auto_resize' => true,
        'max_width' => 1920,
        'max_height' => 1920,
        'quality' => 80,
    ],
];
